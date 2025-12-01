<?php
session_start();
require 'config.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

if (!isset($_SESSION['pending_user_id'], $_SESSION['pending_email'])) {
    header('Location: signup.html');
    exit;
}

$userId   = (int)$_SESSION['pending_user_id'];
$email    = $_SESSION['pending_email'];
$fullName = $_SESSION['pending_name'] ?? 'User';

$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) RESEND CODE
    if (isset($_POST['resend'])) {

        // make new 6-digit code
        $newCode   = random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);

        // save new code to DB
        $stmt = $conn->prepare("
            UPDATE users
            SET verification_code = ?, verification_expires = ?
            WHERE id = ? AND email = ?
        ");
        $stmt->bind_param('ssis', $newCode, $expiresAt, $userId, $email);
        $stmt->execute();
        $stmt->close();

        // send email with PHPMailer
        $subject = "Physique Check - New Verification Code";
        $message = "Hi {$fullName},\n\n" .
                   "Your new email verification code is: {$newCode}\n" .
                   "This code will expire in 10 minutes.\n\n" .
                   "– Physique Check";

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;

            // ⚠️ SAME Gmail + app password as in signup_process.php
           $mail->Username   = 'sanom6268@gmail.com';   // your Gmail address
           $mail->Password   = 'ptfbjqlspupjrswp';      // your 16-char app password (no spaces)

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->SMTPDebug   = 0;
            $mail->Debugoutput = 'error_log';

            $mail->setFrom('sanom6268@gmail.com', 'Physique Check');
            $mail->addAddress($email, $fullName);

            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $message;

            $mail->send();

            $info = 'A new verification code has been sent to your email.';

        } catch (Exception $e) {
            $error = 'Could not resend code. Mailer Error: ' . $mail->ErrorInfo;
        }

    // 2) VERIFY CODE
    } else {

        $codeInput = trim($_POST['code'] ?? '');

        if (strlen($codeInput) !== 6) {
            $error = 'Please enter the 6-digit code.';
        } else {
            $stmt = $conn->prepare("
                SELECT verification_code, verification_expires, username
                FROM users
                WHERE id = ? AND email = ?
                LIMIT 1
            ");
            $stmt->bind_param('is', $userId, $email);
            $stmt->execute();
            $stmt->bind_result($dbCode, $dbExpires, $dbUsername);
            $stmt->fetch();
            $stmt->close();

            if (empty($dbCode)) {
                // no code stored
                $error = 'Verification not found.';
            } elseif (empty($dbExpires) || $dbExpires === '0000-00-00 00:00:00') {
                // invalid or missing expiry in DB
                $error = 'Code expiry is invalid. Please sign up again.';
            } else {
                // try to parse expiry safely
                $expiresAtObj = DateTime::createFromFormat('Y-m-d H:i:s', $dbExpires);

                if (!$expiresAtObj) {
                    $error = 'Code expiry is invalid. Please sign up again.';
                } elseif (new DateTime() > $expiresAtObj) {
                    $error = 'Code expired. Please sign up again.';
                } elseif ($codeInput !== $dbCode) {
                    $error = 'Wrong code.';
                } else {
                    // mark verified
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET is_verified = 1, verification_code = NULL, verification_expires = NULL
                        WHERE id = ?
                    ");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();

                    // log in
                    $_SESSION['user_id']    = $userId;
                    $_SESSION['username']   = $dbUsername;
                    $_SESSION['user_email'] = $email;

                    // clear pending
                    unset($_SESSION['pending_user_id'], $_SESSION['pending_email'], $_SESSION['pending_name']);

                    header('Location: home.php'); // or home.html
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Email - Physique Check</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-black text-white flex items-center justify-center min-h-screen">
<div class="bg-gray-900 border border-gray-700 p-8 rounded-2xl w-full max-w-md">

    <!-- Back button -->
    <a href="signup.html"
       class="inline-flex items-center text-sm text-gray-400 hover:text-white mb-4">
        ← Back to Sign Up
    </a>

    <h2 class="text-2xl font-bold mb-2">Verify your email</h2>
    <p class="text-gray-400 mb-2">
        We sent a 6-digit code to<br>
        <span class="text-white font-medium"><?= htmlspecialchars($email) ?></span>
    </p>

    <?php if ($info): ?>
        <p class="text-green-400 mb-3"><?= htmlspecialchars($info) ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="text-red-400 mb-3"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" class="space-y-4">
        <input
            type="text"
            name="code"
            maxlength="6"
            class="w-full bg-gray-800 border border-gray-700 rounded-lg p-3 text-center tracking-[0.5em]"
            placeholder="Enter 6-digit code"
            autofocus
            required
        >
        <div class="flex gap-3">
            <button type="submit"
                    class="flex-1 bg-green-500 text-black font-bold py-3 rounded-lg hover:bg-green-400 transition-colors">
                Verify &amp; Continue
            </button>
            <button type="submit" name="resend" value="1"
                    class="flex-1 bg-gray-700 text-white font-semibold py-3 rounded-lg hover:bg-gray-600 transition-colors">
                Resend Code
            </button>
        </div>
    </form>
</div>
</body>
</html>
