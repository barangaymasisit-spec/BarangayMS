<?php
require_once __DIR__ . '/layout.php';

$type = (string)($_GET['type'] ?? '');
$recordId = max(0, (int)($_GET['id'] ?? 0));
$role = $_SESSION['role'] ?? '';
$allowedTypes = [
    'personnel' => ['admin'],
    'household' => ['admin'],
    'appointment' => ['admin', 'staff'],
    'certificate' => ['admin', 'staff'],
    'complaint' => ['admin', 'staff'],
    'emergency' => ['admin', 'security_force'],
];
if (!isset($allowedTypes[$type]) || !in_array($role, $allowedTypes[$type], true)) {
    header('Location: dashboard.php?denied=1');
    exit;
}

$record = null;
$members = [];
$recordKind = '';
$recordTitle = '';
$personnelRole = '';
$identifierLabel = 'RECORD NO.';
$identifier = '';
$sections = [];
$photoSrc = null;

$queries = [
    'personnel' => [
        'sql' => "SELECT id, first_name, last_name, username, email, role, status, term, term_start, term_end, position, address, contact_number, photo_path, photo_data, photo_mime, created_at, updated_at FROM users WHERE id = ? AND role IN ('admin', 'staff', 'health_worker', 'security_force')",
        'kind' => 'Barangay Personnel',
        'identifier_label' => 'ACCOUNT ID',
    ],
    'household' => [
        'sql' => 'SELECT households.*, ' . householdMembersSql('households.household_number') . ' AS live_members_count FROM households WHERE id = ?',
        'kind' => 'Household Record',
        'identifier_label' => 'HOUSEHOLD NO.',
    ],
    'appointment' => [
        'sql' => 'SELECT a.*, r.resident_number AS linked_resident_number, COALESCE(NULLIF(a.resident_name, ""), CONCAT(r.first_name, " ", r.last_name), "Unknown") AS linked_resident_name FROM appointments a LEFT JOIN residents r ON r.id = a.resident_id WHERE a.id = ?',
        'kind' => 'Appointment Record',
        'identifier_label' => 'APPOINTMENT NO.',
    ],
    'certificate' => [
        'sql' => 'SELECT c.*, r.resident_number AS linked_resident_number, COALESCE(CONCAT(r.first_name, " ", r.last_name), "Unknown") AS linked_resident_name, COALESCE(CONCAT(u.first_name, " ", u.last_name), "Not recorded") AS requested_by_name FROM certificates c LEFT JOIN residents r ON r.id = c.resident_id LEFT JOIN users u ON u.id = c.requested_by_user_id WHERE c.id = ?',
        'kind' => 'Certificate Request',
        'identifier_label' => 'REQUEST NO.',
    ],
    'complaint' => [
        'sql' => 'SELECT c.*, r.resident_number AS linked_resident_number, COALESCE(NULLIF(c.resident_name, ""), CONCAT(r.first_name, " ", r.last_name), "Unknown") AS linked_resident_name FROM complaints c LEFT JOIN residents r ON r.id = c.resident_id WHERE c.id = ?',
        'kind' => 'Complaint Record',
        'identifier_label' => 'TRACKING NO.',
    ],
    'emergency' => [
        'sql' => 'SELECT e.*, r.resident_number AS linked_resident_number, COALESCE(CONCAT(r.first_name, " ", r.last_name), "Not linked") AS linked_resident_name FROM emergency_alerts e LEFT JOIN residents r ON r.id = e.resident_id WHERE e.id = ?',
        'kind' => 'Emergency Report',
        'identifier_label' => 'TRACKING NO.',
    ],
];

