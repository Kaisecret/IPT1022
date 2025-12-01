<?php
// config.php
$host = 'localhost';
$db   = 'physique_check';
$user = 'root';
$pass = ''; // XAMPP default: empty password

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
