<?php

date_default_timezone_set('Asia/Manila');

// Use Railway's MYSQL* variables in production and keep XAMPP defaults locally.
$DB_HOST = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$DB_PORT = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
$DB_USER = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
$DB_NAME = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

function ensureBarangaySettingsSchema(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS barangay_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            barangay_name VARCHAR(255) DEFAULT NULL,
            barangay_captain VARCHAR(255) DEFAULT NULL,
            municipality VARCHAR(150) DEFAULT NULL,
            province VARCHAR(150) DEFAULT NULL,
            zip_code VARCHAR(20) DEFAULT NULL,
            contact_number VARCHAR(50) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            office_hours VARCHAR(100) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            logo_path VARCHAR(255) DEFAULT NULL,
            enable_appointments TINYINT(1) NOT NULL DEFAULT 0,
            enable_complaints TINYINT(1) NOT NULL DEFAULT 0,
            enable_notifications TINYINT(1) NOT NULL DEFAULT 0,
            allow_registration TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $columns = $conn->query('SHOW COLUMNS FROM barangay_settings');
    if ($columns) {
        $hasEmail = false;
        while ($row = $columns->fetch_assoc()) {
            if (($row['Field'] ?? '') === 'email') {
                $hasEmail = true;
                break;
            }
        }
        if (!$hasEmail) {
            $conn->query('ALTER TABLE barangay_settings ADD COLUMN email VARCHAR(150) NULL AFTER contact_number');
        }
    }
}

ensureBarangaySettingsSchema($conn);

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDatabaseDateTime(?string $value, string $format = 'M d, Y h:i A'): string {
    if (!$value) {
        return '';
    }

    $timestamp = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );
    if (!$timestamp) {
        return '';
    }

    return $timestamp->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
}

