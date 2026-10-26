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
    $stmt = $conn->prepare("SELECT c.*, CASE WHEN u.id IS NULL THEN 'Not assigned' ELSE CONCAT(u.first_name, ' ', u.last_name) END AS assigned_personnel_name, u.position AS assigned_personnel_position FROM complaints c LEFT JOIN users u ON u.id = c.assigned_to_user_id WHERE c.id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $complaint = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$complaint) {
        header('Location: complaints.php?msg=missing');
        exit;
    }
}

if ($complaint && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Location: complaints.php?msg=delete_disabled');
    exit;
}
if ($complaint && !empty($complaint['is_archived']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: complaints.php?view=archived&msg=archived_read_only', true, 303);
    exit;
}

$isEdit = $complaint && isset($_GET['edit']) && empty($complaint['is_archived']);
$isNew = !$complaint;

$residents = $conn->query('SELECT id, resident_number, first_name, middle_name, last_name, suffix FROM residents ORDER BY last_name ASC, first_name ASC');
$residentOptions = $residents->fetch_all(MYSQLI_ASSOC);
$assignedPersonnelStmt = $conn->prepare("SELECT id, first_name, last_name, position, status FROM users WHERE role IN ('staff', 'health_worker', 'security_force') AND (status = 'Active' OR id = ?) ORDER BY status = 'Active' DESC, first_name, last_name");
$currentAssigneeId = (int)($complaint['assigned_to_user_id'] ?? 0);
$assignedPersonnelStmt->bind_param('i', $currentAssigneeId);
$assignedPersonnelStmt->execute();
$assignedPersonnel = $assignedPersonnelStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$assignedPersonnelStmt->close();

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

$statusOptions = ['Pending', 'Ongoing', 'Resolved'];

$values = [
    'tracking_number' => $complaint['tracking_number'] ?? nextTrackingNumber($conn),
    'resident_id'     => $complaint['resident_id'] ?? '',
    'resident_name'   => $complaint['resident_name'] ?? '',
    'complainee_resident_id' => $complaint['complainee_resident_id'] ?? '',
    'complainee_name' => $complaint['complainee_name'] ?? '',
    'category'        => $complaint['category'] ?? '',
    'status'          => $complaint['status'] ?? 'Pending',
    'date_filed'      => $complaint['date_filed'] ?? date('Y-m-d'),
    'description'     => $complaint['description'] ?? '',
    'response'        => $complaint['response'] ?? '',
    'resolution_remarks' => $complaint['resolution_remarks'] ?? '',
    'assigned_to_user_id' => $complaint['assigned_to_user_id'] ?? '',
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
        $values['resolution_remarks'] = '';
        $values['assigned_to_user_id'] = '';
    }
    $residentId = (int)$values['resident_id'];
    $complaineeId = (int)$values['complainee_resident_id'];
    $assignedToId = 0;
    if (!$isResident && $values['assigned_to_user_id'] !== '') {
        $validatedAssigneeId = filter_var($values['assigned_to_user_id'], FILTER_VALIDATE_INT);
        if ($validatedAssigneeId === false || $validatedAssigneeId <= 0) {
            $error = 'Choose a valid barangay personnel member for this complaint.';
        } else {
            $assignedToId = $validatedAssigneeId;
        }
    }

    // Resolve the selected complainant from the resident record to keep the link authoritative.
    if ($residentId > 0) {
        $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix FROM residents WHERE id = ?');
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $linked = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($linked) {
            $values['resident_name'] = trim(implode(' ', array_filter([
                $linked['first_name'],
                $linked['middle_name'],
                $linked['last_name'],
                $linked['suffix'],
            ])));
        } else {
            $error = 'That complainant resident record is no longer available. Search again or enter an unregistered name.';
            $values['resident_id'] = '';
            $residentId = 0;
        }
    }
    $residentIdOrNull = $residentId > 0 ? $residentId : null;
    if ($complaineeId > 0) {
        $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, suffix FROM residents WHERE id = ?');
        $stmt->bind_param('i', $complaineeId);
        $stmt->execute();
        $linkedComplainee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($linkedComplainee) {
            $values['complainee_name'] = trim(implode(' ', array_filter([
                $linkedComplainee['first_name'],
                $linkedComplainee['middle_name'],
                $linkedComplainee['last_name'],
                $linkedComplainee['suffix'],
            ])));
        } else {
            $error = 'Choose a valid registered resident as the respondent.';
        }
    } else {
        $values['complainee_name'] = '';
        $error = 'Please select a registered resident as the respondent.';
    }
    $complaineeIdOrNull = $complaineeId > 0 ? $complaineeId : null;
    $assignedToIdOrNull = $assignedToId > 0 ? $assignedToId : null;
    if (!$isResident && $assignedToId > 0) {
        $assignmentStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('staff', 'health_worker', 'security_force') AND (status = 'Active' OR id = ?)");
        $assignmentStmt->bind_param('ii', $assignedToId, $currentAssigneeId);
        $assignmentStmt->execute();
        $validAssignee = (bool)$assignmentStmt->get_result()->fetch_assoc();
        $assignmentStmt->close();
        if (!$validAssignee) {
            $error = 'Choose an active barangay personnel member for this complaint.';
        }
    }

    if ($error !== '') {
        // Keep the form open with the validation message.
    } elseif ($values['tracking_number'] === '') {
        $error = 'Tracking number is required.';
    } elseif ($values['resident_name'] === '') {
        $error = 'Please choose a resident or type the complainant name.';
    } elseif ($values['category'] === '') {
        $error = 'Please choose a complaint category.';
    } elseif ($values['description'] === '') {
        $error = 'Please describe the complaint.';
    } elseif (!in_array($values['status'], $statusOptions, true)) {
        $error = 'Invalid complaint status.';
    } elseif ($values['status'] === 'Resolved' && ($values['response'] === '' || $values['resolution_remarks'] === '')) {
        $error = 'Add the action taken and resolution remarks before marking this complaint as resolved.';
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
            $stmt = $conn->prepare('UPDATE complaints SET tracking_number = ?, resident_id = ?, resident_name = ?, complainee_resident_id = ?, complainee_name = ?, assigned_to_user_id = ?, category = ?, status = ?, date_filed = ?, description = ?, response = ?, resolution_remarks = ? WHERE id = ?');
            $stmt->bind_param(
                'sisssissssssi',
                $values['tracking_number'],
                $residentIdOrNull,
                $values['resident_name'],
                $complaineeIdOrNull,
                $values['complainee_name'],
                $assignedToIdOrNull,
                $values['category'],
                $values['status'],
                $values['date_filed'],
                $values['description'],
                $values['response'],
                $values['resolution_remarks'],
                $id
            );
            $stmt->execute();
            $stmt->close();
            $previousStatus = (string)($complaint['status'] ?? '');
            if ($previousStatus !== $values['status']) {
                logActivity($conn, 'updated', 'complaints', 'Complaint status changed from ' . $previousStatus . ' to ' . $values['status'] . '.', $id);
            }
            if ((int)($complaint['assigned_to_user_id'] ?? 0) !== $assignedToId) {
                $assigneeName = 'No personnel';
                if ($assignedToId > 0) {
                    foreach ($assignedPersonnel as $personnel) {
                        if ((int)$personnel['id'] === $assignedToId) {
                            $assigneeName = trim($personnel['first_name'] . ' ' . $personnel['last_name']);
                            break;
                        }
                    }
                }
                logActivity($conn, 'assigned', 'complaints', 'Complaint assignment changed to ' . $assigneeName . '.', $id);
            }
            if ($values['status'] === 'Resolved' && ($complaint['response'] !== $values['response'] || ($complaint['resolution_remarks'] ?? '') !== $values['resolution_remarks'])) {
                logActivity($conn, 'resolved', 'complaints', 'Resolution details recorded.', $id);
            }
            if ($previousStatus === $values['status'] && (int)($complaint['assigned_to_user_id'] ?? 0) === $assignedToId && $complaint['response'] === $values['response'] && ($complaint['resolution_remarks'] ?? '') === $values['resolution_remarks']) {
                logActivity($conn, 'updated', 'complaints', 'Updated complaint record.', $id);
            }
            header('Location: complaints.php?msg=updated');
            exit;
        } else {
            $stmt = $conn->prepare('INSERT INTO complaints (tracking_number, resident_id, resident_name, complainee_resident_id, complainee_name, assigned_to_user_id, category, status, date_filed, description, response, resolution_remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'sisssissssss',
                $values['tracking_number'],
                $residentIdOrNull,
                $values['resident_name'],
                $complaineeIdOrNull,
                $values['complainee_name'],
                $assignedToIdOrNull,
                $values['category'],
                $values['status'],
                $values['date_filed'],
                $values['description'],
                $values['response'],
                $values['resolution_remarks']
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'created', 'complaints', 'Created complaint with status ' . $values['status'] . '.', $conn->insert_id);
            if ($assignedToId > 0) {
                $assignedName = '';
                foreach ($assignedPersonnel as $personnel) {
                    if ((int)$personnel['id'] === $assignedToId) {
                        $assignedName = trim($personnel['first_name'] . ' ' . $personnel['last_name']);
                        break;
                    }
                }
                logActivity($conn, 'assigned', 'complaints', 'Complaint assigned to ' . ($assignedName ?: 'barangay personnel') . '.', $conn->insert_id);
            }
            if ($values['status'] === 'Resolved') {
                logActivity($conn, 'resolved', 'complaints', 'Resolution details recorded.', $conn->insert_id);
            }
            header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=complaint_created' : 'complaints.php?msg=created'));
            exit;
        }
    }
}

