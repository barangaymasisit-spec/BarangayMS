<?php
// Serves a logo or seal uploaded in Settings. Public: the login page shows the logo too.
require_once __DIR__ . '/db.php';

$name = (string)($_GET['name'] ?? '');
$row = null;
if (isset(BRANDING_IMAGES[$name])) {
    try {
        $stmt = $conn->prepare('SELECT mime, data FROM branding_images WHERE name = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception) {
        // no uploads yet
    }
}
if (!$row) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $row['mime']);
header('X-Content-Type-Options: nosniff');
// The URL carries ?v=<upload time>, so a new upload gets a new URL.
header('Cache-Control: public, max-age=31536000, immutable');
echo $row['data'];
