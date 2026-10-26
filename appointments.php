<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$purposeFilter = trim($_GET['purpose'] ?? '');
$assignedFilter = filter_var($_GET['assigned_to'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$appointmentView = in_array($_GET['view'] ?? 'active', ['active', 'archived'], true) ? $_GET['view'] : 'active';
$displayMode = in_array($_GET['display'] ?? 'list', ['list', 'calendar'], true) ? $_GET['display'] : 'list';
$statusOptions = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rejected', 'No Show'];
$archivableStatuses = ['Completed', 'Cancelled', 'Rejected', 'No Show'];
$jsonResponse = static function (array $payload, int $status = 200): never {
    $action = (string)($_POST['action'] ?? '');
    $isAppointmentAction = $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($action, ['archive_appointment', 'restore_appointment', 'update_appointment_status'], true);
    $isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAppointmentAction && !$isAjaxRequest) {
        $succeeded = $status < 400 && !empty($payload['success']);
        $message = $succeeded
            ? match ($action) {
                'archive_appointment' => 'archive_success',
                'restore_appointment' => 'restore_success',
                default => 'updated',
            }
            : 'action_error';
        $view = $action === 'archive_appointment' ? 'archived' : 'active';
        header('Location: appointments.php?' . http_build_query(['view' => $view, 'msg' => $message]), true, 303);
        exit;
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'details') {
    $appointmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$appointmentId || $appointmentId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid appointment record.'], 400);
    }
    $stmt = $conn->prepare(
        "SELECT a.*, CASE WHEN r.id IS NULL THEN COALESCE(NULLIF(a.resident_name, ''), 'Resident not linked')
                          ELSE CONCAT_WS(' ', NULLIF(r.first_name, ''), NULLIF(r.middle_name, ''), NULLIF(r.last_name, ''), NULLIF(r.suffix, ''))
                     END AS linked_resident_name,
                     CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel,
                     u.position AS assigned_position,
                     CASE WHEN archive_user.id IS NULL THEN 'Not recorded' ELSE CONCAT(archive_user.first_name, ' ', archive_user.last_name) END AS archived_by_name
         FROM appointments a
         LEFT JOIN residents r ON r.id = a.resident_id
         LEFT JOIN users u ON u.id = a.assigned_to_user_id
         LEFT JOIN users archive_user ON archive_user.id = a.archived_by_user_id
         WHERE a.id = ?"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$record) {
        $jsonResponse(['success' => false, 'message' => 'Appointment record not found.'], 404);
    }

    $timeline = [];
    $hasSubmission = false;
    $historyStmt = $conn->prepare(
        "SELECT a.action, a.details, a.created_at, CASE WHEN u.id IS NULL THEN '' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS updated_by
         FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity = 'appointments' AND a.related_id = ? ORDER BY a.id ASC"
    );
    $historyStmt->bind_param('i', $appointmentId);
    $historyStmt->execute();
    $history = $historyStmt->get_result();
    while ($event = $history->fetch_assoc()) {
        $activity = '';
        if ($event['action'] === 'created') {
            $activity = 'Appointment requested';
            $hasSubmission = true;
        } elseif (preg_match('/Appointment status changed from .+ to (.+)\.$/i', (string)$event['details'], $matches)) {
            $activity = 'Status changed to ' . trim($matches[1]);
        } elseif ($event['action'] === 'rescheduled' || $event['action'] === 'assigned') {
            $activity = (string)$event['details'];
        } elseif ($event['action'] === 'archived') {
            $activity = 'Appointment archived';
        } elseif ($event['action'] === 'restored') {
            $activity = 'Appointment restored';
        } elseif ($event['action'] === 'updated') {
            $activity = 'Appointment record updated';
        }
        if ($activity !== '') {
            $timeline[] = ['activity' => $activity, 'date' => $event['created_at'], 'updated_by' => $event['updated_by']];
        }
    }
    $historyStmt->close();
    if (!$hasSubmission) {
        array_unshift($timeline, ['activity' => 'Appointment requested (date recorded)', 'date' => $record['created_at'] ?: null, 'updated_by' => '']);
    }
    $record['timeline'] = $timeline;
    $record['can_archive'] = empty($record['is_archived']) && in_array($record['status'], $archivableStatuses, true);
    $jsonResponse(['success' => true, 'record' => $record]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_appointment', 'restore_appointment', 'update_appointment_status'], true)) {
    $action = (string)$_POST['action'];
    $appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$appointmentId || $appointmentId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid appointment record.'], 400);
    }
    $stmt = $conn->prepare('SELECT status, is_archived, appointment_date, appointment_time, duration_minutes, assigned_to_user_id FROM appointments WHERE id = ?');
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $currentAppointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$currentAppointment) {
        $jsonResponse(['success' => false, 'message' => 'Appointment record not found.'], 404);
    }
    $previousStatus = (string)$currentAppointment['status'];

    if ($action === 'archive_appointment') {
        if ($currentAppointment['is_archived'] || !in_array($previousStatus, $archivableStatuses, true)) {
            $jsonResponse(['success' => false, 'message' => 'Only Completed, Cancelled, Rejected, or No Show appointments can be archived.'], 409);
        }
        $archivedById = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE appointments SET is_archived = 1, archived_at = NOW(), archived_by_user_id = ? WHERE id = ? AND is_archived = 0');
        $stmt->bind_param('ii', $archivedById, $appointmentId);
        $activity = 'archived';
        $message = 'Appointment archived.';
    } elseif ($action === 'restore_appointment') {
        if (!$currentAppointment['is_archived']) {
            $jsonResponse(['success' => false, 'message' => 'This appointment is already active.'], 409);
        }
        $stmt = $conn->prepare('UPDATE appointments SET is_archived = 0, archived_at = NULL, archived_by_user_id = NULL WHERE id = ? AND is_archived = 1');
        $stmt->bind_param('i', $appointmentId);
        $activity = 'restored';
        $message = 'Appointment restored.';
    } else {
        $newStatus = trim((string)($_POST['status'] ?? ''));
        if (!in_array($newStatus, $statusOptions, true)) {
            $jsonResponse(['success' => false, 'message' => 'Choose a valid appointment status.'], 422);
        }
        if ($currentAppointment['is_archived']) {
            $jsonResponse(['success' => false, 'message' => 'Restore the appointment before changing its status.'], 409);
        }
        $scheduleTransactionStarted = $newStatus === 'Approved' && (int)$currentAppointment['assigned_to_user_id'] > 0;
        if ($scheduleTransactionStarted) {
            $conn->begin_transaction();
            $personnelId = (int)$currentAppointment['assigned_to_user_id'];
            $personnelLockStmt = $conn->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $personnelLockStmt->bind_param('i', $personnelId);
            $personnelLockStmt->execute();
            $personnelExists = (bool)$personnelLockStmt->get_result()->fetch_assoc();
            $personnelLockStmt->close();
            if (!$personnelExists) {
                $conn->rollback();
                $jsonResponse(['success' => false, 'message' => 'The assigned personnel record no longer exists. Reassign the appointment before approving it.'], 409);
            }
            $conflictStmt = $conn->prepare("SELECT appointment_time, duration_minutes FROM appointments WHERE assigned_to_user_id = ? AND appointment_date = ? AND is_archived = 0 AND status IN ('Pending', 'Approved') AND id <> ?");
            $conflictStmt->bind_param('isi', $currentAppointment['assigned_to_user_id'], $currentAppointment['appointment_date'], $appointmentId);
            $conflictStmt->execute();
            $conflicts = $conflictStmt->get_result();
            $currentStart = strtotime($currentAppointment['appointment_date'] . ' ' . $currentAppointment['appointment_time']);
            $currentEnd = $currentStart + max(15, (int)$currentAppointment['duration_minutes']) * 60;
            while ($busy = $conflicts->fetch_assoc()) {
                $busyStart = strtotime($currentAppointment['appointment_date'] . ' ' . $busy['appointment_time']);
                $busyEnd = $busyStart + max(15, (int)$busy['duration_minutes']) * 60;
                if ($currentStart < $busyEnd && $currentEnd > $busyStart) {
                    $conflictStmt->close();
                    $conn->rollback();
                    $jsonResponse(['success' => false, 'message' => 'Scheduling conflict: the assigned personnel member is already booked during this time.'], 409);
                }
            }
            $conflictStmt->close();
        }
        if ($previousStatus !== $newStatus) {
            $stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE id = ? AND is_archived = 0');
            $stmt->bind_param('si', $newStatus, $appointmentId);
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'appointments', 'Appointment status changed from ' . $previousStatus . ' to ' . $newStatus . '.', $appointmentId);
        }
        if ($scheduleTransactionStarted) {
            $conn->commit();
        }
        $jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$changed) {
        $jsonResponse(['success' => false, 'message' => 'The appointment could not be updated. Refresh and try again.'], 409);
    }
    logActivity($conn, $activity, 'appointments', $message, $appointmentId);
    $jsonResponse(['success' => true, 'message' => $message]);
}

