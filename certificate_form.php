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
    $stmt = $conn->prepare('SELECT c.*, r.first_name, r.last_name, u.first_name AS requester_first_name, u.last_name AS requester_last_name FROM certificates c LEFT JOIN residents r ON c.resident_id = r.id LEFT JOIN users u ON c.requested_by_user_id = u.id WHERE c.id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $certificate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$certificate) {
        header('Location: certificates.php?msg=missing');
        exit;
    }
}

if ($certificate && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Location: certificates.php?msg=delete_disabled');
    exit;
}

if ($certificate && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: certificates.php?msg=status_only');
    exit;
}

if ($certificate && isset($_GET['edit'])) {
    header('Location: certificates.php?msg=status_only');
    exit;
}

$isNew = !$certificate;

$residents = $isResident
    ? $conn->query('SELECT id, resident_number, first_name, middle_name, last_name, suffix FROM residents WHERE id = ' . (int)$currentResident['id'])
    : $conn->query('SELECT id, resident_number, first_name, middle_name, last_name, suffix FROM residents ORDER BY last_name ASC, first_name ASC');
$residentOptions = $residents->fetch_all(MYSQLI_ASSOC);

$statusOptions = ['Pending', 'Approved', 'Released', 'Rejected'];

$values = [
    'resident_id'      => $certificate['resident_id'] ?? '',
    'requester_relationship' => $certificate['requester_relationship'] ?? '',
    'certificate_type' => $certificate['certificate_type'] ?? '',
    'status'           => $certificate['status'] ?? 'Pending',
    'request_date'     => $certificate['request_date'] ?? date('Y-m-d'),
    'approved_date'    => $certificate['approved_date'] ?? '',
    'purpose'          => $certificate['purpose'] ?? '',
    'remarks'          => $certificate['remarks'] ?? '',
    'request_for'      => 'self',
    'other_resident_number' => '',
    'other_first_name' => '',
    'other_middle_name' => '',
    'other_last_name' => '',
    'other_suffix' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $values[$field] = trim($_POST[$field] ?? '');
    }
    if ($isResident) {
        $values['request_for'] = trim($_POST['request_for'] ?? 'self');
        $values['other_resident_number'] = trim($_POST['other_resident_number'] ?? '');
        $values['other_first_name'] = trim($_POST['other_first_name'] ?? '');
        $values['other_middle_name'] = trim($_POST['other_middle_name'] ?? '');
        $values['other_last_name'] = trim($_POST['other_last_name'] ?? '');
        $values['other_suffix'] = trim($_POST['other_suffix'] ?? '');
        $values['requester_relationship'] = trim($_POST['requester_relationship'] ?? '');
        $values['status'] = 'Pending';
        $values['approved_date'] = '';
        $values['request_date'] = date('Y-m-d');

        if ($values['request_for'] === 'self') {
            $values['resident_id'] = (string)$currentResident['id'];
            $values['requester_relationship'] = '';
        } elseif ($values['request_for'] === 'other') {
            if ($values['other_resident_number'] === '' || $values['other_first_name'] === '' || $values['other_last_name'] === '') {
                $error = 'Enter the other resident’s resident number, first name, and last name. Include their middle name and suffix when present.';
            } elseif (!in_array($values['requester_relationship'], ['Parent/Guardian', 'Spouse', 'Child', 'Sibling', 'Other'], true)) {
                $error = 'Choose your relationship to the resident.';
            } else {
                $stmt = $conn->prepare('SELECT id FROM residents WHERE resident_number = ? AND first_name = ? AND COALESCE(middle_name, \'\') = ? AND last_name = ? AND COALESCE(suffix, \'\') = ? LIMIT 1');
                $stmt->bind_param('sssss', $values['other_resident_number'], $values['other_first_name'], $values['other_middle_name'], $values['other_last_name'], $values['other_suffix']);
                $stmt->execute();
                $otherResident = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($otherResident) {
                    $values['resident_id'] = (string)$otherResident['id'];
                } else {
                    $error = 'Those details do not match a resident record. Check them or contact the barangay office.';
                }
            }
        } else {
            $error = 'Choose whether this request is for yourself or another resident.';
        }
    }
    $residentId = (int)$values['resident_id'];
    $approvedDate = $values['approved_date'] !== '' ? $values['approved_date'] : null;
    $requestedByUserId = (int)($_SESSION['user_id'] ?? 0);
    $requesterRelationship = $values['requester_relationship'] !== '' ? $values['requester_relationship'] : null;
    $residentExists = false;
    if (!$isResident && $residentId > 0) {
        $residentStmt = $conn->prepare('SELECT id FROM residents WHERE id = ?');
        $residentStmt->bind_param('i', $residentId);
        $residentStmt->execute();
        $residentExists = (bool)$residentStmt->get_result()->fetch_assoc();
        $residentStmt->close();
    }

    if ($error !== '') {
        // Keep the form open with the validation message.
    } elseif ($residentId <= 0) {
        $error = 'Please choose the resident requesting the certificate.';
    } elseif (!$isResident && !$residentExists) {
        $error = 'Choose a resident from the search suggestions.';
    } elseif ($values['certificate_type'] === '') {
        $error = 'Please choose a certificate type.';
    } elseif (!in_array($values['status'], $statusOptions, true)) {
        $error = 'Invalid certificate status.';
    } elseif ($values['request_date'] === '') {
        $error = 'Request date is required.';
    } else {
        $stmt = $conn->prepare('INSERT INTO certificates (resident_id, requested_by_user_id, requester_relationship, certificate_type, status, request_date, approved_date, purpose, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param(
            'iisssssss',
            $residentId,
            $requestedByUserId,
            $requesterRelationship,
            $values['certificate_type'],
            $values['status'],
            $values['request_date'],
            $approvedDate,
            $values['purpose'],
            $values['remarks']
        );
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'created', 'certificates', 'Created certificate request with status ' . $values['status'] . '.', $conn->insert_id);
        header('Location: ' . ($isResident ? 'resident_dashboard.php?msg=certificate_created' : 'certificates.php?msg=created'));
        exit;
    }
}

