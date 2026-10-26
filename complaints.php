<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$assignedFilter = filter_var($_GET['assigned_to'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$complaintView = in_array($_GET['view'] ?? 'active', ['active', 'archived'], true) ? $_GET['view'] : 'active';
$statusOptions = ['Pending', 'Ongoing', 'Resolved'];
$archivableStatuses = ['Resolved'];
$jsonResponse = static function (array $payload, int $status = 200): never {
    $action = (string)($_POST['action'] ?? '');
    $isComplaintAction = $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($action, ['archive_complaint', 'restore_complaint'], true);
    $isAjaxRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isComplaintAction && !$isAjaxRequest) {
        $succeeded = $status < 400 && !empty($payload['success']);
        $message = $succeeded
            ? ($action === 'archive_complaint' ? 'archive_success' : 'restore_success')
            : 'action_error';
        $view = $action === 'archive_complaint' ? 'archived' : 'active';
        header('Location: complaints.php?' . http_build_query(['view' => $view, 'msg' => $message]), true, 303);
        exit;
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'details') {
    $complaintId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$complaintId || $complaintId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid complaint record.'], 400);
    }
    $stmt = $conn->prepare(
        "SELECT c.id, c.tracking_number, c.resident_name, c.complainee_name, c.category, c.status, c.date_filed,
                c.description, c.response, c.resolution_remarks, c.is_archived, c.archived_at,
                c.assigned_to_user_id,
                CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel,
                u.position AS assigned_position
         FROM complaints c
         LEFT JOIN users u ON u.id = c.assigned_to_user_id
         WHERE c.id = ?"
    );
    $stmt->bind_param('i', $complaintId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$record) {
        $jsonResponse(['success' => false, 'message' => 'Complaint record not found.'], 404);
    }

    $timeline = [];
    $hasSubmission = false;
    $historyStmt = $conn->prepare(
        "SELECT a.action, a.details, a.created_at,
                CASE WHEN u.id IS NULL THEN '' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS updated_by
         FROM activity_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity = 'complaints' AND a.related_id = ?
         ORDER BY a.id ASC"
    );
    $historyStmt->bind_param('i', $complaintId);
    $historyStmt->execute();
    $history = $historyStmt->get_result();
    while ($event = $history->fetch_assoc()) {
        $details = (string)($event['details'] ?? '');
        $activity = '';
        if ($event['action'] === 'created') {
            $activity = 'Complaint submitted';
            $hasSubmission = true;
        } elseif (preg_match('/Complaint status changed from .+ to (.+)\.$/i', $details, $matches)) {
            $activity = 'Status changed to ' . trim($matches[1]);
        } elseif ($event['action'] === 'assigned') {
            $activity = $details;
        } elseif ($event['action'] === 'resolved') {
            $activity = 'Resolution details recorded';
        } elseif ($event['action'] === 'archived') {
            $activity = 'Complaint archived';
        } elseif ($event['action'] === 'restored') {
            $activity = 'Complaint restored';
        } elseif ($event['action'] === 'updated') {
            $activity = 'Complaint record updated';
        }
        if ($activity !== '') {
            $timeline[] = ['activity' => $activity, 'date' => $event['created_at'], 'updated_by' => $event['updated_by']];
        }
    }
    $historyStmt->close();
    if (!$hasSubmission) {
        array_unshift($timeline, [
            'activity' => 'Complaint submitted (date recorded)',
            'date' => $record['date_filed'] ?: null,
            'updated_by' => '',
        ]);
    }
    $record['timeline'] = $timeline;
    $record['can_archive'] = !$record['is_archived'] && in_array($record['status'], $archivableStatuses, true);
    $jsonResponse(['success' => true, 'record' => $record]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_complaint', 'restore_complaint'], true)) {
    $action = (string)$_POST['action'];
    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$complaintId || $complaintId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid complaint record.'], 400);
    }
    $stmt = $conn->prepare('SELECT status, is_archived FROM complaints WHERE id = ?');
    $stmt->bind_param('i', $complaintId);
    $stmt->execute();
    $currentComplaint = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$currentComplaint) {
        $jsonResponse(['success' => false, 'message' => 'Complaint record not found.'], 404);
    }

    if ($action === 'archive_complaint') {
        if ($currentComplaint['is_archived'] || !in_array($currentComplaint['status'], $archivableStatuses, true)) {
            $jsonResponse(['success' => false, 'message' => 'Only resolved complaints can be archived.'], 409);
        }
        $stmt = $conn->prepare('UPDATE complaints SET is_archived = 1, archived_at = NOW() WHERE id = ? AND is_archived = 0 AND status = ?');
        $stmt->bind_param('is', $complaintId, $currentComplaint['status']);
        $activity = 'archived';
        $message = 'Complaint archived.';
    } else {
        if (!$currentComplaint['is_archived']) {
            $jsonResponse(['success' => false, 'message' => 'This complaint is already active.'], 409);
        }
        $stmt = $conn->prepare('UPDATE complaints SET is_archived = 0, archived_at = NULL WHERE id = ? AND is_archived = 1');
        $stmt->bind_param('i', $complaintId);
        $activity = 'restored';
        $message = 'Complaint restored.';
    }
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$changed) {
        $jsonResponse(['success' => false, 'message' => 'The complaint could not be updated. Refresh and try again.'], 409);
    }
    logActivity($conn, $activity, 'complaints', $message, $complaintId);
    $jsonResponse(['success' => true, 'message' => $message]);
}

