<?php
require_once __DIR__ . '/layout.php';

if (!databaseTableExists($conn, 'announcements')) {
    http_response_code(503);
    header('Retry-After: 60');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php pageTitle('Announcements temporarily unavailable'); ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="<?php echo asset('settings.css'); ?>">
        <link rel="stylesheet" href="<?php echo asset('announcements.css'); ?>">
    </head>
    <body>
    <div class="wrapper">
        <?php renderSidebar('announcements', 'compact'); ?>
        <main class="main-content announcements-page">
            <?php renderTopbar('Announcements', 'News and notices from your barangay office.', 'compact'); ?>
            <div class="alert alert-warning mt-4" role="status">
                The announcements board is temporarily unavailable. Please try again shortly.
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$role = $_SESSION['role'] ?? '';
$canManage = in_array($role, ['admin', 'staff'], true);
$userId = (int)($_SESSION['user_id'] ?? 0);
$error = '';
$staffRoleOptions = [
    'admin' => 'Administrators',
    'staff' => 'Barangay Officials',
    'health_worker' => 'Health Workers',
    'security_force' => 'Security Force',
];
$formValues = [
    'title' => '',
    'body' => '',
    'status' => 'Active',
    'audience' => 'all_residents',
    'target_purok' => '',
    'target_roles' => [],
];
$editingAnnouncement = null;

function announcementCharacterCount(string $value): int|false {
    $count = preg_match_all('/./us', $value);
    return $count === false ? false : $count;
}

function announcementAudienceLabel(array $announcement, array $staffRoleOptions): string {
    return match ($announcement['audience'] ?? '') {
        'all_residents' => 'All Residents',
        'purok' => 'Purok / Zone: ' . (string)($announcement['target_purok'] ?? ''),
        'staff' => 'Staff: ' . implode(', ', array_map(
            static fn($role) => $staffRoleOptions[$role] ?? $role,
            array_filter(explode(',', (string)($announcement['target_roles'] ?? '')))
        )),
        default => 'Unspecified',
    };
}

$purokResult = $conn->query("SELECT DISTINCT TRIM(purok) AS purok FROM residents WHERE TRIM(COALESCE(purok, '')) <> '' ORDER BY TRIM(purok)");
$puroks = $purokResult->fetch_all(MYSQLI_ASSOC);

$editId = $canManage ? max(0, (int)($_GET['edit'] ?? 0)) : 0;

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT id, title, body, status, audience, target_purok, target_roles FROM announcements WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editingAnnouncement = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$editingAnnouncement) {
        header('Location: announcements.php?msg=missing');
        exit;
    }
    $formValues = [
        'title' => $editingAnnouncement['title'],
        'body' => $editingAnnouncement['body'],
        'status' => $editingAnnouncement['status'],
        'audience' => $editingAnnouncement['audience'] ?? 'all_residents',
        'target_purok' => $editingAnnouncement['target_purok'] ?? '',
        'target_roles' => array_filter(explode(',', (string)($editingAnnouncement['target_roles'] ?? ''))),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = trim($_POST['action'] ?? '');
    $postedId = max(0, (int)($_POST['id'] ?? 0));

    if (in_array($action, ['create', 'save'], true)) {
        $postedTargetRoles = is_array($_POST['target_roles'] ?? null) ? $_POST['target_roles'] : [];
        $formValues = [
            'title' => trim($_POST['title'] ?? ''),
            'body' => trim($_POST['body'] ?? ''),
            'status' => trim($_POST['status'] ?? 'Draft'),
            'audience' => trim($_POST['audience'] ?? ''),
            'target_purok' => trim($_POST['target_purok'] ?? ''),
            'target_roles' => array_values(array_unique(array_filter(
                $postedTargetRoles,
                static fn($value) => is_string($value) && isset($staffRoleOptions[$value])
            ))),
        ];

        $titleLength = announcementCharacterCount($formValues['title']);
        $bodyLength = announcementCharacterCount($formValues['body']);
        if ($action === 'save' && ($postedId <= 0 || $postedId !== $editId || !$editingAnnouncement)) {
            $error = 'That announcement could not be found. Reload the page and try again.';
        } elseif ($formValues['title'] === '' || $titleLength === false || $titleLength > 200) {
            $error = 'Enter an announcement title of 1 to 200 characters.';
        } elseif ($formValues['body'] === '' || $bodyLength === false || $bodyLength > 12000) {
            $error = 'Enter announcement details of 1 to 12,000 characters.';
        } elseif (!in_array($formValues['status'], ['Active', 'Archived', 'Draft'], true)) {
            $error = 'Choose a valid announcement status.';
        } elseif (!in_array($formValues['audience'], ['all_residents', 'purok', 'staff'], true)) {
            $error = 'Choose a valid announcement audience.';
        } elseif ($formValues['audience'] === 'purok' && $formValues['target_purok'] === '') {
            $error = 'Choose or enter the Purok / Zone for this announcement.';
        } elseif ($formValues['audience'] === 'staff' && $formValues['target_roles'] === []) {
            $error = 'Select at least one staff role for this announcement.';
        } else {
            $targetRoles = implode(',', $formValues['target_roles']);
            $targetPurok = $formValues['audience'] === 'purok' ? $formValues['target_purok'] : null;
            $targetRolesValue = $formValues['audience'] === 'staff' ? $targetRoles : null;
            if ($action === 'save') {
                $stmt = $conn->prepare('UPDATE announcements SET title = ?, body = ?, status = ?, audience = ?, target_purok = ?, target_roles = ? WHERE id = ?');
                $stmt->bind_param('ssssssi', $formValues['title'], $formValues['body'], $formValues['status'], $formValues['audience'], $targetPurok, $targetRolesValue, $postedId);
                $stmt->execute();
                $stmt->close();
                logActivity($conn, 'updated', 'announcements', 'Updated announcement: ' . $formValues['title'] . '.', $postedId);
                header('Location: announcements.php?msg=updated');
                exit;
            }

            $stmt = $conn->prepare('INSERT INTO announcements (title, body, status, audience, target_purok, target_roles, created_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
            $stmt->bind_param('ssssssi', $formValues['title'], $formValues['body'], $formValues['status'], $formValues['audience'], $targetPurok, $targetRolesValue, $userId);
            $stmt->execute();
            $announcementId = (int)$stmt->insert_id;
            $stmt->close();
            logActivity($conn, strtolower($formValues['status']), 'announcements', 'Created announcement: ' . $formValues['title'] . '.', $announcementId);
            header('Location: announcements.php?msg=created');
            exit;
        }
    } elseif (in_array($action, ['archive', 'publish'], true) && $postedId > 0) {
        $newStatus = $action === 'archive' ? 'Archived' : 'Active';
        if ($action === 'publish') {
            $stmt = $conn->prepare("UPDATE announcements SET status = ?, published_at = UTC_TIMESTAMP() WHERE id = ? AND status <> 'Active'");
        } else {
            $stmt = $conn->prepare("UPDATE announcements SET status = ? WHERE id = ? AND status = 'Active'");
        }
        $stmt->bind_param('si', $newStatus, $postedId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        if ($changed) {
            logActivity(
                $conn,
                $action === 'archive' ? 'archived' : 'published',
                'announcements',
                $action === 'archive' ? 'Archived announcement.' : 'Activated announcement.',
                $postedId
            );
            header('Location: announcements.php?msg=' . ($action === 'archive' ? 'archived' : 'published'));
            exit;
        }

        header('Location: announcements.php?msg=updated');
        exit;
    }
}

$showForm = $canManage && ($editId > 0 || isset($_GET['create']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== ''));
$activeSql = "SELECT a.id, a.title, a.body, a.status, a.audience, a.target_purok, a.published_at, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.status = 'Active'";
$resident = $role === 'resident' ? currentResident($conn) : null;
[$audienceSql, $activeParams, $activeTypes] = announcementAudienceFilter((string)$role, $resident);
$activeSql .= ' AND (' . $audienceSql . ')';
$activeSql .= ' ORDER BY a.published_at DESC, a.id DESC';
$activeStmt = $conn->prepare($activeSql);
if ($activeParams) {
    bindPreparedParams($activeStmt, $activeTypes, $activeParams);
}
$activeStmt->execute();
$activeAnnouncements = $activeStmt->get_result();

$allAnnouncements = null;
if ($canManage) {
    $allAnnouncements = $conn->query("SELECT a.id, a.title, a.status, a.audience, a.target_purok, a.target_roles, a.published_at, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC, a.id DESC");
}

$activeCountSql = "SELECT COUNT(*) FROM announcements a WHERE a.status = 'Active'";
$activeCountSql .= ' AND (' . $audienceSql . ')';
$activeCountStmt = $conn->prepare($activeCountSql);
if ($activeParams) {
    bindPreparedParams($activeCountStmt, $activeTypes, $activeParams);
}
$activeCountStmt->execute();
$activeCount = (int)$activeCountStmt->get_result()->fetch_row()[0];
$activeCountStmt->close();

$totalCount = $canManage ? (int)($conn->query('SELECT COUNT(*) FROM announcements')->fetch_row()[0] ?? 0) : $activeCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Announcements'); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('settings.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('announcements.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('announcements', 'compact'); ?>
    <main class="main-content announcements-page">
        <?php
        $newAnnouncementAction = $canManage
            ? '<a href="announcements.php?create=1" class="announcement-create-button"><i class="fa-solid fa-plus" aria-hidden="true"></i> New announcement</a>'
            : '';
        renderTopbar('Announcements', 'News and notices from your barangay office.', 'compact', ['clock' => true, 'dashboardHeader' => true, 'actions' => $newAnnouncementAction]);
        renderNotice([
            'created' => 'Announcement created.',
            'updated' => 'Announcement updated.',
            'archived' => 'Announcement archived.',
            'published' => 'Announcement activated.',
            'missing' => 'That announcement no longer exists.',
        ]);
        ?>

        <section class="announcement-banner" aria-labelledby="announcementBannerTitle">
            <div class="announcement-banner-copy">
                <span class="announcement-eyebrow"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> BARANGAY BULLETIN</span>
                <h2 id="announcementBannerTitle">What’s happening in your community</h2>
                <p>Important updates, notices, and community news — all in one place.</p>
            </div>
            <div class="announcement-banner-mark" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></div>
        </section>

        <?php if ($showForm): ?>
            <section class="content-card announcement-form-card" id="announcementForm" aria-labelledby="announcementFormTitle">
                <div class="card-header-custom">
                    <h3 id="announcementFormTitle"><?php echo $editingAnnouncement ? 'Edit announcement' : 'New announcement'; ?></h3>
                    <a href="announcements.php" class="announcement-cancel-link">Cancel</a>
                </div>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
                <?php endif; ?>
                <form method="post" action="announcements.php<?php echo $editingAnnouncement ? '?edit=' . (int)$editingAnnouncement['id'] : '?create=1'; ?>" class="announcement-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="<?php echo $editingAnnouncement ? 'save' : 'create'; ?>">
                    <?php if ($editingAnnouncement): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editingAnnouncement['id']; ?>">
                    <?php endif; ?>
                    <label for="announcementTitle">Title</label>
                    <input id="announcementTitle" name="title" type="text" maxlength="200" required value="<?php echo h($formValues['title']); ?>" placeholder="Give your announcement a clear headline">
                    <label for="announcementBody">Details</label>
                    <textarea id="announcementBody" name="body" rows="5" maxlength="12000" required placeholder="Share the details people need to know…"><?php echo h($formValues['body']); ?></textarea>
                    <label for="announcementStatus">Status</label>
                    <select id="announcementStatus" name="status" required>
                        <?php foreach (['Active', 'Archived', 'Draft'] as $statusOption): ?>
                            <option value="<?php echo h($statusOption); ?>"<?php echo $formValues['status'] === $statusOption ? ' selected' : ''; ?>><?php echo h($statusOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="announcementAudience">Who should see this announcement?</label>
                    <select id="announcementAudience" name="audience" required>
                        <option value="all_residents"<?php echo $formValues['audience'] === 'all_residents' ? ' selected' : ''; ?>>All Residents</option>
                        <option value="purok"<?php echo $formValues['audience'] === 'purok' ? ' selected' : ''; ?>>Specific Purok / Zone</option>
                        <option value="staff"<?php echo $formValues['audience'] === 'staff' ? ' selected' : ''; ?>>Admin / Barangay Staff Only</option>
                    </select>
                    <div data-announcement-audience="purok">
                        <label for="announcementPurok">Purok / Zone</label>
                        <select id="announcementPurok" name="target_purok">
                            <option value="">Select Purok / Zone</option>
                            <?php foreach ($puroks as $purokOption): ?>
                                <option value="<?php echo h($purokOption['purok']); ?>"<?php echo $formValues['target_purok'] === $purokOption['purok'] ? ' selected' : ''; ?>><?php echo h($purokOption['purok']); ?></option>
                            <?php endforeach; ?>
                            <?php if ($formValues['target_purok'] !== '' && !in_array($formValues['target_purok'], array_column($puroks, 'purok'), true)): ?>
                                <option value="<?php echo h($formValues['target_purok']); ?>" selected><?php echo h($formValues['target_purok']); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <fieldset class="announcement-role-targets" data-announcement-audience="staff">
                        <legend>Staff roles to notify</legend>
                        <?php foreach ($staffRoleOptions as $staffRole => $staffRoleLabel): ?>
                            <label><input type="checkbox" name="target_roles[]" value="<?php echo h($staffRole); ?>"<?php echo in_array($staffRole, $formValues['target_roles'], true) ? ' checked' : ''; ?>> <?php echo h($staffRoleLabel); ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="announcement-form-footer">
                        <button type="submit" class="announcement-submit-button">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            <?php echo $editingAnnouncement ? 'Save changes' : 'Save announcement'; ?>
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <div class="announcement-section-heading">
            <div>
                <span class="announcement-eyebrow">LATEST FROM THE OFFICE</span>
                <h2>Community updates</h2>
            </div>
            <span class="announcement-count"><i class="fa-regular fa-newspaper" aria-hidden="true"></i> <?php echo $activeCount; ?> active</span>
        </div>

        <section class="announcement-list" aria-label="Active announcements">
            <?php if ($activeAnnouncements->num_rows === 0): ?>
                <div class="announcement-empty">
                    <span class="announcement-empty-icon"><i class="fa-regular fa-envelope-open" aria-hidden="true"></i></span>
                    <h3>No announcements yet</h3>
                    <p>When the barangay office shares an update, it will appear here.</p>
                </div>
            <?php else: ?>
                <?php while ($announcement = $activeAnnouncements->fetch_assoc()): ?>
                    <article class="announcement-card">
                        <div class="announcement-card-top">
                            <span class="announcement-category"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> <?php echo h(announcementAudienceLabel($announcement, $staffRoleOptions)); ?></span>
                        </div>
                        <h3><?php echo h($announcement['title']); ?></h3>
                        <div class="announcement-body"><?php echo nl2br(h($announcement['body'])); ?></div>
                        <footer>
                            <span><i class="fa-regular fa-clock" aria-hidden="true"></i> <?php echo h(formatDatabaseDateTime($announcement['published_at'])); ?></span>
                            <?php if (trim($announcement['author']) !== ''): ?>
                                <span>Posted by <?php echo h(trim($announcement['author'])); ?></span>
                            <?php endif; ?>
                        </footer>
                    </article>
                <?php endwhile; ?>
            <?php endif; ?>
        </section>

        <?php if ($canManage): ?>
            <section class="content-card announcement-manage-card" aria-labelledby="announcementManageTitle">
                <div class="card-header-custom">
                    <h3 id="announcementManageTitle"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Manage announcements</h3>
                    <span class="text-muted small"><?php echo $totalCount; ?> total</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr><th>Announcement</th><th>Audience</th><th>Posted</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$allAnnouncements || $allAnnouncements->num_rows === 0): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No announcements yet.</td></tr>
                            <?php else: ?>
                                <?php while ($managed = $allAnnouncements->fetch_assoc()): ?>
                                    <?php
                                    $statusLabel = in_array($managed['status'], ['Active', 'Archived', 'Draft'], true) ? $managed['status'] : 'Draft';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo h($managed['title']); ?></strong><?php if (trim($managed['author']) !== ''): ?><div class="small text-muted">By <?php echo h(trim($managed['author'])); ?></div><?php endif; ?></td>
                                        <td><?php echo h(announcementAudienceLabel($managed, $staffRoleOptions)); ?></td>
                                        <td class="text-nowrap"><?php echo h(formatDatabaseDateTime($managed['published_at'], 'M d, Y')); ?></td>
                                        <td><span class="announcement-status announcement-status-<?php echo strtolower($statusLabel); ?>"><?php echo h($statusLabel); ?></span></td>
                                        <td class="text-nowrap">
                                            <a class="btn btn-sm btn-outline-primary" href="announcements.php?edit=<?php echo (int)$managed['id']; ?>" aria-label="Edit <?php echo h($managed['title']); ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a>
                                            <form method="post" action="announcements.php" class="d-inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="id" value="<?php echo (int)$managed['id']; ?>">
                                                <?php if ($managed['status'] !== 'Active'): ?>
                                                    <button class="btn btn-sm btn-outline-success" type="submit" name="action" value="publish"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Activate</button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="archive" onclick="return confirm('Archive this announcement? It will no longer appear to other users.');"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archive</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php renderFooterScripts(); ?>
<script>
    const audienceSelect = document.getElementById('announcementAudience');
    const audienceFields = document.querySelectorAll('[data-announcement-audience]');
    function updateAudienceFields() {
        if (!audienceSelect) return;
        audienceFields.forEach((field) => {
            const visible = field.dataset.announcementAudience === audienceSelect.value;
            field.hidden = !visible;
            field.querySelectorAll('input, select').forEach((input) => {
                input.disabled = !visible;
                if (input.name === 'target_purok') input.required = visible;
                if (input.name === 'target_roles[]') input.required = false;
            });
        });
    }
    audienceSelect?.addEventListener('change', updateAudienceFields);
    updateAudienceFields();
</script>
</body>
</html>
