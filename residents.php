<?php
require_once __DIR__ . '/layout.php';

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$isHealthWorker = ($_SESSION['role'] ?? '') === 'health_worker';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true) && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    $delId = (int)$_POST['id'];
    $stmt = $conn->prepare('DELETE FROM residents WHERE id = ?');
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, 'deleted', 'residents', 'Deleted resident record.', $delId);
    header('Location: residents.php?msg=deleted');
    exit;
}

// Message handling
$notice = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $notice = 'Resident record deleted.';
    } elseif ($_GET['msg'] === 'created') {
        $notice = 'Resident record created.';
    } elseif ($_GET['msg'] === 'updated') {
        $notice = 'Resident record updated.';
    }
}

$fields = $isHealthWorker
    ? 'id, resident_number, first_name, middle_name, last_name, resident_status, blood_type_health, medical_condition, categories'
    : 'id, resident_number, first_name, middle_name, last_name, resident_status, mobile_number, email';

$whereSql = '';
$params = [];
$types = '';

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $whereSql .= ' WHERE (first_name LIKE ? OR last_name LIKE ? OR resident_number LIKE ? OR email LIKE ? OR mobile_number LIKE ?)';
    $params = [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike];
    $types = 'sssss';
}

if ($statusFilter !== 'all') {
    $whereSql .= ($whereSql === '' ? ' WHERE ' : ' AND ') . 'resident_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$countStmt = $conn->prepare('SELECT COUNT(*) FROM residents' . $whereSql);
if ($params !== []) {
    bindPreparedParams($countStmt, $types, $params);
}
$countStmt->execute();
$totalResidentsCount = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();
$totalPages = max(1, (int)ceil($totalResidentsCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$residentsStmt = $conn->prepare("SELECT $fields FROM residents $whereSql ORDER BY last_name ASC, first_name ASC, resident_number ASC LIMIT ? OFFSET ?");
if ($params !== []) {
    $bindParams = $params;
    $bindParams[] = $perPage;
    $bindParams[] = $offset;
    $bindTypes = $types . 'ii';
    bindPreparedParams($residentsStmt, $bindTypes, $bindParams);
} else {
    $residentsStmt->bind_param('ii', $perPage, $offset);
}
$residentsStmt->execute();
$residents = $residentsStmt->get_result();
$residentsStmt->close();

$paginationBase = ['page' => $page, 'search' => $search, 'status_filter' => $statusFilter];

// Next resident number for the blank form
$generatedResidentNumber = nextResidentNumber($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <?php pageTitle('Resident Information'); ?>

    <link rel="stylesheet" href="<?php echo asset('residents.css'); ?>">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
          rel="stylesheet">

</head>

<body>

<div class="wrapper">

    <?php renderSidebar('residents', 'panel'); ?>

    <main class="main-content">

        <?php renderTopbar('Resident Information', 'Manage Resident Profile', 'panel', ['clock' => true]); ?>

        <?php if ($notice !== ''): ?>
            <div class="notice" role="status"><?php echo h($notice); ?></div>
        <?php endif; ?>

        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)): ?>
        <form action="resident_form.php" method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>

            <input type="hidden" name="id" value="0">

            <div class="resident-layout">

                <div class="profile-card">

                    <h3>
                        <i class="fa-solid fa-user"></i>
                        Resident Profile
                    </h3>

                    <div class="profile-image">

                        <img src="user.svg"
                             alt="Resident Photo"
                             id="previewImage">

                    </div>

                    <label for="residentPhoto">Resident Photo</label>

                    <input type="file"
                           id="residentPhoto"
                           name="resident_photo"
                           accept="image/*">

                    <div class="profile-details">

                        <label for="residentId">Resident ID</label>

                        <input type="text"
                               id="residentId"
                               value="<?php echo h($generatedResidentNumber); ?>"
                               readonly>

                        <label for="profileResidentStatus">Resident Status</label>

                        <select id="profileResidentStatus"
                                name="profileResidentStatus">

                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Moved Out">Moved Out</option>
                            <option value="Deceased">Deceased</option>

                        </select>

                    </div>

                </div>

                <div class="form-container">

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-id-card"></i>
                            Personal Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="firstName">First Name</label>
                                <input type="text" id="firstName" name="first_name" required>
                            </div>

                            <div>
                                <label for="middleName">Middle Name</label>
                                <input type="text" id="middleName" name="middle_name">
                            </div>

                            <div>
                                <label for="lastName">Last Name</label>
                                <input type="text" id="lastName" name="last_name" required>
                            </div>

                            <div>
                                <label for="suffix">Suffix</label>
                                <input type="text" id="suffix" name="suffix">
                            </div>

                            <div>
                                <label for="sex">Sex</label>
                                <select id="sex" name="sex">
                                    <option>Male</option>
                                    <option>Female</option>
                                </select>
                            </div>

                            <div>
                                <label for="birthDate">Date of Birth</label>
                                <input type="date" id="birthDate" name="birthDate">
                            </div>

                            <div>
                                <label for="age">Age</label>
                                <input type="number" id="age" name="age" readonly>
                            </div>

                            <div>
                                <label for="birthPlace">Place of Birth</label>
                                <input type="text" id="birthPlace" name="birthPlace">
                            </div>

                            <div>
                                <label for="civilStatus">Civil Status</label>
                                <select id="civilStatus" name="civilStatus">
                                    <option>Single</option>
                                    <option>Married</option>
                                    <option>Widowed</option>
                                    <option>Separated</option>
                                </select>
                            </div>

                            <div>
                                <label for="nationality">Nationality</label>
                                <input type="text" id="nationality" name="nationality">
                            </div>

                            <div>
                                <label for="religion">Religion</label>
                                <input type="text" id="religion" name="religion">
                            </div>

                            <div>
                                <label for="bloodType">Blood Type</label>
                                <select id="bloodType" name="bloodType">
                                    <option>A+</option>
                                    <option>A-</option>
                                    <option>B+</option>
                                    <option>B-</option>
                                    <option>AB+</option>
                                    <option>AB-</option>
                                    <option>O+</option>
                                    <option>O-</option>
                                </select>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-phone"></i>
                            Contact Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="mobileNumber">Mobile Number</label>
                                <input type="tel" id="mobileNumber" name="mobileNumber">
                            </div>

                            <div>
                                <label for="telephoneNumber">Telephone Number</label>
                                <input type="tel" id="telephoneNumber" name="telephoneNumber">
                            </div>

                            <div>
                                <label for="emailAddress">Email Address</label>
                                <input type="email" id="emailAddress" name="emailAddress">
                            </div>

                            <div>
                                <label for="emergencyContact">Emergency Contact</label>
                                <input type="text" id="emergencyContact" name="emergencyContact">
                            </div>

                            <div>
                                <label for="emergencyNumber">Emergency Number</label>
                                <input type="tel" id="emergencyNumber" name="emergencyNumber">
                            </div>

                            <div>
                                <label for="relationship">Relationship</label>
                                <input type="text" id="relationship" name="relationship">
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-location-dot"></i>
                            Address Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="houseNumber">House Number</label>
                                <input type="text" id="houseNumber" name="houseNumber">
                            </div>

                            <div>
                                <label for="street">Street</label>
                                <input type="text" id="street" name="street">
                            </div>

                            <div>
                                <label for="purok">Purok / Sitio</label>
                                <input type="text" id="purok" name="purok">
                            </div>

                            <div>
                                <label for="barangay">Barangay</label>
                                <input type="text" id="barangay" name="barangay">
                            </div>

                            <div>
                                <label for="municipality">Municipality / City</label>
                                <input type="text" id="municipality" name="municipality">
                            </div>

                            <div>
                                <label for="province">Province</label>
                                <input type="text" id="province" name="province">
                            </div>

                            <div>
                                <label for="zipCode">ZIP Code</label>
                                <input type="text" id="zipCode" name="zipCode">
                            </div>

                            <div>
                                <label for="lengthResidency">Length of Residency</label>
                                <input type="text" id="lengthResidency" name="lengthResidency">
                            </div>

                            <div>
                                <label for="residencyType">Residency Type</label>
                                <select id="residencyType" name="residencyType">
                                    <option value="">Select Residency Type</option>
                                    <option value="Permanent">Permanent</option>
                                    <option value="Temporary">Temporary</option>
                                </select>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-house-user"></i>
                            Household Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="householdId">Household ID</label>
                                <input type="text" id="householdId" name="householdId">
                            </div>

                            <div>
                                <label for="householdHead">Household Head</label>
                                <input type="text" id="householdHead" name="householdHead">
                            </div>

                            <div>
                                <label for="relationshipHead">Relationship to Household Head</label>
                                <input type="text" id="relationshipHead" name="relationshipHead">
                            </div>

                            <div>
                                <label for="householdMembers">Household Members</label>
                                <input type="number" id="householdMembers" name="householdMembers" min="1">
                            </div>

                            <div>
                                <label for="householdType">Household Type</label>
                                <select id="householdType" name="householdType">
                                    <option value="">Select Household Type</option>
                                    <option value="Owned">Owned</option>
                                    <option value="Rented">Rented</option>
                                    <option value="Shared">Shared</option>
                                </select>
                            </div>

                            <div>
                                <label for="incomeBracket">Income Bracket</label>
                                <select id="incomeBracket" name="incomeBracket">
                                    <option value="">Select Income Bracket</option>
                                    <option>Below &#8369;10,000</option>
                                    <option>&#8369;10,000 - &#8369;20,000</option>
                                    <option>&#8369;20,001 - &#8369;40,000</option>
                                    <option>&#8369;40,001 - &#8369;60,000</option>
                                    <option>Above &#8369;60,000</option>
                                </select>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-address-card"></i>
                            Government Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="voterStatus">Voter Status</label>
                                <select id="voterStatus" name="voterStatus">
                                    <option value="">Select Voter Status</option>
                                    <option value="Registered">Registered</option>
                                    <option value="Not Registered">Not Registered</option>
                                </select>
                            </div>

                            <div>
                                <label for="precinctNumber">Precinct Number</label>
                                <input type="text" id="precinctNumber" name="precinctNumber">
                            </div>

                            <div>
                                <label for="philhealth">PhilHealth Number</label>
                                <input type="text" id="philhealth" name="philhealth">
                            </div>

                            <div>
                                <label for="sss">SSS Number</label>
                                <input type="text" id="sss" name="sss">
                            </div>

                            <div>
                                <label for="gsis">GSIS Number</label>
                                <input type="text" id="gsis" name="gsis">
                            </div>

                            <div>
                                <label for="tin">TIN</label>
                                <input type="text" id="tin" name="tin">
                            </div>

                            <div>
                                <label for="nationalId">National ID Number</label>
                                <input type="text" id="nationalId" name="nationalId">
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-briefcase"></i>
                            Employment Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="employmentStatus">Employment Status</label>
                                <select id="employmentStatus" name="employmentStatus">
                                    <option value="">Select Employment Status</option>
                                    <option>Employed</option>
                                    <option>Self-employed</option>
                                    <option>Unemployed</option>
                                    <option>Student</option>
                                    <option>Retired</option>
                                </select>
                            </div>

                            <div>
                                <label for="occupation">Occupation</label>
                                <input type="text" id="occupation" name="occupation">
                            </div>

                            <div>
                                <label for="company">Employer / Company</label>
                                <input type="text" id="company" name="company">
                            </div>

                            <div>
                                <label for="workplace">Workplace Address</label>
                                <input type="text" id="workplace" name="workplace">
                            </div>

                            <div>
                                <label for="monthlyIncome">Monthly Income</label>
                                <input type="text" id="monthlyIncome" name="monthlyIncome">
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-graduation-cap"></i>
                            Educational Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="education">Highest Educational Attainment</label>
                                <select id="education" name="education">
                                    <option value="">Select Educational Attainment</option>
                                    <option>Elementary</option>
                                    <option>High School</option>
                                    <option>Senior High School</option>
                                    <option>Vocational</option>
                                    <option>College</option>
                                    <option>Master's Degree</option>
                                    <option>Doctorate Degree</option>
                                </select>
                            </div>

                            <div>
                                <label for="schoolName">School Name</label>
                                <input type="text" id="schoolName" name="schoolName">
                            </div>

                            <div>
                                <label for="studentStatus">Student Status</label>
                                <select id="studentStatus" name="studentStatus">
                                    <option value="">Select Student Status</option>
                                    <option>Yes</option>
                                    <option>No</option>
                                </select>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-heart-pulse"></i>
                            Health Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="pwdStatus">PWD Status</label>
                                <select id="pwdStatus" name="pwdStatus">
                                    <option value="">Select</option>
                                    <option>Yes</option>
                                    <option>No</option>
                                </select>
                            </div>

                            <div>
                                <label for="disabilityType">Disability Type</label>
                                <input type="text" id="disabilityType" name="disabilityType">
                            </div>

                            <div>
                                <label for="seniorCitizen">Senior Citizen</label>
                                <select id="seniorCitizen" name="seniorCitizen">
                                    <option value="">Select</option>
                                    <option>Yes</option>
                                    <option>No</option>
                                </select>
                            </div>

                            <div>
                                <label for="bloodTypeHealth">Blood Type</label>
                                <select id="bloodTypeHealth" name="bloodTypeHealth">
                                    <option value="">Select Blood Type</option>
                                    <option>A+</option>
                                    <option>A-</option>
                                    <option>B+</option>
                                    <option>B-</option>
                                    <option>AB+</option>
                                    <option>AB-</option>
                                    <option>O+</option>
                                    <option>O-</option>
                                </select>
                            </div>

                            <div class="full-width">
                                <label for="medicalCondition">Medical Condition / Maintenance Medication</label>
                                <textarea id="medicalCondition" name="medicalCondition" rows="4"></textarea>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-list-check"></i>
                            Special Categories
                        </h3>

                        <div class="checkbox-grid">

                            <label><input type="checkbox" name="categories[]" value="Senior Citizen"> Senior Citizen</label>

                            <label><input type="checkbox" name="categories[]" value="Solo Parent"> Solo Parent</label>

                            <label><input type="checkbox" name="categories[]" value="PWD"> PWD</label>

                            <label><input type="checkbox" name="categories[]" value="Indigenous Person"> Indigenous Person</label>

                            <label><input type="checkbox" name="categories[]" value="Pregnant Woman"> Pregnant Woman</label>

                            <label><input type="checkbox" name="categories[]" value="Lactating Mother"> Lactating Mother</label>

                            <label><input type="checkbox" name="categories[]" value="OFW Family"> OFW Family</label>

                            <label><input type="checkbox" name="categories[]" value="4Ps Beneficiary"> 4Ps Beneficiary</label>

                            <label><input type="checkbox" name="categories[]" value="Registered Voter"> Registered Voter</label>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-location-crosshairs"></i>
                            Residency Status
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="residentStatus">Resident Status</label>
                                <select id="residentStatus" name="residentStatus">
                                    <option>Active</option>
                                    <option>Inactive</option>
                                    <option>Moved Out</option>
                                    <option>Deceased</option>
                                </select>
                            </div>

                            <div>
                                <label for="dateRegistered">Date Registered</label>
                                <input type="date" id="dateRegistered" name="dateRegistered">
                            </div>

                            <div>
                                <label for="dateMovedIn">Date Moved In</label>
                                <input type="date" id="dateMovedIn" name="dateMovedIn">
                            </div>

                            <div>
                                <label for="dateMovedOut">Date Moved Out</label>
                                <input type="date" id="dateMovedOut" name="dateMovedOut">
                            </div>

                            <div class="full-width">
                                <label for="moveReason">Reason for Moving Out</label>
                                <textarea id="moveReason" name="moveReason" rows="3"></textarea>
                            </div>

                        </div>

                    </section>

                    <section class="form-card">

                        <h3>
                            <i class="fa-solid fa-server"></i>
                            System Information
                        </h3>

                        <div class="form-grid">

                            <div>
                                <label for="systemResidentId">Resident Number</label>
                                <input type="text"
                                       id="systemResidentId"
                                       name="systemResidentId"
                                       value="<?php echo h($generatedResidentNumber); ?>"
                                       readonly>
                            </div>

                            <div>
                                <label for="accountStatus">Account Status</label>
                                <input type="text"
                                       id="accountStatus"
                                       name="accountStatus"
                                       value="Active"
                                       readonly>
                            </div>

                            <div>
                                <label for="createdDate">Date Created</label>
                                <input type="text" id="createdDate" name="createdDate" readonly>
                            </div>

                            <div>
                                <label for="lastUpdated">Last Updated</label>
                                <input type="text" id="lastUpdated" name="lastUpdated" readonly>
                            </div>

                        </div>

                    </section>

                    <div class="button-group">

                        <button type="submit" class="btn save-btn">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save
                        </button>

                        <button type="reset" class="btn reset-btn">
                            <i class="fa-solid fa-rotate-left"></i>
                            Reset
                        </button>

                        <a href="residents.php" class="btn cancel-btn">
                            <i class="fa-solid fa-xmark"></i>
                            Cancel
                        </a>

                    </div>

                </div>

            </div>

        </form>
        <?php endif; ?>

        <section class="records-card">

            <div class="records-header">

                <div>
                    <h2>Resident Records</h2>
                    <p>View and manage all registered residents.</p>
                </div>

                <form class="records-search" method="get" action="residents.php">

                    <label class="sr-only" for="searchResident">Search Resident</label>

                    <input id="searchResident"
                           type="search"
                           name="search"
                           value="<?php echo h($search); ?>"
                           placeholder="Search by name or resident number">

                    <select name="status_filter" aria-label="Filter by resident status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $statusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="Moved Out" <?php echo $statusFilter === 'Moved Out' ? 'selected' : ''; ?>>Moved Out</option>
                        <option value="Deceased" <?php echo $statusFilter === 'Deceased' ? 'selected' : ''; ?>>Deceased</option>
                    </select>

                    <button type="submit" class="btn save-btn">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        Search
                    </button>

                </form>

            </div>

            <div class="records-table">

                <table>

                    <thead>
                        <tr>
                            <th scope="col">Resident ID</th>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <?php if ($isHealthWorker): ?>
                                <th scope="col">Blood Type</th>
                                <th scope="col">Medical Condition</th>
                                <th scope="col">Health Categories</th>
                            <?php else: ?>
                                <th scope="col">Mobile</th>
                                <th scope="col">Email</th>
                            <?php endif; ?>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if ($residents->num_rows === 0): ?>
                            <tr>
                                <td colspan="<?php echo $isHealthWorker ? '7' : '6'; ?>">No resident records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php while ($resident = $residents->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo h($resident['resident_number']); ?></td>
                                    <td><?php echo h(trim($resident['first_name'] . ' ' . $resident['middle_name'] . ' ' . $resident['last_name'])); ?></td>
                                    <td>
                                        <span class="status <?php echo strtolower(str_replace(' ', '-', (string)$resident['resident_status'])); ?>">
                                            <?php echo h($resident['resident_status']); ?>
                                        </span>
                                    </td>
                                    <?php if ($isHealthWorker): ?>
                                        <td><?php echo h($resident['blood_type_health']); ?></td>
                                        <td><?php echo h($resident['medical_condition']); ?></td>
                                        <td><?php echo h($resident['categories']); ?></td>
                                    <?php else: ?>
                                        <td><?php echo h($resident['mobile_number']); ?></td>
                                        <td><?php echo h($resident['email']); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="action-group">
                                            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)): ?>
                                                <a href="resident_form.php?id=<?php echo (int)$resident['id']; ?>" class="btn-view" title="View Resident" aria-label="View resident record"><i class="fa-solid fa-eye" aria-hidden="true"></i></a>
                                                <a href="resident_form.php?id=<?php echo (int)$resident['id']; ?>&amp;edit=1" class="btn-edit" title="Edit Resident" aria-label="Edit resident record"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                                                <form action="residents.php" method="post" class="d-inline" onsubmit="return confirm('Delete this resident?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$resident['id']; ?>"><button type="submit" class="btn-delete" title="Delete Resident" aria-label="Delete resident record"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>
                                            <?php else: ?>
                                                <span class="text-muted">Read only</span>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($totalResidentsCount > $perPage): ?>
                <div class="pagination-wrap" style="margin-top: 16px; display:flex; justify-content: space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div class="text-muted">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></div>
                    <div class="btn-group">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="residents.php?search=<?php echo urlencode($search); ?>&status_filter=<?php echo urlencode($statusFilter); ?>&page=<?php echo (int)($page - 1); ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="residents.php?search=<?php echo urlencode($search); ?>&status_filter=<?php echo urlencode($statusFilter); ?>&page=<?php echo (int)($page + 1); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<?php renderFooterScripts(false); ?>

</body>

</html>
