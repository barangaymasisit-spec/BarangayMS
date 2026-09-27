<?php
require_once __DIR__ . '/layout.php';

$isResident = ($_SESSION['role'] ?? '') === 'resident';
$currentResident = $isResident ? currentResident($conn) : null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$certificate = null;
$error = '';

if ($isResident && ($id > 0 || !$currentResident)) {
    header('Location: resident_dashboard.php');
    exit;
}

if ($id > 0) {
    $stmt = $conn->prepare('SELECT c.*, r.first_name, r.last_name FROM certificates c LEFT JOIN residents r ON c.resident_id = r.id WHERE c.id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $certificate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$certificate) {
        header('Location: certificates.php?msg=missing');
        exit;
    }
}

// Delete: only for requests never issued; approved/released ones stay on record.
$canDelete = $certificate && in_array($certificate['status'], ['Pending', 'Rejected'], true);
if ($canDelete && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $conn->prepare('DELETE FROM certificates WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, 'deleted', 'certificates', 'Deleted certificate request.', $id);
    header('Location: certificates.php?msg=deleted');
    exit;
}

$isEdit = $certificate && isset($_GET['edit']);
$isNew = !$certificate;

$residents = $isResident
    ? $conn->query('SELECT id, resident_number, first_name, last_name FROM residents WHERE id = ' . (int)$currentResident['id'])
    : $conn->query('SELECT id, resident_number, first_name, last_name FROM residents ORDER BY last_name ASC, first_name ASC');

$values = [
    'resident_id'      => $certificate['resident_id'] ?? '',
    'certificate_type' => $certificate['certificate_type'] ?? '',
    'status'           => $certificate['status'] ?? 'Pending',
    'request_date'     => $certificate['request_date'] ?? date('Y-m-d'),
    'approved_date'    => $certificate['approved_date'] ?? '',
    'purpose'          => $certificate['purpose'] ?? '',
    'remarks'          => $certificate['remarks'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $values[$field] = trim($_POST[$field] ?? '');
    }
    if ($isResident) {
        $values['resident_id'] = (string)$currentResident['id'];
        $values['status'] = 'Pending';
        $values['approved_date'] = '';
        $values['request_date'] = date('Y-m-d');
    }
    $residentId = (int)$values['resident_id'];
    $approvedDate = $values['approved_date'] !== '' ? $values['approved_date'] : null;

    if ($residentId <= 0) {
        $error = 'Please choose the resident requesting the certificate.';
    } elseif ($values['certificate_type'] === '') {
        $error = 'Please choose a certificate type.';
    } elseif ($values['request_date'] === '') {
        $error = 'Request date is required.';
    } else {
        if ($certificate) {
            $stmt = $conn->prepare('UPDATE certificates SET resident_id = ?, certificate_type = ?, status = ?, request_date = ?, approved_date = ?, purpose = ?, remarks = ? WHERE id = ?');
            $stmt->bind_param(
                'issssssi',
                $residentId,
                $values['certificate_type'],
                $values['status'],
                $values['request_date'],
                $approvedDate,
                $values['purpose'],
                $values['remarks'],
                $id
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'certificates', 'Updated certificate request.', $id);
            header('Location: certificates.php?msg=updated');
            exit;
        }

        $stmt = $conn->prepare('INSERT INTO certificates (resident_id, certificate_type, status, request_date, approved_date, purpose, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param(
            'issssss',
            $residentId,
            $values['certificate_type'],
            $values['status'],
            $values['request_date'],
            $approvedDate,
            $values['purpose'],
            $values['remarks']
        );
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'created', 'certificates', 'Created certificate request.', $conn->insert_id);
        header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=certificate_created' : 'certificates.php?msg=created'));
        exit;
    }
}