$showForm = $isNew;
$title = $isNew ? 'New Certificate Request' : 'Certificate Request Details';
$typeOptions = ['Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Business Clearance', 'Good Moral'];
$selectedResidentLabel = '';
foreach ($residentOptions as $residentOption) {
    if ((int)$residentOption['id'] === (int)$values['resident_id']) {
        $selectedResidentLabel = trim($residentOption['last_name'] . ', ' . $residentOption['first_name'] . ' ' . $residentOption['middle_name'] . ' ' . $residentOption['suffix'])
            . ' (' . $residentOption['resident_number'] . ')';
        break;
    }
}
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

            <?php if ($showForm && count($residentOptions) === 0): ?>

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

                <h3>New Request</h3>

                <form class="record-form" method="post" action="certificate_form.php<?php echo $certificate ? '?id=' . $id . '&edit=1' : ''; ?>">
                    <?php echo csrfField(); ?>

                    <div class="record-grid">

                        <?php if ($isResident): ?>
                        <fieldset class="form-group full-width recipient-choice">
                            <legend>Who is this certificate for? *</legend>
                            <label><input type="radio" name="request_for" value="self"<?php echo $values['request_for'] === 'self' ? ' checked' : ''; ?>> For myself</label>
                            <label><input type="radio" name="request_for" value="other"<?php echo $values['request_for'] === 'other' ? ' checked' : ''; ?>> For another resident</label>
                        </fieldset>
                        <div class="form-group full-width" id="selfRecipient">
                            <label for="resident_self">Resident</label>
                            <input type="text" id="resident_self" value="<?php echo h(trim($currentResident['first_name'] . ' ' . $currentResident['last_name'])); ?>" readonly>
                        </div>
                        <div class="recipient-fields full-width" id="otherRecipientFields"<?php echo $values['request_for'] === 'other' ? '' : ' hidden'; ?>>
                            <div class="form-group">
                                <label for="other_resident_number">Resident Number *</label>
                                <input type="text" id="other_resident_number" name="other_resident_number" value="<?php echo h($values['other_resident_number']); ?>" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label for="other_first_name">Resident First Name *</label>
                                <input type="text" id="other_first_name" name="other_first_name" value="<?php echo h($values['other_first_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="other_middle_name">Resident Middle Name</label>
                                <input type="text" id="other_middle_name" name="other_middle_name" value="<?php echo h($values['other_middle_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="other_last_name">Resident Last Name *</label>
                                <input type="text" id="other_last_name" name="other_last_name" value="<?php echo h($values['other_last_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="other_suffix">Suffix</label>
                                <input type="text" id="other_suffix" name="other_suffix" value="<?php echo h($values['other_suffix']); ?>" placeholder="Jr., Sr., III">
                            </div>
                            <div class="form-group">
                                <label for="requester_relationship">Your relationship *</label>
                                <select id="requester_relationship" name="requester_relationship">
                                    <option value="">Select relationship</option>
                                    <?php foreach (['Parent/Guardian', 'Spouse', 'Child', 'Sibling', 'Other'] as $relationship): ?>
                                        <option value="<?php echo h($relationship); ?>"<?php echo $values['requester_relationship'] === $relationship ? ' selected' : ''; ?>><?php echo h($relationship); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <p class="recipient-help">The other person must already have a resident record. The request will be reviewed by the barangay office.</p>
                            <p class="recipient-help" id="residentLookupStatus" role="status" aria-live="polite">Enter the resident’s full name, including middle name and suffix when present, to look up their resident number.</p>
                        </div>
                        <?php else: ?>
                        <div class="form-group">
                            <label for="resident_search">Resident *</label>
                            <div class="resident-autocomplete" id="residentAutocomplete">
                                <input type="search" id="resident_search" name="resident_search" value="<?php echo h($_POST['resident_search'] ?? $selectedResidentLabel); ?>" placeholder="Search by resident name or number" autocomplete="off" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="residentSuggestions" required>
                                <input type="hidden" id="resident_id" name="resident_id" value="<?php echo (int)$values['resident_id']; ?>">
                                <div class="resident-autocomplete__suggestions" id="residentSuggestions" role="listbox" aria-label="Matching residents" hidden>
                                    <?php foreach ($residentOptions as $residentOption): ?>
                                        <?php
                                        $residentLabel = trim($residentOption['last_name'] . ', ' . $residentOption['first_name'] . ' ' . $residentOption['middle_name'] . ' ' . $residentOption['suffix'])
                                            . ' (' . $residentOption['resident_number'] . ')';
                                        $residentSearchValue = strtolower(trim(
                                            $residentOption['first_name'] . ' ' . $residentOption['middle_name'] . ' ' .
                                            $residentOption['last_name'] . ' ' . $residentOption['suffix'] . ' ' .
                                            $residentOption['resident_number'] . ' ' . $residentLabel
                                        ));
                                        ?>
                                        <button type="button" class="resident-autocomplete__option" id="resident-option-<?php echo (int)$residentOption['id']; ?>" tabindex="-1" role="option" aria-selected="false" data-resident-id="<?php echo (int)$residentOption['id']; ?>" data-label="<?php echo h($residentLabel); ?>" data-search="<?php echo h($residentSearchValue); ?>">
                                            <span><?php echo h(trim($residentOption['first_name'] . ' ' . $residentOption['middle_name'] . ' ' . $residentOption['last_name'] . ' ' . $residentOption['suffix'])); ?></span>
                                            <small><?php echo h($residentOption['resident_number']); ?></small>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="resident-autocomplete__hint">Type a name or resident number, then select a match.</small>
                        </div>
                        <?php endif; ?>

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
                        <?php if (!empty($certificate['requester_first_name'])): ?>
                        <tr><th>Requested By</th><td><?php echo h(trim($certificate['requester_first_name'] . ' ' . $certificate['requester_last_name'])); ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($certificate['requester_relationship'])): ?>
                        <tr><th>Requester Relationship</th><td><?php echo h($certificate['requester_relationship']); ?></td></tr>
                        <?php endif; ?>
                        <tr><th>Certificate Type</th><td><?php echo h($certificate['certificate_type']); ?></td></tr>
                        <tr><th>Status</th><td><?php echo h($certificate['status']); ?></td></tr>
                        <tr><th>Request Date</th><td><?php echo h($certificate['request_date']); ?></td></tr>
                        <tr><th>Approved / Released Date</th><td><?php echo h($certificate['approved_date']); ?></td></tr>
                        <tr><th>Purpose</th><td><?php echo h($certificate['purpose']); ?></td></tr>
                        <tr><th>Remarks</th><td><?php echo h($certificate['remarks']); ?></td></tr>
                    </tbody>
                </table>

                <div class="record-actions">
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
<?php if (!$isResident && $showForm): ?>
<script>
    const residentAutocomplete = document.getElementById('residentAutocomplete');
    const residentSearchInput = document.getElementById('resident_search');
    const residentIdInput = document.getElementById('resident_id');
    const residentSuggestions = document.getElementById('residentSuggestions');
    const residentOptions = Array.from(residentSuggestions.querySelectorAll('[role="option"]'));
    let activeResidentOption = -1;

    function closeResidentSuggestions() {
        residentSuggestions.hidden = true;
        residentSearchInput.setAttribute('aria-expanded', 'false');
        residentSearchInput.removeAttribute('aria-activedescendant');
        residentOptions.forEach((option) => {
            option.classList.remove('is-active');
            option.setAttribute('aria-selected', 'false');
        });
        activeResidentOption = -1;
    }

    function updateResidentSuggestions() {
        const query = residentSearchInput.value.trim().toLowerCase();
        residentIdInput.value = '';
        residentSearchInput.setCustomValidity('');
        let visibleCount = 0;

        residentOptions.forEach((option) => {
            const matches = query !== '' && option.dataset.search.includes(query);
            option.hidden = !matches;
            if (matches) visibleCount += 1;
        });

        if (visibleCount > 0) {
            residentSuggestions.hidden = false;
            residentSearchInput.setAttribute('aria-expanded', 'true');
        } else {
            closeResidentSuggestions();
        }
        activeResidentOption = -1;
    }

    function selectResident(option) {
        residentSearchInput.value = option.dataset.label;
        residentIdInput.value = option.dataset.residentId;
        residentSearchInput.setCustomValidity('');
        closeResidentSuggestions();
    }

    function setActiveResidentOption(index) {
        const visibleOptions = residentOptions.filter((option) => !option.hidden);
        if (visibleOptions.length === 0) return;
        activeResidentOption = (index + visibleOptions.length) % visibleOptions.length;
        residentOptions.forEach((option) => {
            option.classList.remove('is-active');
            option.setAttribute('aria-selected', 'false');
        });
        const activeOption = visibleOptions[activeResidentOption];
        activeOption.classList.add('is-active');
        activeOption.setAttribute('aria-selected', 'true');
        residentSearchInput.setAttribute('aria-activedescendant', activeOption.id);
        activeOption.scrollIntoView({block: 'nearest'});
    }

    residentSearchInput.addEventListener('input', updateResidentSuggestions);
    residentSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (residentSuggestions.hidden) updateResidentSuggestions();
            setActiveResidentOption(activeResidentOption + 1);
        } else if (event.key === 'ArrowUp' && !residentSuggestions.hidden) {
            event.preventDefault();
            setActiveResidentOption(activeResidentOption - 1);
        } else if (event.key === 'Enter' && !residentSuggestions.hidden) {
            const visibleOptions = residentOptions.filter((option) => !option.hidden);
            if (visibleOptions.length > 0) {
                event.preventDefault();
                selectResident(visibleOptions[Math.max(activeResidentOption, 0)]);
            }
        } else if (event.key === 'Escape') {
            closeResidentSuggestions();
        }
    });
    residentOptions.forEach((option) => {
        option.addEventListener('click', () => selectResident(option));
    });
    document.addEventListener('click', (event) => {
        if (!residentAutocomplete.contains(event.target)) closeResidentSuggestions();
    });
    residentSearchInput.form.addEventListener('submit', (event) => {
        if (residentIdInput.value === '') {
            event.preventDefault();
            residentSearchInput.setCustomValidity('Choose a resident from the search suggestions.');
            residentSearchInput.reportValidity();
        }
    });
