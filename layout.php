<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/toast.php';
applyNoStoreHeaders();
restorePersistentAuthSession($conn);
enforceActiveAccount($conn);
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$residentPages = ['resident_dashboard.php', 'certificate_form.php', 'appointment_form.php', 'complaint_form.php', 'emergency_report.php', 'logout.php'];
if ($role === 'resident' && !in_array(basename($_SERVER['PHP_SELF']), $residentPages, true)) {
    header('Location: resident_dashboard.php');
    exit;
}
if (!in_array($role, ['admin', 'staff', 'health_worker', 'security_force', 'resident'], true)) {
    $_SESSION = [];
    session_destroy();
    header('Location: auth.php?denied=1');
    exit;
}

$allRolePages = [
    'dashboard.php', 'residents.php', 'resident_form.php', 'household.php', 'household_form.php', 'officials.php', 'audit.php',
    'certificates.php', 'certificate_form.php', 'certificate_generate.php', 'indigency.php', 'residency.php', 'barangay_clearance.php', 'business.php', 'good_moral.php',
    'complaints.php', 'complaint_form.php', 'appointments.php', 'appointment_form.php', 'emergency.php', 'emergency_report.php', 'settings.php', 'logout.php', 'resident_dashboard.php',
];

$rolePages = [
    'admin' => $allRolePages,
    'staff' => [
        'dashboard.php', 'residents.php', 'resident_form.php',
        'certificates.php', 'certificate_form.php', 'certificate_generate.php', 'indigency.php', 'residency.php', 'barangay_clearance.php', 'business.php', 'good_moral.php',
        'complaints.php', 'complaint_form.php', 'appointments.php', 'appointment_form.php', 'logout.php',
    ],
    'health_worker' => ['dashboard.php', 'residents.php', 'logout.php'],
    'security_force' => ['dashboard.php', 'emergency.php', 'logout.php'],
    'resident' => ['resident_dashboard.php', 'certificate_form.php', 'appointment_form.php', 'complaint_form.php', 'emergency_report.php', 'logout.php'],
];
if (isset($rolePages[$role]) && !in_array(basename($_SERVER['PHP_SELF']), $rolePages[$role], true)) {
    header('Location: dashboard.php?denied=1');
    exit;
}

if (isset($conn) && $conn instanceof mysqli) {
    $GLOBALS['conn'] = $conn;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken()) {
    if (!empty($_SESSION['user_id'])) {
        session_regenerate_id(true);
        refreshSessionCsrfToken();
        clearPersistentAuthCookie();
        setSessionRecoveryNotice('Your session was refreshed automatically. Please review the form and submit it again.');
        $redirectUrl = $_SERVER['PHP_SELF'] ?? 'dashboard.php';
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $redirectParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $redirectParams);
        }
        $redirectParams['csrf_recovered'] = '1';
        $redirectUrl .= $queryString === '' ? '?csrf_recovered=1' : ('?' . http_build_query($redirectParams));
        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    http_response_code(403);
    exit('Invalid or expired form token. Please go back, refresh the page, and try again.');
}

/**
 * Office-side notification counts, limited to pages the current role may open
 * (a health worker is not told about complaints they cannot see). Cached per request.
 */
