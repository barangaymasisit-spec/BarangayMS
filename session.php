<?php

final class DatabaseSessionHandler implements SessionHandlerInterface {
    private ?mysqli $connection = null;
    private static bool $tableChecked = false;

    public static function databaseAvailable(): bool {
        $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
        $port = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
        $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
        $password = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
        $database = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement';

        try {
            $conn = @new mysqli($host, $user, $password, $database, $port);
            if ($conn->connect_error) {
                return false;
            }
            $conn->close();
            return true;
        } catch (Throwable $e) {
            error_log('Database session fallback triggered: ' . $e->getMessage());
            return false;
        }
    }

    public function open(string $path, string $name): bool {
        $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
        $port = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
        $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
        $password = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
        $database = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement';

        try {
            $this->connection = @new mysqli($host, $user, $password, $database, $port);
            if (!$this->connection || $this->connection->connect_error) {
                $this->connection = null;
                return false;
            }
            $this->connection->set_charset('utf8mb4');
            if (!self::$tableChecked) {
                $created = $this->connection->query(
                    'CREATE TABLE IF NOT EXISTS php_sessions (
                        id VARCHAR(128) NOT NULL,
                        data MEDIUMBLOB NOT NULL,
                        last_activity INT UNSIGNED NOT NULL,
                        PRIMARY KEY (id),
                        KEY idx_php_sessions_last_activity (last_activity)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                if (!$created) {
                    error_log('PHP session table initialization failed: ' . $this->connection->error);
                    $this->connection->close();
                    $this->connection = null;
                    return false;
                }
                self::$tableChecked = true;
            }
            return true;
        } catch (Throwable $e) {
            error_log('Database session open failed: ' . $e->getMessage());
            $this->connection = null;
            return false;
        }
    }

    public function close(): bool {
        if ($this->connection instanceof mysqli) {
            $this->connection->close();
        }
        $this->connection = null;
        return true;
    }

    public function read(string $id): string {
        if (!$this->connection) {
            return '';
        }
        $stmt = $this->connection->prepare('SELECT data FROM php_sessions WHERE id = ? LIMIT 1');
        if (!$stmt) {
            error_log('PHP session read prepare failed: ' . $this->connection->error);
            return '';
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $data = (string)($stmt->get_result()->fetch_column() ?: '');
        $stmt->close();
        return $data;
    }

    public function write(string $id, string $data): bool {
        if (!$this->connection) {
            return false;
        }
        $now = time();
        $stmt = $this->connection->prepare(
            'INSERT INTO php_sessions (id, data, last_activity) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)'
        );
        if (!$stmt) {
            error_log('PHP session write prepare failed: ' . $this->connection->error);
            return false;
        }
        $stmt->bind_param('ssi', $id, $data, $now);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    public function destroy(string $id): bool {
        if (!$this->connection) {
            return false;
        }
        $stmt = $this->connection->prepare('DELETE FROM php_sessions WHERE id = ?');
        $stmt->bind_param('s', $id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    public function gc(int $maxLifetime): int|false {
        if (!$this->connection) {
            return false;
        }
        $cutoff = time() - $maxLifetime;
        $stmt = $this->connection->prepare('DELETE FROM php_sessions WHERE last_activity < ?');
        $stmt->bind_param('i', $cutoff);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        return $deleted;
    }
}

function authCookieSecret(): string {
    return (string)(getenv('APP_KEY') ?: hash('sha256', implode('|', [
        getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement',
        getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root',
        getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '',
    ])));
}

function setPersistentAuthCookie(array $user): void {
    $payload = base64_encode(json_encode([
        'id' => (int)$user['id'],
        'exp' => time() + (30 * 24 * 60 * 60),
    ], JSON_THROW_ON_ERROR));
    $payload = rtrim(strtr($payload, '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, authCookieSecret());
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    setcookie('BARANGAY_AUTH', $payload . '.' . $signature, [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearPersistentAuthCookie(): void {
    setcookie('BARANGAY_AUTH', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

const SESSION_IDLE_TIMEOUT = 1800;

function enforceSessionIdleTimeout(): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) >= SESSION_IDLE_TIMEOUT) {
        clearPersistentAuthCookie();
        unset($_COOKIE['BARANGAY_AUTH']);
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['last_activity'] = time();
}

function restorePersistentAuthSession(mysqli $conn): void {
    if (isset($_SESSION['user_id']) || empty($_COOKIE['BARANGAY_AUTH'])) {
        return;
    }

    [$payload, $signature] = array_pad(explode('.', (string)$_COOKIE['BARANGAY_AUTH'], 2), 2, '');
    if ($payload === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $payload, authCookieSecret()), $signature)) {
        clearPersistentAuthCookie();
        return;
    }

    $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $userId = (int)($decoded['id'] ?? 0);
    if ($userId < 1 || (int)($decoded['exp'] ?? 0) < time()) {
        return;
    }

    $stmt = $conn->prepare('SELECT id, first_name, last_name, username, email, role FROM users WHERE id = ? AND status = \'Active\' LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        return;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Re-checks the logged-in account on every request so a deactivated, deleted
 * or re-roled account loses its old access right away (not at next login).
 */
function enforceActiveAccount(mysqli $conn): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        clearPersistentAuthCookie();
        unset($_COOKIE['BARANGAY_AUTH']);
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['role'] = $user['role'];
}

function applyNoStoreHeaders(): void {
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');
}

function refreshSessionCsrfToken(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function setSessionRecoveryNotice(string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['session_recovery_notice'] = $message;
}

function consumeSessionRecoveryNotice(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $message = (string)($_SESSION['session_recovery_notice'] ?? '');
    unset($_SESSION['session_recovery_notice']);
    return $message;
}

function recoverStaleAuthenticatedSession(): void {
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        return;
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        session_regenerate_id(true);
        refreshSessionCsrfToken();
        return;
    }

    $currentSessionId = session_id();
    if ($currentSessionId === '') {
        session_regenerate_id(true);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('BARANGAYSESSID');
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (DatabaseSessionHandler::databaseAvailable()) {
        $sessionHandler = new DatabaseSessionHandler();
        session_set_save_handler($sessionHandler, true);
    } else {
        error_log('Database session store unavailable; falling back to PHP file-based sessions.');
    }

    ini_set('session.use_strict_mode', '1');
    session_start();
}

enforceSessionIdleTimeout();
recoverStaleAuthenticatedSession();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    refreshSessionCsrfToken();
}

applyNoStoreHeaders();
