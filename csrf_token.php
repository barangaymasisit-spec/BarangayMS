<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
restorePersistentAuthSession($conn);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

echo json_encode(['csrf_token' => csrfToken()]);
