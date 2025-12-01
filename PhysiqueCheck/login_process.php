<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

// Helper: redirect back to login with a nice error message
function redirect_with_error(string $msg): void {
    header('Location: login.html?error=' . urlencode($msg));
    exit;
}

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// 1. Basic checks
if ($email === '' || $password === '') {
    redirect_with_error('Please fill in both email and password.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_with_error('Invalid email format.');
}

// 2. Look up user by email
$stmt = $conn->prepare("
    SELECT id, username, email, password_hash, is_verified
    FROM users
    WHERE email = ?
    LIMIT 1
");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    // no row → no login
    $stmt->close();
    redirect_with_error('Account not found.');
}

$stmt->bind_result($id, $username, $dbEmail, $passwordHashDb, $isVerified);
$stmt->fetch();
$stmt->close();

// 3. Make sure we have a real hash (and force it to be string)
$storedHash = (string)$passwordHashDb;   // <— this removes the “null” type warning

if ($storedHash === '') {
    redirect_with_error('This account has no password set. Please sign up again.');
}

// 4. Check verification flag
if ((int)$isVerified !== 1) {
    redirect_with_error('Please verify your email before logging in.');
}

// 5. Check password
if (!password_verify($password, $storedHash)) {
    redirect_with_error('Wrong password.');
}

// 6. Success – set session and go to dashboard
$_SESSION['user_id']    = $id;
$_SESSION['username']   = $username;
$_SESSION['user_email'] = $dbEmail;

header('Location: home.php');
exit;
