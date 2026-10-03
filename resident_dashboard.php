<?php
require_once __DIR__ . '/layout.php';

$resident = currentResident($conn);
if (!$resident) {
    $_SESSION = [];
    session_destroy();
    header('Location: auth.php?denied=1');
    exit;
}

$residentId = (int)$resident['id'];
$certificateCount = 0;
$appointmentCount = 0;
$complaintCount = 0;

$stmt = $conn->prepare('SELECT COUNT(*) FROM certificates WHERE resident_id = ?');
$stmt->bind_param('i', $residentId);
$stmt->execute();
$certificateCount = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $conn->prepare('SELECT purpose, appointment_date, appointment_time, status FROM appointments WHERE resident_id = ? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 5');
$stmt->bind_param('i', $residentId);
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

$stmt = $conn->prepare('SELECT tracking_number, category, date_filed, status FROM complaints WHERE resident_id = ? ORDER BY id DESC LIMIT 5');
$stmt->bind_param('i', $residentId);
$stmt->execute();
$complaints = $stmt->get_result();
$stmt->close();

$activeAlerts = $conn->query("SELECT category, description, created_at FROM emergency_alerts WHERE status = 'Active' ORDER BY created_at DESC LIMIT 5");

$stmt = $conn->prepare('SELECT COUNT(*) FROM appointments WHERE resident_id = ?');
$stmt->bind_param('i', $residentId);
$stmt->execute();
$appointmentCount = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $conn->prepare('SELECT COUNT(*) FROM complaints WHERE resident_id = ?');
$stmt->bind_param('i', $residentId);
$stmt->execute();
$complaintCount = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$currentUserId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT c.id, c.resident_id, c.certificate_type, c.status, c.request_date, r.first_name AS recipient_first_name, r.last_name AS recipient_last_name FROM certificates c LEFT JOIN residents r ON c.resident_id = r.id WHERE c.resident_id = ? OR c.requested_by_user_id = ? ORDER BY c.id DESC LIMIT 5');
$stmt->bind_param('ii', $residentId, $currentUserId);
$stmt->execute();
$certificates = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php pageTitle('Resident Dashboard'); ?>
    <link rel="stylesheet" href="<?php echo asset('dashboard.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>
