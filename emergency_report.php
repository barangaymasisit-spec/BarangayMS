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
        $year = date('Y');
        $count = (int)($conn->query('SELECT COUNT(*) FROM complaints')->fetch_row()[0] ?? 0) + 1;
        $trackingNumber = 'EMG-' . $year . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
        $residentId = $resident ? (int)$resident['id'] : null;
        $residentName = $resident
            ? trim($resident['first_name'] . ' ' . $resident['last_name'])
            : 'Barangay Office';
        $status = 'Ongoing';
        $dateFiled = date('Y-m-d');
        $stmt = $conn->prepare('INSERT INTO complaints (tracking_number, resident_id, resident_name, category, status, date_filed, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sisssss', $trackingNumber, $residentId, $residentName, $category, $status, $dateFiled, $description);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php' : 'emergency.php?msg=created'));
            exit;
        }
        $error = 'The emergency report could not be submitted.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('New Emergency Report'); ?>
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar($role === 'resident' ? 'resident_emergency' : 'emergency', 'compact'); ?>
    <main class="main-content">
        <?php renderTopbar('New Emergency Report', 'Report a natural disaster to the barangay office.', 'compact', ['clock' => true]); ?>
        <div class="content-card emergency-form-card">
            <div class="emergency-form-heading">
                <div class="emergency-form-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <h3>Emergency Report Details</h3>
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
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send Emergency Report</button>
                    <a href="<?php echo $role === 'resident' ? 'resident_dashboard.php' : 'emergency.php'; ?>" class="btn btn-link"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel</a>
                </div>
            </form>
        </div>
    </main>
</div>
<?php renderFooterScripts(false); ?>
</body>
</html>