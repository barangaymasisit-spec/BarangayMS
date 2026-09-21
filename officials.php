<?php
require_once __DIR__ . '/layout.php';

$photoColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
if ($photoColumn && $photoColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER contact_number");
}
$termColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'term'");
if ($termColumn && $termColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN term VARCHAR(100) DEFAULT NULL AFTER status");
}
$positionColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'position'");
if ($positionColumn && $positionColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN position VARCHAR(100) DEFAULT NULL AFTER term");
}
$roleColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($roleColumn && ($roleDefinition = $roleColumn->fetch_assoc()) && strpos($roleDefinition['Type'], "'health_worker'") === false) {
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','staff','health_worker','security_force','resident') NOT NULL DEFAULT 'admin'");
}
$photoDataColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'photo_data'");
if ($photoDataColumn && $photoDataColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN photo_data MEDIUMBLOB NULL AFTER photo_path");
}
$photoMimeColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'photo_mime'");
if ($photoMimeColumn && $photoMimeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN photo_mime VARCHAR(50) DEFAULT NULL AFTER photo_data");
}
$legacyPhotos = $conn->query("SELECT id, photo_path FROM users WHERE photo_path IS NOT NULL AND photo_path <> '' AND (photo_data IS NULL OR photo_mime IS NULL)");
if ($legacyPhotos) {
    while ($legacyPhoto = $legacyPhotos->fetch_assoc()) {
        $legacyPath = __DIR__ . '/' . $legacyPhoto['photo_path'];
        if (!is_file($legacyPath)) {
            continue;
        }
        $legacyData = file_get_contents($legacyPath);
        $legacyMime = mime_content_type($legacyPath);
        if ($legacyData === false || $legacyMime === false) {
            continue;
        }
        $legacyStmt = $conn->prepare('UPDATE users SET photo_data = ?, photo_mime = ? WHERE id = ?');
        $legacyId = (int)$legacyPhoto['id'];
        $legacyStmt->bind_param('bsi', $legacyData, $legacyMime, $legacyId);
        $legacyStmt->send_long_data(0, $legacyData);
        $legacyStmt->execute();
        $legacyStmt->close();
    }
}

$uploadError = '';
$officialError = '';
$officialSuccess = '';
$editAdminId = (int)($_GET['edit_admin'] ?? 0);
$editAdmin = null;
$editOfficialId = (int)($_GET['edit'] ?? 0);
$editOfficial = null;

function officialPhotoSrc(array $person): ?string {
    if (!empty($person['photo_data']) && !empty($person['photo_mime'])) {
        return 'data:' . $person['photo_mime'] . ';base64,' . base64_encode($person['photo_data']);
    }
    if (!empty($person['photo_path']) && is_file(__DIR__ . '/' . $person['photo_path'])) {
        return $person['photo_path'];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'delete_admin') {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $adminCount = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0] ?? 0);
    if ($adminId === $currentUserId) {
        $officialError = 'You cannot delete the administrator account currently in use.';
    } elseif ($adminCount <= 1) {
        $officialError = 'At least one administrator account must remain.';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        if ($deleted) {
            logActivity($conn, 'deleted', 'users', 'Deleted administrator account.', $adminId);
            header('Location: officials.php?deleted=1');
            exit;
        }
        $officialError = 'Administrator account could not be deleted.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'admin_account') {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $password = $_POST['password'] ?? '';

    if ($firstName === '' || $lastName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $officialError = 'Please provide valid administrator name, username, and email details.';
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
        $officialError = 'Invalid administrator status.';
    } elseif ($adminId === 0 && strlen($password) < 6) {
        $officialError = 'A new administrator password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $stmt->bind_param('ssi', $username, $email, $adminId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($duplicate) {
            $officialError = 'That username or email is already in use.';
        } elseif ($adminId > 0) {
            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, status = ?, password_hash = ? WHERE id = ? AND role = 'admin'");
                $stmt->bind_param('ssssssi', $firstName, $lastName, $username, $email, $status, $passwordHash, $adminId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, status = ? WHERE id = ? AND role = 'admin'");
                $stmt->bind_param('sssssi', $firstName, $lastName, $username, $email, $status, $adminId);
            }
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'users', 'Updated administrator account.', $adminId);
            header('Location: officials.php?saved=1');
            exit;
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'admin', ?)");
            $stmt->bind_param('ssssss', $firstName, $lastName, $username, $email, $passwordHash, $status);
            $stmt->execute();
            $newAdminId = $conn->insert_id;
            $stmt->close();
            logActivity($conn, 'created', 'users', 'Created administrator account.', $newAdminId);
            header('Location: officials.php?saved=1');
            exit;
        }
    }
}

