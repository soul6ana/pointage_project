<?php
session_start();
require_once 'config.php';
if (isset($_SESSION['admin_id'])) { header('Location: admin.php'); exit; }
$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $erreur = "Veuillez remplir tous les champs.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['nom_complet'];
            $_SESSION['admin_role'] = $admin['role'] ?? 'subadmin';
            header('Location: admin.php'); exit;
        } else {
            $erreur = "Identifiant ou mot de passe incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Connexion Admin</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center}
    .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.10);padding:40px 36px;width:100%;max-width:400px}
    .header{text-align:center;margin-bottom:30px}
    .icon{width:56px;height:56px;border-radius:50%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px}
    h1{font-size:20px;font-weight:600;color:#1a1a2e}
    .sub{font-size:13px;color:#888;margin-top:4px}
    label{display:block;font-size:13px;font-weight:500;color:#555;margin-bottom:6px}
    input{width:100%;padding:12px 14px;border:1.5px solid #dde3ee;border-radius:8px;font-size:15px;color:#1a1a2e;outline:none;margin-bottom:16px}
    input:focus{border-color:#1a1a2e}
    .btn{width:100%;padding:13px;background:#1a1a2e;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer}
    .btn:hover{opacity:.88}
    .err{background:#fcebeb;color:#A32D2D;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px;text-align:center}
    .back{text-align:center;margin-top:18px;font-size:12px;color:#aaa}
    .back a{color:#1D9E75;text-decoration:none}
  </style>
</head>
<body>
<div class="card">
  <div class="header">
    <div class="icon">🔐</div>
    <h1>Espace Administrateur</h1>
    <p class="sub">Connectez-vous pour accéder au tableau de bord</p>
  </div>
  <?php if($erreur): ?><div class="err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>
  <form method="POST">
    <label>Identifiant</label>
    <input type="text" name="username" placeholder="admin" required/>
    <label>Mot de passe</label>
    <input type="password" name="password" placeholder="••••••••" required/>
    <button type="submit" class="btn">Se connecter</button>
  </form>
  <div class="back"><a href="index.php">← Retour au pointage</a></div>
</div>
</body>
</html>