function staffNotifications(mysqli $conn): array {
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    global $rolePages;
    $allowedPages = $rolePages[$_SESSION['role'] ?? ''] ?? [];
    $queries = [
        ['sql' => "SELECT COUNT(*) FROM certificates WHERE status = 'Pending'", 'text' => 'Pending certificate requests', 'detail' => 'Review certificate approvals', 'href' => 'certificates.php', 'icon' => 'fa-file-lines'],
        ['sql' => "SELECT COUNT(*) FROM appointments WHERE status = 'Pending'", 'text' => 'Pending appointments', 'detail' => 'Review appointment requests', 'href' => 'appointments.php', 'icon' => 'fa-calendar-check'],
        ['sql' => "SELECT COUNT(*) FROM complaints WHERE status = 'Pending'", 'text' => 'Pending complaints', 'detail' => 'Review complaint reports', 'href' => 'complaints.php', 'icon' => 'fa-circle-exclamation'],
        ['sql' => "SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing'", 'text' => 'Ongoing complaints', 'detail' => 'View active complaints', 'href' => 'complaints.php', 'icon' => 'fa-spinner'],
        ['sql' => "SELECT COUNT(*) FROM emergency_alerts WHERE status = 'Reported'", 'text' => 'Emergency reports to review', 'detail' => 'Broadcast or dismiss resident reports', 'href' => 'emergency.php', 'icon' => 'fa-triangle-exclamation'],
    ];
    $items = [];
    foreach ($queries as $query) {
        if (!in_array($query['href'], $allowedPages, true)) {
            continue;
        }
        $result = $conn->query($query['sql']);
        $total = (int)($result ? ($result->fetch_row()[0] ?? 0) : 0);
        if ($total > 0) {
            $items[] = ['icon' => $query['icon'], 'text' => $total . ' ' . $query['text'], 'detail' => $query['detail'], 'href' => $query['href'], 'total' => $total];
        }
    }
    return $items;
}

function notificationCount(mysqli $conn): int {
    if (($_SESSION['role'] ?? '') !== 'resident') {
        return array_sum(array_column(staffNotifications($conn), 'total')) + count(officialTermAlerts($conn));
    }
    $resident = currentResident($conn);
    if (!$resident) {
        return 0;
    }
    $residentId = (int)$resident['id'];
    $stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM certificates WHERE resident_id = ? AND status IN ('Approved', 'Released')) +
        (SELECT COUNT(*) FROM appointments WHERE resident_id = ? AND status = 'Approved') +
        (SELECT COUNT(*) FROM complaints WHERE resident_id = ? AND status IN ('Ongoing', 'Resolved')) +
        (SELECT COUNT(*) FROM emergency_alerts WHERE status = 'Active')");
    $stmt->bind_param('iii', $residentId, $residentId, $residentId);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
}

function notificationItems(mysqli $conn): array {
    $items = [];
    $role = $_SESSION['role'] ?? '';

    if ($role === 'resident') {
        $resident = currentResident($conn);
        if (!$resident) {
            return [];
        }
        $residentId = (int)$resident['id'];
        $alerts = $conn->query("SELECT category, description FROM emergency_alerts WHERE status = 'Active' ORDER BY created_at DESC LIMIT 5");
        while ($row = $alerts->fetch_assoc()) {
            $items[] = ['icon' => 'fa-triangle-exclamation', 'text' => 'Emergency alert: ' . $row['category'], 'detail' => $row['description'], 'href' => 'resident_dashboard.php'];
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT certificate_type, status FROM certificates WHERE (resident_id = ? OR requested_by_user_id = ?) AND status IN ('Approved', 'Released') ORDER BY updated_at DESC LIMIT 5");
        $stmt->bind_param('ii', $residentId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = ['icon' => 'fa-file-lines', 'text' => 'Certificate ' . ($row['status'] === 'Released' ? 'released' : 'approved'), 'detail' => $row['certificate_type'] ?: 'Certificate request', 'href' => 'certificate_form.php'];
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT purpose, status FROM appointments WHERE resident_id = ? AND status = 'Approved' ORDER BY updated_at DESC LIMIT 5");
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = ['icon' => 'fa-calendar-check', 'text' => 'Appointment approved', 'detail' => $row['purpose'] ?: 'Appointment request', 'href' => 'appointment_form.php'];
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT category, status FROM complaints WHERE resident_id = ? AND status IN ('Ongoing', 'Resolved') ORDER BY updated_at DESC LIMIT 5");
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = ['icon' => 'fa-circle-exclamation', 'text' => 'Complaint ' . strtolower($row['status']), 'detail' => $row['category'] ?: 'Complaint report', 'href' => 'complaint_form.php'];
        }
        $stmt->close();
    } else {
        $items = array_merge(officialTermAlerts($conn), staffNotifications($conn));
    }

    return array_slice($items, 0, 8);
}

function notificationBell(mysqli $conn): string {
    $total = notificationCount($conn);
    $label = $total > 99 ? '99+' : (string)$total;
    $items = notificationItems($conn);
    $html = '<div class="notification-wrap">';
    $html .= '<button type="button" class="notification-btn notification-toggle" aria-label="View Notifications" aria-expanded="false" title="Notifications"><i class="fa-solid fa-bell" aria-hidden="true"></i>';
    if ($total > 0) {
        $html .= '<span class="notification-badge" style="position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#dc3545;color:#fff;font:700 11px/20px Arial,sans-serif;text-align:center">' . $label . '</span>';
    }
    $html .= '</button><div class="notification-menu" hidden><div class="notification-menu-title">Notifications</div>';
    if (!$items) {
        $html .= '<div class="notification-empty">No new notifications.</div>';
    } else {
        foreach ($items as $item) {
            $html .= '<a class="notification-item" href="' . htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid ' . htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') . '"></i><span><strong>' . htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8') . '</strong><small>' . htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') . '</small></span></a>';
        }
    }
    $html .= '</div></div>';
    return $html;
}

