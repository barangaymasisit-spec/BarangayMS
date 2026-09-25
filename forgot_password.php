<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$error = '';
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        http_response_code(403);
        exit('Invalid or expired form token.');
    }

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        ensurePasswordResetTable($conn);
        $stmt = $conn->prepare('SELECT id, first_name FROM users WHERE email = ? AND status = "Active" LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
            $stmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
            $userId = (int)$user['id'];
            $stmt->bind_param('iss', $userId, $tokenHash, $expiresAt);
            $stmt->execute();
            $stmt->close();

            $settings = $conn->query('SELECT email FROM barangay_settings LIMIT 1')->fetch_assoc() ?: [];
            $from = trim((string)($settings['email'] ?? '')) ?: trim((string)(getenv('MAIL_FROM') ?: ''));
            $baseUrl = rtrim(trim(getenv('APP_URL') ?: 'https://barangayms.up.railway.app'), '/');
            $resetUrl = $baseUrl . '/reset_password.php?token=' . urlencode($token);
            $body = "Hello " . ($user['first_name'] ?: 'there') . ",\n\nUse this link to reset your Barangay Management System password:\n\n" . $resetUrl . "\n\nThis link expires in 1 hour. If you did not request this, ignore this email.\n";
            if ($from !== '' && sendSmtpEmail($email, 'Barangay MS password reset', $body, $from)) {
                $sent = true;
            } else {
                $error = 'The reset email could not be sent. Please contact the administrator.';
            }
        } else {
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Barangay Management System</title>
    <link rel="stylesheet" href="auth.css?v=2">
</head>
<body>
<div class="container"><div class="login-box">
    <h2>Forgot Password</h2>
    <p>Enter your registered email to receive a reset link.</p>
    <?php if ($error !== ''): ?><div class="error-message" role="alert"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($sent): ?><div class="success-message" role="status">If an active account uses that email, a reset link has been sent.</div><?php endif; ?>
    <?php if (!$sent): ?>
    <form method="post" action="forgot_password.php">
        <?php echo csrfField(); ?>
        <div class="input-group"><label for="email">Email address</label><input type="email" id="email" name="email" value="<?php echo h($email); ?>" autocomplete="email" required></div>
        <button type="submit">Send Reset Link</button>
    </form>
    <?php endif; ?>
    <div class="links"><a href="auth.php">Back to Login</a></div>
</div></div>
</body>
</html>
