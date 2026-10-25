<?php
require_once __DIR__ . '/layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$household = null;
$householdResidents = [];
$error = '';

if ($id > 0) {
    $stmt = $conn->prepare('SELECT households.*, ' . householdMembersSql('households.household_number') . ' AS members_count FROM households WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $household = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$household) {
        header('Location: household.php?msg=missing');
        exit;
    }
}

if ($household) {
    $membersStmt = $conn->prepare('SELECT id, resident_number, first_name, middle_name, last_name, suffix, household_head, relationship_head FROM residents WHERE household_id = ? ORDER BY last_name, first_name');
    $membersStmt->bind_param('s', $household['household_number']);
    $membersStmt->execute();
    $householdResidents = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $membersStmt->close();
}

$residentFullName = static function (array $resident): string {
    return trim(implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? '',
        $resident['suffix'] ?? '',
    ], static fn($part) => trim((string)$part) !== '')));
};
$memberById = [];
foreach ($householdResidents as $householdResident) {
    $memberById[(int)$householdResident['id']] = $householdResident;
}
$selectedHeadResidentId = 0;
foreach ($householdResidents as $householdResident) {
    if ($residentFullName($householdResident) === (string)($household['household_head'] ?? '')) {
        $selectedHeadResidentId = (int)$householdResident['id'];
        break;
    }
}
$familyAssignments = [];
$familyRoles = [];
$familyHeads = [];
$existingFamilyNames = [];
foreach ($householdResidents as $householdResident) {
    $currentFamilyName = trim((string)($householdResident['household_head'] ?? ''));
    if ($currentFamilyName !== '') {
        $existingFamilyNames[$currentFamilyName] = true;
    }
    $name = $residentFullName($householdResident);
    if ($name !== '' && $currentFamilyName === $name) {
        $familyHeads[(int)$householdResident['id']] = $name;
    }
}

if ($household && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Location: household.php?msg=delete_disabled');
    exit;
}

$isEdit = $household && isset($_GET['edit']);
$isNew = !$household;

