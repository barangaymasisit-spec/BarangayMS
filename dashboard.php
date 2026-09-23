<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/db.php';

$totalResidents = (int)($conn->query('SELECT COUNT(*) FROM residents')->fetch_row()[0] ?? 0);
$maleResidents = (int)($conn->query("SELECT COUNT(*) FROM residents WHERE sex = 'Male'")->fetch_row()[0] ?? 0);
$femaleResidents = (int)($conn->query("SELECT COUNT(*) FROM residents WHERE sex = 'Female'")->fetch_row()[0] ?? 0);
$registeredVoters = (int)($conn->query("SELECT COUNT(*) FROM residents WHERE voter_status = 'Registered'")->fetch_row()[0] ?? 0);
$totalHouseholds = (int)($conn->query('SELECT COUNT(*) FROM households')->fetch_row()[0] ?? 0);
$pendingCases = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$ongoingCases = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing'")->fetch_row()[0] ?? 0);
$resolvedCases = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Resolved'")->fetch_row()[0] ?? 0);
$pendingAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$officials = $conn->query("SELECT first_name, last_name, role FROM users WHERE role IN ('admin','staff') ORDER BY id ASC LIMIT 10");

$categoryCounts = [
    'Senior Citizen' => 0,
    'Solo Parent' => 0,
    'PWD' => 0,
    'Indigenous Person' => 0,
    'Pregnant Woman' => 0,
    'Lactating Mother' => 0,
    'OFW Family' => 0,
    '4Ps Beneficiary' => 0,
];
$residentsResult = $conn->query('SELECT categories FROM residents WHERE categories IS NOT NULL AND categories <> ""');
while ($row = $residentsResult->fetch_assoc()) {
    $categories = array_map('trim', explode(',', $row['categories']));
    foreach ($categories as $cat) {
        if (isset($categoryCounts[$cat])) {
            $categoryCounts[$cat]++;
        }
    }
}
$specialTotal = array_sum($categoryCounts);

$trendMonths = [];
$trendLabels = [];
$trendResidents = [];
$trendAppointments = [];
$trendComplaints = [];
$trendCertificates = [];

for ($i = 5; $i >= 0; $i--) {
    $monthDate = date('Y-m-01', strtotime("-$i month"));
    $monthKey = date('Y-m', strtotime($monthDate));
    $trendMonths[] = $monthKey;
    $trendLabels[] = date('M', strtotime($monthDate));
}

if ($trendMonths !== []) {
    $monthRangeStart = $trendMonths[0] . '-01';
    $monthRangeEnd = date('Y-m-01', strtotime('+1 month'));

    $residentTrendStmt = $conn->prepare('SELECT DATE_FORMAT(date_registered, "%Y-%m") AS month_key, COUNT(*) AS total FROM residents WHERE date_registered >= ? AND date_registered < ? GROUP BY month_key');
    $residentTrendStmt->bind_param('ss', $monthRangeStart, $monthRangeEnd);
    $residentTrendStmt->execute();
    $residentTrendRows = $residentTrendStmt->get_result();
    $residentTrendMap = [];
    while ($row = $residentTrendRows->fetch_assoc()) {
        $residentTrendMap[$row['month_key']] = (int)$row['total'];
    }
    $residentTrendStmt->close();

    $appointmentTrendStmt = $conn->prepare('SELECT DATE_FORMAT(appointment_date, "%Y-%m") AS month_key, COUNT(*) AS total FROM appointments WHERE appointment_date >= ? AND appointment_date < ? GROUP BY month_key');
    $appointmentTrendStmt->bind_param('ss', $monthRangeStart, $monthRangeEnd);
    $appointmentTrendStmt->execute();
    $appointmentTrendRows = $appointmentTrendStmt->get_result();
    $appointmentTrendMap = [];
    while ($row = $appointmentTrendRows->fetch_assoc()) {
        $appointmentTrendMap[$row['month_key']] = (int)$row['total'];
    }
    $appointmentTrendStmt->close();

    $complaintTrendStmt = $conn->prepare('SELECT DATE_FORMAT(date_filed, "%Y-%m") AS month_key, COUNT(*) AS total FROM complaints WHERE date_filed >= ? AND date_filed < ? GROUP BY month_key');
    $complaintTrendStmt->bind_param('ss', $monthRangeStart, $monthRangeEnd);
    $complaintTrendStmt->execute();
    $complaintTrendRows = $complaintTrendStmt->get_result();
    $complaintTrendMap = [];
    while ($row = $complaintTrendRows->fetch_assoc()) {
        $complaintTrendMap[$row['month_key']] = (int)$row['total'];
    }
    $complaintTrendStmt->close();

    $certificateTrendStmt = $conn->prepare('SELECT DATE_FORMAT(request_date, "%Y-%m") AS month_key, COUNT(*) AS total FROM certificates WHERE request_date >= ? AND request_date < ? GROUP BY month_key');
    $certificateTrendStmt->bind_param('ss', $monthRangeStart, $monthRangeEnd);
    $certificateTrendStmt->execute();
    $certificateTrendRows = $certificateTrendStmt->get_result();
    $certificateTrendMap = [];
    while ($row = $certificateTrendRows->fetch_assoc()) {
        $certificateTrendMap[$row['month_key']] = (int)$row['total'];
    }
    $certificateTrendStmt->close();

    foreach ($trendMonths as $monthKey) {
        $trendResidents[] = $residentTrendMap[$monthKey] ?? 0;
        $trendAppointments[] = $appointmentTrendMap[$monthKey] ?? 0;
        $trendComplaints[] = $complaintTrendMap[$monthKey] ?? 0;
        $trendCertificates[] = $certificateTrendMap[$monthKey] ?? 0;
    }
}

