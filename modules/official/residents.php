<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Residents Status';
$activePage = 'residents';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── OVERRIDE STATUS ──
    if ($action === 'override_status') {
        $residentId = (int) ($_POST['resident_id'] ?? 0);
        $newStatus  = $_POST['new_status'] ?? '';
        $notes      = trim($_POST['notes'] ?? '');
        $centerId   = $_POST['center_id'] ?? null;

        $validStatuses = ['safe_at_home', 'evacuated', 'need_help'];

        if (!in_array($newStatus, $validStatuses)) {
            $error = 'Invalid status.';
        } elseif ($residentId) {
            $stmt = $conn->prepare("
                INSERT INTO safety_reports (resident_id, status, notes, center_id, reported_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $residentId,
                $newStatus,
                $notes ?: null,
                ($newStatus === 'evacuated' && $centerId) ? $centerId : null,
            ]);
            $success = 'Resident status updated successfully.';
        }
    }
}

// ── Filters ──
$search     = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';
$filterArea   = $_GET['area']   ?? 'all';

// ── Fetch latest status per resident ──
$sql = "
    SELECT
        r.resident_id,
        r.first_name, r.last_name, r.phone,
        r.birthdate, r.gender,
        r.house_no, r.street, r.area,
        sr.status, sr.notes, sr.reported_at,
        ec.name AS center_name,
        u.is_active
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN safety_reports sr ON sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = r.resident_id
    )
    LEFT JOIN evacuation_centers ec ON ec.center_id = sr.center_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR r.area LIKE ? OR r.street LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterStatus !== 'all') {
    if ($filterStatus === 'unidentified') {
        $sql .= " AND sr.status IS NULL";
    } else {
        $sql .= " AND sr.status = ?";
        $params[] = $filterStatus;
    }
}

if ($filterArea !== 'all') {
    $sql .= " AND r.area = ?";
    $params[] = $filterArea;
}

$sql .= " ORDER BY
    CASE WHEN sr.status = 'need_help' THEN 1
         WHEN sr.status IS NULL THEN 2
         WHEN sr.status = 'evacuated' THEN 3
         ELSE 4 END,
    sr.reported_at DESC";

$stmtResidents = $conn->prepare($sql);
$stmtResidents->execute($params);
$residents = $stmtResidents->fetchAll();

// ── Summary counts ──
$counts = [
    'total'       => 0,
    'safe_at_home'=> 0,
    'evacuated'   => 0,
    'need_help'   => 0,
    'unidentified'=> 0,
];

$stmtCounts = $conn->query("
    SELECT
        COUNT(DISTINCT r.resident_id) AS total,
        SUM(CASE WHEN sr.status = 'safe_at_home'  THEN 1 ELSE 0 END) AS safe_at_home,
        SUM(CASE WHEN sr.status = 'evacuated'     THEN 1 ELSE 0 END) AS evacuated,
        SUM(CASE WHEN sr.status = 'need_help'     THEN 1 ELSE 0 END) AS need_help,
        SUM(CASE WHEN sr.status IS NULL           THEN 1 ELSE 0 END) AS unidentified
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    LEFT JOIN safety_reports sr ON sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = r.resident_id
    )
");
$counts = $stmtCounts->fetch();

// ── Fetch areas for filter dropdown ──
$stmtAreas = $conn->query("SELECT DISTINCT area FROM residents WHERE area IS NOT NULL AND area != '' ORDER BY area ASC");
$areas = $stmtAreas->fetchAll();

