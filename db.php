<?php

date_default_timezone_set('Asia/Manila');

// Railway's database resource exposes a connection URL. Prefer it when present so
// the application can connect without manually copying individual credentials.
$DB_URL = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
if ($DB_URL) {
    $DB_URL_PARTS = parse_url($DB_URL);
    if (!$DB_URL_PARTS || empty($DB_URL_PARTS['host']) || empty($DB_URL_PARTS['path'])) {
        die('Invalid DATABASE_URL configuration.');
    }

    $DB_HOST = $DB_URL_PARTS['host'];
    $DB_PORT = isset($DB_URL_PARTS['port']) ? (int)$DB_URL_PARTS['port'] : 3306;
    $DB_USER = rawurldecode($DB_URL_PARTS['user'] ?? '');
    $DB_PASS = rawurldecode($DB_URL_PARTS['pass'] ?? '');
    $DB_NAME = trim($DB_URL_PARTS['path'], '/');
} else {
    // Use Railway's MYSQL* variables in production and keep XAMPP defaults locally.
    $DB_HOST = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
    $DB_PORT = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
    $DB_USER = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    $DB_PASS = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
    $DB_NAME = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement';
}

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    $GLOBALS['databaseConnectionError'] = 'Database connection unavailable.';
} else {
    $conn->set_charset('utf8mb4');
    // Store timestamps in UTC everywhere (Railway's MySQL already is; XAMPP uses the PC's zone).
    // formatDatabaseDateTime() converts them to Manila time for display.
    @ $conn->query("SET time_zone = '+00:00'");
}

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

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    ensureBarangaySettingsSchema($conn);
}

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

    // Accounts are linked to their resident record by ID, so renaming a resident or
    // changing their email does not cut them off from their own records.
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare('SELECT r.* FROM users u JOIN residents r ON r.id = u.resident_id WHERE u.id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($resident) {
        return $resident;
    }

    // Accounts made before the link existed: match once by name and email, then save the link.
    $stmt = $conn->prepare('SELECT r.* FROM residents r WHERE r.first_name = ? AND r.last_name = ? AND r.email = ?
        AND NOT EXISTS (SELECT 1 FROM users other WHERE other.resident_id = r.id AND other.id <> ?)
        AND EXISTS (SELECT 1 FROM users me WHERE me.id = ? AND me.resident_id IS NULL) LIMIT 1');
    $stmt->bind_param('sssii', $_SESSION['first_name'], $_SESSION['last_name'], $_SESSION['email'], $userId, $userId);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($resident) {
        $residentId = (int)$resident['id'];
        $stmt = $conn->prepare('UPDATE users SET resident_id = ? WHERE id = ? AND resident_id IS NULL');
        $stmt->bind_param('ii', $residentId, $userId);
        $stmt->execute();
        $stmt->close();
    }
    return $resident ?: null;
}

/**
 * users.resident_id links a resident account to its record; email_verify_hash and the
 * 'Unverified' status hold a self-registered account until its email is confirmed.
 */
function ensureUserAccountColumns(mysqli $conn): void {
    $columns = [];
    foreach ($conn->query("SHOW COLUMNS FROM users WHERE Field IN ('resident_id', 'email_verify_hash', 'status')")->fetch_all(MYSQLI_ASSOC) as $column) {
        $columns[$column['Field']] = strtolower($column['Type']);
    }
    if (!isset($columns['resident_id'])) {
        $conn->query('ALTER TABLE users ADD COLUMN resident_id INT UNSIGNED NULL, ADD KEY idx_users_resident_id (resident_id)');
    }
    if (!isset($columns['email_verify_hash'])) {
        $conn->query('ALTER TABLE users ADD COLUMN email_verify_hash CHAR(64) NULL');
    }
    if (isset($columns['status']) && str_starts_with($columns['status'], 'enum(') && !str_contains($columns['status'], "'unverified'")) {
        $conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('Active','Inactive','Unverified') NOT NULL DEFAULT 'Active'");
    }
}

