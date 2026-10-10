<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$notice = '';
$registrationAllowed = true;
$settingsResult = $conn->query('SELECT allow_registration FROM barangay_settings LIMIT 1');
if ($settingsResult && ($registrationSettings = $settingsResult->fetch_assoc())) {
    $registrationAllowed = (int)$registrationSettings['allow_registration'] === 1;
}
$old = [
    'first_name' => '',
    'last_name'  => '',
    'resident_number' => '',
    'username'   => '',
    'address'    => '',
    'email'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        http_response_code(403);
        exit('Invalid or expired form token. Please go back, refresh the page, and try again.');
    }

    $old['first_name'] = trim($_POST['first_name'] ?? '');
    $old['last_name']  = trim($_POST['last_name'] ?? '');
    $old['resident_number'] = trim($_POST['resident_number'] ?? '');
    $old['username']   = trim($_POST['username'] ?? '');
    $old['address']    = trim($_POST['address'] ?? '');
    $old['email']      = trim($_POST['email'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirmPassword   = $_POST['confirm_password'] ?? '';

    if (($_POST['action'] ?? '') === 'get_resident_number') {
        if (!$registrationAllowed) {
            $error = 'Resident account registration is currently disabled.';
        } elseif ($old['first_name'] === '' || $old['last_name'] === '' || $old['email'] === '') {
            $error = 'Enter your first name, last name, and email address first.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $requestKey = 'resident-number:' . strtolower($old['email']);
            if (!isLoginRateLimited($conn, $requestKey)) {
                $stmt = $conn->prepare('SELECT resident_number, email FROM residents WHERE first_name = ? AND last_name = ? AND email = ? ORDER BY id LIMIT 2');
                $stmt->bind_param('sss', $old['first_name'], $old['last_name'], $old['email']);
                $stmt->execute();
                $residentMatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                if (count($residentMatches) === 1) {
                    $settings = $conn->query('SELECT email FROM barangay_settings LIMIT 1')->fetch_assoc() ?: [];
                    $from = trim((string)($settings['email'] ?? '')) ?: trim((string)(getenv('MAIL_FROM') ?: ''));
                    $body = "Your resident number is: " . $residentMatches[0]['resident_number']
                        . "\n\nEnter this number on the Barangay Management System registration form. Keep it private. If you did not request this message, ignore it or contact the barangay office.\n";
                    if ($from !== '') {
                        sendSmtpEmail($residentMatches[0]['email'], 'Your Barangay Resident Number', $body, $from);
                    }
                }
                recordLoginAttempt($conn, $requestKey, false);
            }

            $notice = 'If your details match and the email was sent, check the inbox and Spam folder for the resident number. If it does not arrive, wait before requesting again or contact the barangay office.';
        }
    } else {
    if (!$registrationAllowed) {
        $error = 'Resident account registration is currently disabled.';
    } elseif ($old['first_name'] === '' || $old['last_name'] === '' || $old['resident_number'] === '' || $old['username'] === '' || $old['email'] === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and confirmation do not match.';
    } else {
        $passwordValidation = validatePasswordStrength($password);
        if (!$passwordValidation['valid']) {
            $error = implode(' ', $passwordValidation['errors']);
        } elseif (!isset($_POST['terms'])) {
            $error = 'You must accept the Terms and Conditions.';
        } elseif (isLoginRateLimited($conn, 'register:' . strtolower($old['resident_number']))) {
            $error = 'Too many registration attempts. Please wait 15 minutes before trying again.';
        } else {
            // Remove old unconfirmed accounts left by the previous email-link flow.
            $conn->query("DELETE FROM users WHERE status = 'Unverified' AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY");

            $stmt = $conn->prepare('SELECT id FROM residents WHERE resident_number = ? AND first_name = ? AND last_name = ? AND email = ? LIMIT 1');
            $stmt->bind_param('ssss', $old['resident_number'], $old['first_name'], $old['last_name'], $old['email']);
            $stmt->execute();
            $residentExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$residentExists) {
                recordLoginAttempt($conn, 'register:' . strtolower($old['resident_number']), false);
                $error = 'The information does not match an existing resident record.';
            } else {
                $residentId = (int)$residentExists['id'];
                $stmt = $conn->prepare('SELECT id FROM users WHERE BINARY username = ? OR email = ? OR resident_id = ? LIMIT 1');
                $stmt->bind_param('ssi', $old['username'], $old['email'], $residentId);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $error = 'That username, email address, or resident already has an account.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'resident';
                    $status = 'Active';
                    $stmt = $conn->prepare('INSERT INTO users (first_name, last_name, username, email, password_hash, role, status, address, resident_id, email_verify_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
                    $stmt->bind_param('ssssssssi', $old['first_name'], $old['last_name'], $old['username'], $old['email'], $passwordHash, $role, $status, $old['address'], $residentId);
                    try {
                        $inserted = $stmt->execute();
                        $duplicate = false;
                    } catch (mysqli_sql_exception $exception) {
                        $inserted = false;
                        $duplicate = $exception->getCode() === 1062;
                    }

                    if ($inserted) {
                        $newUserId = (int)$stmt->insert_id;
                        $stmt->close();
                        try {
                            logActivity($conn, 'created', 'users', 'Resident account registered via public form.', $newUserId, null);
                        } catch (Throwable $logError) {
                            error_log('Registration activity log failed: ' . $logError->getMessage());
                        }
                        header('Location: auth.php?registered=1');
                        exit;
                    } else {
                        $stmt->close();
                        $error = $duplicate
                            ? 'That username, email address, or resident already has an account.'
                            : 'Registration failed. Please try again.';
                    }
                }
            }
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
    <title>Create Account</title>

    <link rel="stylesheet" href="register.css?v=3">
    <link rel="stylesheet" href="toast.css?v=1">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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

    <div class="register-box">

        <div class="header">
            <a href="auth.php" aria-label="Go back to Login">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h2>Register</h2>
        </div>

        <img src="<?php echo h(barangayLogoPath()); ?>" class="logo" alt="Barangay Logo">

        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <?php renderToast($notice); ?>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <?php echo csrfField(); ?>

            <!-- First Name -->
            <div class="input-box">
                <label for="first_name" class="sr-only">First Name</label>

                <i class="fa-regular fa-user"></i>

                <input
                    type="text"
                    id="first_name"
                    name="first_name"
                    placeholder="First Name"
                    value="<?php echo h($old['first_name']); ?>"
                    required>
            </div>

            <!-- Last Name -->
            <div class="input-box">
                <label for="last_name" class="sr-only">Last Name</label>

                <i class="fa-regular fa-user"></i>

                <input
                    type="text"
                    id="last_name"
                    name="last_name"
                    placeholder="Last Name"
                    value="<?php echo h($old['last_name']); ?>"
                    required>
            </div>

                    <!-- Resident Number -->
                    <div class="input-box">
                    <label for="resident_number" class="sr-only">Resident Number</label>

                    <i class="fa-solid fa-id-card"></i>

                    <input
                        type="text"
                        id="resident_number"
                        name="resident_number"
                        placeholder="Resident Number"
                        value="<?php echo h($old['resident_number']); ?>"
                        autocomplete="off"
                        required>
                    </div>

            <div class="resident-number-help">
                <button type="submit" name="action" value="get_resident_number" formnovalidate class="secondary-button">Get Resident Number</button>
                <p>We will send it to the email address registered with the barangay.</p>
            </div>

            <!-- Username -->
            <div class="input-box">
                <label for="username" class="sr-only">Username</label>

                <i class="fa-solid fa-id-badge"></i>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Username"
                    value="<?php echo h($old['username']); ?>"
                    required>
            </div>

            <!-- Address -->
            <div class="input-box">
                <label for="address" class="sr-only">Address</label>

                <i class="fa-solid fa-location-dot"></i>

                <input
                    type="text"
                    id="address"
                    name="address"
                    placeholder="Address (Optional)"
                    value="<?php echo h($old['address']); ?>">
            </div>

            <!-- Email -->
            <div class="input-box">
                <label for="email" class="sr-only">Email Address</label>

                <i class="fa-regular fa-envelope"></i>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Email Address"
                    value="<?php echo h($old['email']); ?>"
                    required>
            </div>

            <!-- Password -->
            <div class="input-box">
                <label for="password" class="sr-only">Password</label>

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    required>

                <button type="button" class="password-toggle" aria-controls="password" aria-label="Show password" aria-pressed="false">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Confirm Password -->
            <div class="input-box">
                <label for="confirm_password" class="sr-only">Confirm Password</label>

                <i class="fa-solid fa-lock"></i>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Confirm Password"
                    required>

                <button type="button" class="password-toggle" aria-controls="confirm_password" aria-label="Show password" aria-pressed="false">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Terms -->
            <div class="terms">
                <input
                    type="checkbox"
                    id="terms"
                    name="terms"
                    required>

                <label for="terms">
                    By proceeding, I accept the
                    <a href="#">Terms and Conditions</a>
                    and
                    <a href="#">Privacy Policy</a>.
                </label>
            </div>

            <button type="submit">Register</button>

            <div class="login-link">
                <p>
                    Already have an account?
                    <a href="auth.php">Login</a>
                </p>
            </div>

        </form>

    </div>

</div>


<script>
    document.querySelectorAll('.password-toggle').forEach((toggle) => {
        const input = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!input) return;

        toggle.addEventListener('click', () => {
            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(showPassword));
            toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            toggle.innerHTML = '<i class="fa-solid ' + (showPassword ? 'fa-eye-slash' : 'fa-eye') + '" aria-hidden="true"></i>';
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
    });
</script>
<script src="password-peek.js"></script>
<script src="toast.js?v=1"></script>
</body>
</html>
