<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-d');

if (!strtotime($startDate)) $startDate = date('Y-m-01');
if (!strtotime($endDate))   $endDate   = date('Y-m-d');

// ── Resident Status ──
$stmtResidents = $conn->prepare("
    SELECT r.first_name, r.last_name, r.phone,
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
    ORDER BY CASE WHEN sr.status='need_help' THEN 1 WHEN sr.status IS NULL THEN 2 ELSE 3 END, r.last_name ASC
");
$stmtResidents->execute([$startDate, $endDate]);
$residentReport = $stmtResidents->fetchAll();

$statusCounts = ['safe_at_home'=>0,'evacuated'=>0,'need_help'=>0,'unidentified'=>0];
foreach ($residentReport as $r) {
    $s = $r['status'] ?? 'unidentified';
    if (isset($statusCounts[$s])) $statusCounts[$s]++;
    else $statusCounts['unidentified']++;
}

// ── Alerts ──
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

// ── Centers ──
$stmtCenters = $conn->prepare("
    SELECT ec.name, ec.capacity, ec.is_active,
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

// ── Settings ──
$stmtSettings = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$sysSettings  = [];
foreach ($stmtSettings->fetchAll() as $row) $sysSettings[$row['setting_key']] = $row['setting_value'];
$barangayName = $sysSettings['barangay_name'] ?? 'Barangay 12';
$tagline      = $sysSettings['system_tagline'] ?? 'Risk Reduction & Emergency Management';

// Who generated
$stmtOff = $conn->prepare("SELECT first_name, last_name, position FROM officials WHERE user_id = ?");
$stmtOff->execute([$_SESSION['user_id']]);
$officialData = $stmtOff->fetch();
$generatedBy  = $officialData
    ? htmlspecialchars($officialData['first_name'].' '.$officialData['last_name'].' ('.$officialData['position'].')')
    : 'System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RESCUE Report — <?= date('M j, Y', strtotime($startDate)) ?> to <?= date('M j, Y', strtotime($endDate)) ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #2C2C2C;
            background: #fff;
            padding: 0;
        }

        /* Print button — hidden when printing */
        .print-controls {
            position: fixed;
            top: 16px;
            right: 16px;
            display: flex;
            gap: 8px;
            z-index: 999;
        }

        .print-btn {
            background: #C0392B;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: Arial, sans-serif;
        }

        .close-btn {
            background: #5D6D7E;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: Arial, sans-serif;
        }

        /* Page layout */
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 15mm 15mm 12mm;
            background: #fff;
        }

        /* Header */
        .report-header {
            border-bottom: 3px solid #C0392B;
            padding-bottom: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
        }

        .report-header-left h1 {
            font-size: 22px;
            font-weight: 700;
            color: #C0392B;
            letter-spacing: 2px;
            margin-bottom: 2px;
        }

        .report-header-left p {
            font-size: 10px;
            color: #666;
        }

        .report-header-right {
            text-align: right;
        }

        .report-header-right h2 {
            font-size: 13px;
            font-weight: 700;
            color: #2C2C2C;
        }

        .report-header-right p {
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }

        /* Meta info bar */
        .report-meta {
            background: #F8F9FA;
            border: 1px solid #E0E0E0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 11px;
        }

        .report-meta span { color: #666; }
        .report-meta strong { color: #2C2C2C; }

        /* Summary boxes */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }

        .summary-box {
            border: 1px solid #E0E0E0;
            border-radius: 6px;
            padding: 8px 10px;
            text-align: center;
        }

        .summary-box .val {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
        }

        .summary-box .lbl {
            font-size: 9px;
            color: #666;
            margin-top: 2px;
        }

        /* Section titles */
        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #2C2C2C;
            padding: 6px 10px;
            background: #F0F3F4;
            border-left: 4px solid #C0392B;
            margin-bottom: 8px;
            margin-top: 16px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 11px;
        }

        th {
            background: #2C3E50;
            color: #fff;
            padding: 5px 8px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        td {
            padding: 5px 8px;
            border-bottom: 1px solid #EBEBEB;
            vertical-align: middle;
        }

        tr:nth-child(even) td { background: #F8F9FA; }
        tr:last-child td { border-bottom: none; }

        /* Status badges */
        .b-safe  { background:#EAFAF1; color:#1E8449; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-evac  { background:#EBF5FB; color:#2980B9; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-help  { background:#FADBD8; color:#922B21; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-unkn  { background:#F2F3F4; color:#666;    padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-high  { background:#FADBD8; color:#C0392B; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-med   { background:#FDEBD0; color:#D68910; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-low   { background:#EAFAF1; color:#1E8449; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-act   { background:#EAFAF1; color:#1E8449; padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }
        .b-res   { background:#F2F3F4; color:#666;    padding:1px 6px; border-radius:3px; font-size:9px; font-weight:700; }

        /* Occupancy bar */
        .occ-bar-bg   { background:#E0E0E0; border-radius:3px; height:6px; width:80px; display:inline-block; vertical-align:middle; }
        .occ-bar-fill { height:6px; border-radius:3px; display:block; }

        /* Footer */
        .report-footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #E0E0E0;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #999;
        }

        /* Signature area */
        .signature-area {
            margin-top: 24px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .signature-box {
            border-top: 1px solid #2C2C2C;
            padding-top: 6px;
            font-size: 10px;
        }

        .signature-box .sig-name { font-weight: 700; font-size: 11px; }
        .signature-box .sig-role { color: #666; }

        /* Empty state */
        .empty-msg {
            text-align: center;
            padding: 16px;
            color: #999;
            font-style: italic;
            font-size: 11px;
            border: 1px dashed #E0E0E0;
            border-radius: 4px;
        }

        /* Print styles */
        @media print {
            .print-controls { display: none !important; }
            body { padding: 0; }
            .page { padding: 12mm; margin: 0; width: 100%; }
        }

        @page {
            size: A4;
            margin: 0;
        }
    </style>
</head>
<body>

<!-- Print controls -->
<div class="print-controls">
    <button class="print-btn" onclick="window.print()">&#128438; Save as PDF / Print</button>
    <button class="close-btn" onclick="window.close()">&#x2715; Close</button>
</div>

<div class="page">

    <!-- Header -->
    <div class="report-header">
        <div class="report-header-left">
            <h1>RESCUE</h1>
            <p><?= htmlspecialchars($tagline) ?></p>
        </div>
        <div class="report-header-right">
            <h2><?= htmlspecialchars($barangayName) ?></h2>
            <p>Nasugbu, Batangas</p>
            <p style="margin-top:6px; font-weight:700; color:#C0392B;">DISASTER MANAGEMENT REPORT</p>
        </div>
    </div>

    <!-- Meta info -->
    <div class="report-meta">
        <div><span>Report Period: </span><strong><?= date('F j, Y', strtotime($startDate)) ?> &mdash; <?= date('F j, Y', strtotime($endDate)) ?></strong></div>
        <div><span>Generated By: </span><strong><?= $generatedBy ?></strong></div>
        <div><span>Generated On: </span><strong><?= date('F j, Y g:i A') ?></strong></div>
    </div>

    <!-- Summary boxes -->
    <div class="summary-grid">
        <div class="summary-box">
            <div class="val" style="color:#2980B9;"><?= count($residentReport) ?></div>
            <div class="lbl">Total Residents</div>
        </div>
        <div class="summary-box">
            <div class="val" style="color:#1E8449;"><?= $statusCounts['safe_at_home'] ?></div>
            <div class="lbl">Safe at Home</div>
        </div>
        <div class="summary-box">
            <div class="val" style="color:#2980B9;"><?= $statusCounts['evacuated'] ?></div>
            <div class="lbl">Evacuated</div>
        </div>
        <div class="summary-box">
            <div class="val" style="color:#C0392B;"><?= $statusCounts['need_help'] ?></div>
            <div class="lbl">Need Help</div>
        </div>
        <div class="summary-box">
            <div class="val" style="color:#B7770D;"><?= count($alertReport) ?></div>
            <div class="lbl">Alerts Sent</div>
        </div>
    </div>

    <!-- Section 1: Resident Status -->
    <div class="section-title">1. RESIDENT STATUS REPORT</div>
    <?php if (empty($residentReport)): ?>
    <div class="empty-msg">No resident data for the selected period.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Address</th>
                <th>Status</th>
                <th>Center / Location</th>
                <th>Last Updated</th>
                <th>Contact</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($residentReport as $i => $r):
            $name = htmlspecialchars($r['first_name'].' '.$r['last_name']);
            $addr = implode(', ', array_filter([$r['house_no'], $r['street'], $r['area']])) ?: '—';
        ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td style="font-weight:600;"><?= $name ?></td>
            <td><?= htmlspecialchars($addr) ?></td>
            <td>
                <?php if (!$r['status']): ?>
                    <span class="b-unkn">Unidentified</span>
                <?php elseif ($r['status']==='safe_at_home'): ?>
                    <span class="b-safe">Safe at Home</span>
                <?php elseif ($r['status']==='evacuated'): ?>
                    <span class="b-evac">Evacuated</span>
                <?php else: ?>
                    <span class="b-help">Need Help</span>
                <?php endif; ?>
            </td>
            <td>
                <?= $r['status']==='evacuated' && $r['center_name']
                    ? htmlspecialchars($r['center_name'])
                    : ($r['status']==='safe_at_home' ? 'At home' : '—') ?>
            </td>
            <td><?= $r['reported_at'] ? date('M j, Y g:i A', strtotime($r['reported_at'])) : '—' ?></td>
            <td><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Section 2: Alert History -->
    <div class="section-title">2. ALERT HISTORY REPORT</div>
    <?php if (empty($alertReport)): ?>
    <div class="empty-msg">No alerts were sent during this period.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Alert Title</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Status</th>
                <th>Sent By</th>
                <th>Date Sent</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($alertReport as $i => $a):
            $sentBy = $a['first_name'] ? htmlspecialchars($a['first_name'].' '.$a['last_name']) : 'System';
        ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($a['title']) ?></td>
            <td><?= strtoupper($a['type']) ?></td>
            <td>
                <?php if ($a['severity']==='high'): ?>
                    <span class="b-high">HIGH</span>
                <?php elseif ($a['severity']==='medium'): ?>
                    <span class="b-med">MEDIUM</span>
                <?php else: ?>
                    <span class="b-low">LOW</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="<?= $a['is_active'] ? 'b-act' : 'b-res' ?>">
                    <?= $a['is_active'] ? 'Active' : 'Resolved' ?>
                </span>
            </td>
            <td><?= $sentBy ?></td>
            <td><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Section 3: Evacuation Centers -->
    <div class="section-title">3. EVACUATION CENTER SUMMARY</div>
    <?php if (empty($centerReport)): ?>
    <div class="empty-msg">No evacuation centers found.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Center Name</th>
                <th>Capacity</th>
                <th>Occupancy</th>
                <th>Usage %</th>
                <th>Utilization</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($centerReport as $i => $c):
            $capacity  = (int) $c['capacity'];
            $occupancy = (int) $c['occupancy'];
            $pct       = $capacity > 0 ? min(100, round($occupancy / $capacity * 100)) : 0;
            $barColor  = $pct >= 100 ? '#C0392B' : ($pct >= 75 ? '#E67E22' : '#2ECC71');
            $isFull    = $capacity > 0 && $occupancy >= $capacity;
        ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></td>
            <td><?= $capacity > 0 ? $capacity : '—' ?></td>
            <td><?= $occupancy ?></td>
            <td><?= $capacity > 0 ? $pct.'%' : 'N/A' ?></td>
            <td>
                <?php if ($capacity > 0): ?>
                <div class="occ-bar-bg">
                    <div class="occ-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>;"></div>
                </div>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <?php if (!$c['is_active']): ?>
                    <span class="b-unkn">Inactive</span>
                <?php elseif ($isFull): ?>
                    <span class="b-help">Full</span>
                <?php else: ?>
                    <span class="b-safe">Available</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Signature area -->
    <div class="signature-area">
        <div class="signature-box">
            <div style="height:36px;"></div>
            <div class="sig-name"><?= $generatedBy ?></div>
            <div class="sig-role">Report Generated By</div>
        </div>
        <div class="signature-box">
            <div style="height:36px;"></div>
            <div class="sig-name">________________________</div>
            <div class="sig-role">Noted By / Barangay Captain</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="report-footer">
        <span>RESCUE &mdash; <?= htmlspecialchars($barangayName) ?> Disaster Management System</span>
        <span>Generated: <?= date('M j, Y g:i A') ?></span>
        <span>CONFIDENTIAL &mdash; For Official Use Only</span>
    </div>

</div>

<script>
// Auto-trigger print dialog after page loads
window.addEventListener('load', function() {
    setTimeout(function() {
        // Small delay to let page render fully
    }, 500);
});
</script>

</body>
</html>