// Values shown in the form: the posted values on error, otherwise the record.
$values = [
    'household_number' => $household['household_number'] ?? '',
    'household_head'   => $household['household_head'] ?? '',
    'members_count'    => $household['members_count'] ?? '',
    'zone'             => $household['zone'] ?? '',
    'status'           => $household['status'] ?? 'Active',
    'household_type'   => $household['household_type'] ?? '',
    'income_bracket'   => $household['income_bracket'] ?? '',
    'address'          => $household['address'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $values[$field] = trim($_POST[$field] ?? '');
    }
    $membersCount = $household ? (int)($household['members_count'] ?? 0) : null;
    $selectedHeadResidentId = max(0, (int)($_POST['household_head_resident_id'] ?? 0));
    $familyAssignments = is_array($_POST['family_head'] ?? null) ? $_POST['family_head'] : [];
    $familyRoles = is_array($_POST['family_role'] ?? null) ? $_POST['family_role'] : [];

    if ($household && $householdResidents !== []) {
        if (!isset($memberById[$selectedHeadResidentId])) {
            $error = 'Choose a household head from the residents linked to this household.';
        } else {
            $values['household_head'] = $residentFullName($memberById[$selectedHeadResidentId]);
        }

        foreach ($householdResidents as $householdResident) {
            $residentId = (int)$householdResident['id'];
            $familyHeadId = max(0, (int)($familyAssignments[$residentId] ?? 0));
            if (!isset($memberById[$familyHeadId])) {
                $error = 'Choose a family for every resident in this household.';
                break;
            }
            if (trim((string)($familyRoles[$residentId] ?? '')) === '') {
                $error = 'Enter a family role for every resident in this household.';
                break;
            }
        }
    }

    if ($error !== '') {
        // Keep the submitted form values visible so the selections can be corrected.
    } elseif ($values['household_number'] === '') {
        $error = 'Household number is required.';
    } elseif ($values['household_head'] === '') {
        $error = 'Household head is required.';
    } else {
        // Household number must stay unique
        $check = $conn->prepare('SELECT id FROM households WHERE household_number = ? AND id <> ? LIMIT 1');
        $checkId = $household ? $id : 0;
        $check->bind_param('si', $values['household_number'], $checkId);
        $check->execute();
        $duplicate = $check->get_result()->fetch_assoc();
        $check->close();

        if ($duplicate) {
            $error = 'That household number is already used by another record.';
        } elseif ($household) {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare('UPDATE households SET household_number = ?, household_head = ?, members_count = ?, zone = ?, status = ?, household_type = ?, income_bracket = ?, address = ? WHERE id = ?');
                $stmt->bind_param(
                    'ssisssssi',
                    $values['household_number'],
                    $values['household_head'],
                    $membersCount,
                    $values['zone'],
                    $values['status'],
                    $values['household_type'],
                    $values['income_bracket'],
                    $values['address'],
                    $id
                );
                $stmt->execute();
                $stmt->close();

                if ($householdResidents !== []) {
                    $memberUpdate = $conn->prepare('UPDATE residents SET household_id = ?, household_head = ?, relationship_head = ? WHERE id = ? AND household_id = ?');
                    foreach ($householdResidents as $householdResident) {
                        $residentId = (int)$householdResident['id'];
                        $familyHeadId = (int)$familyAssignments[$residentId];
                        $familyHeadName = $residentFullName($memberById[$familyHeadId]);
                        $familyRole = trim((string)$familyRoles[$residentId]);
                        $memberUpdate->bind_param('sssis', $values['household_number'], $familyHeadName, $familyRole, $residentId, $household['household_number']);
                        $memberUpdate->execute();
                    }
                    $memberUpdate->close();
                }

                $conn->commit();
                logActivity($conn, 'updated', 'households', 'Updated household record and family assignments.', $id);
                header('Location: household.php?msg=updated');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Household update failed: ' . $exception->getMessage());
                $error = 'The household changes could not be saved. Please try again.';
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO households (household_number, household_head, members_count, zone, status, household_type, income_bracket, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param(
                'ssisssss',
                $values['household_number'],
                $values['household_head'],
                $membersCount,
                $values['zone'],
                $values['status'],
                $values['household_type'],
                $values['income_bracket'],
                $values['address']
            );
            $stmt->execute();
            $stmt->close();
            logActivity($conn, 'created', 'households', 'Created household record.', $conn->insert_id);
            header('Location: household.php?msg=created');
            exit;
        }
    }
}

$showForm = $isNew || $isEdit;
$pageTitle = $isNew ? 'Add Household' : ($isEdit ? 'Edit Household' : 'Household Details');
$statusOptions = ['Active', 'Archived'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($pageTitle); ?>
    <link rel="stylesheet" href="<?php echo asset('household.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('household'); ?>
    <main class="main-content">

        <?php renderTopbar($pageTitle, $isNew ? 'Create a new household record.' : 'View and manage a household record.', 'panel', ['breadcrumb' => 'Household', 'clock' => true]); ?>

        <div class="content-card">

            <?php if ($error !== ''): ?>
                <div class="notice notice-error" role="alert"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($showForm): ?>

                <h3><?php echo $isNew ? 'New Household' : 'Edit Household'; ?></h3>

                <form class="record-form" method="post" action="household_form.php<?php echo $household ? '?id=' . $id . '&edit=1' : ''; ?>">
                    <?php echo csrfField(); ?>

                    <div class="record-grid">

                        <div class="form-group">
                            <label for="household_number">Household Number *</label>
                            <input type="text" id="household_number" name="household_number" value="<?php echo h($values['household_number']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="household_head">Household Head *</label>
                            <?php if ($householdResidents !== []): ?>
                                <select id="household_head_resident_id" name="household_head_resident_id" required>
                                    <option value="">Choose household head</option>
                                    <?php foreach ($householdResidents as $householdResident): ?>
                                        <?php $residentId = (int)$householdResident['id']; ?>
                                        <option value="<?php echo $residentId; ?>"<?php echo $selectedHeadResidentId === $residentId ? ' selected' : ''; ?>>
                                            <?php echo h($residentFullName($householdResident) . ' · ' . $householdResident['resident_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Choose from residents linked to this household.</small>
                            <?php else: ?>
                                <input type="text" id="household_head" name="household_head" value="<?php echo h($values['household_head']); ?>" required>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="members_count">Number of Members</label>
                            <input type="number" id="members_count" value="<?php echo h($household['members_count'] ?? 0); ?>" readonly aria-describedby="members_count_help">
                            <small id="members_count_help" class="text-muted">Counted from residents with this Household ID.</small>
                        </div>

                        <div class="form-group">
                            <label for="zone">Zone / Purok</label>
                            <input type="text" id="zone" name="zone" value="<?php echo h($values['zone']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <?php foreach ($statusOptions as $option): ?>
                                    <option<?php echo $values['status'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="household_type">Household Type</label>
                            <select id="household_type" name="household_type">
                                <option value="">Select type</option>
                                <?php selectOptions(HOUSEHOLD_TYPES, $values['household_type']); ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="income_bracket">Monthly Income Bracket</label>
                            <select id="income_bracket" name="income_bracket">
                                <option value="">Select bracket</option>
                                <?php selectOptions(INCOME_BRACKETS, $values['income_bracket']); ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label for="address">Complete Address</label>
                            <textarea id="address" name="address" rows="3"><?php echo h($values['address']); ?></textarea>
                        </div>

                    </div>

                    <?php if ($household && $householdResidents !== []): ?>
                        <section class="household-family-manager" aria-labelledby="familyManagerHeading">
                            <div class="household-family-manager-heading">
                                <div>
                                    <h3 id="familyManagerHeading">Family Management</h3>
                                    <p><?php echo count($existingFamilyNames); ?> existing families · <?php echo count($householdResidents); ?> residents. Choose a family and role for each resident.</p>
                                </div>
                                <button class="btn-primary" type="button" id="addFamilyButton">
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i> Add Family
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="household-assignment-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Resident</th>
                                            <th scope="col">Family</th>
                                            <th scope="col">Family Role</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($householdResidents as $householdResident): ?>
                                            <?php
                                            $residentId = (int)$householdResident['id'];
                                            $currentFamilyHeadId = 0;
                                            foreach ($familyHeads as $familyHeadId => $familyHeadName) {
                                                if ($familyHeadName === trim((string)($householdResident['household_head'] ?? ''))) {
                                                    $currentFamilyHeadId = (int)$familyHeadId;
                                                    break;
                                                }
                                            }
                                            $selectedFamilyHeadId = (int)($familyAssignments[$residentId] ?? $currentFamilyHeadId);
                                            $familyRole = (string)($familyRoles[$residentId] ?? $householdResident['relationship_head'] ?? '');
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo h($residentFullName($householdResident)); ?></strong>
                                                    <small><?php echo h($householdResident['resident_number']); ?></small>
                                                </td>
                                                <td>
                                                    <select name="family_head[<?php echo $residentId; ?>]" class="resident-family-select" data-resident-id="<?php echo $residentId; ?>" required>
                                                        <option value="">Choose family</option>
                                                        <optgroup label="Existing families">
                                                            <?php foreach ($familyHeads as $familyHeadId => $familyHeadName): ?>
                                                                <option value="<?php echo (int)$familyHeadId; ?>"<?php echo $selectedFamilyHeadId === (int)$familyHeadId ? ' selected' : ''; ?>>
                                                                    <?php echo h('Family of ' . $familyHeadName); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                        <optgroup label="Start a family with">
                                                            <?php foreach ($householdResidents as $candidate): ?>
                                                                <?php if (isset($familyHeads[(int)$candidate['id']])) continue; ?>
                                                                <option value="<?php echo (int)$candidate['id']; ?>"<?php echo $selectedFamilyHeadId === (int)$candidate['id'] ? ' selected' : ''; ?>>
                                                                    <?php echo h($residentFullName($candidate)); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </optgroup>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" name="family_role[<?php echo $residentId; ?>]" value="<?php echo h($familyRole); ?>" placeholder="e.g. Spouse, Child, Family Head" required>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <dialog class="add-family-dialog" id="addFamilyDialog" aria-labelledby="addFamilyHeading">
                            <h3 id="addFamilyHeading">Add a family</h3>
                            <p>Choose the resident who will lead this family group.</p>
                            <label for="newFamilyHead">Family Head</label>
                            <select id="newFamilyHead">
                                <option value="">Choose a resident</option>
                                <?php foreach ($householdResidents as $candidate): ?>
                                    <option value="<?php echo (int)$candidate['id']; ?>"><?php echo h($residentFullName($candidate)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="record-actions">
                                <button type="button" class="btn-primary" id="confirmAddFamily">Add Family</button>
                                <button type="button" class="btn-cancel" id="cancelAddFamily">Cancel</button>
                            </div>
                        </dialog>
                    <?php endif; ?>

                    <div class="record-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                            <span><?php echo $isNew ? 'Save Household' : 'Update Household'; ?></span>
                        </button>
                        <a href="household.php" class="btn-cancel">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            <span>Cancel</span>
                        </a>
                    </div>

                </form>

            <?php else: ?>

                <h3>Household Record</h3>

                <table class="record-table">
                    <tbody>
                        <tr><th>Household Number</th><td><?php echo h($household['household_number']); ?></td></tr>
                        <tr><th>Household Head</th><td><?php echo h($household['household_head']); ?></td></tr>
                        <tr><th>Number of Members</th><td><?php echo h($household['members_count']); ?></td></tr>
                        <tr><th>Zone / Purok</th><td><?php echo h($household['zone']); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($household['status']); ?></td></tr>
                        <tr><th>Household Type</th><td><?php echo h($household['household_type']); ?></td></tr>
                        <tr><th>Income Bracket</th><td><?php echo h($household['income_bracket']); ?></td></tr>
                        <tr><th>Address</th><td><?php echo h($household['address']); ?></td></tr>
                        <tr><th>Date Added</th><td><?php echo h(formatDatabaseDateTime($household['created_at'])); ?></td></tr>
                    </tbody>
                </table>

                <div class="record-actions">
                    <a href="household_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn-primary">
                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                    <a href="household.php" class="btn-cancel">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Back to Household</span>
                    </a>
                </div>

            <?php endif; ?>

        </div>

    </main>
</div>
<?php renderFooterScripts(false); ?>
<?php if ($household && $householdResidents !== []): ?>
<script>
(function(){
    const dialog = document.getElementById('addFamilyDialog');
    const addButton = document.getElementById('addFamilyButton');
    const confirmButton = document.getElementById('confirmAddFamily');
    const cancelButton = document.getElementById('cancelAddFamily');
    const headPicker = document.getElementById('newFamilyHead');

    addButton.addEventListener('click', () => dialog.showModal());
    cancelButton.addEventListener('click', () => dialog.close());
    confirmButton.addEventListener('click', function(){
        const residentId = headPicker.value;
        if (!residentId) {
            headPicker.focus();
            return;
        }
        const familySelect = document.querySelector('.resident-family-select[data-resident-id="' + residentId + '"]');
        if (!familySelect) return;
        familySelect.value = residentId;
        familySelect.dispatchEvent(new Event('change', { bubbles: true }));
        dialog.close();
        headPicker.value = '';
    });

    document.querySelectorAll('.resident-family-select').forEach(function(select){
        select.addEventListener('change', function(){
            if (select.value !== select.dataset.residentId) return;
            const roleInput = select.closest('tr').querySelector('input[name^="family_role["]');
            if (roleInput) roleInput.value = 'Family Head';
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
