<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function residentLookupResponse(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    residentLookupResponse(405, ['found' => false, 'message' => 'Method not allowed.']);
}

if (($_SESSION['role'] ?? '') !== 'resident' || !currentResident($conn)) {
    residentLookupResponse(403, ['found' => false, 'message' => 'Please sign in with a resident account.']);
}

if (!verifyCsrfToken()) {
    residentLookupResponse(403, ['found' => false, 'message' => 'Refresh the page and try again.']);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
 $suffix = trim((string)($_POST['suffix'] ?? ''));
if ($firstName === '' || $lastName === '' || strlen($firstName) > 100 || strlen($middleName) > 100 || strlen($lastName) > 100 || strlen($suffix) > 50) {
    residentLookupResponse(400, ['found' => false, 'message' => 'Enter a valid first and last name.']);
}

$now = time();
$recentLookups = array_values(array_filter(
    $_SESSION['resident_lookup_attempts'] ?? [],
    static fn($attempt) => is_int($attempt) && $attempt > $now - 60
));
if (count($recentLookups) >= 10) {
    residentLookupResponse(429, ['found' => false, 'message' => 'Too many lookups. Wait one minute and try again.']);
}
$recentLookups[] = $now;
$_SESSION['resident_lookup_attempts'] = $recentLookups;

$stmt = $conn->prepare('SELECT resident_number FROM residents WHERE first_name = ? AND COALESCE(middle_name, \'\') = ? AND last_name = ? AND COALESCE(suffix, \'\') = ? ORDER BY id LIMIT 2');
$stmt->bind_param('ssss', $firstName, $middleName, $lastName, $suffix);
$stmt->execute();
$matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($matches) !== 1) {
    residentLookupResponse(200, ['found' => false, 'ambiguous' => count($matches) > 1]);
}

residentLookupResponse(200, ['found' => true, 'resident_number' => $matches[0]['resident_number']]);