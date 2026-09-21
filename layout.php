<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
applyNoStoreHeaders();
restorePersistentAuthSession($conn);
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
$residentPages = ['resident_dashboard.php', 'certificate_form.php', 'appointment_form.php', 'complaint_form.php', 'emergency_report.php', 'certificate_generate.php', 'indigency.php', 'residency.php', 'barangay_clearance.php', 'business.php', 'good_moral.php', 'logout.php'];
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

$rolePages = [
    'staff' => [
        'dashboard.php', 'residents.php', 'resident_form.php',
        'certificates.php', 'certificate_form.php', 'certificate_generate.php', 'indigency.php', 'residency.php', 'barangay_clearance.php', 'business.php', 'good_moral.php',
        'complaints.php', 'complaint_form.php', 'appointments.php',
        'appointment_form.php', 'logout.php',
    ],
    'health_worker' => ['dashboard.php', 'residents.php', 'logout.php'],
    'security_force' => ['dashboard.php', 'emergency.php', 'logout.php'],
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

function notificationCount(mysqli $conn): int {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'resident') {
        $resident = currentResident($conn);
        if (!$resident) {
            return 0;
        }
        $residentId = (int)$resident['id'];
        $stmt = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM certificates WHERE resident_id = ? AND status IN ('Approved', 'Released')) +
            (SELECT COUNT(*) FROM appointments WHERE resident_id = ? AND status = 'Approved') +
            (SELECT COUNT(*) FROM complaints WHERE resident_id = ? AND status IN ('Ongoing', 'Resolved'))");
        $stmt->bind_param('iii', $residentId, $residentId, $residentId);
    } else {
        $stmt = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM certificates WHERE status = 'Pending') +
            (SELECT COUNT(*) FROM appointments WHERE status = 'Pending') +
            (SELECT COUNT(*) FROM complaints WHERE status = 'Pending') +
            (SELECT COUNT(*) FROM complaints WHERE status = 'Ongoing')");
    }
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
        $stmt = $conn->prepare("SELECT certificate_type, status FROM certificates WHERE resident_id = ? AND status IN ('Approved', 'Released') ORDER BY updated_at DESC LIMIT 5");
        $stmt->bind_param('i', $residentId);
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
        $queries = [
            ['sql' => "SELECT COUNT(*) AS total FROM certificates WHERE status = 'Pending'", 'text' => 'Pending certificate requests', 'detail' => 'Review certificate approvals', 'href' => 'certificates.php', 'icon' => 'fa-file-lines'],
            ['sql' => "SELECT COUNT(*) AS total FROM appointments WHERE status = 'Pending'", 'text' => 'Pending appointments', 'detail' => 'Review appointment requests', 'href' => 'appointments.php', 'icon' => 'fa-calendar-check'],
            ['sql' => "SELECT COUNT(*) AS total FROM complaints WHERE status = 'Pending'", 'text' => 'Pending complaints', 'detail' => 'Review complaint reports', 'href' => 'complaints.php', 'icon' => 'fa-circle-exclamation'],
            ['sql' => "SELECT COUNT(*) AS total FROM complaints WHERE status = 'Ongoing'", 'text' => 'Ongoing complaints', 'detail' => 'View active complaints', 'href' => 'complaints.php', 'icon' => 'fa-triangle-exclamation'],
        ];
        foreach ($queries as $query) {
            $result = $conn->query($query['sql']);
            $total = (int)($result ? ($result->fetch_assoc()['total'] ?? 0) : 0);
            if ($total > 0) {
                $query['text'] = $total . ' ' . $query['text'];
                $items[] = $query;
            }
        }
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
        echo '<a href="' . $link['href'] . '" title="' . htmlspecialchars(strip_tags($link['label']), ENT_QUOTES, 'UTF-8') . '"' . ($isActive ? ' aria-current="page"' : '') . '>';
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

    echo '<div class="topbar-right topbar-actions">';
    if ($clock) {
        echo liveClockMarkup();
    }
    echo notificationBell($conn);
    echo $actions;
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
    $class = $key === 'missing' ? 'notice notice-error' : 'notice';
    echo '<div class="' . $class . '" role="status">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
}