/**
 * Appends the file's last-modified time to a local asset URL so the browser
 * always downloads the current version instead of a cached one.
 */
function asset(string $file): string {
    $path = __DIR__ . '/' . $file;
    $version = is_file($path) ? filemtime($path) : 1;
    return htmlspecialchars($file . '?v=' . $version, ENT_QUOTES, 'UTF-8');
}

const HOUSEHOLD_TYPES = ['Nuclear Family', 'Extended Family', 'Single Parent', 'Solo Dweller', 'Others'];
const INCOME_BRACKETS = ['Below 10,000', '10,000 - 20,000', '20,001 - 30,000', '30,001 - 50,000', 'Above 50,000'];

/**
 * Prints <option>s for $options with $current selected. A saved value that is
 * no longer in the list is kept as an extra option so editing never drops it.
 */
function selectOptions(array $options, string $current = ''): void {
    if ($current !== '' && !in_array($current, $options, true)) {
        $options[] = $current;
    }
    foreach ($options as $option) {
        echo '<option' . ($option === $current ? ' selected' : '') . '>' . h($option) . '</option>';
    }
}

/**
 * SQL that counts a household's members: residents linked by Household ID,
 * leaving out those who died or moved out. $householdIdExpr is trusted SQL, never user input.
 */
function householdMembersSql(string $householdIdExpr): string {
    return "(SELECT COUNT(*) FROM residents hm WHERE hm.household_id = $householdIdExpr AND COALESCE(hm.resident_status, '') NOT IN ('Deceased', 'Moved Out'))";
}

/**
 * Suggests known household heads while typing in $headInputId, and when one is
 * picked fills the household fields in $fieldIds (id, members, type, income => input id).
 */
function householdHeadLookup(mysqli $conn, string $headInputId, array $fieldIds): void {
    $heads = [];
    $sql = "SELECT household_head, household_number, " . householdMembersSql('households.household_number') . ", household_type, income_bracket FROM households WHERE household_head <> ''
            UNION ALL
            SELECT household_head, household_id, " . householdMembersSql('residents.household_id') . ", household_type, income_bracket FROM residents WHERE household_head <> '' AND household_id <> ''";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_row()) {
            $heads[strtolower(trim($row[0]))] ??= $row;
        }
    }
    echo '<datalist id="householdHeadList">';
    foreach ($heads as [$name, $number, $members, $type, $income]) {
        echo '<option value="' . h(trim($name)) . '" data-id="' . h((string)$number) . '" data-members="' . h((string)$members)
            . '" data-type="' . h((string)$type) . '" data-income="' . h((string)$income) . '"></option>';
    }
    echo '</datalist>';
    ?>
