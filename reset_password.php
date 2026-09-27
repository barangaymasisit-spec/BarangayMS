<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

ensurePasswordResetTable($conn);
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = false;
$resetId = 0;

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $resetId = (int)($reset['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        http_response_code(403);
        exit('Invalid or expired form token.');
    }
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$resetId) {
        $error = 'This reset link is invalid or expired.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $validation = validatePasswordStrength($password);
        if (!$validation['valid']) {
            $error = implode(' ', $validation['errors']);
        } else {
            $tokenHash = hash('sha256', $token);
            $stmt = $conn->prepare('SELECT user_id FROM password_resets WHERE id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1');
            $stmt->bind_param('is', $resetId, $tokenHash);
            $stmt->execute();
            $reset = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$reset) {
                $error = 'This reset link is invalid or expired.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $userId = (int)$reset['user_id'];
                $stmt->bind_param('si', $passwordHash, $userId);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?');
                $stmt->bind_param('i', $resetId);
                $stmt->execute();
                $stmt->close();
                clearFailedLogin($conn, $userId);
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Barangay Management System</title>
    <link rel="stylesheet" href="auth.css?v=2">
</head>
<body>
<div class="container"><div class="login-box">
    <h2>Reset Password</h2>
    <?php if ($success): ?>
        <div class="success-message" role="status">Your password has been updated.</div>
        <div class="links"><a href="auth.php">Return to Login</a></div>
    <?php else: ?>
        <?php if ($error !== ''): ?><div class="error-message" role="alert"><?php echo h($error); ?></div><?php endif; ?>
        <form method="post" action="reset_password.php?token=<?php echo urlencode($token); ?>">
            <?php echo csrfField(); ?>
            <div class="input-group"><label for="password">New password</label><input type="password" id="password" name="password" minlength="8" required></div>
            <div class="input-group"><label for="confirm_password">Confirm password</label><input type="password" id="confirm_password" name="confirm_password" minlength="8" required></div>
            <button type="submit">Update Password</button>
        </form>
    <?php endif; ?>
</div></div>
<script src="password-peek.js"></script>
</body>
</html>
