<?php
require_once __DIR__ . '/layout.php';

$role = (string)($_SESSION['role'] ?? '');
$isAdmin = $role === 'admin';
if (!in_array($role, ['admin', 'security_force'], true)) {
    header('Location: dashboard.php?denied=1');
    exit;
}

$view = in_array($_GET['view'] ?? 'reports', ['reports', 'broadcasts', 'history', 'archived'], true)
    ? (string)($_GET['view'] ?? 'reports')
    : 'reports';
$search = trim((string)($_GET['search'] ?? ''));
$severityFilter = trim((string)($_GET['severity'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$severityOptions = ['Critical', 'High', 'Moderate', 'Advisory', 'Unassessed'];
$incidentStatuses = ['Reported', 'Verified', 'Responding', 'Resolved', 'Dismissed', 'Referred'];
$allowedAreas = ['Entire Barangay'];
$areaResult = $conn->query("SELECT DISTINCT TRIM(purok) AS purok FROM residents WHERE purok IS NOT NULL AND TRIM(purok) <> '' ORDER BY purok");
if ($areaResult) {
    $allowedAreas = array_values(array_unique(array_merge($allowedAreas, array_column($areaResult->fetch_all(MYSQLI_ASSOC), 'purok'))));
}

$rejectAction = static function (string $message): never {
    $_SESSION['emergency_action_error'] = $message;
    header('Location: emergency.php?view=' . rawurlencode((string)($_POST['return_view'] ?? 'reports')) . '&msg=action_error', true, 303);
    exit;
};

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $alertId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$alertId || $alertId <= 0) {
        $rejectAction('The emergency record could not be found.');
    }

    $stmt = $conn->prepare('SELECT id, status, incident_status, broadcast_status, severity, location, affected_area, category, archived_at FROM emergency_alerts WHERE id = ?');
    if (!$stmt) {
        $rejectAction('Could not open the emergency record.');
    }
    $stmt->bind_param('i', $alertId);
    $stmt->execute();
    $alert = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$alert) {
        $rejectAction('The emergency record no longer exists.');
    }
    if (!empty($alert['archived_at']) && $action !== 'restore') {
        $rejectAction('Restore the emergency record before making changes.');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $incidentStatus = (string)$alert['incident_status'];
    $broadcastStatus = (string)$alert['broadcast_status'];
    $update = null;
    $activity = '';

    if ($action === 'verify') {
        if ($incidentStatus !== 'Reported') {
            $rejectAction('Only reports awaiting review can be verified.');
        }
        $severity = trim((string)($_POST['severity'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $selectedArea = trim((string)($_POST['affected_area'] ?? ''));
        $area = $selectedArea;
        $otherArea = trim((string)($_POST['other_affected_area'] ?? ''));
        $incidentInput = trim((string)($_POST['incident_at'] ?? ''));
        if ($selectedArea === 'Other') {
            $area = $otherArea;
        }
        $incidentAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $incidentInput, new DateTimeZone('Asia/Manila'));
        if (!in_array($severity, ['Critical', 'High', 'Moderate', 'Advisory'], true)
            || $location === '' || strlen($location) > 255
            || $area === '' || strlen($area) > 150
            || ($selectedArea === 'Other' ? $otherArea === '' : !in_array($selectedArea, $allowedAreas, true))
            || !$incidentAt || $incidentAt->format('Y-m-d\TH:i') !== $incidentInput) {
            $rejectAction('Complete the severity, incident location, affected area, and valid incident date/time before verifying.');
        }
        $incidentUtc = $incidentAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE emergency_alerts SET status = 'Verified', incident_status = 'Verified', broadcast_status = 'Draft', severity = ?, location = ?, affected_area = ?, incident_at = ?, verified_by_user_id = ?, verified_at = UTC_TIMESTAMP() WHERE id = ? AND incident_status = 'Reported' AND archived_at IS NULL");
        $stmt->bind_param('ssssii', $severity, $location, $area, $incidentUtc, $userId, $alertId);
        $update = 'verified';
        $activity = 'Emergency report verified. Severity: ' . $severity . '; location: ' . $location . '; affected area: ' . $area . '.';
    } elseif ($action === 'respond') {
        if ($incidentStatus !== 'Verified') {
            $rejectAction('Verify the emergency report before marking it as responding.');
        }
        $stmt = $conn->prepare("UPDATE emergency_alerts SET status = 'Responding', incident_status = 'Responding' WHERE id = ? AND incident_status = 'Verified' AND archived_at IS NULL");
        $stmt->bind_param('i', $alertId);
        $update = 'responding';
        $activity = 'Emergency response started.';
    } elseif ($action === 'broadcast') {
        if (!in_array($incidentStatus, ['Verified', 'Responding'], true) || !in_array($broadcastStatus, ['Draft', 'Not Sent'], true)) {
            $rejectAction('Only a verified or responding incident with a prepared draft can be broadcast.');
        }
        if (!in_array($alert['severity'], ['Critical', 'High', 'Moderate', 'Advisory'], true)
            || trim((string)$alert['location']) === '' || trim((string)$alert['affected_area']) === '') {
            $rejectAction('Set the severity, location, and affected area before broadcasting.');
        }
        $area = (string)$alert['affected_area'];
        $recipientStmt = $conn->prepare("SELECT COUNT(*) FROM residents WHERE ? = 'Entire Barangay' OR TRIM(COALESCE(purok, '')) = ?");
        $recipientStmt->bind_param('ss', $area, $area);
        $recipientStmt->execute();
        $recipientCount = (int)$recipientStmt->get_result()->fetch_row()[0];
        $recipientStmt->close();
        if ($recipientCount === 0) {
            $rejectAction('No resident records match this affected area. Correct the area before broadcasting.');
        }
        $message = strtoupper((string)$alert['category']) . ' — ' . strtoupper((string)$alert['severity']) . ' ALERT. '
            . 'An emergency incident has been reported at ' . (string)$alert['location'] . ' (' . $area . '). '
            . 'Follow instructions from authorized emergency personnel and monitor official barangay updates.';
        $stmt = $conn->prepare("UPDATE emergency_alerts SET broadcast_status = 'Sent', broadcast_message = ?, broadcasted_by_user_id = ?, broadcasted_at = UTC_TIMESTAMP() WHERE id = ? AND incident_status IN ('Verified', 'Responding') AND broadcast_status IN ('Draft', 'Not Sent') AND archived_at IS NULL");
        $stmt->bind_param('sii', $message, $userId, $alertId);
        $update = 'broadcast';
        $activity = 'Official alert published in the resident dashboard for ' . $area . ' at ' . $alert['severity'] . ' severity; ' . $recipientCount . ' resident record(s) are in the affected area.';
    } elseif ($action === 'resolve') {
        if (!in_array($incidentStatus, ['Verified', 'Responding'], true)) {
            $rejectAction('Only a verified or responding incident can be resolved.');
        }
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks === '' || strlen($remarks) > 2000) {
            $rejectAction('Enter resolution remarks (up to 2,000 characters) before resolving this emergency.');
        }
        $stmt = $conn->prepare("UPDATE emergency_alerts SET status = 'Resolved', incident_status = 'Resolved', broadcast_status = IF(broadcast_status = 'Sent', 'Expired', broadcast_status), resolution_remarks = ?, resolved_by_user_id = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND incident_status IN ('Verified', 'Responding') AND archived_at IS NULL");
        $stmt->bind_param('sii', $remarks, $userId, $alertId);
        $update = 'resolved';
        $activity = 'Emergency incident resolved. Remarks: ' . $remarks;
    } elseif (in_array($action, ['dismiss', 'refer'], true)) {
        if ($action === 'dismiss' && $incidentStatus !== 'Reported') {
            $rejectAction('Only unverified reports may be dismissed.');
        }
        if ($action === 'refer' && !in_array($incidentStatus, ['Verified', 'Responding'], true)) {
            $rejectAction('Verify the report before referring it to another response agency.');
        }
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks === '' || strlen($remarks) > 2000) {
            $rejectAction('Add a reason or referral details (up to 2,000 characters).');
        }
        $targetStatus = $action === 'dismiss' ? 'Dismissed' : 'Referred';
        $incidentGuard = $action === 'dismiss'
            ? "incident_status = 'Reported'"
            : "incident_status IN ('Verified', 'Responding')";
        $stmt = $conn->prepare('UPDATE emergency_alerts SET status = ?, incident_status = ?, broadcast_status = IF(broadcast_status = \'Sent\', \'Expired\', broadcast_status), resolution_remarks = ?, resolved_by_user_id = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND ' . $incidentGuard . ' AND archived_at IS NULL');
        $stmt->bind_param('sssii', $targetStatus, $targetStatus, $remarks, $userId, $alertId);
        $update = $action === 'dismiss' ? 'dismissed' : 'referred';
        $activity = $action === 'dismiss' ? 'Emergency report dismissed. Reason: ' . $remarks : 'Emergency report referred. Details: ' . $remarks;
    } elseif ($action === 'expire_broadcast') {
        if ($broadcastStatus !== 'Sent') {
            $rejectAction('Only a published broadcast can be expired.');
        }
        $stmt = $conn->prepare("UPDATE emergency_alerts SET broadcast_status = 'Expired' WHERE id = ? AND broadcast_status = 'Sent' AND archived_at IS NULL");
        $stmt->bind_param('i', $alertId);
        $update = 'expired';
        $activity = 'Official broadcast expired and removed from resident dashboards.';
    } elseif ($action === 'archive') {
        if (!in_array($incidentStatus, ['Resolved', 'Dismissed', 'Referred'], true) || $broadcastStatus === 'Sent') {
            $rejectAction('Only closed incidents with no active broadcast can be archived.');
        }
        $stmt = $conn->prepare("UPDATE emergency_alerts SET archived_at = UTC_TIMESTAMP(), archived_by_user_id = ? WHERE id = ? AND incident_status IN ('Resolved', 'Dismissed', 'Referred') AND broadcast_status <> 'Sent' AND archived_at IS NULL");
        $stmt->bind_param('ii', $userId, $alertId);
        $update = 'archived';
        $activity = 'Emergency report archived.';
    } elseif ($action === 'restore') {
        if (empty($alert['archived_at'])) {
            $rejectAction('This emergency record is already active.');
        }
        $stmt = $conn->prepare('UPDATE emergency_alerts SET archived_at = NULL, archived_by_user_id = NULL WHERE id = ? AND archived_at IS NOT NULL');
        $stmt->bind_param('i', $alertId);
        $update = 'restored';
        $activity = 'Emergency report restored.';
    } else {
        $rejectAction('Unknown emergency action.');
    }

    if (!$stmt) {
        $rejectAction('Could not prepare the emergency record update.');
    }
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) {
        $rejectAction('The emergency record changed or could not be updated. Refresh and try again.');
    }
    logActivity($conn, $update, 'emergency_alerts', $activity, $alertId);
    $msg = $update === 'broadcast' ? 'sent' : $update;
    header('Location: emergency.php?view=' . rawurlencode((string)($_POST['return_view'] ?? 'reports')) . '&msg=' . rawurlencode($msg), true, 303);
    exit;
}

