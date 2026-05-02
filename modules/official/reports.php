<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Reports & Analytics';
$activePage = 'reports';

// ── Date filters ──
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // first day of current month
$endDate   = $_GET['end_date']   ?? date('Y-m-d');  // today

// Validate dates
if (!strtotime($startDate)) $startDate = date('Y-m-01');
if (!strtotime($endDate))   $endDate   = date('Y-m-d');

// ── RESIDENT STATUS REPORT ──
$stmtResidents = $conn->prepare("
    SELECT
        r.first_name, r.last_name, r.phone,
        r.house_no, r.street, r.area,
        sr.status, sr.reported_at,
        ec.name AS center_name
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN safety_reports sr ON sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = r.resident_id
        AND DATE(sr2.reported_at) BETWEEN ? AND ?
    )
    LEFT JOIN evacuation_centers ec ON ec.center_id = sr.center_id
    ORDER BY
        CASE WHEN sr.status = 'need_help' THEN 1
             WHEN sr.status IS NULL THEN 2
             ELSE 3 END,
        r.last_name ASC
");
$stmtResidents->execute([$startDate, $endDate]);
$residentReport = $stmtResidents->fetchAll();

// Status distribution
$statusCounts = ['safe_at_home' => 0, 'evacuated' => 0, 'need_help' => 0, 'unidentified' => 0];
foreach ($residentReport as $r) {
    $s = $r['status'] ?? 'unidentified';
    if (isset($statusCounts[$s])) $statusCounts[$s]++;
    else $statusCounts['unidentified']++;
}
$totalResidents = count($residentReport);

