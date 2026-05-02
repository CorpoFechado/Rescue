<?php
// includes/header_official.php
// Usage: require_once __DIR__ . '/../../includes/header_official.php';
// Before including this file, define $activePage variable:
// $activePage = 'dashboard'; // or 'residents', 'evacuation', 'alerts', 'hazard', 'reports', 'settings'

$activePage = $activePage ?? '';

// Fetch official name for display
$officialName = 'Official';
$officialPosition = 'Barangay Official';
if (isset($conn) && isset($_SESSION['user_id'])) {
    $stmtOff = $conn->prepare("SELECT first_name, last_name, position FROM officials WHERE user_id = ?");
    $stmtOff->execute([$_SESSION['user_id']]);
    $officialData = $stmtOff->fetch();
    if ($officialData) {
        $officialName     = htmlspecialchars($officialData['first_name'] . ' ' . $officialData['last_name']);
        $officialPosition = htmlspecialchars($officialData['position']);
    }
}

// Initials for avatar
$nameParts = explode(' ', $officialName);
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Unread alerts count for badge
$unreadCount = 0;
if (isset($conn)) {
    $stmtUnread = $conn->prepare("SELECT COUNT(*) FROM alerts WHERE is_active = 1");
    $stmtUnread->execute();
    $unreadCount = (int) $stmtUnread->fetchColumn();
}

$navItems = [
    'dashboard'       => ['icon' => 'bi-speedometer2',              'label' => 'Dashboard'],
    'residents'       => ['icon' => 'bi-people-fill',               'label' => 'Residents Status'],
    'evacuation'      => ['icon' => 'bi-building-fill',             'label' => 'Evacuation Centers'],
    'alerts'          => ['icon' => 'bi-bell-fill',                 'label' => 'Alerts & Announcements'],
    'hazard'          => ['icon' => 'bi-map-fill',                  'label' => 'Hazard Map'],
    'reports'         => ['icon' => 'bi-file-earmark-bar-graph-fill','label' => 'Reports'],
    'user_management' => ['icon' => 'bi-person-fill-gear',          'label' => 'User Management'],  // ← add
    'settings'        => ['icon' => 'bi-gear-fill',                 'label' => 'Settings'],
];

$navLinks = [
    'dashboard'       => BASE_URL . '/modules/official/dashboard.php',
    'residents'       => BASE_URL . '/modules/official/residents.php',
    'evacuation'      => BASE_URL . '/modules/official/evacuation_centers.php',
    'alerts'          => BASE_URL . '/modules/official/alerts.php',
    'hazard'          => BASE_URL . '/modules/official/hazard_map.php',
    'reports'         => BASE_URL . '/modules/official/reports.php',
    'user_management' => BASE_URL . '/modules/official/user_management.php',  // ← add
    'settings'        => BASE_URL . '/modules/official/settings.php',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Officials Portal' ?> &mdash; RESCUE</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leaflet.css">
    <?php if (isset($extraStyles)) echo $extraStyles; ?>
</head>
<body>

<div class="officials-layout">

    <!-- ── Sidebar overlay (mobile) ── -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ── Sidebar ── -->
    <aside class="officials-sidebar" id="officialsSidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-shield-fill-exclamation"></i>
            </div>
            <div>
                <div class="brand-name">RESCUE</div>
                <div class="brand-sub">Officials Portal</div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
            <a href="<?= $navLinks[$key] ?>"
               class="<?= $activePage === $key ? 'active' : '' ?>">
                <i class="bi <?= $item['icon'] ?>"></i>
                <?= $item['label'] ?>
                <?php if ($key === 'alerts' && $unreadCount > 0): ?>
                    <span style="margin-left:auto; background:var(--rescue-red); color:#fff; font-size:0.65rem; font-weight:700; padding:2px 7px; border-radius:20px;">
                        <?= $unreadCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- Exit -->
        <div style="padding:0.75rem 0; border-top:1px solid rgba(255,255,255,0.08);">
            <a href="#" onclick="confirmLogout(event)"
            style="display:flex; align-items:center; gap:10px; padding:0.65rem 1.25rem; color:rgba(255,255,255,0.5); text-decoration:none; font-size:0.85rem; font-weight:500; transition:color 0.15s;"
            onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.5)'">
                <i class="bi bi-box-arrow-left" style="font-size:1rem; width:18px; text-align:center;"></i>
                Exit
            </a>
        </div>

    </aside>

    <!-- ── Main area ── -->
    <div class="officials-main">

        <!-- Top bar -->
        <div class="officials-topbar">

            <!-- Hamburger (mobile) -->
            <button id="sidebarToggle"
                style="display:none; background:none; border:none; cursor:pointer; padding:4px; color:var(--rescue-text); font-size:1.3rem; margin-right:8px;">
                <i class="bi bi-list"></i>
            </button>

            <!-- Search -->
            <div style="flex:1; max-width:380px;">
                <div style="position:relative;">
                    <i class="bi bi-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--rescue-muted); font-size:0.85rem;"></i>
                    <input type="text" placeholder="Search residents, alerts..."
                        style="width:100%; padding:0.5rem 0.75rem 0.5rem 2rem; border:1.5px solid var(--rescue-border); border-radius:10px; font-size:0.83rem; font-family:inherit; background:#FAFAFA; color:var(--rescue-text);"
                        onfocus="this.style.borderColor='var(--rescue-red)'; this.style.boxShadow='0 0 0 3px rgba(192,57,43,0.1)'"
                        onblur="this.style.borderColor='var(--rescue-border)'; this.style.boxShadow='none'">
                </div>
            </div>

            <!-- Right side -->
            <div style="display:flex; align-items:center; gap:12px; margin-left:auto;">

                <!-- Notification bell -->
                <div style="position:relative;">
                    <button style="background:none; border:none; cursor:pointer; padding:6px; color:var(--rescue-text); font-size:1.1rem; position:relative;">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                        <span style="position:absolute; top:2px; right:2px; width:8px; height:8px; background:var(--rescue-red); border-radius:50%; border:2px solid #fff;"></span>
                        <?php endif; ?>
                    </button>
                </div>

                <!-- User info -->
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:34px; height:34px; border-radius:50%; background:var(--rescue-red); display:flex; align-items:center; justify-content:center; color:#fff; font-size:0.8rem; font-weight:700; flex-shrink:0;">
                        <?= $initials ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div style="font-size:0.85rem; font-weight:700; color:var(--rescue-text); line-height:1.2;">
                            <?= $officialName ?>
                        </div>
                        <div style="font-size:0.72rem; color:var(--rescue-muted);">
                            <?= $officialPosition ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Page content starts here -->
        <div class="page-content">