$viewWhere = match ($view) {
    'broadcasts' => "e.archived_at IS NULL AND e.broadcast_status IN ('Draft', 'Sent')",
    'history' => "e.archived_at IS NULL AND (e.incident_status IN ('Resolved', 'Dismissed', 'Referred') OR e.broadcast_status = 'Expired')",
    'archived' => 'e.archived_at IS NOT NULL',
    default => "e.archived_at IS NULL AND e.incident_status IN ('Reported', 'Verified', 'Responding')",
};
$where = [$viewWhere];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = '(e.tracking_number LIKE ? OR e.category LIKE ? OR e.reported_by LIKE ? OR e.location LIKE ? OR e.affected_area LIKE ? OR e.description LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
    $types .= 'ssssss';
}
if ($severityFilter !== '' && in_array($severityFilter, $severityOptions, true)) {
    $where[] = 'e.severity = ?';
    $params[] = $severityFilter;
    $types .= 's';
}
if ($statusFilter !== '' && in_array($statusFilter, $incidentStatuses, true)) {
    $where[] = 'e.incident_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$listSql = 'SELECT e.*, COALESCE(NULLIF(e.reported_by, \'\'), \'Barangay Office\') AS reporter_name,
                   CASE WHEN verified_user.id IS NULL THEN \'Not recorded\' ELSE CONCAT(verified_user.first_name, \' \', verified_user.last_name) END AS verified_by_name,
                   CASE WHEN broadcast_user.id IS NULL THEN \'Not recorded\' ELSE CONCAT(broadcast_user.first_name, \' \', broadcast_user.last_name) END AS broadcasted_by_name,
                   CASE WHEN resolved_user.id IS NULL THEN \'Not recorded\' ELSE CONCAT(resolved_user.first_name, \' \', resolved_user.last_name) END AS resolved_by_name,
                   CASE WHEN archive_user.id IS NULL THEN \'Not recorded\' ELSE CONCAT(archive_user.first_name, \' \', archive_user.last_name) END AS archived_by_name
            FROM emergency_alerts e
            LEFT JOIN users verified_user ON verified_user.id = e.verified_by_user_id
            LEFT JOIN users broadcast_user ON broadcast_user.id = e.broadcasted_by_user_id
            LEFT JOIN users resolved_user ON resolved_user.id = e.resolved_by_user_id
            LEFT JOIN users archive_user ON archive_user.id = e.archived_by_user_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(e.incident_at, e.created_at) DESC, e.id DESC';
$stmt = $conn->prepare($listSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$alerts = $stmt->get_result();
$stmt->close();

$counts = [];
foreach (['Reported', 'Verified', 'Responding', 'Resolved'] as $status) {
    $countStmt = $conn->prepare('SELECT COUNT(*) FROM emergency_alerts WHERE incident_status = ? AND archived_at IS NULL');
    $countStmt->bind_param('s', $status);
    $countStmt->execute();
    $counts[$status] = (int)$countStmt->get_result()->fetch_row()[0];
    $countStmt->close();
}
$todayStartUtc = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$todayStmt = $conn->prepare('SELECT COUNT(*) FROM emergency_alerts WHERE created_at >= ?');
$todayStmt->bind_param('s', $todayStartUtc);
$todayStmt->execute();
$todayAlerts = (int)$todayStmt->get_result()->fetch_row()[0];
$todayStmt->close();
$activeBroadcasts = $conn->query("SELECT id, tracking_number, category, severity, affected_area, location, broadcasted_at FROM emergency_alerts WHERE broadcast_status = 'Sent' AND archived_at IS NULL AND incident_status IN ('Verified', 'Responding') ORDER BY broadcasted_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$previousBroadcasts = $conn->query("SELECT id, tracking_number, category, severity, affected_area, location, broadcast_status, broadcasted_at FROM emergency_alerts WHERE broadcast_status IN ('Expired', 'Sent') AND (archived_at IS NOT NULL OR incident_status IN ('Resolved', 'Dismissed', 'Referred') OR broadcast_status = 'Expired') ORDER BY broadcasted_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$message = (string)($_SESSION['emergency_action_error'] ?? '');
unset($_SESSION['emergency_action_error']);
$statusClasses = ['Reported' => 'reported', 'Verified' => 'verified', 'Responding' => 'responding', 'Resolved' => 'resolved', 'Dismissed' => 'dismissed', 'Referred' => 'referred'];
$severityClasses = ['Critical' => 'critical', 'High' => 'high', 'Moderate' => 'moderate', 'Advisory' => 'advisory', 'Unassessed' => 'unassessed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Emergency Management'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('emergency.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('emergency', 'compact'); ?>
    <main class="main-content">
        <?php renderTopbar('Emergency Alerts', 'Separate incident reports from official broadcasts.', 'compact', ['clock' => true, 'dashboardHeader' => true]); ?>
        <?php renderNotice([
            'draft' => 'Official alert draft prepared. No resident notification was sent.',
            'verified' => 'Emergency report verified and ready for response or broadcast.',
            'responding' => 'Incident marked as responding.',
            'sent' => 'Official alert published in resident dashboards for the selected affected area.',
            'resolved' => 'Emergency incident resolved and any active broadcast expired.',
            'dismissed' => 'Unverified report dismissed.',
            'referred' => 'Emergency report referred to another response agency.',
            'expired' => 'Broadcast expired and removed from resident dashboards.',
            'archived' => 'Emergency record archived.',
            'restored' => 'Emergency record restored.',
            'action_error' => $message !== '' ? $message : 'The emergency action could not be completed.',
        ]); ?>
        <?php if ($message !== '' && (($_GET['msg'] ?? '') !== 'action_error')): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($message); ?></div>
        <?php endif; ?>

        <section class="emergency-stat-grid" aria-label="Emergency incident summary">
            <article class="emergency-stat emergency-stat-open"><span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Unresolved Incidents</span><strong><?php echo $counts['Reported'] + $counts['Verified'] + $counts['Responding']; ?></strong></article>
            <article class="emergency-stat emergency-stat-review"><span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> For Review</span><strong><?php echo $counts['Reported']; ?></strong></article>
            <article class="emergency-stat emergency-stat-response"><span><i class="fa-solid fa-truck-medical" aria-hidden="true"></i> Responding</span><strong><?php echo $counts['Responding']; ?></strong></article>
            <article class="emergency-stat emergency-stat-resolved"><span><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Resolved</span><strong><?php echo $counts['Resolved']; ?></strong></article>
            <article class="emergency-stat emergency-stat-today"><span><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> Reported Today</span><strong><?php echo $todayAlerts; ?></strong></article>
        </section>

        <div class="row g-4 mt-1">
            <section class="col-xl-8">
                <div class="content-card emergency-management-card">
                    <header class="emergency-section-heading">
                        <div><span class="emergency-eyebrow">INCIDENT CONTROL</span><h2>Emergency Management</h2></div>
                        <?php if ($isAdmin): ?><a href="emergency_report.php" class="btn btn-danger"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Prepare Official Alert</a><?php endif; ?>
                    </header>
                    <nav class="emergency-tabs" aria-label="Emergency records">
                        <?php
                        $tabs = [
                            'reports' => ['Emergency Reports', $counts['Reported'] + $counts['Verified'] + $counts['Responding']],
                            'broadcasts' => ['Official Alerts', (int)$conn->query("SELECT COUNT(*) FROM emergency_alerts WHERE broadcast_status IN ('Draft', 'Sent') AND archived_at IS NULL")->fetch_row()[0]],
                            'history' => ['Alert History', (int)$conn->query("SELECT COUNT(*) FROM emergency_alerts WHERE archived_at IS NULL AND (incident_status IN ('Resolved', 'Dismissed', 'Referred') OR broadcast_status = 'Expired')")->fetch_row()[0]],
                            'archived' => ['Archived', (int)$conn->query("SELECT COUNT(*) FROM emergency_alerts WHERE archived_at IS NOT NULL")->fetch_row()[0]],
                        ];
                        foreach ($tabs as $tabKey => [$tabLabel, $tabCount]):
                        ?>
                            <a href="emergency.php?<?php echo h(http_build_query(['view' => $tabKey])); ?>" class="emergency-tab<?php echo $view === $tabKey ? ' is-active' : ''; ?>"<?php echo $view === $tabKey ? ' aria-current="page"' : ''; ?>>
                                <?php echo h($tabLabel); ?><span><?php echo (int)$tabCount; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <form class="emergency-filters" method="get" action="emergency.php">
                        <input type="hidden" name="view" value="<?php echo h($view); ?>">
                        <label class="visually-hidden" for="emergencySearch">Search emergency records</label>
                        <input class="form-control" id="emergencySearch" name="search" type="search" value="<?php echo h($search); ?>" placeholder="Search tracking no., emergency, location...">
                        <label class="visually-hidden" for="emergencySeverity">Filter by severity</label>
                        <select class="form-select" id="emergencySeverity" name="severity">
                            <option value="">All Severity</option>
                            <?php foreach ($severityOptions as $option): ?><option value="<?php echo h($option); ?>"<?php echo $severityFilter === $option ? ' selected' : ''; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                        </select>
                        <label class="visually-hidden" for="emergencyStatus">Filter by incident status</label>
                        <select class="form-select" id="emergencyStatus" name="status">
                            <option value="">All Incident Status</option>
                            <?php foreach ($incidentStatuses as $option): ?><option value="<?php echo h($option); ?>"<?php echo $statusFilter === $option ? ' selected' : ''; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
                    </form>

                    <div class="table-responsive emergency-table-wrap">
                        <table class="table align-middle emergency-table">
                            <thead><tr><th>Tracking / Reporter</th><th>Incident / Location</th><th>Severity</th><th>Incident Status</th><th>Broadcast</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if ($alerts->num_rows === 0): ?>
                                <tr><td colspan="6" class="emergency-empty">No records found in this view.</td></tr>
                            <?php else: ?>
                                <?php while ($alert = $alerts->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo h($alert['tracking_number']); ?></strong><small><?php echo h($alert['reported_by']); ?> · <?php echo h(formatDatabaseDateTime($alert['created_at'])); ?></small></td>
                                        <td><strong><?php echo h($alert['category']); ?></strong><small><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo h(trim(($alert['affected_area'] ?? '') . ' · ' . ($alert['location'] ?? ''), ' ·')); ?></small></td>
                                        <td><span class="emergency-badge severity-<?php echo h($severityClasses[$alert['severity']] ?? 'unassessed'); ?>"><?php echo h($alert['severity']); ?></span></td>
                                        <td><span class="emergency-badge incident-<?php echo h($statusClasses[$alert['incident_status']] ?? 'reported'); ?>"><?php echo h($alert['incident_status']); ?></span></td>
                                        <td><span class="emergency-badge broadcast-<?php echo h(strtolower(str_replace(' ', '-', $alert['broadcast_status']))); ?>"><?php echo h($alert['broadcast_status']); ?></span></td>
                                        <td>
                                            <div class="emergency-row-actions">
                                                <a class="emergency-action" href="record_view.php?type=emergency&amp;id=<?php echo (int)$alert['id']; ?>" aria-label="View emergency timeline"><i class="fa-regular fa-eye" aria-hidden="true"></i> View</a>
                                                <?php if ($view === 'archived' && $isAdmin): ?>
                                                    <form method="post"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int)$alert['id']; ?>"><input type="hidden" name="return_view" value="archived"><button type="submit" name="action" value="restore" class="emergency-action is-restore"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button></form>
                                                <?php elseif ($isAdmin && $view !== 'archived'): ?>
                                                    <?php if ($alert['incident_status'] === 'Reported'): ?>
                                                        <button type="button" class="emergency-action is-verify" data-open-verify data-id="<?php echo (int)$alert['id']; ?>" data-category="<?php echo h($alert['category']); ?>" data-location="<?php echo h($alert['location']); ?>" data-area="<?php echo h($alert['affected_area']); ?>" data-incident-at="<?php echo h($alert['incident_at']); ?>"><i class="fa-solid fa-shield-check" aria-hidden="true"></i> Verify</button>
                                                        <button type="button" class="emergency-action is-dismiss" data-open-reason data-action="dismiss" data-id="<?php echo (int)$alert['id']; ?>">Dismiss</button>
                                                    <?php elseif (in_array($alert['incident_status'], ['Verified', 'Responding'], true)): ?>
                                                        <?php if ($alert['incident_status'] === 'Verified'): ?>
                                                            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int)$alert['id']; ?>"><input type="hidden" name="return_view" value="<?php echo h($view); ?>"><button type="submit" name="action" value="respond" class="emergency-action"><i class="fa-solid fa-truck-medical" aria-hidden="true"></i> Responding</button></form>
                                                        <?php endif; ?>
                                                        <?php if (in_array($alert['broadcast_status'], ['Draft', 'Not Sent'], true)): ?>
                                                            <button type="button" class="emergency-action is-broadcast" data-open-broadcast data-id="<?php echo (int)$alert['id']; ?>" data-category="<?php echo h($alert['category']); ?>" data-severity="<?php echo h($alert['severity']); ?>" data-location="<?php echo h($alert['location']); ?>" data-area="<?php echo h($alert['affected_area']); ?>"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Broadcast</button>
                                                        <?php else: ?>
                                                            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int)$alert['id']; ?>"><input type="hidden" name="return_view" value="<?php echo h($view); ?>"><button type="submit" name="action" value="expire_broadcast" class="emergency-action is-expire">Expire Alert</button></form>
                                                        <?php endif; ?>
                                                        <button type="button" class="emergency-action is-resolve" data-open-reason data-action="resolve" data-id="<?php echo (int)$alert['id']; ?>">Resolve</button>
                                                        <button type="button" class="emergency-action" data-open-reason data-action="refer" data-id="<?php echo (int)$alert['id']; ?>">Refer</button>
                                                    <?php elseif (in_array($alert['incident_status'], ['Resolved', 'Dismissed', 'Referred'], true)): ?>
                                                        <?php if ($alert['broadcast_status'] !== 'Sent'): ?>
                                                            <form method="post"><?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int)$alert['id']; ?>"><input type="hidden" name="return_view" value="<?php echo h($view); ?>"><button type="submit" name="action" value="archive" class="emergency-action is-archive">Archive</button></form>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <aside class="col-xl-4">
                <div class="content-card emergency-broadcast-panel">
                    <span class="emergency-eyebrow">RESIDENT DASHBOARD</span>
                    <h2>Official Broadcasts</h2>
                    <nav class="broadcast-tabs" aria-label="Broadcast history">
                        <a href="#activeBroadcasts" class="is-active" data-broadcast-tab="active">Active <span><?php echo count($activeBroadcasts); ?></span></a>
                        <a href="#previousBroadcasts" data-broadcast-tab="previous">Previous <span><?php echo count($previousBroadcasts); ?></span></a>
                    </nav>
                    <div id="activeBroadcasts" class="broadcast-list">
                        <?php if (!$activeBroadcasts): ?><p class="emergency-panel-empty">No active official broadcasts.</p><?php endif; ?>
                        <?php foreach ($activeBroadcasts as $broadcast): ?>
                            <a class="broadcast-card severity-border-<?php echo h($severityClasses[$broadcast['severity']] ?? 'unassessed'); ?>" href="record_view.php?type=emergency&amp;id=<?php echo (int)$broadcast['id']; ?>">
                                <span class="broadcast-card-meta"><strong><?php echo h($broadcast['severity']); ?></strong><small><?php echo h($broadcast['tracking_number']); ?></small></span>
                                <h3><?php echo h($broadcast['category']); ?></h3>
                                <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo h($broadcast['affected_area']); ?> · <?php echo h($broadcast['location']); ?></p>
                                <small><?php echo h(formatDatabaseDateTime($broadcast['broadcasted_at'])); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div id="previousBroadcasts" class="broadcast-list" hidden>
                        <?php if (!$previousBroadcasts): ?><p class="emergency-panel-empty">No previous broadcasts.</p><?php endif; ?>
                        <?php foreach ($previousBroadcasts as $broadcast): ?>
                            <a class="broadcast-card is-previous" href="record_view.php?type=emergency&amp;id=<?php echo (int)$broadcast['id']; ?>">
                                <span class="broadcast-card-meta"><strong><?php echo h($broadcast['severity']); ?></strong><small><?php echo h($broadcast['broadcast_status']); ?></small></span>
                                <h3><?php echo h($broadcast['category']); ?></h3>
                                <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo h($broadcast['affected_area']); ?> · <?php echo h($broadcast['location']); ?></p>
                                <small><?php echo h(formatDatabaseDateTime($broadcast['broadcasted_at'])); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <p class="emergency-channel-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> “Sent” means published in resident dashboards. SMS and email are not configured.</p>
                </div>
            </aside>
        </div>
    </main>
</div>

<dialog class="emergency-dialog" id="verifyDialog" aria-labelledby="verifyTitle">
    <form method="post" class="emergency-dialog-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="verify"><input type="hidden" name="id" id="verifyId"><input type="hidden" name="return_view" value="reports">
        <header><span>REPORT REVIEW</span><button type="button" data-close-dialog aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <h2 id="verifyTitle">Verify emergency report</h2>
        <p>Confirm the details and assess severity before the report can be broadcast as an official alert.</p>
        <label for="verifySeverity">Severity level</label>
        <select class="form-select" id="verifySeverity" name="severity" required>
            <option value="">Select severity</option><?php foreach (['Critical', 'High', 'Moderate', 'Advisory'] as $option): ?><option value="<?php echo h($option); ?>"><?php echo h($option); ?></option><?php endforeach; ?>
        </select>
        <label for="verifyLocation">Street / Landmark</label><input class="form-control" id="verifyLocation" name="location" maxlength="255" required>
        <label for="verifyArea">Affected area</label>
        <select class="form-select" id="verifyArea" name="affected_area" required><option value="">Choose purok</option><?php foreach ($allowedAreas as $area): ?><option value="<?php echo h($area); ?>"><?php echo h($area); ?></option><?php endforeach; ?><option value="Other">Other area</option></select>
        <div id="verifyOtherAreaWrap" hidden><label for="verifyOtherArea">Specify affected area</label><input class="form-control" id="verifyOtherArea" name="other_affected_area" maxlength="150"></div>
        <label for="verifyIncidentAt">Incident date and time</label><input class="form-control" type="datetime-local" id="verifyIncidentAt" name="incident_at" required>
        <footer><button type="button" class="btn btn-light" data-close-dialog>Cancel</button><button type="submit" class="btn btn-primary"><i class="fa-solid fa-shield-check"></i> Verify Report</button></footer>
    </form>
</dialog>

<dialog class="emergency-dialog" id="broadcastDialog" aria-labelledby="broadcastTitle">
    <form method="post" class="emergency-dialog-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="broadcast"><input type="hidden" name="id" id="broadcastId"><input type="hidden" name="return_view" value="broadcasts">
        <header><span>OFFICIAL EMERGENCY ALERT</span><button type="button" data-close-dialog aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <h2 id="broadcastTitle">Review and confirm broadcast</h2>
        <p>This message will be published to resident dashboards for the affected area. No SMS or email will be sent.</p>
        <div class="broadcast-preview">
            <strong id="broadcastPreviewHeading"></strong>
            <span id="broadcastPreviewArea"></span>
            <p id="broadcastPreviewMessage"></p>
        </div>
        <footer><button type="button" class="btn btn-light" data-close-dialog>Cancel</button><button type="submit" class="btn btn-danger"><i class="fa-solid fa-bullhorn"></i> Confirm Broadcast</button></footer>
    </form>
</dialog>

<dialog class="emergency-dialog" id="reasonDialog" aria-labelledby="reasonTitle">
    <form method="post" class="emergency-dialog-form">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" id="reasonAction"><input type="hidden" name="id" id="reasonId"><input type="hidden" name="return_view" value="reports">
        <header><span>INCIDENT UPDATE</span><button type="button" data-close-dialog aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <h2 id="reasonTitle">Update emergency report</h2>
        <p id="reasonDescription"></p>
        <label for="resolutionRemarks">Remarks <span>*</span></label>
        <textarea class="form-control" id="resolutionRemarks" name="remarks" rows="4" maxlength="2000" required></textarea>
        <footer><button type="button" class="btn btn-light" data-close-dialog>Cancel</button><button type="submit" id="reasonSubmit" class="btn btn-primary">Confirm</button></footer>
    </form>
</dialog>

<script>
    document.querySelectorAll('[data-broadcast-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            document.querySelectorAll('[data-broadcast-tab]').forEach(function (item) { item.classList.toggle('is-active', item === tab); });
            document.getElementById('activeBroadcasts').hidden = tab.dataset.broadcastTab !== 'active';
            document.getElementById('previousBroadcasts').hidden = tab.dataset.broadcastTab !== 'previous';
        });
    });
    const verifyDialog = document.getElementById('verifyDialog');
    const broadcastDialog = document.getElementById('broadcastDialog');
    const reasonDialog = document.getElementById('reasonDialog');
    const toManilaInputValue = function (utcValue) {
        if (!utcValue) return '';
        const date = new Date(utcValue.replace(' ', 'T') + 'Z');
        if (Number.isNaN(date.getTime())) return '';
        const parts = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(date);
        const values = Object.fromEntries(parts.map(function (part) { return [part.type, part.value]; }));
        return values.year + '-' + values.month + '-' + values.day + 'T' + values.hour + ':' + values.minute;
    };
    document.querySelectorAll('[data-open-verify]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('verifyId').value = button.dataset.id;
            document.getElementById('verifyLocation').value = button.dataset.location || '';
            document.getElementById('verifyIncidentAt').value = toManilaInputValue(button.dataset.incidentAt);
            const reportedArea = button.dataset.area || '';
            const areaIsListed = Array.from(verifyArea.options).some(function (option) { return option.value === reportedArea; });
            verifyArea.value = areaIsListed ? reportedArea : (reportedArea ? 'Other' : '');
            verifyOtherArea.value = areaIsListed ? '' : reportedArea;
            verifyArea.dispatchEvent(new Event('change'));
            verifyDialog.showModal();
        });
    });
    const verifyArea = document.getElementById('verifyArea');
    const verifyOtherAreaWrap = document.getElementById('verifyOtherAreaWrap');
    const verifyOtherArea = document.getElementById('verifyOtherArea');
    verifyArea.addEventListener('change', function () {
        const showOther = verifyArea.value === 'Other';
        verifyOtherAreaWrap.hidden = !showOther;
        verifyOtherArea.required = showOther;
    });
    document.querySelectorAll('[data-open-broadcast]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('broadcastId').value = button.dataset.id;
            document.getElementById('broadcastPreviewHeading').textContent = button.dataset.category + ' · ' + button.dataset.severity;
            document.getElementById('broadcastPreviewArea').textContent = button.dataset.area + ' · ' + button.dataset.location;
            document.getElementById('broadcastPreviewMessage').textContent = button.dataset.category.toUpperCase() + ' — ' + button.dataset.severity.toUpperCase() + ' ALERT. An emergency incident has been reported at ' + button.dataset.location + ' (' + button.dataset.area + '). Follow instructions from authorized emergency personnel and monitor official barangay updates.';
            broadcastDialog.showModal();
        });
    });
    document.querySelectorAll('[data-open-reason]').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.dataset.action;
            const config = {
                resolve: ['Resolve emergency incident?', 'Record the outcome and action taken. Any active broadcast will expire.', 'Resolve Incident'],
                dismiss: ['Dismiss this unverified report?', 'Record why this report is invalid, duplicate, or outside barangay response.', 'Dismiss Report'],
                refer: ['Refer this emergency?', 'Record the agency or response team receiving this referral.', 'Refer Incident']
            }[action];
            document.getElementById('reasonAction').value = action;
            document.getElementById('reasonId').value = button.dataset.id;
            document.getElementById('reasonTitle').textContent = config[0];
            document.getElementById('reasonDescription').textContent = config[1];
            document.getElementById('reasonSubmit').textContent = config[2];
            document.getElementById('resolutionRemarks').value = '';
            reasonDialog.showModal();
        });
    });
    document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
        button.addEventListener('click', function () { button.closest('dialog').close(); });
    });
    [verifyDialog, broadcastDialog, reasonDialog].forEach(function (dialog) {
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    });
</script>
<?php renderFooterScripts(); ?>
</body>
</html>
