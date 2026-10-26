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

function recentEmergencyReports(mysqli $conn, int $residentId): int {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM emergency_alerts WHERE resident_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR');
    $stmt->bind_param('i', $residentId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
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
$severityOptions = ['Critical', 'High', 'Moderate', 'Advisory'];
$purokResult = $conn->query("SELECT DISTINCT TRIM(purok) AS purok FROM residents WHERE purok IS NOT NULL AND TRIM(purok) <> '' ORDER BY purok");
$areaOptions = $purokResult ? array_column($purokResult->fetch_all(MYSQLI_ASSOC), 'purok') : [];
$incidentAreas = array_values(array_unique(array_merge(['Entire Barangay'], $areaOptions)));
$category = '';
$description = '';
$location = '';
$affectedArea = '';
$otherAffectedArea = '';
$severity = '';
$incidentDateTime = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d\TH:i');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $affectedArea = trim($_POST['affected_area'] ?? '');
    $selectedArea = $affectedArea;
    $otherAffectedArea = trim($_POST['other_affected_area'] ?? '');
    $incidentDateTime = trim($_POST['incident_at'] ?? '');
    $severity = trim($_POST['severity'] ?? '');
    $resolvedArea = $selectedArea === 'Other' ? $otherAffectedArea : $selectedArea;
    $incidentAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $incidentDateTime, new DateTimeZone('Asia/Manila'));
    $validArea = $selectedArea === 'Other'
        ? $otherAffectedArea !== '' && strlen($otherAffectedArea) <= 150
        : in_array($selectedArea, $incidentAreas, true);
    if (!in_array($category, $categoryOptions, true)) {
        $error = 'Please choose the type of natural disaster.';
    } elseif ($description === '') {
        $error = 'Please describe the emergency.';
    } elseif ($location === '' || strlen($location) > 255) {
        $error = 'Enter the emergency location (up to 255 characters).';
    } elseif (!$validArea) {
        $error = 'Choose an affected purok or enter the affected area.';
    } elseif (strlen($description) > 60000) {
        $error = 'The emergency description must be 60,000 characters or fewer.';
    } elseif (!$incidentAt || $incidentAt->format('Y-m-d\TH:i') !== $incidentDateTime) {
        $error = 'Enter a valid incident date and time.';
    } elseif (!$resident && !in_array($severity, $severityOptions, true)) {
        $error = 'Choose an emergency severity level.';
    } elseif ($resident && recentEmergencyReports($conn, (int)$resident['id']) >= 3) {
        // Keeps one resident from flooding the office; real emergencies rarely need more than 3 reports an hour.
        $error = 'You have sent 3 emergency reports in the last hour. Please call the barangay office if you need more help.';
    } else {
        $trackingNumber = nextEmergencyTrackingNumber($conn);
        $residentId = $resident ? (int)$resident['id'] : null;
        $reportedBy = $resident
            ? trim($resident['first_name'] . ' ' . $resident['last_name'])
            : 'Barangay Office';
        $incidentStatus = $resident ? 'Reported' : 'Verified';
        $broadcastStatus = $resident ? 'Not Sent' : 'Draft';
        $legacyStatus = $incidentStatus;
        $severity = $resident ? 'Unassessed' : $severity;
        $incidentAtUtc = $incidentAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare(
            'INSERT INTO emergency_alerts
                (tracking_number, category, description, resident_id, reported_by, status, incident_status, broadcast_status, severity, location, affected_area, incident_at, verified_by_user_id, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 1 THEN UTC_TIMESTAMP() ELSE NULL END)'
        );
        $isOfficeAlert = $resident ? 0 : 1;
        $verifiedById = $isOfficeAlert ? $userId : null;
        $stmt->bind_param('sssissssssssii', $trackingNumber, $category, $description, $residentId, $reportedBy, $legacyStatus, $incidentStatus, $broadcastStatus, $severity, $location, $resolvedArea, $incidentAtUtc, $verifiedById, $isOfficeAlert);
        if ($stmt->execute()) {
            $alertId = (int)$stmt->insert_id;
            $stmt->close();
            logActivity($conn, 'created', 'emergency_alerts', $resident
                ? 'Emergency report submitted for review.'
                : 'Official emergency alert prepared as a draft. No resident notification was sent yet.', $alertId);
            header('Location: ' . ($role === 'resident' ? 'resident_dashboard.php?msg=emergency_reported' : 'emergency.php?view=reports&msg=draft'));
            exit;
        }
        $error = $resident ? 'The emergency report could not be submitted.' : 'The official emergency alert draft could not be saved.';
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
            ? renderTopbar('Report an Emergency', 'Report an incident for authorized personnel to verify.', 'compact', ['clock' => true])
            : renderTopbar('Prepare Official Alert', 'Create a verified draft. Residents are notified only after broadcast confirmation.', 'compact', ['clock' => true]); ?>
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
                <?php if (!$resident): ?>
                <div class="emergency-form-field">
                    <label for="severity">Severity Level <span>*</span></label>
                    <select id="severity" name="severity" required>
                        <option value="">Assess severity</option>
                        <?php foreach ($severityOptions as $option): ?>
                            <option value="<?php echo h($option); ?>"<?php echo $severity === $option ? ' selected' : ''; ?>><?php echo h($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="emergency-form-field">
                    <label for="location">Street / Landmark <span>*</span></label>
                    <input type="text" id="location" name="location" maxlength="255" required value="<?php echo h($location); ?>" placeholder="e.g. near the bridge or a street landmark">
                </div>
                <div class="emergency-form-field">
                    <label for="affected_area">Affected Area <span>*</span></label>
                    <select id="affected_area" name="affected_area" required>
                        <option value="">Select affected area</option>
                        <?php foreach ($incidentAreas as $area): ?>
                            <option value="<?php echo h($area); ?>"<?php echo $affectedArea === $area ? ' selected' : ''; ?>><?php echo h($area); ?></option>
                        <?php endforeach; ?>
                        <option value="Other"<?php echo $otherAffectedArea !== '' ? ' selected' : ''; ?>>Other area</option>
                    </select>
                </div>
                <div class="emergency-form-field" id="otherAffectedAreaField"<?php echo $otherAffectedArea === '' ? ' hidden' : ''; ?>>
                    <label for="other_affected_area">Specify Affected Area <span>*</span></label>
                    <input type="text" id="other_affected_area" name="other_affected_area" maxlength="150" value="<?php echo h($otherAffectedArea); ?>" placeholder="Enter purok or affected location">
                </div>
                <div class="emergency-form-field">
                    <label for="incident_at">Incident Date &amp; Time <span>*</span></label>
                    <input type="datetime-local" id="incident_at" name="incident_at" required value="<?php echo h($incidentDateTime); ?>">
                </div>
                <div class="emergency-form-field emergency-form-field-wide">
                    <label for="description">Describe the emergency <span>*</span></label>
                    <textarea id="description" name="description" rows="7" required placeholder="Describe what happened and any assistance needed."><?php echo h($description); ?></textarea>
                </div>
                <div class="emergency-form-actions">
                    <button type="submit" class="btn btn-danger"><i class="fa-solid fa-<?php echo $resident ? 'paper-plane' : 'file-circle-check'; ?>" aria-hidden="true"></i> <?php echo $resident ? 'Submit Emergency Report' : 'Prepare Alert Draft'; ?></button>
                    <a href="<?php echo $role === 'resident' ? 'resident_dashboard.php' : 'emergency.php'; ?>" class="btn btn-link"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel</a>
                </div>
            </form>
        </div>
    </main>
</div>
<?php renderFooterScripts(false); ?>
<script>
    const affectedArea = document.getElementById('affected_area');
    const otherAreaField = document.getElementById('otherAffectedAreaField');
    const otherAreaInput = document.getElementById('other_affected_area');
    if (affectedArea && otherAreaField && otherAreaInput) {
        const toggleOtherArea = () => {
            const showOther = affectedArea.value === 'Other';
            otherAreaField.hidden = !showOther;
            otherAreaInput.required = showOther;
        };
        affectedArea.addEventListener('change', toggleOtherArea);
        toggleOtherArea();
    }
</script>
</body>
</html>