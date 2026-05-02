<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/config/database.php';

// ── Latest Announcements (from announcements table) ───────────────────────
$stmtAnn = $conn->prepare("
    SELECT a.title, a.content, a.category, a.is_pinned, a.created_at,
           o.first_name, o.last_name
    FROM announcements a
    JOIN officials o ON o.official_id = a.created_by
    WHERE a.is_active = 1
    ORDER BY a.is_pinned DESC, a.created_at DESC
    LIMIT 3
");
$stmtAnn->execute();
$announcements = $stmtAnn->fetchAll();

// ── Active Alerts (all types, with severity) ──────────────────────────────
$stmtAlerts = $conn->prepare("
    SELECT title, message, type, severity, created_at
    FROM alerts
    WHERE is_active = 1
    ORDER BY
        CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
        created_at DESC
    LIMIT 5
");
$stmtAlerts->execute();
$activeAlerts = $stmtAlerts->fetchAll();

// ── Evacuation Centers ────────────────────────────────────────────────────
$stmtCenters = $conn->prepare("
    SELECT ec.name, ec.capacity, ec.is_active,
           COALESCE((
               SELECT COUNT(*)
               FROM safety_reports sr
               JOIN residents r ON sr.resident_id = r.resident_id
               WHERE sr.status = 'evacuated'
                 AND sr.report_id = (
                     SELECT MAX(sr2.report_id) FROM safety_reports sr2
                     WHERE sr2.resident_id = r.resident_id
                 )
           ), 0) AS occupancy
    FROM evacuation_centers ec
    WHERE ec.is_active = 1
    ORDER BY ec.name ASC
    LIMIT 3
");
$stmtCenters->execute();
$centers = $stmtCenters->fetchAll();

// ── Emergency Contacts ────────────────────────────────────────────────────
$stmtContacts = $conn->prepare("
    SELECT name, role, phone FROM emergency_contacts WHERE is_active = 1 LIMIT 4
");
$stmtContacts->execute();
$contacts = $stmtContacts->fetchAll();

// ── Active alert count for banner ────────────────────────────────────────
$activeAlertCount = (int)$conn->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1")->fetchColumn();

// ── Helpers ───────────────────────────────────────────────────────────────
function alertTypeBadge(string $type): string {
    $map = [
        'flood'     => ['label' => 'FLOOD',     'bg' => '#EBF5FB', 'color' => '#1A5276'],
        'fire'      => ['label' => 'FIRE',      'bg' => '#FDEDEC', 'color' => '#922B21'],
        'landslide' => ['label' => 'LANDSLIDE', 'bg' => '#FEF9E7', 'color' => '#784212'],
        'general'   => ['label' => 'GENERAL',   'bg' => '#EAFAF1', 'color' => '#1E8449'],
    ];
    $t = $map[$type] ?? $map['general'];
    return '<span style="background:'.$t['bg'].';color:'.$t['color'].';font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;">'.$t['label'].'</span>';
}

function alertSeverityBadge(string $severity): string {
    $map = [
        'high'   => ['label' => 'HIGH',   'bg' => '#FADBD8', 'color' => '#C0392B'],
        'medium' => ['label' => 'MEDIUM', 'bg' => '#FDEBD0', 'color' => '#D68910'],
        'low'    => ['label' => 'LOW',    'bg' => '#EAFAF1', 'color' => '#1E8449'],
    ];
    $s = $map[$severity] ?? $map['low'];
    return '<span style="background:'.$s['bg'].';color:'.$s['color'].';font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px;">'.$s['label'].'</span>';
}

function alertDotColor(string $severity): string {
    return match($severity) {
        'high'   => '#C0392B',
        'medium' => '#D68910',
        default  => '#1E8449',
    };
}

function categoryBadge(string $category, bool $pinned = false): string {
    $map = [
        'general'        => ['label' => 'General',        'bg' => '#EAFAF1', 'color' => '#1E8449'],
        'health'         => ['label' => 'Health',         'bg' => '#F5EEF8', 'color' => '#6C3483'],
        'relief'         => ['label' => 'Relief Goods',   'bg' => '#EBF5FB', 'color' => '#1A5276'],
        'infrastructure' => ['label' => 'Infrastructure', 'bg' => '#FEF9E7', 'color' => '#784212'],
        'weather'        => ['label' => 'Weather',        'bg' => '#EAFAF1', 'color' => '#117A65'],
    ];
    $c = $map[$category] ?? $map['general'];
    $out = '<span style="background:'.$c['bg'].';color:'.$c['color'].';font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;">'.$c['label'].'</span>';
    if ($pinned) $out .= ' <span style="background:#FEF9E7;color:#B7770D;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;"><i class="bi bi-pin-angle-fill"></i> Pinned</span>';
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RESCUE &mdash; Barangay 12 Emergency Management</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leaflet.css">
    <style>
        /* ── Hero ── */
        .hero {
            background: linear-gradient(135deg, #1a0a09 0%, #3d1410 30%, #6b2318 55%, #2d1a3a 80%, #0f0a1a 100%);
            padding: 5rem 1.5rem 6rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(176,52,40,0.28) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(100,30,80,0.2) 0%, transparent 50%),
                radial-gradient(ellipse at 60% 85%, rgba(50,20,100,0.15) 0%, transparent 50%);
            pointer-events: none;
        }
        .hero::after {
            content: '';
            position: absolute;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
            top: -140px; right: -100px;
            pointer-events: none;
        }
        .hero-content { position: relative; z-index: 1; max-width: 600px; margin: 0 auto; }
        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18);
            color: rgba(255,255,255,0.85); font-size: .75rem; font-weight: 600;
            padding: 5px 14px; border-radius: 20px; margin-bottom: 1.5rem; letter-spacing: .5px;
        }
        .hero h1 { font-size: clamp(2rem,5vw,3rem); font-weight: 700; color: #fff; line-height: 1.2; margin-bottom: 1rem; }
        .hero p  { font-size: 1rem; color: rgba(255,255,255,.78); line-height: 1.7; margin-bottom: 2rem; max-width: 480px; margin-left: auto; margin-right: auto; }
        .hero-actions { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .btn-hero-primary {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff; color: #B03428; font-weight: 700; font-size: .875rem;
            padding: .75rem 1.75rem; border-radius: 50px; text-decoration: none;
            border: none; transition: transform .15s,box-shadow .15s;
            box-shadow: 0 4px 18px rgba(0,0,0,.2);
        }
        .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.28); color: #922B21; }
        .btn-hero-secondary {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.12); color: #fff; font-weight: 600; font-size: .875rem;
            padding: .75rem 1.5rem; border-radius: 50px; text-decoration: none;
            border: 1px solid rgba(255,255,255,.25); transition: background .15s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,.2); color: #fff; }

        /* ── Alert banner (only shown when active alerts exist) ── */
        .alert-banner {
            background: #B03428;
            color: #fff;
            padding: .6rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: .85rem;
            font-weight: 600;
            flex-wrap: wrap;
        }
        .alert-banner a { color: #fff; text-decoration: underline; white-space: nowrap; }

        /* ── Main content bg ── */
        .main-content { background: #EFEFEF; padding: 2.5rem 0 4rem; }

        /* ── Section title ── */
        .section-title { font-size: 1rem; font-weight: 700; color: var(--rescue-text); margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; }
        .section-title i { color: #B03428; font-size: .95rem; }

        /* ── Shared card shell ── */
        .content-shell { background: var(--rescue-white); border: 1px solid #E4E4E4; border-radius: 14px; overflow: hidden; margin-bottom: 1.25rem; }
        .content-shell-header { padding: .875rem 1.25rem; border-bottom: 1px solid #EBEBEB; display: flex; align-items: center; justify-content: space-between; }

        /* ── Announcements ── */
        .announcement-item { padding: 1rem 1.25rem; border-bottom: 1px solid #F0F0F0; transition: background .15s; }
        .announcement-item:last-child { border-bottom: none; }
        .announcement-item:hover { background: #FAFAFA; }
        .ann-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: .35rem; }
        .ann-title  { font-size: .875rem; font-weight: 700; color: var(--rescue-text); margin: 0; }
        .ann-date   { font-size: .72rem; color: var(--rescue-muted); white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
        .ann-body   { font-size: .82rem; color: var(--rescue-muted); line-height: 1.5; margin: 0 0 .5rem; }

        /* ── Alerts ── */
        .alert-item { padding: 1rem 1.25rem; border-bottom: 1px solid #F0F0F0; display: flex; gap: 12px; align-items: flex-start; transition: background .15s; }
        .alert-item:last-child { border-bottom: none; }
        .alert-item:hover { background: #FAFAFA; }
        .alert-dot  { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
        .alert-title { font-size: .875rem; font-weight: 700; color: #B03428; margin: 0 0 3px; }
        .alert-msg  { font-size: .8rem; color: var(--rescue-muted); margin: 0 0 6px; line-height: 1.45; }
        .alert-meta { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .alert-time { font-size: .72rem; color: var(--rescue-muted); }

        /* ── Sidebar ── */
        .side-card { background: var(--rescue-white); border: 1px solid #E4E4E4; border-radius: 14px; overflow: hidden; margin-bottom: 1.25rem; }
        .side-card-header { padding: .875rem 1.25rem; border-bottom: 1px solid #EBEBEB; display: flex; align-items: center; gap: 8px; }
        .evac-map-placeholder { background: linear-gradient(160deg,#F5F5F5 0%,#EAEAEA 100%); height: 130px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #AAAAAA; font-size: .78rem; gap: 5px; border-bottom: 1px solid #EBEBEB; }
        .evac-map-placeholder i { font-size: 1.75rem; }
        .evac-center-item { display: flex; align-items: center; justify-content: space-between; padding: .65rem 1.25rem; border-bottom: 1px solid #F0F0F0; font-size: .82rem; gap: 8px; }
        .evac-center-item:last-of-type { border-bottom: none; }
        .evac-center-name { color: var(--rescue-text); font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .evac-badge { font-size: .7rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
        .evac-badge-available { background: #EAFAF1; color: #1E8449; }
        .evac-badge-full      { background: #FADBD8; color: #922B21; }
        .hotline-item { display: flex; align-items: center; justify-content: space-between; padding: .65rem 1.25rem; border-bottom: 1px solid #F0F0F0; gap: 8px; }
        .hotline-item:last-of-type { border-bottom: none; }
        .hotline-name   { font-size: .82rem; color: var(--rescue-text); font-weight: 500; }
        .hotline-number { font-size: .82rem; color: #B03428; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .hotline-number:hover { color: #922B21; text-decoration: underline; }
        .card-view-all { display: block; text-align: center; padding: .65rem; font-size: .8rem; font-weight: 600; color: #B03428; text-decoration: none; border-top: 1px solid #EBEBEB; transition: background .15s; }
        .card-view-all:hover { background: #FEF9F9; color: #922B21; }
        .count-badge { background: #B03428; color: #fff; font-size: .68rem; font-weight: 700; padding: 2px 7px; border-radius: 20px; margin-left: 4px; }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--rescue-muted); font-size: .85rem; }
        .empty-state i { font-size: 2rem; margin-bottom: .5rem; display: block; color: #D5D8DC; }

        /* ── Navbar ── */
        .rescue-nav-links { display: flex; align-items: center; gap: .25rem; }
        .rescue-nav-links a { font-size: .85rem; font-weight: 500; color: var(--rescue-text); text-decoration: none; padding: .4rem .75rem; border-radius: 8px; transition: background .15s,color .15s; }
        .rescue-nav-links a:hover, .rescue-nav-links a.active { background: #F5F5F5; color: #B03428; }
        .rescue-nav-links a.nav-login { background: #B03428; color: #fff; padding: .4rem 1rem; }
        .rescue-nav-links a.nav-login:hover { background: #922B21; color: #fff; }
        .mobile-menu { display: none; flex-direction: column; padding: .75rem 1.25rem 1rem; border-top: 1px solid var(--rescue-border); gap: 4px; }
        .mobile-menu.open { display: flex; }
        .mobile-menu a { font-size: .9rem; font-weight: 500; color: var(--rescue-text); text-decoration: none; padding: .6rem .75rem; border-radius: 8px; transition: background .15s; }
        .mobile-menu a:hover { background: #F5F5F5; color: #B03428; }
        .mobile-menu a.nav-login { background: #B03428; color: #fff; text-align: center; margin-top: 4px; }
        .mobile-menu a.nav-login:hover { background: #922B21; color: #fff; }
        @media(max-width:767.98px){ .rescue-nav-links{display:none;} .hero{padding:3.5rem 1.25rem 4.5rem;} }
        @media(min-width:768px){ .btn-hamburger{display:none!important;} }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav style="background:var(--rescue-white);border-bottom:1px solid var(--rescue-border);position:sticky;top:0;z-index:100;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.5rem;">
        <a href="<?= BASE_URL ?>/index.php" class="rescue-navbar-brand">
            <div class="brand-icon"><i class="bi bi-shield-fill-exclamation"></i></div>
            <div><div class="brand-name">RESCUE</div><div class="brand-sub">Risk Reduction &amp; Emergency Management</div></div>
        </a>
        <div class="rescue-nav-links">
            <a href="<?= BASE_URL ?>/index.php" class="active">Home</a>
            <a href="<?= BASE_URL ?>/modules/public/alerts.php">Alerts</a>
            <a href="<?= BASE_URL ?>/modules/public/map.php">Map</a>
            <a href="<?= BASE_URL ?>/modules/public/contacts.php">Contacts</a>
            <a href="<?= BASE_URL ?>/modules/auth/login.php" class="nav-login">Login</a>
        </div>
        <button class="btn-hamburger" id="hamburgerBtn" style="background:none;border:none;cursor:pointer;padding:6px;color:var(--rescue-text);font-size:1.3rem;">
            <i class="bi bi-list" id="hamburgerIcon"></i>
        </button>
    </div>
    <div class="mobile-menu" id="mobileMenu">
        <a href="<?= BASE_URL ?>/index.php">Home</a>
        <a href="<?= BASE_URL ?>/modules/public/alerts.php">Alerts</a>
        <a href="<?= BASE_URL ?>/modules/public/map.php">Map</a>
        <a href="<?= BASE_URL ?>/modules/public/contacts.php">Contacts</a>
        <a href="<?= BASE_URL ?>/modules/auth/login.php" class="nav-login">Login</a>
    </div>
</nav>

<!-- ── Active Alert Banner (only when alerts exist) ── -->
<?php if ($activeAlertCount > 0):
    // grab the highest severity active alert for the banner
    $bannerAlert = $conn->query("SELECT title, message, severity FROM alerts WHERE is_active=1 ORDER BY CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END LIMIT 1")->fetch();
    $bannerColors = ['high'=>['bg'=>'#C0392B','icon'=>'bi-exclamation-triangle-fill'],'medium'=>['bg'=>'#D68910','icon'=>'bi-exclamation-circle-fill'],'low'=>['bg'=>'#1E8449','icon'=>'bi-info-circle-fill']];
    $bc = $bannerColors[$bannerAlert['severity']] ?? $bannerColors['low'];
?>
<div class="alert-banner" style="background:<?= $bc['bg'] ?>;">
    <div style="display:flex;align-items:center;gap:10px;">
        <i class="bi <?= $bc['icon'] ?>"></i>
        <span><?= htmlspecialchars($bannerAlert['title']) ?> &mdash; <?= htmlspecialchars(mb_strimwidth($bannerAlert['message'], 0, 90, '…')) ?></span>
    </div>
    <a href="<?= BASE_URL ?>/modules/public/alerts.php">View all <?= $activeAlertCount ?> alert<?= $activeAlertCount!==1?'s':'' ?> &rarr;</a>
</div>
<?php endif; ?>

<!-- ── Hero ── -->
<section class="hero">
    <div class="hero-content">
        <div class="hero-eyebrow"><i class="bi bi-shield-fill-check" style="font-size:11px;"></i> Barangay 12 Emergency Management</div>
        <h1>Stay Safe,<br>Stay Informed.</h1>
        <p>Real-time disaster alerts and emergency response system for our community.</p>
        <div class="hero-actions">
            <a href="<?= BASE_URL ?>/modules/auth/login.php?redirect=report" class="btn-hero-primary">
                <i class="bi bi-flag-fill"></i> Report Your Status
            </a>
            <a href="<?= BASE_URL ?>/modules/public/alerts.php" class="btn-hero-secondary">
                <i class="bi bi-bell"></i> View Alerts
            </a>
        </div>
    </div>
</section>

<!-- ── Main Content ── -->
<div class="main-content">
    <div class="container-fluid" style="max-width:1200px;">
        <div class="row g-4">

            <!-- Left column -->
            <div class="col-12 col-lg-7">

                <!-- ── Latest Announcements (from announcements table) ── -->
                <div class="content-shell mb-4">
                    <div class="content-shell-header">
                        <h2 class="section-title mb-0">
                            <i class="bi bi-megaphone-fill"></i> Latest Announcements
                        </h2>
                        <?php if (!empty($announcements)): ?>
                        <span style="font-size:.72rem;color:var(--rescue-muted);">
                            <?= count($announcements) ?> post<?= count($announcements)!==1?'s':'' ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($announcements)): ?>
                    <div class="empty-state">
                        <i class="bi bi-megaphone"></i>
                        No announcements at this time.
                    </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $ann): ?>
                        <div class="announcement-item">
                            <div class="ann-header">
                                <p class="ann-title">
                                    <?php if ($ann['is_pinned']): ?>
                                    <i class="bi bi-pin-angle-fill" style="color:#F39C12;font-size:.8rem;margin-right:3px;"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($ann['title']) ?>
                                </p>
                                <span class="ann-date"><?= date('M j, Y', strtotime($ann['created_at'])) ?></span>
                            </div>
                            <p class="ann-body"><?= nl2br(htmlspecialchars($ann['content'])) ?></p>
                            <div style="margin-top:.35rem;">
                                <?= categoryBadge($ann['category'], (bool)$ann['is_pinned']) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- ── Active Alerts (with severity) ── -->
                <div class="content-shell">
                    <div class="content-shell-header">
                        <h2 class="section-title mb-0">
                            <i class="bi bi-bell-fill"></i> Active Alerts
                            <?php if (!empty($activeAlerts)): ?>
                            <span class="count-badge"><?= count($activeAlerts) ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>

                    <?php if (empty($activeAlerts)): ?>
                    <div class="empty-state">
                        <i class="bi bi-bell-slash"></i>
                        No active alerts at this time.
                    </div>
                    <?php else: ?>
                        <?php foreach ($activeAlerts as $alert): ?>
                        <div class="alert-item">
                            <div class="alert-dot" style="background:<?= alertDotColor($alert['severity']) ?>;"></div>
                            <div style="flex:1;min-width:0;">
                                <p class="alert-title"><?= htmlspecialchars($alert['title']) ?></p>
                                <p class="alert-msg"><?= htmlspecialchars($alert['message']) ?></p>
                                <div class="alert-meta">
                                    <?= alertTypeBadge($alert['type']) ?>
                                    <?= alertSeverityBadge($alert['severity']) ?>
                                    <span class="alert-time">
                                        <i class="bi bi-clock" style="font-size:.7rem;"></i>
                                        <?= date('M j, Y g:i A', strtotime($alert['created_at'])) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right column -->
            <div class="col-12 col-lg-5">

                <!-- Evacuation Centers -->
                <div class="side-card">
                    <div class="side-card-header">
                        <h2 class="section-title mb-0"><i class="bi bi-map-fill"></i> Evacuation Centers</h2>
                    </div>
                    <div id="landing-evac-map" style="height:130px; z-index:1;"></div>
                    <?php if (empty($centers)): ?>
                    <div class="empty-state" style="padding:1.25rem;">No centers listed yet.</div>
                    <?php else: ?>
                        <?php foreach ($centers as $c):
                            $isFull = $c['capacity'] > 0 && $c['occupancy'] >= $c['capacity'];
                        ?>
                        <div class="evac-center-item">
                            <span class="evac-center-name">
                                <i class="bi bi-geo-alt-fill" style="color:<?= $isFull?'#B03428':'#2ECC71' ?>;font-size:.75rem;"></i>
                                <?= htmlspecialchars($c['name']) ?>
                            </span>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <?php if ($c['capacity'] > 0): ?>
                                <span style="font-size:.72rem;color:var(--rescue-muted);"><?= (int)$c['occupancy'] ?>/<?= (int)$c['capacity'] ?></span>
                                <?php endif; ?>
                                <span class="evac-badge <?= $isFull?'evac-badge-full':'evac-badge-available' ?>">
                                    <?= $isFull?'Full':'Available' ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/modules/public/map.php" class="card-view-all">
                        View All Centers <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <!-- Emergency Hotlines -->
                <div class="side-card">
                    <div class="side-card-header">
                        <h2 class="section-title mb-0"><i class="bi bi-telephone-fill"></i> Emergency Hotlines</h2>
                    </div>
                    <?php if (empty($contacts)): ?>
                    <div class="empty-state" style="padding:1.25rem;">No contacts listed yet.</div>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): ?>
                        <div class="hotline-item">
                            <span class="hotline-name"><?= htmlspecialchars($contact['name']) ?></span>
                            <a href="tel:<?= htmlspecialchars($contact['phone']) ?>" class="hotline-number">
                                <?= htmlspecialchars($contact['phone']) ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/modules/public/contacts.php" class="card-view-all">
                        View All Contacts <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ── Footer ── -->
<footer class="rescue-footer">
    <p>&copy; 2026 RESCUE &mdash; Barangay 12 Risk Reduction and Emergency Management System</p>
    <p style="margin-top:4px;">For emergencies, call <strong>911</strong> or your local emergency hotline</p>
</footer>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
    const hamburgerBtn  = document.getElementById('hamburgerBtn');
    const hamburgerIcon = document.getElementById('hamburgerIcon');
    const mobileMenu    = document.getElementById('mobileMenu');
    hamburgerBtn.addEventListener('click', () => {
        const isOpen = mobileMenu.classList.toggle('open');
        hamburgerIcon.className = isOpen ? 'bi bi-x-lg' : 'bi bi-list';
    });
    mobileMenu.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            mobileMenu.classList.remove('open');
            hamburgerIcon.className = 'bi bi-list';
        });
    });
</script>

<?php
$stmtLandingCenters = $conn->prepare("
    SELECT name, latitude, longitude, capacity,
        (SELECT COUNT(DISTINCT sr.resident_id)
         FROM safety_reports sr
         WHERE sr.center_id = ec.center_id
         AND sr.status = 'evacuated'
         AND sr.report_id = (
             SELECT MAX(sr2.report_id) FROM safety_reports sr2
             WHERE sr2.resident_id = sr.resident_id
         )) AS occupancy
    FROM evacuation_centers ec WHERE is_active = 1
");
$stmtLandingCenters->execute();
$landingCenters = $stmtLandingCenters->fetchAll();
?>
<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<script>
const LANDING_CENTERS = <?= json_encode($landingCenters) ?>;

const landingMap = L.map('landing-evac-map', {
    center: [14.0639, 120.6358],
    zoom: 15,
    zoomControl: false,
    scrollWheelZoom: false,
    dragging: false,
    doubleClickZoom: false,
    attributionControl: false,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
}).addTo(landingMap);

LANDING_CENTERS.forEach(c => {
    if (!c.latitude || !c.longitude) return;
    const isFull = c.capacity > 0 && c.occupancy >= c.capacity;
    const color  = isFull ? '#C0392B' : '#2ECC71';
    L.circleMarker([c.latitude, c.longitude], {
        radius: 8, color: color, fillColor: color,
        fillOpacity: 0.85, weight: 2,
    })
    .bindPopup(`<strong>${c.name}</strong><br><small>${isFull ? 'Full' : 'Available'}</small>`)
    .addTo(landingMap);
});
</script>
</body>
</html>