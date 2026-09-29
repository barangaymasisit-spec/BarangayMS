<?php
require_once __DIR__ . '/layout.php';

$role = $_SESSION['role'] ?? '';
$resident = $role === 'resident' ? currentResident($conn) : null;
if ($role === 'resident' && !$resident) {
    header('Location: auth.php?denied=1');
    exit;
}
if (!in_array($role, ['admin', 'resident'], true)) {
    header('Location: dashboard.php?denied=1');
    exit;
}

$error = '';
$categoryOptions = [
    'Earthquake',
    'Typhoon',
    'Flood',
    'Landslide',
    'Volcanic Eruption',
    'Tsunami',
    'Storm Surge',
];
$category = '';
$description = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!in_array($category, $categoryOptions, true)) {
        $error = 'Please choose the type of natural disaster.';
    } elseif ($description === '') {
        $error = 'Please describe the emergency.';
    } else {
        $trackingNumber = nextEmergencyTrackingNumber($conn);
        $residentId = $resident ? (int)$resident['id'] : null;
        $reportedBy = $resident
            ? trim($resident['first_name'] . ' ' . $resident['last_name'])
            : 'Barangay Office';
        // The office's own alert goes straight out to residents; a resident's report waits for review.
        $status = $resident ? 'Reported' : 'Active';
        $stmt = $conn->prepare('INSERT INTO emergency_alerts (tracking_number, category, description, resident_id, reported_by, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $trackingNumber, $category, $description, $residentId, $reportedBy, $status);
        if ($stmt->execute()) {
            $alertId = (int)$stmt->insert_id;
            $stmt->close();
            logActivity($conn, 'created', 'emergency_alerts', $resident ? 'Reported an emergency.' : 'Sent an emergency alert.', $alertId);
            header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php?msg=emergency_reported' : 'emergency.php?msg=sent'));
            exit;
        }
        $error = $resident ? 'The emergency report could not be submitted.' : 'The emergency alert could not be sent.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($resident ? 'Report an Emergency' : 'Send Emergency Alert'); ?>
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar($role === 'resident' ? 'resident_emergency' : 'emergency', 'compact'); ?>
    <main class="main-content">
        <?php $resident
            ? renderTopbar('Report an Emergency', 'Report a natural disaster to the barangay office.', 'compact', ['clock' => true])
            : renderTopbar('Send Emergency Alert', 'Broadcast an alert to every resident.', 'compact', ['clock' => true]); ?>
        <div class="content-card emergency-form-card">
            <div class="emergency-form-heading">
                <div class="emergency-form-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <h3><?php echo $resident ? 'Emergency Report Details' : 'Alert Details'; ?></h3>
            </div>
            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?php echo h($error); ?></div><?php endif; ?>
            <form method="post" class="emergency-form">
                <?php echo csrfField(); ?>
                <div class="emergency-form-field">
                    <label for="category">Natural Disaster <span>*</span></label>
                    <select id="category" name="category" required>
                        <option value="">Select disaster type</option>
                        <?php foreach ($categoryOptions as $option): ?>
                            <option value="<?php echo h($option); ?>" <?php echo $category === $option ? 'selected' : ''; ?>><?php echo h($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="emergency-form-field">
                    <label for="description">Describe the emergency <span>*</span></label>
                    <textarea id="description" name="description" rows="7" required placeholder="Describe what happened, where it happened, and the assistance needed."><?php echo h($description); ?></textarea>
                </div>
                <div class="emergency-form-actions">
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-<?php echo $resident ? 'paper-plane' : 'bullhorn'; ?>" aria-hidden="true"></i> <?php echo $resident ? 'Send Emergency Report' : 'Send Alert to Residents'; ?></button>
                    <a href="<?php echo $role === 'resident' ? 'resident_dashboard.php' : 'emergency.php'; ?>" class="btn btn-link"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel</a>
                </div>
            </form>
        </div>
    </main>
</div>
<?php renderFooterScripts(false); ?>
</body>
</html>