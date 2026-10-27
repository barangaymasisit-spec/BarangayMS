<?php
require_once __DIR__ . '/layout.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff', 'health_worker'], true)) {
    header('Location: residents.php?denied=1');
    exit;
}

$residentId = max(0, (int)($_GET['id'] ?? 0));
$stmt = $conn->prepare(
    'SELECT residents.*, ' . householdMembersSql('residents.household_id') . ' AS live_household_members
     FROM residents
     WHERE residents.id = ?'
);
$stmt->bind_param('i', $residentId);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resident) {
    http_response_code(404);
}

$sections = [
    'Personal Information' => [
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'suffix' => 'Suffix',
        'sex' => 'Sex',
        'birth_date' => 'Birth Date',
        'age' => 'Age',
        'birth_place' => 'Place of Birth',
        'civil_status' => 'Civil Status',
        'nationality' => 'Nationality',
        'religion' => 'Religion',
        'blood_type' => 'Blood Type',
    ],
    'Contact & Emergency Contact' => [
        'mobile_number' => 'Mobile Number',
        'telephone_number' => 'Telephone Number',
        'email' => 'Email Address',
        'emergency_contact' => 'Emergency Contact',
        'emergency_number' => 'Emergency Contact Number',
        'relationship' => 'Relationship to Emergency Contact',
    ],
    'Residential Address' => [
        'house_number' => 'House Number',
        'street' => 'Street',
        'purok' => 'Purok',
        'barangay' => 'Barangay',
        'municipality' => 'Municipality',
        'province' => 'Province',
        'zip_code' => 'ZIP Code',
        'length_residency' => 'Length of Residency',
        'residency_type' => 'Residency Type',
    ],
    'Household Information' => [
        'household_id' => 'Household Number',
        'household_head' => 'Household Head',
        'relationship_head' => 'Relationship to Household Head',
        'household_members' => 'Recorded Household Members',
        'live_household_members' => 'Current Household Members',
        'household_type' => 'Household Type',
        'income_bracket' => 'Household Income Bracket',
    ],
    'Voter & Identification' => [
        'voter_status' => 'Voter Status',
        'precinct_number' => 'Precinct Number',
        'philhealth_number' => 'PhilHealth Number',
        'sss_number' => 'SSS Number',
        'gsis_number' => 'GSIS Number',
        'tin' => 'TIN',
        'national_id' => 'National ID',
    ],
    'Employment & Education' => [
        'employment_status' => 'Employment Status',
        'occupation' => 'Occupation',
        'company' => 'Company / Organization',
        'workplace' => 'Workplace',
        'monthly_income' => 'Monthly Income',
        'education' => 'Education Level',
        'school_name' => 'School Name',
        'student_status' => 'Student Status',
    ],
    'Health & Special Categories' => [
        'pwd_status' => 'Person with Disability',
        'disability_type' => 'Disability Type',
        'senior_citizen' => 'Senior Citizen',
        'blood_type_health' => 'Health Record Blood Type',
        'medical_condition' => 'Medical Condition',
        'categories' => 'Special Categories',
    ],
    'Resident Record' => [
        'resident_number' => 'Resident Number',
        'resident_status' => 'Resident Status',
        'date_registered' => 'Date Registered',
        'date_moved_in' => 'Date Moved In',
        'date_moved_out' => 'Date Moved Out',
        'move_reason' => 'Reason for Moving',
        'account_status' => 'Account Status',
        'created_date' => 'Record Created',
        'last_updated' => 'Last Updated',
    ],
];

$knownFields = ['id', 'photo_path'];
foreach ($sections as $fields) {
    $knownFields = array_merge($knownFields, array_keys($fields));
}
$additionalFields = [];
if ($resident) {
    foreach ($resident as $field => $value) {
        if (!in_array($field, $knownFields, true) && $field !== 'live_household_members') {
            $additionalFields[$field] = ucwords(str_replace('_', ' ', $field));
        }
    }
}
if ($additionalFields !== []) {
    $sections['Additional Information'] = $additionalFields;
}

function residentDetailValue(array $resident, string $field): string {
    $value = $resident[$field] ?? null;
    if ($value === null || trim((string)$value) === '') {
        return 'Not recorded';
    }
    return (string)$value;
}