function renderFooterScripts(bool $withBootstrap = true): void {
    echo '<style>.notification-wrap{position:relative;display:inline-flex}.notification-menu{position:absolute;z-index:100;top:calc(100% + 10px);right:0;width:320px;max-width:calc(100vw - 28px);background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.18);overflow:hidden;text-align:left}.notification-menu[hidden]{display:none}.notification-menu-title{padding:13px 16px;font-weight:700;color:#123f83;border-bottom:1px solid #eef1f5}.notification-item{display:flex;align-items:flex-start;gap:11px;padding:12px 15px;color:#27364a;text-decoration:none;border-bottom:1px solid #f0f2f5}.notification-item:hover{background:#f5f8ff}.notification-item>i{color:#0b4a9e;margin-top:3px;width:18px;text-align:center}.notification-item span{display:flex;flex-direction:column;gap:3px;min-width:0}.notification-item strong{font-size:13px}.notification-item small{color:#6b7280;font-size:11px}.notification-empty{padding:18px 16px;color:#6b7280;font-size:13px}@media(max-width:768px){.notification-menu{position:fixed;top:68px;right:12px}.notification-wrap{position:static}}</style>';
    echo '<style>@media (max-width: 768px) { html, body { max-width: 100%; overflow-x: hidden; } .wrapper { min-width: 0; } .sidebar { position: fixed !important; z-index: 20; left: 0; top: 0; width: 72px !important; height: 100vh !important; padding: 16px 8px !important; overflow: visible !important; } .sidebar-header { margin-bottom: 20px !important; } .sidebar-header img, .sidebar-logo { width: 48px !important; height: 48px !important; object-fit: cover; } .sidebar-header h2, .sidebar-header p, .menu li a span { display: none !important; } .menu li { margin: 6px 0 !important; } .menu li a { position: relative; justify-content: center !important; gap: 0 !important; padding: 13px 0 !important; } .menu li a i { margin: 0 !important; } .menu li a.sidebar-label-open span { display: block !important; position: absolute; left: 54px; top: 50%; transform: translateY(-50%); z-index: 30; width: max-content; max-width: 190px; padding: 9px 12px; border-radius: 8px; background: #123f83; color: #fff; box-shadow: 0 4px 14px rgba(0,0,0,.25); font-size: 13px; white-space: nowrap; } .main-content { min-width: 0 !important; margin-left: 72px !important; width: calc(100% - 72px) !important; padding: 12px !important; overflow-x: hidden !important; } .topbar { padding: 16px !important; margin-bottom: 16px !important; border-radius: 14px !important; } .topbar h1 { font-size: 25px !important; white-space: normal !important; } .topbar p { font-size: 13px; } .profile-section, .topbar-right { width: 100%; gap: 8px !important; flex-wrap: wrap; } .profile-section > div:last-child, .topbar-right > div:last-child { display: none; } .main-content .content-card { max-width: 100%; overflow: hidden; } .main-content table { min-width: 680px; } .main-content .table-responsive, .main-content table { overflow-x: auto; -webkit-overflow-scrolling: touch; } .main-content table { display: block; } .main-content table thead, .main-content table tbody { display: table; width: 100%; table-layout: auto; } } @media (max-width: 768px) and (orientation: landscape) { .sidebar { width: 220px !important; padding: 18px 14px !important; overflow-y: auto !important; } .sidebar-header img, .sidebar-logo { width: 64px !important; height: 64px !important; } .sidebar-header h2, .sidebar-header p, .menu li a span { display: block !important; } .sidebar-header h2 { font-size: 18px !important; } .sidebar-header p { font-size: 11px !important; } .menu li a { justify-content: flex-start !important; gap: 12px !important; padding: 9px 10px !important; font-size: 13px !important; } .menu li a i { width: 20px !important; } .main-content { margin-left: 220px !important; width: auto !important; margin-right: 0 !important; padding: 14px !important; } .topbar, .cards, .content, .panel, .chart-card, .summary { width: 100% !important; max-width: none !important; } .topbar { flex-direction: row !important; align-items: center !important; padding: 14px 18px !important; } .topbar h1 { font-size: 26px !important; } .topbar p { font-size: 12px !important; } .profile-section, .topbar-right { width: auto !important; flex-wrap: nowrap !important; } .profile-section > div:last-child, .topbar-right > div:last-child { display: block !important; } .main-content table { min-width: 0; } }</style>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".notification-toggle").forEach(function(button){button.addEventListener("click",function(event){event.stopPropagation();var menu=button.nextElementSibling;var isOpen=!menu.hasAttribute("hidden");document.querySelectorAll(".notification-menu").forEach(function(item){item.setAttribute("hidden","")});if(!isOpen){menu.removeAttribute("hidden");button.setAttribute("aria-expanded","true")}else{button.setAttribute("aria-expanded","false")}})});document.addEventListener("click",function(){document.querySelectorAll(".notification-menu").forEach(function(menu){menu.setAttribute("hidden","")});document.querySelectorAll(".notification-toggle").forEach(function(button){button.setAttribute("aria-expanded","false")})});document.querySelectorAll(".sidebar a").forEach(function(link){var pressTimer;var hideTimer;var longPressed=false;function clearPress(){window.clearTimeout(pressTimer)}function hideLabel(){window.clearTimeout(hideTimer);hideTimer=window.setTimeout(function(){link.classList.remove("sidebar-label-open")},1800)}link.addEventListener("pointerdown",function(){longPressed=false;clearPress();pressTimer=window.setTimeout(function(){longPressed=true;link.classList.add("sidebar-label-open");hideLabel()},450)});link.addEventListener("pointerup",function(){clearPress();if(!longPressed){link.classList.remove("sidebar-label-open")}else{hideLabel()}});link.addEventListener("pointercancel",function(){clearPress();link.classList.remove("sidebar-label-open")});link.addEventListener("pointerleave",function(){clearPress()});link.addEventListener("click",function(event){if(longPressed){event.preventDefault();longPressed=false}})})});</script>';
    echo '<script>(function(){var timeout=1800000;var timer;function resetTimer(){window.clearTimeout(timer);timer=window.setTimeout(function(){window.location.href="logout.php?expired=1"},timeout)}["click","keydown","mousemove","scroll","touchstart"].forEach(function(eventName){document.addEventListener(eventName,resetTimer,{passive:true})});resetTimer()})();</script>';
    if ($withBootstrap) {
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    }
    echo '<script src="' . asset('scripts.js') . '"></script>';
}