if ($recordId > 0) {
    $config = $queries[$type];
    $stmt = $conn->prepare($config['sql']);
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($record) {
    $recordKind = $queries[$type]['kind'];
    $identifierLabel = $queries[$type]['identifier_label'];
    $identifier = (string)($record['tracking_number'] ?? $record['household_number'] ?? $record['resident_number'] ?? $record['id'] ?? '');

    if ($type === 'personnel') {
        $personnelRole = (string)$record['role'];
        $recordTitle = trim($record['first_name'] . ' ' . $record['last_name']);
        if (!empty($record['photo_data']) && !empty($record['photo_mime'])) {
            $photoSrc = 'data:' . $record['photo_mime'] . ';base64,' . base64_encode($record['photo_data']);
        } elseif (!empty($record['photo_path']) && is_file(__DIR__ . '/' . $record['photo_path'])) {
            $photoSrc = $record['photo_path'];
        }
        $sections = [
            'Account Information' => [
                'username' => 'Username',
                'email' => 'Email Address',
                'role' => 'Personnel Type',
                'position' => 'Position',
                'status' => 'Account Status',
            ],
            'Contact & Address' => [
                'contact_number' => 'Contact Number',
                'address' => 'Address',
            ],
            'Term Information' => [
                'term' => 'Term',
                'term_start' => 'Term Start',
                'term_end' => 'Term End',
            ],
            'Record Information' => [
                'created_at' => 'Account Created',
                'updated_at' => 'Last Updated',
            ],
        ];
        $roleNames = ['admin' => 'Administrator', 'staff' => 'Barangay Official', 'health_worker' => 'Barangay Health Worker', 'security_force' => 'Barangay Security Force'];
        $record['role'] = $roleNames[$record['role']] ?? $record['role'];
        $identifierLabel = 'ACCOUNT ID';
    } elseif ($type === 'household') {
        $recordTitle = (string)($record['household_head'] ?: $record['household_number']);
        $identifier = (string)$record['household_number'];
        $sections = [
            'Household Details' => [
                'household_number' => 'Household Number',
                'household_head' => 'Household Head',
                'members_count' => 'Recorded Member Count',
                'live_members_count' => 'Current Member Count',
                'zone' => 'Zone / Purok',
                'status' => 'Status',
            ],
            'Household Classification' => [
                'household_type' => 'Household Type',
                'income_bracket' => 'Income Bracket',
                'address' => 'Address',
            ],
            'Record Information' => [
                'created_at' => 'Record Created',
                'updated_at' => 'Last Updated',
            ],
        ];
        $membersStmt = $conn->prepare("SELECT resident_number, first_name, middle_name, last_name, suffix, resident_status, household_head, relationship_head FROM residents WHERE household_id = ? ORDER BY household_head, last_name, first_name");
        $membersStmt->bind_param('s', $record['household_number']);
        $membersStmt->execute();
        $members = $membersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $membersStmt->close();
    } elseif ($type === 'appointment') {
        $recordTitle = (string)($record['linked_resident_name'] ?: 'Appointment');
        $identifier = str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT);
        $sections = [
            'Appointment Details' => [
                'linked_resident_name' => 'Resident Name',
                'linked_resident_number' => 'Resident Number',
                'purpose' => 'Purpose',
                'appointment_date' => 'Appointment Date',
                'appointment_time' => 'Appointment Time',
                'status' => 'Status',
                'notes' => 'Notes',
            ],
            'Record Information' => [
                'created_at' => 'Request Created',
                'updated_at' => 'Last Updated',
            ],
        ];
    } elseif ($type === 'certificate') {
        $recordTitle = (string)($record['linked_resident_name'] ?? 'Certificate Request');
        $identifier = str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT);
        $sections = [
            'Request Details' => [
                'linked_resident_name' => 'Resident Name',
                'linked_resident_number' => 'Resident Number',
                'requested_by_name' => 'Requested By',
                'requester_relationship' => 'Requester Relationship',
                'certificate_type' => 'Certificate Type',
                'purpose' => 'Purpose',
                'status' => 'Request Status',
            ],
            'Processing Information' => [
                'request_date' => 'Request Date',
                'approved_date' => 'Approved / Released Date',
                'remarks' => 'Remarks',
                'created_at' => 'Record Created',
                'updated_at' => 'Last Updated',
            ],
        ];
    } elseif ($type === 'complaint') {
        $recordTitle = (string)($record['tracking_number'] ?? 'Complaint');
        $identifier = (string)$record['tracking_number'];
        $sections = [
            'Complaint Details' => [
                'linked_resident_name' => 'Complainant',
                'complainee_name' => 'Complainee',
                'linked_resident_number' => 'Resident Number',
                'category' => 'Category',
                'status' => 'Status',
                'date_filed' => 'Date Filed',
                'description' => 'Description',
                'response' => 'Barangay Response',
            ],
            'Record Information' => [
                'created_at' => 'Record Created',
                'updated_at' => 'Last Updated',
            ],
        ];
    } else {
        $recordTitle = (string)($record['category'] ?? 'Emergency Report');
        $identifier = (string)($record['tracking_number'] ?? $record['id']);
        $sections = [
            'Emergency Details' => [
                'tracking_number' => 'Tracking Number',
                'category' => 'Emergency Type',
                'description' => 'Description',
                'reported_by' => 'Reported By',
                'linked_resident_name' => 'Resident Name',
                'linked_resident_number' => 'Resident Number',
                'status' => 'Status',
            ],
            'Record Information' => [
                'created_at' => 'Report Created',
                'updated_at' => 'Last Updated',
            ],
        ];
    }
}

