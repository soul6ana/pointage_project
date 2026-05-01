<?php
// index.php - Page de pointage avec liaison d'appareil
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
  if (!ENFORCE_GEOFENCE) {
    return true;
  }
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

function get_today_pointage_state(PDO $pdo, int $employeId, string $datePointage): array {
  $hasArrivee = false;
  $hasDepart = false;

  $st = $pdo->prepare("SELECT type FROM pointages WHERE employe_id = ? AND date_pointage = ?");
  $st->execute([$employeId, $datePointage]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if ($t === 'arrivee') {
      $hasArrivee = true;
    }
    if ($t === 'depart') {
      $hasDepart = true;
    }
  }

  return [
    'has_arrivee' => $hasArrivee,
    'has_depart' => $hasDepart,
    'can_arrivee' => !$hasArrivee,
    'can_depart' => $hasArrivee && !$hasDepart,
  ];
}

function bind_or_validate_device(PDO $pdo, array $employe, string $deviceFingerprint, bool $bindIfMissing = false): array {
  if ($deviceFingerprint === '') {
    return ['ok' => false, 'bound_now' => false, 'message' => 'Identifiant appareil manquant. Rechargez la page puis réessayez.'];
  }

  $saved = trim((string)($employe['webauthn_credential_id'] ?? ''));

  // Legacy WebAuthn values are not SHA-256 hex fingerprints; replace once during first device-binding check.
  if ($saved !== '' && !preg_match('/^[a-f0-9]{64}$/', $saved)) {
    $meta = json_encode([
      'bound_at' => (new DateTime())->format('Y-m-d H:i:s'),
      'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
      'migrated_from_legacy' => true
    ]);
    $pdo->prepare("UPDATE employes SET webauthn_credential_id = ?, webauthn_credential = ? WHERE id = ?")
        ->execute([$deviceFingerprint, $meta, $employe['id']]);
    return ['ok' => true, 'bound_now' => true, 'message' => ''];
  }

  if ($saved !== '') {
    if (!hash_equals($saved, $deviceFingerprint)) {
      return [
        'ok' => false,
        'bound_now' => false,
        'message' => '🔒 Ce compte est lié à un autre téléphone. Utilisez l\'appareil déjà enregistré ou contactez votre manager.'
      ];
    }
    return ['ok' => true, 'bound_now' => false, 'message' => ''];
  }

  if (!$bindIfMissing) {
    return [
      'ok' => false,
      'bound_now' => false,
      'message' => 'Appareil non enregistré. Vérifiez votre code employé pour lier ce téléphone.'
    ];
  }

  $meta = json_encode([
    'bound_at' => (new DateTime())->format('Y-m-d H:i:s'),
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
  ]);

  $pdo->prepare("UPDATE employes SET webauthn_credential_id = ?, webauthn_credential = ? WHERE id = ?")
      ->execute([$deviceFingerprint, $meta, $employe['id']]);

  return ['ok' => true, 'bound_now' => true, 'message' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $code_employe = trim($_POST['code_employe'] ?? '');
    $type         = $_POST['type'] ?? '';
    $latitude     = $_POST['latitude'] ?? null;
    $longitude    = $_POST['longitude'] ?? null;
    $adresse      = trim($_POST['adresse'] ?? '');
    $device_fingerprint = trim($_POST['device_fingerprint'] ?? '');

    // --- Vérifier code employé + binding appareil ---
    if ($action === 'check_credential') {
        if (empty($code_employe)) {
          json_response(['success' => false, 'message' => 'Code employé requis.']);
        }

        $stmt = $pdo->prepare("SELECT id, webauthn_credential_id, nom, prenom FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
          json_response(['success' => false, 'message' => 'Code introuvable.']);
        }

        $deviceCheck = bind_or_validate_device($pdo, $employe, $device_fingerprint, true);
        if (!$deviceCheck['ok']) {
          json_response(['success' => false, 'message' => $deviceCheck['message']]);
        }

        $todayState = get_today_pointage_state($pdo, (int)$employe['id'], (new DateTime())->format('Y-m-d'));

        json_response([
          'success' => true,
          'nom' => $employe['prenom'] . ' ' . $employe['nom'],
          'device_bound_now' => $deviceCheck['bound_now'],
          'has_arrivee' => $todayState['has_arrivee'],
          'has_depart' => $todayState['has_depart'],
          'can_arrivee' => $todayState['can_arrivee'],
          'can_depart' => $todayState['can_depart']
        ]);
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
              'message' => '❌ Localisation requise. Activez votre GPS puis réessayez.'
            ]);
          }

          $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
          $stmt->execute([$code_employe]);
          $employe = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$employe) {
            json_response(['success' => false, 'message' => 'Code employé introuvable.']);
          }

          $deviceCheck = bind_or_validate_device($pdo, $employe, $device_fingerprint, false);
          if (!$deviceCheck['ok']) {
            json_response(['success' => false, 'message' => $deviceCheck['message']]);
          }

          $today = (new DateTime())->format('Y-m-d');
          $state = get_today_pointage_state($pdo, (int)$employe['id'], $today);
          if ($type === 'depart' && !$state['has_arrivee']) {
            json_response(['success' => false, 'message' => '⚠️ Vous devez pointer votre arrivée avant de demander un OTP départ.', 'warning' => true]);
          }
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
            json_response(['success' => false, 'message' => '❌ Localisation requise. Activez votre GPS puis réessayez.']);
          }

          $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
          $stmt->execute([$code_employe]);
          $employe = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$employe) {
            json_response(['success' => false, 'message' => 'Code employé introuvable.']);
          }

          $deviceCheck = bind_or_validate_device($pdo, $employe, $device_fingerprint, false);
          if (!$deviceCheck['ok']) {
            json_response(['success' => false, 'message' => $deviceCheck['message']]);
          }

          $maintenant    = new DateTime();
          $heure         = $maintenant->format('Y-m-d H:i:s');
          $date_pointage = $maintenant->format('Y-m-d');

          $state = get_today_pointage_state($pdo, (int)$employe['id'], $date_pointage);
          if ($type === 'depart' && !$state['has_arrivee']) {
            json_response(['success' => false, 'message' => '⚠️ Vous devez pointer votre arrivée avant le départ.', 'warning' => true]);
          }

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
          $distance = null;
          if (!validate_geofence($latitude, $longitude, $distance)) {
            json_response(['success' => false, 'message' => '❌ Localisation requise. Activez votre GPS puis réessayez.']);
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

        $deviceCheck = bind_or_validate_device($pdo, $employe, $device_fingerprint, false);
        if (!$deviceCheck['ok']) {
          json_response(['success' => false, 'message' => $deviceCheck['message']]);
        }

        $maintenant    = new DateTime();
        $heure         = $maintenant->format('Y-m-d H:i:s');
        $date_pointage = $maintenant->format('Y-m-d');

        $state = get_today_pointage_state($pdo, (int)$employe['id'], $date_pointage);
        if ($type === 'depart' && !$state['has_arrivee']) {
          json_response(['success' => false, 'message' => '⚠️ Vous devez pointer votre arrivée avant le départ.', 'warning' => true]);
        }

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

    /* Pointage */
    .pointage-box {
      display: none; background: #f7f9fc; border: 1.5px solid #eee;
      border-radius: 12px; padding: 22px; margin-bottom: 14px; text-align: center;
    }
    .pointage-box.visible { display: block; }
    .emp-nom  { font-size: 16px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
    .emp-sous { font-size: 12px; color: #888; margin-bottom: 10px; }

    .day-status {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin-bottom: 14px;
    }
    .state-chip {
      border-radius: 8px;
      padding: 8px 10px;
      font-size: 12px;
      font-weight: 600;
      text-align: center;
      border: 1px solid #e5e8ee;
      color: #555;
      background: #fff;
    }
    .state-chip.done.arrivee { background: #e1f5ee; border-color: #b9e7d7; color: #0F6E56; }
    .state-chip.done.depart { background: #fcebeb; border-color: #f6caca; color: #A32D2D; }
    .flow-hint {
      margin-bottom: 14px;
      font-size: 12px;
      color: #52607a;
      background: #eef3ff;
      border: 1px solid #d7e3ff;
      border-radius: 8px;
      padding: 8px 10px;
    }

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
    .otp-btn:disabled { opacity: .45; cursor: not-allowed; }
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

  <!-- Pointage -->
  <div class="pointage-box" id="pointage-box">
    <div class="emp-nom" id="emp-nom"></div>
    <div class="emp-sous">Appareil validé. Le flux est guidé automatiquement.</div>
    <div class="day-status">
      <div class="state-chip arrivee" id="chip-arrivee">Arrivée non faite</div>
      <div class="state-chip depart" id="chip-depart">Départ non fait</div>
    </div>
    <div class="flow-hint" id="flow-hint">Commencez par Arrivée.</div>
    <div class="fp-grid">
      <div class="fp-item">
        <div class="fp-label" style="color:#1D9E75;">🟢 Arrivée</div>
        <button class="fp-btn arrivee" id="btn-arrivee" onclick="pointerSimple('arrivee')" disabled>✓</button>
      </div>
      <div class="fp-item">
        <div class="fp-label" style="color:#E24B4A;">🔴 Départ</div>
        <button class="fp-btn depart" id="btn-depart" onclick="pointerSimple('depart')" disabled>✓</button>
      </div>
    </div>

    <div class="otp-help">
      <div class="title">Téléphone refusé ? OTP avec validation manager</div>
      <p>Si votre téléphone n'est pas reconnu, demandez un OTP pour Arrivée ou Départ. Votre manager devra valider la demande.</p>
      <div class="otp-actions">
        <button class="otp-btn arrivee" id="otp-arrivee" onclick="demanderOtp('arrivee')">Demander OTP Arrivée</button>
        <button class="otp-btn depart" id="otp-depart" onclick="demanderOtp('depart')">Demander OTP Départ</button>
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
</div>

<script>
let otpType = 'arrivee';
let deviceFingerprint = '';
let dayState = { has_arrivee: false, has_depart: false, can_arrivee: true, can_depart: false };

async function buildDeviceFingerprint() {
  let seed = localStorage.getItem('pointage_device_seed');
  if (!seed) {
    seed = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
    localStorage.setItem('pointage_device_seed', seed);
  }

  const base = [
    seed,
    navigator.userAgent || '',
    navigator.language || '',
    navigator.platform || '',
    String(navigator.hardwareConcurrency || ''),
    String(navigator.maxTouchPoints || ''),
    Intl.DateTimeFormat().resolvedOptions().timeZone || '',
    screen.width + 'x' + screen.height
  ].join('|');

  if (window.crypto && window.crypto.subtle) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(base));
    deviceFingerprint = Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
  } else {
    deviceFingerprint = btoa(base).slice(0, 120);
  }
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

function applyDayState(state) {
  dayState = {
    has_arrivee: !!state.has_arrivee,
    has_depart: !!state.has_depart,
    can_arrivee: !!state.can_arrivee,
    can_depart: !!state.can_depart,
  };

  const btnArrivee = document.getElementById('btn-arrivee');
  const btnDepart = document.getElementById('btn-depart');
  const otpArrivee = document.getElementById('otp-arrivee');
  const otpDepart = document.getElementById('otp-depart');
  const chipArrivee = document.getElementById('chip-arrivee');
  const chipDepart = document.getElementById('chip-depart');
  const flowHint = document.getElementById('flow-hint');

  btnArrivee.disabled = !dayState.can_arrivee;
  btnDepart.disabled = !dayState.can_depart;
  otpArrivee.disabled = !dayState.can_arrivee;
  otpDepart.disabled = !dayState.can_depart;

  chipArrivee.textContent = dayState.has_arrivee ? 'Arrivée validée' : 'Arrivée non faite';
  chipDepart.textContent = dayState.has_depart ? 'Départ validé' : 'Départ non fait';
  chipArrivee.className = 'state-chip arrivee' + (dayState.has_arrivee ? ' done' : '');
  chipDepart.className = 'state-chip depart' + (dayState.has_depart ? ' done' : '');

  if (dayState.has_arrivee && dayState.has_depart) {
    flowHint.textContent = 'Journée terminée : arrivée et départ déjà enregistrés.';
  } else if (dayState.can_depart) {
    flowHint.textContent = 'Arrivée validée. Vous pouvez maintenant pointer le départ.';
  } else {
    flowHint.textContent = 'Commencez par Arrivée.';
  }
}

async function postAction(formData) {
  formData.append('device_fingerprint', deviceFingerprint);
  const res = await fetch('', { method: 'POST', body: formData });
  return res.json();
}

// ===== VÉRIFIER CODE =====
async function verifierCode(silent = false) {
  if (!gpsOk) { msg('⚠️ Activez votre localisation d\'abord.', 'error'); return; }
  const code = document.getElementById('code_employe').value.trim();
  if (!code) { msg('Entrez votre code employé.', 'error'); return; }

  if (!silent) {
    msg('Vérification...', 'info');
  }
  let data;
  try {
    const fd = new FormData();
    fd.append('action', 'check_credential');
    fd.append('code_employe', code);
    data = await postAction(fd);
  } catch (e) {
    msg('Erreur de communication avec le serveur. Réessayez.', 'error');
    console.error(e);
    return;
  }

  if (!data.success) { msg(data.message || 'Code introuvable.', 'error'); return; }

  document.getElementById('emp-nom').textContent = data.nom;

  document.getElementById('pointage-box').classList.add('visible');
  applyDayState(data);
  if (data.device_bound_now) {
    msg('Téléphone lié avec succès pour ' + data.nom + '.', 'success');
  } else if (!silent) {
    msg('Bonjour ' + data.nom + ' ! Téléphone reconnu.', 'info');
  }
}

// ===== OTP FALLBACK =====
async function demanderOtp(type) {
  if (!gpsOk) { msg('⚠️ Activez votre localisation pour envoyer une demande OTP.', 'error'); return; }
  if (type === 'depart' && !dayState.can_depart) { msg('⚠️ Vous devez pointer l\'arrivée avant de demander un OTP départ.', 'warning'); return; }
  if (type === 'arrivee' && !dayState.can_arrivee) { msg('Arrivée déjà effectuée aujourd\'hui.', 'warning'); return; }

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
  if (otpType === 'depart' && !dayState.can_depart) { msg('⚠️ Vous devez pointer l\'arrivée avant le départ.', 'warning'); return; }
  if (otpType === 'arrivee' && !dayState.can_arrivee) { msg('Arrivée déjà effectuée aujourd\'hui.', 'warning'); return; }

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
    await verifierCode(true);
  }
}

// ===== POINTER =====
async function pointerSimple(type) {
  if (!gpsOk) { msg('⚠️ Activez votre localisation pour pointer.', 'error'); return; }
  if (type === 'depart' && !dayState.can_depart) { msg('⚠️ Vous devez pointer l\'arrivée avant le départ.', 'warning'); return; }
  if (type === 'arrivee' && !dayState.can_arrivee) { msg('Arrivée déjà effectuée aujourd\'hui.', 'warning'); return; }

  const code  = document.getElementById('code_employe').value.trim();
  const fd = new FormData();
  fd.append('action',       'pointer');
  fd.append('code_employe', code);
  fd.append('type',         type);
  fd.append('latitude',     document.getElementById('lat').value);
  fd.append('longitude',    document.getElementById('lng').value);
  fd.append('adresse',      document.getElementById('adresse_field').value);

  const label = type === 'arrivee' ? 'Arrivée' : 'Départ';
  msg('Validation ' + label + '...', 'info');
  const data = await postAction(fd);
  msg(data.message, data.success ? 'success' : (data.warning ? 'warning' : 'error'));
  if (data.success) {
    await verifierCode(true);
  }
}

buildDeviceFingerprint().catch(() => {
  msg('Impossible de préparer l\'identifiant appareil.', 'error');
});
</script>
</body>
</html>