<script>
(function(){
    const head = document.getElementById(<?php echo json_encode($headInputId); ?>);
    const fields = <?php echo json_encode($fieldIds); ?>;
    if (!head) return;
    head.setAttribute('list', 'householdHeadList');
    head.setAttribute('autocomplete', 'off');
    head.addEventListener('input', function(){
        const name = head.value.trim().toLowerCase();
        const match = Array.from(document.querySelectorAll('#householdHeadList option'))
            .find(o => o.value.toLowerCase() === name);
        if (!match) return;
        for (const [key, inputId] of Object.entries(fields)) {
            const el = document.getElementById(inputId);
            if (el && match.dataset[key]) el.value = match.dataset[key];
        }
        // The count covers residents already saved in the household; add this one if they are joining it
        // and still count as a member (the deceased and moved out do not).
        const idInput = document.getElementById(fields.id || '');
        const membersInput = document.getElementById(fields.members || '');
        const statusInput = document.getElementById(fields.status || '');
        const counts = !statusInput || !['Deceased', 'Moved Out'].includes(statusInput.value);
        if (idInput && membersInput && idInput.defaultValue.trim() !== match.dataset.id) {
            membersInput.value = Number(match.dataset.members || 0) + (counts ? 1 : 0);
        }
    });
    const status = document.getElementById(fields.status || '');
    if (status) status.addEventListener('change', () => head.dispatchEvent(new Event('input')));
})();
</script>
    <?php
}

function pageTitle(string $title): void {
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' | Barangay Management System</title>';
}

function currentUserName(): string {
    $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    return $name !== '' ? $name : 'Administrator';
}

function currentUserRole(): string {
    $role = $_SESSION['role'] ?? 'admin';
    if ($role === 'admin') {
        return 'Barangay Admin';
    }
    if ($role === 'staff') {
        return 'Barangay Official';
    }
    if ($role === 'health_worker') {
        return 'Barangay Health Worker';
    }
    if ($role === 'security_force') {
        return 'Barangay Security Force';
    }
    return 'Resident';
}

function sidebarLinks(): array {
    $links = [
        'dashboard'    => ['href' => 'dashboard.php',    'icon' => 'fa-house',                'label' => 'Dashboard'],
        'residents'    => ['href' => 'residents.php',    'icon' => 'fa-users',                'label' => 'Residents'],
        'household'    => ['href' => 'household.php',    'icon' => 'fa-house-user',           'label' => 'Household'],
        'officials'    => ['href' => 'officials.php',    'icon' => 'fa-user-tie',             'label' => 'Officials'],
        'audit'        => ['href' => 'audit.php',         'icon' => 'fa-clock-rotate-left',    'label' => 'Audit History'],
        'certificates' => ['href' => 'certificates.php', 'icon' => 'fa-file-lines',           'label' => 'Online Certification'],
        'complaints'   => ['href' => 'complaints.php',   'icon' => 'fa-circle-exclamation',   'label' => 'Complaints &amp; Alerts'],
        'appointments' => ['href' => 'appointments.php', 'icon' => 'fa-calendar-check',       'label' => 'Appointments'],
        'emergency'    => ['href' => 'emergency.php',    'icon' => 'fa-bell',                 'label' => 'Emergency Alerts'],
        'settings'     => ['href' => 'settings.php',     'icon' => 'fa-gear',                 'label' => 'Settings'],
        'logout'       => ['href' => 'logout.php',       'icon' => 'fa-right-from-bracket',   'label' => 'Logout'],
    ];

    $role = $_SESSION['role'] ?? '';
    if ($role === 'staff') {
        unset($links['household'], $links['officials'], $links['audit'], $links['emergency'], $links['settings']);
    } elseif ($role === 'health_worker') {
        $links = array_intersect_key($links, array_flip(['dashboard', 'residents', 'logout']));
    } elseif ($role === 'security_force') {
        $links = array_intersect_key($links, array_flip(['dashboard', 'emergency', 'logout']));
    }

    return $links;
}

/**
 * Sidebar markup taken from the HTML designs.
 *
 * Every page renders the same sidebar-header markup so the logo, the font size
 * and the menu spacing stay identical across the whole system (dashboard.css is
 * the reference style). $variant is kept for backwards compatibility only.
 */
