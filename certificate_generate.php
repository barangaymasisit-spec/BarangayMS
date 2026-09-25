<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
restorePersistentAuthSession($conn);

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

// Get certificate ID and type from query string
$certificateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$typeParam = isset($_GET['type']) ? strtolower($_GET['type']) : '';

if (!$certificateId) {
    die('Certificate ID is required.');
}

// Fetch certificate details using prepared statement
$stmt = $conn->prepare('SELECT * FROM certificates WHERE id = ?');
$stmt->bind_param('i', $certificateId);
$stmt->execute();
$certResult = $stmt->get_result();
$stmt->close();

if ($certResult->num_rows === 0) {
    die('Certificate not found.');
}
$cert = $certResult->fetch_assoc();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'staff', 'resident'], true)) {
    http_response_code(403);
    exit('You are not authorized to view this certificate.');
}

// Determine certificate type from DB or parameter
$dbType = strtolower(trim($cert['certificate_type'] ?? ''));
$certificateType = $dbType !== '' ? $dbType : str_replace('_', ' ', $typeParam);
$isBarangayClearance = $certificateType === 'barangay clearance';
$isResidency = $certificateType === 'certificate of residency';
$isIndigency = $certificateType === 'certificate of indigency';
$isBusinessClearance = $certificateType === 'business clearance';
$isGoodMoral = $certificateType === 'good moral';
$isGeneral = !$isIndigency;

// Fetch resident details
$residentId = $cert['resident_id'] ?? 0;
$residentStmt = $conn->prepare('SELECT * FROM residents WHERE id = ?');
$residentStmt->bind_param('i', $residentId);
$residentStmt->execute();
$resident = $residentStmt->get_result()->fetch_assoc();
$residentStmt->close();

$residentName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
    $resident['first_name'] ?? '',
    $resident['middle_name'] ?? '',
    $resident['last_name'] ?? '',
    $resident['suffix'] ?? '',
]))));

if ($role === 'resident') {
    $currentResident = currentResident($conn);
    if (!$currentResident || !$resident || (int)$resident['id'] !== (int)$currentResident['id']) {
        header('Location: resident_dashboard.php');
        exit;
    }
}

// Fetch barangay settings
$settingsResult = $conn->query('SELECT * FROM barangay_settings LIMIT 1');
$settings = $settingsResult->fetch_assoc();
$officialsResult = $conn->query("SELECT first_name, last_name, position, term FROM users WHERE role = 'staff' AND status = 'Active' ORDER BY id ASC");
$officials = $officialsResult ? $officialsResult->fetch_all(MYSQLI_ASSOC) : [];

// Get barangay logo
$logoPath = !empty($settings['logo_path']) && file_exists(__DIR__ . '/' . $settings['logo_path'])
    ? $settings['logo_path']
    : 'logo.png';

