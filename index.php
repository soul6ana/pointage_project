<?php
// index.php - Page de pointage avec empreinte digitale
session_start();
require_once 'config.php';

$message = '';
$message_type = '';

function json_response(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}

function employee_distance_meters(float $lat, float $lng): float {
  $earth = 6371000;
  $dLat = deg2rad(OFFICE_LATITUDE - $lat);
  $dLng = deg2rad(OFFICE_LONGITUDE - $lng);
  $a = sin($dLat / 2) * sin($dLat / 2)
    + cos(deg2rad($lat)) * cos(deg2rad(OFFICE_LATITUDE)) * sin($dLng / 2) * sin($dLng / 2);
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $earth * $c;
}

function validate_geofence($latitude, $longitude, ?float &$distance = null): bool {
  if (!is_numeric($latitude) || !is_numeric($longitude)) {
    return false;
  }
  $lat = (float)$latitude;
  $lng = (float)$longitude;
  $distance = employee_distance_meters($lat, $lng);
  return $distance <= GEOFENCE_RADIUS_METERS;
}

function otp_table_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'otp_fallback_requests'");
    $ready = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $ready = false;
  }
  return $ready;
}

function insert_pointage(PDO $pdo, array $payload): void {
  try {
    $pdo->prepare(
      "INSERT INTO pointages (employe_id, type, heure, latitude, longitude, adresse, verification_method, otp_request_id, date_pointage)
       VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([
      $payload['employe_id'],
      $payload['type'],
      $payload['heure'],
      $payload['latitude'],
      $payload['longitude'],
      $payload['adresse'],
      $payload['verification_method'],
      $payload['otp_request_id'],
      $payload['date_pointage']
    ]);
  } catch (PDOException $e) {
    // Backward compatibility for legacy schema without verification columns.
    $pdo->prepare(
      "INSERT INTO pointages (employe_id, type, heure, latitude, longitude, adresse, date_pointage)
       VALUES (?,?,?,?,?,?,?)"
    )->execute([
      $payload['employe_id'],
      $payload['type'],
      $payload['heure'],
      $payload['latitude'],
      $payload['longitude'],
      $payload['adresse'],
      $payload['date_pointage']
    ]);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $code_employe = trim($_POST['code_employe'] ?? '');
    $type         = $_POST['type'] ?? '';
    $latitude     = $_POST['latitude'] ?? null;
    $longitude    = $_POST['longitude'] ?? null;
    $adresse      = trim($_POST['adresse'] ?? '');

    // --- Enregistrer l'empreinte (première fois) ---
    if ($action === 'register_credential') {
        $credential_id   = $_POST['credential_id']     ?? '';
        $attestation_b64 = $_POST['attestation_object'] ?? ''; // full attestationObject
        $client_data_b64 = $_POST['client_data_json']   ?? ''; // clientDataJSON

        if (empty($code_employe) || empty($credential_id)) {
          json_response(['success' => false, 'message' => 'Données manquantes.']);
        }

        // --- Verify registration challenge ---
        if (!empty($client_data_b64)) {
          if (empty($_SESSION['webauthn_reg_challenge'])
              || (time() - ($_SESSION['webauthn_reg_challenge_ts'] ?? 0)) > 300) {
            unset($_SESSION['webauthn_reg_challenge'],
                  $_SESSION['webauthn_reg_challenge_uid'],
                  $_SESSION['webauthn_reg_challenge_ts']);
            json_response(['success' => false, 'message' => 'Challenge d\'enregistrement expiré. Rechargez la page.']);
          }

          $cdj      = base64_decode(strtr($client_data_b64, '-_', '+/'));
          $cdParsed = json_decode($cdj, true);

          if (is_array($cdParsed) && ($cdParsed['type'] ?? '') === 'webauthn.create') {
            $expB64 = rtrim(strtr(base64_encode(hex2bin($_SESSION['webauthn_reg_challenge'])), '+/', '-_'), '=');
            $rcv    = rtrim(strtr($cdParsed['challenge'] ?? '', '+/', '-_'), '=');
            if (!hash_equals($expB64, $rcv)) {
              unset($_SESSION['webauthn_reg_challenge'],
                    $_SESSION['webauthn_reg_challenge_uid'],
                    $_SESSION['webauthn_reg_challenge_ts']);
              json_response(['success' => false, 'message' => 'Challenge d\'enregistrement invalide.']);
            }
            $proto           = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $expected_origin = $proto . '://' . $_SERVER['HTTP_HOST'];
            if (($cdParsed['origin'] ?? '') !== $expected_origin) {
              json_response(['success' => false, 'message' => 'Origine invalide lors de l\'enregistrement.']);
            }
          }
          unset($_SESSION['webauthn_reg_challenge'],
                $_SESSION['webauthn_reg_challenge_uid'],
                $_SESSION['webauthn_reg_challenge_ts']);
        }

        $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
          json_response(['success' => false, 'message' => 'Code employé introuvable.']);
        }

        $pdo->prepare("UPDATE employes SET webauthn_credential_id = ?, webauthn_credential = ? WHERE code_employe = ?")
            ->execute([$credential_id, (!empty($attestation_b64) ? $attestation_b64 : $credential_id), $code_employe]);

        json_response(['success' => true, 'message' => 'Empreinte enregistrée avec succès !']);
    }

    // --- Vérifier si employé a une empreinte enregistrée ---
    if ($action === 'check_credential') {
        if (empty($code_employe)) {
          json_response(['success' => false, 'has_credential' => false]);
        }
        $stmt = $pdo->prepare("SELECT id, webauthn_credential_id, webauthn_credential, nom, prenom FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
          json_response(['success' => false, 'has_credential' => false, 'message' => 'Code introuvable.']);
        }

        if ($employe['webauthn_credential_id']) {
            // Generate server-side authentication challenge (prevents direct-POST bypass)
            $cBytes = random_bytes(32);
            $cB64   = rtrim(strtr(base64_encode($cBytes), '+/', '-_'), '=');
            $_SESSION['webauthn_auth_challenge']     = bin2hex($cBytes);
            $_SESSION['webauthn_auth_challenge_uid'] = (int)$employe['id'];
            $_SESSION['webauthn_auth_challenge_ts']  = time();
            json_response([
                'success'        => true,
                'has_credential' => true,
                'credential_id'  => $employe['webauthn_credential_id'],
                'nom'            => $employe['prenom'] . ' ' . $employe['nom'],
                'challenge'      => $cB64
            ]);
        } else {
            // Generate server-side registration challenge
            $cBytes = random_bytes(32);
            $cB64   = rtrim(strtr(base64_encode($cBytes), '+/', '-_'), '=');
            $_SESSION['webauthn_reg_challenge']     = bin2hex($cBytes);
            $_SESSION['webauthn_reg_challenge_uid'] = (int)$employe['id'];
            $_SESSION['webauthn_reg_challenge_ts']  = time();
            json_response([
                'success'        => true,
                'has_credential' => false,
                'nom'            => $employe['prenom'] . ' ' . $employe['nom'],
                'reg_challenge'  => $cB64
            ]);
        }
        }

        // --- Vérifier l'assertion WebAuthn côté serveur ---
        if ($action === 'verify_assertion') {
          $cdj_b64  = $_POST['client_data_json'] ?? '';
          $cred_rcvd = $_POST['credential_id']   ?? '';

          if (empty($code_employe) || empty($cdj_b64)) {
            json_response(['success' => false, 'message' => 'Données manquantes.']);
          }

          // Challenge must exist and be fresh (≤5 min)
          if (empty($_SESSION['webauthn_auth_challenge'])
              || (time() - ($_SESSION['webauthn_auth_challenge_ts'] ?? 0)) > 300) {
            unset($_SESSION['webauthn_auth_challenge'],
                  $_SESSION['webauthn_auth_challenge_uid'],
                  $_SESSION['webauthn_auth_challenge_ts']);
            json_response(['success' => false, 'message' => 'Challenge expiré. Revérifiez votre code employé.']);
          }

          // Decode and parse clientDataJSON
          $cdj    = base64_decode(strtr($cdj_b64, '-_', '+/'));
          $cdData = json_decode($cdj, true);

          if (!is_array($cdData)) {
            json_response(['success' => false, 'message' => 'ClientDataJSON invalide.']);
          }

          // Verify type
          if (($cdData['type'] ?? '') !== 'webauthn.get') {
            json_response(['success' => false, 'message' => 'Type WebAuthn invalide.']);
          }

          // Verify challenge
          $expB64 = rtrim(strtr(base64_encode(hex2bin($_SESSION['webauthn_auth_challenge'])), '+/', '-_'), '=');
          $rcv    = rtrim(strtr($cdData['challenge'] ?? '', '+/', '-_'), '=');
          if (!hash_equals($expB64, $rcv)) {
            json_response(['success' => false, 'message' => 'Challenge WebAuthn invalide. Recommencez.']);
          }

          // Verify origin
          $proto           = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          $expected_origin = $proto . '://' . $_SERVER['HTTP_HOST'];
          if (($cdData['origin'] ?? '') !== $expected_origin) {
            json_response(['success' => false, 'message' => 'Origine invalide.']);
          }

          // Look up employee; verify it is the same employee the challenge was issued for
          $stmt = $pdo->prepare("SELECT id, webauthn_credential_id FROM employes WHERE code_employe = ? AND actif = 1");
          $stmt->execute([$code_employe]);
          $employe = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$employe || (int)$employe['id'] !== (int)$_SESSION['webauthn_auth_challenge_uid']) {
            json_response(['success' => false, 'message' => 'Employé invalide pour ce challenge.']);
          }

          // Credential ID sent by client must match registered one
          if (!empty($cred_rcvd) && !empty($employe['webauthn_credential_id'])) {
            if (!hash_equals($employe['webauthn_credential_id'], $cred_rcvd)) {
              json_response(['success' => false, 'message' => 'Credential invalide.']);
            }
          }

          // Consume challenge (one-time use)
          $uid = (int)$employe['id'];
          unset($_SESSION['webauthn_auth_challenge'],
                $_SESSION['webauthn_auth_challenge_uid'],
                $_SESSION['webauthn_auth_challenge_ts']);

          // Grant short-lived assertion session token (2 min, one-use)
          $_SESSION['webauthn_assertion'] = [
            'employe_id' => $uid,
            'expires_at' => time() + 120,
          ];

          json_response(['success' => true]);
        }

        // --- Demande OTP fallback (à approuver par manager) ---
        if ($action === 'request_otp_fallback') {
          if (!otp_table_ready($pdo)) {
            json_response(['success' => false, 'message' => 'OTP fallback indisponible. Appliquez la mise à jour SQL puis réessayez.']);
          }
          $reason = trim($_POST['reason'] ?? '');

          if (empty($code_employe) || !in_array($type, ['arrivee', 'depart'], true)) {
            json_response(['success' => false, 'message' => 'Données invalides.']);
          }

          $distance = null;
          if (!validate_geofence($latitude, $longitude, $distance)) {
            json_response([
              'success' => false,
              'message' => '❌ Hors zone autorisée. Rapprochez-vous du site pour demander un OTP.'
            ]);
          }

          $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
          $stmt->execute([$code_employe]);
          $employe = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$employe) {
            json_response(['success' => false, 'message' => 'Code employé introuvable.']);
          }

          $today = (new DateTime())->format('Y-m-d');
          $exists = $pdo->prepare("SELECT id FROM pointages WHERE employe_id = ? AND type = ? AND date_pointage = ?");
          $exists->execute([$employe['id'], $type, $today]);
          if ($exists->fetch()) {
            $label = $type === 'arrivee' ? 'arrivée' : 'départ';
            json_response(['success' => false, 'message' => "Vous avez déjà pointé votre $label aujourd'hui.", 'warning' => true]);
          }

          $insert = $pdo->prepare(
            "INSERT INTO otp_fallback_requests (employe_id, type, requested_latitude, requested_longitude, requested_address, request_reason) VALUES (?,?,?,?,?,?)"
          );
          $insert->execute([
            $employe['id'],
            $type,
            (float)$latitude,
            (float)$longitude,
            $adresse ?: null,
            $reason ?: null
          ]);

          json_response([
            'success' => true,
            'message' => 'Demande OTP envoyée au manager. Attendez la validation.',
            'distance' => round((float)$distance, 1)
          ]);
        }

        // --- Vérifier OTP fallback validé ---
        if ($action === 'verify_otp_fallback') {
          if (!otp_table_ready($pdo)) {
            json_response(['success' => false, 'message' => 'OTP fallback indisponible. Appliquez la mise à jour SQL puis réessayez.']);
          }
          $otp = trim($_POST['otp'] ?? '');

          if (empty($code_employe) || empty($otp) || !in_array($type, ['arrivee', 'depart'], true)) {
            json_response(['success' => false, 'message' => 'Données invalides.']);
          }

          $distance = null;
          if (!validate_geofence($latitude, $longitude, $distance)) {
            json_response(['success' => false, 'message' => '❌ Vous êtes hors zone autorisée.']);
          }

          $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
          $stmt->execute([$code_employe]);
          $employe = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$employe) {
            json_response(['success' => false, 'message' => 'Code employé introuvable.']);
          }

          $maintenant    = new DateTime();
          $heure         = $maintenant->format('Y-m-d H:i:s');
          $date_pointage = $maintenant->format('Y-m-d');

          $exists = $pdo->prepare("SELECT id FROM pointages WHERE employe_id = ? AND type = ? AND date_pointage = ?");
          $exists->execute([$employe['id'], $type, $date_pointage]);
          if ($exists->fetch()) {
            $label = $type === 'arrivee' ? 'arrivée' : 'départ';
            json_response(['success' => false, 'message' => "Vous avez déjà pointé votre $label aujourd'hui.", 'warning' => true]);
          }

          $otpReq = $pdo->prepare(
            "SELECT * FROM otp_fallback_requests
             WHERE employe_id = ? AND type = ? AND status = 'approved'
               AND otp_expires_at >= NOW() AND otp_used_at IS NULL
             ORDER BY approved_at DESC, id DESC LIMIT 1"
          );
          $otpReq->execute([$employe['id'], $type]);
          $req = $otpReq->fetch(PDO::FETCH_ASSOC);

          if (!$req || empty($req['otp_hash']) || !password_verify($otp, $req['otp_hash'])) {
            json_response(['success' => false, 'message' => 'OTP invalide ou expiré.']);
          }

          insert_pointage($pdo, [
            'employe_id' => $employe['id'],
            'type' => $type,
            'heure' => $heure,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'adresse' => $adresse ?: null,
            'verification_method' => 'otp_fallback',
            'otp_request_id' => $req['id'],
            'date_pointage' => $date_pointage
          ]);

          $pdo->prepare("UPDATE otp_fallback_requests SET status='used', otp_used_at=NOW() WHERE id=?")
            ->execute([$req['id']]);

          $label   = $type === 'arrivee' ? 'Arrivée' : 'Départ';
          $heure_f = $maintenant->format('H:i');
          json_response(['success' => true, 'message' => "✓ $label enregistrée via OTP pour {$employe['prenom']} {$employe['nom']} à $heure_f."]);
    }

    // --- Pointage ---
    if ($action === 'pointer') {
          // Require a valid WebAuthn assertion token (set by verify_assertion)
          $assertTok = $_SESSION['webauthn_assertion'] ?? null;
          if (!$assertTok || (int)($assertTok['expires_at'] ?? 0) < time()) {
            unset($_SESSION['webauthn_assertion']);
            json_response(['success' => false, 'message' => '🔒 Authentification biométrique requise. Posez votre doigt à nouveau.']);
          }

          $distance = null;
          if (!validate_geofence($latitude, $longitude, $distance)) {
            json_response(['success' => false, 'message' => '❌ Localisation requise dans la zone autorisée.']);
          }
        if (empty($code_employe) || !in_array($type, ['arrivee', 'depart'])) {
            json_response(['success' => false, 'message' => 'Données invalides.']);
        }

        $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
          json_response(['success' => false, 'message' => 'Code employé introuvable.']);
        }

        // Verify assertion token belongs to this employee, then consume it
        if ((int)$assertTok['employe_id'] !== (int)$employe['id']) {
          json_response(['success' => false, 'message' => '🔒 Jeton biométrique invalide.']);
        }
        unset($_SESSION['webauthn_assertion']);

        $maintenant    = new DateTime();
        $heure         = $maintenant->format('Y-m-d H:i:s');
        $date_pointage = $maintenant->format('Y-m-d');

        $stmt2 = $pdo->prepare("SELECT id FROM pointages WHERE employe_id = ? AND type = ? AND date_pointage = ?");
        $stmt2->execute([$employe['id'], $type, $date_pointage]);

        if ($stmt2->fetch()) {
            $label = $type === 'arrivee' ? "arrivée" : "départ";
          json_response(['success' => false, 'message' => "Vous avez déjà pointé votre $label aujourd'hui.", 'warning' => true]);
        }

        insert_pointage($pdo, [
          'employe_id' => $employe['id'],
          'type' => $type,
          'heure' => $heure,
          'latitude' => (float)$latitude,
          'longitude' => (float)$longitude,
          'adresse' => $adresse ?: null,
          'verification_method' => 'webauthn',
          'otp_request_id' => null,
          'date_pointage' => $date_pointage
        ]);

        $label   = $type === 'arrivee' ? "Arrivée" : "Départ";
        $heure_f = $maintenant->format('H:i');
        json_response(['success' => true, 'message' => "✓ $label enregistrée pour {$employe['prenom']} {$employe['nom']} à $heure_f."]);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pointage Employé</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', sans-serif; background: #f0f4f8;
      min-height: 100vh; display: flex; align-items: center;
      justify-content: center; padding: 20px;
    }
    .card {
      background: #fff; border-radius: 16px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.10);
      padding: 36px 32px; width: 100%; max-width: 440px;
    }
    .logo { text-align: center; margin-bottom: 22px; }
    .logo .icon {
      width: 60px; height: 60px; background: #1D9E75; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 10px; font-size: 26px;
    }
    .logo h1 { font-size: 21px; font-weight: 600; color: #1a1a2e; }
    .logo p  { font-size: 13px; color: #888; margin-top: 3px; }

    .clock {
      text-align: center; background: #f7f9fc;
      border-radius: 10px; padding: 12px; margin-bottom: 20px;
    }
    .clock .time { font-size: 34px; font-weight: 700; color: #1D9E75; letter-spacing: 2px; }
    .clock .date { font-size: 13px; color: #888; margin-top: 2px; }

    label { display: block; font-size: 13px; font-weight: 500; color: #555; margin-bottom: 6px; }
    .input-row { display: flex; gap: 8px; margin-bottom: 16px; }
    input[type="text"] {
      flex: 1; padding: 12px 14px; border: 1.5px solid #dde3ee;
      border-radius: 8px; font-size: 15px; color: #1a1a2e; outline: none;
    }
    input[type="text"]:focus { border-color: #1D9E75; }
    .btn-verif {
      padding: 12px 16px; background: #1a1a2e; color: #fff;
      border: none; border-radius: 8px; font-size: 14px;
      font-weight: 600; cursor: pointer;
    }

    /* GPS */
    .gps-status {
      display: flex; align-items: center; gap: 8px; font-size: 12px;
      color: #888; margin-bottom: 14px; padding: 10px 12px;
      background: #f7f9fc; border-radius: 8px; border: 1.5px solid #eee;
    }
    .gps-dot { width: 10px; height: 10px; border-radius: 50%; background: #ccc; flex-shrink: 0; }
    .gps-dot.loading { background: #F5A623; animation: pulse 1s infinite; }
    .gps-dot.ok      { background: #1D9E75; }
    .gps-dot.err     { background: #E24B4A; }
    @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.3} }

    .gps-alerte {
      display: none; background: #fff3cd; border: 1.5px solid #F5A623;
      border-radius: 10px; padding: 14px; margin-bottom: 14px;
    }
    .gps-alerte .titre { font-size: 14px; font-weight: 700; color: #854F0B; margin-bottom: 8px; text-align:center; }
    .gps-alerte .texte { font-size: 12px; color: #6d4c0f; line-height: 1.6; }
    .gps-alerte.visible { display: block; }

    /* Enregistrement */
    .register-box {
      display: none; background: #e8f4ff; border: 1.5px solid #378ADD;
      border-radius: 10px; padding: 16px; margin-bottom: 14px; text-align: center;
    }
    .register-box p { font-size: 13px; color: #1a5276; margin-bottom: 12px; line-height: 1.5; }
    .btn-register {
      padding: 10px 22px; background: #378ADD; color: #fff;
      border: none; border-radius: 8px; font-size: 14px;
      font-weight: 600; cursor: pointer;
    }
    .register-box.visible { display: block; }

    /* Pointage empreinte */
    .pointage-box {
      display: none; background: #f7f9fc; border: 1.5px solid #eee;
      border-radius: 12px; padding: 22px; margin-bottom: 14px; text-align: center;
    }
    .pointage-box.visible { display: block; }
    .emp-nom  { font-size: 16px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
    .emp-sous { font-size: 12px; color: #888; margin-bottom: 20px; }

    .fp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fp-item { display: flex; flex-direction: column; align-items: center; gap: 8px; }
    .fp-label { font-size: 13px; font-weight: 600; color: #555; }

    .fp-btn {
      width: 76px; height: 76px; border-radius: 50%; border: none;
      cursor: pointer; font-size: 34px; display: flex;
      align-items: center; justify-content: center;
      transition: transform .15s, box-shadow .2s;
    }
    .fp-btn.arrivee {
      background: #e1f5ee;
      box-shadow: 0 0 0 5px #1D9E7522;
    }
    .fp-btn.depart {
      background: #fcebeb;
      box-shadow: 0 0 0 5px #E24B4A22;
    }
    .fp-btn:hover:not(:disabled) { transform: scale(1.06); }
    .fp-btn:active:not(:disabled) { transform: scale(0.94); }
    .fp-btn:disabled { opacity: 0.35; cursor: not-allowed; }

    .otp-help {
      margin-top: 18px; background: #fff; border: 1.5px solid #f0d8a3;
      border-radius: 10px; padding: 12px; text-align: left;
    }
    .otp-help .title { font-size: 12px; color: #854F0B; font-weight: 700; margin-bottom: 6px; }
    .otp-help p { font-size: 12px; color: #6d4c0f; line-height: 1.45; margin-bottom: 8px; }
    .otp-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
    .otp-btn {
      border: none; border-radius: 7px; padding: 7px 10px; font-size: 12px;
      font-weight: 600; cursor: pointer; color: #fff;
    }
    .otp-btn.arrivee { background: #1D9E75; }
    .otp-btn.depart { background: #E24B4A; }
    .otp-verify { display: flex; gap: 8px; }
    .otp-verify input {
      flex: 1; padding: 9px 10px; border: 1.5px solid #dde3ee; border-radius: 7px;
      font-size: 12px; outline: none;
    }
    .otp-verify button {
      border: none; border-radius: 7px; padding: 9px 10px; background: #1a1a2e;
      color: #fff; font-size: 12px; font-weight: 600; cursor: pointer;
    }

    /* Message */
    .msg-box {
      display: none; padding: 12px 14px; border-radius: 8px;
      font-size: 14px; font-weight: 500; text-align: center; margin-top: 14px;
    }
    .msg-box.success { background: #e1f5ee; color: #0F6E56; }
    .msg-box.error   { background: #fcebeb; color: #A32D2D; }
    .msg-box.warning { background: #faeeda; color: #854F0B; }
    .msg-box.info    { background: #e8f4ff; color: #1a5276; }
    .msg-box.visible { display: block; }

    .admin-link { text-align: center; margin-top: 18px; font-size: 12px; color: #aaa; }
    .admin-link a { color: #1D9E75; text-decoration: none; }
  </style>
</head>
<body>
<div class="card">

  <div class="logo">
    <div class="icon">⏱</div>
    <h1>Système de Pointage</h1>
    <p>Enregistrez votre présence</p>
  </div>

  <div class="clock">
    <div class="time" id="clock-time">--:--:--</div>
    <div class="date" id="clock-date"></div>
  </div>

  <!-- GPS -->
  <div class="gps-status">
    <div class="gps-dot loading" id="gps-dot"></div>
    <span id="gps-text">Récupération de la localisation...</span>
  </div>
  <div class="gps-alerte" id="gps-alerte">
    <div class="titre">📍 Activez votre position pour pointer !</div>
    <div class="texte">
      <strong>📱 iPhone :</strong> Réglages → Confidentialité → Service de localisation → Activer<br>
      Puis Réglages → Safari → Localisation → Autoriser<br><br>
      <strong>📱 Android :</strong> Paramètres → Localisation → Activer<br><br>
      <strong>💻 Navigateur :</strong> Cliquez sur 🔒 dans la barre d'adresse → Localisation → Autoriser<br><br>
      Ensuite <strong>rechargez la page</strong>.
    </div>
  </div>

  <!-- Code employé -->
  <div>
    <label>Votre code employé</label>
    <div class="input-row">
      <input type="text" id="code_employe" placeholder="Ex : EMP001" autocomplete="off"/>
      <button class="btn-verif" onclick="verifierCode()">Vérifier →</button>
    </div>
  </div>

  <!-- Enregistrement empreinte (1ère fois) -->
  <div class="register-box" id="register-box">
    <p>👆 Aucune empreinte enregistrée pour votre compte.<br>
       Enregistrez votre empreinte digitale pour pouvoir pointer.</p>
    <button class="btn-register" onclick="enregistrerEmpreinte()">
      👆 Enregistrer mon empreinte
    </button>
  </div>

  <!-- Pointage avec empreinte -->
  <div class="pointage-box" id="pointage-box">
    <div class="emp-nom" id="emp-nom"></div>
    <div class="emp-sous">Appuyez sur un bouton et posez votre doigt</div>
    <div class="fp-grid">
      <div class="fp-item">
        <div class="fp-label" style="color:#1D9E75;">🟢 Arrivée</div>
        <button class="fp-btn arrivee" id="btn-arrivee" onclick="pointerEmpreinte('arrivee')" disabled>👆</button>
      </div>
      <div class="fp-item">
        <div class="fp-label" style="color:#E24B4A;">🔴 Départ</div>
        <button class="fp-btn depart" id="btn-depart" onclick="pointerEmpreinte('depart')" disabled>👆</button>
      </div>
    </div>

    <div class="otp-help">
      <div class="title">Problème biométrique ? OTP avec validation manager</div>
      <p>Si votre empreinte ne fonctionne pas, demandez un OTP pour Arrivée ou Départ. Votre manager devra valider votre demande.</p>
      <div class="otp-actions">
        <button class="otp-btn arrivee" onclick="demanderOtp('arrivee')">Demander OTP Arrivée</button>
        <button class="otp-btn depart" onclick="demanderOtp('depart')">Demander OTP Départ</button>
      </div>
      <div class="otp-verify">
        <input type="text" id="otp_code" placeholder="Entrer OTP (6 chiffres)" maxlength="6"/>
        <button onclick="validerOtp()">Valider OTP</button>
      </div>
    </div>
  </div>

  <div class="msg-box" id="msg-box"></div>

  <!-- Champs cachés -->
  <input type="hidden" id="lat"/>
  <input type="hidden" id="lng"/>
  <input type="hidden" id="adresse_field"/>

  <div class="admin-link"><a href="login.php">Accès administrateur →</a></div>
</div>

<script>
let otpType = 'arrivee';
let serverChallenge    = null; // auth challenge (Uint8Array) from check_credential
let regServerChallenge = null; // registration challenge (Uint8Array)

// ===== BASE64URL HELPERS =====
function b64urlToBytes(b64url) {
  const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
  const padded = b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=');
  return Uint8Array.from(atob(padded), c => c.charCodeAt(0));
}
function bytesToB64url(bytes) {
  let bin = '';
  bytes.forEach(b => bin += String.fromCharCode(b));
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

// ===== HORLOGE =====
const JOURS = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
const MOIS  = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
function clock() {
  const n = new Date();
  document.getElementById('clock-time').textContent =
    String(n.getHours()).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0');
  document.getElementById('clock-date').textContent =
    JOURS[n.getDay()]+' '+n.getDate()+' '+MOIS[n.getMonth()]+' '+n.getFullYear();
}
setInterval(clock, 1000); clock();

// ===== GPS =====
let gpsOk = false;
if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(pos => {
    const lat = pos.coords.latitude.toFixed(7);
    const lng = pos.coords.longitude.toFixed(7);
    document.getElementById('lat').value = lat;
    document.getElementById('lng').value = lng;
    const dot = document.getElementById('gps-dot');
    dot.classList.remove('loading'); dot.classList.add('ok');
    document.getElementById('gps-text').textContent = '✓ Localisation obtenue';
    document.getElementById('gps-alerte').classList.remove('visible');
    gpsOk = true;
    fetch('https://nominatim.openstreetmap.org/reverse?lat='+lat+'&lon='+lng+'&format=json')
      .then(r=>r.json()).then(d=>{
        if(d&&d.display_name) {
          document.getElementById('adresse_field').value = d.display_name;
          const v = d.address.city||d.address.town||d.address.village||'';
          const r = d.address.road||'';
          document.getElementById('gps-text').textContent = '✓ '+(r?r+', ':'')+v;
        }
      }).catch(()=>{});
  }, () => {
    const dot = document.getElementById('gps-dot');
    dot.classList.remove('loading'); dot.classList.add('err');
    document.getElementById('gps-text').textContent = '❌ Localisation refusée';
    document.getElementById('gps-alerte').classList.add('visible');
    gpsOk = false;
  }, { enableHighAccuracy: true, timeout: 15000 });
} else {
  document.getElementById('gps-dot').classList.add('err');
  document.getElementById('gps-text').textContent = '❌ GPS non supporté';
  document.getElementById('gps-alerte').classList.add('visible');
}

// ===== MESSAGE =====
function msg(texte, type) {
  const b = document.getElementById('msg-box');
  b.textContent = texte;
  b.className = 'msg-box ' + type + ' visible';
}

async function postAction(formData) {
  const res = await fetch('', { method: 'POST', body: formData });
  return res.json();
}

// ===== VÉRIFIER CODE =====
async function verifierCode() {
  if (!gpsOk) { msg('⚠️ Activez votre localisation d\'abord.', 'error'); return; }
  const code = document.getElementById('code_employe').value.trim();
  if (!code) { msg('Entrez votre code employé.', 'error'); return; }

  msg('Vérification...', 'info');
  let data;
  try {
    const fd = new FormData();
    fd.append('action', 'check_credential');
    fd.append('code_employe', code);
    const res = await fetch('', { method: 'POST', body: fd });
    data = await res.json();
  } catch (e) {
    msg('Erreur de communication avec le serveur. Réessayez.', 'error');
    console.error(e);
    return;
  }

  if (!data.success) { msg(data.message || 'Code introuvable.', 'error'); return; }

  document.getElementById('emp-nom').textContent = data.nom;

  if (data.has_credential) {
    serverChallenge = data.challenge ? b64urlToBytes(data.challenge) : null;
    document.getElementById('register-box').classList.remove('visible');
    document.getElementById('pointage-box').classList.add('visible');
    document.getElementById('btn-arrivee').disabled = false;
    document.getElementById('btn-depart').disabled  = false;
    msg('Bonjour ' + data.nom + ' ! Posez votre doigt pour pointer.', 'info');
  } else {
    regServerChallenge = data.reg_challenge ? b64urlToBytes(data.reg_challenge) : null;
    document.getElementById('pointage-box').classList.remove('visible');
    document.getElementById('register-box').classList.add('visible');
    msg('Première connexion détectée. Enregistrez votre empreinte.', 'info');
  }
}

// ===== OTP FALLBACK =====
async function demanderOtp(type) {
  if (!gpsOk) { msg('⚠️ Activez votre localisation pour envoyer une demande OTP.', 'error'); return; }

  const code = document.getElementById('code_employe').value.trim();
  if (!code) { msg('Entrez votre code employé.', 'error'); return; }

  const reason = prompt('Raison du fallback OTP (ex: capteur indisponible) :', 'Capteur indisponible');
  if (reason === null) { return; }

  otpType = type;
  const fd = new FormData();
  fd.append('action', 'request_otp_fallback');
  fd.append('code_employe', code);
  fd.append('type', type);
  fd.append('latitude', document.getElementById('lat').value);
  fd.append('longitude', document.getElementById('lng').value);
  fd.append('adresse', document.getElementById('adresse_field').value);
  fd.append('reason', reason.trim());

  msg('Envoi de la demande OTP au manager...', 'info');
  const data = await postAction(fd);
  msg(data.message, data.success ? 'success' : (data.warning ? 'warning' : 'error'));
}

async function validerOtp() {
  if (!gpsOk) { msg('⚠️ Activez votre localisation pour valider OTP.', 'error'); return; }

  const code = document.getElementById('code_employe').value.trim();
  const otp = document.getElementById('otp_code').value.trim();
  if (!code || !otp) { msg('Code employé et OTP requis.', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'verify_otp_fallback');
  fd.append('code_employe', code);
  fd.append('type', otpType);
  fd.append('otp', otp);
  fd.append('latitude', document.getElementById('lat').value);
  fd.append('longitude', document.getElementById('lng').value);
  fd.append('adresse', document.getElementById('adresse_field').value);

  msg('Vérification OTP en cours...', 'info');
  const data = await postAction(fd);
  msg(data.message, data.success ? 'success' : (data.warning ? 'warning' : 'error'));
  if (data.success) {
    document.getElementById('otp_code').value = '';
  }
}

// ===== ENREGISTRER EMPREINTE =====
async function enregistrerEmpreinte() {
  if (!window.PublicKeyCredential) {
    msg('Biométrie non supportée. Utilisez Chrome ou Safari sur téléphone.', 'error'); return;
  }
  const code = document.getElementById('code_employe').value.trim();
  if (!regServerChallenge) {
    msg('⚠️ Rechargez votre code employé avant d\'enregistrer.', 'error'); return;
  }
  try {
    msg('👆 Posez votre doigt sur le capteur...', 'info');
    const cred = await navigator.credentials.create({
      publicKey: {
        challenge: regServerChallenge,
        rp: { name: "Pointage" },
        user: { id: new TextEncoder().encode(code), name: code, displayName: code },
        pubKeyCredParams: [{ type: "public-key", alg: -7 }],
        authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" },
        timeout: 60000
      }
    });

    const credId         = bytesToB64url(new Uint8Array(cred.rawId));
    const clientDataJSON = bytesToB64url(new Uint8Array(cred.response.clientDataJSON));
    const attestation    = bytesToB64url(new Uint8Array(cred.response.attestationObject));

    const fd = new FormData();
    fd.append('action',           'register_credential');
    fd.append('code_employe',     code);
    fd.append('credential_id',    credId);
    fd.append('client_data_json', clientDataJSON);
    fd.append('attestation_object', attestation);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
      regServerChallenge = null;
      msg('✅ Empreinte enregistrée ! Vérification en cours...', 'info');
      // Re-check to get a fresh auth challenge so the employee can punch immediately
      const fd2 = new FormData();
      fd2.append('action', 'check_credential');
      fd2.append('code_employe', code);
      const res2  = await fetch('', { method: 'POST', body: fd2 });
      const data2 = await res2.json();
      if (data2.success && data2.has_credential && data2.challenge) {
        serverChallenge = b64urlToBytes(data2.challenge);
      }
      document.getElementById('register-box').classList.remove('visible');
      document.getElementById('pointage-box').classList.add('visible');
      document.getElementById('btn-arrivee').disabled = false;
      document.getElementById('btn-depart').disabled  = false;
      msg('✅ Empreinte enregistrée ! Vous pouvez maintenant pointer.', 'success');
    } else {
      msg(data.message, 'error');
    }
  } catch (e) {
    msg(e.name === 'NotAllowedError' ? '❌ Empreinte refusée. Réessayez.' : '❌ Erreur : ' + e.message, 'error');
  }
}
// ===== POINTER AVEC EMPREINTE =====
async function pointerEmpreinte(type) {
  if (!gpsOk) { msg('⚠️ Activez votre localisation pour pointer.', 'error'); return; }
  if (!window.PublicKeyCredential) { msg('Biométrie non supportée.', 'error'); return; }
  if (!serverChallenge) { msg('⚠️ Revérifiez votre code employé avant de pointer.', 'error'); return; }

  const label = type === 'arrivee' ? 'Arrivée' : 'Départ';
  const code  = document.getElementById('code_employe').value.trim();

  try {
    msg('👆 Posez votre doigt pour valider ' + label + '...', 'info');

    const assertion = await navigator.credentials.get({
      publicKey: { challenge: serverChallenge, userVerification: "required", timeout: 60000 }
    });

    // Send assertion to server: challenge + origin verified before granting token
    const vfd = new FormData();
    vfd.append('action',             'verify_assertion');
    vfd.append('code_employe',       code);
    vfd.append('client_data_json',   bytesToB64url(new Uint8Array(assertion.response.clientDataJSON)));
    vfd.append('authenticator_data', bytesToB64url(new Uint8Array(assertion.response.authenticatorData)));
    vfd.append('signature',          bytesToB64url(new Uint8Array(assertion.response.signature)));
    vfd.append('credential_id',      bytesToB64url(new Uint8Array(assertion.rawId)));

    msg('🔐 Vérification biométrique côté serveur...', 'info');
    const vData = await postAction(vfd);
    serverChallenge = null; // consumed regardless of outcome

    if (!vData.success) {
      msg(vData.message || 'Vérification biométrique échouée.', 'error');
      return;
    }

    // Server assertion token set — now record attendance
    const fd = new FormData();
    fd.append('action',       'pointer');
    fd.append('code_employe', code);
    fd.append('type',         type);
    fd.append('latitude',     document.getElementById('lat').value);
    fd.append('longitude',    document.getElementById('lng').value);
    fd.append('adresse',      document.getElementById('adresse_field').value);

    const data = await postAction(fd);
    msg(data.message, data.success ? 'success' : (data.warning ? 'warning' : 'error'));

  } catch (e) {
    msg(e.name === 'NotAllowedError' ? '❌ Empreinte refusée ou annulée. Réessayez.' : '❌ Erreur : ' + e.message, 'error');
  }
}
</script>
</body>
</html>