function renderSidebar(string $activePage, string $variant = 'panel'): void {
    if (($_SESSION['role'] ?? '') === 'resident') {
        $links = [
            'resident_dashboard' => ['href' => 'resident_dashboard.php', 'icon' => 'fa-house', 'label' => 'My Dashboard'],
            'resident_certificate' => ['href' => 'certificate_form.php', 'icon' => 'fa-file-lines', 'label' => 'Request Certificate'],
            'resident_appointment' => ['href' => 'appointment_form.php', 'icon' => 'fa-calendar-check', 'label' => 'Book Appointment'],
            'resident_complaint' => ['href' => 'complaint_form.php', 'icon' => 'fa-circle-exclamation', 'label' => 'File Complaint'],
            'resident_emergency' => ['href' => 'emergency_report.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Report Emergency'],
            'logout' => ['href' => 'logout.php', 'icon' => 'fa-right-from-bracket', 'label' => 'Logout'],
        ];
    } else {
        $links = sidebarLinks();
    }

    echo '<div class="sidebar-backdrop" aria-hidden="true"></div>';
    echo '<aside class="sidebar" aria-label="Sidebar Navigation">';

    $sidebarLogo = barangayLogoPath();

    echo '<div class="sidebar-header">';
    echo '<img src="' . htmlspecialchars($sidebarLogo, ENT_QUOTES, 'UTF-8') . '" alt="Barangay Management System Logo" class="sidebar-logo">';
    echo '<h2>Barangay MS</h2>';
    echo '<p>Management System</p>';
    echo '</div>';
    echo '<nav aria-label="Main Navigation"><ul class="menu">';

    foreach ($links as $key => $link) {
        $isActive = $activePage === $key;
        echo '<li' . ($isActive ? ' class="active"' : '') . '>';
        $confirmLogout = $key === 'logout' ? ' data-confirm="Are you sure you want to log out?"' : '';
        echo '<a href="' . $link['href'] . '" title="' . htmlspecialchars(strip_tags($link['label']), ENT_QUOTES, 'UTF-8') . '"' . $confirmLogout . ($isActive ? ' aria-current="page"' : '') . '>';
        echo '<i class="fa-solid ' . $link['icon'] . '" aria-hidden="true"></i>';
        echo '<span>' . $link['label'] . '</span>';
        echo '</a>';
        echo '</li>';
    }

    echo '</ul></nav>';

    echo '</aside>';
}

/**
 * Live clock block shown on the left side of the notification bell.
 */
function liveClockMarkup(): string {
    return '<div class="live-clock"><i class="fa-solid fa-clock" aria-hidden="true"></i>'
         . '<div><h4 id="currentDate">Loading Date...</h4>'
         . '<small id="currentTime">Loading Time...</small></div></div>';
}

/**
 * Topbar markup taken from the HTML designs.
 *
 * $variant = 'panel'   -> topbar-right / topbar-actions + .admin block
 * $variant = 'compact' -> profile-section + .profile-logo block
 *
 * $options:
 *   'clock'      => true to show the live clock (dashboard.css / residents.css)
 *   'breadcrumb' => current page label for the breadcrumb trail
 *                   (household.css / certification.css)
 *   'actions'    => extra HTML rendered before the profile block
 */
function renderTopbar(string $title, string $subtitle, string $variant = 'panel', array $options = []): void {
    global $conn;
    $clock = !empty($options['clock']);
    $breadcrumb = $options['breadcrumb'] ?? '';
    $actions = $options['actions'] ?? '';
    $userName = htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8');
    $userRole = htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8');
    $profileLogo = barangayLogoPath();
    $notificationTotal = notificationCount($conn);
    $notificationLabel = $notificationTotal > 99 ? '99+' : (string)$notificationTotal;

    echo $variant === 'compact' ? '<div class="topbar">' : '<header class="topbar">';
    echo '<button type="button" class="mobile-sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="false"><span></span><span></span><span></span></button>';

    echo '<div>';
    if ($breadcrumb !== '') {
        echo '<nav class="breadcrumb" aria-label="Breadcrumb"><ol>';
        echo '<li><a href="dashboard.php">Dashboard</a></li>';
        echo '<li aria-current="page">' . htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8') . '</li>';
        echo '</ol></nav>';
    }
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div>';

    if ($variant === 'compact') {
        echo '<div class="profile-section">';
        if ($clock) {
            echo liveClockMarkup();
        }
        echo notificationBell($conn);
        echo $actions;
        echo '<img src="' . htmlspecialchars($profileLogo, ENT_QUOTES, 'UTF-8') . '" class="profile-logo" alt="Administrator Profile">';
        echo '<div><h5>' . $userName . '</h5><span>' . $userRole . '</span></div>';
        echo '</div>';
        echo '</div>';
        return;
    }

    echo '<div class="topbar-right topbar-actions' . ($clock ? ' topbar-actions-clock' : '') . '">';
    if ($clock) {
        echo liveClockMarkup();
    }
    echo $actions;
    echo notificationBell($conn);
    echo '<div class="admin"><img src="' . htmlspecialchars($profileLogo, ENT_QUOTES, 'UTF-8') . '" alt="Administrator Profile"><div><h4>' . $userName . '</h4><small>' . $userRole . '</small></div></div>';
    echo '</div>';
    echo '</header>';
}