$residentName = $resident
    ? trim(implode(' ', array_filter([
        $resident['first_name'] ?? '',
        $resident['middle_name'] ?? '',
        $resident['last_name'] ?? '',
        $resident['suffix'] ?? '',
    ], static fn($part) => trim((string)$part) !== '')))
    : '';
$returnUrl = 'residents.php?' . http_build_query([
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'search' => trim($_GET['search'] ?? ''),
    'status_filter' => $_GET['status_filter'] ?? 'all',
    'record_view' => ($_GET['record_view'] ?? 'active') === 'archived' ? 'archived' : 'active',
]) . '#resident-records';
$editUrl = 'resident_form.php?' . http_build_query([
    'id' => (int)($resident['id'] ?? 0),
    'edit' => 1,
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'search' => trim($_GET['search'] ?? ''),
    'status_filter' => $_GET['status_filter'] ?? 'all',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($resident ? 'Resident Record' : 'Resident Not Found'); ?>
    <link rel="stylesheet" href="<?php echo asset('residents.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="resident-view-page">
<div class="wrapper">
    <?php renderSidebar('residents', 'panel'); ?>
    <main class="main-content">
        <?php renderTopbar('Resident Record', 'Complete resident details · A4 printable view', 'panel', ['clock' => true]); ?>

        <?php if (!$resident): ?>
            <div class="notice notice-error" role="alert">The requested resident record could not be found.</div>
            <a class="resident-action resident-action-secondary" href="residents.php"><i class="fa-solid fa-arrow-left"></i> Back to Residents</a>
        <?php else: ?>
            <div class="resident-view-actions">
                <a class="resident-action resident-action-secondary" href="<?php echo h($returnUrl); ?>"><i class="fa-solid fa-arrow-left"></i> Back to Residents</a>
                <?php if (in_array($role, ['admin', 'staff'], true)): ?>
                    <a class="resident-action resident-action-edit" href="<?php echo h($editUrl); ?>"><i class="fa-solid fa-pen"></i> Edit Record</a>
                <?php endif; ?>
                <button class="resident-action resident-action-print" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print A4 / Bond Paper</button>
            </div>

            <article class="resident-sheet">
                <header class="resident-sheet-header">
                    <div class="resident-sheet-mark" aria-hidden="true"><i class="fa-solid fa-building-columns"></i></div>
                    <div>
                        <p class="resident-sheet-kicker">BARANGAY MANAGEMENT SYSTEM</p>
                        <h1>Resident Information Record</h1>
                        <p class="resident-sheet-subtitle">Official resident profile</p>
                    </div>
                    <div class="resident-sheet-number">
                        <span>RESIDENT NO.</span>
                        <strong><?php echo h(residentDetailValue($resident, 'resident_number')); ?></strong>
                    </div>
                </header>

                <section class="resident-identity" aria-label="Resident identity">
                    <?php if (!empty($resident['photo_path'])): ?>
                        <img class="resident-portrait" src="<?php echo h($resident['photo_path']); ?>" alt="Photo of <?php echo h($residentName); ?>">
                    <?php else: ?>
                        <div class="resident-portrait resident-portrait-empty" aria-hidden="true"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div class="resident-identity-copy">
                        <span class="resident-identity-label">REGISTERED RESIDENT</span>
                        <h2><?php echo h($residentName !== '' ? $residentName : 'Name not recorded'); ?></h2>
                    </div>
                    <div class="resident-status-stamp"><?php echo h(residentDetailValue($resident, 'resident_status')); ?></div>
                </section>

                <?php foreach ($sections as $sectionTitle => $fields): ?>
                    <section class="resident-detail-section">
                        <h2><?php echo h($sectionTitle); ?></h2>
                        <dl class="resident-detail-grid">
                            <?php foreach ($fields as $field => $label): ?>
                                <?php
                                if ($field === 'live_household_members' && trim((string)($resident['household_id'] ?? '')) === '') {
                                    continue;
                                }
                                $value = residentDetailValue($resident, $field);
                                ?>
                                <div class="resident-detail-item<?php echo in_array($field, ['medical_condition', 'move_reason', 'categories'], true) ? ' resident-detail-item-wide' : ''; ?>">
                                    <dt><?php echo h($label); ?></dt>
                                    <dd><?php echo nl2br(h($value)); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endforeach; ?>

                <footer class="resident-sheet-footer">
                    <span>CONFIDENTIAL · For authorized barangay use</span>
                    <span>Printed <?php echo h(date('F j, Y')); ?></span>
                </footer>
            </article>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