function recordViewValue(array $record, string $field): string {
    $value = $record[$field] ?? null;
    if ($value === null || trim((string)$value) === '') {
        return 'Not recorded';
    }
    if ($field === 'appointment_time') {
        $timestamp = strtotime((string)$value);
        return $timestamp ? date('g:i A', $timestamp) : (string)$value;
    }
    if (in_array($field, ['created_at', 'updated_at'], true)) {
        return formatDatabaseDateTime((string)$value);
    }
    if (in_array($field, ['term_start', 'term_end', 'appointment_date', 'request_date', 'approved_date', 'date_filed'], true)) {
        $timestamp = strtotime((string)$value);
        return $timestamp ? date('F j, Y', $timestamp) : (string)$value;
    }
    return (string)$value;
}

$returnUrl = match ($type) {
    'personnel' => 'officials.php',
    'household' => 'household.php',
    'appointment' => 'appointments.php',
    'certificate' => 'certificates.php',
    'complaint' => 'complaints.php',
    'emergency' => 'emergency.php',
    default => 'dashboard.php',
};
$editUrl = match ($type) {
    'personnel' => 'officials.php?' . http_build_query($personnelRole === 'admin' ? ['edit_admin' => $recordId] : ['edit' => $recordId]),
    'household' => 'household_form.php?' . http_build_query(['id' => $recordId, 'edit' => 1]),
    'appointment' => 'appointment_form.php?' . http_build_query(['id' => $recordId, 'edit' => 1]),
    'certificate' => '',
    'complaint' => 'complaint_form.php?' . http_build_query(['id' => $recordId, 'edit' => 1]),
    'emergency' => '',
    default => '',
};
$activePage = match ($type) {
    'personnel' => 'officials',
    'household' => 'household',
    'appointment' => 'appointments',
    'certificate' => 'certificates',
    'complaint' => 'complaints',
    'emergency' => 'emergency',
    default => 'dashboard',
};
if (!$record) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($record ? $recordKind : 'Record Not Found'); ?>
    <link rel="stylesheet" href="<?php echo asset('residents.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="record-print-page">
