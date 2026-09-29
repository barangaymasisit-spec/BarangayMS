<?php
require_once __DIR__ . '/layout.php';

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Admin actions: broadcast a resident's report to everyone, dismiss it, or mark a sent alert resolved.
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $alertId = (int)$_POST['id'];
    $transitions = ['broadcast' => ['Reported', 'Active'], 'resolve' => ['Active', 'Resolved'], 'dismiss' => ['Reported', 'Resolved']];
    $action = $_POST['action'] ?? '';
    if (isset($transitions[$action])) {
        [$from, $to] = $transitions[$action];
        $stmt = $conn->prepare('UPDATE emergency_alerts SET status = ? WHERE id = ? AND status = ?');
        $stmt->bind_param('sis', $to, $alertId, $from);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if ($changed) {
            logActivity($conn, 'updated', 'emergency_alerts', 'Emergency alert ' . ($action === 'broadcast' ? 'broadcast to residents.' : 'marked resolved.'), $alertId);
        }
    }
    header('Location: emergency.php?msg=' . ($action === 'broadcast' ? 'sent' : 'updated'));
    exit;
}

$openAlerts = $conn->query("SELECT id, tracking_number, reported_by, category, description, status, created_at FROM emergency_alerts WHERE status IN ('Reported', 'Active') ORDER BY status = 'Active', created_at DESC");
$counts = ['Reported' => 0, 'Active' => 0, 'Resolved' => 0];
foreach ($conn->query('SELECT status, COUNT(*) FROM emergency_alerts GROUP BY status')->fetch_all() as [$status, $total]) {
    $counts[$status] = (int)$total;
}
// created_at is stored in UTC; count from midnight Manila time.
$todayStartUtc = (new DateTimeImmutable('today'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$todayAlerts = (int)($conn->query("SELECT COUNT(*) FROM emergency_alerts WHERE created_at >= '$todayStartUtc'")->fetch_row()[0] ?? 0);
$alertStyles = ['danger', 'warning', 'primary'];
$alertRows = [];
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Emergency Alerts'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">

</head>

<body>

<div class="wrapper">

    <?php renderSidebar('emergency', 'compact'); ?>

    <main class="main-content">

        <?php renderTopbar('Emergency Alerts', 'Monitor and broadcast barangay emergency alerts.', 'compact', ['clock' => true]); ?>

        <?php renderNotice([
            'sent' => 'Emergency alert sent to all residents.',
            'updated' => 'Emergency alert updated.',
        ]); ?>

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                    <h4>Active Alerts</h4>

                    <h2><?php echo $counts['Active']; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-hourglass-half"></i>

                    <h4>Reports to Review</h4>

                    <h2><?php echo $counts['Reported']; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Resolved</h4>

                    <h2><?php echo $counts['Resolved']; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-calendar-day"></i>

                    <h4>Today</h4>

                    <h2><?php echo $todayAlerts; ?></h2>

                </div>

            </div>

        </div>

        <div class="row mt-4">

            <div class="col-lg-8">

                <div class="content-card">

                    <div class="card-header-custom">

                        <h3>Emergency Reports &amp; Alerts</h3>

                    </div>

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th>Tracking No.</th>

                                <th>Reported By</th>

                                <th>Emergency</th>

                                <th>Status</th>

                                <th>Date</th>

                                <?php if ($isAdmin): ?><th>Action</th><?php endif; ?>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($openAlerts->num_rows === 0): ?>

                                <tr>
                                    <td colspan="<?php echo $isAdmin ? 6 : 5; ?>">No open emergency reports or alerts.</td>
                                </tr>

                            <?php else: ?>

                                <?php while ($alert = $openAlerts->fetch_assoc()): ?>

                                    <?php if ($alert['status'] === 'Active') { $alertRows[] = $alert; } ?>

                                    <tr>

                                        <td><?php echo h($alert['tracking_number']); ?></td>

                                        <td><?php echo h($alert['reported_by']); ?></td>

                                        <td>
                                            <strong><?php echo h($alert['category']); ?></strong>
                                            <div class="small text-muted"><?php echo h($alert['description']); ?></div>
                                        </td>

                                        <td>
                                            <span class="badge <?php echo $alert['status'] === 'Active' ? 'bg-danger' : 'bg-warning'; ?>">
                                                <?php echo $alert['status'] === 'Active' ? 'Sent' : 'For Review'; ?>
                                            </span>
                                        </td>

                                        <td class="text-nowrap"><?php echo h(formatDatabaseDateTime($alert['created_at'])); ?></td>

                                        <?php if ($isAdmin): ?>
                                            <td class="text-nowrap">
                                                <form method="post" class="d-inline">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="id" value="<?php echo (int)$alert['id']; ?>">
                                                    <?php if ($alert['status'] === 'Reported'): ?>
                                                        <button type="submit" name="action" value="broadcast" class="btn btn-sm btn-danger" onclick="return confirm('Send this alert to all residents?');">
                                                            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Broadcast
                                                        </button>
                                                        <button type="submit" name="action" value="dismiss" class="btn btn-sm btn-outline-secondary">Dismiss</button>
                                                    <?php else: ?>
                                                        <button type="submit" name="action" value="resolve" class="btn btn-sm btn-success">
                                                            <i class="fa-solid fa-check" aria-hidden="true"></i> Resolve
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        <?php endif; ?>

                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="col-lg-4">

                <div class="content-card">

                    <h3 class="mb-4">Alerts Sent to Residents</h3>

                    <?php if (empty($alertRows)): ?>

                        <p class="text-muted">No active alerts.</p>

                    <?php else: ?>

                        <?php foreach ($alertRows as $index => $alert): ?>

                            <div class="alert-box <?php echo $alertStyles[$index % count($alertStyles)]; ?>">

                                <h5><?php echo h($alert['category']); ?></h5>

                                <p><?php echo h($alert['description']); ?></p>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                        <a href="emergency_report.php" class="btn btn-danger w-100 mt-3">
                            <i class="fa-solid fa-bullhorn"></i>
                            Send Emergency Alert
                        </a>
                    <?php endif; ?>

                </div>

            </div>

        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
