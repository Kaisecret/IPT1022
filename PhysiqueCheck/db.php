<?php
// db.php
$host = 'localhost';
$db   = 'physique_check';
$user = 'root';     // XAMPP default
$pass = '';         // XAMPP default (empty). Change if you set a password.

$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('DB connection failed: ' . $e->getMessage());
}