$showForm = $isNew || $isEdit;
$title = $isNew ? 'New Certificate Request' : ($isEdit ? 'Edit Certificate Request' : 'Certificate Request Details');
$typeOptions = ['Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Business Clearance', 'Good Moral'];
$statusOptions = ['Pending', 'Approved', 'Released', 'Rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($title); ?>
    <link rel="stylesheet" href="<?php echo asset('certification.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar($isResident ? 'resident_certificate' : 'certificates'); ?>
    <main class="main-content">

        <?php renderTopbar($title, $isNew ? 'Record a new certificate request.' : 'View and manage a certificate request.', 'panel', ['breadcrumb' => 'Online Certifications', 'clock' => true]); ?>

        <div class="content-card">

            <?php if ($error !== ''): ?>
                <div class="notice notice-error" role="alert"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($showForm && $residents->num_rows === 0): ?>

                <h3>No residents on file</h3>
                <p>A certificate request has to be linked to a resident. Please add a resident record first.</p>
                <div class="record-actions">
                    <a href="resident_form.php" class="btn-primary">
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                        <span>Add Resident</span>
                    </a>
                    <a href="certificates.php" class="btn-cancel">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Back to Certifications</span>
                    </a>
                </div>

            <?php elseif ($showForm): ?>

                <h3><?php echo $isNew ? 'New Request' : 'Edit Request'; ?></h3>

                <form class="record-form" method="post" action="certificate_form.php<?php echo $certificate ? '?id=' . $id . '&edit=1' : ''; ?>">
                    <?php echo csrfField(); ?>

                    <div class="record-grid">

                        <div class="form-group">
                            <label for="resident_id">Resident *</label>
                            <?php if ($isResident): ?>
                                <input type="hidden" name="resident_id" value="<?php echo (int)$currentResident['id']; ?>">
                                <input type="text" id="resident_id" value="<?php echo h(trim($currentResident['first_name'] . ' ' . $currentResident['last_name'])); ?>" readonly>
                            <?php else: ?>
                            <select id="resident_id" name="resident_id" required>
                                <option value="">Select resident</option>
                                <?php while ($resident = $residents->fetch_assoc()): ?>
                                    <option value="<?php echo (int)$resident['id']; ?>"<?php echo (int)$values['resident_id'] === (int)$resident['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(trim($resident['last_name'] . ', ' . $resident['first_name']) . ' (' . $resident['resident_number'] . ')'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="certificate_type">Certificate Type *</label>
                            <select id="certificate_type" name="certificate_type" required>
                                <option value="">Select certificate</option>
                                <?php foreach ($typeOptions as $option): ?>
                                    <option<?php echo $values['certificate_type'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option<?php echo $values['status'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="request_date">Request Date *</label>
                            <input type="date" id="request_date" name="request_date" value="<?php echo h($values['request_date']); ?>" required<?php echo $isResident ? ' readonly' : ''; ?>>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="form-group">
                            <label for="approved_date">Approved / Released Date</label>
                            <input type="date" id="approved_date" name="approved_date" value="<?php echo h($values['approved_date']); ?>">
                        </div>
                        <?php endif; ?>

                        <div class="form-group full-width">
                            <label for="purpose">Purpose</label>
                            <textarea id="purpose" name="purpose" rows="3"><?php echo h($values['purpose']); ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="3"><?php echo h($values['remarks']); ?></textarea>
                        </div>

                    </div>

                    <div class="record-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            <span><?php echo $isNew ? 'Save Request' : 'Update Request'; ?></span>
                        </button>
                        <a href="<?php echo $isResident ? 'resident_dashboard.php' : 'certificates.php'; ?>" class="btn-cancel">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            <span>Cancel</span>
                        </a>
                    </div>

                </form>

            <?php else: ?>

                <h3>Certificate Request</h3>

                <table class="record-table">
                    <tbody>
                        <tr><th>Request ID</th><td><?php echo h($certificate['id']); ?></td></tr>
                        <tr><th>Resident</th><td><?php echo h(trim(($certificate['first_name'] ?? '') . ' ' . ($certificate['last_name'] ?? '')) ?: 'Unknown'); ?></td></tr>
                        <tr><th>Certificate Type</th><td><?php echo h($certificate['certificate_type']); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($certificate['status']); ?></td></tr>
                        <tr><th>Request Date</th><td><?php echo h($certificate['request_date']); ?></td></tr>
                        <tr><th>Approved / Released Date</th><td><?php echo h($certificate['approved_date']); ?></td></tr>
                        <tr><th>Purpose</th><td><?php echo h($certificate['purpose']); ?></td></tr>
                        <tr><th>Remarks</th><td><?php echo h($certificate['remarks']); ?></td></tr>
                    </tbody>
                </table>

                <div class="record-actions">
                    <a href="certificate_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn-primary">
                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                    <?php if ($canDelete): ?>
                    <form method="post" action="certificate_form.php?id=<?php echo $id; ?>" class="d-inline"
                          onsubmit="return confirm('Delete this certificate request?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-danger">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            <span>Delete</span>
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="certificates.php" class="btn-cancel">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Back to Certifications</span>
                    </a>
                </div>

            <?php endif; ?>

        </div>

    </main>
</div>
<?php renderFooterScripts(false); ?>
</body>
</html>