$recentActivity = $conn->query(
    "SELECT a.id, a.action, a.entity, a.details, a.created_at, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS user_name
     FROM activity_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 8"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Dashboard'); ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="<?php echo asset('dashboard.css'); ?>">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
          rel="stylesheet">
</head>

<body>

<div class="wrapper">

    <!-- ================= Sidebar ================= -->

    <?php renderSidebar('dashboard', 'panel'); ?>

    <main class="main-content">

        <?php renderTopbar('Dashboard', 'Barangay Management System', 'panel', ['clock' => true]); ?>

        <!-- Statistics Cards -->

        <section class="cards">

            <div class="card">
                <i class="fa-solid fa-users"></i>
                <h3>Total Population</h3>
                <h2><?php echo $totalResidents; ?></h2>
            </div>

            <div class="card">
                <i class="fa-solid fa-mars"></i>
                <h3>Male</h3>
                <h2><?php echo $maleResidents; ?></h2>
            </div>

            <div class="card">
                <i class="fa-solid fa-venus"></i>
                <h3>Female</h3>
                <h2><?php echo $femaleResidents; ?></h2>
            </div>

            <div class="card">
                <i class="fa-solid fa-check-to-slot"></i>
                <h3>Registered Voters</h3>
                <h2><?php echo $registeredVoters; ?></h2>
            </div>

        </section>

        <!-- Main Content -->

        <section class="content">

            <!-- Barangay Officials -->

            <div class="panel">

                <h3>Barangay Officials</h3>

                <table>

                    <thead>

                        <tr>
                            <th>Name</th>
                            <th>Committee</th>
                            <th>Position</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php if ($officials->num_rows === 0): ?>
                            <tr>
                                <td colspan="3">No officials found.</td>
                            </tr>
                        <?php else: ?>
                            <?php while ($official = $officials->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo h(trim($official['first_name'] . ' ' . $official['last_name'])); ?></td>
                                    <td><?php echo $official['role'] === 'admin' ? 'Administration' : 'Barangay Services'; ?></td>
                                    <td><?php echo h(ucfirst($official['role'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- Summary -->
            <div class="chart-card">

                <h3>Special Categories</h3>

                <div class="chart-container">
                    <canvas id="specialCategoryChart"></canvas>
                </div>

                <div class="chart-total">
                    <span>Total Special Categories</span>
                    <h2 id="specialTotal"><?php echo $specialTotal; ?></h2>
                </div>

            </div>

            <div class="summary">

                <div class="summary-card">
                    <h4>Settled Cases</h4>
                    <h2><?php echo $resolvedCases; ?></h2>
                </div>

                <div class="summary-card">
                    <h4>Pending Cases</h4>
                    <h2><?php echo $pendingCases; ?></h2>
                </div>

                <div class="summary-card">
                    <h4>Scheduled Appointments</h4>
                    <h2><?php echo $pendingAppointments; ?></h2>
                </div>

                <div class="summary-card">
                    <h4>Total Households</h4>
                    <h2><?php echo $totalHouseholds; ?></h2>
                </div>

            </div>

            <div class="panel trend-panel">
                <div class="panel-header-row">
                    <h3>Service Activity Trend</h3>
                    <span class="trend-tag">Last 6 months</span>
                </div>
                <div class="chart-container trend-chart-container">
                    <canvas id="serviceTrendChart"></canvas>
                </div>
            </div>

        </section>

        <section class="panel" style="margin-top: 24px;">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h3>Recent Audit History</h3>
                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                    <a href="audit.php" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-clock-rotate-left"></i> View Full History</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentActivity->num_rows === 0): ?>
                            <tr><td colspan="5">No recent audit activity found.</td></tr>
                        <?php else: ?>
                            <?php while ($log = $recentActivity->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo h(formatDatabaseDateTime($log['created_at'])); ?></td>
                                    <td><?php echo h(trim($log['user_name'] ?: 'System')); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo h(strtoupper($log['action'])); ?></span></td>
                                    <td><?php echo h($log['entity']); ?></td>
                                    <td><?php echo h($log['details'] ?: 'No details recorded'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

</div>

<!-- JavaScript -->
<script>
    window.specialCategoryData = <?php echo json_encode([
        'seniorCitizen' => $categoryCounts['Senior Citizen'],
        'soloParent'    => $categoryCounts['Solo Parent'],
        'pwd'           => $categoryCounts['PWD'],
        'indigenous'    => $categoryCounts['Indigenous Person'],
        'pregnant'      => $categoryCounts['Pregnant Woman'],
        'lactating'     => $categoryCounts['Lactating Mother'],
        'ofw'           => $categoryCounts['OFW Family'],
        'fourPs'        => $categoryCounts['4Ps Beneficiary'],
    ]); ?>;
    window.serviceTrendData = <?php echo json_encode([
        'labels' => $trendLabels,
        'residents' => $trendResidents,
        'appointments' => $trendAppointments,
        'complaints' => $trendComplaints,
        'certificates' => $trendCertificates,
    ]); ?>;
</script>
<script src="<?php echo asset('dashboard.js'); ?>"></script>
<?php renderFooterScripts(false); ?>

</body>
</html>
