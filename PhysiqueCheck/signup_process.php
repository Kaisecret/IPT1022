<?php
session_start();
require 'config.php';

// bring in PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: signup.html');
    exit;
}

// 1. Get form data
$fullName = trim($_POST['fullname'] ?? '');
$username = trim($_POST['username'] ?? '');
$age      = (int)($_POST['age'] ?? 0);
$gender   = $_POST['gender'] ?? '';
$goal     = $_POST['goal'] ?? '';
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// 2. Simple checks
if ($fullName === '' || $username === '' || $age <= 0 || $email === '' || $password === '') {
    die('Please fill in all fields.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die('Invalid email.');
}

// 3. Check if email or username already exists
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
$stmt->bind_param('ss', $email, $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    die('Email or username already in use.');
}
$stmt->close();

// 4. Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// 5. Make 6-digit code
$code = random_int(100000, 999999);
$expiresAt = date('Y-m-d H:i:s', time() + 10 * 60); // 10 minutes from now

// 6. Save user in database
$stmt = $conn->prepare("
    INSERT INTO users (full_name, username, age, gender, goal, email, password_hash, verification_code, verification_expires)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'ssissssss',
    $fullName,
    $username,
    $age,
    $gender,
    $goal,
    $email,
    $passwordHash,
    $code,
    $expiresAt
);

if (!$stmt->execute()) {
    die('Error saving user: ' . $stmt->error);
}

$userId = $stmt->insert_id;
$stmt->close();

// 7. Send email with 6-digit code using PHPMailer + Gmail SMTP
$subject = "Physique Check - Email Verification Code";
$message = "Hi {$fullName},\n\n" .
           "Your email verification code is: {$code}\n" .
           "This code will expire in 10 minutes.\n\n" .
           "– Physique Check";

$mail = new PHPMailer(true);

try {
    // SMTP settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;

    // ⚠️ These must match your Gmail + app password
    $mail->Username   = 'sanom6268@gmail.com';   // your Gmail address
    $mail->Password   = 'ptfbjqlspupjrswp';      // your 16-char app password (no spaces)
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    // Sender and recipient
    $mail->setFrom('sanom6268@gmail.com', 'Physique Check'); // same as Username
    $mail->addAddress($email, $fullName); // send to the new user

    // Content
    $mail->isHTML(false); // plain text
    $mail->Subject = $subject;
    $mail->Body    = $message;

    $mail->send();

} catch (Exception $e) {
    // If sending fails, show error
    die('Account saved but email could not be sent. Mailer Error: ' . $mail->ErrorInfo);
}

// 8. Save info in session for next step
$_SESSION['pending_user_id'] = $userId;
$_SESSION['pending_email']   = $email;
$_SESSION['pending_name']    = $fullName;

// 9. Go to verification page
header('Location: verify_email.php');
exit;
