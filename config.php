<?php
// config.php
$host     = 'localhost';
$dbname   = 'pointage_emp';
$username = 'root';       // ton user phpMyAdmin
$password = '';           // ton mot de passe phpMyAdmin (vide sur XAMPP)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur connexion : " . $e->getMessage());
}
?>