$where = [];
$params = [];
$types = '';
$where[] = 'a.is_archived = ?';
$params[] = $appointmentView === 'archived' ? 1 : 0;
$types .= 'i';

if ($search !== '') {
    $where[] = '(a.resident_name LIKE ? OR a.purpose LIKE ? OR a.id = ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = ctype_digit($search) ? (int)$search : 0;
    $types .= 'ssi';
}
if ($statusFilter !== '') {
    if ($statusFilter === 'overdue') {
        $where[] = "a.status IN ('Pending', 'Approved') AND TIMESTAMP(a.appointment_date, a.appointment_time) < NOW()";
    } else {
        $where[] = 'a.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
}
if ($purposeFilter !== '') {
    $where[] = 'a.purpose = ?';
    $params[] = $purposeFilter;
    $types .= 's';
}
if ($assignedFilter > 0) {
    $where[] = 'a.assigned_to_user_id = ?';
    $params[] = $assignedFilter;
    $types .= 'i';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'a.appointment_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'a.appointment_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$listSql = "SELECT a.id, a.resident_id, a.resident_name, a.purpose, a.appointment_date, a.appointment_time,
                   a.duration_minutes, a.status, a.is_archived, a.archived_at, a.archived_by_user_id,
                   CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel
            FROM appointments a LEFT JOIN users u ON u.id = a.assigned_to_user_id"
    . $whereSql . ' ORDER BY a.appointment_date DESC, a.appointment_time DESC';
if ($params) {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $appointments = $stmt->get_result();
} else {
    $appointments = $conn->query($listSql);
}

$activeAppointments = (int)($conn->query('SELECT COUNT(*) FROM appointments WHERE is_archived = 0')->fetch_row()[0] ?? 0);
$archivedAppointments = (int)($conn->query('SELECT COUNT(*) FROM appointments WHERE is_archived = 1')->fetch_row()[0] ?? 0);
$totalAppointments = $activeAppointments;
$pendingAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending' AND is_archived = 0")->fetch_row()[0] ?? 0);
$approvedAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Approved' AND is_archived = 0")->fetch_row()[0] ?? 0);
$completedAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Completed' AND is_archived = 0")->fetch_row()[0] ?? 0);
$cancelledAppointments = (int)($conn->query("SELECT COUNT(*) FROM appointments WHERE status = 'Cancelled' AND is_archived = 0")->fetch_row()[0] ?? 0);
$manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$manilaNowValue = $manilaNow->format('Y-m-d H:i:s');
$overdueStmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE is_archived = 0 AND status IN ('Pending', 'Approved') AND TIMESTAMP(appointment_date, appointment_time) < ?");
$overdueStmt->bind_param('s', $manilaNowValue);
$overdueStmt->execute();
$overdueAppointments = (int)($overdueStmt->get_result()->fetch_row()[0] ?? 0);
$overdueStmt->close();
$purposeOptions = $conn->query("SELECT DISTINCT purpose FROM appointments WHERE purpose IS NOT NULL AND TRIM(purpose) <> '' ORDER BY purpose")->fetch_all(MYSQLI_ASSOC);
$personnelOptions = $conn->query("SELECT DISTINCT u.id, u.first_name, u.last_name, u.position FROM users u INNER JOIN appointments a ON a.assigned_to_user_id = u.id WHERE u.role IN ('staff', 'health_worker', 'security_force') ORDER BY u.first_name, u.last_name")->fetch_all(MYSQLI_ASSOC);

function appointmentStatusClass(string $status): string {
    return match (strtolower(trim($status))) {
        'pending' => 'pending',
        'approved' => 'approved',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        'rejected' => 'rejected',
        'no show' => 'no-show',
        default => 'unknown',
    };
}

function formatTime($time) {
    if (!$time) {
        return '';
    }
    $timestamp = strtotime($time);
    return $timestamp ? date('g:i A', $timestamp) : $time;
}

$today = $manilaNow->format('Y-m-d');
$todayStmt = $conn->prepare("SELECT id, resident_name, purpose, appointment_time, status FROM appointments WHERE is_archived = 0 AND appointment_date = ? AND status IN ('Pending', 'Approved') ORDER BY appointment_time ASC");
$todayStmt->bind_param('s', $today);
$todayStmt->execute();
$todaySchedule = $todayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$todayStmt->close();
$nextAppointment = null;
if ($todaySchedule === []) {
    $nextStmt = $conn->prepare("SELECT id, resident_name, purpose, appointment_date, appointment_time, status FROM appointments WHERE is_archived = 0 AND status IN ('Pending', 'Approved') AND TIMESTAMP(appointment_date, appointment_time) > ? ORDER BY appointment_date, appointment_time LIMIT 1");
    $nextStmt->bind_param('s', $manilaNowValue);
    $nextStmt->execute();
    $nextAppointment = $nextStmt->get_result()->fetch_assoc() ?: null;
    $nextStmt->close();
}
$calendarStart = $manilaNow->modify('first day of this month')->modify('-12 months')->format('Y-m-d');
$calendarEnd = $manilaNow->modify('first day of this month')->modify('+13 months')->format('Y-m-d');
$calendarStmt = $conn->prepare("SELECT id, appointment_date, appointment_time, resident_name, purpose, status FROM appointments WHERE is_archived = 0 AND appointment_date >= ? AND appointment_date < ? AND status IN ('Pending', 'Approved') ORDER BY appointment_date, appointment_time LIMIT 2000");
$calendarStmt->bind_param('ss', $calendarStart, $calendarEnd);
$calendarStmt->execute();
$calendarRows = $calendarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$calendarStmt->close();
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

        <?php renderTopbar('Appointments', 'Manage Resident Appointments', 'compact', ['clock' => true, 'dashboardHeader' => true]); ?>

        <?php renderNotice([
            'created' => 'Appointment scheduled.',
            'updated' => 'Appointment updated.',
            'delete_disabled' => 'Deleting appointment records is disabled. You can view or edit the record instead.',
            'archive_success' => 'Appointment archived.',
            'restore_success' => 'Appointment restored.',
            'action_error' => 'The appointment could not be updated. Refresh the page and try again.',
            'archived_read_only' => 'Archived appointments are read-only. Restore the appointment before editing it.',
        ]); ?>

        <!-- Cards -->

        <div class="row g-4 appointment-statistics">

            <div class="col-sm-6 col-lg-2-4">

                <div class="stat-card">

                    <i class="fa-solid fa-calendar-days"></i>

                    <h4>Total Appointments</h4>

                    <h2><?php echo $totalAppointments; ?></h2>

                </div>

            </div>

            <div class="col-sm-6 col-lg-2-4">

                <div class="stat-card">

                    <i class="fa-solid fa-clock"></i>

                    <h4>Pending</h4>

                    <h2><?php echo $pendingAppointments; ?></h2>

                </div>

            </div>

            <div class="col-sm-6 col-lg-2-4">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Approved</h4>

                    <h2><?php echo $approvedAppointments; ?></h2>

                </div>

            </div>

            <div class="col-sm-6 col-lg-2-4">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Completed</h4>

                    <h2><?php echo $completedAppointments; ?></h2>

                </div>

            </div>

            <div class="col-sm-6 col-lg-2-4">

                <div class="stat-card stat-card-cancelled">
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

                    <nav class="appointment-tabs" aria-label="Appointment records">
                        <a class="appointment-tab<?php echo $appointmentView === 'active' ? ' is-active' : ''; ?>" href="appointments.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'purpose' => $purposeFilter, 'assigned_to' => $assignedFilter ?: '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) + ['view' => 'active', 'display' => $displayMode])); ?>"<?php echo $appointmentView === 'active' ? ' aria-current="page"' : ''; ?>>
                            <span><i class="fa-solid fa-inbox" aria-hidden="true"></i> Active Appointments</span><strong><?php echo $activeAppointments; ?></strong>
                        </a>
                        <a class="appointment-tab<?php echo $appointmentView === 'archived' ? ' is-active' : ''; ?>" href="appointments.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'purpose' => $purposeFilter, 'assigned_to' => $assignedFilter ?: '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) + ['view' => 'archived', 'display' => $displayMode])); ?>"<?php echo $appointmentView === 'archived' ? ' aria-current="page"' : ''; ?>>
                            <span><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archived</span><strong><?php echo $archivedAppointments; ?></strong>
                        </a>
                    </nav>

                    <div class="appointment-view-switch" role="group" aria-label="Appointment display mode">
                        <a class="<?php echo $displayMode === 'list' ? 'is-active' : ''; ?>" href="appointments.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'purpose' => $purposeFilter, 'assigned_to' => $assignedFilter ?: '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) + ['view' => $appointmentView, 'display' => 'list'])); ?>"><i class="fa-solid fa-list" aria-hidden="true"></i> List</a>
                        <a class="<?php echo $displayMode === 'calendar' ? 'is-active' : ''; ?>" href="appointments.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'purpose' => $purposeFilter, 'assigned_to' => $assignedFilter ?: '', 'date_from' => $dateFrom, 'date_to' => $dateTo]) + ['view' => $appointmentView, 'display' => 'calendar'])); ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Calendar</a>
                    </div>

                    <form class="filters" method="get" action="appointments.php">
                        <input type="hidden" name="view" value="<?php echo h($appointmentView); ?>">
                        <input type="hidden" name="display" value="<?php echo h($displayMode); ?>">

                        <label for="searchAppointment" class="visually-hidden">
                            Search Appointment
                        </label>

                        <input
                            id="searchAppointment"
                            name="search"
                            type="search"
                            class="form-control"
                            value="<?php echo h($search); ?>"
                            placeholder="Search resident or appointment ID...">

                        <label for="statusFilter" class="visually-hidden">
                            Filter by Status
                        </label>

                        <select id="statusFilter" name="status" class="form-select">

                            <option value="">All Status</option>
                            <option value="overdue"<?php echo $statusFilter === 'overdue' ? ' selected' : ''; ?>>Overdue</option>
                            <option value="Pending"<?php echo $statusFilter === 'Pending' ? ' selected' : ''; ?>>Pending</option>
                            <option value="Approved"<?php echo $statusFilter === 'Approved' ? ' selected' : ''; ?>>Approved</option>
                            <option value="Completed"<?php echo $statusFilter === 'Completed' ? ' selected' : ''; ?>>Completed</option>
                            <option value="Cancelled"<?php echo $statusFilter === 'Cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                            <option value="Rejected"<?php echo $statusFilter === 'Rejected' ? ' selected' : ''; ?>>Rejected</option>
                            <option value="No Show"<?php echo $statusFilter === 'No Show' ? ' selected' : ''; ?>>No Show</option>

                        </select>

                        <label class="visually-hidden" for="purposeFilter">Filter by purpose</label>
                        <select id="purposeFilter" name="purpose" class="form-select">
                            <option value="">All Purposes</option>
                            <?php foreach ($purposeOptions as $option): ?>
                                <option value="<?php echo h($option['purpose']); ?>"<?php echo $purposeFilter === $option['purpose'] ? ' selected' : ''; ?>><?php echo h($option['purpose']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="visually-hidden" for="assignedFilter">Filter by assigned personnel</label>
                        <select id="assignedFilter" name="assigned_to" class="form-select">
                            <option value="">All Personnel</option>
                            <?php foreach ($personnelOptions as $personnel): ?>
                                <option value="<?php echo (int)$personnel['id']; ?>"<?php echo $assignedFilter === (int)$personnel['id'] ? ' selected' : ''; ?>><?php echo h(trim($personnel['first_name'] . ' ' . $personnel['last_name']) . ($personnel['position'] ? ' · ' . $personnel['position'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="visually-hidden" for="dateFrom">Date from</label>
                        <input type="date" id="dateFrom" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>" aria-label="From date">
                        <label class="visually-hidden" for="dateTo">Date to</label>
                        <input type="date" id="dateTo" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>" aria-label="To date">

                        <button type="submit" class="btn btn-primary">

                            <i class="fa-solid fa-magnifying-glass"></i>

                            Search

                        </button>

                    </form>

                    <div class="table-responsive appointment-table-wrap"<?php echo $displayMode === 'calendar' ? ' hidden' : ''; ?>>
                    <table class="table appointment-table">

                        <thead>

                            <tr>

                                <th>ID</th>

                                <th>Resident</th>

                                <th>Purpose</th>

                                <th>Date</th>

                                <th>Time</th>

                                <th>Assigned To</th>

                                <th>Status</th>

                                <th>Action</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($appointments->num_rows === 0): ?>

                                <tr>
                                    <td colspan="8">No appointments found.</td>
                                </tr>

                            <?php else: ?>

                                <?php while ($row = $appointments->fetch_assoc()): ?>

                                    <tr>

                                        <td><?php echo h(str_pad((string)$row['id'], 3, '0', STR_PAD_LEFT)); ?></td>

                                        <td><?php echo h($row['resident_name']); ?></td>

                                        <td><?php echo h($row['purpose']); ?></td>

                                        <td><?php echo h($row['appointment_date']); ?></td>

                                        <td><?php echo h(formatTime($row['appointment_time'])); ?></td>
                                        <td><?php echo h($row['assigned_personnel']); ?></td>

                                        <td>
                                            <span class="appointment-status-badge <?php echo h(appointmentStatusClass((string)$row['status'])); ?>">
                                                <?php echo h($row['status']); ?>
                                            </span>
                                            <?php if (in_array($row['status'], ['Pending', 'Approved'], true) && strtotime($row['appointment_date'] . ' ' . $row['appointment_time']) < time()): ?>
                                                <span class="appointment-overdue-badge">Overdue</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>

                                            <div class="appointment-actions">
                                                <button type="button" class="appointment-action appointment-details-trigger" data-appointment-id="<?php echo (int)$row['id']; ?>"><i class="fa-regular fa-eye" aria-hidden="true"></i> View</button>
                                                <?php if ($appointmentView === 'archived'): ?>
                                                    <button type="button" class="appointment-action is-restore" data-appointment-action="restore_appointment" data-appointment-id="<?php echo (int)$row['id']; ?>"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button>
                                                <?php else: ?>
                                                    <a href="appointment_form.php?id=<?php echo (int)$row['id']; ?>&amp;edit=1" class="appointment-action"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit</a>
                                                    <?php if (in_array($row['status'], $archivableStatuses, true)): ?>
                                                        <button type="button" class="appointment-action is-archive" data-appointment-action="archive_appointment" data-appointment-id="<?php echo (int)$row['id']; ?>"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
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
                    <section class="appointment-calendar" id="appointmentCalendar" aria-label="Monthly appointment calendar"<?php echo $displayMode === 'calendar' ? '' : ' hidden'; ?>>
                        <div class="calendar-toolbar">
                            <button type="button" id="calendarPrevious" aria-label="Previous month"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                            <h4 id="calendarMonthTitle"></h4>
                            <button type="button" id="calendarNext" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                        </div>
                        <div class="calendar-grid" id="calendarGrid"></div>
                    </section>

                </div>

            </div>

            <!-- Right Panel -->

            <div class="col-lg-4">

                <div class="content-card">

                    <h3>Today's Schedule</h3>

                    <?php if ($todaySchedule === []): ?>

                        <p class="schedule-empty">No appointments scheduled today.</p>
                        <?php if ($nextAppointment): ?>
                            <div class="next-appointment-card">
                                <span><i class="fa-solid fa-forward" aria-hidden="true"></i> NEXT APPOINTMENT</span>
                                <strong><?php echo h(date('F j, Y', strtotime($nextAppointment['appointment_date']))); ?> · <?php echo h(formatTime($nextAppointment['appointment_time'])); ?></strong>
                                <p><?php echo h($nextAppointment['resident_name']); ?> · <?php echo h($nextAppointment['purpose']); ?></p>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>

                        <?php foreach ($todaySchedule as $item): ?>

                            <button type="button" class="schedule-box appointment-details-trigger" data-appointment-id="<?php echo (int)$item['id']; ?>">

                                <span class="schedule-time"><?php echo h(formatTime($item['appointment_time'])); ?></span>

                                <span class="schedule-resident"><?php echo h($item['resident_name']); ?></span>

                                <small><?php echo h($item['purpose']); ?> · <?php echo h($item['status']); ?></small>

                            </button>

                        <?php endforeach; ?>

                    <?php endif; ?>
                    <a class="schedule-full-link" href="appointments.php?display=calendar&view=active"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> View Full Calendar</a>

                    <?php if ($overdueAppointments > 0): ?>
                        <a class="overdue-panel" href="appointments.php?status=overdue&view=active">
                            <span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Overdue · Action Required</span>
                            <strong><?php echo $overdueAppointments; ?></strong>
                            <small>Appointment(s) need staff review. Status is not changed automatically.</small>
                        </a>
                    <?php endif; ?>

                </div>

            </div>

        </div>

    </main>

</div>

<dialog class="appointment-details-dialog" id="appointmentDetailsDialog" aria-labelledby="appointmentDetailsTitle">
    <header class="appointment-dialog-header">
        <div><span>APPOINTMENT RECORD</span><h2 id="appointmentDetailsTitle">Appointment details</h2></div>
        <button type="button" class="appointment-dialog-close" data-close-appointment-dialog aria-label="Close details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </header>
    <p id="appointmentDetailsMessage" role="status" aria-live="polite"></p>
    <div class="appointment-dialog-body">
        <section class="appointment-detail-grid">
            <div><span>Appointment ID</span><strong id="appointmentDetailId"></strong></div>
            <div><span>Status</span><strong id="appointmentDetailStatus"></strong></div>
            <div><span>Resident</span><strong id="appointmentDetailResident"></strong></div>
            <div><span>Purpose</span><strong id="appointmentDetailPurpose"></strong></div>
            <div><span>Scheduled date</span><strong id="appointmentDetailDate"></strong></div>
            <div><span>Time</span><strong id="appointmentDetailTime"></strong></div>
            <div><span>Duration</span><strong id="appointmentDetailDuration"></strong></div>
            <div><span>Assigned personnel</span><strong id="appointmentDetailAssignee"></strong></div>
            <div class="wide"><span>Notes</span><strong id="appointmentDetailNotes"></strong></div>
            <div class="wide" id="appointmentArchivedAtRow" hidden><span>Archived</span><strong id="appointmentDetailArchivedAt"></strong></div>
            <div class="wide" id="appointmentArchivedByRow" hidden><span>Archived by</span><strong id="appointmentDetailArchivedBy"></strong></div>
        </section>
        <form class="appointment-status-form" id="appointmentStatusForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_appointment_status">
            <input type="hidden" name="appointment_id" id="appointmentStatusId">
            <label for="appointmentStatusSelect">Update status</label>
            <div><select id="appointmentStatusSelect" name="status"><?php foreach ($statusOptions as $option): ?><option value="<?php echo h($option); ?>"><?php echo h($option); ?></option><?php endforeach; ?></select><button type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Status</button></div>
        </form>
        <section class="appointment-timeline"><h3>Appointment History</h3><ol id="appointmentTimelineList"></ol></section>
    </div>
    <footer class="appointment-dialog-actions">
        <a id="appointmentDetailEdit" href="#" class="appointment-action"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit / Reschedule</a>
        <button id="appointmentDetailArchive" type="button" class="appointment-action is-archive" hidden><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
        <button id="appointmentDetailRestore" type="button" class="appointment-action is-restore" hidden><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button>
        <button type="button" class="appointment-action" data-close-appointment-dialog>Close</button>
    </footer>
</dialog>

<dialog class="appointment-confirm-dialog" id="appointmentConfirmDialog" aria-labelledby="appointmentConfirmTitle" data-csrf-token="<?php echo h(csrfToken()); ?>">
    <div class="appointment-confirm-icon" id="appointmentConfirmIcon"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></div>
    <span>APPOINTMENT RECORD</span>
    <h2 id="appointmentConfirmTitle">Archive this appointment?</h2>
    <p id="appointmentConfirmMessage">You can restore this appointment later.</p>
    <div class="appointment-confirm-actions"><button type="button" id="appointmentConfirmCancel">Cancel</button><button type="button" id="appointmentConfirmSubmit" class="is-archive"><i class="fa-solid fa-box-archive" aria-hidden="true"></i><span>Archive</span></button></div>
</dialog>

<?php renderFooterScripts(); ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const appointmentDetails = document.getElementById('appointmentDetailsDialog');
        const appointmentConfirm = document.getElementById('appointmentConfirmDialog');
        const detailsMessage = document.getElementById('appointmentDetailsMessage');
        const confirmMessage = document.getElementById('appointmentConfirmMessage');
        const confirmSubmit = document.getElementById('appointmentConfirmSubmit');
        const statusForm = document.getElementById('appointmentStatusForm');
        const archiveButton = document.getElementById('appointmentDetailArchive');
        const restoreButton = document.getElementById('appointmentDetailRestore');
        const manilaDateFormatter = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            dateStyle: 'medium',
            timeStyle: 'short'
        });
        let pendingAction = null;

        const setText = function (id, value) {
            document.getElementById(id).textContent = value || 'Not recorded';
        };

        const sendAction = async function (formData) {
            const response = await fetch('appointments.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not update the appointment.');
            }
            return result;
        };

        const openDetails = async function (appointmentId) {
            detailsMessage.textContent = 'Loading appointment details…';
            document.getElementById('appointmentTimelineList').replaceChildren();
            appointmentDetails.showModal();
            try {
                const response = await fetch('appointments.php?ajax=details&id=' + encodeURIComponent(appointmentId), {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Could not load appointment details.');
                const record = result.record;
                setText('appointmentDetailId', 'APT-' + String(record.id).padStart(4, '0'));
                setText('appointmentDetailStatus', record.status);
                setText('appointmentDetailResident', record.linked_resident_name);
                setText('appointmentDetailPurpose', record.purpose);
                setText('appointmentDetailDate', record.appointment_date);
                setText('appointmentDetailTime', record.appointment_time ? new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(new Date('2000-01-01T' + record.appointment_time)) : '');
                setText('appointmentDetailDuration', String(record.duration_minutes || 30) + ' minutes');
                setText('appointmentDetailAssignee', record.assigned_personnel + (record.assigned_position ? ' · ' + record.assigned_position : ''));
                setText('appointmentDetailNotes', record.notes);
                document.getElementById('appointmentStatusId').value = record.id;
                document.getElementById('appointmentStatusSelect').value = record.status;
                statusForm.hidden = Boolean(Number(record.is_archived));
                document.getElementById('appointmentDetailEdit').href = 'appointment_form.php?id=' + encodeURIComponent(record.id) + '&edit=1';
                document.getElementById('appointmentDetailEdit').hidden = Boolean(Number(record.is_archived));
                archiveButton.hidden = !record.can_archive;
                restoreButton.hidden = !Number(record.is_archived);
                document.getElementById('appointmentArchivedAtRow').hidden = !Number(record.is_archived);
                document.getElementById('appointmentArchivedByRow').hidden = !Number(record.is_archived);
                setText('appointmentDetailArchivedAt', record.archived_at);
                setText('appointmentDetailArchivedBy', record.archived_by_name);
                const timeline = document.getElementById('appointmentTimelineList');
                record.timeline.forEach(function (event) {
                    const item = document.createElement('li');
                    const activity = document.createElement('strong');
                    const date = document.createElement('time');
                    activity.textContent = event.activity;
                    if (event.date) {
                        const utcDate = event.date.replace(' ', 'T') + 'Z';
                        const parsedDate = new Date(utcDate);
                        date.textContent = Number.isNaN(parsedDate.getTime()) ? event.date : manilaDateFormatter.format(parsedDate);
                        date.dateTime = utcDate;
                    } else {
                        date.textContent = 'Date not recorded';
                    }
                    item.append(activity, date);
                    if (event.updated_by) {
                        const actor = document.createElement('span');
                        actor.textContent = 'Updated by ' + event.updated_by;
                        item.append(actor);
                    }
                    timeline.append(item);
                });
                detailsMessage.textContent = 'Appointment history includes recorded staff actions.';
                appointmentDetails.dataset.appointmentId = String(record.id);
            } catch (error) {
                console.error('Could not load appointment details:', error);
                detailsMessage.textContent = error.message;
            }
        };

        const showConfirmation = function (action, appointmentId) {
            pendingAction = {action: action, appointmentId: appointmentId};
            const isArchive = action === 'archive_appointment';
            document.getElementById('appointmentConfirmIcon').classList.toggle('is-archive', isArchive);
            document.getElementById('appointmentConfirmTitle').textContent = isArchive ? 'Archive this appointment?' : 'Restore this appointment?';
            confirmMessage.textContent = isArchive
                ? 'This appointment will move to Archived. You can restore it later.'
                : 'The appointment will return to Active Appointments without changing its status.';
            confirmSubmit.classList.toggle('is-archive', isArchive);
            confirmSubmit.querySelector('i').className = isArchive ? 'fa-solid fa-box-archive' : 'fa-solid fa-rotate-left';
            confirmSubmit.querySelector('span').textContent = isArchive ? 'Archive' : 'Restore';
            confirmSubmit.disabled = false;
            appointmentConfirm.showModal();
            document.getElementById('appointmentConfirmCancel').focus();
        };

        document.addEventListener('click', function (event) {
            const detailsTrigger = event.target.closest('.appointment-details-trigger');
            if (detailsTrigger) {
                openDetails(detailsTrigger.dataset.appointmentId);
                return;
            }
            const actionTrigger = event.target.closest('[data-appointment-action]');
            if (actionTrigger) {
                showConfirmation(actionTrigger.dataset.appointmentAction, actionTrigger.dataset.appointmentId);
                return;
            }
            if (event.target.closest('#appointmentDetailArchive')) {
                showConfirmation('archive_appointment', appointmentDetails.dataset.appointmentId);
                return;
            }
            if (event.target.closest('#appointmentDetailRestore')) {
                showConfirmation('restore_appointment', appointmentDetails.dataset.appointmentId);
                return;
            }
            if (event.target.closest('[data-close-appointment-dialog]')) appointmentDetails.close();
        });

        statusForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            try {
                await sendAction(new FormData(statusForm));
                window.location.reload();
            } catch (error) {
                detailsMessage.textContent = error.message;
            }
        });

        document.getElementById('appointmentConfirmCancel').addEventListener('click', function () {
            pendingAction = null;
            appointmentConfirm.close();
        });

        confirmSubmit.addEventListener('click', async function () {
            if (!pendingAction) return;
            confirmSubmit.disabled = true;
            confirmMessage.textContent = 'Saving your changes…';
            const formData = new FormData();
            formData.set('action', pendingAction.action);
            formData.set('appointment_id', pendingAction.appointmentId);
            formData.set('csrf_token', appointmentConfirm.dataset.csrfToken || '');
            const completedAction = pendingAction.action;
            try {
                await sendAction(formData);
                const destination = new URL(window.location.href);
                destination.searchParams.set('view', completedAction === 'archive_appointment' ? 'archived' : 'active');
                destination.searchParams.set('msg', completedAction === 'archive_appointment' ? 'archive_success' : 'restore_success');
                destination.searchParams.delete('page');
                window.location.assign(destination.toString());
            } catch (error) {
                confirmMessage.textContent = error.message;
                confirmSubmit.disabled = false;
            }
        });

        appointmentConfirm.addEventListener('click', function (event) {
            if (event.target === appointmentConfirm) {
                pendingAction = null;
                appointmentConfirm.close();
            }
        });
        appointmentDetails.addEventListener('click', function (event) {
            if (event.target === appointmentDetails) appointmentDetails.close();
        });

        const calendarGrid = document.getElementById('calendarGrid');
        if (calendarGrid) {
            const events = <?php echo json_encode($calendarRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const monthTitle = document.getElementById('calendarMonthTitle');
            const cursor = new Date();
            cursor.setDate(1);
            const renderCalendar = function () {
                const year = cursor.getFullYear();
                const month = cursor.getMonth();
                monthTitle.textContent = new Intl.DateTimeFormat(undefined, {month: 'long', year: 'numeric'}).format(cursor);
                calendarGrid.replaceChildren();
                ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (day) {
                    const heading = document.createElement('strong');
                    heading.className = 'calendar-weekday';
                    heading.textContent = day;
                    calendarGrid.append(heading);
                });
                const offset = new Date(year, month, 1).getDay();
                for (let blank = 0; blank < offset; blank++) {
                    const cell = document.createElement('div');
                    cell.className = 'calendar-day is-empty';
                    calendarGrid.append(cell);
                }
                const days = new Date(year, month + 1, 0).getDate();
                for (let day = 1; day <= days; day++) {
                    const key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                    const cell = document.createElement('div');
                    cell.className = 'calendar-day';
                    const date = document.createElement('span');
                    date.className = 'calendar-date';
                    date.textContent = String(day);
                    cell.append(date);
                    events.filter(function (item) { return item.appointment_date === key; }).forEach(function (item) {
                        const appointment = document.createElement('button');
                        appointment.type = 'button';
                        appointment.className = 'calendar-event ' + (item.status === 'Approved' ? 'is-approved' : 'is-pending');
                        appointment.dataset.appointmentId = item.id;
                        appointment.textContent = formatTime(item.appointment_time) + ' · ' + item.resident_name;
                        appointment.addEventListener('click', function () { openDetails(item.id); });
                        cell.append(appointment);
                    });
                    calendarGrid.append(cell);
                }
            };
            const formatTime = function (time) {
                if (!time) return '';
                const date = new Date('2000-01-01T' + time);
                return Number.isNaN(date.getTime()) ? time : new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(date);
            };
            document.getElementById('calendarPrevious').addEventListener('click', function () { cursor.setMonth(cursor.getMonth() - 1); renderCalendar(); });
            document.getElementById('calendarNext').addEventListener('click', function () { cursor.setMonth(cursor.getMonth() + 1); renderCalendar(); });
            renderCalendar();
        }
    });
</script>

</body>

</html>