$showForm = $isNew || $isEdit;
$title = $isNew ? 'New Complaint' : ($isEdit ? 'Edit Complaint' : 'Complaint Details');
$categoryOptions = ['Noise Complaint', 'Boundary Dispute', 'Property Damage', 'Theft', 'Physical Injury', 'Domestic Dispute', 'Public Disturbance', 'Others'];
$complaineeSearch = trim((string)($_POST['complainee_search'] ?? ''));
$complainantChoices = [];
$complaineeChoices = [];
foreach ($residentOptions as $resident) {
    $fullName = trim(implode(' ', array_filter([
        $resident['first_name'],
        $resident['middle_name'],
        $resident['last_name'],
        $resident['suffix'],
    ])));
    $residentChoice = [
        'id' => (int)$resident['id'],
        'label' => $fullName . ' (' . $resident['resident_number'] . ')',
        'search' => $fullName . ' ' . $resident['resident_number'],
    ];
    $complainantChoices[] = $residentChoice;
    $complaineeChoices[] = $residentChoice;
    if ($complaineeSearch === '' && (int)$values['complainee_resident_id'] === (int)$resident['id']) {
        $complaineeSearch = $fullName . ' (' . $resident['resident_number'] . ')';
    }
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
    <link rel="stylesheet" href="<?php echo asset('complaints.css'); ?>">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar($isResident ? 'resident_complaint' : 'complaints', 'compact'); ?>
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

                        <?php if ($isResident): ?>
                        <input type="hidden" name="resident_id" value="<?php echo (int)$currentResident['id']; ?>">
                        <div class="col-md-8">
                            <label class="form-label" for="resident_name">Complainant</label>
                            <input type="text" class="form-control" id="resident_name" name="resident_name" value="<?php echo h($values['resident_name']); ?>" readonly>
                        </div>
                        <?php else: ?>
                        <div class="col-md-8">
                            <label class="form-label" for="resident_name">Complainant *</label>
                            <div class="resident-search">
                                <div class="resident-autocomplete-input">
                                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                    <input type="search" class="form-control" id="resident_name" name="resident_name" value="<?php echo h($values['resident_name']); ?>" placeholder="Search resident name or number" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="complainant-suggestions" required>
                                    <input type="hidden" id="resident_id" name="resident_id" value="<?php echo h($values['resident_id']); ?>">
                                </div>
                                <div class="resident-search-results" id="complainant-suggestions" role="listbox" aria-label="Matching complainants" hidden></div>
                                <small class="resident-search-hint" id="complainant-search-hint">Select a registered resident, or enter a name if they are not registered.</small>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="complainee_search">Respondent</label>
                            <div class="resident-search">
                                <input type="hidden" id="complainee_resident_id" name="complainee_resident_id" value="<?php echo (int)$values['complainee_resident_id']; ?>">
                                <input type="text" class="form-control" id="complainee_search" name="complainee_search" value="<?php echo h($complaineeSearch); ?>" placeholder="Search resident name or number" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="complainee-suggestions" required>
                                <div class="resident-search-results" id="complainee-suggestions" role="listbox" hidden></div>
                            </div>
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
                        <div class="col-md-4">
                            <label class="form-label" for="assigned_to_user_id">Assigned Personnel</label>
                            <select class="form-select" id="assigned_to_user_id" name="assigned_to_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($assignedPersonnel as $personnel): ?>
                                    <option value="<?php echo (int)$personnel['id']; ?>"<?php echo (int)$values['assigned_to_user_id'] === (int)$personnel['id'] ? ' selected' : ''; ?>>
                                        <?php echo h(trim($personnel['first_name'] . ' ' . $personnel['last_name']) . ($personnel['position'] ? ' · ' . $personnel['position'] : '') . ($personnel['status'] !== 'Active' ? ' · Archived personnel (historical assignment)' : '')); ?>
                                    </option>
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
                            <label class="form-label" for="response">Resolution Action Taken <span class="resolution-required-indicator" hidden>*</span></label>
                            <textarea class="form-control" id="response" name="response" rows="3" placeholder="Record the steps taken to resolve the complaint."><?php echo h($values['response']); ?></textarea>
                            <small class="text-muted">Required before setting the status to Resolved.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="resolution_remarks">Resolution Remarks <span class="resolution-required-indicator" hidden>*</span></label>
                            <textarea class="form-control" id="resolution_remarks" name="resolution_remarks" rows="3" placeholder="Add the outcome or any follow-up notes."><?php echo h($values['resolution_remarks']); ?></textarea>
                            <small class="text-muted">Required before setting the status to Resolved.</small>
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
                        <tr><th>Respondent</th><td><?php echo h($complaint['complainee_name'] ?? ''); ?></td></tr>
                        <tr><th>Category</th><td><?php echo h($complaint['category']); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($complaint['status']); ?></td></tr>
                        <tr><th>Assigned Personnel</th><td><?php echo h($complaint['assigned_personnel_name']); ?><?php if (!empty($complaint['assigned_personnel_position'])): ?> · <?php echo h($complaint['assigned_personnel_position']); ?><?php endif; ?></td></tr>
                        <tr><th>Date Filed</th><td><?php echo h($complaint['date_filed']); ?></td></tr>
                        <tr><th>Description</th><td><?php echo nl2br(h($complaint['description'])); ?></td></tr>
                        <tr><th>Resolution Action Taken</th><td><?php echo nl2br(h($complaint['response'])); ?></td></tr>
                        <tr><th>Resolution Remarks</th><td><?php echo nl2br(h($complaint['resolution_remarks'] ?? '')); ?></td></tr>
                    </tbody>
                </table>

                <div class="mt-3">
                    <?php if (empty($complaint['is_archived'])): ?>
                    <a href="complaint_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn btn-primary">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Edit
                    </a>
                    <?php endif; ?>
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
<script>
    const complainantSearch = document.getElementById('resident_name');
    const complainantId = document.getElementById('resident_id');
    const complainantSuggestions = document.getElementById('complainant-suggestions');
    const complainantHint = document.getElementById('complainant-search-hint');
    const complainantChoices = <?php echo json_encode($complainantChoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let activeComplainantSuggestion = -1;

    const closeComplainantSuggestions = () => {
        if (!complainantSuggestions || !complainantSearch) return;
        complainantSuggestions.hidden = true;
        complainantSuggestions.replaceChildren();
        complainantSearch.setAttribute('aria-expanded', 'false');
        complainantSearch.removeAttribute('aria-activedescendant');
        activeComplainantSuggestion = -1;
    };

    const chooseComplainant = (resident) => {
        complainantId.value = String(resident.id);
        complainantSearch.value = resident.label.replace(/\s+\([^)]+\)$/, '');
        complainantHint.textContent = 'Linked resident · ' + resident.label.match(/\(([^)]+)\)$/)?.[1];
        closeComplainantSuggestions();
    };

    const showComplainantSuggestions = () => {
        if (!complainantSearch || !complainantSuggestions) return;
        complainantId.value = '';
        const query = complainantSearch.value.trim().toLocaleLowerCase();
        complainantSuggestions.replaceChildren();
        activeComplainantSuggestion = -1;
        if (!query) {
            closeComplainantSuggestions();
            complainantHint.textContent = 'Select a registered resident, or enter a name if they are not registered.';
            return;
        }

        const matches = complainantChoices
            .filter((resident) => resident.search.toLocaleLowerCase().includes(query))
            .slice(0, 8);
        matches.forEach((resident, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'resident-search-option';
            option.id = `complainant-option-${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            const name = document.createElement('strong');
            const number = document.createElement('span');
            name.textContent = resident.label.replace(/\s+\([^)]+\)$/, '');
            number.textContent = 'Resident No. ' + (resident.label.match(/\(([^)]+)\)$/)?.[1] || '');
            option.append(name, number);
            option.addEventListener('mousedown', (event) => event.preventDefault());
            option.addEventListener('click', () => chooseComplainant(resident));
            complainantSuggestions.append(option);
        });

        const manualOption = document.createElement('button');
        manualOption.type = 'button';
        manualOption.className = 'resident-search-option is-manual';
        manualOption.id = 'complainant-option-manual';
        manualOption.setAttribute('role', 'option');
        manualOption.setAttribute('aria-selected', 'false');
        const manualName = document.createElement('strong');
        const manualHint = document.createElement('span');
        manualName.textContent = 'Use as unregistered complainant';
        manualHint.textContent = complainantSearch.value.trim();
        manualOption.append(manualName, manualHint);
        manualOption.addEventListener('mousedown', (event) => event.preventDefault());
        manualOption.addEventListener('click', () => {
            complainantId.value = '';
            complainantSearch.value = complainantSearch.value.trim();
            complainantHint.textContent = 'This complaint will be saved without linking a resident record.';
            closeComplainantSuggestions();
        });
        complainantSuggestions.append(manualOption);
        complainantSuggestions.hidden = false;
        complainantSearch.setAttribute('aria-expanded', 'true');
        complainantHint.textContent = matches.length
            ? 'Choose a resident to link the complaint, or use the typed name without a resident record.'
            : 'No registered residents match. You can use the typed name without linking a resident record.';
    };

    complainantSearch?.addEventListener('input', showComplainantSuggestions);
    complainantSearch?.addEventListener('focus', showComplainantSuggestions);
    complainantSearch?.addEventListener('keydown', (event) => {
        if (!complainantSuggestions || complainantSuggestions.hidden) return;
        const options = [...complainantSuggestions.querySelectorAll('[role="option"]')];
        if (event.key === 'ArrowDown' && options.length) {
            event.preventDefault();
            activeComplainantSuggestion = (activeComplainantSuggestion + 1) % options.length;
        } else if (event.key === 'ArrowUp' && options.length) {
            event.preventDefault();
            activeComplainantSuggestion = activeComplainantSuggestion <= 0 ? options.length - 1 : activeComplainantSuggestion - 1;
        } else if (event.key === 'Enter' && activeComplainantSuggestion >= 0) {
            event.preventDefault();
            options[activeComplainantSuggestion].click();
        } else if (event.key === 'Escape') {
            closeComplainantSuggestions();
            return;
        } else {
            return;
        }
        options.forEach((option, index) => {
            option.setAttribute('aria-selected', index === activeComplainantSuggestion ? 'true' : 'false');
        });
        complainantSearch.setAttribute('aria-activedescendant', options[activeComplainantSuggestion].id);
    });
    complainantSearch?.addEventListener('blur', () => window.setTimeout(closeComplainantSuggestions, 120));

    if (complainantSearch && complainantId?.value && complainantHint) {
        const selected = complainantChoices.find((resident) => String(resident.id) === complainantId.value);
        if (selected) {
            complainantHint.textContent = 'Linked resident · ' + (selected.label.match(/\(([^)]+)\)$/)?.[1] || '');
        }
    }

    const complaineeSearch = document.getElementById('complainee_search');
    const complaineeId = document.getElementById('complainee_resident_id');
    const suggestions = document.getElementById('complainee-suggestions');
    const residentChoices = <?php echo json_encode($complaineeChoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let activeSuggestion = -1;

    const closeSuggestions = () => {
        if (!suggestions || !complaineeSearch) return;
        suggestions.hidden = true;
        suggestions.replaceChildren();
        complaineeSearch.setAttribute('aria-expanded', 'false');
        complaineeSearch.removeAttribute('aria-activedescendant');
        activeSuggestion = -1;
    };

    const chooseResident = (resident) => {
        complaineeId.value = String(resident.id);
        complaineeSearch.value = resident.label;
        complaineeSearch.setCustomValidity('');
        closeSuggestions();
    };

    const showSuggestions = () => {
        if (!complaineeSearch || !suggestions) return;
        complaineeId.value = '';
        complaineeSearch.setCustomValidity('Choose a registered resident from the suggestions.');
        const query = complaineeSearch.value.trim().toLocaleLowerCase();
        suggestions.replaceChildren();
        if (!query) {
            closeSuggestions();
            return;
        }

        const matches = residentChoices
            .filter((resident) => resident.search.toLocaleLowerCase().includes(query))
            .slice(0, 8);

        if (matches.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'resident-search-empty';
            empty.textContent = 'No matching residents';
            suggestions.append(empty);
        } else {
            matches.forEach((resident, index) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'resident-search-option';
                option.id = `complainee-option-${index}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.textContent = resident.label;
                option.addEventListener('mousedown', (event) => event.preventDefault());
                option.addEventListener('click', () => chooseResident(resident));
                suggestions.append(option);
            });
        }

        suggestions.hidden = false;
        complaineeSearch.setAttribute('aria-expanded', 'true');
    };

    complaineeSearch?.addEventListener('input', showSuggestions);
    complaineeSearch?.addEventListener('keydown', (event) => {
        if (!suggestions || suggestions.hidden) return;
        const options = [...suggestions.querySelectorAll('[role="option"]')];
        if (event.key === 'ArrowDown' && options.length) {
            event.preventDefault();
            activeSuggestion = (activeSuggestion + 1) % options.length;
        } else if (event.key === 'ArrowUp' && options.length) {
            event.preventDefault();
            activeSuggestion = (activeSuggestion - 1 + options.length) % options.length;
        } else if (event.key === 'Enter' && activeSuggestion >= 0) {
            event.preventDefault();
            options[activeSuggestion].click();
        } else if (event.key === 'Escape') {
            closeSuggestions();
            return;
        } else {
            return;
        }
        options.forEach((option, index) => {
            option.setAttribute('aria-selected', index === activeSuggestion ? 'true' : 'false');
        });
        complaineeSearch.setAttribute('aria-activedescendant', options[activeSuggestion].id);
    });
    complaineeSearch?.addEventListener('blur', () => {
        window.setTimeout(closeSuggestions, 120);
    });

    if (complaineeSearch && complaineeId && complaineeId.value === '') {
        complaineeSearch.setCustomValidity('Choose a registered resident from the suggestions.');
    }
    const complaintStatus = document.getElementById('status');
    const resolutionAction = document.getElementById('response');
    const resolutionRemarks = document.getElementById('resolution_remarks');
    const resolutionRequiredIndicators = document.querySelectorAll('.resolution-required-indicator');
    const updateResolutionRequirements = () => {
        const isResolved = complaintStatus?.value === 'Resolved';
        if (resolutionAction) resolutionAction.required = isResolved;
        if (resolutionRemarks) resolutionRemarks.required = isResolved;
        resolutionRequiredIndicators.forEach((indicator) => {
            indicator.hidden = !isResolved;
        });
    };
    complaintStatus?.addEventListener('change', updateResolutionRequirements);
    updateResolutionRequirements();
</script>
</body>
</html>
