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
    $stmt = $conn->prepare("SELECT a.*, CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel_name, u.position AS assigned_personnel_position FROM appointments a LEFT JOIN users u ON u.id = a.assigned_to_user_id WHERE a.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $appointment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        header('Location: appointments.php?msg=missing');
        exit;
    }
}

if ($appointment && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Location: appointments.php?msg=delete_disabled');
    exit;
}
if ($appointment && !empty($appointment['is_archived']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: appointments.php?view=archived&msg=archived_read_only', true, 303);
    exit;
}

$isEdit = $appointment && isset($_GET['edit']) && empty($appointment['is_archived']);
$isNew = !$appointment;

$residents = $conn->query('SELECT id, resident_number, first_name, middle_name, last_name, suffix FROM residents ORDER BY first_name ASC, last_name ASC');
$residentOptions = $residents->fetch_all(MYSQLI_ASSOC);

$statusOptions = ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rejected', 'No Show'];
$currentAssigneeId = (int)($appointment['assigned_to_user_id'] ?? 0);
$personnelStmt = $conn->prepare("SELECT id, first_name, last_name, position, status FROM users WHERE role IN ('staff', 'health_worker', 'security_force') AND (status = 'Active' OR id = ?) ORDER BY status = 'Active' DESC, first_name, last_name");
$personnelStmt->bind_param('i', $currentAssigneeId);
$personnelStmt->execute();
$personnelOptions = $personnelStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$personnelStmt->close();

$values = [
    'resident_id'      => $appointment['resident_id'] ?? '',
    'resident_name'    => $appointment['resident_name'] ?? '',
    'purpose'          => $appointment['purpose'] ?? '',
    'appointment_date' => $appointment['appointment_date'] ?? date('Y-m-d'),
    'appointment_time' => $appointment ? substr((string)$appointment['appointment_time'], 0, 5) : '09:00',
    'duration_minutes' => $appointment['duration_minutes'] ?? 30,
    'assigned_to_user_id' => $appointment['assigned_to_user_id'] ?? '',
    'status'           => $appointment['status'] ?? 'Pending',
    'notes'            => $appointment['notes'] ?? '',
    'request_for'      => 'self',
    'other_resident_number' => '',
    'other_first_name' => '',
    'other_middle_name' => '',
    'other_last_name' => '',
    'other_suffix' => '',
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
        $values['status'] = 'Pending';
        $values['assigned_to_user_id'] = '';
        $values['request_for'] = trim($_POST['request_for'] ?? 'self');
        $values['other_resident_number'] = trim($_POST['other_resident_number'] ?? '');
        $values['other_first_name'] = trim($_POST['other_first_name'] ?? '');
        $values['other_middle_name'] = trim($_POST['other_middle_name'] ?? '');
        $values['other_last_name'] = trim($_POST['other_last_name'] ?? '');
        $values['other_suffix'] = trim($_POST['other_suffix'] ?? '');

        if ($values['request_for'] === 'self') {
            $values['resident_id'] = (string)$currentResident['id'];
            $values['resident_name'] = trim(implode(' ', array_filter([
                $currentResident['first_name'],
                $currentResident['middle_name'],
                $currentResident['last_name'],
                $currentResident['suffix'],
            ])));
        } elseif ($values['request_for'] === 'other') {
            if ($values['other_resident_number'] === '' || $values['other_first_name'] === '' || $values['other_last_name'] === '') {
                $error = 'Enter the other resident’s resident number, first name, and last name.';
            } else {
                $stmt = $conn->prepare('SELECT id, first_name, middle_name, last_name, suffix FROM residents WHERE resident_number = ? AND first_name = ? AND COALESCE(middle_name, \'\') = ? AND last_name = ? AND COALESCE(suffix, \'\') = ? LIMIT 1');
                $stmt->bind_param('sssss', $values['other_resident_number'], $values['other_first_name'], $values['other_middle_name'], $values['other_last_name'], $values['other_suffix']);
                $stmt->execute();
                $otherResident = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($otherResident) {
                    $values['resident_id'] = (string)$otherResident['id'];
                    $values['resident_name'] = trim(implode(' ', array_filter([
                        $otherResident['first_name'],
                        $otherResident['middle_name'],
                        $otherResident['last_name'],
                        $otherResident['suffix'],
                    ])));
                } else {
                    $error = 'Those details do not match a resident record. Check them or contact the barangay office.';
                }
            }
        } else {
            $error = 'Choose whether this appointment is for yourself or another resident.';
        }
    }
    $residentId = (int)$values['resident_id'];
    $assignedToId = 0;
    if (!$isResident && $values['assigned_to_user_id'] !== '') {
        $validatedAssigneeId = filter_var($values['assigned_to_user_id'], FILTER_VALIDATE_INT);
        if ($validatedAssigneeId === false || $validatedAssigneeId <= 0) {
            $error = 'Choose a valid personnel member.';
        } else {
            $assignedToId = $validatedAssigneeId;
        }
    }
    $durationMinutes = filter_var($values['duration_minutes'], FILTER_VALIDATE_INT);
    if ($durationMinutes === false || $durationMinutes < 15 || $durationMinutes > 240) {
        $durationMinutes = 30;
        $error = 'Choose an appointment duration between 15 minutes and 4 hours.';
    }

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
    $parsedAppointmentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['appointment_date']);

    if ($error !== '') {
        // Keep the form open with the validation message.
    } elseif ($values['resident_name'] === '') {
        $error = 'Please choose a resident or type the name.';
    } elseif ($values['purpose'] === '') {
        $error = 'Purpose of the appointment is required.';
    } elseif (!$parsedAppointmentDate || $parsedAppointmentDate->format('Y-m-d') !== $values['appointment_date']) {
        $error = 'Enter a valid appointment date.';
    } elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $values['appointment_time'])) {
        $error = 'Enter a valid appointment time.';
    } elseif (!in_array($values['status'], $statusOptions, true)) {
        $error = 'Invalid appointment status.';
    } else {
        $assignedToIdOrNull = $assignedToId > 0 ? $assignedToId : null;
        $scheduleTransactionStarted = false;
        if ($assignedToId > 0) {
            if (in_array($values['status'], ['Pending', 'Approved'], true)) {
                $conn->begin_transaction();
                $scheduleTransactionStarted = true;
            }
            $assignmentSql = "SELECT id FROM users WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force') AND (status = 'Active' OR id = ?)";
            if ($scheduleTransactionStarted) {
                $assignmentSql .= ' FOR UPDATE';
            }
            $assignmentStmt = $conn->prepare($assignmentSql);
            $assignmentStmt->bind_param('ii', $assignedToId, $currentAssigneeId);
            $assignmentStmt->execute();
            $validAssignee = (bool)$assignmentStmt->get_result()->fetch_assoc();
            $assignmentStmt->close();
            if (!$validAssignee) {
                $error = 'Choose an active barangay personnel member.';
            }
        }

        if ($error === '' && $assignedToId > 0 && in_array($values['status'], ['Pending', 'Approved'], true)) {
            $conflictStmt = $conn->prepare("SELECT id, appointment_time, duration_minutes FROM appointments WHERE assigned_to_user_id = ? AND appointment_date = ? AND is_archived = 0 AND status IN ('Pending', 'Approved') AND id <> ?");
            $conflictId = $appointment ? $id : 0;
            $conflictStmt->bind_param('isi', $assignedToId, $values['appointment_date'], $conflictId);
            $conflictStmt->execute();
            $conflicts = $conflictStmt->get_result();
            $newStart = strtotime($values['appointment_date'] . ' ' . $values['appointment_time']);
            $newEnd = $newStart + $durationMinutes * 60;
            while ($busy = $conflicts->fetch_assoc()) {
                $busyStart = strtotime($values['appointment_date'] . ' ' . $busy['appointment_time']);
                $busyEnd = $busyStart + max(15, (int)$busy['duration_minutes']) * 60;
                if ($newStart < $busyEnd && $newEnd > $busyStart) {
                    $error = 'Scheduling conflict: this personnel member already has an appointment during that time. Choose another time or personnel member.';
                    break;
                }
            }
            $conflictStmt->close();
        }

        if ($error !== '') {
            if ($scheduleTransactionStarted) {
                $conn->rollback();
            }
        } elseif ($appointment) {
            $stmt = $conn->prepare('UPDATE appointments SET resident_id = ?, resident_name = ?, purpose = ?, appointment_date = ?, appointment_time = ?, duration_minutes = ?, assigned_to_user_id = ?, status = ?, notes = ? WHERE id = ?');
            $stmt->bind_param(
                'issssiissi',
                $residentIdOrNull,
                $values['resident_name'],
                $values['purpose'],
                $values['appointment_date'],
                $values['appointment_time'],
                $durationMinutes,
                $assignedToIdOrNull,
                $values['status'],
                $values['notes'],
                $id
            );
            $stmt->execute();
            $stmt->close();
            $previousStatus = (string)($appointment['status'] ?? '');
            $oldSchedule = (string)$appointment['appointment_date'] . ' ' . substr((string)$appointment['appointment_time'], 0, 5);
            $newSchedule = $values['appointment_date'] . ' ' . $values['appointment_time'];
            if ($previousStatus !== $values['status']) {
                logActivity($conn, 'updated', 'appointments', 'Appointment status changed from ' . $previousStatus . ' to ' . $values['status'] . '.', $id);
            }
            if ($oldSchedule !== $newSchedule || (int)($appointment['duration_minutes'] ?? 30) !== $durationMinutes) {
                logActivity($conn, 'rescheduled', 'appointments', 'Appointment rescheduled to ' . $newSchedule . '.', $id);
            }
            if ((int)($appointment['assigned_to_user_id'] ?? 0) !== $assignedToId) {
                $assignedName = 'No personnel';
                foreach ($personnelOptions as $personnel) {
                    if ((int)$personnel['id'] === $assignedToId) {
                        $assignedName = trim($personnel['first_name'] . ' ' . $personnel['last_name']);
                        break;
                    }
                }
                logActivity($conn, 'assigned', 'appointments', 'Appointment assigned to ' . $assignedName . '.', $id);
            }
            if ($previousStatus === $values['status'] && $oldSchedule === $newSchedule && (int)($appointment['assigned_to_user_id'] ?? 0) === $assignedToId && (int)($appointment['duration_minutes'] ?? 30) === $durationMinutes) {
                logActivity($conn, 'updated', 'appointments', 'Updated appointment record.', $id);
            }
            if ($scheduleTransactionStarted) {
                $conn->commit();
            }
            header('Location: appointments.php?msg=updated');
            exit;
        } else {
            $requestedByUserId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $conn->prepare('INSERT INTO appointments (resident_id, requested_by_user_id, assigned_to_user_id, resident_name, purpose, appointment_date, appointment_time, duration_minutes, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'iiissssiss',
                $residentIdOrNull,
                $requestedByUserId,
                $assignedToIdOrNull,
                $values['resident_name'],
                $values['purpose'],
                $values['appointment_date'],
                $values['appointment_time'],
                $durationMinutes,
                $values['status'],
                $values['notes']
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'created', 'appointments', 'Created appointment request with status ' . $values['status'] . '.', $conn->insert_id);
            if ($assignedToId > 0) {
                foreach ($personnelOptions as $personnel) {
                    if ((int)$personnel['id'] === $assignedToId) {
                        logActivity($conn, 'assigned', 'appointments', 'Appointment assigned to ' . trim($personnel['first_name'] . ' ' . $personnel['last_name']) . '.', $conn->insert_id);
                        break;
                    }
                }
            }
            if ($scheduleTransactionStarted) {
                $conn->commit();
            }
            header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=appointment_created' : 'appointments.php?msg=created'));
            exit;
        }
    }
}

