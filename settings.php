<?php
require_once __DIR__ . '/layout.php';

$error = '';
$success = '';
$recoveryNotice = consumeSessionRecoveryNotice();

if (isset($_GET['csrf_recovered']) && $_GET['csrf_recovered'] === '1') {
    $cleanQuery = $_SERVER['QUERY_STRING'] ?? '';
    if ($cleanQuery !== '') {
        parse_str($cleanQuery, $queryParams);
        unset($queryParams['csrf_recovered']);
        $redirectLocation = strtok($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'], '?') ?: ($_SERVER['PHP_SELF'] ?? 'settings.php');
        $queryString = http_build_query($queryParams);
        if ($queryString !== '') {
            $redirectLocation .= '?' . $queryString;
        }
        if ((string)($_SERVER['REQUEST_URI'] ?? '') !== $redirectLocation) {
            header('Location: ' . $redirectLocation, true, 303);
            exit;
        }
    }
}

$settingsResult = $conn->query('SELECT * FROM barangay_settings LIMIT 1');
if ($settingsResult->num_rows === 0) {
    $conn->query("INSERT INTO barangay_settings (barangay_name, barangay_captain, municipality, province, zip_code, contact_number, email, office_hours, address, enable_appointments, enable_complaints, enable_notifications, allow_registration) VALUES ('', '', '', '', '', '', '', '', '', 1, 1, 1, 1)");
    $settingsResult = $conn->query('SELECT * FROM barangay_settings LIMIT 1');
}
$settings = $settingsResult->fetch_assoc();
if (!$settings) {
    $settings = ['id' => 0, 'barangay_name' => '', 'barangay_captain' => '', 'municipality' => '', 'province' => '', 'zip_code' => '', 'contact_number' => '', 'email' => '', 'office_hours' => '', 'address' => '', 'logo_path' => '', 'enable_appointments' => 1, 'enable_complaints' => 1, 'enable_notifications' => 1, 'allow_registration' => 1];
}

$demoNames = ['Barangay Example', 'Barangay Examples'];
$currentValues = [$settings['barangay_name'], $settings['barangay_captain'], $settings['municipality'], $settings['province'], $settings['zip_code'], $settings['contact_number'], $settings['email'], $settings['office_hours'], $settings['address']];
$isDemoSettings = in_array($settings['barangay_name'], $demoNames, true)
    && $currentValues === [$settings['barangay_name'], 'Barangay Captain', 'Municipality', 'Province', '0000', '09171234567', 'barangay@example.com', '8:00 AM - 5:00 PM', 'Example Address'];
if ($isDemoSettings) {
    $conn->query("UPDATE barangay_settings SET barangay_name = '', barangay_captain = '', municipality = '', province = '', zip_code = '', contact_number = '', email = '', office_hours = '', address = '' WHERE id = " . (int)$settings['id']);
    $settings = $conn->query('SELECT * FROM barangay_settings LIMIT 1')->fetch_assoc();
}

$adminResult = $conn->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
if ($adminResult->num_rows === 0) {
    $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
    $conn->query("INSERT INTO users (first_name, last_name, username, email, password_hash, role, status) VALUES ('Admin', 'User', 'admin', 'admin@example.com', '$passwordHash', 'admin', 'Active')");
    $adminResult = $conn->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
}
$admin = $adminResult->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'barangay') {
        $barangayName = trim($_POST['barangay_name'] ?? '');
        $barangayCaptain = trim($_POST['barangay_captain'] ?? '');
        $municipality = trim($_POST['municipality'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $zipCode = trim($_POST['zip_code'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $officeHours = trim($_POST['office_hours'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $stmt = $conn->prepare('UPDATE barangay_settings SET barangay_name = ?, barangay_captain = ?, municipality = ?, province = ?, zip_code = ?, contact_number = ?, email = ?, office_hours = ?, address = ? WHERE id = ?');
        $stmt->bind_param('sssssssssi', $barangayName, $barangayCaptain, $municipality, $province, $zipCode, $contactNumber, $email, $officeHours, $address, $settings['id']);
        if (!$stmt->execute()) {
            $error = 'Unable to save the barangay email address. Please try again.';
            $stmt->close();
        } else {
            $stmt->close();
            $settings['email'] = $email;
        }
        $changes = [];
        foreach (['barangay_name' => $barangayName, 'barangay_captain' => $barangayCaptain, 'municipality' => $municipality, 'province' => $province, 'zip_code' => $zipCode, 'contact_number' => $contactNumber, 'email' => $email, 'office_hours' => $officeHours, 'address' => $address] as $field => $newValue) {
            $oldValue = (string)($settings[$field] ?? '');
            if ($oldValue !== $newValue) {
                $changes[] = $field . ': "' . $oldValue . '" -> "' . $newValue . '"';
            }
        }
        logActivity($conn, 'updated', 'barangay_settings', $changes ? implode('; ', $changes) : 'No barangay profile values changed.', (int)$settings['id']);
        $success = 'Barangay information updated successfully.';

        // Optional logo upload
        if (!empty($_FILES['barangay_logo']['name'])) {
            $allowed = ['image/png', 'image/jpeg'];
            $mimeType = mime_content_type($_FILES['barangay_logo']['tmp_name']);
            if (!in_array($mimeType, $allowed, true)) {
                $error = 'The barangay logo must be a PNG or JPG image.';
            } else {
                $uploadsDir = __DIR__ . '/uploads';
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
                $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['barangay_logo']['name']));
                if (move_uploaded_file($_FILES['barangay_logo']['tmp_name'], $uploadsDir . '/' . $fileName)) {
                    $logoPath = 'uploads/' . $fileName;
                    $stmt = $conn->prepare('UPDATE barangay_settings SET logo_path = ? WHERE id = ?');
                    $stmt->bind_param('si', $logoPath, $settings['id']);
                    $stmt->execute();
                    $stmt->close();
                    logActivity($conn, 'updated', 'barangay_settings', 'Updated barangay logo.', (int)$settings['id']);
                    $success = 'Barangay information and logo updated successfully.';
                } else {
                    $error = 'The logo could not be uploaded.';
                }
            }
        }
    }

    if ($formType === 'admin') {
        $adminName = trim($_POST['admin_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } elseif ($newPassword !== '' && !password_verify($currentPassword, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif ($newPassword !== '') {
            $passwordValidation = validatePasswordStrength($newPassword);
            if (!$passwordValidation['valid']) {
                $error = implode(' ', $passwordValidation['errors']);
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET first_name = ?, username = ?, password_hash = ? WHERE id = ?');
                $stmt->bind_param('sssi', $adminName, $username, $passwordHash, $admin['id']);
                $stmt->execute();
                $stmt->close();
                logActivity($conn, 'updated', 'users', 'Updated administrator account details.', (int)$admin['id']);
                $success = 'Administrator account updated successfully.';
            }
        } else {
            $stmt = $conn->prepare('UPDATE users SET first_name = ?, username = ? WHERE id = ?');
            $stmt->bind_param('ssi', $adminName, $username, $admin['id']);
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'users', 'Updated administrator account credentials.', (int)$admin['id']);
            $success = 'Administrator account updated successfully.';
        }
    }

    if ($formType === 'preferences') {
        $enableAppointments = isset($_POST['enable_appointments']) ? 1 : 0;
        $enableComplaints = isset($_POST['enable_complaints']) ? 1 : 0;
        $enableNotifications = isset($_POST['enable_notifications']) ? 1 : 0;
        $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;

        $stmt = $conn->prepare('UPDATE barangay_settings SET enable_appointments = ?, enable_complaints = ?, enable_notifications = ?, allow_registration = ? WHERE id = ?');
        $stmt->bind_param('iiiii', $enableAppointments, $enableComplaints, $enableNotifications, $allowRegistration, $settings['id']);
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'updated', 'barangay_settings', 'Updated system preferences.', (int)$settings['id']);
        $success = 'System preferences updated successfully.';
    }

    $settings = $conn->query('SELECT * FROM barangay_settings LIMIT 1')->fetch_assoc();
    $admin = $conn->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetch_assoc();
}

$totalResidents = (int)($conn->query('SELECT COUNT(*) FROM residents')->fetch_row()[0] ?? 0);
$totalAdministrators = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0] ?? 0);
$backupFiles = glob(__DIR__ . '/backups/*.sql');
$totalBackups = $backupFiles ? count($backupFiles) : 0;
$lastBackup = 'No backup recorded';
if ($backupFiles) {
    $lastBackup = date('F d, Y', max(array_map('filemtime', $backupFiles)));
}
$logoSrc = !empty($settings['logo_path']) && file_exists(__DIR__ . '/' . $settings['logo_path'])
    ? $settings['logo_path']
    : 'logo.png';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Settings'); ?>

    <!-- Bootstrap -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <!-- Font Awesome -->

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Custom CSS -->

    <link rel="stylesheet"
          href="<?php echo asset('settings.css'); ?>">

