<?php
// index.php - Page de pointage avec empreinte digitale
require_once 'config.php';

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action       = $_POST['action'] ?? '';
    $code_employe = trim($_POST['code_employe'] ?? '');
    $type         = $_POST['type'] ?? '';
    $latitude     = $_POST['latitude'] ?? null;
    $longitude    = $_POST['longitude'] ?? null;
    $adresse      = trim($_POST['adresse'] ?? '');

    // --- Enregistrer l'empreinte (première fois) ---
    if ($action === 'register_credential') {
        $credential_id = $_POST['credential_id'] ?? '';
        $credential    = $_POST['credential']    ?? '';

        if (empty($code_employe) || empty($credential_id)) {
            echo json_encode(['success' => false, 'message' => 'Données manquantes.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
            echo json_encode(['success' => false, 'message' => 'Code employé introuvable.']);
            exit;
        }

        $pdo->prepare("UPDATE employes SET webauthn_credential_id = ?, webauthn_credential = ? WHERE code_employe = ?")
            ->execute([$credential_id, $credential, $code_employe]);

        echo json_encode(['success' => true, 'message' => 'Empreinte enregistrée avec succès !']);
        exit;
    }

    // --- Vérifier si employé a une empreinte enregistrée ---
    if ($action === 'check_credential') {
        if (empty($code_employe)) {
            echo json_encode(['success' => false, 'has_credential' => false]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT webauthn_credential_id, webauthn_credential, nom, prenom FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
            echo json_encode(['success' => false, 'has_credential' => false, 'message' => 'Code introuvable.']);
            exit;
        }

        if ($employe['webauthn_credential_id']) {
            echo json_encode([
                'success'        => true,
                'has_credential' => true,
                'credential_id'  => $employe['webauthn_credential_id'],
                'nom'            => $employe['prenom'] . ' ' . $employe['nom']
            ]);
        } else {
            echo json_encode([
                'success'        => true,
                'has_credential' => false,
                'nom'            => $employe['prenom'] . ' ' . $employe['nom']
            ]);
        }
        exit;
    }

    // --- Pointage ---
    if ($action === 'pointer') {
        if (empty($latitude) || empty($longitude)) {
            echo json_encode(['success' => false, 'message' => '❌ Localisation requise ! Activez votre position et réessayez.']);
            exit;
        }
        if (empty($code_employe) || !in_array($type, ['arrivee', 'depart'])) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM employes WHERE code_employe = ? AND actif = 1");
        $stmt->execute([$code_employe]);
        $employe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employe) {
            echo json_encode(['success' => false, 'message' => 'Code employé introuvable.']);
            exit;
        }

        $maintenant    = new DateTime();
        $heure         = $maintenant->format('Y-m-d H:i:s');
        $date_pointage = $maintenant->format('Y-m-d');

        $stmt2 = $pdo->prepare("SELECT id FROM pointages WHERE employe_id = ? AND type = ? AND date_pointage = ?");
        $stmt2->execute([$employe['id'], $type, $date_pointage]);

        if ($stmt2->fetch()) {
            $label = $type === 'arrivee' ? "arrivée" : "départ";
            echo json_encode(['success' => false, 'message' => "Vous avez déjà pointé votre $label aujourd'hui.", 'warning' => true]);
            exit;
        }

        $pdo->prepare("INSERT INTO pointages (employe_id, type, heure, latitude, longitude, adresse, date_pointage) VALUES (?,?,?,?,?,?,?)")
            ->execute([$employe['id'], $type, $heure, $latitude, $longitude, $adresse ?: null, $date_pointage]);

        $label   = $type === 'arrivee' ? "Arrivée" : "Départ";
        $heure_f = $maintenant->format('H:i');
        echo json_encode(['success' => true, 'message' => "✓ $label enregistrée pour {$employe['prenom']} {$employe['nom']} à $heure_f."]);
        exit;
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
  </div>

  <div class="msg-box" id="msg-box"></div>

  <!-- Champs cachés -->
  <input type="hidden" id="lat"/>
  <input type="hidden" id="lng"/>
  <input type="hidden" id="adresse_field"/>

  <div class="admin-link"><a href="login.php">Accès administrateur →</a></div>
</div>

<script>
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

// ===== VÉRIFIER CODE =====
async function verifierCode() {
  if (!gpsOk) { msg('⚠️ Activez votre localisation d\'abord.', 'error'); return; }
  const code = document.getElementById('code_employe').value.trim();
  if (!code) { msg('Entrez votre code employé.', 'error'); return; }

  msg('Vérification...', 'info');
  const fd = new FormData();
  fd.append('action', 'check_credential');
  fd.append('code_employe', code);
  const res  = await fetch('', { method: 'POST', body: fd });
  const data = await res.json();

  if (!data.success) { msg(data.message || 'Code introuvable.', 'error'); return; }

  document.getElementById('emp-nom').textContent = data.nom;

  if (data.has_credential) {
    document.getElementById('register-box').classList.remove('visible');
    document.getElementById('pointage-box').classList.add('visible');
    document.getElementById('btn-arrivee').disabled = false;
    document.getElementById('btn-depart').disabled  = false;
    msg('Bonjour ' + data.nom + ' ! Posez votre doigt pour pointer.', 'info');
  } else {
    document.getElementById('pointage-box').classList.remove('visible');
    document.getElementById('register-box').classList.add('visible');
    msg('Première connexion détectée. Enregistrez votre empreinte.', 'info');
  }
}

// ===== ENREGISTRER EMPREINTE =====
async function enregistrerEmpreinte() {
  if (!window.PublicKeyCredential) {
    msg('Biométrie non supportée. Utilisez Chrome ou Safari sur téléphone.', 'error'); return;
  }
  const code = document.getElementById('code_employe').value.trim();
  try {
    msg('👆 Posez votre doigt sur le capteur...', 'info');
    const challenge = new Uint8Array(32);
    window.crypto.getRandomValues(challenge);

    const cred = await navigator.credentials.create({
      publicKey: {
        challenge,
        rp: { name: "Pointage" },
        user: { id: new TextEncoder().encode(code), name: code, displayName: code },
        pubKeyCredParams: [{ type: "public-key", alg: -7 }],
        authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" },
        timeout: 60000
      }
    });

    const credId = btoa(String.fromCharCode(...new Uint8Array(cred.rawId)));
    const fd = new FormData();
    fd.append('action', 'register_credential');
    fd.append('code_employe', code);
    fd.append('credential_id', credId);
    fd.append('credential', credId);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
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

  const label = type === 'arrivee' ? 'Arrivée' : 'Départ';
  try {
    msg('👆 Posez votre doigt pour valider ' + label + '...', 'info');
    const challenge = new Uint8Array(32);
    window.crypto.getRandomValues(challenge);

    await navigator.credentials.get({
      publicKey: { challenge, userVerification: "required", timeout: 60000 }
    });

    // Empreinte OK → envoyer pointage
    const fd = new FormData();
    fd.append('action',       'pointer');
    fd.append('code_employe', document.getElementById('code_employe').value.trim());
    fd.append('type',         type);
    fd.append('latitude',     document.getElementById('lat').value);
    fd.append('longitude',    document.getElementById('lng').value);
    fd.append('adresse',      document.getElementById('adresse_field').value);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();
    msg(data.message, data.success ? 'success' : (data.warning ? 'warning' : 'error'));

  } catch (e) {
    msg(e.name === 'NotAllowedError' ? '❌ Empreinte refusée ou annulée. Réessayez.' : '❌ Erreur : ' + e.message, 'error');
  }
}
</script>
</body>
</html>