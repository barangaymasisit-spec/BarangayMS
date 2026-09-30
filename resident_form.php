<?php
require_once __DIR__ . '/layout.php';
$resident = null;
$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $stmt = $conn->prepare('SELECT residents.*, ' . householdMembersSql('residents.household_id') . ' AS live_household_members FROM residents WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $resident = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$householdMembers = $resident && trim((string)$resident['household_id']) !== '' ? $resident['live_household_members'] : '';

// Prepare generated resident number for new records
$generatedResidentNumber = '';
if (!$resident) {
    $generatedResidentNumber = nextResidentNumber($conn);
} else {
    $generatedResidentNumber = $resident['resident_number'] ?? '';
}

// Handle form submissions (create or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    // Map form fields to DB columns
    $data = [];
    $data['resident_number'] = trim($_POST['systemResidentId'] ?? $_POST['resident_number'] ?? uniqid('RES-'));
    $data['first_name'] = trim($_POST['first_name'] ?? '');
    $data['middle_name'] = trim($_POST['middle_name'] ?? '');
    $data['last_name'] = trim($_POST['last_name'] ?? '');
    $data['suffix'] = trim($_POST['suffix'] ?? '');
    $data['sex'] = trim($_POST['sex'] ?? '');
    $data['birth_date'] = trim($_POST['birthDate'] ?? $_POST['birthdate'] ?? '');
    $data['age'] = is_numeric($_POST['age'] ?? null) ? (int)$_POST['age'] : null;
    $data['birth_place'] = trim($_POST['birthPlace'] ?? '');
    $data['civil_status'] = trim($_POST['civilStatus'] ?? '');
    $data['nationality'] = trim($_POST['nationality'] ?? '');
    $data['religion'] = trim($_POST['religion'] ?? '');
    $data['blood_type'] = trim($_POST['bloodType'] ?? '');
    $data['mobile_number'] = trim($_POST['mobileNumber'] ?? $_POST['mobile_number'] ?? '');
    $data['telephone_number'] = trim($_POST['telephoneNumber'] ?? '');
    $data['email'] = trim($_POST['emailAddress'] ?? $_POST['email'] ?? '');
    $data['emergency_contact'] = trim($_POST['emergencyContact'] ?? '');
    $data['emergency_number'] = trim($_POST['emergencyNumber'] ?? '');
    $data['relationship'] = trim($_POST['relationship'] ?? '');
    $data['house_number'] = trim($_POST['houseNumber'] ?? $_POST['house_number'] ?? '');
    $data['street'] = trim($_POST['street'] ?? '');
    $data['purok'] = trim($_POST['purok'] ?? '');
    $data['barangay'] = trim($_POST['barangay'] ?? '');
    $data['municipality'] = trim($_POST['municipality'] ?? '');
    $data['province'] = trim($_POST['province'] ?? '');
    $data['zip_code'] = trim($_POST['zipCode'] ?? $_POST['zip_code'] ?? '');
    $data['length_residency'] = trim($_POST['lengthResidency'] ?? '');
    $data['residency_type'] = trim($_POST['residencyType'] ?? '');
    $data['household_id'] = trim($_POST['householdId'] ?? '');
    $data['household_head'] = trim($_POST['householdHead'] ?? '');
    $data['relationship_head'] = trim($_POST['relationshipHead'] ?? '');
    $data['household_members'] = is_numeric($_POST['householdMembers'] ?? null) ? (int)$_POST['householdMembers'] : null;
    $data['household_type'] = trim($_POST['householdType'] ?? '');
    $data['income_bracket'] = trim($_POST['incomeBracket'] ?? '');
    $data['voter_status'] = trim($_POST['voterStatus'] ?? '');
    $data['precinct_number'] = trim($_POST['precinctNumber'] ?? '');
    $data['philhealth_number'] = trim($_POST['philhealth'] ?? '');
    $data['sss_number'] = trim($_POST['sss'] ?? '');
    $data['gsis_number'] = trim($_POST['gsis'] ?? '');
    $data['tin'] = trim($_POST['tin'] ?? '');
    $data['national_id'] = trim($_POST['nationalId'] ?? '');
    $data['employment_status'] = trim($_POST['employmentStatus'] ?? '');
    $data['occupation'] = trim($_POST['occupation'] ?? '');
    $data['company'] = trim($_POST['company'] ?? '');
    $data['workplace'] = trim($_POST['workplace'] ?? '');
    $data['monthly_income'] = trim($_POST['monthlyIncome'] ?? '');
    $data['education'] = trim($_POST['education'] ?? '');
    $data['school_name'] = trim($_POST['schoolName'] ?? '');
    $data['student_status'] = trim($_POST['studentStatus'] ?? '');
    $data['pwd_status'] = trim($_POST['pwdStatus'] ?? '');
    $data['disability_type'] = trim($_POST['disabilityType'] ?? '');
    $data['senior_citizen'] = trim($_POST['seniorCitizen'] ?? '');
    $data['blood_type_health'] = trim($_POST['bloodTypeHealth'] ?? '');
    $data['medical_condition'] = trim($_POST['medicalCondition'] ?? '');
    // categories checkbox array -> comma separated
    if (isset($_POST['categories']) && is_array($_POST['categories'])) {
        $data['categories'] = implode(',', array_map('trim', $_POST['categories']));
    } else {
        $data['categories'] = trim($_POST['categories_txt'] ?? '');
    }
    $data['resident_status'] = trim($_POST['residentStatus'] ?? $_POST['resident_status'] ?? '');
    $data['date_registered'] = trim($_POST['dateRegistered'] ?? '');
    $data['date_moved_in'] = trim($_POST['dateMovedIn'] ?? '');
    $data['date_moved_out'] = trim($_POST['dateMovedOut'] ?? '');
    $data['move_reason'] = trim($_POST['moveReason'] ?? '');
    $data['account_status'] = trim($_POST['accountStatus'] ?? '');

    if ($postId === 0) {
        $numberCheck = $conn->prepare('SELECT id FROM residents WHERE resident_number = ? LIMIT 1');
        $numberCheck->bind_param('s', $data['resident_number']);
        $numberCheck->execute();
        $numberTaken = (bool)$numberCheck->get_result()->fetch_assoc();
        $numberCheck->close();
        if ($numberTaken) {
            $data['resident_number'] = nextResidentNumber($conn);
        }
    }

    $data['birth_date'] = $data['birth_date'] !== '' ? $data['birth_date'] : null;
    $data['date_registered'] = $data['date_registered'] !== '' ? $data['date_registered'] : date('Y-m-d');
    $data['date_moved_in'] = $data['date_moved_in'] !== '' ? $data['date_moved_in'] : null;
    $data['date_moved_out'] = $data['date_moved_out'] !== '' ? $data['date_moved_out'] : null;

    // Handle photo upload
    $uploadPath = null;
    $photoFile = $_FILES['resident_photo'] ?? null;
    $allowedPhotoTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mimeType = '';
    if ($photoFile && $photoFile['error'] !== UPLOAD_ERR_NO_FILE) {
        $mimeType = $photoFile['error'] === UPLOAD_ERR_OK
            ? mime_content_type($photoFile['tmp_name'])
            : '';
        if ($photoFile['error'] !== UPLOAD_ERR_OK || $photoFile['size'] > 5 * 1024 * 1024 || !isset($allowedPhotoTypes[$mimeType])) {
            $error = 'Resident photos must be JPG, PNG, or WEBP images no larger than 5 MB.';
        }
    }
    if ($error === '' && $photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
        $tmp = $photoFile['tmp_name'];
        $fname = 'resident_' . bin2hex(random_bytes(16)) . '.' . $allowedPhotoTypes[$mimeType];
        $dest = $uploadsDir . '/' . $fname;
        if (move_uploaded_file($tmp, $dest)) {
            $uploadPath = 'uploads/' . $fname;
            $data['photo_path'] = $uploadPath;
        } else {
            $error = 'The resident photo could not be saved.';
        }
    }

    if ($error === '' && $postId > 0) {
        // The edit page has no health blood type field; keep the saved value instead of blanking it.
        if (!isset($_POST['bloodTypeHealth'])) {
            unset($data['blood_type_health']);
        }
        // Build update statement
        $cols = [];
        $types = '';
        $values = [];
        foreach ($data as $col => $val) {
            $cols[] = "$col = ?";
            $types .= 's';
            $values[] = $val;
        }
        $types .= 'i';
        $values[] = $postId;
        $sql = 'UPDATE residents SET ' . implode(', ', $cols) . ', last_updated = NOW() WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $params = array_merge([$types], $values);
        $refs = [];
        foreach ($params as $k => $v) $refs[$k] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $stmt->close();
        logActivity($conn, 'updated', 'residents', 'Updated resident profile.', (int)$postId);
        header('Location: residents.php?msg=updated');
        exit;
    } else {
        // Insert new resident
        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');
        $types = str_repeat('s', count($cols));
        $values = array_values($data);

        // Ensure date_registered is included once
        if (!in_array('date_registered', $cols)) {
            $cols[] = 'date_registered';
            $placeholders[] = '?';
            $types .= 's';
            $values[] = $data['date_registered'] ?: date('Y-m-d');
        }

        $sql = 'INSERT INTO residents (' . implode(', ', $cols) . ', created_date) VALUES (' . implode(', ', $placeholders) . ', NOW())';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Prepare failed: ' . $conn->error);
            echo '<pre>Prepare failed: ' . htmlspecialchars($conn->error) . '\nSQL: ' . htmlspecialchars($sql) . '</pre>';
            exit;
        }

        $params = array_merge([$types], $values);
        $refs = [];
        foreach ($params as $k => $v) $refs[$k] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) {
            error_log('Execute failed: ' . $stmt->error);
            echo '<pre>Execute failed: ' . htmlspecialchars($stmt->error) . '</pre>';
            exit;
        }
        $newResidentId = $conn->insert_id;
        $stmt->close();
        logActivity($conn, 'created', 'residents', 'Created resident profile.', (int)$newResidentId);
        header('Location: residents.php?msg=created');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle($resident ? 'Resident Details' : 'New Resident'); ?>
    <link rel="stylesheet" href="<?php echo asset('residents.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('residents'); ?>
    <main class="main-content">
        <?php renderTopbar($resident ? 'Resident Details' : 'Add Resident', $resident ? 'View resident record' : 'Create a new resident', 'panel', ['clock' => true]); ?>
        <div class="content-card">
            <h3><?php echo $resident ? 'Resident Record' : 'New Resident'; ?></h3>
            <form method="post" action="resident_form.php<?php echo $resident ? '?id=' . (int)$resident['id'] . '&edit=1' : ''; ?>" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" value="<?php echo $resident ? (int)$resident['id'] : 0; ?>">

                <div class="resident-layout">

                    <div class="profile-card">
                        <h3><i class="fa-solid fa-user"></i> Resident Profile</h3>
                        <div class="profile-image">
                            <img src="<?php echo $resident && !empty($resident['photo_path']) ? h($resident['photo_path']) : 'user.svg'; ?>" alt="Resident Photo" id="previewImage">
                        </div>
                        <label for="residentPhoto">Resident Photo</label>
                        <input type="file" id="residentPhoto" name="resident_photo" accept="image/*">
                        <div class="profile-details">
                            <label for="resident_number">Resident ID</label>
                            <input type="text" id="resident_number" name="resident_number" value="<?php echo h($resident ? $resident['resident_number'] : $generatedResidentNumber); ?>" readonly>

                            <label for="resident_status">Resident Status</label>
                            <select id="resident_status" name="resident_status">
                                <option value="Active" <?php echo $resident && $resident['resident_status']==='Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $resident && $resident['resident_status']==='Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Moved Out" <?php echo $resident && $resident['resident_status']==='Moved Out' ? 'selected' : ''; ?>>Moved Out</option>
                                <option value="Deceased" <?php echo $resident && $resident['resident_status']==='Deceased' ? 'selected' : ''; ?>>Deceased</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-container">
                        <section class="form-card">
                            <h3><i class="fa-solid fa-id-card"></i> Personal Information</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo $resident ? h($resident['first_name']) : ''; ?>" required>
                                </div>
                                <div>
                                    <label for="middle_name">Middle Name</label>
                                    <input type="text" id="middle_name" name="middle_name" value="<?php echo $resident ? h($resident['middle_name']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo $resident ? h($resident['last_name']) : ''; ?>" required>
                                </div>
                                <div>
                                    <label for="suffix">Suffix</label>
                                    <input type="text" id="suffix" name="suffix" value="<?php echo $resident ? h($resident['suffix'] ?? '') : ''; ?>">
                                </div>
                                <div>
                                    <label for="sex">Sex</label>
                                    <select id="sex" name="sex">
                                        <option value="Male" <?php echo $resident && $resident['sex']==='Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $resident && $resident['sex']==='Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="birthdate">Birthdate</label>
                                    <input type="date" id="birthdate" name="birthdate" value="<?php echo $resident ? h($resident['birthdate'] ?? $resident['birth_date'] ?? '') : ''; ?>">
                                </div>
                                <div>
                                    <label for="age">Age</label>
                                    <input type="number" id="age" name="age" value="<?php echo $resident ? h($resident['age']) : ''; ?>" readonly>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-house"></i> Address</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="house_number">House Number</label>
                                    <input type="text" id="house_number" name="house_number" value="<?php echo $resident ? h($resident['house_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="street">Street</label>
                                    <input type="text" id="street" name="street" value="<?php echo $resident ? h($resident['street']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="barangay">Barangay</label>
                                    <input type="text" id="barangay" name="barangay" value="<?php echo $resident ? h($resident['barangay']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="municipality">Municipality</label>
                                    <input type="text" id="municipality" name="municipality" value="<?php echo $resident ? h($resident['municipality']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="purok">Purok / Sitio</label>
                                    <input type="text" id="purok" name="purok" value="<?php echo $resident ? h($resident['purok']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="province">Province</label>
                                    <input type="text" id="province" name="province" value="<?php echo $resident ? h($resident['province']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="zipCode">ZIP Code</label>
                                    <input type="text" id="zipCode" name="zipCode" value="<?php echo $resident ? h($resident['zip_code']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="dateMovedIn">Date Moved In</label>
                                    <input type="date" id="dateMovedIn" name="dateMovedIn" value="<?php echo $resident ? h($resident['date_moved_in']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="lengthResidency">Length of Residency</label>
                                    <input type="text" id="lengthResidency" name="lengthResidency" value="<?php echo $resident ? h($resident['length_residency']) : ''; ?>" readonly>
                                </div>
                                <div>
                                    <label for="residencyType">Residency Type</label>
                                    <select id="residencyType" name="residencyType">
                                        <option value="">Select</option>
                                        <option value="Permanent" <?php echo $resident && $resident['residency_type']==='Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                        <option value="Temporary" <?php echo $resident && $resident['residency_type']==='Temporary' ? 'selected' : ''; ?>>Temporary</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-heartbeat"></i> Health Information</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="birth_place">Place of Birth</label>
                                    <input type="text" id="birth_place" name="birthPlace" value="<?php echo $resident ? h($resident['birth_place']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="civil_status">Civil Status</label>
                                    <select id="civil_status" name="civilStatus">
                                        <option value="">Select</option>
                                        <option value="Single" <?php echo $resident && $resident['civil_status']==='Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo $resident && $resident['civil_status']==='Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo $resident && $resident['civil_status']==='Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Divorced" <?php echo $resident && $resident['civil_status']==='Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="nationality">Nationality</label>
                                    <input type="text" id="nationality" name="nationality" value="<?php echo $resident ? h($resident['nationality']) : 'Filipino'; ?>">
                                </div>
                                <div>
                                    <label for="religion">Religion</label>
                                    <input type="text" id="religion" name="religion" value="<?php echo $resident ? h($resident['religion']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="blood_type">Blood Type</label>
                                    <select id="blood_type" name="bloodType">
                                        <option value="">Select</option>
                                        <option value="O+" <?php echo $resident && $resident['blood_type']==='O+' ? 'selected' : ''; ?>>O+</option>
                                        <option value="O-" <?php echo $resident && $resident['blood_type']==='O-' ? 'selected' : ''; ?>>O-</option>
                                        <option value="A+" <?php echo $resident && $resident['blood_type']==='A+' ? 'selected' : ''; ?>>A+</option>
                                        <option value="A-" <?php echo $resident && $resident['blood_type']==='A-' ? 'selected' : ''; ?>>A-</option>
                                        <option value="B+" <?php echo $resident && $resident['blood_type']==='B+' ? 'selected' : ''; ?>>B+</option>
                                        <option value="B-" <?php echo $resident && $resident['blood_type']==='B-' ? 'selected' : ''; ?>>B-</option>
                                        <option value="AB+" <?php echo $resident && $resident['blood_type']==='AB+' ? 'selected' : ''; ?>>AB+</option>
                                        <option value="AB-" <?php echo $resident && $resident['blood_type']==='AB-' ? 'selected' : ''; ?>>AB-</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="medical_condition">Medical Condition</label>
                                    <textarea id="medical_condition" name="medicalCondition"><?php echo $resident ? h($resident['medical_condition']) : ''; ?></textarea>
                                </div>
                                <div>
                                    <label for="pwd_status">PWD Status</label>
                                    <select id="pwd_status" name="pwdStatus">
                                        <option value="">Select</option>
                                        <option value="Yes" <?php echo $resident && $resident['pwd_status']==='Yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="No" <?php echo $resident && $resident['pwd_status']==='No' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="disability_type">Disability Type</label>
                                    <input type="text" id="disability_type" name="disabilityType" value="<?php echo $resident ? h($resident['disability_type']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="senior_citizen">Senior Citizen</label>
                                    <select id="senior_citizen" name="seniorCitizen">
                                        <option value="">Select</option>
                                        <option value="Yes" <?php echo $resident && $resident['senior_citizen']==='Yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="No" <?php echo $resident && $resident['senior_citizen']==='No' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-phone"></i> Contact Information</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="mobile_number">Mobile Number</label>
                                    <input type="tel" id="mobile_number" name="mobileNumber" value="<?php echo $resident ? h($resident['mobile_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="telephone_number">Telephone Number</label>
                                    <input type="tel" id="telephone_number" name="telephoneNumber" value="<?php echo $resident ? h($resident['telephone_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" name="emailAddress" value="<?php echo $resident ? h($resident['email']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="emergency_contact">Emergency Contact</label>
                                    <input type="text" id="emergency_contact" name="emergencyContact" value="<?php echo $resident ? h($resident['emergency_contact']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="emergency_number">Emergency Number</label>
                                    <input type="tel" id="emergency_number" name="emergencyNumber" value="<?php echo $resident ? h($resident['emergency_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="relationship">Relationship</label>
                                    <input type="text" id="relationship" name="relationship" value="<?php echo $resident ? h($resident['relationship']) : ''; ?>">
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-home"></i> Household Information</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="household_id">Household ID</label>
                                    <input type="text" id="household_id" name="householdId" value="<?php echo $resident ? h($resident['household_id']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="household_head">Household Head</label>
                                    <input type="text" id="household_head" name="householdHead" value="<?php echo $resident ? h($resident['household_head']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="relationship_head">Relationship to Head</label>
                                    <input type="text" id="relationship_head" name="relationshipHead" value="<?php echo $resident ? h($resident['relationship_head']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="household_members">Household Members</label>
                                    <input type="number" id="household_members" name="householdMembers" value="<?php echo h($householdMembers); ?>" readonly title="Filled in from the household you pick">
                                </div>
                                <div>
                                    <label for="household_type">Household Type</label>
                                    <select id="household_type" name="householdType">
                                        <option value="">Select</option>
                                        <?php selectOptions(HOUSEHOLD_TYPES, $resident['household_type'] ?? ''); ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="income_bracket">Income Bracket</label>
                                    <select id="income_bracket" name="incomeBracket">
                                        <option value="">Select</option>
                                        <?php selectOptions(INCOME_BRACKETS, $resident['income_bracket'] ?? ''); ?>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-ballot-check"></i> Voter & Identification</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="voter_status">Voter Status</label>
                                    <select id="voter_status" name="voterStatus">
                                        <option value="">Select</option>
                                        <option value="Registered" <?php echo $resident && $resident['voter_status']==='Registered' ? 'selected' : ''; ?>>Registered</option>
                                        <option value="Not Registered" <?php echo $resident && $resident['voter_status']==='Not Registered' ? 'selected' : ''; ?>>Not Registered</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="precinct_number">Precinct Number</label>
                                    <input type="text" id="precinct_number" name="precinctNumber" value="<?php echo $resident ? h($resident['precinct_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="philhealth">PhilHealth Number</label>
                                    <input type="text" id="philhealth" name="philhealth" value="<?php echo $resident ? h($resident['philhealth_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="sss">SSS Number</label>
                                    <input type="text" id="sss" name="sss" value="<?php echo $resident ? h($resident['sss_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="gsis">GSIS Number</label>
                                    <input type="text" id="gsis" name="gsis" value="<?php echo $resident ? h($resident['gsis_number']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="tin">TIN</label>
                                    <input type="text" id="tin" name="tin" value="<?php echo $resident ? h($resident['tin']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="national_id">National ID</label>
                                    <input type="text" id="national_id" name="nationalId" value="<?php echo $resident ? h($resident['national_id']) : ''; ?>">
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-briefcase"></i> Employment & Education</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="employment_status">Employment Status</label>
                                    <select id="employment_status" name="employmentStatus">
                                        <option value="">Select</option>
                                        <option value="Employed" <?php echo $resident && $resident['employment_status']==='Employed' ? 'selected' : ''; ?>>Employed</option>
                                        <option value="Self-Employed" <?php echo $resident && $resident['employment_status']==='Self-Employed' ? 'selected' : ''; ?>>Self-Employed</option>
                                        <option value="Unemployed" <?php echo $resident && $resident['employment_status']==='Unemployed' ? 'selected' : ''; ?>>Unemployed</option>
                                        <option value="Student" <?php echo $resident && $resident['employment_status']==='Student' ? 'selected' : ''; ?>>Student</option>
                                        <option value="Retired" <?php echo $resident && $resident['employment_status']==='Retired' ? 'selected' : ''; ?>>Retired</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="occupation">Occupation</label>
                                    <input type="text" id="occupation" name="occupation" value="<?php echo $resident ? h($resident['occupation']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="company">Company/Organization</label>
                                    <input type="text" id="company" name="company" value="<?php echo $resident ? h($resident['company']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="workplace">Workplace</label>
                                    <input type="text" id="workplace" name="workplace" value="<?php echo $resident ? h($resident['workplace']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="monthly_income">Monthly Income</label>
                                    <input type="text" id="monthly_income" name="monthlyIncome" value="<?php echo $resident ? h($resident['monthly_income']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="education">Education Level</label>
                                    <select id="education" name="education">
                                        <option value="">Select</option>
                                        <option value="Elementary" <?php echo $resident && $resident['education']==='Elementary' ? 'selected' : ''; ?>>Elementary</option>
                                        <option value="High School" <?php echo $resident && $resident['education']==='High School' ? 'selected' : ''; ?>>High School</option>
                                        <option value="College" <?php echo $resident && $resident['education']==='College' ? 'selected' : ''; ?>>College</option>
                                        <option value="Vocational" <?php echo $resident && $resident['education']==='Vocational' ? 'selected' : ''; ?>>Vocational</option>
                                        <option value="Post-Graduate" <?php echo $resident && $resident['education']==='Post-Graduate' ? 'selected' : ''; ?>>Post-Graduate</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="school_name">School Name</label>
                                    <input type="text" id="school_name" name="schoolName" value="<?php echo $resident ? h($resident['school_name']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="student_status">Student Status</label>
                                    <select id="student_status" name="studentStatus">
                                        <option value="">Select</option>
                                        <option value="Studying" <?php echo $resident && $resident['student_status']==='Studying' ? 'selected' : ''; ?>>Studying</option>
                                        <option value="Not Studying" <?php echo $resident && $resident['student_status']==='Not Studying' ? 'selected' : ''; ?>>Not Studying</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-list-check"></i> Special Categories</h3>
                            <?php $selectedCategories = $resident ? array_map('trim', explode(',', (string)$resident['categories'])) : []; ?>
                            <div class="checkbox-grid">
                                <?php foreach (['Senior Citizen', 'Solo Parent', 'PWD', 'Indigenous Person', 'Pregnant Woman', 'Lactating Mother', 'OFW Family', '4Ps Beneficiary'] as $category): ?>
                                    <label><input type="checkbox" name="categories[]" value="<?php echo h($category); ?>" <?php echo in_array($category, $selectedCategories, true) ? 'checked' : ''; ?>> <?php echo h($category); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-tags"></i> Status</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="date_registered">Date Registered</label>
                                    <input type="date" id="date_registered" name="dateRegistered" value="<?php echo $resident ? h($resident['date_registered']) : date('Y-m-d'); ?>">
                                </div>
                                <div>
                                    <label for="date_moved_out">Date Moved Out</label>
                                    <input type="date" id="date_moved_out" name="dateMovedOut" value="<?php echo $resident ? h($resident['date_moved_out']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="move_reason">Reason for Moving</label>
                                    <input type="text" id="move_reason" name="moveReason" value="<?php echo $resident ? h($resident['move_reason']) : ''; ?>">
                                </div>
                                <div>
                                    <label for="account_status">Account Status</label>
                                    <select id="account_status" name="accountStatus">
                                        <option value="">Select</option>
                                        <option value="Active" <?php echo $resident && $resident['account_status']==='Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $resident && $resident['account_status']==='Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <h3><i class="fa-solid fa-server"></i> System Information</h3>
                            <div class="form-grid">
                                <div>
                                    <label for="resident_number_display">Resident Number</label>
                                    <input type="text" id="resident_number_display" value="<?php echo h($generatedResidentNumber); ?>" readonly>
                                </div>
                                <div>
                                    <label for="resident_status_display">Resident Status</label>
                                    <input type="text" id="resident_status_display" value="<?php echo $resident ? h($resident['resident_status']) : 'Active'; ?>" readonly>
                                </div>
                                <div>
                                    <label for="created_date">Date Created</label>
                                    <input type="text" id="created_date" value="<?php echo $resident ? h($resident['date_registered']) : date('Y-m-d'); ?>" readonly>
                                </div>
                            </div>
                        </section>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><?php echo $resident ? 'Save Changes' : 'Create Resident'; ?></button>
                            <a href="residents.php" class="btn btn-secondary ms-2">Cancel</a>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </main>