$validation = validateCertificatePrintable($cert, $resident ?: []);
$trackingNumber = certificateTrackingNumber((int)($cert['id'] ?? 0), $cert['request_date'] ?? null);
$issuedOn = date('Y-m-d');
$printedTimestamp = strtotime($issuedOn);
$emailAddress = !empty($resident['email']) ? $resident['email'] : ($settings['email'] ?? '');
$mailtoHref = $emailAddress !== '' ? 'mailto:' . rawurlencode($emailAddress) . '?subject=' . rawurlencode('Certificate - ' . ($cert['certificate_type'] ?? 'Barangay Certificate')) . '&body=' . rawurlencode("Dear Resident,\n\nYour certificate is ready. Tracking Number: $trackingNumber\n\nPlease present this email when claiming your document.\n\nThank you.") : '#';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }

        .certificate-container {
            background: white;
            width: 8.5in;
            height: 11in;
            margin: 20px auto;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .certificate-header {
            text-align: center;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .logo-section {
            width: 100px;
            text-align: center;
        }

        .logo-section img {
            width: 100%;
            height: auto;
            max-width: 100px;
        }

        .header-text {
            flex: 1;
            text-align: center;
        }

        .header-text h1 {
            font-size: 11px;
            font-weight: 600;
            margin: 2px 0;
            letter-spacing: 1px;
        }

        .header-text p {
            font-size: 10px;
            margin: 2px 0;
        }

        .header-text h2 {
            font-size: 14px;
            font-weight: 700;
            margin: 5px 0;
        }

        .certificate-title {
            text-align: center;
            margin: 30px 0;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 15px 0;
        }

        .certificate-title h3 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 3px;
        }

        .office-name {
            text-align: center;
            font-weight: 700;
            margin: 20px 0;
            font-size: 12px;
        }

        .certificate-body {
            font-size: 12px;
            line-height: 1.8;
            text-align: justify;
            margin: 20px 0;
            font-family: 'Times New Roman', Times, serif;
        }

        .certificate-body p {
            margin: 15px 0;
            text-indent: 30px;
        }

        .certificate-body p.no-indent {
            text-indent: 0;
        }

        .certificate-footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .signature-section {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-top: 1px;
            margin-bottom: 3px;
        }

        .signature-name {
            margin-top: 50px;
            font-weight: 700;
            font-size: 11px;
        }

        .signature-title {
            font-size: 10px;
            color: #555;
        }

        .footer-info {
            font-size: 10px;
            margin-top: 20px;
        }

        .footer-info p {
            margin: 3px 0;
        }

        .form-fields {
            margin: 15px 0;
        }

        .form-field {
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
        }

        .form-field label {
            font-weight: 600;
            min-width: 120px;
        }

        .form-field-line {
            border-bottom: 1px solid #000;
            flex: 1;
            min-height: 20px;
            display: flex;
            align-items: center;
            padding: 0 5px;
        }

        .picture-placeholder {
            width: 120px;
            height: 150px;
            border: 2px solid #999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            text-align: center;
            font-size: 10px;
            color: #999;
        }

        .note {
            text-align: center;
            font-size: 10px;
            font-weight: 600;
            margin-top: 20px;
            border-top: 1px solid #000;
            padding-top: 10px;
        }

        @media print {
            @page {
                size: letter portrait;
                margin: 0;
            }

            body {
                background: white;
                padding: 0;
            }
            .certificate-container {
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 0.5in;
                box-shadow: none;
                page-break-after: always;
            }
            .print-button {
                display: none;
            }
        }

        .print-button {
            display: block;
            margin: 20px auto;
            padding: 12px 30px;
            background: #0B4A9E;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
        }

        .print-button:hover {
            background: #0a3a7d;
        }

        .print-button[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .print-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 18px auto 0;
        }

        .notice-banner {
            max-width: 820px;
            margin: 10px auto 0;
            background: #fff4d6;
            border: 1px solid #f2c768;
            color: #7a5600;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .notice-banner.error {
            background: #fdecec;
            border-color: #f0aeae;
            color: #8e1c1c;
        }

        .tracking-box {
            display: inline-block;
            margin: 12px auto 0;
            padding: 8px 16px;
            border: 1px solid #d7d7d7;
            border-radius: 999px;
            background: #f8f8f8;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
        }

        .signature-seal {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            margin: 0 auto 10px;
            border: 2px dashed #b7b7b7;
            border-radius: 50%;
            color: #8a8a8a;
            font-size: 10px;
            text-align: center;
            line-height: 1.2;
            font-weight: 700;
        }

        .signature-seal-image {
            display: block;
            width: 120px;
            height: 120px;
            margin: 0 auto 10px;
            object-fit: contain;
            opacity: 0.35;
        }

        .good-moral-certificate {
            display: grid;
            grid-template-columns: 2.55in 1fr;
            grid-template-rows: auto 1fr;
            gap: 0;
            align-content: stretch;
            margin: 0 auto;
            padding: 0.24in 0.28in 1in;
            font-family: Arial, Helvetica, sans-serif;
        }

        .good-moral-certificate .officials-column {
            border-right: 3px double #111;
            padding: 0.06in 0.08in 0 0;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .good-moral-certificate .officials-column h4 {
            font-size: 18px;
            margin: 0.5in 0 0.18in;
            letter-spacing: 0.2px;
        }

        .officials-logo {
            display: block;
            width: 0.7in;
            height: 0.7in;
            object-fit: contain;
            margin: 0 auto 0.1in;
        }

        .official-entry {
            margin: 0 0 0.12in;
            font-size: 15px;
            line-height: 1.25;
        }

        .official-entry strong,
        .official-entry span {
            display: block;
        }

        .official-entry span {
            font-style: italic;
        }

        .officials-note {
            border-top: 1px solid #111;
            margin-top: auto;
            padding-top: 0.08in;
            font-size: 12px;
            line-height: 1.3;
        }

        .good-moral-main {
            position: relative;
            padding: 0 0.28in 0 0.3in;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .good-moral-main::before {
            content: none;
        }

        .good-moral-main > * {
            position: relative;
        }

        .good-moral-header {
            display: grid;
            grid-column: 1 / -1;
            grid-template-columns: 1.05in 3in 1.05in;
            align-items: center;
            justify-content: space-between;
            gap: 0;
            padding-left: 0.2in;
            padding-right: 0.2in;
            padding-bottom: 0.16in;
            border-bottom: 4px double #111;
            text-align: center;
            font-family: 'Times New Roman', Times, serif;
            box-sizing: border-box;
        }

        .good-moral-header img {
            width: 1.05in;
            height: 1.05in;
            object-fit: contain;
        }

        .good-moral-header p {
            margin: 0 0 2px;
            font-size: 12px;
        }

        .good-moral-header h1 {
            margin: 3px 0;
            font-size: 20px;
            letter-spacing: 0.8px;
        }

        .good-moral-header h2 {
            margin: 0;
            font-size: 15px;
            letter-spacing: 1px;
        }

        .good-moral-title {
            margin: 0.28in 0 0.25in;
            text-align: center;
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 29px;
            font-style: italic;
            letter-spacing: 4px;
            text-decoration: underline;
            text-underline-offset: 5px;
        }

        .good-moral-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-evenly;
            font-size: 16px;
            line-height: 1.55;
            text-align: justify;
        }

        .good-moral-body p {
            margin: 0;
        }

        .good-moral-body .indent {
            text-indent: 0.45in;
        }

        .issued-date-value {
            display: inline-block;
            border-bottom: 1px solid #111;
            line-height: 1.05;
            text-align: center;
        }

        .good-moral-signature {
            width: 2.4in;
            margin: 0.16in 0 0 auto;
            text-align: center;
            font-size: 12px;
        }

        .good-moral-signature strong {
            display: block;
            margin-top: 0.35in;
        }

        .good-moral-footer {
            display: flex;
            justify-content: space-between;
            gap: 0.2in;
            margin-top: 0.18in;
            font-size: 11px;
        }

        .good-moral-footer p {
            margin: 2px 0;
        }

        @media print {
            .good-moral-certificate {
                width: 8.5in;
                height: 11in;
                margin: 0;
                padding: 0.2in 0.24in 1in;
                box-shadow: none;
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <?php if (!$validation['allowed']): ?>
        <div class="notice-banner error">
            <?php echo h(implode(' ', $validation['errors'])); ?>
        </div>
    <?php endif; ?>

    <?php if ($isGoodMoral): ?>
    <div class="certificate-container good-moral-certificate">
        <header class="good-moral-header">
            <img src="seal.jpg" alt="Barangay Seal">
            <div>
                <p>Republic of the Philippines</p>
                <p>Province of <?php echo h($settings['province'] ?? ''); ?></p>
                <p>Municipality of <?php echo h($settings['municipality'] ?? ''); ?></p>
                <h1><?php echo h(strtoupper($settings['barangay_name'] ?? 'BARANGAY')); ?></h1>
                <?php if (!empty($settings['email'])): ?><p>Email: <?php echo h($settings['email']); ?></p><?php endif; ?>
            </div>
            <img src="<?php echo h($logoPath); ?>" alt="Barangay Logo">
        </header>

        <aside class="officials-column">
            <h4>BARANGAY OFFICIALS</h4>
            <?php foreach ($officials as $official): ?>
                <div class="official-entry">
                    <strong><?php echo h('Hon. ' . trim(($official['first_name'] ?? '') . ' ' . ($official['last_name'] ?? ''))); ?></strong>
                    <span><?php echo h($official['position'] ?: ($official['term'] ?: 'Barangay Official')); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="officials-note">NOT VALID WITHOUT THE OFFICIAL SEAL OF THE BARANGAY</div>
        </aside>

        <main class="good-moral-main">
            <h2 class="good-moral-title">CERTIFICATION</h2>

            <div class="good-moral-body">
                <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
                <p class="indent"><strong>THIS IS TO CERTIFY</strong> that <u><?php echo h($residentName); ?></u>, <?php echo h($resident['age'] ?? '___'); ?> years of age, single/married/widow(er), Filipino citizen, and a resident of the above-named person for all legal purposes it may serve.</p>
                <p class="indent">This Certification is being issued upon this request of the above-named person for all legal purposes it may serve.</p>
                <p class="indent">This is to certify further that <u><?php echo h($residentName); ?></u> is bona fide resident of <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?>, Municipality of <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?>, and a person of <strong>GOOD MORAL CHARACTER</strong>, peaceful and law-abiding citizen.</p>
                <p class="indent">This Certification is being issued upon this request of the above-named person for all legal purposes it may serve.</p>
                <p class="indent">Issued this <span class="issued-date-value"><?php echo h(date('d', $printedTimestamp)); ?></span> day of <span class="issued-date-value"><?php echo h(date('F', $printedTimestamp)); ?></span> <span class="issued-date-value"><?php echo h(date('Y', $printedTimestamp)); ?></span> at <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?>, <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?>, Philippines.</p>
            </div>

            <div class="good-moral-signature">
                <strong><?php echo h($settings['barangay_captain'] ?? 'PUNONG BARANGAY'); ?></strong>
                <div class="signature-line"></div>
                <div>Punong Barangay</div>
            </div>

            <footer class="good-moral-footer">
                <div>
                    <p>Certification Fee: <strong>P 200.00</strong></p>
                    <p>Issued at: <u><?php echo h(($settings['municipality'] ?? '') . ', ' . ($settings['province'] ?? '')); ?></u></p>
                </div>
                <p>Paid Under: OR # __________________</p>
            </footer>
        </main>
    </div>
    <?php else: ?>
    <div class="certificate-container">
        <!-- Header -->
        <div class="certificate-header">
            <div class="logo-section">
                <img src="<?php echo htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Barangay Logo">
            </div>
            <div class="header-text">
                <p>Republic of the Philippines</p>
                <p>Province of <?php echo h($settings['province'] ?? 'Cagayan'); ?></p>
                <p>Municipality of <?php echo h($settings['municipality'] ?? 'Sanchez Mira'); ?></p>
                <h2><?php echo h($settings['barangay_name'] ?? 'Barangay'); ?></h2>
            </div>
            <div class="logo-section">
                <img src="logosm.png?v=<?php echo h((string)filemtime(__DIR__ . '/logosm.png')); ?>" alt="Barangay Logo">
            </div>
        </div>

        <!-- Office Name -->
        <div class="office-name">
            OFFICE OF THE PUNONG BARANGAY
        </div>

        <!-- Certificate Title -->
        <div class="certificate-title">
            <h3><?php echo htmlspecialchars(strtoupper($cert['certificate_type'] ?? 'Certificate'), ENT_QUOTES, 'UTF-8'); ?></h3>
        </div>

        <div style="text-align:center;">
            <div class="tracking-box">Tracking No.: <?php echo h($trackingNumber); ?></div>
        </div>

        <!-- Certificate Body -->
        <div class="certificate-body">
            <?php if ($isIndigency): ?>
                <!-- Indigency Certificate -->
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>
                
                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span>, 
                <?php echo h($resident['age'] ?? '___'); ?> years of age,
                resident of <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?>, <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?> 
                <span style="border-bottom: 1px solid #000; padding: 0 10px;">single/married/widow/er</span>, and bona fide
                character, peaceful and law-abiding citizen in our Barangay, that the above-mentioned
                named is listed as one of indigent families in our Barangay.</p>

                <p>THIS CERTIFIES FURTHER, that the above-mentioned named is known to me
                personally, a person of good moral character, and law-abiding citizen in our Barangay.</p>

                <p>This Certification is being issued upon the request of the above-named person for
                all legal purposes it may serve.</p>

            <?php elseif ($isResidency): ?>
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>

                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span> is a bona fide resident of <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?>, <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?>.</p>

                <p>According to our records, the above-named person has been residing in this barangay and is known to be a person of good moral character.</p>

                <p>This certification is issued upon the request of the above-named person for <strong><?php echo h($cert['purpose'] ?: 'whatever legal purpose it may serve'); ?></strong>.</p>

            <?php elseif ($isBarangayClearance): ?>
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>

                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span> is a bonafide resident of this barangay.</p>

                <p>Based on the records available in this office, the above-named person has no derogatory record or pending case reported in this barangay as of the date of issuance.</p>

                <p>This clearance is issued upon the request of the above-named person for <strong><?php echo h($cert['purpose'] ?: 'whatever legal purpose it may serve'); ?></strong>.</p>

            <?php elseif ($isBusinessClearance): ?>
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>

                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span> is a resident of <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?> and is cleared, as far as this office is concerned, to apply for or operate a business within this barangay.</p>

                <p>This clearance is issued upon the request of the above-named person for <strong><?php echo h($cert['purpose'] ?: 'business permit application'); ?></strong>, subject to applicable laws, ordinances, and other required permits.</p>

            <?php elseif ($isGoodMoral): ?>
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>

                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span> is a resident of <?php echo h($settings['barangay_name'] ?? 'Barangay'); ?> and is known to be a person of good moral character.</p>

                <p>To the best of our knowledge, the above-named person has no derogatory record in this barangay.</p>

                <p>This certificate is issued upon the request of the above-named person for <strong><?php echo h($cert['purpose'] ?: 'whatever legal purpose it may serve'); ?></strong>.</p>

            <?php else: ?>
                <!-- Fallback for legacy certificate types -->
                <p class="no-indent"><strong>TO WHOM IT MAY CONCERN:</strong></p>
                
                <p>This is to certify that <span style="border-bottom: 1px solid #000; padding: 0 10px;"><?php echo h($residentName); ?></span> is a resident of this Barangay.</p>

                <p>CERTIFYING further that the above-named mentioned person is a person of good moral
                character and has no derogatory and/or criminal records in the Barangay.</p>

                <p>This Certification is being issued upon the request of the above-named name person for
                IDENTIFICATION and RESIDENCY purposes.</p>
            <?php endif; ?>

            <p style="margin-top: 30px;">Issued this <span style="border-bottom: 1px solid #000; padding: 0 10px; width: 80px;">
                <?php echo h(date('d', $printedTimestamp)); ?>
            </span> day of 
            <span style="border-bottom: 1px solid #000; padding: 0 10px; width: 150px;">
                <?php echo h(date('F', $printedTimestamp)); ?>
            </span>, 
            <span style="border-bottom: 1px solid #000; padding: 0 10px; width: 60px;">
                <?php echo h(date('Y', $printedTimestamp)); ?>
            </span> at the office of the
            <?php echo h($settings['barangay_name'] ?? 'Punong Barangay'); ?> of <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?>.</p>
        </div>

        <!-- Footer -->
        <div class="certificate-footer">
            <div style="width: 45%;">
                <!-- Picture placeholder for general certification -->
                <?php if ($isGeneral && !$isIndigency): ?>
                    <div class="picture-placeholder">
                        2x2<br>ID PICTURE
                    </div>
                <?php endif; ?>
            </div>
            <div class="signature-section">
                <img class="signature-seal-image" src="seal.jpg" alt="Official Seal">
                <div class="signature-name"><?php echo h($settings['barangay_captain'] ?? 'PUNONG BARANGAY'); ?></div>
                <div class="signature-line"></div>
                <div class="signature-title"><?php echo h($settings['barangay_name'] ?? 'Punong Barangay'); ?></div>
            </div>
        </div>

        <!-- Footer Information -->
        <div class="footer-info">
            <?php if ($isGeneral && !$isIndigency): ?>
                <p><strong>Certification Fee:</strong> ________________</p>
                <p><strong>O.R. No:</strong> ________________</p>
            <?php endif; ?>
            <p><strong>Issued On:</strong> <?php echo h(date('M d, Y', strtotime($issuedOn))); ?></p>
            <p><strong>Printed On:</strong> <?php echo h(date('M d, Y h:i A')); ?></p>
            <p><strong>Issued At:</strong> <?php echo h($settings['municipality'] ?? 'Municipality'); ?>, <?php echo h($settings['province'] ?? 'Province'); ?></p>
        </div>

        <!-- Note -->
        <div class="note">
            NOTE: NOT VALID WITHOUT THE OFFICIAL SEAL OF THE BARANGAY.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($validation['allowed']): ?>
        <?php logActivity($conn, 'printed', 'certificates', 'Printed certificate', (int)($cert['id'] ?? 0)); ?>
    <?php endif; ?>

    <div class="print-actions">
        <button class="print-button" onclick="window.print()" <?php echo $validation['allowed'] ? '' : 'disabled'; ?>>🖨️ Print Certificate</button>
        <button class="print-button" onclick="window.print(); return false;">📄 Save as PDF</button>
        <?php if ($emailAddress !== ''): ?>
            <a class="print-button" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;" href="<?php echo h($mailtoHref); ?>">✉️ Email Certificate</a>
        <?php endif; ?>
    </div>

</body>
</html>