</head>

<body>

<div class="wrapper">

    <!-- =======================
            SIDEBAR
    ======================== -->

    <?php renderSidebar('settings', 'compact'); ?>

    <!-- =======================
          MAIN CONTENT
    ======================== -->

    <main class="main-content">

        <!-- Header -->

        <?php renderTopbar('Settings', 'Configure Barangay Information and System Preferences', 'compact', ['clock' => true]); ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="status"><?php echo h($success); ?></div>
        <?php endif; ?>

        <?php if ($recoveryNotice !== ''): ?>
            <div class="alert alert-warning" role="alert">
                <?php echo h($recoveryNotice); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-users"></i>

                    <h4>Total Residents</h4>

                    <h2><?php echo $totalResidents; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-user-shield"></i>

                    <h4>Administrators</h4>

                    <h2><?php echo $totalAdministrators; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-database"></i>

                    <h4>Database Backups</h4>

                    <h2><?php echo $totalBackups; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-gear"></i>

                    <h4>System Status</h4>

                    <h2>Online</h2>

                </div>

            </div>

        </div>

        <!-- =======================
              CONTENT STARTS
        ======================== -->

        <div class="row mt-4">

            <!-- LEFT COLUMN -->

            <div class="col-lg-8">

                <form action="settings.php" method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>

                    <input type="hidden" name="form_type" value="barangay">

                    <!-- Barangay Information -->

                    <div class="content-card mb-4">

                        <div class="card-header-custom">

                            <h3>

                                <i class="fa-solid fa-building"></i>

                                Barangay Information

                            </h3>

                        </div>

                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label for="barangayName" class="form-label">

                                    Barangay Name

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="barangayName"
                                    name="barangay_name"
                                    value="<?php echo h($settings['barangay_name']); ?>"
                                    placeholder="Enter Barangay Name">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="barangayCaptain" class="form-label">

                                    Barangay Captain

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="barangayCaptain"
                                    name="barangay_captain"
                                    value="<?php echo h($settings['barangay_captain']); ?>"
                                    placeholder="Enter Barangay Captain">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="municipality" class="form-label">

                                    Municipality

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="municipality"
                                    name="municipality"
                                    value="<?php echo h($settings['municipality']); ?>"
                                    placeholder="Municipality">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="province" class="form-label">

                                    Province

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="province"
                                    name="province"
                                    value="<?php echo h($settings['province']); ?>"
                                    placeholder="Province">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="zipCode" class="form-label">

                                    ZIP Code

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="zipCode"
                                    name="zip_code"
                                    value="<?php echo h($settings['zip_code']); ?>"
                                    placeholder="3520">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="contactNumber" class="form-label">

                                    Contact Number

                                </label>

                                <input
                                    type="tel"
                                    class="form-control"
                                    id="contactNumber"
                                    name="contact_number"
                                    value="<?php echo h($settings['contact_number']); ?>"
                                    placeholder="09XXXXXXXXX">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="emailAddress" class="form-label">

                                    Email Address

                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="emailAddress"
                                    name="email"
                                    value="<?php echo h($settings['email']); ?>"
                                    placeholder="barangay@email.com">

                            </div>

                            <div class="col-md-6 mb-3">

                                <label for="officeHours" class="form-label">

                                    Office Hours

                                </label>

                                <input
                                    type="text"
                                    class="form-control"
                                    id="officeHours"
                                    name="office_hours"
                                    value="<?php echo h($settings['office_hours']); ?>"
                                    placeholder="8:00 AM - 5:00 PM">

                            </div>

                            <div class="col-12 mb-3">

                                <label for="barangayAddress" class="form-label">

                                    Complete Address

                                </label>

                                <textarea
                                    id="barangayAddress"
                                    name="address"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Enter Complete Address"><?php echo h($settings['address']); ?></textarea>

                            </div>

                        </div>

                    </div>

                    <!-- Logo -->

                    <div class="content-card">

                        <div class="card-header-custom">

                            <h3>

                                <i class="fa-solid fa-image"></i>

                                Barangay Logo

                            </h3>

                        </div>

                        <div class="row align-items-center">

                            <div class="col-md-4 text-center">

                                <img src="<?php echo h($logoSrc); ?>"
                                     alt="Barangay Logo"
                                     class="img-fluid rounded shadow barangay-logo">

                            </div>

                            <div class="col-md-8">

                                <label
                                    for="barangayLogo"
                                    class="form-label">

                                    Upload New Logo

                                </label>

                                <input
                                    type="file"
                                    id="barangayLogo"
                                    name="barangay_logo"
                                    class="form-control"
                                    accept=".png,.jpg,.jpeg">

                                <div class="mt-4">

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                        aria-label="Save Barangay Information">

                                        <i class="fa-solid fa-floppy-disk"
                                           aria-hidden="true"></i>

                                        Save Changes

                                    </button>

                                    <button
                                        type="reset"
                                        class="btn btn-secondary ms-2"
                                        aria-label="Reset Barangay Information">

                                        <i class="fa-solid fa-rotate-left"
                                           aria-hidden="true"></i>

                                        Reset

                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

            <!-- RIGHT COLUMN -->

            <div class="col-lg-4">

                <!-- Administrator Account -->

                <div class="content-card mb-4">

                    <div class="card-header-custom">

                        <h3>

                            <i class="fa-solid fa-user-shield"></i>

                            Administrator Account

                        </h3>

                    </div>

                    <form action="settings.php" method="POST">
                        <?php echo csrfField(); ?>

                        <input type="hidden" name="form_type" value="admin">

                        <div class="mb-3">

                            <label for="adminName" class="form-label">

                                Full Name

                            </label>

                            <input
                                type="text"
                                id="adminName"
                                name="admin_name"
                                class="form-control"
                                value="<?php echo h($admin['first_name']); ?>"
                                placeholder="Administrator">

                        </div>

                        <div class="mb-3">

                            <label for="username" class="form-label">

                                Username

                            </label>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="form-control"
                                value="<?php echo h($admin['username']); ?>"
                                placeholder="Username">

                        </div>

                        <div class="mb-3">

                            <label for="currentPassword" class="form-label">

                                Current Password

                            </label>

                            <input
                                type="password"
                                id="currentPassword"
                                name="current_password"
                                class="form-control">

                        </div>

                        <div class="mb-3">

                            <label for="newPassword" class="form-label">

                                New Password

                            </label>

                            <input
                                type="password"
                                id="newPassword"
                                name="new_password"
                                class="form-control">

                        </div>

                        <div class="mb-3">

                            <label for="confirmPassword" class="form-label">

                                Confirm Password

                            </label>

                            <input
                                type="password"
                                id="confirmPassword"
                                name="confirm_password"
                                class="form-control">

                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            aria-label="Update Administrator Account">

                            <i class="fa-solid fa-key" aria-hidden="true"></i>

                            Update Account

                        </button>

                    </form>

                </div>

                <!-- System Preferences -->

                <div class="content-card mb-4">

                    <div class="card-header-custom">

                        <h3>

                            <i class="fa-solid fa-sliders"></i>

                            System Preferences

                        </h3>

                    </div>

                    <form action="settings.php" method="POST">
                        <?php echo csrfField(); ?>

                        <input type="hidden" name="form_type" value="preferences">

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="enable_appointments"
                                id="appointments"
                                <?php echo $settings['enable_appointments'] ? 'checked' : ''; ?>>

                            <label
                                class="form-check-label"
                                for="appointments">

                                Enable Online Appointments

                            </label>

                        </div>

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="enable_complaints"
                                id="complaints"
                                <?php echo $settings['enable_complaints'] ? 'checked' : ''; ?>>

                            <label
                                class="form-check-label"
                                for="complaints">

                                Enable Online Complaints

                            </label>

                        </div>

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="enable_notifications"
                                id="notifications"
                                <?php echo $settings['enable_notifications'] ? 'checked' : ''; ?>>

                            <label
                                class="form-check-label"
                                for="notifications">

                                Enable Email Notifications

                            </label>

                        </div>

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="allow_registration"
                                id="registration"
                                <?php echo $settings['allow_registration'] ? 'checked' : ''; ?>>

                            <label
                                class="form-check-label"
                                for="registration">

                                Allow Resident Registration

                            </label>

                        </div>

                        <button type="submit" class="btn btn-primary w-100">

                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>

                            Save Preferences

                        </button>

                    </form>

                </div>

                <!-- Backup -->

                <div class="content-card mb-4">

                    <div class="card-header-custom">

                        <h3>

                            <i class="fa-solid fa-database"></i>

                            Backup &amp; Restore

                        </h3>

                    </div>

                    <p>

                        <strong>Last Backup:</strong>

                        <?php echo h($lastBackup); ?>

                    </p>

                    <div class="d-grid gap-2">

                        <button
                            type="button"
                            class="btn btn-success js-not-implemented"
                            aria-label="Backup Database">

                            <i class="fa-solid fa-download" aria-hidden="true"></i>

                            Backup Database

                        </button>

                        <button
                            type="button"
                            class="btn btn-warning js-not-implemented"
                            aria-label="Restore Database">

                            <i class="fa-solid fa-upload" aria-hidden="true"></i>

                            Restore Database

                        </button>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