function ensureCertificateRequesterColumns(mysqli $conn): void {
    $columns = [];
    foreach ($conn->query("SHOW COLUMNS FROM certificates WHERE Field IN ('requested_by_user_id', 'requester_relationship')")->fetch_all(MYSQLI_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }
    if (!isset($columns['requested_by_user_id'])) {
        $conn->query('ALTER TABLE certificates ADD COLUMN requested_by_user_id INT UNSIGNED NULL AFTER resident_id');
    }
    if (!isset($columns['requester_relationship'])) {
        $conn->query('ALTER TABLE certificates ADD COLUMN requester_relationship VARCHAR(100) NULL AFTER requested_by_user_id');
    }
    if ($conn->query("SHOW INDEX FROM certificates WHERE Key_name = 'idx_certificates_requested_by'")->num_rows === 0) {
        $conn->query('ALTER TABLE certificates ADD KEY idx_certificates_requested_by (requested_by_user_id)');
    }
}

/**
 * Voter status lives only in residents.voter_status. Older records ticked a
 * "Registered Voter" category instead; move those over and drop the category.
 */
function migrateRegisteredVoterCategory(mysqli $conn): void {
    try {
        $conn->query("UPDATE residents
            SET voter_status = 'Registered',
                categories = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', REPLACE(categories, ', ', ','), ','), ',Registered Voter,', ',')), '')
            WHERE FIND_IN_SET('Registered Voter', REPLACE(categories, ', ', ','))");
    } catch (mysqli_sql_exception) {
        // residents table not installed yet
    }
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

// Images uploaded in Settings for the certificate header. name => [label, bundled default].
const BRANDING_IMAGES = [
    'left_logo' => ['Left Logo', 'logo.png'],
    'right_logo' => ['Right Logo', 'logosm.png'],
    'seal' => ['Official Seal', 'seal.jpg'],
];

// Stored in the database because Railway's disk is wiped on every deploy.
function ensureBrandingImagesTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS branding_images (
            name VARCHAR(20) NOT NULL,
            mime VARCHAR(50) NOT NULL,
            data MEDIUMBLOB NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * URL for a branding image: the one uploaded in Settings, else the older file-based
 * barangay logo (left logo only), else the bundled default. ?v= changes on each upload.
 */
function brandingImageUrl(string $name): string {
    static $versions = null;
    $conn = $GLOBALS['conn'] ?? null;
    if ($versions === null) {
        $versions = [];
        if ($conn instanceof mysqli) {
            try {
                foreach ($conn->query('SELECT name, UNIX_TIMESTAMP(updated_at) FROM branding_images')->fetch_all() as [$imageName, $version]) {
                    $versions[$imageName] = $version;
                }
            } catch (mysqli_sql_exception) {
                // table created on the first upload
            }
        }
    }
    if (isset($versions[$name])) {
        return 'branding_image.php?name=' . rawurlencode($name) . '&v=' . $versions[$name];
    }
    if ($name === 'left_logo' && $conn instanceof mysqli) {
        $legacy = (string)($conn->query('SELECT logo_path FROM barangay_settings LIMIT 1')->fetch_row()[0] ?? '');
        if ($legacy !== '' && file_exists(__DIR__ . '/' . $legacy)) {
            return $legacy;
        }
    }
    $default = BRANDING_IMAGES[$name][1];
    return $default . '?v=' . filemtime(__DIR__ . '/' . $default);
}

function barangayLogoPath(): string {
    return brandingImageUrl('left_logo');
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

/**
 * The visitor's own IP. On Railway every request arrives from the proxy (100.64.0.0/10),
 * which appends the real client to X-Forwarded-For; reading it from the right means a
 * client-supplied fake entry further left is ignored. Anywhere else REMOTE_ADDR is used.
 */
function loginRequestIp(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $long = ip2long($remote);
    $fromRailwayProxy = $long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    if ($fromRailwayProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (array_reverse(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $candidateLong = ip2long($candidate);
                if ($candidateLong === false || ($candidateLong & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000)) {
                    return $candidate;
                }
            }
        }
    }
    return $remote;
}

function loginIdentifierHash(string $value): string {
    return hash_hmac('sha256', strtolower(trim($value)), authCookieSecret());
}

/**
 * Blocks only the visitor who keeps failing: 5 misses on one account, or 20 overall,
 * from the same IP within 15 minutes. Someone else's wrong guesses never lock out
 * the real owner of an account.
 */
function isLoginRateLimited(mysqli $conn, string $username): bool {
    ensureLoginAttemptsTable($conn);
    $ipHash = loginIdentifierHash(loginRequestIp());
    $usernameHash = loginIdentifierHash($username);
    $stmt = $conn->prepare('SELECT COUNT(*), COALESCE(SUM(username_hash = ?), 0) FROM login_attempts WHERE successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND ip_hash = ?');
    $stmt->bind_param('ss', $usernameHash, $ipHash);
    $stmt->execute();
    [$fromIp, $forAccount] = array_map('intval', $stmt->get_result()->fetch_row());
    $stmt->close();
    return $fromIp >= 20 || $forAccount >= 5;
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
    $host = getenv('SMTP_HOST') ?: '';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    $smtpReady = $host !== '' && $username !== '' && $password !== '';

    // Gmail API (HTTPS) first: Railway blocks outbound SMTP, and mail sent by Gmail
    // itself passes SPF/DKIM so it lands in the inbox. Falls through on failure.
    $gmailClientId = getenv('GMAIL_CLIENT_ID') ?: '';
    $gmailClientSecret = getenv('GMAIL_CLIENT_SECRET') ?: '';
    $gmailRefreshToken = getenv('GMAIL_REFRESH_TOKEN') ?: '';
    if ($gmailClientId !== '' && $gmailClientSecret !== '' && $gmailRefreshToken !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $httpPost = static function (string $url, string $contentType, string $content, string $extraHeaders = ''): array {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: {$contentType}\r\nContent-Length: " . strlen($content) . "\r\n" . $extraHeaders,
                'content' => $content,
                'ignore_errors' => true,
                'timeout' => 10,
            ]]);
            $response = @file_get_contents($url, false, $context);
            $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
            preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $matches);
            return [(int)($matches[1] ?? 0), json_decode((string)$response, true) ?: []];
        };

        [$tokenStatus, $tokenData] = $httpPost('https://oauth2.googleapis.com/token', 'application/x-www-form-urlencoded', http_build_query([
            'client_id' => $gmailClientId,
            'client_secret' => $gmailClientSecret,
            'refresh_token' => $gmailRefreshToken,
            'grant_type' => 'refresh_token',
        ]));
        $accessToken = $tokenData['access_token'] ?? '';
        if ($accessToken === '') {
            error_log('Gmail API token refresh failed with HTTP ' . $tokenStatus . ': ' . ($tokenData['error'] ?? 'unknown'));
        } else {
            $sender = getenv('GMAIL_SENDER') ?: $from;
            $mime = 'From: Barangay Management System <' . $sender . ">\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $body;
            $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
            [$sendStatus] = $httpPost('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', 'application/json', json_encode(['raw' => $raw]), "Authorization: Bearer {$accessToken}\r\n");
            if ($sendStatus >= 200 && $sendStatus < 300) {
                return true;
            }
            error_log('Gmail API rejected reset email with HTTP ' . $sendStatus . '.');
        }
    }

    // SMTP (e.g. Gmail + App Password) wins when configured: mail sent through the
    // sender's own provider passes SPF/DKIM, while SendGrid "from @gmail.com" lands in spam.
    $sendGridKey = getenv('SENDGRID_API_KEY') ?: '';
    $sendGridFrom = getenv('SENDGRID_FROM_EMAIL') ?: $from;
    if (!$smtpReady && $sendGridKey !== '' && filter_var($sendGridFrom, FILTER_VALIDATE_EMAIL)) {
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

    if (!$smtpReady || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('SMTP reset email skipped: required configuration is missing.');
        return false;
    }
    // Send as the authenticated account so the From domain matches the signing domain.
    if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $from = $username;
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

    $headers = 'From: Barangay Management System <' . $from . '>' . "\r\n" . 'Reply-To: ' . $from . "\r\n" . 'Date: ' . date('r') . "\r\n" . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>' . "\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
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

/**
 * Personnel whose term_end has passed become Inactive (kept for audit history,
 * blocked from login, dropped from certificates). Re-elected = extend term_end.
 */
function expireOfficialTerms(mysqli $conn): void {
    try {
        $column = $conn->query("SHOW COLUMNS FROM users LIKE 'term_end'");
    } catch (mysqli_sql_exception) {
        return; // users table not installed yet
    }
    if (!$column) {
        return;
    }
    if ($column->num_rows === 0) {
        $conn->query('ALTER TABLE users ADD COLUMN term_end DATE NULL');
    }
    $column = $conn->query("SHOW COLUMNS FROM users LIKE 'term_start'");
    if ($column && $column->num_rows === 0) {
        $conn->query('ALTER TABLE users ADD COLUMN term_start DATE NULL');
    }

    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT id FROM users WHERE term_end < ? AND status = 'Active' AND role IN ('staff', 'health_worker', 'security_force')");
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $expired = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($expired as $row) {
        $id = (int)$row['id'];
        $conn->query("UPDATE users SET status = 'Inactive' WHERE id = $id");
        logActivity($conn, 'updated', 'users', 'Term ended; account set to Inactive.', $id);
    }
}

/**
 * Admin notification items: terms ending within 30 days, and a missing captain
 * (certificates then fall back to the Settings name).
 */
function officialTermAlerts(mysqli $conn): array {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return [];
    }

    $alerts = [];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+30 days'));
    $stmt = $conn->prepare("SELECT id, first_name, last_name, term_end FROM users WHERE status = 'Active' AND term_end BETWEEN ? AND ? AND role IN ('staff', 'health_worker', 'security_force') ORDER BY term_end");
    $stmt->bind_param('ss', $today, $soon);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $alerts[] = ['icon' => 'fa-hourglass-end', 'text' => 'Term ending: Hon. ' . trim($row['first_name'] . ' ' . $row['last_name']), 'detail' => 'Ends ' . date('M d, Y', strtotime($row['term_end'])), 'href' => 'officials.php?edit=' . (int)$row['id']];
    }
    $stmt->close();

    $captain = $conn->query("SELECT 1 FROM users WHERE role = 'staff' AND status = 'Active' AND (LOWER(position) LIKE '%captain%' OR LOWER(position) LIKE '%punong barangay%') AND LOWER(position) NOT LIKE '%vice%' AND LOWER(position) NOT LIKE '%deputy%' LIMIT 1");
    if ($captain && $captain->num_rows === 0) {
        $alerts[] = ['icon' => 'fa-user-slash', 'text' => 'No active Punong Barangay', 'detail' => 'Certificates will use the captain name in Settings', 'href' => 'officials.php'];
    }

    return $alerts;
}

const BACKUP_HEADER = '-- BarangayMS database backup';
// Sessions, login throttling and reset tokens are live security state, not records worth restoring.
// database_backups holds the backups themselves; dumping it would nest every old backup in each new one.
const BACKUP_SKIP_TABLES = ['php_sessions', 'login_attempts', 'password_resets', 'database_backups'];
const AUTO_BACKUP_HOUR = 17; // 5:00 PM Manila
const AUTO_BACKUPS_KEPT = 7;

function writeBackupChunk(mixed $output, string $chunk, ?HashContext $hash = null): void {
    if ($hash !== null) {
        hash_update($hash, $chunk);
    }
    $length = strlen($chunk);
    for ($offset = 0; $offset < $length;) {
        $written = fwrite($output, substr($chunk, $offset), $length - $offset);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write database backup data.');
        }
        $offset += $written;
    }
}

