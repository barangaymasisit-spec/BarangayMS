<?php
require_once __DIR__ . '/session.php';
if (
    ($_SESSION['role'] ?? '') === 'admin'
    && !empty($_SESSION['admin_session_token'])
) {
    require_once __DIR__ . '/db.php';
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        try {
            releaseAdminSessionLock($conn, (int)$_SESSION['user_id'], (string)$_SESSION['admin_session_token']);
        } catch (mysqli_sql_exception $exception) {
            error_log('Could not release administrator session lock during logout: ' . $exception->getMessage());
        }
    }
}
clearPersistentAuthCookie();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
// session.php may have already destroyed an idle-expired session.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
$redirect = isset($_GET['expired']) ? 'auth.php?expired=1' : 'auth.php';
header('Location: ' . $redirect);
exit;
