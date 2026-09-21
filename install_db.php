<?php

$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$port = (int)(getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);
$user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
$dbName = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'dbbarangaymanagement';

$connection = new mysqli($host, $user, $pass, '', $port);
if ($connection->connect_error) {
    die('Connection failed: ' . $connection->connect_error);
}
if (!$connection->select_db($dbName)) {
    die('Could not select configured database: ' . $connection->error);
}

$sql = file_get_contents(__DIR__ . '/install_db.sql');
if ($sql === false) {
    die('Could not read install_db.sql');
}

// The hosted database is provisioned by Railway, so use its configured database
// instead of attempting to create or switch to the local XAMPP database name.
$sql = preg_replace('/^\s*CREATE DATABASE.*?;\s*USE `[^`]+`;\s*/is', '', $sql, 1);
if ($sql === null) {
    die('Could not prepare database schema');
}

if (!$connection->multi_query($sql)) {
    die('Failed to create database or tables: ' . $connection->error);
}

while ($connection->more_results() && $connection->next_result()) {
    // flush multi query results
}

$adminUsername = 'admin';
$adminEmail = 'admin@example.com';
$adminPassword = bin2hex(random_bytes(12));
$adminPasswordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
$adminCreated = false;

$checkAdmin = $connection->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$checkAdmin->bind_param('s', $adminUsername);
$checkAdmin->execute();
$checkAdmin->store_result();

if ($checkAdmin->num_rows === 0) {
    $insertAdmin = $connection->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $role = 'admin';
    $status = 'Active';
    $firstName = 'Admin';
    $lastName = 'User';
    $insertAdmin->bind_param('sssssss', $firstName, $lastName, $adminUsername, $adminEmail, $adminPasswordHash, $role, $status);
    $adminCreated = $insertAdmin->execute();
    $insertAdmin->close();
}
$checkAdmin->close();

$settingsExists = $connection->query('SELECT id FROM barangay_settings LIMIT 1');
if ($settingsExists && $settingsExists->num_rows === 0) {
    $connection->query(
        "INSERT INTO barangay_settings (barangay_name, barangay_captain, municipality, province, zip_code, contact_number, email, office_hours, address, enable_appointments, enable_complaints, enable_notifications, allow_registration)
         VALUES ('Barangay Example', 'Barangay Captain', 'Municipality', 'Province', '0000', '09171234567', 'barangay@example.com', '8:00 AM - 5:00 PM', 'Example Address', 1, 1, 1, 1)"
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Management System Setup</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; color: #222; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .container { background: #fff; border-radius: 12px; box-shadow: 0 16px 40px rgba(0,0,0,0.08); padding: 32px; max-width: 560px; width: 100%; }
        h1 { margin-top: 0; }
        p { line-height: 1.6; }
        a.button { display: inline-block; margin-top: 20px; padding: 12px 22px; background: #0d6efd; color: #fff; border-radius: 8px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Setup Complete</h1>
        <p>The database <strong>dbbarangaymanagement</strong> and the required tables have been created successfully.</p>
        <?php if ($adminCreated): ?>
            <p>Administrator account created for this installation:</p>
            <ul>
                <li><strong>Username:</strong> <?php echo htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8'); ?></li>
                <li><strong>Temporary password:</strong> <?php echo htmlspecialchars($adminPassword, ENT_QUOTES, 'UTF-8'); ?></li>
            </ul>
            <p>Change this password immediately after signing in, then remove or protect <code>install_db.php</code>.</p>
        <?php else: ?>
            <p>The database already has an administrator account. Use its existing credentials.</p>
        <?php endif; ?>
        <p>You can now copy this folder into XAMPP's <code>htdocs</code> directory and open the app in your browser.</p>
        <a class="button" href="index.php">Go to App</a>
    </div>
</body>
</html>
