<?php
// admin.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: login.php'); exit; }

$role     = $_SESSION['admin_role'] ?? 'subadmin';
$admin_id = $_SESSION['admin_id'];
$tab      = $_GET['tab'] ?? 'pointages';

$otpTableReady = false;
try {
  $otpTableReady = (bool)$pdo->query("SHOW TABLES LIKE 'otp_fallback_requests'")->fetchColumn();
} catch (Throwable $e) {
  $otpTableReady = false;
}

$auditTableReady = false;
try {
  $auditTableReady = (bool)$pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn();
} catch (Throwable $e) {
  $auditTableReady = false;
}

// ===== ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Créer sous-admin
    if ($_POST['action'] === 'creer_subadmin' && $role === 'superadmin') {
        try {
            $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admins (username,password,nom_complet,role,created_by) VALUES (?,?,?,'subadmin',?)")
                ->execute([trim($_POST['username']), $hash, trim($_POST['nom_complet']), $admin_id]);
            header('Location: admin.php?tab=subadmins&msg=created'); exit;
        } catch (PDOException $e) { $erreur_form = "Nom d'utilisateur déjà pris."; }
    }

    // Supprimer sous-admin
    if ($_POST['action'] === 'supprimer_subadmin' && $role === 'superadmin') {
        $pdo->prepare("DELETE FROM admins WHERE id=? AND role='subadmin'")->execute([$_POST['id']]);
        header('Location: admin.php?tab=subadmins&msg=deleted'); exit;
    }

    // Ajouter employé
    if ($_POST['action'] === 'ajouter_employe') {
        try {
            $pdo->prepare("INSERT INTO employes (code_employe,nom,prenom,poste,email,telephone,created_by_admin) VALUES (?,?,?,?,?,?,?)")
                ->execute([trim($_POST['code_employe']),trim($_POST['nom']),trim($_POST['prenom']),trim($_POST['poste']),trim($_POST['email']),trim($_POST['telephone']),$admin_id]);
            header('Location: admin.php?tab=employes&msg=ajoute'); exit;
        } catch (PDOException $e) { $erreur_emp = "Ce code employé existe déjà."; }
    }

    // Supprimer employé
    if ($_POST['action'] === 'supprimer_employe') {
        $id = (int)$_POST['id'];
        if ($role === 'superadmin') {
            $pdo->prepare("UPDATE employes SET actif=0 WHERE id=?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE employes SET actif=0 WHERE id=? AND created_by_admin=?")->execute([$id, $admin_id]);
        }
        header('Location: admin.php?tab=employes&msg=supprime'); exit;
    }

    // Réinitialiser liaison appareil
    if ($_POST['action'] === 'reset_device_binding') {
      $id = (int)$_POST['id'];

      if ($role === 'superadmin') {
        $sel = $pdo->prepare("SELECT id, code_employe, nom, prenom FROM employes WHERE id=? AND actif=1");
        $sel->execute([$id]);
      } else {
        $sel = $pdo->prepare("SELECT id, code_employe, nom, prenom FROM employes WHERE id=? AND actif=1 AND created_by_admin=?");
        $sel->execute([$id, $admin_id]);
      }
      $targetEmploye = $sel->fetch(PDO::FETCH_ASSOC);

      if (!$targetEmploye) {
        header('Location: admin.php?tab=employes&msg=device_reset_denied'); exit;
      }

      if ($role === 'superadmin') {
        $pdo->prepare("UPDATE employes SET webauthn_credential_id=NULL, webauthn_credential=NULL WHERE id=?")->execute([$id]);
      } else {
        $pdo->prepare("UPDATE employes SET webauthn_credential_id=NULL, webauthn_credential=NULL WHERE id=? AND created_by_admin=?")
          ->execute([$id, $admin_id]);
      }

      if ($auditTableReady) {
        $details = sprintf(
          'Reset liaison appareil pour %s %s (%s)',
          $targetEmploye['prenom'],
          $targetEmploye['nom'],
          $targetEmploye['code_employe']
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $pdo->prepare(
          "INSERT INTO audit_logs (admin_id, employe_id, action, details, ip_address, user_agent) VALUES (?,?,?,?,?,?)"
        )->execute([$admin_id, $targetEmploye['id'], 'device_binding_reset', $details, $ip, $ua]);
      }

      header('Location: admin.php?tab=employes&msg=device_reset'); exit;
    }

    // Approuver demande OTP fallback
    if ($_POST['action'] === 'approve_otp_request') {
      if (!$otpTableReady) {
        header('Location: admin.php?tab=otp&msg=otp_schema_missing'); exit;
      }
      $reqId = (int)($_POST['id'] ?? 0);
      $note = trim($_POST['decision_note'] ?? '');

      if ($role === 'superadmin') {
        $rq = $pdo->prepare("SELECT r.id FROM otp_fallback_requests r WHERE r.id=? AND r.status='pending'");
        $rq->execute([$reqId]);
      } else {
        $rq = $pdo->prepare(
          "SELECT r.id FROM otp_fallback_requests r
           JOIN employes e ON e.id = r.employe_id
           WHERE r.id=? AND r.status='pending' AND e.created_by_admin=?"
        );
        $rq->execute([$reqId, $admin_id]);
      }

      if ($rq->fetch()) {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $sql = "UPDATE otp_fallback_requests
            SET status='approved', approved_by_admin_id=?, approved_at=NOW(), decision_note=?, otp_hash=?,
              otp_expires_at=DATE_ADD(NOW(), INTERVAL " . OTP_EXPIRY_MINUTES . " MINUTE)
            WHERE id=?";
        $pdo->prepare($sql)->execute([$admin_id, $note ?: null, $otpHash, $reqId]);

        $_SESSION['otp_flash'] = "OTP demande #$reqId : $otp (valide " . OTP_EXPIRY_MINUTES . " min)";
      }

      header('Location: admin.php?tab=otp'); exit;
    }

    // Rejeter demande OTP fallback
    if ($_POST['action'] === 'reject_otp_request') {
      if (!$otpTableReady) {
        header('Location: admin.php?tab=otp&msg=otp_schema_missing'); exit;
      }
      $reqId = (int)($_POST['id'] ?? 0);
      $note = trim($_POST['decision_note'] ?? '');

      if ($role === 'superadmin') {
        $rq = $pdo->prepare("SELECT r.id FROM otp_fallback_requests r WHERE r.id=? AND r.status='pending'");
        $rq->execute([$reqId]);
      } else {
        $rq = $pdo->prepare(
          "SELECT r.id FROM otp_fallback_requests r
           JOIN employes e ON e.id = r.employe_id
           WHERE r.id=? AND r.status='pending' AND e.created_by_admin=?"
        );
        $rq->execute([$reqId, $admin_id]);
      }

      if ($rq->fetch()) {
        $pdo->prepare(
          "UPDATE otp_fallback_requests
           SET status='rejected', rejected_by_admin_id=?, rejected_at=NOW(), decision_note=?
           WHERE id=?"
        )->execute([$admin_id, $note ?: null, $reqId]);
      }

      header('Location: admin.php?tab=otp'); exit;
    }
}

