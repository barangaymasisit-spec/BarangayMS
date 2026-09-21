<?php
require_once __DIR__ . '/layout.php';

$activeAlerts = $conn->query("SELECT id, tracking_number, resident_name, category, date_filed, description FROM complaints WHERE status = 'Ongoing' ORDER BY date_filed DESC");
$pendingAlerts = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$ongoingAlerts = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing'")->fetch_row()[0] ?? 0);
$resolvedAlerts = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Resolved'")->fetch_row()[0] ?? 0);
$todayAlerts = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE date_filed = CURDATE()")->fetch_row()[0] ?? 0);
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

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                    <h4>Active Alerts</h4>

                    <h2><?php echo $ongoingAlerts; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-hourglass-half"></i>

                    <h4>Awaiting Response</h4>

                    <h2><?php echo $pendingAlerts; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Resolved</h4>

                    <h2><?php echo $resolvedAlerts; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-calendar-day"></i>

                    <h4>Filed Today</h4>

                    <h2><?php echo $todayAlerts; ?></h2>

                </div>

            </div>

        </div>

        <div class="row mt-4">

            <div class="col-lg-8">

                <div class="content-card">

                    <div class="card-header-custom">

                        <h3>Active Emergency Reports</h3>

                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <a href="emergency_report.php" class="btn btn-primary">
                                <i class="fa-solid fa-plus"></i>
                                New Report
                            </a>
                        <?php endif; ?>

                    </div>

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th>Tracking No.</th>

                                <th>Reported By</th>

                                <th>Category</th>

                                <th>Date Filed</th>

                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($activeAlerts->num_rows === 0): ?>

                                <tr>
                                    <td colspan="5">No active emergency reports.</td>
                                </tr>

                            <?php else: ?>

                                <?php while ($alert = $activeAlerts->fetch_assoc()): ?>

                                    <?php $alertRows[] = $alert; ?>

                                    <tr>

                                        <td><?php echo h($alert['tracking_number']); ?></td>

                                        <td><?php echo h($alert['resident_name']); ?></td>

                                        <td><?php echo h($alert['category']); ?></td>

                                        <td><?php echo h($alert['date_filed']); ?></td>

                                        <td>
                                            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                                <a href="complaint_form.php?id=<?php echo (int)$alert['id']; ?>" class="btn btn-sm btn-primary">View</a>
                                            <?php else: ?>
                                                <span class="text-muted">Read only</span>
                                            <?php endif; ?>
                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="col-lg-4">

                <div class="content-card">

                    <h3 class="mb-4">Alert Board</h3>

                    <?php if (empty($alertRows)): ?>

                        <p class="text-muted">No active alerts to broadcast.</p>

                    <?php else: ?>

                        <?php foreach ($alertRows as $index => $alert): ?>

                            <div class="alert-box <?php echo $alertStyles[$index % count($alertStyles)]; ?>">

                                <h5><?php echo h($alert['category']); ?></h5>

                                <p><?php echo h($alert['description'] ?: $alert['resident_name']); ?></p>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <button type="button" class="btn btn-danger w-100 mt-3 js-not-implemented">

                        <i class="fa-solid fa-bullhorn"></i>

                        Send Emergency Alert

                    </button>

                </div>

            </div>

        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
