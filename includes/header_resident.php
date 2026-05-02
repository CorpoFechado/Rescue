<?php
// includes/header_resident.php
// Usage: require_once __DIR__ . '/../../includes/header_resident.php';
// Define $activePage before including:
// $activePage = 'dashboard'; // dashboard, alerts, map, contacts, profile

$activePage = $activePage ?? 'dashboard';

// Fetch resident info
$residentName     = 'Resident';
$residentInitials = 'R';
$residentData     = null;

if (isset($conn) && isset($_SESSION['user_id'])) {
    $stmtRes = $conn->prepare("
        SELECT r.first_name, r.last_name, r.phone,
               r.house_no, r.street, r.area,
               sr.status AS current_status, sr.reported_at
        FROM residents r
        LEFT JOIN safety_reports sr ON sr.report_id = (
            SELECT MAX(sr2.report_id) FROM safety_reports sr2
            WHERE sr2.resident_id = r.resident_id
        )
        WHERE r.user_id = ?
    ");
    $stmtRes->execute([$_SESSION['user_id']]);
    $residentData = $stmtRes->fetch();

    if ($residentData) {
        $residentName     = htmlspecialchars($residentData['first_name'] . ' ' . $residentData['last_name']);
        $residentInitials = strtoupper(
            substr($residentData['first_name'], 0, 1) .
            substr($residentData['last_name'],  0, 1)
        );
    }
}

// Unread/active alerts count
$alertCount = 0;
if (isset($conn)) {
    $alertCount = (int) $conn->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?? 'RESCUE' ?> &mdash; RESCUE</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons/bootstrap-icons.css">
    <?php if (isset($extraStyles)) echo $extraStyles; ?>

    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #F5F5F5;
            color: var(--rescue-text);
            margin: 0;
            padding: 0;
            /* Reserve space for bottom tab bar */
            padding-bottom: 70px;
        }

        /* ── Top navbar ── */
        .resident-navbar {
            background: var(--rescue-white);
            border-bottom: 1px solid var(--rescue-border);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 0 1rem;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .resident-navbar-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .resident-navbar-brand .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--rescue-red);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 15px;
            flex-shrink: 0;
        }

        .resident-navbar-brand .brand-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--rescue-text);
            line-height: 1;
        }

        .resident-navbar-brand .brand-sub {
            font-size: 0.65rem;
            color: var(--rescue-muted);
            margin-top: 1px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .navbar-alert-btn {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #F5F5F5;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--rescue-text);
            font-size: 1rem;
            text-decoration: none;
            transition: background 0.15s;
        }

        .navbar-alert-btn:hover { background: #EBEBEB; }

        .alert-dot {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 8px;
            height: 8px;
            background: var(--rescue-red);
            border-radius: 50%;
            border: 2px solid #fff;
        }

        .navbar-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--rescue-red);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            cursor: pointer;
            text-decoration: none;
        }

        /* ── Page content area ── */
        .resident-content {
            max-width: 600px;
            margin: 0 auto;
            padding: 1rem 1rem 1.5rem;
        }

        /* Full-width variant for map pages */
        .resident-content-full {
            padding: 0;
        }

        /* ── Bottom tab bar ── */
        .bottom-tab-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            background: var(--rescue-white);
            border-top: 1px solid var(--rescue-border);
            display: flex;
            align-items: stretch;
            z-index: 200;
            box-shadow: 0 -2px 12px rgba(0,0,0,0.06);
        }

        .tab-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            text-decoration: none;
            color: var(--rescue-muted);
            font-size: 0.62rem;
            font-weight: 600;
            padding: 6px 4px;
            transition: color 0.15s;
            position: relative;
            cursor: pointer;
            border: none;
            background: none;
            font-family: inherit;
        }

        .tab-item i {
            font-size: 1.25rem;
            line-height: 1;
        }

        .tab-item.active {
            color: var(--rescue-red);
        }

        .tab-item.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 3px;
            background: var(--rescue-red);
            border-radius: 0 0 4px 4px;
        }

        .tab-badge {
            position: absolute;
            top: 6px;
            right: calc(50% - 18px);
            background: var(--rescue-red);
            color: #fff;
            font-size: 0.55rem;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 10px;
            min-width: 14px;
            text-align: center;
            line-height: 1.4;
        }

        /* ── Page header ── */
        .page-header {
            margin-bottom: 1.25rem;
        }

        .page-header h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--rescue-text);
            margin: 0 0 3px;
        }

        .page-header p {
            font-size: 0.82rem;
            color: var(--rescue-muted);
            margin: 0;
        }

        /* ── Cards ── */
        .r-card {
            background: var(--rescue-white);
            border: 1px solid var(--rescue-border);
            border-radius: 14px;
            padding: 1.1rem 1.1rem;
            margin-bottom: 1rem;
        }

        .r-card-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--rescue-text);
            margin-bottom: 0.875rem;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .r-card-title i { color: var(--rescue-red); }

        /* ── Status badges ── */
        .badge-safe-home  { background:#EAFAF1; color:#1E8449; font-size:.75rem; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
        .badge-evacuated  { background:#EBF5FB; color:#2980B9; font-size:.75rem; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
        .badge-need-help  { background:var(--rescue-red-light); color:var(--rescue-red); font-size:.75rem; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }
        .badge-unknown    { background:#F2F3F4; color:var(--rescue-muted); font-size:.75rem; font-weight:700; padding:4px 10px; border-radius:20px; display:inline-flex; align-items:center; gap:5px; }

        /* ── Feedback ── */
        .alert-success {
            background:#EAFAF1; border:1px solid #A9DFBF; color:#1E8449;
            border-radius:10px; padding:0.75rem 1rem; margin-bottom:1rem; font-size:0.85rem;
            display:flex; align-items:center; gap:8px;
        }

        .alert-error {
            background:var(--rescue-red-light); border:1px solid #F1948A; color:var(--rescue-red);
            border-radius:10px; padding:0.75rem 1rem; margin-bottom:1rem; font-size:0.85rem;
            display:flex; align-items:center; gap:8px;
        }

        /* ── Modals ── */
        .r-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }

        .r-modal-backdrop.open { display: flex; }

        /* Bottom sheet style on mobile */
        .r-modal {
            background: #fff;
            border-radius: 20px 20px 0 0;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding-bottom: env(safe-area-inset-bottom, 16px);
        }

        .r-modal-handle {
            width: 36px;
            height: 4px;
            background: #E0E0E0;
            border-radius: 2px;
            margin: 10px auto 0;
        }

        .r-modal-header {
            padding: 1rem 1.25rem 0.875rem;
            border-bottom: 1px solid var(--rescue-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .r-modal-header h5 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            color: var(--rescue-text);
        }

        .r-modal-body   { padding: 1.1rem 1.25rem; }
        .r-modal-footer {
            padding: 0.875rem 1.25rem;
            border-top: 1px solid var(--rescue-border);
            display: flex;
            gap: 10px;
        }

        .btn-r-cancel {
            flex: 1;
            padding: 0.75rem;
            border: 1.5px solid var(--rescue-border);
            border-radius: 12px;
            background: #fff;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--rescue-text);
            cursor: pointer;
            font-family: inherit;
            text-align: center;
        }

        .btn-r-submit {
            flex: 2;
            padding: 0.75rem;
            border: none;
            border-radius: 12px;
            background: var(--rescue-red);
            font-size: 0.875rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            font-family: inherit;
            text-align: center;
            transition: background 0.15s;
        }

        .btn-r-submit:hover { background: var(--rescue-red-dark); }
    </style>
</head>
<body>

<!-- ── Top Navbar ── -->
<nav class="resident-navbar">
    <a href="<?= BASE_URL ?>/modules/resident/dashboard.php" class="resident-navbar-brand">
        <div class="brand-icon">
            <i class="bi bi-shield-fill-exclamation"></i>
        </div>
        <div>
            <div class="brand-name">RESCUE</div>
            <div class="brand-sub">Resident Portal</div>
        </div>
    </a>

    <div class="navbar-right">
        <!-- Alert bell -->
        <a href="<?= BASE_URL ?>/modules/resident/alerts.php" class="navbar-alert-btn">
            <i class="bi bi-bell"></i>
            <?php if ($alertCount > 0): ?>
            <span class="alert-dot"></span>
            <?php endif; ?>
        </a>

        <!-- Avatar / Profile -->
        <a href="<?= BASE_URL ?>/modules/resident/profile.php" class="navbar-avatar">
            <?= $residentInitials ?>
        </a>
        
        <!-- Logout -->
        <button onclick="openLogoutModal()"
            style="width:36px;height:36px;border-radius:50%;background:#F5F5F5;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--rescue-muted);font-size:1rem;transition:background 0.15s;"
            onmouseover="this.style.background='#EBEBEB'"
            onmouseout="this.style.background='#F5F5F5'">
            <i class="bi bi-box-arrow-right"></i>
        </button>
    </div>
</nav>

<!-- ── Page content starts ── -->