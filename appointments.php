<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = '(resident_name LIKE ? OR purpose LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$listSql = 'SELECT id, resident_name, purpose, appointment_date, appointment_time, status FROM appointments'
    . $whereSql . ' ORDER BY appointment_date DESC, appointment_time DESC';
if ($params) {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $appointments = $stmt->get_result();
} else {
    $appointments = $conn->query($listSql);
}

$totalAppointments = (int)($conn->query('SELECT COUNT(*) FROM appointments')->fetch_row()[0] ?? 0);
$pendingAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$approvedAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Approved'")->fetch_row()[0] ?? 0);
$cancelledAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled'")->fetch_row()[0] ?? 0);

$todaySchedule = $conn->query("SELECT resident_name, purpose, appointment_time FROM appointments WHERE appointment_date = CURDATE() ORDER BY appointment_time ASC");

function badgeClass($status) {
    if ($status === 'Approved') {
        return 'bg-success';
    }
    if ($status === 'Cancelled') {
        return 'bg-danger';
    }
    if ($status === 'Pending') {
        return 'bg-warning';
    }
    return 'bg-secondary';
}

function formatTime($time) {
    if (!$time) {
        return '';
    }
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Appointments'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <link rel="stylesheet" href="<?php echo asset('appointments.css'); ?>">

</head>

<body>

<div class="wrapper">

    <!-- Sidebar -->

    <?php renderSidebar('appointments', 'compact'); ?>

    <!-- Main -->

    <main class="main-content">

        <?php renderTopbar('Appointments', 'Manage Resident Appointments', 'compact', ['clock' => true]); ?>

        <?php renderNotice([
            'created' => 'Appointment scheduled.',
            'updated' => 'Appointment updated.',
            'deleted' => 'Appointment deleted.',
        ]); ?>

        <!-- Cards -->

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-calendar-days"></i>

                    <h4>Total Appointments</h4>

                    <h2><?php echo $totalAppointments; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-clock"></i>

                    <h4>Pending</h4>

                    <h2><?php echo $pendingAppointments; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Approved</h4>

                    <h2><?php echo $approvedAppointments; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-calendar-xmark"></i>

                    <h4>Cancelled</h4>

                    <h2><?php echo $cancelledAppointments; ?></h2>

                </div>

            </div>

        </div>

        <!-- Content -->

        <div class="row mt-4">

            <!-- Table -->

            <div class="col-lg-8">

                <div class="content-card">

                    <div class="card-header-custom">

                        <h3>Appointment List</h3>

                        <a
                            href="appointment_form.php"
                            class="btn btn-primary"
                            aria-label="Add Appointment">

                            <i class="fa-solid fa-plus"></i>

                            Add Appointment

                        </a>

                    </div>

                    <form class="filters" method="get" action="appointments.php">

                        <label for="searchAppointment" class="visually-hidden">
                            Search Appointment
                        </label>

                        <input
                            id="searchAppointment"
                            name="search"
                            type="search"
                            class="form-control"
                            value="<?php echo h($search); ?>"
                            placeholder="Search resident...">

                        <label for="statusFilter" class="visually-hidden">
                            Filter by Status
                        </label>

                        <select id="statusFilter" name="status" class="form-select">

                            <option value="">All Status</option>

                            <option value="Pending"<?php echo $statusFilter === 'Pending' ? ' selected' : ''; ?>>Pending</option>

                            <option value="Approved"<?php echo $statusFilter === 'Approved' ? ' selected' : ''; ?>>Approved</option>

                            <option value="Cancelled"<?php echo $statusFilter === 'Cancelled' ? ' selected' : ''; ?>>Cancelled</option>

                        </select>

                        <button type="submit" class="btn btn-primary">

                            <i class="fa-solid fa-magnifying-glass"></i>

                            Search

                        </button>

                    </form>

                    <table class="table">

                        <thead>

                            <tr>

                                <th>ID</th>

                                <th>Resident</th>

                                <th>Purpose</th>

                                <th>Date</th>

                                <th>Time</th>

                                <th>Status</th>

                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($appointments->num_rows === 0): ?>

                                <tr>
                                    <td colspan="7">No appointments found.</td>
                                </tr>

                            <?php else: ?>

                                <?php while ($row = $appointments->fetch_assoc()): ?>

                                    <tr>

                                        <td><?php echo h(str_pad((string)$row['id'], 3, '0', STR_PAD_LEFT)); ?></td>

                                        <td><?php echo h($row['resident_name']); ?></td>

                                        <td><?php echo h($row['purpose']); ?></td>

                                        <td><?php echo h($row['appointment_date']); ?></td>

                                        <td><?php echo h(formatTime($row['appointment_time'])); ?></td>

                                        <td>
                                            <span class="badge <?php echo badgeClass($row['status']); ?>">
                                                <?php echo h($row['status']); ?>
                                            </span>
                                        </td>

                                        <td>

                                            <a
                                                href="appointment_form.php?id=<?php echo (int)$row['id']; ?>"
                                                class="btn btn-sm btn-primary"
                                                aria-label="View Appointment">

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

            <!-- Right Panel -->

            <div class="col-lg-4">

                <div class="content-card">

                    <h3>Today's Schedule</h3>

                    <?php if ($todaySchedule->num_rows === 0): ?>

                        <p class="text-muted">No scheduled appointments for today.</p>

                    <?php else: ?>

                        <?php while ($item = $todaySchedule->fetch_assoc()): ?>

                            <div class="schedule-box">

                                <h5><?php echo h(formatTime($item['appointment_time'])); ?></h5>

                                <p><?php echo h($item['resident_name']); ?></p>

                                <small><?php echo h($item['purpose']); ?></small>

                            </div>

                        <?php endwhile; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
