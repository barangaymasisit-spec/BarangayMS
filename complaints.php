<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(tracking_number LIKE ? OR resident_name LIKE ? OR category LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$listSql = 'SELECT id, tracking_number, resident_name, category, status, date_filed FROM complaints' . $whereSql . ' ORDER BY id DESC';
if ($params) {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $complaints = $stmt->get_result();
} else {
    $complaints = $conn->query($listSql);
}

$totalComplaints = (int)($conn->query('SELECT COUNT(*) FROM complaints')->fetch_row()[0] ?? 0);
$pendingComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$resolvedComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Resolved'")->fetch_row()[0] ?? 0);
$ongoingComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing'")->fetch_row()[0] ?? 0);

$activeAlerts = $conn->query("SELECT tracking_number, category, description FROM emergency_alerts WHERE status = 'Active' ORDER BY created_at DESC LIMIT 5");
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$alertStyles = ['danger', 'warning', 'primary'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Complaints & Alerts'); ?>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- CSS -->
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
</head>

<body>

<div class="wrapper">

    <!-- SIDEBAR -->
    <?php renderSidebar('complaints', 'compact'); ?>

    <!-- MAIN -->
    <main class="main-content">

        <!-- TOP HEADER -->
        <?php renderTopbar('Complaints & Alerts', 'Barangay Management System', 'compact', ['clock' => true]); ?>

        <?php renderNotice([
            'created' => 'Complaint record created.',
            'updated' => 'Complaint record updated.',
            'deleted' => 'Complaint record deleted.',
        ]); ?>

        <!-- CARDS -->

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-file-circle-exclamation"></i>

                    <h4>Total Complaints</h4>

                    <h2><?php echo $totalComplaints; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-hourglass-half"></i>

                    <h4>Pending</h4>

                    <h2><?php echo $pendingComplaints; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Resolved</h4>

                    <h2><?php echo $resolvedComplaints; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-spinner"></i>

                    <h4>Ongoing</h4>

                    <h2><?php echo $ongoingComplaints; ?></h2>

                </div>

            </div>

        </div>

        <!-- CONTENT -->

        <div class="row mt-4">

            <!-- LEFT -->

            <div class="col-lg-8">

                <div class="content-card">

                    <div class="card-header-custom">

                        <h3>Resident Complaints</h3>

                        <a href="complaint_form.php" class="btn btn-primary">
                            <i class="fa-solid fa-plus"></i>
                            New Complaint
                        </a>

                    </div>

                    <form class="filters" method="get" action="complaints.php">

                        <label for="searchComplaint" class="visually-hidden">
                            Search complaints
                        </label>

                        <input type="text"
                            id="searchComplaint"
                            name="search"
                            class="form-control"
                            value="<?php echo h($search); ?>"
                            placeholder="Search complaint...">

                        <label for="statusFilter" class="visually-hidden">
                            Filter complaints by status
                        </label>

                        <select id="statusFilter" name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Pending"<?php echo $statusFilter === 'Pending' ? ' selected' : ''; ?>>Pending</option>
                            <option value="Ongoing"<?php echo $statusFilter === 'Ongoing' ? ' selected' : ''; ?>>Ongoing</option>
                            <option value="Resolved"<?php echo $statusFilter === 'Resolved' ? ' selected' : ''; ?>>Resolved</option>
                        </select>

                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            Filter
                        </button>

                    </form>

                    <table class="table align-middle">

                        <thead>

                        <tr>

                            <th>#</th>

                            <th>Tracking No.</th>

                            <th>Resident</th>

                            <th>Category</th>

                            <th>Status</th>

                            <th>Date</th>

                            <th>Action</th>

                        </tr>

                        </thead>

                        <tbody>

                        <?php if ($complaints->num_rows === 0): ?>

                            <tr>
                                <td colspan="7">No complaint records found.</td>
                            </tr>

                        <?php else: $index = 1; ?>

                            <?php while ($row = $complaints->fetch_assoc()): ?>

                                <tr>

                                    <td><?php echo $index++; ?></td>

                                    <td><?php echo h($row['tracking_number']); ?></td>

                                    <td><?php echo h($row['resident_name']); ?></td>

                                    <td><?php echo h($row['category']); ?></td>

                                    <td>
                                        <span class="badge <?php echo $row['status'] === 'Pending' ? 'bg-warning' : ($row['status'] === 'Resolved' ? 'bg-success' : 'bg-info'); ?>">
                                            <?php echo h($row['status']); ?>
                                        </span>
                                    </td>

                                    <td><?php echo h($row['date_filed']); ?></td>

                                    <td>

                                        <a href="complaint_form.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-primary">
                                            View
                                        </a>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <!-- RIGHT -->

            <div class="col-lg-4">

                <div class="content-card">

                    <h3 class="mb-4">

                        Active Emergency Alerts

                    </h3>

                    <?php if ($activeAlerts->num_rows === 0): ?>

                        <p class="text-muted">No active emergency alerts.</p>

                    <?php else: $alertIndex = 0; ?>

                        <?php while ($alert = $activeAlerts->fetch_assoc()): ?>

                            <div class="alert-box <?php echo $alertStyles[$alertIndex++ % count($alertStyles)]; ?>">

                                <h5>

                                    <?php echo h($alert['category']); ?>

                                </h5>

                                <p>

                                    <?php echo h($alert['description']); ?>

                                </p>

                            </div>

                        <?php endwhile; ?>

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