<div class="wrapper">
    <?php renderSidebar('resident_dashboard', 'panel'); ?>
    <main class="main-content">
        <?php renderTopbar('Resident Dashboard', 'Manage your barangay requests', 'panel', ['clock' => true]); ?>
        <?php renderNotice([
            'certificate_created' => 'Your certificate request was submitted. Please wait for the barangay office to approve it.',
            'appointment_created' => 'Your appointment request was submitted.',
            'complaint_created' => 'Your complaint was submitted.',
            'emergency_reported' => 'Your emergency report was sent to the barangay office.',
        ]); ?>

        <section class="cards" aria-label="My request totals">
            <div class="card">
                <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                <h3>Certificate Requests</h3>
                <h2><?php echo $certificateCount; ?></h2>
            </div>
            <div class="card">
                <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
                <h3>Appointments</h3>
                <h2><?php echo $appointmentCount; ?></h2>
            </div>
            <div class="card">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <h3>Complaints</h3>
                <h2><?php echo $complaintCount; ?></h2>
            </div>
        </section>

        <section class="content-section">
            <div class="section-header">
                <div>
                    <h3>Welcome, <?php echo h($resident['first_name']); ?></h3>
                    <p><?php echo h(trim($resident['first_name'] . ' ' . $resident['last_name'])); ?> | <?php echo h($resident['resident_number']); ?></p>
                </div>
            </div>
            <div class="quick-actions">
                <a class="action-card" href="certificate_form.php">
                    <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i>
                    <strong>Request a Certificate</strong>
                    <span>Submit a barangay certificate request.</span>
                </a>
                <a class="action-card" href="appointment_form.php">
                    <i class="fa-solid fa-calendar-plus" aria-hidden="true"></i>
                    <strong>Book an Appointment</strong>
                    <span>Request a schedule with the barangay office.</span>
                </a>
                <a class="action-card" href="complaint_form.php">
                    <i class="fa-solid fa-file-circle-exclamation" aria-hidden="true"></i>
                    <strong>File a Complaint</strong>
                    <span>Submit an issue for barangay review.</span>
                </a>
                <a class="action-card" href="emergency_report.php">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <strong>Report an Emergency</strong>
                    <span>Send an urgent report to the barangay office.</span>
                </a>
            </div>
        </section>

        <section class="content-section">
            <div class="section-header">
                <div>
                    <h3>Emergency Alerts</h3>
                    <p>Current alerts issued by the barangay office.</p>
                </div>
            </div>
            <?php if ($activeAlerts->num_rows === 0): ?>
                <p>No active emergency alerts.</p>
            <?php else: ?>
                <div class="alert-list">
                    <?php while ($alert = $activeAlerts->fetch_assoc()): ?>
                        <div class="alert-box">
                            <strong><?php echo h($alert['category']); ?></strong>
                            <span><?php echo h($alert['description']); ?></span>
                            <small><?php echo h(formatDatabaseDateTime($alert['created_at'])); ?></small>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="content-section">
            <div class="section-header">
                <div>
                    <h3>My Certificate Requests</h3>
                    <p>Track the latest status of your requests. Approved certificates are claimed at the barangay hall; bring your tracking number.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>Tracking No.</th><th>For</th><th>Certificate</th><th>Request Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($certificates->num_rows === 0): ?>
                        <tr><td colspan="5">No certificate requests yet.</td></tr>
                    <?php else: ?>
                        <?php $statusLabels = ['Approved' => 'Ready for pickup', 'Released' => 'Claimed']; ?>
                        <?php while ($certificate = $certificates->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo h(certificateTrackingNumber((int)$certificate['id'], $certificate['request_date'])); ?></td>
                                <td><?php echo h(trim(($certificate['recipient_first_name'] ?? '') . ' ' . ($certificate['recipient_last_name'] ?? ''))); ?></td>
                                <td><?php echo h($certificate['certificate_type']); ?></td>
                                <td><?php echo h($certificate['request_date']); ?></td>
                                <td><?php echo h($statusLabels[$certificate['status']] ?? $certificate['status']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="content-section">
            <div class="section-header"><div><h3>My Appointments</h3><p>Track your appointment requests.</p></div></div>
            <div class="table-responsive"><table><thead><tr><th>Purpose</th><th>Date</th><th>Time</th><th>Status</th></tr></thead><tbody>
            <?php if ($appointments->num_rows === 0): ?><tr><td colspan="4">No appointments yet.</td></tr>
            <?php else: while ($appointment = $appointments->fetch_assoc()): ?><tr><td><?php echo h($appointment['purpose']); ?></td><td><?php echo h($appointment['appointment_date']); ?></td><td><?php echo h(substr((string)$appointment['appointment_time'], 0, 5)); ?></td><td><?php echo h($appointment['status']); ?></td></tr><?php endwhile; endif; ?>
            </tbody></table></div>
        </section>

        <section class="content-section">
            <div class="section-header"><div><h3>My Complaints</h3><p>Track your complaints.</p></div></div>
            <div class="table-responsive"><table><thead><tr><th>Tracking No.</th><th>Category</th><th>Date Filed</th><th>Status</th></tr></thead><tbody>
            <?php if ($complaints->num_rows === 0): ?><tr><td colspan="4">No complaints yet.</td></tr>
            <?php else: while ($complaint = $complaints->fetch_assoc()): ?><tr><td><?php echo h($complaint['tracking_number']); ?></td><td><?php echo h($complaint['category']); ?></td><td><?php echo h($complaint['date_filed']); ?></td><td><?php echo h($complaint['status']); ?></td></tr><?php endwhile; endif; ?>
            </tbody></table></div>
        </section>
    </main>
</div>
<?php renderFooterScripts(); ?>
</body>
</html>
