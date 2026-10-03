<?php
require_once __DIR__ . '/layout.php';

$residentSearch = trim($_GET['residentSearch'] ?? '');
$certificateType = trim($_GET['certificateType'] ?? '');
$requestStatus = trim($_GET['requestStatus'] ?? '');
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
$types = '';

if ($residentSearch !== '') {
    $where[] = 'CONCAT(COALESCE(r.first_name, ""), " ", COALESCE(r.last_name, "")) LIKE ?';
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
        $stmt = $conn->prepare('SELECT c.id, COALESCE(CONCAT(r.first_name, " ", r.last_name), "Unknown") AS resident_name,
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

$listSql = 'SELECT c.id, c.certificate_type, c.status, c.request_date, c.purpose, c.requester_relationship,
                   COALESCE(CONCAT(r.first_name, " ", r.last_name), "Unknown") AS resident_name,
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

$totalRequests = (int)($conn->query('SELECT COUNT(*) FROM certificates')->fetch_row()[0] ?? 0);
$pendingRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Pending'")->fetch_row()[0] ?? 0);
$approvedRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Approved'")->fetch_row()[0] ?? 0);
$releasedRequests = (int)($conn->query("SELECT COUNT(*) FROM certificates WHERE status = 'Released'")->fetch_row()[0] ?? 0);

function certificatePageUrl(int $page, array $filters): string {
    $query = array_filter($filters, function ($value) {
        return $value !== '';
    });
    $query['page'] = $page;
    return 'certificates.php?' . http_build_query($query);
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
            ['breadcrumb' => 'Online Certifications', 'clock' => true, 'actions' => $newRequestButton]
        );

        renderNotice([
            'created' => 'Certificate request created.',
            'updated' => 'Certificate request updated.',
            'deleted' => 'Certificate request deleted.',
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
                        foreach (['Pending', 'Approved', 'Released', 'Rejected'] as $option) {
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

                                            No Certificate Requests

                                        </h3>

                                        <p>

                                            There are currently no certificate requests available.

                                        </p>

                                        <a
                                            href="certificate_form.php"
                                            class="btn-primary"
                                            aria-label="Create New Certificate Request">

                                            <i
                                                class="fa-solid fa-plus"
                                                aria-hidden="true"></i>

                                            <span>

                                                Create First Request

                                            </span>

                                        </a>

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

                                    <td>

                                        <span class="status <?php echo h(strtolower((string)$row['status'])); ?>">

                                            <?php echo h($row['status']); ?>

                                        </span>

                                    </td>

                                    <td>

                                        <div class="action-group">

                                            <a
                                                href="certificate_form.php?id=<?php echo (int)$row['id']; ?>"
                                                class="btn-view"
                                                title="View Request"
                                                aria-label="View certificate request">

                                                <i class="fa-solid fa-eye" aria-hidden="true"></i>

                                            </a>

                                            <a
                                                href="certificate_form.php?id=<?php echo (int)$row['id']; ?>&amp;edit=1"
                                                class="btn-edit"
                                                title="Edit Request"
                                                aria-label="Edit certificate request">

                                                <i class="fa-solid fa-pen" aria-hidden="true"></i>

                                            </a>

                                            <a
                                                href="<?php echo h(certificateGeneratorPage($row['certificate_type'])); ?>?id=<?php echo (int)$row['id']; ?>&type=<?php echo urlencode(strtolower(str_replace(' ', '_', $row['certificate_type']))); ?>"
                                                target="_blank"
                                                class="btn-print"
                                                title="Print Certificate"
                                                aria-label="Print certificate">

                                                <i class="fa-solid fa-print" aria-hidden="true"></i>

                                            </a>

                                            <?php if (in_array($row['status'], ['Pending', 'Rejected'], true)): ?>
                                            <form
                                                method="post"
                                                action="certificate_form.php?id=<?php echo (int)$row['id']; ?>"
                                                class="d-inline"
                                                onsubmit="return confirm('Delete this certificate request?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <button
                                                    type="submit"
                                                    class="btn-delete"
                                                    title="Delete Request"
                                                    aria-label="Delete certificate request">
                                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
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
    });
</script>

</body>

</html>
