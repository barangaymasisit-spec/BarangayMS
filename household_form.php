<?php
require_once __DIR__ . '/layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$household = null;
$error = '';

if ($id > 0) {
    $stmt = $conn->prepare('SELECT * FROM households WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $household = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$household) {
        header('Location: household.php?msg=missing');
        exit;
    }
}

// Delete
if ($household && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $conn->prepare('DELETE FROM households WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, 'deleted', 'households', 'Deleted household record.', $id);
    header('Location: household.php?msg=deleted');
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
    $membersCount = $values['members_count'] === '' ? null : (int)$values['members_count'];

    if ($values['household_number'] === '') {
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
            logActivity($conn, 'updated', 'households', 'Updated household record.', $id);
            header('Location: household.php?msg=updated');
            exit;
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
$typeOptions = ['Nuclear Family', 'Extended Family', 'Single Parent', 'Solo Dweller', 'Others'];
$incomeOptions = ['Below 10,000', '10,000 - 20,000', '20,001 - 30,000', '30,001 - 50,000', 'Above 50,000'];
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
                            <input type="text" id="household_head" name="household_head" value="<?php echo h($values['household_head']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="members_count">Number of Members</label>
                            <input type="number" id="members_count" name="members_count" min="0" value="<?php echo h($values['members_count']); ?>">
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
                                <?php foreach ($typeOptions as $option): ?>
                                    <option<?php echo $values['household_type'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="income_bracket">Monthly Income Bracket</label>
                            <select id="income_bracket" name="income_bracket">
                                <option value="">Select bracket</option>
                                <?php foreach ($incomeOptions as $option): ?>
                                    <option<?php echo $values['income_bracket'] === $option ? ' selected' : ''; ?>><?php echo $option; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label for="address">Complete Address</label>
                            <textarea id="address" name="address" rows="3"><?php echo h($values['address']); ?></textarea>
                        </div>

                    </div>

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
                        <tr><th>Date Added</th><td><?php echo h($household['created_at']); ?></td></tr>
                    </tbody>
                </table>

                <div class="record-actions">
                    <a href="household_form.php?id=<?php echo $id; ?>&amp;edit=1" class="btn-primary">
                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                                        <form method="post" action="household_form.php?id=<?php echo $id; ?>" class="d-inline"
                                                    onsubmit="return confirm('Delete this household record?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                <button type="submit" class="btn-danger"><span>Delete</span></button>
                                        </form>
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
</body>
</html>
