<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
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
        } else {
            $stmt = $conn->prepare('SELECT id FROM residents WHERE resident_number = ? AND first_name = ? AND last_name = ? AND email = ? LIMIT 1');
            $stmt->bind_param('ssss', $old['resident_number'], $old['first_name'], $old['last_name'], $old['email']);
            $stmt->execute();
            $residentExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$residentExists) {
                $error = 'The information does not match an existing resident record.';
            } else {
                $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
                $stmt->bind_param('ss', $old['username'], $old['email']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($exists) {
                    $error = 'That username or email address is already registered.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'resident';
                    $status = 'Active';
                    $stmt = $conn->prepare('INSERT INTO users (first_name, last_name, username, email, password_hash, role, status, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('ssssssss', $old['first_name'], $old['last_name'], $old['username'], $old['email'], $passwordHash, $role, $status, $old['address']);

                    if ($stmt->execute()) {
                        $stmt->close();
                        logActivity($conn, 'created', 'users', 'Resident account registered via public form.', null, null);
                        header('Location: auth.php?registered=1');
                        exit;
                    }

                    $error = 'Registration failed. Please try again.';
                    $stmt->close();
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

    <link rel="stylesheet" href="register.css?v=2">

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
                        required>
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

                <i class="fa-regular fa-eye"></i>
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

                <i class="fa-regular fa-eye"></i>
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

</body>
</html>
