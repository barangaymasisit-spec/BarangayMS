<?php
require_once __DIR__ . '/layout.php';

$isResident = ($_SESSION['role'] ?? '') === 'resident';
$currentResident = $isResident ? currentResident($conn) : null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$appointment = null;
$error = '';

if ($isResident && ($id > 0 || !$currentResident)) {
    header('Location: resident_dashboard.php');
    exit;
}

if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM appointments WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        header('Location: appointments.php?msg=missing');
        exit;
    }
}

// Delete
if ($appointment && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $conn->prepare('DELETE FROM appointments WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, 'deleted', 'appointments', 'Deleted appointment record.', $id);
    header('Location: appointments.php?msg=deleted');
    exit;
}

$isEdit = $appointment && isset($_GET['edit']);
$isNew = !$appointment;

$residents = $isResident
    ? $conn->query('SELECT id, resident_number, first_name, last_name FROM residents WHERE id = ' . (int)$currentResident['id'])
    : $conn->query('SELECT id, resident_number, first_name, last_name FROM residents ORDER BY last_name ASC, first_name ASC');

$statusOptions = ['Pending', 'Approved', 'Cancelled'];

$values = [
    'resident_id'      => $appointment['resident_id'] ?? '',
    'resident_name'    => $appointment['resident_name'] ?? '',
    'purpose'          => $appointment['purpose'] ?? '',
    'appointment_date' => $appointment['appointment_date'] ?? date('Y-m-d'),
    'appointment_time' => $appointment ? substr((string)$appointment['appointment_time'], 0, 5) : '09:00',
    'status'           => $appointment['status'] ?? 'Pending',
    'notes'            => $appointment['notes'] ?? '',
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
        $values['resident_id'] = (string)$currentResident['id'];
        $values['resident_name'] = trim($currentResident['first_name'] . ' ' . $currentResident['last_name']);
        $values['status'] = 'Pending';
    }
    $residentId = (int)$values['resident_id'];

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

    if ($values['resident_name'] === '') {
        $error = 'Please choose a resident or type the name.';
    } elseif ($values['purpose'] === '') {
        $error = 'Purpose of the appointment is required.';
    } elseif ($values['appointment_date'] === '') {
        $error = 'Appointment date is required.';
    } elseif ($values['appointment_time'] === '') {
        $error = 'Appointment time is required.';
    } elseif (!in_array($values['status'], $statusOptions, true)) {
        $error = 'Invalid appointment status.';
    } else {
        if ($appointment) {
            $stmt = $conn->prepare('UPDATE appointments SET resident_id = ?, resident_name = ?, purpose = ?, appointment_date = ?, appointment_time = ?, status = ?, notes = ? WHERE id = ?');
            $stmt->bind_param(
                'issssssi',
                $residentIdOrNull,
                $values['resident_name'],
                $values['purpose'],
                $values['appointment_date'],
                $values['appointment_time'],
                $values['status'],
                $values['notes'],
                $id
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'updated', 'appointments', 'Updated appointment record.', $id);
            header('Location: appointments.php?msg=updated');
            exit;
        }

        $stmt = $conn->prepare('INSERT INTO appointments (resident_id, resident_name, purpose, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param(
            'issssss',
            $residentIdOrNull,
            $values['resident_name'],
            $values['purpose'],
            $values['appointment_date'],
            $values['appointment_time'],
            $values['status'],
            $values['notes']
        );
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'created', 'appointments', 'Created appointment record.', $conn->insert_id);
        header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=appointment_created' : 'appointments.php?msg=created'));
        exit;
    }
}

$showForm = $isNew || $isEdit;
$title = $isNew ? 'New Appointment' : ($isEdit ? 'Edit Appointment' : 'Appointment Details');
$purposeOptions = ['Barangay Clearance', 'Certificate Request', 'Complaint Hearing', 'Business Permit', 'Consultation', 'Others'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($title); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset('appointments.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar($isResident ? 'resident_appointment' : 'appointments', 'compact'); ?>
    <main class="main-content">

        <?php renderTopbar($title, $isNew ? 'Schedule a new appointment.' : 'View and manage an appointment.', 'compact', ['clock' => true]); ?>

        <div class="content-card">

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($showForm): ?>

                <div class="card-header-custom">
                    <h3><?php echo $isNew ? 'New Appointment' : 'Edit Appointment'; ?></h3>
                </div>

                <form method="post" action="appointment_form.php<?php echo $appointment ? '?id=' . $id . '&edit=1' : ''; ?>">
                    <?php echo csrfField(); ?>

                    <div class="row g-3">

                        <div class="col-md-6">
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

                        <div class="col-md-6">
                            <label class="form-label" for="resident_name">Name *</label>
                            <input type="text" class="form-control" id="resident_name" name="resident_name" value="<?php echo h($values['resident_name']); ?>"<?php echo $isResident ? ' readonly' : ''; ?>>
                            <small class="text-muted">Filled in automatically when a resident is selected.</small>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="purpose">Purpose *</label>
                            <select class="form-select" id="purpose" name="purpose" required>
                                <option value="">Select purpose</option>
                                <?php foreach ($purposeOptions as $option): ?>
                                    <option<?php echo $values['purpose'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option<?php echo $values['status'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="appointment_date">Date *</label>
                            <input type="date" class="form-control" id="appointment_date" name="appointment_date" value="<?php echo h($values['appointment_date']); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="appointment_time">Time *</label>
                            <input type="time" class="form-control" id="appointment_time" name="appointment_time" value="<?php echo h($values['appointment_time']); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo h($values['notes']); ?></textarea>
                        </div>

                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?php echo $isNew ? 'Save Appointment' : 'Update Appointment'; ?>
                        </button>
                        <a href="<?php echo $isResident ? 'resident_dashboard.php' : 'appointments.php'; ?>" class="btn btn-secondary ms-2">
                            <i class="fa-solid fa-xmark"></i>
                            Cancel
                        </a>
                    </div>

                </form>

            <?php else: ?>

                <div class="card-header-custom">
                    <h3>Appointment Record</h3>
                </div>

                <table class="table table-striped align-middle">
                    <tbody>
                        <tr><th style="width:220px">Resident</th><td><?php echo h($appointment['resident_name']); ?></td></tr>
                        <tr><th>Purpose</th><td><?php echo h($appointment['purpose']); ?></td></tr>
                        <tr><th>Date</th><td><?php echo h($appointment['appointment_date']); ?></td></tr>
                        <tr><th>Time</th><td><?php echo h(date('h:i A', strtotime((string)$appointment['appointment_time']))); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($appointment['status']); ?></td></tr>
                        <tr><th>Notes</th><td><?php echo nl2br(h($appointment['notes'])); ?></td></tr>
                    </tbody>
                </table>

                <div class="mt-3">
                    <a href="appointment_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn btn-primary">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Edit
                    </a>
                                        <form method="post" action="appointment_form.php?id=<?php echo $id; ?>" class="d-inline ms-2"
                                                    onsubmit="return confirm('Delete this appointment?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                        <i class="fa-solid fa-trash"></i>
                                                <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                    <a href="appointments.php" class="btn btn-secondary ms-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to Appointments
                    </a>
                </div>

            <?php endif; ?>

        </div>

    </main>
</div>
<?php renderFooterScripts(); ?>
</body>
</html>
