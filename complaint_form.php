<?php
require_once __DIR__ . '/layout.php';

$isResident = ($_SESSION['role'] ?? '') === 'resident';
$currentResident = $isResident ? currentResident($conn) : null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$complaint = null;
$error = '';

if ($isResident && ($id > 0 || !$currentResident)) {
    header('Location: resident_dashboard.php');
    exit;
}

if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM complaints WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$complaint) {
        header('Location: complaints.php?msg=missing');
        exit;
    }
}

// Delete
if ($complaint && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $conn->prepare('DELETE FROM complaints WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, 'deleted', 'complaints', 'Deleted complaint record.', $id);
    header('Location: complaints.php?msg=deleted');
    exit;
}

$isEdit = $complaint && isset($_GET['edit']);
$isNew = !$complaint;

$residents = $isResident
    ? $conn->query('SELECT id, resident_number, first_name, last_name FROM residents WHERE id = ' . (int)$currentResident['id'])
    : $conn->query('SELECT id, resident_number, first_name, last_name FROM residents ORDER BY last_name ASC, first_name ASC');

// Tracking numbers look like CMP-2026-0007
function nextTrackingNumber(mysqli $conn): string {
    $year = date('Y');
    $count = (int)($conn->query('SELECT COUNT(*) FROM complaints')->fetch_row()[0] ?? 0);
    do {
        $count++;
        $candidate = 'CMP-' . $year . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare('SELECT id FROM complaints WHERE tracking_number = ? LIMIT 1');
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($taken);
    return $candidate;
}

$values = [
    'tracking_number' => $complaint['tracking_number'] ?? nextTrackingNumber($conn),
    'resident_id'     => $complaint['resident_id'] ?? '',
    'resident_name'   => $complaint['resident_name'] ?? '',
    'category'        => $complaint['category'] ?? '',
    'status'          => $complaint['status'] ?? 'Pending',
    'date_filed'      => $complaint['date_filed'] ?? date('Y-m-d'),
    'description'     => $complaint['description'] ?? '',
    'response'        => $complaint['response'] ?? '',
];

if ($isResident) {
    $values['resident_id'] = (string)$currentResident['id'];
    $values['resident_name'] = trim($currentResident['first_name'] . ' ' . $currentResident['last_name']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $values[$field] = trim($_POST[$field] ?? '');
    }
    if ($isResident) {
        $values['tracking_number'] = $complaint['tracking_number'] ?? nextTrackingNumber($conn);
        $values['resident_id'] = (string)$currentResident['id'];
        $values['resident_name'] = trim($currentResident['first_name'] . ' ' . $currentResident['last_name']);
        $values['status'] = 'Pending';
        $values['response'] = '';
    }
    $residentId = (int)$values['resident_id'];

    // A linked resident fills in the name automatically
    if ($residentId > 0) {
        $stmt = $conn->prepare('SELECT first_name, last_name FROM residents WHERE id = ?');
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $linked = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($linked) {
            $values['resident_name'] = trim($linked['first_name'] . ' ' . $linked['last_name']);
        }
    }
    $residentIdOrNull = $residentId > 0 ? $residentId : null;

    if ($values['tracking_number'] === '') {
        $error = 'Tracking number is required.';
    } elseif ($values['resident_name'] === '') {
        $error = 'Please choose a resident or type the complainant name.';
    } elseif ($values['category'] === '') {
        $error = 'Please choose a complaint category.';
    } elseif ($values['description'] === '') {
        $error = 'Please describe the complaint.';
    } else {
        $check = $conn->prepare('SELECT id FROM complaints WHERE tracking_number = ? AND id <> ? LIMIT 1');
        $checkId = $complaint ? $id : 0;
        $check->bind_param('si', $values['tracking_number'], $checkId);
        $check->execute();
        $duplicate = $check->get_result()->fetch_assoc();
        $check->close();

        if ($duplicate) {
            $error = 'That tracking number is already used by another complaint.';
        } elseif ($complaint) {
            $stmt = $conn->prepare('UPDATE complaints SET tracking_number = ?, resident_id = ?, resident_name = ?, category = ?, status = ?, date_filed = ?, description = ?, response = ? WHERE id = ?');
            $stmt->bind_param(
                'sissssssi',
                $values['tracking_number'],
                $residentIdOrNull,
                $values['resident_name'],
                $values['category'],
                $values['status'],
                $values['date_filed'],
                $values['description'],
                $values['response'],
                $id
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'complaints', 'Updated complaint record.', $id);
            header('Location: complaints.php?msg=updated');
            exit;
        } else {
            $stmt = $conn->prepare('INSERT INTO complaints (tracking_number, resident_id, resident_name, category, status, date_filed, description, response) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'sissssss',
                $values['tracking_number'],
                $residentIdOrNull,
                $values['resident_name'],
                $values['category'],
                $values['status'],
                $values['date_filed'],
                $values['description'],
                $values['response']
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'created', 'complaints', 'Created complaint record.', $conn->insert_id);
            header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=complaint_created' : 'complaints.php?msg=created'));
            exit;
        }
    }
}

$showForm = $isNew || $isEdit;
$title = $isNew ? 'New Complaint' : ($isEdit ? 'Edit Complaint' : 'Complaint Details');
$categoryOptions = ['Noise Complaint', 'Boundary Dispute', 'Property Damage', 'Theft', 'Physical Injury', 'Domestic Dispute', 'Public Disturbance', 'Others'];
$statusOptions = ['Pending', 'Ongoing', 'Resolved'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($title); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('complaints', 'compact'); ?>
    <main class="main-content">

        <?php renderTopbar($title, $isNew ? 'File a new complaint record.' : 'View and manage a complaint record.', 'compact', ['clock' => true]); ?>

        <div class="content-card">

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($showForm): ?>

                <div class="card-header-custom">
                    <h3><?php echo $isNew ? 'New Complaint' : 'Edit Complaint'; ?></h3>
                </div>

                <form method="post" action="complaint_form.php<?php echo $complaint ? '?id=' . $id . '&edit=1' : ''; ?>">
                    <?php echo csrfField(); ?>

                    <div class="row g-3">

                        <div class="col-md-4">
                            <label class="form-label" for="tracking_number">Tracking Number *</label>
                            <input type="text" class="form-control" id="tracking_number" name="tracking_number" value="<?php echo h($values['tracking_number']); ?>" required<?php echo $isResident ? ' readonly' : ''; ?>>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="resident_id">Resident</label>
                            <?php if ($isResident): ?>
                            <input type="hidden" name="resident_id" value="<?php echo (int)$currentResident['id']; ?>">
                            <input type="text" class="form-control" id="resident_id" value="<?php echo h(trim($currentResident['first_name'] . ' ' . $currentResident['last_name'])); ?>" readonly>
                            <?php else: ?>
                            <select class="form-select" id="resident_id" name="resident_id">
                                <option value="">Not a registered resident</option>
                                <?php while ($resident = $residents->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$resident['id']; ?>"<?php echo (int)$values['resident_id'] === (int)$resident['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(trim($resident['last_name'] . ', ' . $resident['first_name']) . ' (' . $resident['resident_number'] . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="resident_name">Complainant Name *</label>
                            <input type="text" class="form-control" id="resident_name" name="resident_name" value="<?php echo h($values['resident_name']); ?>"<?php echo $isResident ? ' readonly' : ''; ?>>
                            <small class="text-muted">Filled in automatically when a resident is selected.</small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="category">Category *</label>
                            <select class="form-select" id="category" name="category" required>
                                <option value="">Select category</option>
                                <?php foreach ($categoryOptions as $option): ?>
                                    <option<?php echo $values['category'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-md-4">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option<?php echo $values['status'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label" for="date_filed">Date Filed *</label>
                            <input type="date" class="form-control" id="date_filed" name="date_filed" value="<?php echo h($values['date_filed']); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="4" required><?php echo h($values['description']); ?></textarea>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-12">
                            <label class="form-label" for="response">Action Taken / Response</label>
                            <textarea class="form-control" id="response" name="response" rows="3"><?php echo h($values['response']); ?></textarea>
                        </div>
                        <?php endif; ?>

                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?php echo $isNew ? 'Save Complaint' : 'Update Complaint'; ?>
                        </button>
                        <a href="<?php echo $isResident ? 'resident_dashboard.php' : 'complaints.php'; ?>" class="btn btn-secondary ms-2">
                            <i class="fa-solid fa-xmark"></i>
                            Cancel
                        </a>
                    </div>

                </form>

            <?php else: ?>

                <div class="card-header-custom">
                    <h3>Complaint Record</h3>
                </div>

                <table class="table table-striped align-middle">
                    <tbody>
                        <tr><th style="width:220px">Tracking Number</th><td><?php echo h($complaint['tracking_number']); ?></td></tr>
                        <tr><th>Complainant</th><td><?php echo h($complaint['resident_name']); ?></td></tr>
                        <tr><th>Category</th><td><?php echo h($complaint['category']); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($complaint['status']); ?></td></tr>
                        <tr><th>Date Filed</th><td><?php echo h($complaint['date_filed']); ?></td></tr>
                        <tr><th>Description</th><td><?php echo nl2br(h($complaint['description'])); ?></td></tr>
                        <tr><th>Action Taken</th><td><?php echo nl2br(h($complaint['response'])); ?></td></tr>
                    </tbody>
                </table>

                <div class="mt-3">
                    <a href="complaint_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn btn-primary">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Edit
                    </a>
                                        <form method="post" action="complaint_form.php?id=<?php echo $id; ?>" class="d-inline ms-2"
                                                    onsubmit="return confirm('Delete this complaint record?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                        <i class="fa-solid fa-trash"></i>
                                                <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                    <a href="complaints.php" class="btn btn-secondary ms-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to Complaints
                    </a>
                </div>

            <?php endif; ?>

        </div>

    </main>
</div>
<?php renderFooterScripts(); ?>
</body>
</html>