<div class="wrapper">
    <?php renderSidebar($activePage); ?>
    <main class="main-content">
        <?php renderTopbar($recordKind ?: 'Record Details', 'Complete details · A4 printable view', 'panel', ['clock' => true]); ?>
        <?php if (!$record): ?>
            <div class="notice notice-error" role="alert">The requested record could not be found.</div>
            <a class="resident-action resident-action-secondary" href="<?php echo h($returnUrl); ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <?php else: ?>
            <div class="resident-view-actions">
                <a class="resident-action resident-action-secondary" href="<?php echo h($returnUrl); ?>"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
                <?php if ($editUrl !== ''): ?>
                    <a class="resident-action resident-action-edit" href="<?php echo h($editUrl); ?>"><i class="fa-solid fa-pen"></i> Edit Record</a>
                <?php endif; ?>
                <button class="resident-action resident-action-print" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print A4 / Bond Paper</button>
            </div>

            <article class="resident-sheet">
                <header class="resident-sheet-header">
                    <div class="resident-sheet-mark" aria-hidden="true"><i class="fa-solid fa-building-columns"></i></div>
                    <div>
                        <p class="resident-sheet-kicker">BARANGAY MANAGEMENT SYSTEM</p>
                        <h1><?php echo h($recordKind); ?></h1>
                        <p class="resident-sheet-subtitle">Official record details</p>
                    </div>
                    <div class="resident-sheet-number">
                        <span><?php echo h($identifierLabel); ?></span>
                        <strong><?php echo h($identifier); ?></strong>
                    </div>
                </header>

                <section class="resident-identity" aria-label="Record identity">
                    <?php if ($photoSrc !== null): ?>
                        <img class="resident-portrait" src="<?php echo h($photoSrc); ?>" alt="<?php echo h($recordTitle); ?>">
                    <?php else: ?>
                        <div class="resident-portrait resident-portrait-empty" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></div>
                    <?php endif; ?>
                    <div class="resident-identity-copy">
                        <span class="resident-identity-label"><?php echo h(strtoupper($recordKind)); ?></span>
                        <h2><?php echo h($recordTitle); ?></h2>
                    </div>
                    <?php if (!empty($record['status'])): ?>
                        <div class="resident-status-stamp"><?php echo h((string)$record['status']); ?></div>
                    <?php endif; ?>
                </section>

                <?php foreach ($sections as $sectionTitle => $fields): ?>
                    <section class="resident-detail-section">
                        <h2><?php echo h($sectionTitle); ?></h2>
                        <dl class="resident-detail-grid">
                            <?php foreach ($fields as $field => $label): ?>
                                <div class="resident-detail-item<?php echo in_array($field, ['address', 'notes', 'purpose', 'remarks', 'description', 'response'], true) ? ' resident-detail-item-wide' : ''; ?>">
                                    <dt><?php echo h($label); ?></dt>
                                    <dd><?php echo nl2br(h(recordViewValue($record, $field))); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endforeach; ?>

                <?php if ($type === 'household'): ?>
                    <section class="resident-detail-section">
                        <h2>Families and Household Members</h2>
                        <?php if ($members === []): ?>
                            <p class="resident-no-members">No resident members are linked to this household.</p>
                        <?php else: ?>
                            <?php
                            $families = [];
                            foreach ($members as $member) {
                                $familyHead = trim((string)($member['household_head'] ?? '')) ?: 'Family group not specified';
                                $families[$familyHead][] = $member;
                            }
                            ?>
                            <?php foreach ($families as $familyHead => $familyMembers): ?>
                                <?php
                                $familyMembers = array_values(array_filter($familyMembers, static function (array $member) use ($familyHead): bool {
                                    $memberName = trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name'], $member['suffix']])));
                                    return $memberName !== $familyHead;
                                }));
                                ?>
                                <h3><?php echo h($familyHead === 'Family group not specified' ? $familyHead : 'Family of ' . $familyHead); ?> · <?php echo count($familyMembers); ?> members</h3>
                                <?php if ($familyMembers === []): ?>
                                    <p class="resident-no-members">No other residents are assigned to this family.</p>
                                <?php else: ?>
                                    <table class="resident-members-table">
                                        <thead><tr><th>Resident No.</th><th>Name</th><th>Relationship / Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($familyMembers as $member): ?>
                                                <tr>
                                                    <td><?php echo h($member['resident_number']); ?></td>
                                                    <td><?php echo h(trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name'], $member['suffix']])))); ?></td>
                                                    <td><?php echo h(($member['relationship_head'] ?? '') ?: ($member['resident_status'] ?? '')); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

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