// ===== DONNÉES =====
if ($role === 'superadmin') {
    $employes = $pdo->query("SELECT e.*,a.nom_complet as admin_nom FROM employes e LEFT JOIN admins a ON e.created_by_admin=a.id WHERE e.actif=1 ORDER BY e.nom")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $s = $pdo->prepare("SELECT * FROM employes WHERE actif=1 AND created_by_admin=? ORDER BY nom");
    $s->execute([$admin_id]); $employes = $s->fetchAll(PDO::FETCH_ASSOC);
}
$emp_ids = array_column($employes, 'id');

$subadmins = [];
if ($role === 'superadmin') {
    $subadmins = $pdo->query("SELECT * FROM admins WHERE role='subadmin' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

$otpFlash = $_SESSION['otp_flash'] ?? null;
unset($_SESSION['otp_flash']);

$otp_requests = [];
if ($otpTableReady && $role === 'superadmin') {
  $otp_requests = $pdo->query(
    "SELECT r.*, e.code_employe, e.nom, e.prenom
     FROM otp_fallback_requests r
     JOIN employes e ON e.id=r.employe_id
     WHERE r.status IN ('pending','approved')
     ORDER BY r.requested_at DESC"
  )->fetchAll(PDO::FETCH_ASSOC);
} elseif ($otpTableReady) {
  $sOtp = $pdo->prepare(
    "SELECT r.*, e.code_employe, e.nom, e.prenom
     FROM otp_fallback_requests r
     JOIN employes e ON e.id=r.employe_id
     WHERE e.created_by_admin=? AND r.status IN ('pending','approved')
     ORDER BY r.requested_at DESC"
  );
  $sOtp->execute([$admin_id]);
  $otp_requests = $sOtp->fetchAll(PDO::FETCH_ASSOC);
}

$audit_logs = [];
if ($auditTableReady && $role === 'superadmin') {
  $audit_logs = $pdo->query(
    "SELECT l.*, a.username AS admin_username, e.code_employe, e.nom, e.prenom
     FROM audit_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     LEFT JOIN employes e ON e.id = l.employe_id
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 200"
  )->fetchAll(PDO::FETCH_ASSOC);
} elseif ($auditTableReady) {
  $sAudit = $pdo->prepare(
    "SELECT l.*, a.username AS admin_username, e.code_employe, e.nom, e.prenom
     FROM audit_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     LEFT JOIN employes e ON e.id = l.employe_id
     WHERE l.admin_id = ?
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 200"
  );
  $sAudit->execute([$admin_id]);
  $audit_logs = $sAudit->fetchAll(PDO::FETCH_ASSOC);
}

// Filtres
$filtre_date = $_GET['date']    ?? date('Y-m-d');
$filtre_emp  = $_GET['employe'] ?? '';
$filtre_code = trim($_GET['code']  ?? '');
$filtre_nom  = trim($_GET['nom_f'] ?? '');

// Pointages
$pointages = [];
if ($role === 'superadmin' || !empty($emp_ids)) {
    if ($role === 'superadmin') {
        $sql = "SELECT p.*,e.nom,e.prenom,e.code_employe,e.poste FROM pointages p JOIN employes e ON p.employe_id=e.id WHERE p.date_pointage=?";
        $params = [$filtre_date];
    } else {
        $pl  = implode(',', array_fill(0, count($emp_ids), '?'));
        $sql = "SELECT p.*,e.nom,e.prenom,e.code_employe,e.poste FROM pointages p JOIN employes e ON p.employe_id=e.id WHERE p.date_pointage=? AND p.employe_id IN ($pl)";
        $params = array_merge([$filtre_date], $emp_ids);
    }
    if ($filtre_emp  !== '') { $sql .= " AND p.employe_id=?";           $params[] = $filtre_emp; }
    if ($filtre_code !== '') { $sql .= " AND e.code_employe LIKE ?";    $params[] = '%'.$filtre_code.'%'; }
    if ($filtre_nom  !== '') { $sql .= " AND (e.nom LIKE ? OR e.prenom LIKE ?)"; $params[] = '%'.$filtre_nom.'%'; $params[] = '%'.$filtre_nom.'%'; }
    $sql .= " ORDER BY p.heure ASC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $pointages = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Stats
$today = date('Y-m-d');
if ($role === 'superadmin') {
    $nb_arrives = $pdo->query("SELECT COUNT(*) FROM pointages WHERE type='arrivee' AND date_pointage='$today'")->fetchColumn();
    $nb_partis  = $pdo->query("SELECT COUNT(*) FROM pointages WHERE type='depart'  AND date_pointage='$today'")->fetchColumn();
    $nb_total   = $pdo->query("SELECT COUNT(*) FROM employes WHERE actif=1")->fetchColumn();
} else {
    $nb_total = count($employes);
    if (!empty($emp_ids)) {
        $pl = implode(',', $emp_ids);
        $nb_arrives = $pdo->query("SELECT COUNT(*) FROM pointages WHERE type='arrivee' AND date_pointage='$today' AND employe_id IN ($pl)")->fetchColumn();
        $nb_partis  = $pdo->query("SELECT COUNT(*) FROM pointages WHERE type='depart'  AND date_pointage='$today' AND employe_id IN ($pl)")->fetchColumn();
    } else { $nb_arrives = 0; $nb_partis = 0; }
}
$nb_absents = max(0, $nb_total - $nb_arrives);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Administration</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;color:#1a1a2e}
    .layout{display:flex;min-height:100vh}
    .sidebar{width:220px;background:#1a1a2e;color:#fff;display:flex;flex-direction:column;padding:24px 0;flex-shrink:0;position:fixed;top:0;left:0;height:100vh;z-index:10}
    .brand{padding:0 20px 16px;border-bottom:1px solid rgba(255,255,255,.1);font-size:16px;font-weight:700}
    .brand span{color:#1D9E75}
    .role-badge{margin:10px 20px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;text-align:center;background:<?= $role==='superadmin'?'#1D9E75':'#378ADD' ?>;color:#fff}
    nav{padding:8px 0;flex:1}
    .nav-item{display:flex;align-items:center;gap:10px;padding:10px 20px;font-size:14px;color:rgba(255,255,255,.7);text-decoration:none;transition:background .15s}
    .nav-item:hover,.nav-item.active{background:rgba(255,255,255,.08);color:#fff}
    .logout{padding:14px 20px;border-top:1px solid rgba(255,255,255,.1)}
    .logout a{font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;display:flex;align-items:center;gap:8px}
    .logout a:hover{color:#fff}
    .main{margin-left:220px;padding:28px;flex:1}
    .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px}
    .topbar h2{font-size:21px;font-weight:600}
    .abadge{background:#fff;border-radius:8px;padding:6px 14px;font-size:13px;color:#555;border:1px solid #e0e0e0}
    .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
    .sc{background:#fff;border-radius:12px;padding:18px;border-left:4px solid #eee}
    .sc.v{border-left-color:#1D9E75}.sc.r{border-left-color:#E24B4A}.sc.b{border-left-color:#378ADD}.sc.g{border-left-color:#888}
    .sc .val{font-size:30px;font-weight:700;margin-bottom:3px}.sc .lbl{font-size:12px;color:#888}
    .tabs{display:flex;gap:4px;margin-bottom:18px;flex-wrap:wrap}
    .tb{padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:500;color:#888;background:#fff;border:1.5px solid #e0e0e0;transition:all .15s}
    .tb.active{background:#1a1a2e;color:#fff;border-color:#1a1a2e}
    .panel{background:#fff;border-radius:12px;padding:22px;margin-bottom:18px}
    .ph{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px}
    .ph h3{font-size:15px;font-weight:600}
    .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .filters input,.filters select{padding:8px 10px;border:1.5px solid #dde3ee;border-radius:8px;font-size:13px;outline:none;color:#1a1a2e;background:#fff}
    .filters input:focus,.filters select:focus{border-color:#1D9E75}
    .btn{padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
    .btn:hover{opacity:.88}
    .bg{background:#1D9E75;color:#fff}.bb{background:#378ADD;color:#fff}.bd{background:#1a1a2e;color:#fff}
    table{width:100%;border-collapse:collapse;font-size:13px}
    thead th{text-align:left;padding:9px 10px;background:#f7f9fc;color:#888;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
    tbody tr{border-bottom:1px solid #f0f0f0}
    tbody tr:last-child{border-bottom:none}
    tbody td{padding:11px 10px}
    tbody tr:hover{background:#fafafa}
    .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
    .badge.arrivee{background:#e1f5ee;color:#0F6E56}.badge.depart{background:#fcebeb;color:#A32D2D}
    .badge.sub{background:#378ADD;color:#fff}
    .glink{font-size:11px;color:#378ADD;text-decoration:none}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .fg .g{display:flex;flex-direction:column;gap:5px}
    .fg .g label{font-size:12px;font-weight:500;color:#555}
    .fg .g input{padding:9px 11px;border:1.5px solid #dde3ee;border-radius:8px;font-size:13px;outline:none}
    .fg .g input:focus{border-color:#1D9E75}
    .bsub{margin-top:12px;padding:10px 22px;background:#1a1a2e;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
    .ok{background:#e1f5ee;color:#0F6E56;padding:9px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
    .er{background:#fcebeb;color:#A32D2D;padding:9px 14px;border-radius:8px;font-size:13px;margin-bottom:12px}
    .bdel{background:none;border:none;color:#E24B4A;cursor:pointer;font-size:17px;padding:0 4px}
    .breset{background:none;border:none;color:#378ADD;cursor:pointer;font-size:17px;padding:0 4px}
    .vide{text-align:center;padding:36px;color:#aaa;font-size:14px}
    @media(max-width:900px){.sidebar{display:none}.main{margin-left:0;padding:14px}.stats{grid-template-columns:1fr 1fr}.fg{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">⏱ Point<span>age.</span></div>
    <div class="role-badge"><?= $role==='superadmin'?'👑 Super Admin':'🔵 Sous Admin' ?></div>
    <nav>
      <a href="admin.php?tab=pointages" class="nav-item <?= $tab==='pointages'?'active':'' ?>">📋 Pointages</a>
      <a href="admin.php?tab=employes"  class="nav-item <?= $tab==='employes' ?'active':'' ?>">👥 Employés</a>
      <a href="admin.php?tab=otp" class="nav-item <?= $tab==='otp'?'active':'' ?>">🔐 OTP fallback</a>
      <a href="admin.php?tab=audit" class="nav-item <?= $tab==='audit'?'active':'' ?>">🧾 Audit</a>
      <?php if($role==='superadmin'): ?><a href="admin.php?tab=subadmins" class="nav-item <?= $tab==='subadmins'?'active':'' ?>">🔑 Sous-admins</a><?php endif; ?>
      <a href="index.php" class="nav-item" target="_blank">⏱ Pointage</a>
    </nav>
    <div class="logout"><a href="admin.php?logout=1">🚪 Déconnexion</a></div>
  </aside>

  <main class="main">
    <div class="topbar">
      <h2><?= $tab==='pointages'?'Tableau de bord':($tab==='employes'?'Employés':($tab==='otp'?'OTP fallback':($tab==='audit'?'Journal audit':'Sous-admins'))) ?></h2>
      <div class="abadge">👤 <?= htmlspecialchars($_SESSION['admin_name']) ?></div>
    </div>

    <?php if($tab==='pointages'): ?>
    <div class="stats">
      <div class="sc b"><div class="val"><?= $nb_total ?></div><div class="lbl">Mes employés</div></div>
      <div class="sc v"><div class="val"><?= $nb_arrives ?></div><div class="lbl">Arrivées</div></div>
      <div class="sc r"><div class="val"><?= $nb_absents ?></div><div class="lbl">Absents</div></div>
      <div class="sc g"><div class="val"><?= $nb_partis ?></div><div class="lbl">Départs</div></div>
    </div>
    <?php endif; ?>

    <div class="tabs">
      <a href="admin.php?tab=pointages" class="tb <?= $tab==='pointages'?'active':'' ?>">📋 Pointages</a>
      <a href="admin.php?tab=employes"  class="tb <?= $tab==='employes' ?'active':'' ?>">👥 Employés</a>
      <a href="admin.php?tab=otp" class="tb <?= $tab==='otp'?'active':'' ?>">🔐 OTP fallback</a>
      <a href="admin.php?tab=audit" class="tb <?= $tab==='audit'?'active':'' ?>">🧾 Audit</a>
      <?php if($role==='superadmin'): ?><a href="admin.php?tab=subadmins" class="tb <?= $tab==='subadmins'?'active':'' ?>">🔑 Sous-admins</a><?php endif; ?>
    </div>

    <?php if($tab==='pointages'): ?>
    <div class="panel">
      <div class="ph">
        <h3>Registre des pointages</h3>
        <div class="filters">
          <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="tab" value="pointages"/>
            <input type="date" name="date" value="<?= htmlspecialchars($filtre_date) ?>"/>
            <input type="text" name="code" value="<?= htmlspecialchars($filtre_code) ?>" placeholder="Code" style="width:90px"/>
            <input type="text" name="nom_f" value="<?= htmlspecialchars($filtre_nom) ?>" placeholder="Nom" style="width:110px"/>
            <select name="employe">
              <option value="">Tous</option>
              <?php foreach($employes as $e): ?>
              <option value="<?= $e['id'] ?>" <?= $filtre_emp==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['prenom'].' '.$e['nom']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn bb">🔍</button>
          </form>
          <a href="export.php?date=<?= urlencode($filtre_date) ?>&employe=<?= urlencode($filtre_emp) ?>&code=<?= urlencode($filtre_code) ?>&nom_f=<?= urlencode($filtre_nom) ?>&admin_id=<?= $admin_id ?>&role=<?= $role ?>" class="btn bg">📥 Excel</a>
        </div>
      </div>
      <?php if(empty($pointages)): ?>
        <div class="vide">Aucun pointage trouvé.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employé</th><th>Code</th><th>Poste</th><th>Type</th><th>Heure</th><th>Date</th><th>Vérification</th><th>GPS</th></tr></thead>
        <tbody>
          <?php foreach($pointages as $p): ?>
          <tr>
            <td><strong><?= htmlspecialchars($p['prenom'].' '.$p['nom']) ?></strong></td>
            <td style="color:#888"><?= htmlspecialchars($p['code_employe']) ?></td>
            <td><?= htmlspecialchars($p['poste']??'—') ?></td>
            <td><span class="badge <?= $p['type'] ?>"><?= $p['type']==='arrivee'?'🟢 Arrivée':'🔴 Départ' ?></span></td>
            <td><?= date('H:i',strtotime($p['heure'])) ?></td>
            <td><?= date('d/m/Y',strtotime($p['date_pointage'])) ?></td>
                      <td><?= ($p['verification_method'] ?? 'webauthn') === 'otp_fallback' ? 'OTP manager' : 'Appareil lié' ?></td>
            <td><?php if($p['latitude']&&$p['longitude']): ?><a class="glink" href="https://maps.google.com/?q=<?= $p['latitude'] ?>,<?= $p['longitude'] ?>" target="_blank">📍</a><?php else: ?>—<?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php elseif($tab==='otp'): ?>
    <?php if(isset($_GET['msg']) && $_GET['msg']==='otp_schema_missing'): ?><div class="er">La table OTP n'existe pas encore. Importez la dernière mise à jour SQL.</div><?php endif; ?>
    <?php if($otpFlash): ?><div class="ok">✓ <?= htmlspecialchars($otpFlash) ?></div><?php endif; ?>
    <div class="panel">
      <div class="ph"><h3>Demandes OTP en attente / approuvées</h3></div>
      <?php if(!$otpTableReady): ?><div class="vide">Module OTP inactif: importez la dernière version de la base de données.</div>
      <?php elseif(empty($otp_requests)): ?><div class="vide">Aucune demande OTP.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Employé</th><th>Type</th><th>Demandé le</th><th>Raison</th><th>GPS</th><th>Statut</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach($otp_requests as $r): ?>
          <tr>
            <td><strong><?= (int)$r['id'] ?></strong></td>
            <td><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?> <span style="color:#888">(<?= htmlspecialchars($r['code_employe']) ?>)</span></td>
            <td><?= $r['type']==='arrivee'?'🟢 Arrivée':'🔴 Départ' ?></td>
            <td><?= date('d/m H:i', strtotime($r['requested_at'])) ?></td>
            <td style="max-width:210px"><?= htmlspecialchars($r['request_reason'] ?: '—') ?></td>
            <td><?php if($r['requested_latitude']&&$r['requested_longitude']): ?><a class="glink" href="https://maps.google.com/?q=<?= $r['requested_latitude'] ?>,<?= $r['requested_longitude'] ?>" target="_blank">📍</a><?php else: ?>—<?php endif; ?></td>
            <td><strong><?= htmlspecialchars($r['status']) ?></strong></td>
            <td>
              <?php if($r['status']==='pending'): ?>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="approve_otp_request"/>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>
                  <input type="hidden" name="decision_note" value="Approuvé par manager"/>
                  <button type="submit" class="btn bg" style="padding:6px 10px">Approuver</button>
                </form>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="reject_otp_request"/>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"/>
                  <input type="hidden" name="decision_note" value="Rejeté par manager"/>
                  <button type="submit" class="btn" style="padding:6px 10px;background:#E24B4A;color:#fff">Rejeter</button>
                </form>
              </div>
              <?php elseif($r['status']==='approved'): ?>
                <span style="color:#1D9E75;font-size:12px">OTP émis, en attente d'utilisation</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php elseif($tab==='employes'): ?>
    <?php if(isset($_GET['msg'])): ?>
      <div class="ok">✓ <?= $_GET['msg']==='device_reset' ? 'Liaison appareil réinitialisée.' : ($_GET['msg']==='device_reset_denied' ? 'Action refusée (employé non autorisé).' : 'Action effectuée.') ?></div>
    <?php endif; ?>
    <?php if(isset($erreur_emp)): ?><div class="er"><?= htmlspecialchars($erreur_emp) ?></div><?php endif; ?>
    <div class="panel">
      <div class="ph"><h3>Ajouter un employé</h3></div>
      <form method="POST">
        <input type="hidden" name="action" value="ajouter_employe"/>
        <div class="fg">
          <div class="g"><label>Code *</label><input type="text" name="code_employe" placeholder="EMP004" required/></div>
          <div class="g"><label>Poste</label><input type="text" name="poste" placeholder="Technicien"/></div>
          <div class="g"><label>Nom *</label><input type="text" name="nom" required/></div>
          <div class="g"><label>Prénom *</label><input type="text" name="prenom" required/></div>
          <div class="g"><label>Email</label><input type="text" name="email"/></div>
          <div class="g"><label>Téléphone</label><input type="text" name="telephone"/></div>
        </div>
        <button type="submit" class="bsub">+ Ajouter</button>
      </form>
    </div>
    <div class="panel">
      <div class="ph"><h3>Employés (<?= count($employes) ?>)</h3></div>
      <?php if(empty($employes)): ?><div class="vide">Aucun employé.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Code</th><th>Nom</th><th>Poste</th><th>Email</th><th>Appareil</th><?php if($role==='superadmin'): ?><th>Sous-admin</th><?php endif; ?><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($employes as $e): ?>
          <tr>
            <td><strong><?= htmlspecialchars($e['code_employe']) ?></strong></td>
            <td><?= htmlspecialchars($e['prenom'].' '.$e['nom']) ?></td>
            <td><?= htmlspecialchars($e['poste']??'—') ?></td>
            <td style="color:#888;font-size:12px"><?= htmlspecialchars($e['email']??'—') ?></td>
            <td><?= !empty($e['webauthn_credential_id']) ? '<span class="badge sub">Lié</span>' : '<span style="color:#888">Non lié</span>' ?></td>
            <?php if($role==='superadmin'): ?><td style="color:#888;font-size:12px"><?= htmlspecialchars($e['admin_nom']??'—') ?></td><?php endif; ?>
            <td>
              <form method="POST" style="display:inline" onsubmit="return confirm('Réinitialiser la liaison appareil de cet employé ?')">
                <input type="hidden" name="action" value="reset_device_binding"/>
                <input type="hidden" name="id" value="<?= $e['id'] ?>"/>
                <button type="submit" class="breset" title="Réinitialiser appareil">🔁</button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Désactiver ?')">
                <input type="hidden" name="action" value="supprimer_employe"/>
                <input type="hidden" name="id" value="<?= $e['id'] ?>"/>
                <button type="submit" class="bdel">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php elseif($tab==='audit'): ?>
    <div class="panel">
      <div class="ph"><h3>Journal des actions sensibles</h3></div>
      <?php if(!$auditTableReady): ?><div class="vide">Journal audit inactif: importez la migration audit_logs.</div>
      <?php elseif(empty($audit_logs)): ?><div class="vide">Aucune entrée audit.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Date</th><th>Admin</th><th>Action</th><th>Employé</th><th>Détails</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach($audit_logs as $l): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
            <td><?= htmlspecialchars($l['admin_username'] ?? ('#'.$l['admin_id'])) ?></td>
            <td><?= htmlspecialchars($l['action']) ?></td>
            <td>
              <?php if(!empty($l['employe_id'])): ?>
                <?= htmlspecialchars(($l['prenom'] ?? '').' '.($l['nom'] ?? '')) ?>
                <span style="color:#888">(<?= htmlspecialchars($l['code_employe'] ?? '#'.$l['employe_id']) ?>)</span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="max-width:340px"><?= htmlspecialchars($l['details'] ?? '—') ?></td>
            <td><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php elseif($tab==='subadmins' && $role==='superadmin'): ?>
    <?php if(isset($_GET['msg'])): ?><div class="ok">✓ <?= $_GET['msg']==='created'?'Sous-admin créé.':'Supprimé.' ?></div><?php endif; ?>
    <?php if(isset($erreur_form)): ?><div class="er"><?= htmlspecialchars($erreur_form) ?></div><?php endif; ?>
    <div class="panel">
      <div class="ph"><h3>Créer un sous-admin</h3></div>
      <form method="POST">
        <input type="hidden" name="action" value="creer_subadmin"/>
        <div class="fg">
          <div class="g"><label>Identifiant *</label><input type="text" name="username" required/></div>
          <div class="g"><label>Mot de passe *</label><input type="text" name="password" required/></div>
          <div class="g" style="grid-column:1/-1"><label>Nom complet</label><input type="text" name="nom_complet"/></div>
        </div>
        <button type="submit" class="bsub">+ Créer</button>
      </form>
    </div>
    <div class="panel">
      <div class="ph"><h3>Liste des sous-admins (<?= count($subadmins) ?>)</h3></div>
      <?php if(empty($subadmins)): ?><div class="vide">Aucun sous-admin.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Identifiant</th><th>Nom</th><th>Rôle</th><th>Créé le</th><th>Employés</th><th></th></tr></thead>
        <tbody>
          <?php foreach($subadmins as $sa):
            $nb=$pdo->prepare("SELECT COUNT(*) FROM employes WHERE created_by_admin=? AND actif=1");
            $nb->execute([$sa['id']]); $nbe=$nb->fetchColumn(); ?>
          <tr>
            <td><strong><?= htmlspecialchars($sa['username']) ?></strong></td>
            <td><?= htmlspecialchars($sa['nom_complet']??'—') ?></td>
            <td><span class="badge sub">Sous-admin</span></td>
            <td style="color:#888"><?= date('d/m/Y',strtotime($sa['created_at'])) ?></td>
            <td><strong><?= $nbe ?></strong></td>
            <td>
              <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ?')">
                <input type="hidden" name="action" value="supprimer_subadmin"/>
                <input type="hidden" name="id" value="<?= $sa['id'] ?>"/>
                <button type="submit" class="bdel">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>