if ($editOfficialId > 0) {
    $stmt = $conn->prepare("SELECT id, first_name, last_name, username, email, role, status, term, position FROM users WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force')");
    $stmt->bind_param('i', $editOfficialId);
    $stmt->execute();
    $editOfficial = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($editAdminId > 0) {
    $stmt = $conn->prepare("SELECT id, first_name, last_name, username, email, status FROM users WHERE id = ? AND role = 'admin'");
    $stmt->bind_param('i', $editAdminId);
    $stmt->execute();
    $editAdmin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'official_account') {
    $officialId = (int)($_POST['official_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'staff';
    $status = $_POST['status'] ?? 'Active';
    $term = trim($_POST['term'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $password = $_POST['password'] ?? '';
    $photoPath = null;
    $photoData = null;
    $photoMime = null;
    $photoFile = $_FILES['official_photo'] ?? null;
    $allowedPhotoTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($firstName === '' || $lastName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $officialError = 'Please provide valid name, username, and email details.';
    } elseif (!in_array($role, ['staff', 'health_worker', 'security_force'], true) || !in_array($status, ['Active', 'Inactive'], true)) {
        $officialError = 'Invalid personnel type or status.';
    } elseif ($position === '') {
        $officialError = 'Please choose an official position.';
    } elseif ($officialId === 0 && strlen($password) < 6) {
        $officialError = 'A new official password must be at least 6 characters.';
    } elseif ($photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && ($photoFile['error'] !== UPLOAD_ERR_OK || $photoFile['size'] > 5 * 1024 * 1024 || !isset($allowedPhotoTypes[mime_content_type($photoFile['tmp_name'])]))) {
        $officialError = 'Photo must be a JPG, PNG, or WEBP image no larger than 5 MB.';
    } else {
        if ($photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $mimeType = mime_content_type($photoFile['tmp_name']);
            $photoData = file_get_contents($photoFile['tmp_name']);
            $photoMime = $mimeType;
            $fileName = 'official_' . bin2hex(random_bytes(8)) . '.' . $allowedPhotoTypes[$mimeType];
            if (!move_uploaded_file($photoFile['tmp_name'], $uploadsDir . '/' . $fileName)) {
                $officialError = 'The official photo could not be uploaded.';
            } else {
                $photoPath = 'uploads/' . $fileName;
            }
        }
        if ($officialError !== '') {
            $editOfficialId = $officialId;
        } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $stmt->bind_param('ssi', $username, $email, $officialId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($duplicate) {
            $officialError = 'That username or email is already in use.';
        } elseif ($officialId > 0) {
            if ($password !== '') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ?, status = ?, term = ?, position = ?, password_hash = ?";
                if ($photoPath !== null) $sql .= ', photo_path = ?';
                $sql .= " WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force')";
                $stmt = $conn->prepare($sql);
                if ($photoPath !== null) {
                    $stmt->bind_param('ssssssssssi', $firstName, $lastName, $username, $email, $role, $status, $term, $position, $passwordHash, $photoPath, $officialId);
                } else {
                    $stmt->bind_param('sssssssssi', $firstName, $lastName, $username, $email, $role, $status, $term, $position, $passwordHash, $officialId);
                }
            } else {
                $sql = "UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?, role = ?, status = ?, term = ?, position = ?";
                if ($photoPath !== null) $sql .= ', photo_path = ?';
                $sql .= " WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force')";
                $stmt = $conn->prepare($sql);
                if ($photoPath !== null) {
                    $stmt->bind_param('sssssssssi', $firstName, $lastName, $username, $email, $role, $status, $term, $position, $photoPath, $officialId);
                } else {
                    $stmt->bind_param('ssssssssi', $firstName, $lastName, $username, $email, $role, $status, $term, $position, $officialId);
                }
            }
            $stmt->execute();
            $stmt->close();
            if ($photoData !== null && $photoMime !== null) {
                $photoStmt = $conn->prepare('UPDATE users SET photo_data = ?, photo_mime = ? WHERE id = ?');
                $photoStmt->bind_param('bsi', $photoData, $photoMime, $officialId);
                $photoStmt->send_long_data(0, $photoData);
                $photoStmt->execute();
                $photoStmt->close();
            }
            logActivity($conn, 'updated', 'users', 'Updated official account details.', $officialId);
            header('Location: officials.php?saved=1');
            exit;
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (first_name, last_name, username, email, password_hash, role, status, term, position, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $photoPathValue = $photoPath ?? '';
            $stmt->bind_param('ssssssssss', $firstName, $lastName, $username, $email, $passwordHash, $role, $status, $term, $position, $photoPathValue);
            $stmt->execute();
            $newOfficialId = $conn->insert_id;
            $stmt->close();
            if ($photoData !== null && $photoMime !== null) {
                $photoStmt = $conn->prepare('UPDATE users SET photo_data = ?, photo_mime = ? WHERE id = ?');
                $photoStmt->bind_param('bsi', $photoData, $photoMime, $newOfficialId);
                $photoStmt->send_long_data(0, $photoData);
                $photoStmt->execute();
                $photoStmt->close();
            }
            logActivity($conn, 'created', 'users', 'Created official account.', $newOfficialId);
            header('Location: officials.php?saved=1');
            exit;
        }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'delete_official') {
    $officialId = (int)($_POST['official_id'] ?? 0);
    if ($officialId === (int)($_SESSION['user_id'] ?? 0)) {
        $officialError = 'You cannot delete the account currently in use.';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force')");
        $stmt->bind_param('i', $officialId);
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'deleted', 'users', 'Deleted official account.', $officialId);
        header('Location: officials.php?deleted=1');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'official_photo') {
    $officialId = (int)($_POST['official_id'] ?? 0);
    $file = $_FILES['official_photo'] ?? null;
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($officialId <= 0 || !$file || $file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Please choose an official photo to upload.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $uploadError = 'The official photo must be 5 MB or smaller.';
    } else {
        $mimeType = mime_content_type($file['tmp_name']);
        if (!isset($allowedTypes[$mimeType])) {
            $uploadError = 'Official photos must be JPG, PNG, or WEBP images.';
        } else {
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $fileName = 'official_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
            $relativePath = 'uploads/' . $fileName;
            if (move_uploaded_file($file['tmp_name'], $uploadsDir . '/' . $fileName)) {
                $stmt = $conn->prepare("UPDATE users SET photo_path = ? WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force')");
                $stmt->bind_param('si', $relativePath, $officialId);
                $stmt->execute();
                $stmt->close();
                $photoData = file_get_contents($uploadsDir . '/' . $fileName);
                $photoStmt = $conn->prepare('UPDATE users SET photo_data = ?, photo_mime = ? WHERE id = ?');
                $photoStmt->bind_param('bsi', $photoData, $mimeType, $officialId);
                $photoStmt->send_long_data(0, $photoData);
                $photoStmt->execute();
                $photoStmt->close();
                logActivity($conn, 'updated', 'users', 'Updated official profile photo.', $officialId);
                header('Location: officials.php?photo=uploaded');
                exit;
            }
            $uploadError = 'The official photo could not be saved.';
        }
    }
}

$photoSuccess = isset($_GET['photo']) && $_GET['photo'] === 'uploaded';
$officialSearch = trim($_GET['official_search'] ?? '');
$officialStatusFilter = $_GET['official_status_filter'] ?? 'all';
$officialRoleFilter = $_GET['official_role_filter'] ?? 'all';
$officialPage = max(1, (int)($_GET['official_page'] ?? 1));
$healthWorkerPage = max(1, (int)($_GET['health_worker_page'] ?? 1));
$securityForcePage = max(1, (int)($_GET['security_force_page'] ?? 1));
$officialsPerPage = 5;

function fetchPersonnelPage(mysqli $conn, string $role, string $search, string $statusFilter, int $page, int $perPage): array {
    $where = ['role = ?'];
    $params = [$role];
    $types = 's';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR position LIKE ? OR term LIKE ?)';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        $types .= 'ssssss';
    }

    if ($statusFilter !== 'all') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    $sql = "SELECT id, first_name, last_name, username, role, email, status, term, position, photo_path, photo_data, photo_mime FROM users WHERE " . implode(' AND ', $where) . " ORDER BY CASE WHEN LOWER(position) LIKE '%vice%' OR LOWER(position) LIKE '%deputy%' THEN 2 WHEN LOWER(position) LIKE '%captain%' OR LOWER(position) LIKE '%punong barangay%' THEN 1 WHEN LOWER(position) LIKE '%kagawad%' OR LOWER(position) LIKE '%councilor%' THEN 3 WHEN LOWER(position) LIKE '%secretary%' THEN 4 WHEN LOWER(position) LIKE '%treasurer%' THEN 5 ELSE 99 END ASC, position ASC, last_name ASC, first_name ASC LIMIT ? OFFSET ?";
    $countSql = 'SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $where);

    $countStmt = $conn->prepare($countSql);
    $countParams = $params;
    bindPreparedParams($countStmt, $types, $countParams);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_row()[0];
    $countStmt->close();

    $page = max(1, $page);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $offset = ($page - 1) * $perPage;

    $stmt = $conn->prepare($sql);
    $bindParams = $params;
    $bindParams[] = $perPage;
    $bindParams[] = $offset;
    bindPreparedParams($stmt, $types . 'ii', $bindParams);
    $stmt->execute();
    $rows = $stmt->get_result();
    $stmt->close();

    return ['rows' => $rows, 'total' => $total, 'page' => min($page, $totalPages), 'total_pages' => $totalPages];
}

$admins = $conn->query("SELECT id, first_name, last_name, username, email, status FROM users WHERE role = 'admin' ORDER BY id ASC");
$officialsData = $officialRoleFilter === 'all' || $officialRoleFilter === 'staff' ? fetchPersonnelPage($conn, 'staff', $officialSearch, $officialStatusFilter, $officialPage, $officialsPerPage) : ['rows' => $conn->query('SELECT id, first_name, last_name, username, role, email, status, term, position, photo_path, photo_data, photo_mime FROM users WHERE role = "staff" AND 1 = 0'), 'total' => 0, 'page' => 1, 'total_pages' => 1];
$healthWorkersData = $officialRoleFilter === 'all' || $officialRoleFilter === 'health_worker' ? fetchPersonnelPage($conn, 'health_worker', $officialSearch, $officialStatusFilter, $healthWorkerPage, $officialsPerPage) : ['rows' => $conn->query('SELECT id, first_name, last_name, username, role, email, status, term, position, photo_path, photo_data, photo_mime FROM users WHERE role = "health_worker" AND 1 = 0'), 'total' => 0, 'page' => 1, 'total_pages' => 1];
$securityForcesData = $officialRoleFilter === 'all' || $officialRoleFilter === 'security_force' ? fetchPersonnelPage($conn, 'security_force', $officialSearch, $officialStatusFilter, $securityForcePage, $officialsPerPage) : ['rows' => $conn->query('SELECT id, first_name, last_name, username, role, email, status, term, position, photo_path, photo_data, photo_mime FROM users WHERE role = "security_force" AND 1 = 0'), 'total' => 0, 'page' => 1, 'total_pages' => 1];
$officials = $officialsData['rows'];
$healthWorkers = $healthWorkersData['rows'];
$securityForces = $securityForcesData['rows'];
$totalOfficials = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('staff', 'health_worker', 'security_force')")->fetch_row()[0] ?? 0);
$totalBarangayOfficials = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetch_row()[0] ?? 0);
$totalAdmins = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetch_row()[0] ?? 0);
$totalHealthWorkers = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'health_worker'")->fetch_row()[0] ?? 0);
$totalSecurityForces = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role = 'security_force'")->fetch_row()[0] ?? 0);
$activeAccounts = (int)($conn->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','staff','health_worker','security_force') AND status = 'Active'")->fetch_row()[0] ?? 0);

function renderPersonnelTable(mysqli_result $people, string $title, string $icon, string $emptyMessage): void {
    echo '<div class="row mt-4"><div class="col-12"><div class="content-card"><div class="card-header-custom"><h3><i class="fa-solid ' . h($icon) . '"></i> ' . h($title) . '</h3></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Photo</th><th>Name</th><th>Position</th><th>Email</th><th>Term</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    if ($people->num_rows === 0) {
        echo '<tr><td colspan="7">' . h($emptyMessage) . '</td></tr>';
    } else {
        while ($person = $people->fetch_assoc()) {
            $personName = trim($person['first_name'] . ' ' . $person['last_name']);
            $photoSrc = officialPhotoSrc($person);
            echo '<tr><td>';
            if ($photoSrc !== null) {
                echo '<a href="' . h($photoSrc) . '" target="_blank" rel="noopener" aria-label="View photo of ' . h($personName) . '"><img src="' . h($photoSrc) . '" alt="' . h($personName) . '" width="52" height="52" style="object-fit: cover; border-radius: 50%;"></a>';
            } else {
                echo '<span class="text-muted">No photo</span>';
            }
            echo '</td><td>' . h($personName) . '</td><td>' . h($person['position'] ?: 'Not set') . '</td><td>' . h($person['email']) . '</td><td>' . h($person['term'] ?: 'Not set') . '</td><td><span class="badge ' . ($person['status'] === 'Active' ? 'bg-success' : 'bg-secondary') . '">' . h($person['status']) . '</span></td><td><a href="officials.php?edit=' . (int)$person['id'] . '" class="btn btn-sm btn-outline-secondary">Edit</a>';
            if ((int)$person['id'] !== (int)($_SESSION['user_id'] ?? 0)) {
                echo '<form action="officials.php" method="post" class="d-inline" onsubmit="return confirm(\'Delete this account?\');">' . csrfField() . '<input type="hidden" name="form_type" value="delete_official"><input type="hidden" name="official_id" value="' . (int)$person['id'] . '"><button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form>';
            }
            echo '</td></tr>';
        }
    }
    echo '</tbody></table></div></div></div></div>';
}

function renderPersonnelPagination(array $pageData, string $search, string $statusFilter, string $roleFilter, string $pageParam = 'official_page'): void {
    if ((int)$pageData['total'] <= 5) {
        return;
    }

    $query = [
        'official_search' => $search,
        'official_status_filter' => $statusFilter,
        'official_role_filter' => $roleFilter,
    ];
    echo '<div class="pagination-bar">';
    echo '<span class="page-status">Page ' . (int)$pageData['page'] . ' of ' . (int)$pageData['total_pages'] . '</span><div class="btn-group">';
    if ((int)$pageData['page'] > 1) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="officials.php?' . h(http_build_query(array_merge($query, [$pageParam => (int)$pageData['page'] - 1]))) . '">Previous</a>';
    }
    if ((int)$pageData['page'] < (int)$pageData['total_pages']) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="officials.php?' . h(http_build_query(array_merge($query, [$pageParam => (int)$pageData['page'] + 1]))) . '">Next</a>';
    }
    echo '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Officials'); ?>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <link rel="stylesheet"
          href="<?php echo asset('settings.css'); ?>">

</head>

<body>

<div class="wrapper">

    <?php renderSidebar('officials', 'compact'); ?>

    <main class="main-content">

        <?php renderTopbar('Officials', 'Barangay officials and administrative accounts.', 'compact', ['clock' => true]); ?>

        <?php if ($uploadError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($uploadError); ?></div>
        <?php elseif ($officialError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($officialError); ?></div>
        <?php elseif ($photoSuccess): ?>
            <div class="alert alert-success" role="status">Official photo uploaded successfully.</div>
        <?php elseif (isset($_GET['saved'])): ?>
            <div class="alert alert-success" role="status">Official account saved successfully.</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success" role="status">Official account deleted successfully.</div>
        <?php endif; ?>

        <div id="liveBarangayOfficials" data-live-personnel>
        <div class="row mt-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h3><i class="fa-solid fa-magnifying-glass"></i> Search and Filter Personnel</h3>
                    </div>
                    <form method="get" action="officials.php" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label" for="officialSearch">Search</label>
                            <input class="form-control" id="officialSearch" type="search" name="official_search" value="<?php echo h($officialSearch); ?>" placeholder="Name, email, username, position">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="officialRoleFilter">Role</label>
                            <select class="form-select" id="officialRoleFilter" name="official_role_filter">
                                <option value="all" <?php echo $officialRoleFilter === 'all' ? 'selected' : ''; ?>>All roles</option>
                                <option value="staff" <?php echo $officialRoleFilter === 'staff' ? 'selected' : ''; ?>>Barangay Officials</option>
                                <option value="health_worker" <?php echo $officialRoleFilter === 'health_worker' ? 'selected' : ''; ?>>Health Workers</option>
                                <option value="security_force" <?php echo $officialRoleFilter === 'security_force' ? 'selected' : ''; ?>>Security Force</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="officialStatusFilter">Status</label>
                            <select class="form-select" id="officialStatusFilter" name="official_status_filter">
                                <option value="all" <?php echo $officialStatusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                                <option value="Active" <?php echo $officialStatusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $officialStatusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-filter"></i> Filter</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        </div>

        <div class="row g-4">

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-user-tie"></i>

                    <h4>Total Officials</h4>

                    <h2><?php echo $totalOfficials; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-user-shield"></i>

                    <h4>Administrators</h4>

                    <h2><?php echo $totalAdmins; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-users-gear"></i>

                    <h4>Barangay Officials</h4>

                    <h2><?php echo $totalBarangayOfficials; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-heart-pulse"></i>

                    <h4>Health Workers</h4>

                    <h2><?php echo $totalHealthWorkers; ?></h2>

                </div>

            </div>

            <div class="col-lg-3">

                <div class="stat-card">

                    <i class="fa-solid fa-shield-halved"></i>

                    <h4>Security Forces</h4>

                    <h2><?php echo $totalSecurityForces; ?></h2>

                </div>

            </div>

        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h3><i class="fa-solid fa-user-shield"></i> <?php echo $editAdmin ? 'Edit Administrator Account' : 'Create Administrator Account'; ?></h3>
                    </div>
                    <form method="post" action="officials.php" class="row g-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="admin_account">
                        <input type="hidden" name="admin_id" value="<?php echo (int)($editAdmin['id'] ?? 0); ?>">
                        <div class="col-md-3"><label class="form-label" for="adminFirstName">First Name</label><input class="form-control" id="adminFirstName" name="first_name" required value="<?php echo h($editAdmin['first_name'] ?? ''); ?>" placeholder="First Name"></div>
                        <div class="col-md-3"><label class="form-label" for="adminLastName">Last Name</label><input class="form-control" id="adminLastName" name="last_name" required value="<?php echo h($editAdmin['last_name'] ?? ''); ?>" placeholder="Last Name"></div>
                        <div class="col-md-3"><label class="form-label" for="adminUsername">Username</label><input class="form-control" id="adminUsername" name="username" required value="<?php echo h($editAdmin['username'] ?? ''); ?>" placeholder="Username"></div>
                        <div class="col-md-3"><label class="form-label" for="adminEmail">Email</label><input type="email" class="form-control" id="adminEmail" name="email" required value="<?php echo h($editAdmin['email'] ?? ''); ?>" placeholder="Email Address"></div>
                        <div class="col-md-3"><label class="form-label" for="adminStatus">Status</label><select class="form-select" id="adminStatus" name="status"><option value="Active"<?php echo ($editAdmin['status'] ?? 'Active') === 'Active' ? ' selected' : ''; ?>>Active</option><option value="Inactive"<?php echo ($editAdmin['status'] ?? '') === 'Inactive' ? ' selected' : ''; ?>>Inactive</option></select></div>
                        <div class="col-md-3"><label class="form-label" for="adminPassword">Password <?php echo $editAdmin ? '(optional)' : ''; ?></label><input type="password" class="form-control" id="adminPassword" name="password"<?php echo $editAdmin ? '' : ' required'; ?> minlength="6" placeholder="<?php echo $editAdmin ? 'Leave blank to keep current' : 'At least 6 characters'; ?>"></div>
                        <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php echo $editAdmin ? 'Update Administrator' : 'Create Administrator'; ?></button><?php if ($editAdmin): ?><a href="officials.php" class="btn btn-secondary ms-2">Cancel Edit</a><?php endif; ?></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h3><i class="fa-solid fa-user-plus"></i> <?php echo $editOfficial ? 'Edit Personnel' : 'Add Barangay Personnel'; ?></h3>
                    </div>
                    <form method="post" action="officials.php" enctype="multipart/form-data" class="row g-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="form_type" value="official_account">
                        <input type="hidden" name="official_id" value="<?php echo (int)($editOfficial['id'] ?? 0); ?>">
                        <div class="col-md-3"><label class="form-label" for="officialFirstName">First Name</label><input class="form-control" id="officialFirstName" name="first_name" required value="<?php echo h($editOfficial['first_name'] ?? ''); ?>" placeholder="First Name"></div>
                        <div class="col-md-3"><label class="form-label" for="officialLastName">Last Name</label><input class="form-control" id="officialLastName" name="last_name" required value="<?php echo h($editOfficial['last_name'] ?? ''); ?>" placeholder="Last Name"></div>
                        <div class="col-md-3"><label class="form-label" for="officialUsername">Username</label><input class="form-control" id="officialUsername" name="username" required value="<?php echo h($editOfficial['username'] ?? ''); ?>" placeholder="Username"></div>
                        <div class="col-md-3"><label class="form-label" for="officialEmail">Email</label><input type="email" class="form-control" id="officialEmail" name="email" required value="<?php echo h($editOfficial['email'] ?? ''); ?>" placeholder="Email Address"></div>
                        <div class="col-md-3"><label class="form-label" for="officialTerm">Term</label><input class="form-control" id="officialTerm" name="term" value="<?php echo h($editOfficial['term'] ?? ''); ?>" placeholder="e.g. 2023 - 2026"></div>
                        <div class="col-md-3"><label class="form-label" for="officialType">Personnel Type</label><select class="form-select" id="officialType" name="role" required><option value="staff"<?php echo ($editOfficial['role'] ?? 'staff') === 'staff' ? ' selected' : ''; ?>>Barangay Official</option><option value="health_worker"<?php echo ($editOfficial['role'] ?? '') === 'health_worker' ? ' selected' : ''; ?>>Barangay Health Worker</option><option value="security_force"<?php echo ($editOfficial['role'] ?? '') === 'security_force' ? ' selected' : ''; ?>>Barangay Security Force</option></select></div>
                        <div class="col-md-3"><label class="form-label" for="officialPosition">Position</label><input class="form-control" id="officialPosition" name="position" required value="<?php echo h($editOfficial['position'] ?? ''); ?>" placeholder="e.g. Barangay Captain"></div>
                        <div class="col-md-3"><label class="form-label" for="officialStatus">Status</label><select class="form-select" id="officialStatus" name="status"><option value="Active"<?php echo ($editOfficial['status'] ?? 'Active') === 'Active' ? ' selected' : ''; ?>>Active</option><option value="Inactive"<?php echo ($editOfficial['status'] ?? '') === 'Inactive' ? ' selected' : ''; ?>>Inactive</option></select></div>
                        <div class="col-md-3"><label class="form-label" for="officialPassword">Password <?php echo $editOfficial ? '(optional)' : ''; ?></label><input type="password" class="form-control" id="officialPassword" name="password"<?php echo $editOfficial ? '' : ' required'; ?> placeholder="<?php echo $editOfficial ? 'Leave blank to keep current' : 'At least 6 characters'; ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="officialPhoto">Official Photo</label><input type="file" class="form-control" id="officialPhoto" name="official_photo" accept="image/jpeg,image/png,image/webp"><small class="text-muted">JPG, PNG, or WEBP up to 5 MB.</small></div>
                        <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?php echo $editOfficial ? 'Update Personnel' : 'Add Personnel'; ?></button><?php if ($editOfficial): ?><a href="officials.php" class="btn btn-secondary ms-2">Cancel Edit</a><?php endif; ?></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="row mt-4">

            <div class="col-12">

                <div class="content-card">

                    <div class="card-header-custom">

                        <h3>

                            <i class="fa-solid fa-user-tie"></i>

                            Barangay Officials

                        </h3>

                    </div>

                    <div class="table-responsive">

                        <table class="table align-middle">

                            <thead>

                                <tr>

                                    <th>Photo</th>
                                    <th>Name</th>

                                    <th>Position</th>

                                    <th>Email</th>

                                    <th>Term</th>

                                    <th>Status</th>

                                    <th>Actions</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if ($officials->num_rows === 0): ?>

                                    <tr>
                                        <td colspan="7">No officials found.</td>
                                    </tr>

                                <?php else: ?>

                                    <?php while ($official = $officials->fetch_assoc()): ?>
                                        <?php $officialPhotoSrc = officialPhotoSrc($official); ?>

                                        <tr>

                                            <td>
                                                <?php if ($officialPhotoSrc !== null): ?>
                                                    <a href="<?php echo h($officialPhotoSrc); ?>" target="_blank" rel="noopener" aria-label="View photo of <?php echo h(trim($official['first_name'] . ' ' . $official['last_name'])); ?>">
                                                        <img src="<?php echo h($officialPhotoSrc); ?>" alt="<?php echo h(trim($official['first_name'] . ' ' . $official['last_name'])); ?>" width="52" height="52" style="object-fit: cover; border-radius: 50%;">
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">No photo</span>
                                                <?php endif; ?>
                                            </td>

                                            <td><?php echo h(trim($official['first_name'] . ' ' . $official['last_name'])); ?></td>

                                            <td><?php echo h($official['position'] ?: 'Not set'); ?></td>

                                            <td><?php echo h($official['email']); ?></td>

                                            <td><?php echo h($official['term'] ?: 'Not set'); ?></td>

                                            <td>
                                                <span class="badge <?php echo $official['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo h($official['status']); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <a href="officials.php?edit=<?php echo (int)$official['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                                <?php if ((int)$official['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                                    <form action="officials.php" method="post" class="d-inline" onsubmit="return confirm('Delete this official account?');">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="form_type" value="delete_official">
                                                        <input type="hidden" name="official_id" value="<?php echo (int)$official['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>

                                        </tr>

                                    <?php endwhile; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php renderPersonnelPagination($officialsData, $officialSearch, $officialStatusFilter, $officialRoleFilter, 'official_page'); ?>

                </div>

            </div>

        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="content-card">
                    <div class="card-header-custom"><h3><i class="fa-solid fa-user-shield"></i> Administrators</h3></div>
                    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                        <?php if ($admins->num_rows === 0): ?><tr><td colspan="5">No administrator accounts found.</td></tr><?php else: ?>
                            <?php while ($admin = $admins->fetch_assoc()): ?><tr><td><?php echo h(trim($admin['first_name'] . ' ' . $admin['last_name'])); ?></td><td><?php echo h($admin['username']); ?></td><td><?php echo h($admin['email']); ?></td><td><span class="badge <?php echo $admin['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo h($admin['status']); ?></span></td><td><a href="officials.php?edit_admin=<?php echo (int)$admin['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a><?php if ((int)$admin['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?><form action="officials.php" method="post" class="d-inline" onsubmit="return confirm('Delete this administrator account?');"><?php echo csrfField(); ?><input type="hidden" name="form_type" value="delete_admin"><input type="hidden" name="admin_id" value="<?php echo (int)$admin['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td></tr><?php endwhile; ?>
                        <?php endif; ?>
                    </tbody></table></div>
                </div>
            </div>
        </div>

        <div id="liveHealthWorkers" data-live-personnel>
            <?php renderPersonnelTable($healthWorkers, 'Barangay Health Workers', 'fa-heart-pulse', 'No barangay health workers found.'); ?>
            <?php renderPersonnelPagination($healthWorkersData, $officialSearch, $officialStatusFilter, $officialRoleFilter, 'health_worker_page'); ?>
        </div>
        <div id="liveSecurityForces" data-live-personnel>
            <?php renderPersonnelTable($securityForces, 'Barangay Security Forces', 'fa-shield-halved', 'No barangay security forces found.'); ?>
            <?php renderPersonnelPagination($securityForcesData, $officialSearch, $officialStatusFilter, $officialRoleFilter, 'security_force_page'); ?>
        </div>

    </main>

</div>

<?php renderFooterScripts(); ?>

</body>

</html>
