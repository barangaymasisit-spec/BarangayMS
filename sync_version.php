<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

applyNoStoreHeaders();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}

$result = $conn->query("SELECT CONCAT(
    COALESCE((SELECT MAX(updated_at) FROM users), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(updated_at) FROM barangay_settings), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(last_updated) FROM residents), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(updated_at) FROM households), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(updated_at) FROM certificates), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(updated_at) FROM complaints), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(updated_at) FROM appointments), '1970-01-01 00:00:00'), '|',
    COALESCE((SELECT MAX(id) FROM activity_logs), 0)
) AS version");

if (!$result) {
    http_response_code(503);
    echo json_encode(['error' => 'Unable to check for updates']);
    exit;
}

$row = $result->fetch_assoc();
echo json_encode(['version' => $row['version'] ?? '']);