// ── ALERT HISTORY REPORT ──
$stmtAlerts = $conn->prepare("
    SELECT a.title, a.message, a.type, a.severity, a.is_active, a.created_at,
           o.first_name, o.last_name
    FROM alerts a
    LEFT JOIN officials o ON o.official_id = a.created_by
    WHERE DATE(a.created_at) BETWEEN ? AND ?
    ORDER BY a.created_at DESC
");
$stmtAlerts->execute([$startDate, $endDate]);
$alertReport = $stmtAlerts->fetchAll();

$alertCounts = ['flood' => 0, 'fire' => 0, 'general' => 0];
foreach ($alertReport as $a) {
    if (isset($alertCounts[$a['type']])) $alertCounts[$a['type']]++;
    else $alertCounts['general']++;
}

// ── EVACUATION CENTER SUMMARY ──
$stmtCenters = $conn->prepare("
    SELECT
        ec.name, ec.capacity, ec.is_active,
        COUNT(DISTINCT sr.resident_id) AS occupancy
    FROM evacuation_centers ec
    LEFT JOIN safety_reports sr ON sr.center_id = ec.center_id
        AND sr.status = 'evacuated'
        AND sr.report_id = (
            SELECT MAX(sr2.report_id) FROM safety_reports sr2
            WHERE sr2.resident_id = sr.resident_id
            AND DATE(sr2.reported_at) BETWEEN ? AND ?
        )
    GROUP BY ec.center_id
    ORDER BY ec.name ASC
");
$stmtCenters->execute([$startDate, $endDate]);
$centerReport = $stmtCenters->fetchAll();

// ── Barangay info ──
$stmtSettings = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$sysSettings  = [];
foreach ($stmtSettings->fetchAll() as $row) {
    $sysSettings[$row['setting_key']] = $row['setting_value'];
}
$barangayName = $sysSettings['barangay_name'] ?? 'Barangay 12';

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
    .report-section {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: 1.75rem;
    }

    .report-section-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--rescue-border);
        background: #FAFAFA;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
    }

    .report-section-header h2 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--rescue-text);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .report-table th {
        background: #F8F9FA;
        border-bottom: 1.5px solid var(--rescue-border);
        padding: 0.7rem 1rem;
        text-align: left;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--rescue-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        white-space: nowrap;
    }

    .report-table td {
        padding: 0.8rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        vertical-align: middle;
    }

    .report-table tr:last-child td { border-bottom: none; }
    .report-table tr:hover td { background: #FAFAFA; }

    .badge-safe      { background:#EAFAF1; color:#1E8449; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-evacuated { background:#EBF5FB; color:#2980B9; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-help      { background:var(--rescue-red-light); color:var(--rescue-red); font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-unknown   { background:#F2F3F4; color:var(--rescue-muted); font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-active    { background:#EAFAF1; color:#1E8449; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; }
    .badge-inactive  { background:#F2F3F4; color:var(--rescue-muted); font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; }

    .type-badge { font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .sev-high   { background:#FADBD8; color:#C0392B; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; }
    .sev-medium { background:#FDEBD0; color:#D68910; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; }
    .sev-low    { background:#EAFAF1; color:#1E8449; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; }

    /* Chart bars */
    .chart-bar-wrap { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .chart-bar-label { width:110px; font-size:0.82rem; color:var(--rescue-text); font-weight:600; flex-shrink:0; text-align:right; }
    .chart-bar-bg { flex:1; background:#F0F3F4; border-radius:10px; height:20px; overflow:hidden; }
    .chart-bar-fill { height:100%; border-radius:10px; transition: width 0.6s ease; display:flex; align-items:center; padding-left:8px; }
    .chart-bar-count { font-size:0.75rem; font-weight:700; color:#fff; white-space:nowrap; }

    /* Occupancy bar */
    .occ-bar-wrap { height:6px; background:#F0F3F4; border-radius:10px; overflow:hidden; margin-top:4px; }
    .occ-bar-fill { height:100%; border-radius:10px; }

    @media (max-width:767.98px) {
        .report-table thead { display:none; }
        .report-table tr { display:block; border:1px solid var(--rescue-border); border-radius:10px; margin-bottom:10px; }
        .report-table td { display:flex; justify-content:space-between; align-items:center; padding:0.6rem 1rem; border-bottom:1px solid #F5F5F5; flex-wrap:wrap; gap:6px; }
        .report-table td:last-child { border-bottom:none; }
        .report-table td::before { content:attr(data-label); font-weight:700; color:var(--rescue-muted); font-size:0.75rem; width:100%; }
    }
</style>

<!-- Page header -->
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">Reports &amp; Analytics</h1>
        <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">Generate and review disaster management reports</p>
    </div>
    <button class="btn-rescue" onclick="openPrintReport()"
        style="display:flex; align-items:center; gap:8px;">
        &#128438; Export PDF
    </button>
</div>

<!-- Date filter -->
<div style="background:var(--rescue-white); border:1px solid var(--rescue-border); border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.75rem;">
    <form method="GET" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
        <div>
            <label class="form-label" style="font-size:0.78rem;">Start Date</label>
            <input type="date" name="start_date" value="<?= $startDate ?>"
                class="form-control" style="font-size:0.85rem; padding:0.5rem 0.75rem;">
        </div>
        <div>
            <label class="form-label" style="font-size:0.78rem;">End Date</label>
            <input type="date" name="end_date" value="<?= $endDate ?>"
                class="form-control" style="font-size:0.85rem; padding:0.5rem 0.75rem;">
        </div>
        <button type="submit" class="btn-rescue" style="padding:0.5rem 1.25rem;">
            Apply Filter
        </button>
        <a href="<?= BASE_URL ?>/modules/official/reports.php"
            style="font-size:0.82rem; color:var(--rescue-muted); text-decoration:none; padding-bottom:4px;">
            Reset
        </a>
        <div style="margin-left:auto; font-size:0.82rem; color:var(--rescue-muted); padding-bottom:4px;">
            Showing data from <strong><?= date('M j, Y', strtotime($startDate)) ?></strong>
            to <strong><?= date('M j, Y', strtotime($endDate)) ?></strong>
        </div>
    </form>
</div>

<!-- Summary stat cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:1.75rem;">
    <div class="stat-card">
        <div><div class="stat-label">Total Residents</div><div class="stat-value" style="color:var(--rescue-text);"><?= $totalResidents ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;font-size:1rem;">&#128100;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Safe at Home</div><div class="stat-value" style="color:#1E8449;"><?= $statusCounts['safe_at_home'] ?></div></div>
        <div class="stat-icon" style="background:#EAFAF1;color:#1E8449;font-size:1rem;">&#9989;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Evacuated</div><div class="stat-value" style="color:#2980B9;"><?= $statusCounts['evacuated'] ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;font-size:1rem;">&#127968;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Need Help</div><div class="stat-value" style="color:var(--rescue-red);"><?= $statusCounts['need_help'] ?></div></div>
        <div class="stat-icon" style="background:var(--rescue-red-light);color:var(--rescue-red);font-size:1rem;">&#9888;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Alerts Sent</div><div class="stat-value" style="color:#B7770D;"><?= count($alertReport) ?></div></div>
        <div class="stat-icon" style="background:#FEF9E7;color:#B7770D;font-size:1rem;">&#128276;</div>
    </div>
</div>

<div class="row g-4">

    <!-- Left: Charts -->
    <div class="col-12 col-xl-4">

        <!-- Status distribution chart -->
        <div class="report-section mb-4">
            <div class="report-section-header">
                <h2>&#128200; Resident Status Distribution</h2>
            </div>
            <div style="padding:1.25rem;">
                <?php
                $chartData = [
                    ['label'=>'Safe at Home', 'count'=>$statusCounts['safe_at_home'], 'color'=>'#2ECC71'],
                    ['label'=>'Evacuated',    'count'=>$statusCounts['evacuated'],    'color'=>'#3498DB'],
                    ['label'=>'Need Help',    'count'=>$statusCounts['need_help'],    'color'=>'#C0392B'],
                    ['label'=>'Unidentified', 'count'=>$statusCounts['unidentified'], 'color'=>'#AAB7B8'],
                ];
                $maxCount = max(array_column($chartData, 'count')) ?: 1;
                foreach ($chartData as $item):
                    $pct = round($item['count'] / $maxCount * 100);
                    $realpct = $totalResidents > 0 ? round($item['count'] / $totalResidents * 100) : 0;
                ?>
                <div class="chart-bar-wrap">
                    <div class="chart-bar-label"><?= $item['label'] ?></div>
                    <div class="chart-bar-bg">
                        <div class="chart-bar-fill" style="width:<?= $pct ?>%; background:<?= $item['color'] ?>;">
                            <?php if ($item['count'] > 0): ?>
                            <span class="chart-bar-count"><?= $item['count'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="font-size:0.75rem; color:var(--rescue-muted); width:35px; text-align:right; flex-shrink:0;">
                        <?= $realpct ?>%
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Alert type chart -->
        <div class="report-section">
            <div class="report-section-header">
                <h2>&#128276; Alerts by Type</h2>
            </div>
            <div style="padding:1.25rem;">
                <?php
                $alertChartData = [
                    ['label'=>'Flood',   'count'=>$alertCounts['flood']   ?? 0, 'color'=>'#3498DB'],
                    ['label'=>'Fire',    'count'=>$alertCounts['fire']    ?? 0, 'color'=>'#C0392B'],
                    ['label'=>'General', 'count'=>$alertCounts['general'] ?? 0, 'color'=>'#5D6D7E'],
                ];
                $maxAlerts = max(array_column($alertChartData, 'count')) ?: 1;
                foreach ($alertChartData as $item):
                    $pct = round($item['count'] / $maxAlerts * 100);
                ?>
                <div class="chart-bar-wrap">
                    <div class="chart-bar-label"><?= $item['label'] ?></div>
                    <div class="chart-bar-bg">
                        <div class="chart-bar-fill" style="width:<?= max($pct,0) ?>%; background:<?= $item['color'] ?>;">
                            <?php if ($item['count'] > 0): ?>
                            <span class="chart-bar-count"><?= $item['count'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($alertReport)): ?>
                <div style="text-align:center; padding:1rem 0; font-size:0.82rem; color:var(--rescue-muted);">
                    No alerts in this period.
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Right: Tables -->
    <div class="col-12 col-xl-8">

        <!-- Resident Status Report -->
        <div class="report-section mb-4">
            <div class="report-section-header">
                <h2>&#128101; Resident Status Report</h2>
                <span style="font-size:0.78rem; color:var(--rescue-muted);"><?= $totalResidents ?> residents</span>
            </div>
            <?php if (empty($residentReport)): ?>
            <div style="text-align:center; padding:2rem; color:var(--rescue-muted); font-size:0.85rem;">
                No resident data for this period.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Center / Location</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($residentReport as $r):
                        $name = htmlspecialchars($r['first_name'].' '.$r['last_name']);
                        $addr = implode(', ', array_filter([$r['house_no'], $r['street'], $r['area']])) ?: '—';
                    ?>
                    <tr>
                        <td data-label="Name" style="font-weight:600;"><?= $name ?></td>
                        <td data-label="Address" style="color:var(--rescue-muted);font-size:0.82rem;"><?= htmlspecialchars($addr) ?></td>
                        <td data-label="Status">
                            <?php if (!$r['status']): ?>
                                <span class="badge-unknown">Unidentified</span>
                            <?php elseif ($r['status']==='safe_at_home'): ?>
                                <span class="badge-safe">Safe at Home</span>
                            <?php elseif ($r['status']==='evacuated'): ?>
                                <span class="badge-evacuated">Evacuated</span>
                            <?php else: ?>
                                <span class="badge-help">Need Help</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Location" style="font-size:0.82rem;color:var(--rescue-muted);">
                            <?= $r['status']==='evacuated' && $r['center_name']
                                ? htmlspecialchars($r['center_name'])
                                : ($r['status']==='safe_at_home' ? 'At home' : '—') ?>
                        </td>
                        <td data-label="Last Updated" style="font-size:0.8rem;color:var(--rescue-muted);">
                            <?= $r['reported_at'] ? date('M j, Y g:i A', strtotime($r['reported_at'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Alert History Report -->
        <div class="report-section mb-4">
            <div class="report-section-header">
                <h2>&#128276; Alert History Report</h2>
                <span style="font-size:0.78rem; color:var(--rescue-muted);"><?= count($alertReport) ?> alerts</span>
            </div>
            <?php if (empty($alertReport)): ?>
            <div style="text-align:center; padding:2rem; color:var(--rescue-muted); font-size:0.85rem;">
                No alerts sent in this period.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Alert Title</th>
                            <th>Type</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Sent By</th>
                            <th>Date Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $typeMeta = [
                        'flood'   => ['bg'=>'#EBF5FB','color'=>'#1A5276'],
                        'fire'    => ['bg'=>'#FDEDEC','color'=>'#922B21'],
                        'general' => ['bg'=>'#EAFAF1','color'=>'#1E8449'],
                    ];
                    foreach ($alertReport as $a):
                        $tm = $typeMeta[$a['type']] ?? $typeMeta['general'];
                        $sentBy = $a['first_name'] ? htmlspecialchars($a['first_name'].' '.$a['last_name']) : 'System';
                    ?>
                    <tr>
                        <td data-label="Title" style="font-weight:600;"><?= htmlspecialchars($a['title']) ?></td>
                        <td data-label="Type">
                            <span class="type-badge" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;">
                                <?= strtoupper($a['type']) ?>
                            </span>
                        </td>
                        <td data-label="Severity">
                            <span class="sev-<?= $a['severity'] ?>"><?= strtoupper($a['severity']) ?></span>
                        </td>
                        <td data-label="Status">
                            <span class="<?= $a['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                <?= $a['is_active'] ? 'Active' : 'Resolved' ?>
                            </span>
                        </td>
                        <td data-label="Sent By" style="font-size:0.82rem;color:var(--rescue-muted);"><?= $sentBy ?></td>
                        <td data-label="Date" style="font-size:0.8rem;color:var(--rescue-muted);">
                            <?= date('M j, Y g:i A', strtotime($a['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Evacuation Center Summary -->
        <div class="report-section">
            <div class="report-section-header">
                <h2>&#127968; Evacuation Center Summary</h2>
                <span style="font-size:0.78rem; color:var(--rescue-muted);"><?= count($centerReport) ?> centers</span>
            </div>
            <?php if (empty($centerReport)): ?>
            <div style="text-align:center; padding:2rem; color:var(--rescue-muted); font-size:0.85rem;">
                No evacuation centers found.
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Center Name</th>
                            <th>Capacity</th>
                            <th>Occupancy</th>
                            <th>Usage</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($centerReport as $c):
                        $capacity  = (int) $c['capacity'];
                        $occupancy = (int) $c['occupancy'];
                        $pct       = $capacity > 0 ? min(100, round($occupancy / $capacity * 100)) : 0;
                        $barColor  = $pct >= 100 ? '#C0392B' : ($pct >= 75 ? '#E67E22' : '#2ECC71');
                        $isFull    = $capacity > 0 && $occupancy >= $capacity;
                    ?>
                    <tr>
                        <td data-label="Name" style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></td>
                        <td data-label="Capacity" style="color:var(--rescue-muted);">
                            <?= $capacity > 0 ? $capacity.' persons' : '—' ?>
                        </td>
                        <td data-label="Occupancy" style="font-weight:600;">
                            <?= $occupancy ?><?= $capacity > 0 ? ' / '.$capacity : '' ?>
                        </td>
                        <td data-label="Usage" style="min-width:120px;">
                            <?php if ($capacity > 0): ?>
                            <div style="font-size:0.75rem; color:var(--rescue-muted); margin-bottom:3px;"><?= $pct ?>%</div>
                            <div class="occ-bar-wrap">
                                <div class="occ-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>;"></div>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--rescue-muted); font-size:0.82rem;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if (!$c['is_active']): ?>
                                <span class="badge-inactive">Inactive</span>
                            <?php elseif ($isFull): ?>
                                <span class="badge-help">Full</span>
                            <?php else: ?>
                                <span class="badge-safe">Available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function openPrintReport() {
    const startDate = '<?= $startDate ?>';
    const endDate   = '<?= $endDate ?>';
    const url = '<?= BASE_URL ?>/modules/official/reports_print.php?start_date=' + startDate + '&end_date=' + endDate;
    window.open(url, '_blank', 'width=900,height=700');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>