/**
 * Shows the "record saved / updated / deleted" banner after a form redirect.
 * $labels overrides the wording per message key.
 */
function renderNotice(array $labels = []): void {
    $key = $_GET['msg'] ?? '';
    $defaults = [
        'created' => 'Record saved successfully.',
        'updated' => 'Record updated successfully.',
        'deleted' => 'Record deleted.',
        'missing' => 'That record no longer exists.',
    ];
    $text = $labels[$key] ?? $defaults[$key] ?? '';
    if ($text === '') {
        return;
    }
    renderToast($text, $key === 'missing' ? 'error' : 'success');
}

function renderFooterScripts(bool $withBootstrap = true): void {
    // Keep the page header (title, clock, notifications, profile) in view while scrolling. Not on phones, where it is tall.
    echo '<style>@media (min-width: 769px){.topbar{position:sticky;top:12px;z-index:40}}</style>';
    // Tablets / phones in "desktop site" mode: one collapsed icon sidebar for every page (page CSS disagreed here).
    echo '<style>@media (min-width: 769px) and (max-width: 992px){.wrapper{flex-direction:row!important}.sidebar{position:fixed!important;left:0;top:0;width:85px!important;height:100vh!important;padding:20px 10px!important}.sidebar-header h2,.sidebar-header p,.menu li a span{display:none!important}.sidebar-header img,.sidebar-logo{width:55px!important;height:55px!important}.menu li a{justify-content:center!important;gap:0!important;padding:14px 0!important}.main-content{margin-left:85px!important;width:calc(100% - 85px)!important}}</style>';
    echo '<style>.notification-wrap{position:relative;display:inline-flex}.notification-menu{position:absolute;z-index:100;top:calc(100% + 10px);right:0;width:320px;max-width:calc(100vw - 28px);background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.18);overflow:hidden;text-align:left}.notification-menu[hidden]{display:none}.notification-menu-title{padding:13px 16px;font-weight:700;color:#123f83;border-bottom:1px solid #eef1f5}.notification-item{display:flex;align-items:flex-start;gap:11px;padding:12px 15px;color:#27364a;text-decoration:none;border-bottom:1px solid #f0f2f5}.notification-item:hover{background:#f5f8ff}.notification-item>i{color:#0b4a9e;margin-top:3px;width:18px;text-align:center}.notification-item span{display:flex;flex-direction:column;gap:3px;min-width:0}.notification-item strong{font-size:13px}.notification-item small{color:#6b7280;font-size:11px}.notification-empty{padding:18px 16px;color:#6b7280;font-size:13px}@media(max-width:768px){.notification-menu{position:fixed;top:68px;right:12px}.notification-wrap{position:static}}</style>';
    echo '<style>.notification-btn{position:relative}</style>';
    echo '<link rel="stylesheet" href="' . asset('toast.css') . '">';
    echo '<style>@media (max-width: 768px) { html, body { max-width: 100%; overflow-x: hidden; } .wrapper { min-width: 0; } .sidebar { position: fixed !important; z-index: 20; left: 0; top: 0; width: 72px !important; height: 100vh !important; padding: 16px 8px !important; overflow: visible !important; } .sidebar-header { margin-bottom: 20px !important; } .sidebar-header img, .sidebar-logo { width: 48px !important; height: 48px !important; object-fit: cover; } .sidebar-header h2, .sidebar-header p, .menu li a span { display: none !important; } .menu li { margin: 6px 0 !important; } .menu li a { position: relative; justify-content: center !important; gap: 0 !important; padding: 13px 0 !important; } .menu li a i { margin: 0 !important; } .menu li a.sidebar-label-open span { display: block !important; position: absolute; left: 54px; top: 50%; transform: translateY(-50%); z-index: 30; width: max-content; max-width: 190px; padding: 9px 12px; border-radius: 8px; background: #123f83; color: #fff; box-shadow: 0 4px 14px rgba(0,0,0,.25); font-size: 13px; white-space: nowrap; } .main-content { min-width: 0 !important; margin-left: 72px !important; width: calc(100% - 72px) !important; padding: 12px !important; overflow-x: hidden !important; } .topbar { padding: 16px !important; margin-bottom: 16px !important; border-radius: 14px !important; } .topbar h1 { font-size: 25px !important; white-space: normal !important; } .topbar p { font-size: 13px; } .profile-section, .topbar-right { width: 100%; gap: 8px !important; flex-wrap: wrap; } .profile-section > div:last-child, .topbar-right > div:last-child { display: none; } .main-content .content-card { max-width: 100%; overflow: hidden; } .main-content table { min-width: 680px; } .main-content .table-responsive, .main-content table { overflow-x: auto; -webkit-overflow-scrolling: touch; } .main-content table { display: block; } .main-content table thead, .main-content table tbody { display: table; width: 100%; table-layout: auto; } } @media (max-width: 768px) and (orientation: landscape) { .sidebar { width: 220px !important; padding: 18px 14px !important; overflow-y: auto !important; } .sidebar-header img, .sidebar-logo { width: 64px !important; height: 64px !important; } .sidebar-header h2, .sidebar-header p, .menu li a span { display: block !important; } .sidebar-header h2 { font-size: 18px !important; } .sidebar-header p { font-size: 11px !important; } .menu li a { justify-content: flex-start !important; gap: 12px !important; padding: 9px 10px !important; font-size: 13px !important; } .menu li a i { width: 20px !important; } .main-content { margin-left: 220px !important; width: auto !important; margin-right: 0 !important; padding: 14px !important; } .topbar, .cards, .content, .panel, .chart-card, .summary { width: 100% !important; max-width: none !important; } .topbar { flex-direction: row !important; align-items: center !important; padding: 14px 18px !important; } .topbar h1 { font-size: 26px !important; } .topbar p { font-size: 12px !important; } .profile-section, .topbar-right { width: auto !important; flex-wrap: nowrap !important; } .profile-section > div:last-child, .topbar-right > div:last-child { display: block !important; } .main-content table { min-width: 0; } }</style>';
    echo '<style>@media(max-width:1200px){.topbar-right.topbar-actions-clock .live-clock{display:flex}}@media(max-width:768px){.topbar-right.topbar-actions-clock{width:100%;justify-content:flex-start!important;flex-wrap:nowrap!important;gap:8px!important}.topbar-right.topbar-actions-clock .live-clock{flex:1 1 auto;min-width:0;padding:8px 10px;gap:8px}.topbar-right.topbar-actions-clock .live-clock>div{min-width:0}.topbar-right.topbar-actions-clock .live-clock i{font-size:20px}.topbar-right.topbar-actions-clock .live-clock h4{font-size:12px;line-height:1.25;overflow-wrap:anywhere}.topbar-right.topbar-actions-clock .live-clock small{font-size:12px;white-space:nowrap}.topbar-right.topbar-actions-clock>.notification-wrap,.topbar-right.topbar-actions-clock>.admin{flex:0 0 42px}.topbar-right.topbar-actions-clock>.admin{display:flex!important}.topbar-right.topbar-actions-clock>.admin img,.topbar-right.topbar-actions-clock .notification-btn{width:42px;height:42px}.topbar-right.topbar-actions-clock>.admin img{object-fit:cover}}</style>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".notification-toggle").forEach(function(button){button.addEventListener("click",function(event){event.stopPropagation();var menu=button.nextElementSibling;var isOpen=!menu.hasAttribute("hidden");document.querySelectorAll(".notification-menu").forEach(function(item){item.setAttribute("hidden","")});if(!isOpen){menu.removeAttribute("hidden");button.setAttribute("aria-expanded","true")}else{button.setAttribute("aria-expanded","false")}})});document.addEventListener("click",function(){document.querySelectorAll(".notification-menu").forEach(function(menu){menu.setAttribute("hidden","")});document.querySelectorAll(".notification-toggle").forEach(function(button){button.setAttribute("aria-expanded","false")})});});</script>';
    echo '<style>.mobile-sidebar-toggle{display:none;position:fixed;left:16px;top:18px;z-index:30;width:46px;height:46px;border:none;border-radius:12px;background:#0B4A9E;box-shadow:0 10px 20px rgba(11,74,158,.25);cursor:pointer;justify-content:center;align-items:center;flex-direction:column;gap:5px}.mobile-sidebar-toggle span{display:block;width:22px;height:2px;border-radius:2px;background:#fff;transition:all .2s ease}.mobile-sidebar-toggle[aria-expanded="true"] span:nth-child(1){transform:translateY(7px) rotate(45deg)}.mobile-sidebar-toggle[aria-expanded="true"] span:nth-child(2){opacity:0}.mobile-sidebar-toggle[aria-expanded="true"] span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}@media (max-width: 768px){html,body{max-width:100%;overflow-x:hidden}.mobile-sidebar-toggle{display:flex!important}.sidebar-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:15}.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}.sidebar{position:fixed!important;z-index:20;left:0;top:0;width:280px!important;height:100vh!important;padding:22px 16px!important;overflow-y:auto;overflow-x:hidden;transform:translateX(-110%);transition:transform .25s ease}.sidebar-open .sidebar{transform:translateX(0)}.main-content{margin-left:0!important;width:100%!important;padding:12px!important}.topbar{padding:16px!important;margin-bottom:16px!important;border-radius:14px!important}.topbar h1{font-size:25px!important;white-space:normal!important}.profile-section,.topbar-right{width:100%;gap:8px!important;flex-wrap:wrap;justify-content:flex-end!important}.topbar-right>.btn-primary{margin-right:auto}.topbar>div:first-of-type{padding-left:48px;min-height:46px}.profile-section>div:last-child,.topbar-right>div:last-child{display:none}.main-content .content-card{max-width:100%;overflow:hidden}.main-content table{min-width:680px}.main-content .table-responsive,.main-content table{overflow-x:auto;-webkit-overflow-scrolling:touch}.main-content table{display:block}.main-content table thead,.main-content table tbody{display:table;width:100%;table-layout:auto}}@media (min-width: 769px){.mobile-sidebar-toggle,.sidebar-backdrop{display:none!important}}</style>';
    echo '<style>@media (max-width: 768px){.sidebar{display:block!important}.sidebar-header h2,.sidebar-header p{display:block!important}.menu li a{justify-content:flex-start!important;gap:14px!important;padding:12px 16px!important}.menu li a span{display:inline!important}.live-clock{display:flex!important}.admin{display:flex!important}.admin>div{display:none!important}}</style>';
    echo '<script>(function(){var timeout=1800000;var timer;function resetTimer(){window.clearTimeout(timer);timer=window.setTimeout(function(){window.location.href="logout.php?expired=1"},timeout)}["click","keydown","mousemove","scroll","touchstart"].forEach(function(eventName){document.addEventListener(eventName,resetTimer,{passive:true})});resetTimer()})();</script>';
    if ($withBootstrap) {
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    }
    echo '<script src="' . asset('toast.js') . '"></script>';
    echo '<script src="' . asset('scripts.js') . '"></script>';
    echo '<script src="' . asset('password-peek.js') . '"></script>';
}
