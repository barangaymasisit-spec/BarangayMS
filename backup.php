<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';

if (!runScheduledBackup($conn)) {
    $conn->close();
    exit(1);
}

$conn->close();