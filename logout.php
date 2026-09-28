<?php
require_once __DIR__ . '/session.php';
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