$showForm = $isNew || $isEdit;
$title = $isNew ? 'New Appointment' : ($isEdit ? 'Edit Appointment' : 'Appointment Details');
$purposeOptions = ['Barangay consultation', 'Complaint hearing', 'Business permit consultation', 'Community program', 'Other'];
$residentSearchChoices = [];
foreach ($residentOptions as $resident) {
    $fullName = trim(implode(' ', array_filter([
        $resident['first_name'],
        $resident['middle_name'],
        $resident['last_name'],
        $resident['suffix'],
    ])));
    $residentSearchChoices[] = [
        'resident_number' => (string)$resident['resident_number'],
        'first_name' => (string)$resident['first_name'],
        'middle_name' => (string)($resident['middle_name'] ?? ''),
        'last_name' => (string)$resident['last_name'],
        'suffix' => (string)($resident['suffix'] ?? ''),
        'full_name' => $fullName,
        'search' => $fullName . ' ' . $resident['resident_number'],
    ];
}
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
                            <fieldset class="form-control">
                                <legend class="form-label mb-2">Who is this appointment for?</legend>
                                <label class="me-3"><input type="radio" name="request_for" value="self"<?php echo $values['request_for'] === 'self' ? ' checked' : ''; ?>> For myself</label>
                                <label><input type="radio" name="request_for" value="other"<?php echo $values['request_for'] === 'other' ? ' checked' : ''; ?>> For another resident</label>
                            </fieldset>
                            <div id="selfRecipient" class="mt-2">
                                <input type="hidden" name="resident_id" value="<?php echo (int)$currentResident['id']; ?>">
                                <input type="text" class="form-control" value="<?php echo h(trim(implode(' ', array_filter([$currentResident['first_name'], $currentResident['middle_name'], $currentResident['last_name'], $currentResident['suffix']])))); ?>" readonly>
                            </div>
                            <div id="otherRecipientFields" class="row g-2 mt-1"<?php echo $values['request_for'] === 'other' ? '' : ' hidden'; ?>>
                                <div class="col-12">
                                    <label class="form-label" for="other_first_name">First Name *</label>
                                    <div class="resident-search">
                                        <input class="form-control" id="other_first_name" name="other_first_name" value="<?php echo h($values['other_first_name']); ?>" placeholder="Enter first name" list="appointment-resident-options" autocomplete="off">
                                        <datalist id="appointment-resident-options">
                                            <?php foreach ($residentSearchChoices as $resident): ?>
                                                <option value="<?php echo h($resident['full_name'] . ' (' . $resident['resident_number'] . ')'); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                </div>
                                <div class="col-12"><label class="form-label" for="other_resident_number">Resident Number *</label><input class="form-control" id="other_resident_number" name="other_resident_number" value="<?php echo h($values['other_resident_number']); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label" for="other_middle_name">Middle Name</label><input class="form-control" id="other_middle_name" name="other_middle_name" value="<?php echo h($values['other_middle_name']); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label" for="other_last_name">Last Name *</label><input class="form-control" id="other_last_name" name="other_last_name" value="<?php echo h($values['other_last_name']); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label" for="other_suffix">Suffix</label><input class="form-control" id="other_suffix" name="other_suffix" value="<?php echo h($values['other_suffix']); ?>" readonly></div>
                            </div>
                            <?php else: ?>
                            <select class="form-select" id="resident_id" name="resident_id">
                                <option value="">Not a registered resident</option>
                                <?php foreach ($residentOptions as $resident): ?>
                                    <option value="<?php echo (int)$resident['id']; ?>"<?php echo (int)$values['resident_id'] === (int)$resident['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(trim($resident['last_name'] . ', ' . $resident['first_name'] . ' ' . $resident['middle_name'] . ' ' . $resident['suffix']) . ' (' . $resident['resident_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="resident_name">Name *</label>
                            <input type="text" class="form-control" id="resident_name" name="resident_name" value="<?php echo h($values['resident_name']); ?>"<?php echo $isResident ? ' readonly' : ''; ?>>
                            <small class="text-muted">Filled in automatically when a resident is selected.</small>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="purpose">Purpose *</label>
                            <select class="form-select" id="purpose" name="purpose" required>
                                <option value="">Select purpose</option>
                                <?php foreach ($purposeOptions as $option): ?>
                                    <option<?php echo $values['purpose'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option<?php echo $values['status'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="assigned_to_user_id">Assigned Personnel</label>
                            <select class="form-select" id="assigned_to_user_id" name="assigned_to_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($personnelOptions as $personnel): ?>
                                    <option value="<?php echo (int)$personnel['id']; ?>"<?php echo (int)$values['assigned_to_user_id'] === (int)$personnel['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(trim($personnel['first_name'] . ' ' . $personnel['last_name']) . ($personnel['position'] ? ' · ' . $personnel['position'] : '') . ($personnel['status'] !== 'Active' ? ' · Archived personnel (historical assignment)' : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="appointment_date">Date *</label>
                            <input type="date" class="form-control" id="appointment_date" name="appointment_date" value="<?php echo h($values['appointment_date']); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="appointment_time">Time *</label>
                            <input type="time" class="form-control" id="appointment_time" name="appointment_time" value="<?php echo h($values['appointment_time']); ?>" required>
                        </div>

                        <?php if (!$isResident): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="duration_minutes">Appointment Duration</label>
                            <select class="form-select" id="duration_minutes" name="duration_minutes">
                                <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $duration): ?>
                                    <option value="<?php echo $duration; ?>"<?php echo (int)$values['duration_minutes'] === $duration ? ' selected' : ''; ?>><?php echo $duration < 60 ? $duration . ' minutes' : ($duration / 60) . ' hour' . ($duration === 60 ? '' : 's'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Assigned personnel conflicts are checked using this duration.</small>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="duration_minutes" value="<?php echo (int)$values['duration_minutes']; ?>">
                        <?php endif; ?>

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
                        <tr><th>Assigned Personnel</th><td><?php echo h($appointment['assigned_personnel_name']); ?><?php if (!empty($appointment['assigned_personnel_position'])): ?> · <?php echo h($appointment['assigned_personnel_position']); ?><?php endif; ?></td></tr>
                        <tr><th>Duration</th><td><?php echo (int)($appointment['duration_minutes'] ?? 30); ?> minutes</td></tr>
                        <tr><th>Notes</th><td><?php echo nl2br(h($appointment['notes'])); ?></td></tr>
                        <?php if (!empty($appointment['is_archived'])): ?>
                            <tr><th>Archived</th><td><?php echo h(formatDatabaseDateTime($appointment['archived_at'])); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="mt-3">
                    <?php if (empty($appointment['is_archived'])): ?>
                    <a href="appointment_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn btn-primary">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Edit
                    </a>
                    <?php endif; ?>
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
<?php if ($isResident && $showForm): ?>
<script>
    const requestForInputs = document.querySelectorAll('input[name="request_for"]');
    const selfRecipient = document.getElementById('selfRecipient');
    const otherRecipientFields = document.getElementById('otherRecipientFields');
    const otherRecipientInputs = otherRecipientFields.querySelectorAll('input');
    const otherFirstName = document.getElementById('other_first_name');
    const residentChoices = <?php echo json_encode($residentSearchChoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let selectedOtherResident = null;

    function fillOtherResident(resident) {
        document.getElementById('other_resident_number').value = resident.resident_number;
        otherFirstName.value = resident.first_name;
        document.getElementById('other_middle_name').value = resident.middle_name || '';
        document.getElementById('other_last_name').value = resident.last_name;
        document.getElementById('other_suffix').value = resident.suffix || '';
        otherFirstName.setCustomValidity('');
    }

    function selectOtherResident() {
        const selectedName = otherFirstName.value.trim().toLocaleLowerCase();
        const resident = residentChoices.find((option) =>
            `${option.full_name} (${option.resident_number})`.toLocaleLowerCase() === selectedName
        );
        if (resident) {
            selectedOtherResident = resident;
            fillOtherResident(resident);
        } else if (selectedOtherResident && otherFirstName.value === selectedOtherResident.first_name) {
            return;
        } else {
            selectedOtherResident = null;
            otherResidentInputs.forEach((input) => {
                if (input.id !== 'other_first_name') input.value = '';
            });
            otherFirstName.setCustomValidity('Choose a resident from the suggestions.');
        }
    }

    otherFirstName.addEventListener('input', selectOtherResident);
    otherFirstName.addEventListener('change', selectOtherResident);

    function updateRecipientFields() {
        const forOther = document.querySelector('input[name="request_for"]:checked')?.value === 'other';
        selfRecipient.hidden = forOther;
        otherRecipientFields.hidden = !forOther;
        otherRecipientInputs.forEach((input) => {
            input.disabled = !forOther;
            input.required = forOther && input.id !== 'other_middle_name' && input.id !== 'other_suffix';
        });
        document.querySelector('input[name="resident_id"]').disabled = forOther;
        if (!forOther) {
            otherFirstName.setCustomValidity('');
        }
    }
    requestForInputs.forEach((input) => input.addEventListener('change', updateRecipientFields));
    updateRecipientFields();
</script>
<?php endif; ?>
</body>
</html>