</div>
<?php renderFooterScripts(); ?>
<?php householdHeadLookup($conn, 'household_head', ['id' => 'household_id', 'members' => 'household_members', 'type' => 'household_type', 'income' => 'income_bracket', 'status' => 'resident_status']); ?>

<script>
// Auto-compute age from birthdate
(function(){
    const birth = document.getElementById('birthdate');
    const age = document.getElementById('age');
    const movedIn = document.getElementById('dateMovedIn');
    const lengthRes = document.getElementById('lengthResidency');

    function calcAge(val){
        if(!val) return '';
        const parts = val.split('-').map(Number);
        const birthYear = parts[0];
        const birthMonth = parts[1];
        const birthDay = parts[2];
        const today = new globalThis.Date();
        let years = today.getFullYear() - birthYear;
        const birthdayPassed = (today.getMonth() + 1 > birthMonth)
            || ((today.getMonth() + 1 === birthMonth) && today.getDate() >= birthDay);

        if (!birthdayPassed) years--;
        return years >= 0 ? years : '';
    }

    function calcLength(val){
        if(!val) return '';
        const d = new globalThis.Date(val);
        const now = new globalThis.Date();
        let years = now.getFullYear() - d.getFullYear();
        let months = now.getMonth() - d.getMonth();
        if(months < 0){ years--; months += 12; }
        return years + ' years ' + months + ' months';
    }

    if(birth){
        const updateAge = () => { age.value = calcAge(birth.value); };
        birth.addEventListener('input', updateAge);
        birth.addEventListener('change', updateAge);
        if(birth.value) age.value = calcAge(birth.value);
    }
    if(movedIn){
        movedIn.addEventListener('change', ()=>{ lengthRes.value = calcLength(movedIn.value); });
        if(movedIn.value) lengthRes.value = calcLength(movedIn.value);
    }
})();
</script>

</body>
</html>