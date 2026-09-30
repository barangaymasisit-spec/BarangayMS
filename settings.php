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

// The account card edits the signed-in admin's own account (not whichever admin was created first).
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$currentUserId = (int)$_SESSION['user_id'];
$stmt->bind_param('i', $currentUserId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    // settings.php is also open to staff; backup/restore expose and replace every record, so admins only.
    if (in_array($formType, ['backup', 'restore', 'download_saved'], true) &&($_SESSION['role'] ?? '') !== 'admin') {
        $error = 'Only administrators can back up or restore the database.';
        $formType = '';
    }

    if ($formType === 'backup') {
        // Logged before dumping so the backup file carries its own record.
        logActivity($conn, 'backup', 'database', 'Downloaded a database backup.');
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="barangayms-backup-' . date('Ymd-His') . '.sql"');
        streamDatabaseBackup($conn);
        exit;
    }

    if ($formType === 'download_saved') {
        ensureDatabaseBackupsTable($conn);
        $savedId = (int)($_POST['backup_id'] ?? 0);
        $stmt = $conn->prepare('SELECT backup_date, sql_dump FROM database_backups WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $savedId);
        $stmt->execute();
        $saved = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($saved) {
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="barangayms-auto-backup-' . str_replace('-', '', $saved['backup_date']) . '-1700.sql"');
            echo $saved['sql_dump'];
            exit;
        }
        $error = 'That saved backup no longer exists.';
    }

    if ($formType === 'restore') {
        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $currentUserId = (int)$_SESSION['user_id'];
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();
        $currentHash = (string)($stmt->get_result()->fetch_row()[0] ?? '');
        $stmt->close();

        $backupFile = $_FILES['backup_file'] ?? null;
        if (!password_verify($_POST['restore_password'] ?? '', $currentHash)) {
            $error = 'Password is incorrect. The database was not restored.';
        } elseif (!$backupFile || $backupFile['error'] !== UPLOAD_ERR_OK) {
            $error = 'Choose a backup file to restore (the upload may also be too large).';
        } else {
            $backupSql = (string)file_get_contents($backupFile['tmp_name']);
            if (!str_starts_with($backupSql, BACKUP_HEADER)) {
                $error = 'That file is not a BarangayMS backup. The database was not restored.';
            } else {
                try {
                    restoreDatabaseBackup($conn, $backupSql);
                    logActivity($conn, 'restored', 'database', 'Restored the database from ' . basename((string)$backupFile['name']) . '.');
                    $success = 'Database restored successfully.';
                } catch (Throwable $e) {
                    error_log('Database restore failed: ' . $e->getMessage());
                    $error = 'Restore failed partway: ' . $e->getMessage() . ' Restore a known good backup again.';
                }
            }
        }
    }

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

        // Optional certificate logos and seal
        $updatedImages = [];
        foreach (BRANDING_IMAGES as $imageName => [$imageLabel]) {
            $upload = $_FILES[$imageName] ?? null;
            if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $mimeType = $upload['error'] === UPLOAD_ERR_OK ? mime_content_type($upload['tmp_name']) : '';
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 2 * 1024 * 1024) {
                $error = 'The ' . $imageLabel . ' could not be uploaded. Use an image of 2 MB or less.';
            } elseif (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
                $error = 'The ' . $imageLabel . ' must be a PNG or JPG image.';
            } else {
                ensureBrandingImagesTable($conn);
                $imageData = (string)file_get_contents($upload['tmp_name']);
                $stmt = $conn->prepare('INSERT INTO branding_images (name, mime, data, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE mime = VALUES(mime), data = VALUES(data), updated_at = NOW()');
                $stmt->bind_param('sss', $imageName, $mimeType, $imageData);
                $stmt->execute();
                $stmt->close();
                $updatedImages[] = $imageLabel;
            }
        }
        if ($updatedImages) {
            logActivity($conn, 'updated', 'barangay_settings', 'Updated ' . implode(', ', $updatedImages) . '.', (int)$settings['id']);
            $success = 'Barangay information and ' . implode(', ', $updatedImages) . ' updated successfully.';
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
                rememberPasswordFingerprint($passwordHash); // stay signed in here; other sessions end
                $admin['password_hash'] = $passwordHash;
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
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$totalResidents = (int)($conn->query('SELECT COUNT(*) FROM residents')->fetch_row()[0] ?? 0);
$totalAdministrators = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0] ?? 0);
// Backups download to the admin's computer (Railway's disk resets on deploy), so they are tracked in activity_logs.
[$totalBackups, $lastBackupAt] = $conn->query("SELECT COUNT(*), MAX(created_at) FROM activity_logs WHERE action = 'backup' AND entity = 'database'")->fetch_row();
$totalBackups = (int)$totalBackups;
$lastBackup = $lastBackupAt ? formatDatabaseDateTime($lastBackupAt, 'F d, Y h:i A') : 'No backup recorded';
ensureDatabaseBackupsTable($conn);
$savedBackups = $conn->query('SELECT id, backup_date, LENGTH(sql_dump) AS size_bytes FROM database_backups ORDER BY backup_date DESC')->fetch_all(MYSQLI_ASSOC);

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

                                Certificate Logos &amp; Seal

                            </h3>

                        </div>

                        <p class="text-muted">These appear on every certificate. The left logo is also the system logo. PNG or JPG, up to 2 MB each.</p>

                        <div class="row">

                            <?php foreach (BRANDING_IMAGES as $imageName => [$imageLabel]): ?>
                            <div class="col-md-4 mb-3 text-center">

                                <label for="<?php echo h($imageName); ?>" class="form-label d-block"><?php echo h($imageLabel); ?></label>

                                <img src="<?php echo h(brandingImageUrl($imageName)); ?>"
                                     id="<?php echo h($imageName); ?>Preview"
                                     alt="<?php echo h($imageLabel); ?>"
                                     class="img-fluid rounded shadow mb-2 barangay-logo">

                                <input
                                    type="file"
                                    id="<?php echo h($imageName); ?>"
                                    name="<?php echo h($imageName); ?>"
                                    class="form-control branding-upload"
                                    data-preview="<?php echo h($imageName); ?>Preview"
                                    accept=".png,.jpg,.jpeg">

                            </div>
                            <?php endforeach; ?>

                            <div class="col-12">

                                <div class="mt-2">

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

                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>

                    <form action="settings.php" method="POST" class="d-grid mb-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="backup">
                        <button type="submit" class="btn btn-success">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            Backup Database
                        </button>
                    </form>

                    <p class="mb-1"><strong>Automatic backups</strong> <small class="text-muted">(daily at 5:00 PM, last <?php echo AUTO_BACKUPS_KEPT; ?> kept)</small></p>
                    <?php if ($savedBackups): ?>
                        <ul class="list-unstyled mb-3">
                            <?php foreach ($savedBackups as $saved): ?>
                                <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                    <span><?php echo h(date('M d, Y', strtotime($saved['backup_date']))); ?> <small class="text-muted"><?php echo h(number_format($saved['size_bytes'] / 1024, 1)); ?> KB</small></span>
                                    <form action="settings.php" method="POST" class="m-0">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="form_type" value="download_saved">
                                        <input type="hidden" name="backup_id" value="<?php echo (int)$saved['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" aria-label="Download backup from <?php echo h($saved['backup_date']); ?>">
                                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form action="settings.php" method="POST" enctype="multipart/form-data" class="d-grid gap-2"
                        onsubmit="return confirm('Restoring replaces ALL current records with the backup. Continue?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="restore">
                        <label for="backupFile" class="form-label mb-0">Backup file (.sql)</label>
                        <input type="file" id="backupFile" name="backup_file" class="form-control" accept=".sql" required>
                        <label for="restorePassword" class="form-label mb-0">Your password</label>
                        <input type="password" id="restorePassword" name="restore_password" class="form-control" autocomplete="current-password" required>
                        <small class="text-muted">Restore replaces every current record. Download a fresh backup first.</small>
                        <button type="submit" class="btn btn-warning">
                            <i class="fa-solid fa-upload" aria-hidden="true"></i>
                            Restore Database
                        </button>
                    </form>

                    <?php else: ?>

                    <p class="text-muted mb-0">Only administrators can back up or restore the database.</p>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