function bindPreparedParams(mysqli_stmt $stmt, string $types, array $params): void {
    $refs = [$types];
    foreach ($params as $index => $value) {
        $refs[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrfToken(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $expectedToken = $_SESSION['csrf_token'] ?? null;
    if (!is_string($expectedToken) || $expectedToken === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $expectedToken = $_SESSION['csrf_token'];
    }

    $token = trim((string)($_POST['csrf_token'] ?? ''));
    if ($token === '') {
        return false;
    }

    return hash_equals($expectedToken, $token);
}

function currentResident(mysqli $conn): ?array {
    if (($_SESSION['role'] ?? '') !== 'resident') {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM residents WHERE first_name = ? AND last_name = ? AND email = ? LIMIT 1');
    $stmt->bind_param('sss', $_SESSION['first_name'], $_SESSION['last_name'], $_SESSION['email']);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $resident ?: null;
}

// Lowest free RES- number, so deleted numbers get reused before new ones.
function nextResidentNumber(mysqli $conn): string {
    $used = "SELECT CAST(SUBSTRING(resident_number, 5) AS UNSIGNED) AS n FROM residents WHERE resident_number LIKE 'RES-%'";
    $n = $conn->query("SELECT MIN(n) FROM (SELECT 1 AS n UNION SELECT u.n + 1 FROM ($used) u) c WHERE n NOT IN ($used)")->fetch_row()[0];
    return 'RES-' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

function certificateTrackingNumber(int $certificateId, ?string $date = null): string {
    $certificateId = max(1, (int)$certificateId);
    $stamp = $date ? date('Ymd', strtotime($date)) : date('Ymd');
    return 'CERT-' . $stamp . '-' . str_pad((string)$certificateId, 6, '0', STR_PAD_LEFT);
}

function validateCertificatePrintable(array $cert, array $resident): array {
    $status = strtoupper(trim((string)($cert['status'] ?? '')));
    $errors = [];

    if (!in_array($status, ['APPROVED', 'RELEASED'], true)) {
        $errors[] = 'This certificate is still pending approval and cannot be printed yet.';
    }

    $requiredFields = [
        'first_name' => 'Resident first name',
        'last_name' => 'Resident last name',
        'birth_date' => 'Birth date',
    ];

    foreach ($requiredFields as $field => $label) {
        if (empty($resident[$field] ?? '')) {
            $errors[] = $label . ' is missing.';
        }
    }

    return [
        'allowed' => empty($errors),
        'errors' => $errors,
    ];
}

function barangayLogoPath(): string {
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $result = $conn->query('SELECT logo_path FROM barangay_settings LIMIT 1');
        if ($result && $result->num_rows > 0) {
            $settings = $result->fetch_assoc();
            if (!empty($settings['logo_path']) && file_exists(__DIR__ . '/' . $settings['logo_path'])) {
                return $settings['logo_path'];
            }
        }
    }
    return 'logo.png';
}

function ensureActivityLogsTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS activity_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            action VARCHAR(50) NOT NULL,
            entity VARCHAR(100) NOT NULL,
            details TEXT DEFAULT NULL,
            related_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_activity_logs_user_id (user_id),
            KEY idx_activity_logs_entity (entity),
            KEY idx_activity_logs_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureUserSecurityTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS user_security (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL DEFAULT NULL,
            last_login_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_user_security_user_id (user_id),
            KEY idx_user_security_locked_until (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureLoginAttemptsTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash CHAR(64) NOT NULL,
            username_hash CHAR(64) NOT NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_login_attempts_ip_time (ip_hash, attempted_at),
            KEY idx_login_attempts_username_time (username_hash, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function loginRequestIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function loginIdentifierHash(string $value): string {
    return hash_hmac('sha256', strtolower(trim($value)), authCookieSecret());
}

function isLoginRateLimited(mysqli $conn, string $username): bool {
    ensureLoginAttemptsTable($conn);
    $ipHash = loginIdentifierHash(loginRequestIp());
    $usernameHash = loginIdentifierHash($username);
    $stmt = $conn->prepare('SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (ip_hash = ? OR username_hash = ?)');
    $stmt->bind_param('ss', $ipHash, $usernameHash);
    $stmt->execute();
    $failedAttempts = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $failedAttempts >= 20;
}

function recordLoginAttempt(mysqli $conn, string $username, bool $successful): void {
    ensureLoginAttemptsTable($conn);
    $ipHash = loginIdentifierHash(loginRequestIp());
    $usernameHash = loginIdentifierHash($username);
    $successfulValue = $successful ? 1 : 0;
    $stmt = $conn->prepare('INSERT INTO login_attempts (ip_hash, username_hash, successful) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $ipHash, $usernameHash, $successfulValue);
    $stmt->execute();
    $stmt->close();
    $conn->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

function ensurePasswordResetTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS password_resets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_password_resets_token (token_hash),
            KEY idx_password_resets_user (user_id),
            KEY idx_password_resets_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function sendSmtpEmail(string $to, string $subject, string $body, string $from): bool {
    $sendGridKey = getenv('SENDGRID_API_KEY') ?: '';
    $sendGridFrom = getenv('SENDGRID_FROM_EMAIL') ?: $from;
    if ($sendGridKey !== '' && filter_var($sendGridFrom, FILTER_VALIDATE_EMAIL)) {
        $payload = json_encode([
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => ['email' => $sendGridFrom],
            'subject' => $subject,
            'content' => [['type' => 'text/plain', 'value' => $body]],
        ]);
        $context = stream_context_create([

            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$sendGridKey}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $response = @file_get_contents('https://api.sendgrid.com/v3/mail/send', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $statusCode = (int)($matches[1] ?? 0);
        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }
        error_log('SendGrid API rejected reset email with HTTP ' . $statusCode . '.');
        return false;
    }

    $host = getenv('SMTP_HOST') ?: '';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    if ($host === '' || $username === '' || $password === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('SMTP reset email skipped: required configuration is missing.');
        return false;
    }

    $socketHost = $port === 465 ? 'ssl://' . $host : $host;
    $socket = @fsockopen($socketHost, $port, $errorNumber, $errorMessage, 5);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $errorNumber . ' ' . $errorMessage);
        return false;
    }

    $read = static function ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };
    $write = static function ($socket, string $command, string $stage) use ($read): bool {
        fwrite($socket, $command . "\r\n");
        $response = $read($socket);
        $code = (int)substr($response, 0, 3);
        if ($code >= 400) {
            error_log('SMTP ' . $stage . ' rejected with code ' . $code . '.');
        }
        return $code < 400;
    };

    $read($socket);
    if (!$write($socket, 'EHLO barangayms.local', 'EHLO')) {
        error_log('SMTP EHLO failed.');
        fclose($socket);
        return false;
    }
    if ($port === 587) {
        fwrite($socket, "STARTTLS\r\n");
        if ((int)substr($read($socket), 0, 3) >= 400 || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS failed.');
            fclose($socket);
            return false;
        }
        if (!$write($socket, 'EHLO barangayms.local', 'EHLO after TLS')) {
            error_log('SMTP EHLO after TLS failed.');
            fclose($socket);
            return false;
        }
    }
    if (!$write($socket, 'AUTH LOGIN', 'AUTH LOGIN') || !$write($socket, base64_encode($username), 'SMTP username') || !$write($socket, base64_encode($password), 'SMTP password') || !$write($socket, 'MAIL FROM:<' . $from . '>', 'MAIL FROM') || !$write($socket, 'RCPT TO:<' . $to . '>', 'RCPT TO') || !$write($socket, 'DATA', 'DATA')) {
        error_log('SMTP authentication or envelope rejected.');
        fclose($socket);
        return false;
    }

    $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $from . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
    fwrite($socket, 'Subject: ' . $subject . "\r\n" . $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
    $sent = (int)substr($read($socket), 0, 3) < 400;
    if (!$sent) {
        error_log('SMTP message rejected.');
    }
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return $sent;
}

function validatePasswordStrength(string $password): array {
    $password = trim($password);
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

function getUserSecurity(mysqli $conn, int $userId): ?array {
    ensureUserSecurityTable($conn);
    $stmt = $conn->prepare('SELECT user_id, failed_login_attempts, locked_until, last_login_at FROM user_security WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $record ?: null;
}

function isUserLockedOut(mysqli $conn, int $userId): bool {
    $record = getUserSecurity($conn, $userId);
    if (!$record || empty($record['locked_until'])) {
        return false;
    }

    $lockedUntil = new DateTime($record['locked_until'], new DateTimeZone(date_default_timezone_get()));
    return $lockedUntil > new DateTime('now', new DateTimeZone(date_default_timezone_get()));
}

function recordFailedLogin(mysqli $conn, int $userId): void {
    ensureUserSecurityTable($conn);
    $record = getUserSecurity($conn, $userId) ?? ['failed_login_attempts' => 0, 'locked_until' => null];
    $attempts = (int)($record['failed_login_attempts'] ?? 0) + 1;

    if ($attempts >= 5) {
        $lockUntil = (new DateTime('now'))->modify('+15 minutes')->format('Y-m-d H:i:s');
        $stmt = $conn->prepare('INSERT INTO user_security (user_id, failed_login_attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE failed_login_attempts = VALUES(failed_login_attempts), locked_until = VALUES(locked_until)');
        $stmt->bind_param('iis', $userId, $attempts, $lockUntil);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare('INSERT INTO user_security (user_id, failed_login_attempts, locked_until) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE failed_login_attempts = VALUES(failed_login_attempts), locked_until = NULL');
    $stmt->bind_param('ii', $userId, $attempts);
    $stmt->execute();
    $stmt->close();
}

function clearFailedLogin(mysqli $conn, int $userId): void {
    ensureUserSecurityTable($conn);
    $stmt = $conn->prepare('INSERT INTO user_security (user_id, failed_login_attempts, locked_until, last_login_at) VALUES (?, 0, NULL, NOW()) ON DUPLICATE KEY UPDATE failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function logActivity(mysqli $conn, string $action, string $entity, string $details = '', ?int $relatedId = null, ?int $userId = null): bool {
    ensureActivityLogsTable($conn);

    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    $sql = 'INSERT INTO activity_logs (user_id, action, entity, details, created_at) VALUES (?, ?, ?, ?, NOW())';
    $params = [$userId, $action, $entity, $details];
    $types = 'isss';

    if ($relatedId !== null) {
        $sql = 'INSERT INTO activity_logs (user_id, action, entity, details, related_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())';
        $types = 'isssi';
        $params[] = (int)$relatedId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

ensureActivityLogsTable($conn);