// ── Fetch evacuation centers for override modal ──
$stmtCenters = $conn->query("SELECT center_id, name FROM evacuation_centers WHERE is_active = 1 ORDER BY name ASC");
$centers = $stmtCenters->fetchAll();

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
    .stat-grid-5 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 14px;
        margin-bottom: 1.75rem;
    }

    .filter-bar {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    /* Urgent cases */
    .urgent-section {
        background: #FEF5F5;
        border: 1px solid #F1948A;
        border-radius: 12px;
        padding: 1.1rem 1.25rem;
        margin-bottom: 1.5rem;
    }

    .urgent-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 1rem;
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--rescue-red);
    }

    .urgent-card {
        background: var(--rescue-white);
        border: 1px solid #F1948A;
        border-radius: 10px;
        padding: 0.9rem 1.1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 0.75rem;
    }

    .urgent-card:last-child { margin-bottom: 0; }

    /* Residents table */
    .residents-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .residents-table th {
        background: #FAFAFA;
        border-bottom: 1.5px solid var(--rescue-border);
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--rescue-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        white-space: nowrap;
    }

    .residents-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        vertical-align: middle;
    }

    .residents-table tr:last-child td { border-bottom: none; }
    .residents-table tr:hover td { background: #FAFAFA; }

    /* Status badges */
    .badge-safe     { background:#EAFAF1; color:#1E8449; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-evacuated{ background:#EBF5FB; color:#2980B9; font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-help     { background:var(--rescue-red-light); color:var(--rescue-red); font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .badge-unknown  { background:#F2F3F4; color:var(--rescue-muted); font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }

    .action-btn {
        background: none;
        border: 1px solid var(--rescue-border);
        border-radius: 7px;
        padding: 4px 10px;
        font-size: 0.78rem;
        cursor: pointer;
        font-family: inherit;
        color: var(--rescue-text);
        transition: all 0.15s;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }

    .action-btn:hover   { background: #F5F5F5; }
    .action-btn.blue    { color:#2980B9; border-color:#AED6F1; }
    .action-btn.blue:hover   { background:#EBF5FB; }
    .action-btn.green   { color:#1E8449; border-color:#A9DFBF; }
    .action-btn.green:hover  { background:#EAFAF1; }
    .action-btn.red     { color:var(--rescue-red); border-color:var(--rescue-red-light); }
    .action-btn.red:hover    { background:var(--rescue-red-light); }

    /* Modals */
    .rescue-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .rescue-modal-backdrop.open { display: flex; }

    .rescue-modal {
        background: #fff;
        border-radius: 16px;
        width: 100%;
        max-width: 480px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .rescue-modal-header {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid var(--rescue-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 1;
    }

    .rescue-modal-header h5 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
        color: var(--rescue-text);
    }

    .rescue-modal-body   { padding: 1.25rem 1.5rem; }

    .rescue-modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--rescue-border);
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .btn-modal-cancel {
        padding: 0.6rem 1.25rem;
        border: 1.5px solid var(--rescue-border);
        border-radius: 10px;
        background: #fff;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--rescue-text);
        cursor: pointer;
        font-family: inherit;
    }

    .btn-modal-submit {
        padding: 0.6rem 1.25rem;
        border: none;
        border-radius: 10px;
        background: var(--rescue-red);
        font-size: 0.875rem;
        font-weight: 600;
        color: #fff;
        cursor: pointer;
        font-family: inherit;
    }

    .detail-row {
        display: flex;
        padding: 0.6rem 0;
        border-bottom: 1px solid var(--rescue-border);
        gap: 8px;
    }

    .detail-row:last-child { border-bottom: none; }
    .detail-label { width: 130px; font-size: 0.78rem; font-weight: 700; color: var(--rescue-muted); flex-shrink: 0; }
    .detail-value { font-size: 0.85rem; color: var(--rescue-text); flex: 1; }

    /* Status selector buttons */
    .status-selector { display: flex; gap: 8px; margin-bottom: 1rem; flex-wrap: wrap; }

    .status-btn {
        flex: 1;
        min-width: 100px;
        padding: 0.65rem 0.5rem;
        border: 1.5px solid var(--rescue-border);
        border-radius: 10px;
        background: #fff;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--rescue-muted);
        cursor: pointer;
        font-family: inherit;
        text-align: center;
        transition: all 0.15s;
    }

    .status-btn.active-safe     { border-color:#1E8449; background:#EAFAF1; color:#1E8449; }
    .status-btn.active-evacuated{ border-color:#2980B9; background:#EBF5FB; color:#2980B9; }
    .status-btn.active-help     { border-color:var(--rescue-red); background:var(--rescue-red-light); color:var(--rescue-red); }

    @media (max-width: 767.98px) {
        .residents-table thead { display: none; }
        .residents-table tr {
            display: block;
            border: 1px solid var(--rescue-border);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .residents-table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid #F5F5F5;
            font-size: 0.82rem;
            flex-wrap: wrap;
            gap: 6px;
        }
        .residents-table td:last-child { border-bottom: none; }
        .residents-table td::before {
            content: attr(data-label);
            font-weight: 700;
            color: var(--rescue-muted);
            font-size: 0.75rem;
            width: 100%;
        }
    }
</style>

<!-- Page header -->
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">Resident Monitoring</h1>
    <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">Track and monitor resident safety status</p>
</div>

<!-- Feedback -->
<?php if ($success): ?>
<div style="background:#EAFAF1; border:1px solid #A9DFBF; color:#1E8449; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem;">
    &#9989; <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:var(--rescue-red-light); border:1px solid #F1948A; color:var(--rescue-red-dark); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem;">
    &#9888; <?= $error ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="stat-grid-5">
    <div class="stat-card">
        <div><div class="stat-label">Total</div><div class="stat-value" style="color:var(--rescue-text);"><?= $counts['total'] ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;font-size:1rem;">&#128100;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Safe at Home</div><div class="stat-value" style="color:#1E8449;"><?= $counts['safe_at_home'] ?></div></div>
        <div class="stat-icon" style="background:#EAFAF1;color:#1E8449;font-size:1rem;">&#9989;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Evacuated</div><div class="stat-value" style="color:#2980B9;"><?= $counts['evacuated'] ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;font-size:1rem;">&#127968;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Need Help</div><div class="stat-value" style="color:var(--rescue-red);"><?= $counts['need_help'] ?></div></div>
        <div class="stat-icon" style="background:var(--rescue-red-light);color:var(--rescue-red);font-size:1rem;">&#9888;</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Unidentified</div><div class="stat-value" style="color:var(--rescue-muted);"><?= $counts['unidentified'] ?></div></div>
        <div class="stat-icon" style="background:#F2F3F4;color:var(--rescue-muted);font-size:1rem;">&#10067;</div>
    </div>
</div>

<!-- Urgent cases -->
<?php
$urgentCases = array_filter($residents, fn($r) => $r['status'] === 'need_help');
if (!empty($urgentCases) && $filterStatus === 'all' && empty($search) && $filterArea === 'all'):
?>
<div class="urgent-section">
    <div class="urgent-header">
        &#9888; Urgent Cases — Require Immediate Attention
    </div>
    <?php foreach ($urgentCases as $r):
        $fullName = htmlspecialchars($r['first_name'] . ' ' . $r['last_name']);
        $addr = implode(', ', array_filter([$r['house_no'], $r['street'], $r['area']])) ?: 'Address not set';
        $rData    = json_encode([
            'resident_id' => $r['resident_id'],
            'name'        => $r['first_name'] . ' ' . $r['last_name'],
            'status'      => $r['status'],
            'address'     => $addr,
            'phone'       => $r['phone'] ?? '',
            'notes'       => $r['notes'] ?? '',
            'center_name' => $r['center_name'] ?? '',
            'reported_at' => $r['reported_at'] ?? '',
        ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div class="urgent-card">
        <div style="min-width:0;">
            <div style="font-size:0.9rem; font-weight:700; color:var(--rescue-text); margin-bottom:2px;">
                <?= $fullName ?>
                <span class="badge-help" style="margin-left:6px;">Need Help</span>
            </div>
            <div style="font-size:0.78rem; color:var(--rescue-muted);">
                &#128205; <?= htmlspecialchars($addr) ?>
                <?php if ($r['reported_at']): ?>
                — Reported: <?= date('M j, g:i A', strtotime($r['reported_at'])) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:6px; flex-shrink:0;">
            <button class="action-btn blue" onclick='openViewModal(<?= $rData ?>)'>View</button>
            <button class="action-btn green" onclick='openOverrideModal(<?= $rData ?>, "safe_at_home")'>
                Mark Safe
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="GET" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
        <div style="position:relative; flex:1; min-width:200px;">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                placeholder="Search by name or address..."
                style="width:100%; padding:0.55rem 0.75rem 0.55rem 1rem; border:1.5px solid var(--rescue-border); border-radius:9px; font-size:0.85rem; font-family:inherit; background:#FAFAFA;">
        </div>
        <select name="status" style="padding:0.55rem 0.75rem; border:1.5px solid var(--rescue-border); border-radius:9px; font-size:0.85rem; font-family:inherit; background:#FAFAFA; color:var(--rescue-text);">
            <option value="all"         <?= $filterStatus==='all'         ?'selected':'' ?>>All Status</option>
            <option value="safe_at_home"<?= $filterStatus==='safe_at_home'?'selected':'' ?>>Safe at Home</option>
            <option value="evacuated"   <?= $filterStatus==='evacuated'   ?'selected':'' ?>>Evacuated</option>
            <option value="need_help"   <?= $filterStatus==='need_help'   ?'selected':'' ?>>Need Help</option>
            <option value="unidentified"<?= $filterStatus==='unidentified'?'selected':'' ?>>Unidentified</option>
        </select>
        <select name="area" style="padding:0.55rem 0.75rem; border:1.5px solid var(--rescue-border); border-radius:9px; font-size:0.85rem; font-family:inherit; background:#FAFAFA; color:var(--rescue-text);">
            <option value="all">All Areas</option>
            <?php foreach ($areas as $area): ?>
            <option value="<?= htmlspecialchars($area['area']) ?>"
                <?= $filterArea === $area['area'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($area['area']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-rescue" style="padding:0.55rem 1.1rem;">Filter</button>
        <?php if ($search || $filterStatus !== 'all' || $filterArea !== 'all'): ?>
        <a href="<?= BASE_URL ?>/modules/official/residents.php"
            style="font-size:0.82rem; color:var(--rescue-muted); text-decoration:none;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Residents table -->
<div class="rescue-card" style="padding:0; overflow:hidden;">
    <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--rescue-border); display:flex; align-items:center; justify-content:space-between;">
        <span style="font-size:0.9rem; font-weight:700; color:var(--rescue-text);">
            All Residents
            <span style="font-size:0.8rem; font-weight:500; color:var(--rescue-muted); margin-left:6px;">
                (<?= count($residents) ?> <?= count($residents)===1?'result':'results' ?>)
            </span>
        </span>
    </div>

    <?php if (empty($residents)): ?>
    <div style="text-align:center; padding:3rem 1rem; color:var(--rescue-muted);">
        <p style="font-size:0.875rem; margin:0;">No residents found.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="residents-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Location / Center</th>
                    <th>Last Updated</th>
                    <th>Contact</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($residents as $r):
                $fullName = $r['first_name'] . ' ' . $r['last_name'];
                $initials = strtoupper(substr($r['first_name'],0,1) . substr($r['last_name'],0,1));
                $addr = implode(', ', array_filter([$r['house_no'], $r['street'], $r['area']])) ?: '—';
                $rData    = json_encode([
                    'resident_id' => $r['resident_id'],
                    'name'        => $fullName,
                    'initials'    => $initials,
                    'status'      => $r['status'] ?? '',
                    'address'     => $addr,
                    'phone'       => $r['phone'] ?? '',
                    'notes'       => $r['notes'] ?? '',
                    'center_name' => $r['center_name'] ?? '',
                    'reported_at' => $r['reported_at'] ?? '',
                    'gender'      => $r['gender'] ?? '',
                    'birthdate'   => $r['birthdate'] ?? '',
                ], JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>
            <tr>
                <td data-label="Name">
                    <div style="display:flex; align-items:center; gap:9px;">
                        <div style="width:32px;height:32px;border-radius:50%;background:#EBF5FB;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;color:#2980B9;flex-shrink:0;">
                            <?= $initials ?>
                        </div>
                        <span style="font-weight:600;"><?= htmlspecialchars($fullName) ?></span>
                    </div>
                </td>
                <td data-label="Address" style="color:var(--rescue-muted); font-size:0.82rem;">
                    <?= htmlspecialchars($addr) ?>
                </td>
                <td data-label="Status">
                    <?php if (!$r['status']): ?>
                        <span class="badge-unknown">Unidentified</span>
                    <?php elseif ($r['status'] === 'safe_at_home'): ?>
                        <span class="badge-safe">Safe at Home</span>
                    <?php elseif ($r['status'] === 'evacuated'): ?>
                        <span class="badge-evacuated">Evacuated</span>
                    <?php else: ?>
                        <span class="badge-help">Need Help</span>
                    <?php endif; ?>
                </td>
                <td data-label="Location" style="font-size:0.82rem; color:var(--rescue-muted);">
                    <?= $r['status'] === 'evacuated' && $r['center_name']
                        ? htmlspecialchars($r['center_name'])
                        : ($r['status'] === 'safe_at_home' ? 'At home' : '—') ?>
                </td>
                <td data-label="Last Updated" style="font-size:0.8rem; color:var(--rescue-muted);">
                    <?= $r['reported_at']
                        ? date('M j, Y g:i A', strtotime($r['reported_at']))
                        : '—' ?>
                </td>
                <td data-label="Contact" style="font-size:0.82rem; color:var(--rescue-muted);">
                    <?= $r['phone'] ? htmlspecialchars($r['phone']) : '—' ?>
                </td>
                <td data-label="Actions">
                    <div style="display:flex; gap:5px; flex-wrap:wrap;">
                        <button class="action-btn blue" onclick='openViewModal(<?= $rData ?>)'>View</button>
                        <button class="action-btn" onclick='openOverrideModal(<?= $rData ?>, "")'>
                            Update Status
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>


<!-- ── VIEW RESIDENT MODAL ── -->
<div class="rescue-modal-backdrop" id="viewResidentModal">
    <div class="rescue-modal" style="max-width:460px;">
        <div class="rescue-modal-header">
            <h5>Resident Details</h5>
            <button onclick="closeModal('viewResidentModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body">

            <!-- Avatar + name -->
            <div style="display:flex;align-items:center;gap:14px;padding-bottom:1.1rem;border-bottom:1px solid var(--rescue-border);margin-bottom:1.1rem;">
                <div id="vr-avatar" style="width:48px;height:48px;border-radius:50%;background:#EBF5FB;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#2980B9;flex-shrink:0;"></div>
                <div>
                    <div id="vr-name" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div>
                    <div id="vr-status-badge" style="margin-top:5px;"></div>
                </div>
            </div>

            <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value" id="vr-address"></span></div>
            <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value" id="vr-phone"></span></div>
            <div class="detail-row"><span class="detail-label">Gender</span><span class="detail-value" id="vr-gender"></span></div>
            <div class="detail-row"><span class="detail-label">Birthdate</span><span class="detail-value" id="vr-birthdate"></span></div>
            <div class="detail-row" id="vr-center-row"><span class="detail-label">Evacuation Center</span><span class="detail-value" id="vr-center"></span></div>
            <div class="detail-row" id="vr-notes-row"><span class="detail-label">Notes</span><span class="detail-value" id="vr-notes"></span></div>
            <div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value" id="vr-updated"></span></div>

        </div>
        <div class="rescue-modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn-modal-cancel" onclick="closeModal('viewResidentModal')">Close</button>
            <button type="button" class="btn-modal-submit" style="background:#2980B9;"
                onclick="switchToOverride()">
                Update Status
            </button>
        </div>
    </div>
</div>


<!-- ── OVERRIDE STATUS MODAL ── -->
<div class="rescue-modal-backdrop" id="overrideModal">
    <div class="rescue-modal" style="max-width:440px;">
        <div class="rescue-modal-header">
            <h5>Update Resident Status</h5>
            <button onclick="closeModal('overrideModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST" id="overrideForm">
            <input type="hidden" name="action" value="override_status">
            <input type="hidden" name="resident_id" id="override-resident-id">
            <input type="hidden" name="new_status"  id="override-new-status">
            <div class="rescue-modal-body">

                <!-- Who -->
                <div style="background:#F8F9FA;border:1px solid var(--rescue-border);border-radius:10px;padding:0.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;">
                    <div id="override-avatar" style="width:36px;height:36px;border-radius:50%;background:#EBF5FB;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;color:#2980B9;flex-shrink:0;"></div>
                    <div>
                        <div style="font-size:0.85rem;font-weight:700;color:var(--rescue-text);" id="override-name"></div>
                        <div style="font-size:0.75rem;color:var(--rescue-muted);" id="override-current-status"></div>
                    </div>
                </div>

                <!-- Status selector -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Select New Status *</label>
                    <div class="status-selector">
                        <button type="button" class="status-btn" id="btn-safe"
                            onclick="selectStatus('safe_at_home')">
                            &#9989; Safe at Home
                        </button>
                        <button type="button" class="status-btn" id="btn-evacuated"
                            onclick="selectStatus('evacuated')">
                            &#127968; Evacuated
                        </button>
                        <button type="button" class="status-btn" id="btn-help"
                            onclick="selectStatus('need_help')">
                            &#9888; Need Help
                        </button>
                    </div>
                </div>

                <!-- Center select (only for evacuated) -->
                <div id="center-select-wrap" style="display:none; margin-bottom:1rem;">
                    <label class="form-label">Evacuation Center</label>
                    <select name="center_id" class="form-control">
                        <option value="">— Select Center —</option>
                        <?php foreach ($centers as $c): ?>
                        <option value="<?= $c['center_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Notes -->
                <div>
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="2"
                        placeholder="Additional context for this status update..."
                        style="resize:vertical;"></textarea>
                </div>

                <div style="background:#FEF9E7;border:1px solid #FAD7A0;border-radius:10px;padding:0.75rem 1rem;margin-top:1rem;font-size:0.8rem;color:#7D6608;">
                    This will override the resident's current status on their behalf. The change will be logged with a timestamp.
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('overrideModal')">Cancel</button>
                <button type="button" class="btn-modal-submit" id="override-submit-btn"
                    onclick="submitOverride()" style="opacity:0.5; cursor:not-allowed;">
                    Update Status
                </button>
            </div>
        </form>
    </div>
</div>


<script>
let currentResident = null;
let selectedStatus  = '';

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.rescue-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.rescue-modal-backdrop.open')
            .forEach(m => m.classList.remove('open'));
});

// ── Status badge HTML helper ──
function statusBadge(status) {
    const map = {
        'safe_at_home': '<span class="badge-safe">Safe at Home</span>',
        'evacuated':    '<span class="badge-evacuated">Evacuated</span>',
        'need_help':    '<span class="badge-help">Need Help</span>',
        '':             '<span class="badge-unknown">Unidentified</span>',
    };
    return map[status] || '<span class="badge-unknown">Unidentified</span>';
}

function statusLabel(status) {
    const map = {
        'safe_at_home': 'Safe at Home',
        'evacuated':    'Evacuated',
        'need_help':    'Need Help',
        '':             'Unidentified',
    };
    return map[status] || 'Unidentified';
}

// ── View Modal ──
function openViewModal(r) {
    currentResident = r;

    document.getElementById('vr-avatar').textContent   = r.initials || (r.name.split(' ').map(n=>n[0]).join('').toUpperCase());
    document.getElementById('vr-name').textContent     = r.name;
    document.getElementById('vr-address').textContent  = r.address || '—';
    document.getElementById('vr-phone').textContent    = r.phone   || '—';
    document.getElementById('vr-gender').textContent   = r.gender  ? r.gender.charAt(0).toUpperCase() + r.gender.slice(1) : '—';
    document.getElementById('vr-birthdate').textContent= r.birthdate || '—';
    document.getElementById('vr-updated').textContent  = r.reported_at || '—';
    document.getElementById('vr-status-badge').innerHTML = statusBadge(r.status);

    // Center row
    const centerRow = document.getElementById('vr-center-row');
    if (r.status === 'evacuated' && r.center_name) {
        document.getElementById('vr-center').textContent = r.center_name;
        centerRow.style.display = 'flex';
    } else {
        centerRow.style.display = 'none';
    }

    // Notes row
    const notesRow = document.getElementById('vr-notes-row');
    if (r.notes) {
        document.getElementById('vr-notes').textContent = r.notes;
        notesRow.style.display = 'flex';
    } else {
        notesRow.style.display = 'none';
    }

    openModal('viewResidentModal');
}

function switchToOverride() {
    closeModal('viewResidentModal');
    openOverrideModal(currentResident, '');
}

// ── Override Modal ──
function openOverrideModal(r, preselect) {
    currentResident = r;
    selectedStatus  = preselect;

    document.getElementById('override-resident-id').value = r.resident_id;
    document.getElementById('override-new-status').value  = preselect;

    const initials = r.initials || (r.name.split(' ').map(n=>n[0]).join('').toUpperCase());
    document.getElementById('override-avatar').textContent       = initials;
    document.getElementById('override-name').textContent         = r.name;
    document.getElementById('override-current-status').textContent =
        'Current status: ' + statusLabel(r.status);

    // Reset buttons
    ['btn-safe','btn-evacuated','btn-help'].forEach(id => {
        const btn = document.getElementById(id);
        btn.className = 'status-btn';
    });

    document.getElementById('center-select-wrap').style.display = 'none';

    // Preselect if provided
    if (preselect) selectStatus(preselect);

    // Reset submit button
    const submitBtn = document.getElementById('override-submit-btn');
    if (preselect) {
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor  = 'pointer';
    } else {
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor  = 'not-allowed';
    }

    openModal('overrideModal');
}

function selectStatus(status) {
    selectedStatus = status;
    document.getElementById('override-new-status').value = status;

    // Reset all buttons
    document.getElementById('btn-safe').className      = 'status-btn';
    document.getElementById('btn-evacuated').className = 'status-btn';
    document.getElementById('btn-help').className      = 'status-btn';

    // Activate selected
    if (status === 'safe_at_home') {
        document.getElementById('btn-safe').className = 'status-btn active-safe';
    } else if (status === 'evacuated') {
        document.getElementById('btn-evacuated').className = 'status-btn active-evacuated';
    } else if (status === 'need_help') {
        document.getElementById('btn-help').className = 'status-btn active-help';
    }

    // Show center select only for evacuated
    document.getElementById('center-select-wrap').style.display =
        status === 'evacuated' ? 'block' : 'none';

    // Enable submit
    const submitBtn = document.getElementById('override-submit-btn');
    submitBtn.style.opacity = '1';
    submitBtn.style.cursor  = 'pointer';
}

function submitOverride() {
    if (!selectedStatus) {
        alert('Please select a status first.');
        return;
    }
    document.getElementById('overrideForm').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>