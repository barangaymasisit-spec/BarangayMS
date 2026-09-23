<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$firstName = trim($_GET['first_name'] ?? '');
$lastName = trim($_GET['last_name'] ?? '');

if ($firstName === '' || $lastName === '') {
    echo json_encode(['resident_number' => '']);
    exit;
}

$stmt = $conn->prepare('SELECT resident_number FROM residents WHERE first_name = ? AND last_name = ? LIMIT 1');
$stmt->bind_param('ss', $firstName, $lastName);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'resident_number' => $result['resident_number'] ?? '',
]);
