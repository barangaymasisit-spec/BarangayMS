<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
applyNoStoreHeaders();

// Activates a self-registered resident account from the link emailed at sign-up (valid for 24 hours).
$token = trim((string)($_GET['token'] ?? ''));
$activated = false;
if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("UPDATE users SET status = 'Active', email_verify_hash = NULL WHERE email_verify_hash = ? AND status = 'Unverified' AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $activated = $stmt->affected_rows > 0;
    $stmt->close();
}

if ($activated) {
    logActivity($conn, 'updated', 'users', 'Resident confirmed their email address.', null, null);
}
header('Location: auth.php?' . ($activated ? 'verified=1' : 'verify_failed=1'));
exit;
