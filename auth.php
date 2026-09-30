<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
applyNoStoreHeaders();

if (isset($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $redirectPage = ($_SESSION['role'] === 'resident') ? 'resident_dashboard.php' : 'dashboard.php';
    if (in_array($_SESSION['role'], ['admin', 'staff', 'health_worker', 'security_force', 'resident'], true)) {
        header('Location: ' . $redirectPage);
        exit;
    }
}

if (isset($_SESSION['user_id'])) {
    $_SESSION = [];
    session_destroy();
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        http_response_code(403);
        exit('Invalid or expired form token. Please go back, refresh the page, and try again.');
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    clearPersistentAuthCookie();
    unset($_COOKIE['BARANGAY_AUTH']);

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (isLoginRateLimited($conn, $username)) {
        $error = 'Too many failed login attempts. Please wait 15 minutes before trying again.';
    } else {
        $stmt = $conn->prepare('SELECT id, first_name, last_name, username, email, password_hash, role, status FROM users WHERE BINARY username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Invalid username or password.';
            recordLoginAttempt($conn, $username, false);
        } else {
            $userId = (int)$user['id'];
            if (!in_array($user['status'], ['Active', 'Unverified'], true)) {
                $error = 'Invalid username or password.';
                recordLoginAttempt($conn, $username, false);
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Invalid username or password.';
                recordLoginAttempt($conn, $username, false);
                logActivity($conn, 'login_failed', 'users', 'Failed login attempt for username: ' . $username, $userId, $userId);
            } elseif (!in_array($user['role'], ['admin', 'staff', 'health_worker', 'security_force', 'resident'], true)) {
                $error = 'Invalid username or password.';
            } elseif ($user['status'] === 'Unverified') {
                // Only said after the correct password, so it reveals nothing to someone guessing.
                $error = 'Please confirm your email first. Open the link we sent to your inbox (check Spam too).';
            } else {
                clearFailedLogin($conn, $userId);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                rememberPasswordFingerprint($user['password_hash']);
                recordLoginAttempt($conn, $username, true);
                if ($remember) {
                    setPersistentAuthCookie($user);
                } else {
                    clearPersistentAuthCookie();
                }
                logActivity($conn, 'login', 'users', 'User logged in successfully.', $userId, $userId);
                header('Location: ' . ($user['role'] === 'resident' ? 'resident_dashboard.php' : 'dashboard.php'));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Management System | Login</title>
    <link rel="stylesheet" href="auth.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>

<div class="location-banner" aria-label="Barangay location">
    <span class="location-pin" aria-hidden="true"><span></span></span>
    <div>
        <strong>Masisisit, Sanchez Mira, Cagayan</strong>
        <small>Serbisyong Tapat, Barangay Masisit!</small>
    </div>
</div>

<div class="container">

    <div class="login-box">

        <img src="<?php echo h(barangayLogoPath()); ?>" alt="Barangay Logo" class="logo">

        <h2>Barangay Management System</h2>
        <p>Please login to continue</p>

        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['denied'])): ?>
            <div class="error-message" role="alert">
                That account is not allowed to open the management system.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['expired'])): ?>
            <div class="error-message" role="alert">
                You were logged out because the system was inactive for too long. Please log in again.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['verified'])): ?>
            <div class="success-message" role="status">
                Your email is confirmed. You may now log in.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['verify_failed'])): ?>
            <div class="error-message" role="alert">
                That confirmation link is invalid or has expired. Please register again.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="success-message" role="status">
                Almost done! We sent a confirmation link to your email. Open it to activate your account, then log in.
            </div>
        <?php endif; ?>

        <form action="auth.php" method="POST">
            <?php echo csrfField(); ?>

            <div class="input-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter Username"
                    value="<?php echo h($username); ?>"
                    required
                >
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter Password"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="options">
                <label for="remember">
                    <input
                        type="checkbox"
                        id="remember"
                        name="remember"
                    >
                    Remember Me
                </label>
            </div>

            <button type="submit">Login</button>

            <div class="links">
                <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>

                <p>
                    Don't have an account yet?
                    <a href="register.php" class="create-account">
                        Create one
                    </a>
                </p>
            </div>

        </form>

    </div>

</div>

<script>
    const passwordInput = document.getElementById('password');
    const passwordToggle = document.querySelector('.password-toggle');

    if (passwordInput && passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            this.setAttribute('aria-pressed', String(isPassword));
            this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            this.innerHTML = '<i class="fa-solid ' + (isPassword ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i>';
            passwordInput.focus();
        });
    }
</script>

<script src="password-peek.js"></script>
</body>
</html>