</script>
<?php endif; ?>
<?php if ($isResident && $showForm): ?>
<script>
    const requestForInputs = document.querySelectorAll('input[name="request_for"]');
    const selfRecipient = document.getElementById('selfRecipient');
    const otherRecipientFields = document.getElementById('otherRecipientFields');
    const otherRecipientInputs = otherRecipientFields.querySelectorAll('input, select');
    const firstNameInput = document.getElementById('other_first_name');
    const middleNameInput = document.getElementById('other_middle_name');
    const lastNameInput = document.getElementById('other_last_name');
    const suffixInput = document.getElementById('other_suffix');
    const residentNumberInput = document.getElementById('other_resident_number');
    const lookupStatus = document.getElementById('residentLookupStatus');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    let lookupTimer;
    let lookupSequence = 0;

    async function lookupResidentNumber(sequence) {
        const firstName = firstNameInput.value.trim();
        const middleName = middleNameInput.value.trim();
        const lastName = lastNameInput.value.trim();
        const suffix = suffixInput.value.trim();
        if (!firstName || !lastName) {
            lookupStatus.textContent = '';
            return;
        }

        lookupStatus.textContent = 'Checking the resident record...';
        try {
            const response = await fetch('resident_lookup.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: new URLSearchParams({first_name: firstName, middle_name: middleName, last_name: lastName, suffix: suffix, csrf_token: csrfToken})
            });
            const result = await response.json();
            if (sequence !== lookupSequence) {
                return;
            }
            if (!response.ok) {
                lookupStatus.textContent = result.message || 'Could not check the resident record. Try again shortly.';
                return;
            }
            if (result.found) {
                residentNumberInput.value = result.resident_number;
                lookupStatus.textContent = 'Resident number filled in from the matching resident record.';
            } else if (result.ambiguous) {
                lookupStatus.textContent = 'More than one resident has that name. Enter the resident number manually or contact the barangay office.';
            } else {
                lookupStatus.textContent = 'No single matching resident was found. Check the name or enter the number manually.';
            }
        } catch (error) {
            if (sequence === lookupSequence) {
                lookupStatus.textContent = 'Could not check the resident record. Try again shortly.';
            }
        }
    }

    function scheduleResidentLookup() {
        lookupSequence += 1;
        clearTimeout(lookupTimer);
        residentNumberInput.value = '';
        if (document.querySelector('input[name="request_for"]:checked')?.value !== 'other') {
            lookupStatus.textContent = '';
            return;
        }
        lookupTimer = setTimeout(() => lookupResidentNumber(lookupSequence), 500);
    }

    function updateRecipientFields() {
        const forOther = document.querySelector('input[name="request_for"]:checked')?.value === 'other';
        selfRecipient.hidden = forOther;
        otherRecipientFields.hidden = !forOther;
        otherRecipientInputs.forEach((input) => {
            input.disabled = !forOther;
            input.required = forOther;
        });
    }

    requestForInputs.forEach((input) => input.addEventListener('change', updateRecipientFields));
    firstNameInput.addEventListener('input', scheduleResidentLookup);
    middleNameInput.addEventListener('input', scheduleResidentLookup);
    lastNameInput.addEventListener('input', scheduleResidentLookup);
    suffixInput.addEventListener('input', scheduleResidentLookup);
    updateRecipientFields();
</script>
<?php endif; ?>
</body>
</html>
