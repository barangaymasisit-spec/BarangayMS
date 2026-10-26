<?php
require_once __DIR__ . '/layout.php';

$residentSearch = trim($_GET['residentSearch'] ?? '');
$certificateType = trim($_GET['certificateType'] ?? '');
$requestStatus = trim($_GET['requestStatus'] ?? '');
$certificateView = in_array($_GET['view'] ?? 'active', ['active', 'archived'], true) ? $_GET['view'] : 'active';
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$jsonResponse = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$statusOptions = ['Pending', 'Processing', 'Approved', 'Released', 'Rejected', 'Cancelled'];
$archivableStatuses = ['Released', 'Rejected', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax'] ?? '') === 'details') {
    $certificateId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$certificateId || $certificateId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid certificate request.'], 400);
    }

    $stmt = $conn->prepare(
        'SELECT c.id, c.resident_id, c.requested_by_user_id, c.requester_relationship, c.certificate_type, c.status,
                c.request_date, c.approved_date, c.purpose, c.remarks, c.is_archived, c.archived_at,
                r.id AS linked_resident_id, r.resident_number,
                CONCAT_WS(" ", NULLIF(r.first_name, ""), NULLIF(r.middle_name, ""), NULLIF(r.last_name, ""), NULLIF(r.suffix, "")) AS resident_name,
                CONCAT_WS(" ", NULLIF(u.first_name, ""), NULLIF(u.last_name, "")) AS requested_by
         FROM certificates c
         LEFT JOIN residents r ON r.id = c.resident_id
         LEFT JOIN users u ON u.id = c.requested_by_user_id
         WHERE c.id = ?'
    );
    $stmt->bind_param('i', $certificateId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$record) {
        $jsonResponse(['success' => false, 'message' => 'Certificate request not found.'], 404);
    }

    $timeline = [];
    $hasSubmission = false;
    $hasStatusHistory = false;
    $historyStmt = $conn->prepare("SELECT action, details, created_at FROM activity_logs WHERE entity = 'certificates' AND related_id = ? ORDER BY id ASC");
    $historyStmt->bind_param('i', $certificateId);
    $historyStmt->execute();
    $history = $historyStmt->get_result();
    while ($event = $history->fetch_assoc()) {
        $details = (string)($event['details'] ?? '');
        $activity = '';
        if ($event['action'] === 'created') {
            $activity = 'Request submitted';
            $hasSubmission = true;
        } elseif (preg_match('/status changed from .+ to (.+)\.$/i', $details, $matches)) {
            $activity = 'Status changed to ' . trim($matches[1]);
            $hasStatusHistory = true;
        } elseif ($event['action'] === 'released') {
            $activity = 'Certificate released';
        } elseif ($event['action'] === 'printed') {
            $activity = 'Certificate printed';
        } elseif ($event['action'] === 'archived') {
            $activity = 'Request archived';
        } elseif ($event['action'] === 'restored') {
            $activity = 'Request restored';
        }
        if ($activity !== '') {
            $timeline[] = ['activity' => $activity, 'date' => $event['created_at']];
        }
    }
    $historyStmt->close();
    if (!$hasSubmission) {
        array_unshift($timeline, [
            'activity' => 'Request submitted (date recorded)',
            'date' => $record['request_date'] ?: null,
        ]);
    }
    if (!$hasStatusHistory && !empty($record['approved_date'])) {
        $timeline[] = [
            'activity' => 'Approval / release date recorded',
            'date' => $record['approved_date'],
        ];
    }

    $record['resident_name'] = $record['linked_resident_id']
        ? trim((string)$record['resident_name'])
        : ($record['resident_id'] ? 'Missing resident record (#' . (int)$record['resident_id'] . ')' : 'No resident linked');
    if ($record['resident_name'] === '') {
        $record['resident_name'] = 'Missing resident name (#' . (int)$record['resident_id'] . ')';
    }
    $record['requested_by'] = trim((string)$record['requested_by']) ?: 'Not recorded';
    $record['timeline'] = $timeline;
    $record['can_archive'] = !$record['is_archived'] && in_array($record['status'], $archivableStatuses, true);
    $record['generator_url'] = certificateGeneratorPage((string)$record['certificate_type']) . '?' . http_build_query([
        'id' => (int)$record['id'],
        'type' => strtolower(str_replace(' ', '_', (string)$record['certificate_type'])),
    ]);
    $jsonResponse(['success' => true, 'record' => $record]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['update_status', 'archive_request', 'restore_request'], true)) {
    $action = (string)$_POST['action'];
    $certificateId = filter_var($_POST['certificate_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$certificateId || $certificateId <= 0) {
        $jsonResponse(['success' => false, 'message' => 'Invalid certificate request.'], 400);
    }

    $stmt = $conn->prepare('SELECT status, is_archived FROM certificates WHERE id = ?');
    $stmt->bind_param('i', $certificateId);
    $stmt->execute();
    $currentRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$currentRequest) {
        $jsonResponse(['success' => false, 'message' => 'Certificate request not found.'], 404);
    }
    $previousStatus = (string)$currentRequest['status'];
    if ($action === 'update_status') {
        $newStatus = trim((string)($_POST['status'] ?? ''));
        if (!in_array($newStatus, $statusOptions, true)) {
            $jsonResponse(['success' => false, 'message' => 'Choose a valid certificate status.'], 422);
        }
        if ($currentRequest['is_archived']) {
            $jsonResponse(['success' => false, 'message' => 'Restore this request before changing its status.'], 409);
        }
        if ($previousStatus !== $newStatus) {
            $approvedDate = in_array($newStatus, ['Approved', 'Released'], true) ? date('Y-m-d') : null;
            $stmt = $conn->prepare('UPDATE certificates SET status = ?, approved_date = ? WHERE id = ? AND is_archived = 0');
            $stmt->bind_param('ssi', $newStatus, $approvedDate, $certificateId);
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'certificates', 'Certificate request status changed from ' . $previousStatus . ' to ' . $newStatus . '.', $certificateId);
        }
        $jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    if ($action === 'archive_request') {
        if ($currentRequest['is_archived'] || !in_array($previousStatus, $archivableStatuses, true)) {
            $jsonResponse(['success' => false, 'message' => 'Only Released, Rejected, or Cancelled requests can be archived.'], 409);
        }
        $stmt = $conn->prepare('UPDATE certificates SET is_archived = 1, archived_at = NOW() WHERE id = ? AND is_archived = 0');
        $activity = 'archived';
        $message = 'Certificate request archived.';
    } else {
        if (!$currentRequest['is_archived']) {
            $jsonResponse(['success' => false, 'message' => 'This certificate request is already active.'], 409);
        }
        $stmt = $conn->prepare('UPDATE certificates SET is_archived = 0, archived_at = NULL WHERE id = ? AND is_archived = 1');
        $activity = 'restored';
        $message = 'Certificate request restored.';
    }
    $stmt->bind_param('i', $certificateId);
    $stmt->execute();
    $changed = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$changed) {
        $jsonResponse(['success' => false, 'message' => 'The certificate request could not be updated. Refresh and try again.'], 409);
    }
    logActivity($conn, $activity, 'certificates', $message, $certificateId);
    $jsonResponse(['success' => true, 'message' => $message]);
}