function streamDatabaseBackup(mysqli $conn, mixed $output = null, ?HashContext $hash = null): void {
    $closeOutput = $output === null;
    if ($closeOutput) {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not open backup output stream.');
        }
    }

    try {
        writeBackupChunk($output, BACKUP_HEADER . "\n-- Created: " . date('Y-m-d H:i:s') . "\n\n");
        writeBackupChunk($output, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n", $hash);

        $tables = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all();
        foreach ($tables as [$table]) {
            if (in_array($table, BACKUP_SKIP_TABLES, true)) {
                continue;
            }
            $tableHash = $table === 'activity_logs' ? null : $hash;
            $createSql = $conn->query('SHOW CREATE TABLE `' . $table . '`')->fetch_row()[1];
            writeBackupChunk($output, "DROP TABLE IF EXISTS `$table`;\n$createSql;\n", $tableHash);

            $rows = $conn->query('SELECT * FROM `' . $table . '`', MYSQLI_USE_RESULT);
            // Binary columns are hex-encoded in small pieces to avoid a second full-size BLOB allocation.
            $binary = array_map(
                fn($field) => $field->charsetnr === 63 && in_array($field->type, [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_STRING], true),
                $rows->fetch_fields()
            );
            $hasRows = false;
            while (($row = $rows->fetch_row()) !== null) {
                writeBackupChunk($output, $hasRows ? ",\n(" : "INSERT INTO `$table` VALUES\n(", $tableHash);
                foreach ($row as $index => $value) {
                    if ($index > 0) {
                        writeBackupChunk($output, ',', $tableHash);
                    }
                    if ($value === null) {
                        writeBackupChunk($output, 'NULL', $tableHash);
                    } elseif ($binary[$index]) {
                        writeBackupChunk($output, "X'", $tableHash);
                        $valueLength = strlen($value);
                        for ($offset = 0; $offset < $valueLength; $offset += 8192) {
                            writeBackupChunk($output, bin2hex(substr($value, $offset, 8192)), $tableHash);
                        }
                        writeBackupChunk($output, "'", $tableHash);
                    } else {
                        writeBackupChunk($output, "'" . $conn->real_escape_string($value) . "'", $tableHash);
                    }
                }
                writeBackupChunk($output, ')', $tableHash);
                $hasRows = true;
            }
            if ($hasRows) {
                writeBackupChunk($output, ";\n", $tableHash);
            }
            $rows->free();
            writeBackupChunk($output, "\n", $tableHash);
        }

        writeBackupChunk($output, "SET FOREIGN_KEY_CHECKS = 1;\n", $hash);
    } finally {
        if ($closeOutput && is_resource($output)) {
            fclose($output);
        }
    }
}

