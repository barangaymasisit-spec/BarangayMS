<?php
require_once __DIR__ . '/layout.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: dashboard.php?denied=1');
    exit;
}

$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(a.details LIKE ? OR a.entity LIKE ? OR a.action LIKE ? OR CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, "")) LIKE ?)';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}
if ($actionFilter !== '') {
    $where[] = 'a.action = ?';
    $params[] = $actionFilter;
    $types .= 's';
}
if ($entityFilter !== '') {
    $where[] = 'a.entity = ?';
    $params[] = $entityFilter;
    $types .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $conn->prepare('SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id' . $whereSql);
if ($params) {
    bindPreparedParams($countStmt, $types, $params);
}
$countStmt->execute();
$totalLogs = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();

$totalPages = max(1, (int)ceil($totalLogs / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT a.action, a.entity, a.details, a.created_at, CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, "")) AS user_name
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id' . $whereSql . '
        ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
$bindParams = $params;
$bindParams[] = $perPage;
$bindParams[] = $offset;
bindPreparedParams($stmt, $types . 'ii', $bindParams);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();

$actions = $conn->query("SELECT DISTINCT action FROM activity_logs WHERE action <> '' ORDER BY action ASC");
$entities = $conn->query("SELECT DISTINCT entity FROM activity_logs WHERE entity <> '' ORDER BY entity ASC");
$queryBase = ['search' => $search, 'action' => $actionFilter, 'entity' => $entityFilter];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Audit History'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('settings.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('audit', 'compact'); ?>
    <main class="main-content">
        <?php renderTopbar('Audit History', 'Review account activity and changes across the system.', 'compact', ['clock' => true, 'dashboardHeader' => true]); ?>

        <div class="content-card filter-card mb-4">
            <div class="card-header-custom">
                <h3><i class="fa-solid fa-filter"></i> Filter Activity</h3>
            </div>
            <form method="get" action="audit.php" class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label" for="auditSearch">Search details</label>
                    <input class="form-control" id="auditSearch" type="search" name="search" value="<?php echo h($search); ?>" placeholder="User, action, entity, or details">
                </div>
                <div class="col-lg-2">
                    <label class="form-label" for="auditAction">Action</label>
                    <select class="form-select" id="auditAction" name="action">
                        <option value="">All actions</option>
                        <?php while ($action = $actions->fetch_assoc()): ?>
                            <option value="<?php echo h($action['action']); ?>" <?php echo $actionFilter === $action['action'] ? 'selected' : ''; ?>><?php echo h(ucfirst($action['action'])); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label" for="auditEntity">Entity</label>
                    <select class="form-select" id="auditEntity" name="entity">
                        <option value="">All entities</option>
                        <?php while ($entity = $entities->fetch_assoc()): ?>
                            <option value="<?php echo h($entity['entity']); ?>" <?php echo $entityFilter === $entity['entity'] ? 'selected' : ''; ?>><?php echo h($entity['entity']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
                    <a href="audit.php" class="btn btn-outline-secondary" title="Clear filters" aria-label="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
                </div>
            </form>
        </div>

        <div class="content-card">
            <div class="card-header-custom d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h3><i class="fa-solid fa-clock-rotate-left"></i> Activity Log</h3>
                <span class="text-muted small"><?php echo $totalLogs; ?> record<?php echo $totalLogs === 1 ? '' : 's'; ?></span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($logs->num_rows === 0): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No audit activity matches these filters.</td></tr>
                        <?php else: ?>
                            <?php while ($log = $logs->fetch_assoc()): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo h(formatDatabaseDateTime($log['created_at'])); ?></td>
                                    <td><?php echo h(trim($log['user_name']) ?: 'System'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo h(strtoupper($log['action'])); ?></span></td>
                                    <td><?php echo h($log['entity']); ?></td>
                                    <td><?php echo h($log['details'] ?: 'No details recorded'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalLogs > $perPage): ?>
                <div class="pagination-bar">
                    <span class="page-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <div class="btn-group">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="audit.php?<?php echo http_build_query(array_merge($queryBase, ['page' => $page - 1])); ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="audit.php?<?php echo http_build_query(array_merge($queryBase, ['page' => $page + 1])); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php renderFooterScripts(); ?>
</body>
</html>