$where = [];
$params = [];
$types = '';
$where[] = 'c.is_archived = ?';
$params[] = $certificateView === 'archived' ? 1 : 0;
$types .= 'i';

if ($residentSearch !== '') {
    $where[] = 'CONCAT_WS(" ", r.first_name, r.middle_name, r.last_name, r.suffix, r.resident_number) LIKE ?';
    $params[] = '%' . $residentSearch . '%';
    $types .= 's';
}
if ($certificateType !== '') {
    $where[] = 'c.certificate_type = ?';
    $params[] = $certificateType;
    $types .= 's';
}
if ($requestStatus !== '') {
    $where[] = 'c.status = ?';
    $params[] = $requestStatus;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Export: CSV of every request matching the current filters (not just this page).
if (($_GET['export'] ?? '') === 'csv') {
        $stmt = $conn->prepare('SELECT c.id, CASE WHEN r.id IS NULL THEN CASE WHEN c.resident_id IS NULL THEN "No resident linked" ELSE CONCAT("Missing resident record (#", c.resident_id, ")") END ELSE COALESCE(NULLIF(CONCAT_WS(" ", r.first_name, r.middle_name, r.last_name, r.suffix), ""), CONCAT("Missing resident name (#", c.resident_id, ")")) END AS resident_name,
                        COALESCE(CONCAT(u.first_name, " ", u.last_name), "—") AS requested_by, c.requester_relationship,
                        c.certificate_type, c.purpose, c.request_date, c.status, c.approved_date, c.remarks
                    FROM certificates c
                    LEFT JOIN residents r ON c.resident_id = r.id
                    LEFT JOIN users u ON c.requested_by_user_id = u.id' . $whereSql . ' ORDER BY c.id DESC');
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="certificate_requests_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 names (ñ) correctly
    fputcsv($out, ['Reference No.', 'Resident', 'Requested By', 'Requester Relationship', 'Certificate', 'Purpose', 'Request Date', 'Status', 'Approved / Released Date', 'Remarks']);
    while ($row = $result->fetch_assoc()) {
        $row['id'] = str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
        // Stop Excel from running cells that start with = + - @ as formulas.
        $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v, $row);
        fputcsv($out, $row);
    }
    fclose($out);
    $stmt->close();
    logActivity($conn, 'exported', 'certificates', 'Exported certificate requests to CSV.');
    exit;
}

$countSql = 'SELECT COUNT(*) FROM certificates c LEFT JOIN residents r ON c.resident_id = r.id' . $whereSql;
if ($params) {
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $filteredCount = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
} else {
    $filteredCount = (int)($conn->query($countSql)->fetch_row()[0] ?? 0);
}

$totalPages = max(1, (int)ceil($filteredCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT c.id, c.resident_id, c.certificate_type, c.status, c.request_date, c.purpose, c.requester_relationship,
                   c.is_archived, c.archived_at,
                   CASE WHEN r.id IS NULL THEN CASE WHEN c.resident_id IS NULL THEN "No resident linked" ELSE CONCAT("Missing resident record (#", c.resident_id, ")") END ELSE COALESCE(NULLIF(CONCAT_WS(" ", r.first_name, r.middle_name, r.last_name, r.suffix), ""), CONCAT("Missing resident name (#", c.resident_id, ")")) END AS resident_name,
                   COALESCE(CONCAT(u.first_name, " ", u.last_name), "—") AS requested_by
            FROM certificates c
            LEFT JOIN residents r ON c.resident_id = r.id
            LEFT JOIN users u ON c.requested_by_user_id = u.id'
            . $whereSql . ' ORDER BY c.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($listSql);
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;
$stmt->bind_param($types . 'ii', ...$listParams);
$stmt->execute();
$certificates = $stmt->get_result();

$totalRequests = (int)($conn->query('SELECT COUNT(*) FROM certificates WHERE is_archived = 0')->fetch_row()[0] ?? 0);
$pendingRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Pending' AND is_archived = 0")->fetch_row()[0] ?? 0);
$approvedRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Approved' AND is_archived = 0")->fetch_row()[0] ?? 0);
$releasedRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Released' AND is_archived = 0")->fetch_row()[0] ?? 0);
$activeRequests = (int)($conn->query('SELECT COUNT(*) FROM certificates WHERE is_archived = 0')->fetch_row()[0] ?? 0);
$archivedRequests = (int)($conn->query('SELECT COUNT(*) FROM certificates WHERE is_archived = 1')->fetch_row()[0] ?? 0);

function certificatePageUrl(int $page, array $filters): string {
    $query = array_filter($filters, function ($value) {
        return $value !== '';
    });
    $query['page'] = $page;
    return 'certificates.php?' . http_build_query($query);
}

function certificateStatusClass(string $status): string {
    return match (strtolower(trim($status))) {
        'pending' => 'pending',
        'processing' => 'processing',
        'approved' => 'approved',
        'released' => 'released',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        default => 'unknown',
    };
}

function certificateGeneratorPage(string $certificateType): string {
    $pages = [
        'barangay clearance' => 'barangay_clearance.php',
        'certificate of residency' => 'residency.php',
        'certificate of indigency' => 'indigency.php',
        'business clearance' => 'business.php',
        'good moral' => 'good_moral.php',
    ];

    return $pages[strtolower(trim($certificateType))] ?? 'certificate_generate.php';
}

$activeFilters = [
    'residentSearch'  => $residentSearch,
    'certificateType' => $certificateType,
    'requestStatus'   => $requestStatus,
    'view' => $certificateView,
];
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Online Certifications'); ?>

    <link
        rel="stylesheet"
        href="<?php echo asset('certification.css'); ?>">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

</head>

<body>

<div class="wrapper">

    <?php renderSidebar('certificates', 'panel'); ?>

    <main class="main-content">

        <?php
        $newRequestButton = '<a href="certificate_form.php" class="btn-primary" aria-label="Create New Certificate Request">'
            . '<i class="fa-solid fa-plus" aria-hidden="true"></i><span>New Request</span></a>';
        renderTopbar(
            'Online Certifications',
            'Manage certificate requests and approvals.',
            'panel',
            ['breadcrumb' => 'Online Certifications', 'clock' => true, 'actions' => $newRequestButton, 'dashboardHeader' => true]
        );

        renderNotice([
            'created' => 'Certificate request created.',
            'updated' => 'Certificate request status updated.',
            'status_only' => 'Certificate details are read-only. Change the request status from the certifications list.',
            'invalid_status' => 'The requested certificate status is invalid.',
            'no_change' => 'The certificate request already has that status.',
            'missing' => 'The certificate request could not be found.',
            'delete_disabled' => 'Deleting certificate requests is disabled. You can view the record or change its status.',
        ]);
        ?>

        <section
            class="cards"
            aria-label="Certification Statistics">

            <article class="card">

                <i
                    class="fa-solid fa-file-lines"
                    aria-hidden="true"></i>

                <h3>Total Requests</h3>

                <h2 id="totalRequests"><?php echo $totalRequests; ?></h2>

            </article>

            <article class="card">

                <i
                    class="fa-solid fa-clock"
                    aria-hidden="true"></i>

                <h3>Pending Requests</h3>

                <h2 id="pendingRequests"><?php echo $pendingRequests; ?></h2>

            </article>

            <article class="card">

                <i
                    class="fa-solid fa-circle-check"
                    aria-hidden="true"></i>

                <h3>Approved Requests</h3>

                <h2 id="approvedRequests"><?php echo $approvedRequests; ?></h2>

            </article>

            <article class="card">

                <i
                    class="fa-solid fa-print"
                    aria-hidden="true"></i>

                <h3>Released Certificates</h3>

                <h2 id="releasedRequests"><?php echo $releasedRequests; ?></h2>

            </article>

        </section>

        <form
            class="search-panel"
            method="get"
            action="certificates.php"
            aria-labelledby="searchHeading">

            <h2
                id="searchHeading"
                class="visually-hidden">

                Search Certificate Requests

            </h2>

            <div class="search-grid">

                <div class="form-group">

                    <label for="residentSearch">

                        Search Resident

                    </label>

                    <input
                        id="residentSearch"
                        name="residentSearch"
                        type="search"
                        value="<?php echo h($residentSearch); ?>"
                        placeholder="Enter resident name">

                </div>

                <div class="form-group">

                    <label for="certificateType">

                        Certificate

                    </label>

                    <select
                        id="certificateType"
                        name="certificateType">

                        <option value="">All Certificates</option>
                        <?php
                        $certificateOptions = [
                            'Barangay Clearance',
                            'Certificate of Residency',
                            'Certificate of Indigency',
                            'Business Clearance',
                            'Good Moral',
                        ];
                        foreach ($certificateOptions as $option) {
                            $selected = $certificateType === $option ? ' selected' : '';
                            echo '<option' . $selected . '>' . h($option) . '</option>';
                        }
                        ?>

                    </select>

                </div>

                <div class="form-group">

                    <label for="requestStatus">

                        Status

                    </label>

                    <select
                        id="requestStatus"
                        name="requestStatus">

                        <option value="">All Status</option>
                        <?php
                        foreach ($statusOptions as $option) {
                            $selected = $requestStatus === $option ? ' selected' : '';
                            echo '<option' . $selected . '>' . $option . '</option>';
                        }
                        ?>

                    </select>

                </div>

                <div class="button-group">

                    <button
                        type="submit"
                        class="btn-search"
                        aria-label="Search Certificate Requests">

                        <i
                            class="fa-solid fa-magnifying-glass"
                            aria-hidden="true"></i>

                        <span>Search</span>

                    </button>

                </div>

            </div>

        </form>

        <section
            class="table-card"
            aria-labelledby="certificateRequestsHeading">

            <div class="table-header">

                <div>

                    <h2 id="certificateRequestsHeading">

                        Certificate Requests

                    </h2>

                    <p>

                        View and manage all online certificate requests.

                    </p>

                </div>

                <div class="action-group" style="display:flex; gap:10px; align-items:center;">
                    <button
                        type="button"
                        id="bulkPrintBtn"
                        class="btn-export"
                        aria-label="Print Selected Certificate Requests">

                        <i
                            class="fa-solid fa-print"
                            aria-hidden="true"></i>

                        <span>Print Selected</span>

                    </button>

                    <a
                        href="certificates.php?<?php echo h(http_build_query(array_filter($activeFilters, fn($v) => $v !== '') + ['export' => 'csv'])); ?>"
                        class="btn-export"
                        style="text-decoration:none;"
                        aria-label="Export Certificate Requests to CSV">

                        <i
                            class="fa-solid fa-file-export"
                            aria-hidden="true"></i>

                        <span>Export</span>

                    </a>

                </div>

            </div>

            <nav class="certificate-request-tabs" aria-label="Certificate request status">
                <?php
                $tabFilters = array_filter([
                    'residentSearch' => $residentSearch,
                    'certificateType' => $certificateType,
                    'requestStatus' => $requestStatus,
                ], static fn($value) => $value !== '');
                ?>
                <a class="certificate-request-tab<?php echo $certificateView === 'active' ? ' is-active' : ''; ?>" href="certificates.php?<?php echo h(http_build_query($tabFilters + ['view' => 'active'])); ?>"<?php echo $certificateView === 'active' ? ' aria-current="page"' : ''; ?>>
                    <span><i class="fa-solid fa-inbox" aria-hidden="true"></i> Active Requests</span>
                    <strong><?php echo $activeRequests; ?></strong>
                </a>
                <a class="certificate-request-tab<?php echo $certificateView === 'archived' ? ' is-active' : ''; ?>" href="certificates.php?<?php echo h(http_build_query($tabFilters + ['view' => 'archived'])); ?>"<?php echo $certificateView === 'archived' ? ' aria-current="page"' : ''; ?>>
                    <span><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archived</span>
                    <strong><?php echo $archivedRequests; ?></strong>
                </a>
            </nav>

            <div class="table-responsive">

                <table>

                    <caption>

                        List of online certificate requests.

                    </caption>

                    <thead>

                        <tr>

                            <th scope="col"><input type="checkbox" id="selectAllCertificates" aria-label="Select all certificates"></th>

                            <th scope="col">Reference No.</th>

                            <th scope="col">Resident</th>
                            <th scope="col">Requested By</th>
                            <th scope="col">Relationship</th>

                            <th scope="col">Certificate</th>

                            <th scope="col">Purpose</th>

                            <th scope="col">Request Date</th>

                            <th scope="col">Status</th>

                            <th scope="col">Actions</th>

                        </tr>

                    </thead>

                    <tbody id="certificateTableBody">

                        <?php if ($certificates->num_rows === 0): ?>

                            <tr>

                                <td colspan="10">

                                    <div class="empty-state">

                                        <i
                                            class="fa-solid fa-file-circle-xmark"
                                            aria-hidden="true"></i>

                                        <h3>

                                            <?php echo $certificateView === 'archived' ? 'No Archived Requests' : 'No Active Certificate Requests'; ?>

                                        </h3>

                                        <p>

                                            <?php echo $certificateView === 'archived'
                                                ? 'Archived requests will appear here and can be restored.'
                                                : 'There are currently no active certificate requests available.'; ?>

                                        </p>

                                        <?php if ($certificateView === 'active'): ?>
                                        <a href="certificate_form.php" class="btn-primary" aria-label="Create New Certificate Request">

                                            <i
                                                class="fa-solid fa-plus"
                                                aria-hidden="true"></i>

                                            <span>

                                                Create First Request

                                            </span>

                                        </a>
                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php while ($row = $certificates->fetch_assoc()): ?>

                                <tr>

                                    <td><input type="checkbox" class="certificate-check" value="<?php echo (int)$row['id']; ?>" data-certificate-type="<?php echo h($row['certificate_type']); ?>" data-generator-page="<?php echo h(certificateGeneratorPage($row['certificate_type'])); ?>" aria-label="Select certificate <?php echo (int)$row['id']; ?>"></td>

                                    <td><?php echo h(str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT)); ?></td>

                                    <td><?php echo h($row['resident_name']); ?></td>

                                    <td><?php echo h($row['requested_by']); ?></td>

                                    <td><?php echo h($row['requester_relationship'] ?: 'Self / Staff'); ?></td>

                                    <td><?php echo h($row['certificate_type']); ?></td>

                                    <td><?php echo h($row['purpose']); ?></td>

                                    <td><?php echo h($row['request_date']); ?></td>

                                    <td><span class="certificate-status-badge <?php echo h(certificateStatusClass((string)$row['status'])); ?>"><?php echo h($row['status']); ?></span></td>

                                    <td>
                                        <div class="certificate-actions" aria-label="Actions for certificate request <?php echo (int)$row['id']; ?>">
                                            <button type="button" class="certificate-details-trigger" data-certificate-id="<?php echo (int)$row['id']; ?>">
                                                <i class="fa-regular fa-eye" aria-hidden="true"></i> View
                                            </button>
                                            <a class="certificate-action-link" href="<?php echo h(certificateGeneratorPage($row['certificate_type']) . '?' . http_build_query(['id' => (int)$row['id'], 'type' => strtolower(str_replace(' ', '_', $row['certificate_type']))])); ?>" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-print" aria-hidden="true"></i> Print
                                            </a>
                                            <?php if ($certificateView === 'archived'): ?>
                                                <form method="post" data-certificate-action-form>
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="restore_request">
                                                    <input type="hidden" name="certificate_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="certificate-action-button">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore
                                                    </button>
                                                </form>
                                            <?php elseif (in_array($row['status'], $archivableStatuses, true)): ?>
                                                <form method="post" data-certificate-action-form>
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="archive_request">
                                                    <input type="hidden" name="certificate_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="certificate-action-button is-danger">
                                                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <nav
                class="pagination"
                aria-label="Certificate Pagination">

                <?php if ($page > 1): ?>
                    <a
                        href="<?php echo h(certificatePageUrl($page - 1, $activeFilters)); ?>"
                        class="page-btn"
                        aria-label="Previous Page">

                        Previous

                    </a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a
                        href="<?php echo h(certificatePageUrl($i, $activeFilters)); ?>"
                        class="page-number<?php echo $i === $page ? ' active' : ''; ?>"
                        <?php echo $i === $page ? 'aria-current="page"' : ''; ?>>

                        <?php echo $i; ?>

                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a
                        href="<?php echo h(certificatePageUrl($page + 1, $activeFilters)); ?>"
                        class="page-btn"
                        aria-label="Next Page">

                        Next

                    </a>
                <?php endif; ?>

            </nav>

        </section>

    </main>

</div>

<dialog class="certificate-details-dialog" id="certificateDetailsDialog" aria-labelledby="certificateDetailsTitle" aria-describedby="certificateDetailsMessage">
    <div class="certificate-details-dialog__header">
        <div>
            <span class="certificate-details-dialog__eyebrow">CERTIFICATE REQUEST</span>
            <h2 id="certificateDetailsTitle">Request details</h2>
        </div>
        <button type="button" class="certificate-details-dialog__close" data-close-certificate-dialog aria-label="Close details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <p id="certificateDetailsMessage" class="certificate-details-dialog__message" role="status" aria-live="polite"></p>
    <div class="certificate-details-dialog__body">
        <section class="certificate-details-summary" aria-label="Request information">
            <div><span>Resident</span><strong id="certificateDetailResident"></strong></div>
            <div><span>Resident number</span><strong id="certificateDetailResidentNumber"></strong></div>
            <div><span>Requested by</span><strong id="certificateDetailRequester"></strong></div>
            <div><span>Relationship</span><strong id="certificateDetailRelationship"></strong></div>
            <div><span>Certificate</span><strong id="certificateDetailType"></strong></div>
            <div><span>Request date</span><strong id="certificateDetailRequestDate"></strong></div>
            <div class="certificate-details-summary__wide"><span>Purpose</span><strong id="certificateDetailPurpose"></strong></div>
            <div class="certificate-details-summary__wide"><span>Remarks</span><strong id="certificateDetailRemarks"></strong></div>
            <div class="certificate-details-summary__wide" id="certificateArchivedAtRow" hidden><span>Archived</span><strong id="certificateDetailArchivedAt"></strong></div>
        </section>
        <section class="certificate-timeline" aria-labelledby="certificateTimelineTitle">
            <h3 id="certificateTimelineTitle">Request Timeline</h3>
            <ol id="certificateTimelineList" class="certificate-timeline__list"></ol>
        </section>
        <form id="certificateStatusForm" class="certificate-details-status">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="certificate_id" id="certificateDetailId">
            <label for="certificateDetailStatus">Update status</label>
            <div class="certificate-details-status__controls">
                <select id="certificateDetailStatus" name="status">
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option value="<?php echo h($statusOption); ?>"><?php echo h($statusOption); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-export"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Status</button>
            </div>
        </form>
    </div>
    <div class="certificate-details-dialog__actions">
        <a href="#" id="certificateDetailPrint" class="btn-export" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Generate / Print</a>
        <button type="button" id="certificateDetailArchive" class="certificate-dialog-action is-danger" hidden><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive Request</button>
        <button type="button" id="certificateDetailRestore" class="certificate-dialog-action" hidden><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restore Request</button>
        <button type="button" class="certificate-dialog-action" data-close-certificate-dialog>Close</button>
    </div>
</dialog>

<dialog class="certificate-confirm-dialog" id="certificateConfirmDialog" aria-labelledby="certificateConfirmTitle" aria-describedby="certificateConfirmMessage">
    <div class="certificate-confirm-dialog__icon" id="certificateConfirmIcon" aria-hidden="true">
        <i class="fa-solid fa-box-archive"></i>
    </div>
    <span class="certificate-confirm-dialog__eyebrow">CERTIFICATE REQUEST</span>
    <h2 id="certificateConfirmTitle">Archive this request?</h2>
    <p id="certificateConfirmMessage">The request will move to Archived. You can restore it later.</p>
    <div class="certificate-confirm-dialog__actions">
        <button type="button" class="certificate-confirm-dialog__cancel" id="certificateConfirmCancel">Cancel</button>
        <button type="button" class="certificate-confirm-dialog__submit is-danger" id="certificateConfirmSubmit">
            <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
            <span>Archive Request</span>
        </button>
    </div>
</dialog>

<?php renderFooterScripts(false); ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectAll = document.getElementById('selectAllCertificates');
        const checks = document.querySelectorAll('.certificate-check');
        const bulkPrintBtn = document.getElementById('bulkPrintBtn');

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        if (bulkPrintBtn) {
            bulkPrintBtn.addEventListener('click', function () {
                const chosen = Array.from(checks).filter(function (checkbox) {
                    return checkbox.checked;
                }).map(function (checkbox) {
                    return {
                        id: checkbox.value,
                        type: checkbox.dataset.certificateType || '',
                        page: checkbox.dataset.generatorPage || 'certificate_generate.php'
                    };
                });

                if (!chosen.length) {
                    alert('Please select at least one certificate to print.');
                    return;
                }

                chosen.forEach(function (certificate) {
                    const url = certificate.page + '?id=' + encodeURIComponent(certificate.id)
                        + '&type=' + encodeURIComponent(certificate.type.toLowerCase().replace(/\s+/g, '_'));
                    window.open(url, '_blank');
                });
            });
        }

        const detailsDialog = document.getElementById('certificateDetailsDialog');
        const detailsMessage = document.getElementById('certificateDetailsMessage');
        const statusForm = document.getElementById('certificateStatusForm');
        const archiveButton = document.getElementById('certificateDetailArchive');
        const restoreButton = document.getElementById('certificateDetailRestore');
        const printLink = document.getElementById('certificateDetailPrint');
        const confirmDialog = document.getElementById('certificateConfirmDialog');
        const confirmIcon = document.getElementById('certificateConfirmIcon');
        const confirmTitle = document.getElementById('certificateConfirmTitle');
        const confirmMessage = document.getElementById('certificateConfirmMessage');
        const confirmSubmit = document.getElementById('certificateConfirmSubmit');
        let pendingConfirmation = null;
        let activeCertificate = null;

        const setText = function (id, value) {
            document.getElementById(id).textContent = value || '—';
        };

        const sendCertificateAction = async function (formData) {
            const response = await fetch('certificates.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'The certificate request could not be updated.');
            }
            return result;
        };

        const showActionConfirmation = function (action, formData, errorTarget) {
            const isArchive = action === 'archive_request';
            pendingConfirmation = {formData: formData, errorTarget: errorTarget};
            confirmDialog.dataset.action = action;
            confirmIcon.classList.toggle('is-danger', isArchive);
            confirmIcon.innerHTML = isArchive
                ? '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>'
                : '<i class="fa-solid fa-rotate-left" aria-hidden="true"></i>';
            confirmTitle.textContent = isArchive ? 'Archive this request?' : 'Restore this request?';
            confirmMessage.textContent = isArchive
                ? 'The request will move to Archived. You can restore it later.'
                : 'This request will return to Active Requests.';
            confirmSubmit.classList.toggle('is-danger', isArchive);
            confirmSubmit.querySelector('i').className = isArchive ? 'fa-solid fa-box-archive' : 'fa-solid fa-rotate-left';
            confirmSubmit.querySelector('span').textContent = isArchive ? 'Archive Request' : 'Restore Request';
            confirmSubmit.disabled = false;
            confirmDialog.showModal();
            document.getElementById('certificateConfirmCancel').focus();
        };

        const openCertificateDetails = async function (certificateId) {
            detailsMessage.textContent = 'Loading request details…';
            document.getElementById('certificateTimelineList').replaceChildren();
            detailsDialog.showModal();
            try {
                const response = await fetch('certificates.php?ajax=details&id=' + encodeURIComponent(certificateId), {
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Could not load request details.');
                }
                const record = result.record;
                activeCertificate = record;
                detailsMessage.textContent = 'Request #' + String(record.id).padStart(6, '0') + (Number(record.is_archived) ? ' · Archived' : '');
                setText('certificateDetailResident', record.resident_name);
                setText('certificateDetailResidentNumber', record.resident_number || (record.linked_resident_id ? 'Not recorded' : 'Record not linked'));
                setText('certificateDetailRequester', record.requested_by);
                setText('certificateDetailRelationship', record.requester_relationship || 'Self / Staff');
                setText('certificateDetailType', record.certificate_type);
                setText('certificateDetailRequestDate', record.request_date);
                setText('certificateDetailPurpose', record.purpose);
                setText('certificateDetailRemarks', record.remarks);
                document.getElementById('certificateDetailId').value = record.id;
                document.getElementById('certificateDetailStatus').value = record.status;
                document.getElementById('certificateDetailStatus').disabled = Boolean(Number(record.is_archived));
                statusForm.querySelector('button[type="submit"]').disabled = Boolean(Number(record.is_archived));
                statusForm.hidden = Boolean(Number(record.is_archived));
                printLink.href = record.generator_url;
                archiveButton.hidden = !record.can_archive;
                restoreButton.hidden = !Number(record.is_archived);
                const archivedAtRow = document.getElementById('certificateArchivedAtRow');
                archivedAtRow.hidden = !Number(record.is_archived);
                setText('certificateDetailArchivedAt', record.archived_at || 'Date not recorded');

                const timeline = document.getElementById('certificateTimelineList');
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
                    timeline.append(item);
                });
                detailsMessage.textContent = 'Request #' + String(record.id).padStart(6, '0') + (Number(record.is_archived) ? ' · Archived' : '');
            } catch (error) {
                console.error('Could not load certificate details:', error);
                detailsMessage.textContent = error.message;
            }
        };

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('.certificate-details-trigger');
            if (trigger) {
                event.preventDefault();
                openCertificateDetails(trigger.dataset.certificateId);
            }
            if (event.target.closest('[data-close-certificate-dialog]')) {
                detailsDialog.close();
            }
        });

        document.addEventListener('submit', async function (event) {
            const actionForm = event.target.closest('[data-certificate-action-form]');
            if (!actionForm) return;
            event.preventDefault();
            const action = actionForm.querySelector('[name="action"]').value;
            showActionConfirmation(action, new FormData(actionForm), null);
        });

        statusForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            try {
                await sendCertificateAction(new FormData(statusForm));
                window.location.reload();
            } catch (error) {
                detailsMessage.textContent = error.message;
            }
        });

        archiveButton.addEventListener('click', async function () {
            if (!activeCertificate) return;
            const formData = new FormData(statusForm);
            formData.set('action', 'archive_request');
            showActionConfirmation('archive_request', formData, detailsMessage);
        });

        restoreButton.addEventListener('click', async function () {
            if (!activeCertificate) return;
            const formData = new FormData(statusForm);
            formData.set('action', 'restore_request');
            showActionConfirmation('restore_request', formData, detailsMessage);
        });

        document.getElementById('certificateConfirmCancel').addEventListener('click', function () {
            pendingConfirmation = null;
            confirmDialog.close();
        });

        confirmSubmit.addEventListener('click', async function () {
            if (!pendingConfirmation) return;
            confirmSubmit.disabled = true;
            confirmMessage.textContent = 'Saving your changes…';
            try {
                await sendCertificateAction(pendingConfirmation.formData);
                window.location.reload();
            } catch (error) {
                confirmMessage.textContent = error.message;
                if (pendingConfirmation.errorTarget) {
                    pendingConfirmation.errorTarget.textContent = error.message;
                }
                confirmSubmit.disabled = false;
            }
        });

        confirmDialog.addEventListener('click', function (event) {
            if (event.target === confirmDialog) {
                pendingConfirmation = null;
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