function restoreDatabaseBackup(mysqli $conn, string $sql): void {
    try {
        $conn->multi_query($sql);
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    } finally {
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// Kept in the database because Railway's disk is wiped on every deploy.
function ensureDatabaseBackupsTable(mysqli $conn): void {
    $conn->query(
        'CREATE TABLE IF NOT EXISTS database_backups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            backup_date DATE NOT NULL,
            sql_dump LONGBLOB NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_database_backups_date (backup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $hashColumn = $conn->query("SHOW COLUMNS FROM database_backups LIKE 'content_hash'");
    if ($hashColumn->num_rows === 0) {
        $conn->query('ALTER TABLE database_backups ADD COLUMN content_hash CHAR(64) NULL AFTER backup_date');
    }
}

/** Create a dated backup at 5 PM Manila time only when database content changed. */
function runScheduledBackup(mysqli $conn): bool {
    if ((int)date('G') < AUTO_BACKUP_HOUR) {
        return true;
    }
    $today = date('Y-m-d');
    try {
        ensureDatabaseBackupsTable($conn);
        $exists = $conn->query("SELECT 1 FROM database_backups WHERE backup_date = '$today' LIMIT 1");
        if ($exists->num_rows > 0 || (int)$conn->query("SELECT GET_LOCK('bms_auto_backup', 0)")->fetch_row()[0] !== 1) {
            return true;
        }
        try {
            // Another request may have finished the backup while this one waited for the lock.
            if ($conn->query("SELECT 1 FROM database_backups WHERE backup_date = '$today' LIMIT 1")->num_rows > 0) {
                return true;
            }
            $dump = fopen('php://temp/maxmemory:2097152', 'w+b');
            if ($dump === false) {
                throw new RuntimeException('Could not open temporary backup stream.');
            }
            try {
                $hash = hash_init('sha256');
                streamDatabaseBackup($conn, $dump, $hash);
                $contentHash = hash_final($hash);
                $lastBackup = $conn->query('SELECT content_hash FROM database_backups ORDER BY backup_date DESC, id DESC LIMIT 1')->fetch_assoc();
                $lastHash = (string)($lastBackup['content_hash'] ?? '');
                if ($lastHash !== '' && hash_equals($lastHash, $contentHash)) {
                    return true;
                }
                rewind($dump);

                $stmt = $conn->prepare('INSERT INTO database_backups (backup_date, content_hash, sql_dump) VALUES (?, ?, ?)');
                $dumpPlaceholder = '';
                $stmt->bind_param('ssb', $today, $contentHash, $dumpPlaceholder);
                try {
                    while (!feof($dump)) {
                        $chunk = fread($dump, 1048576);
                        if ($chunk === false) {
                            throw new RuntimeException('Could not read temporary backup stream.');
                        }
                        if ($chunk === '') {
                            break;
                        }
                        if (!$stmt->send_long_data(2, $chunk)) {
                            throw new RuntimeException('Could not send backup data to the database.');
                        }
                    }
                    $stmt->execute();
                    $backupId = (int)$stmt->insert_id;
                } finally {
                    $stmt->close();
                }
            } finally {
                fclose($dump);
            }

            $conn->query('DELETE FROM database_backups WHERE id NOT IN (SELECT id FROM (SELECT id FROM database_backups ORDER BY backup_date DESC LIMIT ' . AUTO_BACKUPS_KEPT . ') AS kept)');
        } finally {
            $conn->query("SELECT RELEASE_LOCK('bms_auto_backup')");
        }
        return true;
    } catch (Throwable $e) {
        error_log('Automatic database backup failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Emergency alerts live apart from complaints. Status flow:
 * Reported (a resident's report, not yet broadcast) -> Active (sent to all residents) -> Resolved.
 * On first run, EMG- reports that used to be stored as complaints are moved here.
 */
function ensureEmergencyAlertsTable(mysqli $conn): void {
    if ($conn->query("SHOW TABLES LIKE 'emergency_alerts'")->num_rows > 0) {
        return;
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS emergency_alerts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_number VARCHAR(50) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            resident_id INT UNSIGNED NULL,
            reported_by VARCHAR(150) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Reported',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_emergency_alerts_tracking (tracking_number),
            KEY idx_emergency_alerts_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "INSERT IGNORE INTO emergency_alerts (tracking_number, category, description, resident_id, reported_by, status, created_at)
         SELECT tracking_number, category, COALESCE(description, ''), resident_id, resident_name,
                CASE status WHEN 'Ongoing' THEN 'Active' WHEN 'Resolved' THEN 'Resolved' ELSE 'Reported' END,
                date_filed
         FROM complaints WHERE tracking_number LIKE 'EMG-%' ORDER BY id"
    );
    $conn->query("DELETE FROM complaints WHERE tracking_number LIKE 'EMG-%'");
}

function nextEmergencyTrackingNumber(mysqli $conn): string {
    $prefix = 'EMG-' . date('Y') . '-';
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(tracking_number, '-', -1) AS UNSIGNED)), 0) + 1 FROM emergency_alerts WHERE tracking_number LIKE CONCAT(?, '%')");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $next = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function runDatabaseMigration(callable $migration, string $name): void {
    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        $migration();
    } catch (Throwable $exception) {
        error_log(sprintf(
            'Database migration %s failed: %s in %s:%d',
            $name,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
    } finally {
        restore_error_handler();
    }
}

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    runDatabaseMigration(static fn () => ensureActivityLogsTable($GLOBALS['conn']), 'activity_logs');
    runDatabaseMigration(static fn () => ensureEmergencyAlertsTable($GLOBALS['conn']), 'emergency_alerts');
    runDatabaseMigration(static fn () => ensureUserAccountColumns($GLOBALS['conn']), 'user account columns');
    runDatabaseMigration(static fn () => ensureCertificateRequesterColumns($GLOBALS['conn']), 'certificate requester columns');
    runDatabaseMigration(static fn () => migrateRegisteredVoterCategory($GLOBALS['conn']), 'registered voter migration');
    runDatabaseMigration(static fn () => expireOfficialTerms($GLOBALS['conn']), 'official terms');
}
// Scheduled backups run through backup.php, not during web requests.
