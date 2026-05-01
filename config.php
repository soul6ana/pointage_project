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

// Security and attendance policy settings.
const OFFICE_LATITUDE = 36.7534503;
const OFFICE_LONGITUDE = 3.4727516;
const GEOFENCE_RADIUS_METERS = 120;
const ENFORCE_GEOFENCE = false;
const OTP_EXPIRY_MINUTES = 5;