$where = [];
$params = [];
$types = '';
$where[] = 'c.is_archived = ?';
$params[] = $complaintView === 'archived' ? 1 : 0;
$types .= 'i';

if ($search !== '') {
    $where[] = '(c.tracking_number LIKE ? OR c.resident_name LIKE ? OR c.complainee_name LIKE ? OR c.category LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
if ($statusFilter !== '') {
    if ($statusFilter === 'unresolved') {
        $where[] = "c.status IN ('Pending', 'Ongoing')";
    } else {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
}
if ($categoryFilter !== '') {
    $where[] = 'c.category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}
if ($assignedFilter > 0) {
    $where[] = 'c.assigned_to_user_id = ?';
    $params[] = $assignedFilter;
    $types .= 'i';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$listSql = "SELECT c.id, c.tracking_number, c.resident_name, c.complainee_name, c.category, c.status, c.date_filed,
                   c.is_archived, c.archived_at, c.assigned_to_user_id,
                   CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel
            FROM complaints c
            LEFT JOIN users u ON u.id = c.assigned_to_user_id" . $whereSql . ' ORDER BY c.id DESC';
if ($params) {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $complaints = $stmt->get_result();
} else {
    $complaints = $conn->query($listSql);
}

$activeComplaints = (int)($conn->query('SELECT COUNT(*) FROM complaints WHERE is_archived = 0')->fetch_row()[0] ?? 0);
$archivedComplaints = (int)($conn->query('SELECT COUNT(*) FROM complaints WHERE is_archived = 1')->fetch_row()[0] ?? 0);
$totalComplaints = $activeComplaints;
$pendingComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Pending' AND is_archived = 0")->fetch_row()[0] ?? 0);
$resolvedComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Resolved' AND is_archived = 0")->fetch_row()[0] ?? 0);
$ongoingComplaints = (int)($conn->query("SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing' AND is_archived = 0")->fetch_row()[0] ?? 0);
$categoryOptions = $conn->query("SELECT DISTINCT category FROM complaints WHERE category IS NOT NULL AND TRIM(category) <> '' ORDER BY category")->fetch_all(MYSQLI_ASSOC);
$personnelOptions = $conn->query("SELECT DISTINCT u.id, u.first_name, u.last_name, u.position FROM users u INNER JOIN complaints c ON c.assigned_to_user_id = u.id WHERE u.role IN ('staff', 'health_worker', 'security_force') ORDER BY u.first_name, u.last_name")->fetch_all(MYSQLI_ASSOC);

// Complaints that still need action; emergency alerts live on emergency.php.
$complaintAlerts = $conn->query("SELECT id, tracking_number, category, status, date_filed FROM complaints WHERE status IN ('Pending', 'Ongoing') AND is_archived = 0 ORDER BY date_filed ASC, id ASC LIMIT 5");
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
        <?php renderTopbar('Complaints & Alerts', 'Barangay Management System', 'compact', ['clock' => true, 'dashboardHeader' => true]); ?>

        <?php renderNotice([
            'created' => 'Complaint record created.',
            'updated' => 'Complaint record updated.',
            'delete_disabled' => 'Deleting complaint records is disabled. You can view or edit the record instead.',
            'archive_success' => 'Complaint archived.',
            'restore_success' => 'Complaint restored.',
            'action_error' => 'The complaint could not be updated. Refresh the page and try again.',
            'archived_read_only' => 'Archived complaints are read-only. Restore the complaint before editing it.',
        ]); ?>

        <!-- CARDS -->

        <div class="row g-4 complaint-statistics">

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

                    <i class="fa-solid fa-spinner"></i>

                    <h4>Ongoing</h4>

                    <h2><?php echo $ongoingComplaints; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-circle-check"></i>

                    <h4>Resolved</h4>

                    <h2><?php echo $resolvedComplaints; ?></h2>

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

                    <nav class="complaint-tabs" aria-label="Complaint records">
                        <a class="complaint-tab<?php echo $complaintView === 'active' ? ' is-active' : ''; ?>" href="complaints.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'category' => $categoryFilter, 'assigned_to' => $assignedFilter ?: '']) + ['view' => 'active'])); ?>"<?php echo $complaintView === 'active' ? ' aria-current="page"' : ''; ?>>
                            <span><i class="fa-solid fa-inbox" aria-hidden="true"></i> Active Complaints</span><strong><?php echo $activeComplaints; ?></strong>
                        </a>
                        <a class="complaint-tab<?php echo $complaintView === 'archived' ? ' is-active' : ''; ?>" href="complaints.php?<?php echo h(http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'category' => $categoryFilter, 'assigned_to' => $assignedFilter ?: '']) + ['view' => 'archived'])); ?>"<?php echo $complaintView === 'archived' ? ' aria-current="page"' : ''; ?>>
                            <span><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archived</span><strong><?php echo $archivedComplaints; ?></strong>
                        </a>
                    </nav>

                    <form class="filters complaint-filters" method="get" action="complaints.php">
                        <input type="hidden" name="view" value="<?php echo h($complaintView); ?>">

                        <label for="searchComplaint" class="visually-hidden">
                            Search tracking number, complainant, respondent, or category
                        </label>

                        <input type="text"
                            id="searchComplaint"
                            name="search"
                            class="form-control"
                            value="<?php echo h($search); ?>"
                            placeholder="Search tracking no., complainant, respondent...">

                        <label for="statusFilter" class="visually-hidden">
                            Filter complaints by status
                        </label>

                        <select id="statusFilter" name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="unresolved"<?php echo $statusFilter === 'unresolved' ? ' selected' : ''; ?>>Unresolved</option>
                            <option value="Pending"<?php echo $statusFilter === 'Pending' ? ' selected' : ''; ?>>Pending</option>
                            <option value="Ongoing"<?php echo $statusFilter === 'Ongoing' ? ' selected' : ''; ?>>Ongoing</option>
                            <option value="Resolved"<?php echo $statusFilter === 'Resolved' ? ' selected' : ''; ?>>Resolved</option>
                        </select>

                        <label for="categoryFilter" class="visually-hidden">Filter complaints by category</label>
                        <select id="categoryFilter" name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo h($option['category']); ?>"<?php echo $categoryFilter === $option['category'] ? ' selected' : ''; ?>><?php echo h($option['category']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="assignedFilter" class="visually-hidden">Filter complaints by assigned personnel</label>
                        <select id="assignedFilter" name="assigned_to" class="form-select">
                            <option value="">All Personnel</option>
                            <?php foreach ($personnelOptions as $personnel): ?>
                                <option value="<?php echo (int)$personnel['id']; ?>"<?php echo $assignedFilter === (int)$personnel['id'] ? ' selected' : ''; ?>>
                                    <?php echo h(trim($personnel['first_name'] . ' ' . $personnel['last_name']) . ($personnel['position'] ? ' · ' . $personnel['position'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            Filter
                        </button>

                    </form>

                    <div class="table-responsive complaint-table-wrap">
                    <table class="table align-middle complaint-table">

                        <thead>

                        <tr>

                            <th>#</th>

                            <th>Tracking No.</th>

                            <th>Complainant</th>

                            <th>Respondent</th>

                            <th>Category</th>

                            <th>Assigned To</th>

                            <th>Status</th>

                            <th>Date</th>

                            <th>Action</th>

                        </tr>

                        </thead>

                        <tbody>

                        <?php if ($complaints->num_rows === 0): ?>

                            <tr>
                                <td colspan="9"><?php echo $complaintView === 'archived' ? 'No archived complaints.' : 'No active complaints match your filters.'; ?></td>
                            </tr>

                        <?php else: $index = 1; ?>

                            <?php while ($row = $complaints->fetch_assoc()): ?>

                                <tr>

                                    <td><?php echo $index++; ?></td>

                                    <td><?php echo h($row['tracking_number']); ?></td>

                                    <td><?php echo h($row['resident_name']); ?></td>

                                    <td><?php echo h($row['complainee_name']); ?></td>

                                    <td><?php echo h($row['category']); ?></td>

                                    <td><?php echo h($row['assigned_personnel']); ?></td>

                                    <td>
                                        <span class="complaint-status-badge <?php echo strtolower(h($row['status'])); ?>">
                                            <?php echo h($row['status']); ?>
                                        </span>
                                    </td>

                                    <td><?php echo h($row['date_filed']); ?></td>

                                    <td>

                                        <div class="complaint-actions">
                                            <button type="button" class="complaint-action complaint-view-trigger" data-complaint-id="<?php echo (int)$row['id']; ?>"><i class="fa-regular fa-eye" aria-hidden="true"></i> View</button>
                                            <?php if ($complaintView === 'archived'): ?>
                                                <button type="button" class="complaint-action is-restore" data-complaint-action="restore_complaint" data-complaint-id="<?php echo (int)$row['id']; ?>"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button>
                                            <?php else: ?>
                                                <a href="complaint_form.php?id=<?php echo (int)$row['id']; ?>&amp;edit=1" class="complaint-action"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit</a>
                                                <?php if (in_array($row['status'], $archivableStatuses, true)): ?>
                                                    <button type="button" class="complaint-action is-archive" data-complaint-action="archive_complaint" data-complaint-id="<?php echo (int)$row['id']; ?>"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
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

            </div>

            <!-- RIGHT -->

            <div class="col-lg-4">

                <div class="content-card">

                    <h3 class="mb-4">

                        Complaint Alerts

                    </h3>

                    <?php if ($complaintAlerts->num_rows === 0): ?>

                        <p class="text-muted">No complaints need attention.</p>

                    <?php else: ?>

                        <?php while ($alert = $complaintAlerts->fetch_assoc()): ?>

                            <?php
                            $filedAt = !empty($alert['date_filed']) ? new DateTimeImmutable($alert['date_filed']) : null;
                            $ageDays = $filedAt ? max(0, (int)$filedAt->diff(new DateTimeImmutable('today'))->format('%r%a')) : null;
                            ?>
                            <button type="button" class="alert-box complaint-alert<?php echo $alert['status'] === 'Pending' ? ' warning' : ' primary'; ?>" data-complaint-id="<?php echo (int)$alert['id']; ?>">
                                <span class="complaint-alert__topline">
                                    <strong><?php echo h($alert['tracking_number']); ?></strong>
                                    <span class="complaint-alert__status"><?php echo h($alert['status']); ?></span>
                                </span>
                                <span class="complaint-alert__category"><?php echo h($alert['category']); ?></span>
                                <span class="complaint-alert__age">
                                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                                    <?php echo $ageDays === null ? 'Filing date not recorded' : 'Open for ' . $ageDays . ' day' . ($ageDays === 1 ? '' : 's'); ?>
                                </span>
                            </button>

                        <?php endwhile; ?>

                    <?php endif; ?>
                    <a class="complaint-alerts-link" href="complaints.php?status=unresolved&amp;view=active">
                        View all unresolved complaints <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>

                </div>

            </div>

        </div>

    </main>

</div>

<dialog class="complaint-details-dialog" id="complaintDetailsDialog" aria-labelledby="complaintDetailsTitle">
    <header class="complaint-dialog-header">
        <div>
            <span class="complaint-dialog-eyebrow">CONFIDENTIAL CASE RECORD</span>
            <h2 id="complaintDetailsTitle">Complaint details</h2>
        </div>
        <button type="button" class="complaint-dialog-close" data-close-complaint-dialog aria-label="Close complaint details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </header>
    <p id="complaintDetailsMessage" class="complaint-dialog-message" role="status" aria-live="polite"></p>
    <div class="complaint-dialog-body">
        <section class="complaint-detail-grid" aria-label="Complaint information">
            <div><span>Tracking number</span><strong id="complaintDetailTracking"></strong></div>
            <div><span>Status</span><strong id="complaintDetailStatus"></strong></div>
            <div><span>Complainant</span><strong id="complaintDetailComplainant"></strong></div>
            <div><span>Respondent</span><strong id="complaintDetailRespondent"></strong></div>
            <div><span>Category</span><strong id="complaintDetailCategory"></strong></div>
            <div><span>Date filed</span><strong id="complaintDetailDate"></strong></div>
            <div class="wide"><span>Assigned personnel</span><strong id="complaintDetailAssignee"></strong></div>
            <div class="wide"><span>Description</span><strong id="complaintDetailDescription"></strong></div>
            <div class="wide"><span>Resolution action taken</span><strong id="complaintDetailAction"></strong></div>
            <div class="wide"><span>Resolution remarks</span><strong id="complaintDetailResolution"></strong></div>
            <div class="wide" id="complaintArchivedDateRow" hidden><span>Archived</span><strong id="complaintDetailArchivedDate"></strong></div>
        </section>
        <section class="complaint-timeline" aria-labelledby="complaintTimelineTitle">
            <h3 id="complaintTimelineTitle">Case Timeline</h3>
            <ol id="complaintTimelineList"></ol>
        </section>
    </div>
    <footer class="complaint-dialog-actions">
        <a id="complaintDetailEdit" href="#" class="complaint-action"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit Record</a>
        <button id="complaintDetailArchive" type="button" class="complaint-action is-archive" hidden><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
        <button id="complaintDetailRestore" type="button" class="complaint-action is-restore" hidden><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore</button>
        <button type="button" class="complaint-action" data-close-complaint-dialog>Close</button>
    </footer>
</dialog>

<dialog class="complaint-confirm-dialog" id="complaintConfirmDialog" aria-labelledby="complaintConfirmTitle" data-csrf-token="<?php echo h(csrfToken()); ?>">
    <div class="complaint-confirm-icon" id="complaintConfirmIcon"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></div>
    <span class="complaint-dialog-eyebrow">COMPLAINT RECORD</span>
    <h2 id="complaintConfirmTitle">Archive this complaint?</h2>
    <p id="complaintConfirmMessage">Only resolved complaints can be archived. You can restore this record later.</p>
    <div class="complaint-confirm-actions">
        <button type="button" id="complaintConfirmCancel">Cancel</button>
        <button type="button" id="complaintConfirmSubmit" class="is-archive"><i class="fa-solid fa-box-archive" aria-hidden="true"></i><span>Archive Complaint</span></button>
    </div>
</dialog>

<?php renderFooterScripts(); ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const detailsDialog = document.getElementById('complaintDetailsDialog');
        const confirmDialog = document.getElementById('complaintConfirmDialog');
        const detailMessage = document.getElementById('complaintDetailsMessage');
        const confirmMessage = document.getElementById('complaintConfirmMessage');
        const confirmSubmit = document.getElementById('complaintConfirmSubmit');
        const archiveButton = document.getElementById('complaintDetailArchive');
        const restoreButton = document.getElementById('complaintDetailRestore');
        let pendingAction = null;

        const setText = function (id, value) {
            document.getElementById(id).textContent = value || 'Not recorded';
        };

        const requestAction = async function (formData) {
            const response = await fetch('complaints.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not update the complaint.');
            }
            return result;
        };

        const openDetails = async function (complaintId) {
            detailMessage.textContent = 'Loading confidential case details…';
            document.getElementById('complaintTimelineList').replaceChildren();
            detailsDialog.showModal();
            try {
                const response = await fetch('complaints.php?ajax=details&id=' + encodeURIComponent(complaintId), {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Could not load complaint details.');
                const record = result.record;
                setText('complaintDetailTracking', record.tracking_number);
                setText('complaintDetailStatus', record.status);
                setText('complaintDetailComplainant', record.resident_name);
                setText('complaintDetailRespondent', record.complainee_name);
                setText('complaintDetailCategory', record.category);
                setText('complaintDetailDate', record.date_filed);
                setText('complaintDetailAssignee', record.assigned_personnel + (record.assigned_position ? ' · ' + record.assigned_position : ''));
                setText('complaintDetailDescription', record.description);
                setText('complaintDetailAction', record.response);
                setText('complaintDetailResolution', record.resolution_remarks);
                document.getElementById('complaintDetailEdit').href = 'complaint_form.php?id=' + encodeURIComponent(record.id) + '&edit=1';
                archiveButton.hidden = !record.can_archive;
                restoreButton.hidden = !Number(record.is_archived);
                document.getElementById('complaintDetailEdit').hidden = Boolean(Number(record.is_archived));
                document.getElementById('complaintArchivedDateRow').hidden = !Number(record.is_archived);
                setText('complaintDetailArchivedDate', record.archived_at);

                const timeline = document.getElementById('complaintTimelineList');
                record.timeline.forEach(function (event) {
                    const item = document.createElement('li');
                    const activity = document.createElement('strong');
                    const date = document.createElement('time');
                    activity.textContent = event.activity;
                    if (event.date) {
                        const parsedDate = new Date(event.date.replace(' ', 'T'));
                        date.textContent = Number.isNaN(parsedDate.getTime())
                            ? event.date
                            : new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: event.date.includes(':') ? 'short' : undefined}).format(parsedDate);
                        date.dateTime = event.date.replace(' ', 'T');
                    } else {
                        date.textContent = 'Date not recorded';
                    }
                    item.append(activity, date);
                    if (event.updated_by) {
                        const actor = document.createElement('span');
                        actor.className = 'complaint-timeline__actor';
                        actor.textContent = 'Updated by ' + event.updated_by;
                        item.append(actor);
                    }
                    timeline.append(item);
                });
                detailMessage.textContent = 'Sensitive complaint details are visible only to authorized personnel.';
                detailsDialog.dataset.complaintId = String(record.id);
            } catch (error) {
                console.error('Could not load complaint details:', error);
                detailMessage.textContent = error.message;
            }
        };

        const showConfirmation = function (action, complaintId) {
            pendingAction = {action: action, complaintId: complaintId};
            const isArchive = action === 'archive_complaint';
            document.getElementById('complaintConfirmIcon').classList.toggle('is-archive', isArchive);
            document.getElementById('complaintConfirmTitle').textContent = isArchive ? 'Archive this complaint?' : 'Restore this complaint?';
            confirmMessage.textContent = isArchive
                ? 'Only resolved complaints can be archived. You can restore this record later.'
                : 'This complaint will return to Active Complaints without changing its status.';
            confirmSubmit.classList.toggle('is-archive', isArchive);
            confirmSubmit.querySelector('i').className = isArchive ? 'fa-solid fa-box-archive' : 'fa-solid fa-rotate-left';
            confirmSubmit.querySelector('span').textContent = isArchive ? 'Archive Complaint' : 'Restore Complaint';
            confirmSubmit.disabled = false;
            confirmDialog.showModal();
            document.getElementById('complaintConfirmCancel').focus();
            document.getElementById('complaintConfirmCancel').focus();
        };

        document.addEventListener('click', function (event) {
            const viewTrigger = event.target.closest('.complaint-view-trigger, .complaint-alert');
            if (viewTrigger) {
                openDetails(viewTrigger.dataset.complaintId);
                return;
            }
            const actionTrigger = event.target.closest('[data-complaint-action]');
            if (actionTrigger) {
                showConfirmation(actionTrigger.dataset.complaintAction, actionTrigger.dataset.complaintId);
                return;
            }
            if (event.target.closest('#complaintDetailArchive')) {
                showConfirmation('archive_complaint', detailsDialog.dataset.complaintId);
                return;
            }
            if (event.target.closest('#complaintDetailRestore')) {
                showConfirmation('restore_complaint', detailsDialog.dataset.complaintId);
                return;
            }
            if (event.target.closest('[data-close-complaint-dialog]')) detailsDialog.close();
        });

        document.getElementById('complaintConfirmCancel').addEventListener('click', function () {
            pendingAction = null;
            confirmDialog.close();
        });

        confirmSubmit.addEventListener('click', async function () {
            if (!pendingAction) return;
            confirmSubmit.disabled = true;
            confirmMessage.textContent = 'Saving your changes…';
            const formData = new FormData();
            formData.set('action', pendingAction.action);
            formData.set('complaint_id', pendingAction.complaintId);
            formData.set('csrf_token', confirmDialog.dataset.csrfToken || '');
            const completedAction = pendingAction.action;
            try {
                await requestAction(formData);
                const destination = new URL(window.location.href);
                destination.searchParams.set('view', completedAction === 'archive_complaint' ? 'archived' : 'active');
                destination.searchParams.set('msg', completedAction === 'archive_complaint' ? 'archive_success' : 'restore_success');
                destination.searchParams.delete('page');
                window.location.assign(destination.toString());
            } catch (error) {
                confirmMessage.textContent = error.message;
                confirmSubmit.disabled = false;
            }
        });

        confirmDialog.addEventListener('click', function (event) {
            if (event.target === confirmDialog) {
                pendingAction = null;
                confirmDialog.close();
            }
        });
        detailsDialog.addEventListener('click', function (event) {
            if (event.target === detailsDialog) detailsDialog.close();
        });
    });
</script>

</body>
</html>
