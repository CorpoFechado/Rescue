<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('resident');

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

$success = '';
$error   = '';

// Get resident_id
$stmtRid = $conn->prepare("SELECT resident_id FROM residents WHERE user_id = ?");
$stmtRid->execute([$_SESSION['user_id']]);
$ridRow     = $stmtRid->fetch();
$residentId = $ridRow ? $ridRow['resident_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $residentId) {
    $action   = $_POST['action'] ?? '';
    $newStatus = $_POST['status'] ?? '';
    $notes     = trim($_POST['notes'] ?? '');
    $centerId  = $_POST['center_id'] ?? null;

    // ── RESET STATUS ──
    if ($action === 'reset_status') {
        $stmtLatest = $conn->prepare("
            SELECT MAX(report_id) AS latest_id 
            FROM safety_reports 
            WHERE resident_id = ?
        ");
        $stmtLatest->execute([$residentId]);
        $latestRow = $stmtLatest->fetch();

        if ($latestRow && $latestRow['latest_id']) {
            $conn->prepare("DELETE FROM safety_reports WHERE report_id = ?")
                 ->execute([$latestRow['latest_id']]);
        }

        header("Location: " . BASE_URL . "/modules/resident/dashboard.php?updated=1");
        exit();
    }

    // ── UPDATE LOCATION ──
    if ($action === 'update_location') {
        $lat = $_POST['latitude']  ?? null;
        $lng = $_POST['longitude'] ?? null;

        if ($lat && $lng) {
            $stmtGetLatest = $conn->prepare("
                SELECT MAX(report_id) AS latest_id
                FROM safety_reports
                WHERE resident_id = ? AND status = 'need_help'
            ");
            $stmtGetLatest->execute([$residentId]);
            $latestRow = $stmtGetLatest->fetch();

            if ($latestRow && $latestRow['latest_id']) {
                $conn->prepare("
                    UPDATE safety_reports
                    SET latitude = ?, longitude = ?
                    WHERE report_id = ?
                ")->execute([$lat, $lng, $latestRow['latest_id']]);
            }
        }

        header("Location: " . BASE_URL . "/modules/resident/dashboard.php?updated=1");
        exit();
    }

    // ── UPDATE STATUS ──
    $validStatuses = ['safe_at_home', 'evacuated', 'need_help'];

    if (in_array($newStatus, $validStatuses)) {
        $lat = $_POST['latitude']  ?? null;
        $lng = $_POST['longitude'] ?? null;

        $saveLat = ($newStatus === 'need_help' && $lat) ? $lat : null;
        $saveLng = ($newStatus === 'need_help' && $lng) ? $lng : null;

        $stmt = $conn->prepare("
            INSERT INTO safety_reports (resident_id, status, notes, center_id, latitude, longitude, reported_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $residentId,
            $newStatus,
            $notes ?: null,
            ($newStatus === 'evacuated' && $centerId) ? $centerId : null,
            $saveLat,
            $saveLng,
        ]);

        header("Location: " . BASE_URL . "/modules/resident/dashboard.php?updated=1");
        exit();
    }
}

if (isset($_GET['updated'])) $success = 'Your status has been updated.';

// ── Fetch resident full data ──
$stmtResident = $conn->prepare("
    SELECT r.first_name, r.last_name, r.phone, r.birthdate, r.gender,
           r.house_no, r.street, r.area,
           sr.status AS current_status, sr.notes, sr.reported_at,
           ec.name AS center_name
    FROM residents r
    LEFT JOIN safety_reports sr ON sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = r.resident_id
    )
    LEFT JOIN evacuation_centers ec ON ec.center_id = sr.center_id
    WHERE r.user_id = ?
");
$stmtResident->execute([$_SESSION['user_id']]);
$resident = $stmtResident->fetch();

$currentStatus = $resident['current_status'] ?? null;
$address = implode(', ', array_filter([$resident['house_no'], $resident['street'], $resident['area']])) ?: 'Address not set';

// ── Active alerts (latest 3) ──
$stmtAlerts = $conn->query("
    SELECT title, message, type, severity, created_at
    FROM alerts
    WHERE is_active = 1
    ORDER BY CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, created_at DESC
    LIMIT 3
");
$alerts = $stmtAlerts->fetchAll();
$totalAlerts = (int) $conn->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1")->fetchColumn();

// ── Evacuation centers ──
$stmtCenters = $conn->query("
    SELECT center_id, name, capacity,
        (SELECT COUNT(DISTINCT sr.resident_id)
         FROM safety_reports sr
         WHERE sr.center_id = ec.center_id
         AND sr.status = 'evacuated'
         AND sr.report_id = (
             SELECT MAX(sr2.report_id) FROM safety_reports sr2
             WHERE sr2.resident_id = sr.resident_id
         )) AS occupancy
    FROM evacuation_centers ec
    WHERE is_active = 1
    ORDER BY name ASC
");
$centers = $stmtCenters->fetchAll();



require_once __DIR__ . '/../../includes/header_resident.php';
?>

<style>
    /* ── Status card ── */
    .status-card {
        background: var(--rescue-white);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 2px solid var(--rescue-border);
        transition: border-color 0.2s;
    }

    .status-card.status-safe     { border-color: #2ECC71; }
    .status-card.status-evacuated{ border-color: #3498DB; }
    .status-card.status-help     { border-color: var(--rescue-red); }
    .status-card.status-unknown  { border-color: var(--rescue-border); }

    .status-icon-wrap {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        margin-bottom: 0.875rem;
    }

    /* ── Status buttons ── */
    .status-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        margin-top: 1rem;
    }

    .status-btn {
        padding: 0.75rem 0.5rem;
        border-radius: 12px;
        border: 2px solid var(--rescue-border);
        background: #fff;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 5px;
        transition: all 0.15s;
        color: var(--rescue-muted);
        line-height: 1.2;
        text-align: center;
    }

    .status-btn i { font-size: 1.3rem; }

    .status-btn.btn-safe:hover,
    .status-btn.btn-safe.selected {
        border-color: #2ECC71;
        background: #EAFAF1;
        color: #1E8449;
    }

    .status-btn.btn-evacuated:hover,
    .status-btn.btn-evacuated.selected {
        border-color: #3498DB;
        background: #EBF5FB;
        color: #2980B9;
    }

    .status-btn.btn-help:hover,
    .status-btn.btn-help.selected {
        border-color: var(--rescue-red);
        background: var(--rescue-red-light);
        color: var(--rescue-red);
    }

    /* ── Quick action buttons ── */
    .quick-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 1rem;
    }

    .quick-action-btn {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: var(--rescue-text);
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.15s;
    }

    .quick-action-btn:hover { background: #F5F5F5; color: var(--rescue-text); }

    .quick-action-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    /* ── Alert items ── */
    .alert-item {
        padding: 0.875rem 0;
        border-bottom: 1px solid var(--rescue-border);
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }

    .alert-item:last-child { border-bottom: none; padding-bottom: 0; }

    .alert-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
        margin-top: 5px;
    }

    .alert-title { font-size: 0.875rem; font-weight: 700; color: var(--rescue-red); margin: 0 0 3px; }
    .alert-msg   { font-size: 0.8rem; color: var(--rescue-muted); margin: 0 0 5px; line-height: 1.4; }
    .alert-meta  { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .alert-time  { font-size: 0.72rem; color: var(--rescue-muted); }

    /* type/sev badges */
    .tbadge { font-size:.68rem; font-weight:700; padding:2px 7px; border-radius:20px; }
    .sev-high   { background:#FADBD8; color:#C0392B; font-size:.68rem; font-weight:700; padding:2px 7px; border-radius:20px; }
    .sev-medium { background:#FDEBD0; color:#D68910; font-size:.68rem; font-weight:700; padding:2px 7px; border-radius:20px; }
    .sev-low    { background:#EAFAF1; color:#1E8449; font-size:.68rem; font-weight:700; padding:2px 7px; border-radius:20px; }

    /* Center list */
    .center-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 0;
        border-bottom: 1px solid var(--rescue-border);
        font-size: 0.85rem;
        gap: 8px;
    }

    .center-item:last-child { border-bottom: none; }

    .occ-bar-bg   { background:#F0F3F4; border-radius:10px; height:5px; flex:1; overflow:hidden; }
    .occ-bar-fill { height:100%; border-radius:10px; }
</style>

<div class="resident-content">

    <!-- Greeting -->
    <div style="margin-bottom:1.1rem;">
        <h1 style="font-size:1.2rem; font-weight:700; color:var(--rescue-text); margin:0 0 2px;">
            Hello, <?= htmlspecialchars($resident['first_name'] ?? 'Resident') ?>! &#128075;
        </h1>
        <p style="font-size:0.8rem; color:var(--rescue-muted); margin:0;">
            <i class="bi bi-geo-alt-fill" style="color:var(--rescue-red); font-size:0.75rem;"></i>
            <?= htmlspecialchars($address) ?>
        </p>
    </div>

    <!-- Feedback -->
    <?php if ($success): ?>
    <div class="alert-success">
        <i class="bi bi-check-circle-fill"></i> <?= $success ?>
    </div>
    <?php endif; ?>

    <!-- ── Status Card ── -->
    <?php
    $statusClass = match($currentStatus) {
        'safe_at_home' => 'status-safe',
        'evacuated'    => 'status-evacuated',
        'need_help'    => 'status-help',
        default        => 'status-unknown',
    };
    $statusIconBg = match($currentStatus) {
        'safe_at_home' => '#EAFAF1',
        'evacuated'    => '#EBF5FB',
        'need_help'    => 'var(--rescue-red-light)',
        default        => '#F2F3F4',
    };
    $statusIconColor = match($currentStatus) {
        'safe_at_home' => '#1E8449',
        'evacuated'    => '#2980B9',
        'need_help'    => 'var(--rescue-red)',
        default        => 'var(--rescue-muted)',
    };
    $statusIcon = match($currentStatus) {
        'safe_at_home' => 'bi-house-check-fill',
        'evacuated'    => 'bi-building-fill',
        'need_help'    => 'bi-exclamation-triangle-fill',
        default        => 'bi-question-circle-fill',
    };
    ?>
    <div class="status-card <?= $statusClass ?>">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
            <div>
                <div style="font-size:0.78rem; font-weight:700; color:var(--rescue-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
                    Your Current Status
                </div>
                <?php if ($currentStatus === 'safe_at_home'): ?>
                    <span class="badge-safe-home" style="font-size:0.9rem; padding:6px 12px;">
                        <i class="bi bi-house-check-fill"></i> Safe at Home
                    </span>
                <?php elseif ($currentStatus === 'evacuated'): ?>
                    <span class="badge-evacuated" style="font-size:0.9rem; padding:6px 12px;">
                        <i class="bi bi-building-fill"></i> Evacuated
                    </span>
                <?php elseif ($currentStatus === 'need_help'): ?>
                    <span class="badge-need-help" style="font-size:0.9rem; padding:6px 12px;">
                        <i class="bi bi-exclamation-triangle-fill"></i> Need Help
                    </span>
                <?php else: ?>
                    <span class="badge-unknown" style="font-size:0.9rem; padding:6px 12px;">
                        <i class="bi bi-question-circle-fill"></i> Not Reported
                    </span>
                <?php endif; ?>

                <?php if ($currentStatus === 'evacuated' && $resident['center_name']): ?>
                <div style="font-size:0.78rem; color:var(--rescue-muted); margin-top:6px;">
                    <i class="bi bi-geo-alt-fill" style="color:#3498DB;"></i>
                    <?= htmlspecialchars($resident['center_name']) ?>
                </div>
                <?php endif; ?>

                <?php if ($resident['reported_at']): ?>
                <div style="font-size:0.75rem; color:var(--rescue-muted); margin-top:4px;">
                    Last updated: <?= date('M j, g:i A', strtotime($resident['reported_at'])) ?>
                </div>
                <?php endif; ?>
                <?php if ($currentStatus === 'need_help'): ?>
                <?php
                $stmtLoc = $conn->prepare("
                    SELECT latitude, longitude FROM safety_reports
                    WHERE resident_id = ? AND status = 'need_help'
                    ORDER BY report_id DESC LIMIT 1
                ");
                $stmtLoc->execute([$residentId]);
                $locRow = $stmtLoc->fetch();
                ?>
                <?php if ($locRow && $locRow['latitude'] && $locRow['longitude']): ?>
                <div style="margin-top:8px;">
                    <button type="button" onclick="viewSubmittedLocation(<?= $locRow['latitude'] ?>, <?= $locRow['longitude'] ?>)"
                        style="background:none;border:1.5px solid var(--rescue-red);border-radius:8px;padding:5px 12px;font-size:0.78rem;font-weight:700;color:var(--rescue-red);cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;">
                        <i class="bi bi-geo-alt-fill"></i> View Submitted Location
                    </button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            

            <?php if ($currentStatus): ?>
            <div style="margin-top:0.75rem; text-align:center;">
                <form method="POST" id="resetStatusForm" style="display:inline;">
                    <input type="hidden" name="action" value="reset_status">
                    <button type="button" onclick="confirmReset()"
                        style="background:none; border:none; cursor:pointer; font-size:0.75rem; color:var(--rescue-muted); font-family:inherit; display:inline-flex; align-items:center; gap:5px; padding:6px 10px; border-radius:8px; transition:background 0.15s;"
                        onmouseover="this.style.background='#F5F5F5'"
                        onmouseout="this.style.background='none'">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset / Clear my status
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <div class="status-icon-wrap" style="background:<?= $statusIconBg ?>; color:<?= $statusIconColor ?>;">
                <i class="bi <?= $statusIcon ?>"></i>
            </div>
        </div>

        <!-- Status update buttons -->
        <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--rescue-border);">
            <div style="font-size:0.78rem; color:var(--rescue-muted); margin-bottom:8px; font-weight:600;">
                Update your status:
            </div>
            <div class="status-buttons">
                <button type="button" class="status-btn btn-safe <?= $currentStatus==='safe_at_home'?'selected':'' ?>"
                    onclick="submitStatus('safe_at_home')">
                    <i class="bi bi-house-check-fill"></i>
                    Safe at Home
                </button>
                <button type="button" class="status-btn btn-evacuated <?= $currentStatus==='evacuated'?'selected':'' ?>"
                    onclick="openEvacModal()">
                    <i class="bi bi-building-fill"></i>
                    Evacuated
                </button>
                <button type="button" class="status-btn btn-help <?= $currentStatus==='need_help'?'selected':'' ?>"
                    onclick="submitStatus('need_help')">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Need Help
                </button>
            </div>
        </div>
    </div>

    <!-- ── Quick Actions ── -->
    <div class="quick-actions">
        <a href="<?= BASE_URL ?>/modules/resident/evacuation_centers.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#EBF5FB; color:#2980B9;">
                <i class="bi bi-map-fill"></i>
            </div>
            <div>
                <div>Evacuation</div>
                <div style="font-size:0.72rem; color:var(--rescue-muted); font-weight:400;">Centers</div>
            </div>
        </a>
        <a href="<?= BASE_URL ?>/modules/resident/contacts.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#EAFAF1; color:#1E8449;">
                <i class="bi bi-telephone-fill"></i>
            </div>
            <div>
                <div>Emergency</div>
                <div style="font-size:0.72rem; color:var(--rescue-muted); font-weight:400;">Contacts</div>
            </div>
        </a>
    </div>

    <!-- ── Active Alerts ── -->
    <div class="r-card">
        <div class="r-card-title" style="justify-content:space-between;">
            <span style="display:flex; align-items:center; gap:7px;">
                <i class="bi bi-bell-fill"></i>
                Active Alerts
                <?php if ($totalAlerts > 0): ?>
                <span style="background:var(--rescue-red); color:#fff; font-size:0.65rem; font-weight:700; padding:1px 6px; border-radius:10px;"><?= $totalAlerts ?></span>
                <?php endif; ?>
            </span>
            <?php if ($totalAlerts > 3): ?>
            <a href="<?= BASE_URL ?>/modules/resident/alerts.php"
                style="font-size:0.75rem; color:var(--rescue-red); text-decoration:none; font-weight:600;">
                View all <i class="bi bi-arrow-right"></i>
            </a>
            <?php endif; ?>
        </div>

        <?php if (empty($alerts)): ?>
        <div style="text-align:center; padding:1.5rem 0; color:var(--rescue-muted); font-size:0.85rem;">
            <i class="bi bi-bell-slash" style="font-size:1.75rem; display:block; margin-bottom:6px; color:#D5D8DC;"></i>
            No active alerts at this time.
        </div>
        <?php else:
            $typeMeta = [
                'flood'   => ['bg'=>'#EBF5FB','color'=>'#1A5276'],
                'fire'    => ['bg'=>'#FDEDEC','color'=>'#922B21'],
                'general' => ['bg'=>'#EAFAF1','color'=>'#1E8449'],
            ];
            $dotColors = ['high'=>'#C0392B','medium'=>'#D68910','low'=>'#1E8449'];
            foreach ($alerts as $a):
                $tm  = $typeMeta[$a['type']] ?? $typeMeta['general'];
                $dot = $dotColors[$a['severity']] ?? '#888';
        ?>
        <div class="alert-item">
            <div class="alert-dot" style="background:<?= $dot ?>;"></div>
            <div style="flex:1; min-width:0;">
                <p class="alert-title"><?= htmlspecialchars($a['title']) ?></p>
                <p class="alert-msg"><?= htmlspecialchars($a['message']) ?></p>
                <div class="alert-meta">
                    <span class="tbadge" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;"><?= strtoupper($a['type']) ?></span>
                    <span class="sev-<?= $a['severity'] ?>"><?= strtoupper($a['severity']) ?></span>
                    <span class="alert-time"><?= date('M j, g:i A', strtotime($a['created_at'])) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ── Evacuation Centers Preview ── -->
    <div class="r-card">
        <div class="r-card-title" style="justify-content:space-between;">
            <span style="display:flex; align-items:center; gap:7px;">
                <i class="bi bi-building-fill"></i>
                Evacuation Centers
            </span>
            <a href="<?= BASE_URL ?>/modules/resident/evacuation_centers.php"
                style="font-size:0.75rem; color:var(--rescue-red); text-decoration:none; font-weight:600;">
                View map <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($centers)): ?>
        <div style="text-align:center; padding:1rem 0; color:var(--rescue-muted); font-size:0.85rem;">
            No evacuation centers listed yet.
        </div>
        <?php else: foreach ($centers as $c):
            $occ     = (int) $c['occupancy'];
            $cap     = (int) $c['capacity'];
            $isFull  = $cap > 0 && $occ >= $cap;
            $pct     = $cap > 0 ? min(100, round($occ / $cap * 100)) : 0;
            $barClr  = $isFull ? '#E74C3C' : ($pct >= 75 ? '#E67E22' : '#2ECC71');
        ?>
        <div class="center-item">
            <div style="min-width:0; flex:1;">
                <div style="font-size:0.85rem; font-weight:600; color:var(--rescue-text); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <i class="bi bi-geo-alt-fill" style="color:<?= $isFull?'#E74C3C':'#2ECC71' ?>; font-size:0.75rem;"></i>
                    <?= htmlspecialchars($c['name']) ?>
                </div>
                <?php if ($cap > 0): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="occ-bar-bg">
                        <div class="occ-bar-fill" style="width:<?= $pct ?>%; background:<?= $barClr ?>;"></div>
                    </div>
                    <span style="font-size:0.72rem; color:var(--rescue-muted); white-space:nowrap;"><?= $occ ?>/<?= $cap ?></span>
                </div>
                <?php endif; ?>
            </div>
            <span style="font-size:0.72rem; font-weight:700; padding:3px 8px; border-radius:20px; flex-shrink:0;
                background:<?= $isFull?'#FADBD8':'#EAFAF1' ?>;
                color:<?= $isFull?'#922B21':'#1E8449' ?>;">
                <?= $isFull ? 'Full' : 'Available' ?>
            </span>
        </div>
        <?php endforeach; endif; ?>
    </div>

</div>


<!-- ── EVACUATED MODAL ── -->
<div class="r-modal-backdrop" id="evacModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-building-fill" style="color:#3498DB; margin-right:6px;"></i> I'm Evacuated</h5>
            <button onclick="closeModal('evacModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="status" value="evacuated">
            <div class="r-modal-body">
                <p style="font-size:0.85rem; color:var(--rescue-muted); margin-bottom:1.1rem; line-height:1.5;">
                    Please select which evacuation center you are currently at so officials can track your location.
                </p>

                <div style="margin-bottom:1rem;">
                    <label class="form-label" style="font-size:0.82rem;">Evacuation Center</label>
                    <select name="center_id" class="form-control" style="font-size:0.875rem;">
                        <option value="">— Select Center —</option>
                        <?php foreach ($centers as $c): ?>
                        <option value="<?= $c['center_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" style="font-size:0.82rem;">Additional Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="2"
                        placeholder="e.g. I'm with my family, need medical attention..."
                        style="font-size:0.875rem; resize:none;"></textarea>
                </div>

            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('evacModal')">Cancel</button>
                <button type="submit" class="btn-r-submit">
                    <i class="bi bi-building-fill"></i> Confirm Evacuated
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ── HELP CONFIRMATION MODAL ── -->
<div class="r-modal-backdrop" id="helpModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-exclamation-triangle-fill" style="color:var(--rescue-red); margin-right:6px;"></i> I Need Help</h5>
            <button onclick="closeModal('helpModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST" id="helpForm">
            <input type="hidden" name="status" value="need_help">
            <input type="hidden" name="latitude"  id="helpLat">
            <input type="hidden" name="longitude" id="helpLng">
            <div class="r-modal-body">
                <div style="background:var(--rescue-red-light); border:1px solid #F1948A; border-radius:10px; padding:0.875rem; margin-bottom:1rem; font-size:0.85rem; color:var(--rescue-red);">
                    <strong>Emergency?</strong> If this is a life-threatening emergency, call <strong>911</strong> immediately.
                </div>

                <p style="font-size:0.85rem; color:var(--rescue-muted); margin-bottom:1rem; line-height:1.5;">
                    Your status will be reported to barangay officials who will coordinate assistance.
                </p>

                <!-- Location sharing -->
                <!-- Location sharing -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label" style="font-size:0.82rem;">Your Location (Optional)</label>
                    <div id="locationStatus" style="background:#F8F9FA; border:1px solid var(--rescue-border); border-radius:10px; padding:0.875rem; display:flex; align-items:center; gap:10px;">
                        <div id="locationIcon" style="width:36px;height:36px;border-radius:50%;background:#F0F3F4;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;color:var(--rescue-muted);">
                            <i class="bi bi-geo-alt"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div id="locationText" style="font-size:0.82rem;font-weight:600;color:var(--rescue-text);">Share your location</div>
                            <div id="locationSub" style="font-size:0.75rem;color:var(--rescue-muted);">Helps officials find you faster</div>
                        </div>
                        <button type="button" id="locationBtn" onclick="getLocation()"
                            style="background:var(--rescue-red);border:none;border-radius:8px;padding:6px 12px;font-size:0.78rem;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;">
                            Share
                        </button>
                    </div>
                </div>

                <div>
                    <label class="form-label" style="font-size:0.82rem;">Describe your situation (Optional)</label>
                    <textarea name="notes" class="form-control" rows="3"
                        placeholder="e.g. Flooding in the area, trapped, need medical help..."
                        style="font-size:0.875rem; resize:none;"></textarea>
                </div>
            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('helpModal')">Cancel</button>
                <button type="submit" class="btn-r-submit" style="background:var(--rescue-red);">
                    <i class="bi bi-exclamation-triangle-fill"></i> Report Need Help
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ── SAFE CONFIRMATION MODAL ── -->
<div class="r-modal-backdrop" id="safeModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-house-check-fill" style="color:#1E8449; margin-right:6px;"></i> I'm Safe at Home</h5>
            <button onclick="closeModal('safeModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="status" value="safe_at_home">
            <div class="r-modal-body">
                <p style="font-size:0.85rem; color:var(--rescue-muted); margin-bottom:1rem; line-height:1.5;">
                    You are confirming that you are safe and staying at home. Barangay officials will be notified.
                </p>
                <div>
                    <label class="form-label" style="font-size:0.82rem;">Additional Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="2"
                        placeholder="Any additional information..."
                        style="font-size:0.875rem; resize:none;"></textarea>
                </div>
            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('safeModal')">Cancel</button>
                <button type="submit" class="btn-r-submit" style="background:#1E8449;">
                    <i class="bi bi-house-check-fill"></i> Confirm Safe at Home
                </button>
            </div>
        </form>
    </div>
</div>

<!-- RESET STATUS MODAL -->
<div class="r-modal-backdrop" id="resetModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-arrow-counterclockwise" style="color:var(--rescue-muted); margin-right:6px;"></i> Reset Status</h5>
            <button onclick="closeModal('resetModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="text-align:center; padding:1.5rem 1.25rem;">
            <div style="width:52px;height:52px;background:#F2F3F4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:var(--rescue-muted);">
                <i class="bi bi-arrow-counterclockwise"></i>
            </div>
            <p style="font-size:0.9rem;font-weight:700;color:var(--rescue-text);margin-bottom:0.5rem;">
                Clear your current status?
            </p>
            <p style="font-size:0.83rem;color:var(--rescue-muted);line-height:1.6;margin:0;">
                This will remove your latest status report. Barangay officials will see you as
                <strong>Unidentified</strong> until you report again.
            </p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('resetModal')">
                Cancel
            </button>
            <button type="button"
                style="flex:2; padding:0.75rem; border:none; border-radius:12px; background:#5D6D7E; font-size:0.875rem; font-weight:700; color:#fff; cursor:pointer; font-family:inherit;"
                onclick="document.getElementById('resetStatusForm').submit()">
                <i class="bi bi-arrow-counterclockwise"></i> Yes, Reset Status
            </button>
        </div>
    </div>
</div>

<!-- LOCATION CONFIRM MODAL -->
<div class="r-modal-backdrop" id="locationConfirmModal" style="z-index:10001;">
    <div class="r-modal" style="max-width:380px;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);margin-right:6px;"></i> Confirm Your Location</h5>
            <button onclick="closeLocationConfirm()"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="padding:0;">
            <div id="confirmMap" style="height:220px;width:100%;"></div>
            <div style="padding:1rem 1.25rem;">
                <div style="background:#EAFAF1;border:1px solid #A9DFBF;border-radius:10px;padding:0.75rem;font-size:0.8rem;color:#1E8449;display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-geo-alt-fill"></i>
                    <div>
                        <div style="font-weight:700;">Detected Location</div>
                        <div id="confirmCoordsText" style="font-family:monospace;font-size:0.75rem;color:var(--rescue-muted);margin-top:2px;"></div>
                    </div>
                </div>
                <p style="font-size:0.8rem;color:var(--rescue-muted);margin:0.75rem 0 0;line-height:1.5;">
                    Is this your correct location? You can drag the pin to adjust it before confirming.
                </p>
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeLocationConfirm()">Cancel</button>
            <button type="button" class="btn-r-submit" style="background:#1E8449;" onclick="confirmLocation()">
                <i class="bi bi-check-circle-fill"></i> Yes, Use This Location
            </button>
        </div>
    </div>
</div>

<!-- VIEW SUBMITTED LOCATION MODAL -->
<div class="r-modal-backdrop" id="viewLocationModal" style="z-index:10001;">
    <div class="r-modal" style="max-width:380px;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);margin-right:6px;"></i> Your Submitted Location</h5>
            <button onclick="closeModal('viewLocationModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="padding:0;">
            <div id="viewLocationMap" style="height:260px;width:100%;"></div>
            <div style="padding:1rem 1.25rem;">
                <div style="background:var(--rescue-red-light);border:1px solid #F1948A;border-radius:10px;padding:0.75rem;font-size:0.8rem;color:var(--rescue-red);display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-geo-alt-fill"></i>
                    <div>
                        <div style="font-weight:700;">Location shared with officials</div>
                        <div id="viewCoordsText" style="font-family:monospace;font-size:0.75rem;margin-top:2px;opacity:0.8;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('viewLocationModal')">Close</button>
            <button type="button" class="btn-r-submit" style="background:#2980B9;" onclick="openEditLocation()">
                <i class="bi bi-pencil-fill"></i> Edit Location
            </button>
        </div>
    </div>
</div>

<!-- EDIT LOCATION MODAL -->
<div class="r-modal-backdrop" id="editLocationModal" style="z-index:10002;">
    <div class="r-modal" style="max-width:380px;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-pencil-fill" style="color:#2980B9;margin-right:6px;"></i> Edit Your Location</h5>
            <button onclick="closeModal('editLocationModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="padding:0;">
            <div id="editLocationMap" style="height:240px;width:100%;"></div>
            <div style="padding:1rem 1.25rem;">
                <div style="background:#EBF5FB;border:1px solid #AED6F1;border-radius:10px;padding:0.75rem;font-size:0.8rem;color:#1A5276;display:flex;align-items:center;gap:8px;margin-bottom:0.75rem;">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>
                        <div style="font-weight:700;">Drag the pin or click map to reposition</div>
                        <div id="editCoordsText" style="font-family:monospace;font-size:0.75rem;margin-top:2px;opacity:0.8;"></div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button"
                        style="flex:1;padding:8px;border:1.5px solid var(--rescue-border);border-radius:8px;background:#fff;font-size:0.78rem;font-weight:700;color:var(--rescue-muted);cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;"
                        onclick="redetectLocation()">
                        <i class="bi bi-crosshair"></i> Redetect GPS
                    </button>
                </div>
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('editLocationModal')">Cancel</button>
            <button type="button" class="btn-r-submit" style="background:#2980B9;" onclick="saveEditedLocation()">
                <i class="bi bi-check-circle-fill"></i> Save Location
            </button>
        </div>
    </div>
</div>

<!-- CONFIRM SAVE LOCATION MODAL -->
<div class="r-modal-backdrop" id="confirmSaveLocationModal" style="z-index:10003;">
    <div class="r-modal" style="max-width:340px;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-geo-alt-fill" style="color:#2980B9;margin-right:6px;"></i> Save New Location?</h5>
            <button onclick="closeModal('confirmSaveLocationModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="text-align:center;padding:1.5rem 1.25rem;">
            <div style="width:52px;height:52px;background:#EBF5FB;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:#2980B9;">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <p style="font-size:0.9rem;font-weight:700;color:var(--rescue-text);margin-bottom:0.5rem;">
                Update your location?
            </p>
            <p style="font-size:0.83rem;color:var(--rescue-muted);line-height:1.6;margin:0 0 0.75rem;">
                Your new coordinates will replace the previously shared location. Officials will see the updated pin.
            </p>
            <div style="background:#F8F9FA;border:1px solid var(--rescue-border);border-radius:8px;padding:0.6rem;font-family:monospace;font-size:0.78rem;color:var(--rescue-text);" id="confirmSaveCoords"></div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('confirmSaveLocationModal')">Cancel</button>
            <button type="button" class="btn-r-submit" style="background:#2980B9;" onclick="doSaveLocation()">
                <i class="bi bi-check-circle-fill"></i> Yes, Update Location
            </button>
        </div>
    </div>
</div>

<form method="POST" id="editLocationForm">
    <input type="hidden" name="action" value="update_location">
    <input type="hidden" name="latitude"  id="editLat">
    <input type="hidden" name="longitude" id="editLng">
</form>


<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leaflet.css">

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.r-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

function submitStatus(status) {
    if (status === 'safe_at_home') {
        openModal('safeModal');
    } else if (status === 'need_help') {
        document.getElementById('helpLat').value = '';
        document.getElementById('helpLng').value = '';
        document.getElementById('locationBtn').textContent = 'Share';
        document.getElementById('locationBtn').disabled = false;
        document.getElementById('locationBtn').style.background = 'var(--rescue-red)';
        document.getElementById('locationIcon').innerHTML = '<i class="bi bi-geo-alt"></i>';
        document.getElementById('locationIcon').style.color = 'var(--rescue-muted)';
        document.getElementById('locationIcon').style.background = '#F0F3F4';
        document.getElementById('locationText').textContent = 'Share your location';
        document.getElementById('locationSub').textContent = 'Helps officials find you faster';
        document.getElementById('locationStatus').style.borderColor = 'var(--rescue-border)';
        openModal('helpModal');
    }
}

function openEvacModal() { openModal('evacModal'); }
function confirmReset()  { openModal('resetModal'); }

// ── Geolocation + confirm map ──
let confirmMap     = null;
let confirmMarker  = null;
let pendingLat     = null;
let pendingLng     = null;
let pendingViewLat = null;
let pendingViewLng = null;

function getLocation() {
    const btn    = document.getElementById('locationBtn');
    const icon   = document.getElementById('locationIcon');
    const text   = document.getElementById('locationText');
    const sub    = document.getElementById('locationSub');
    const status = document.getElementById('locationStatus');

    if (!navigator.geolocation) {
        text.textContent = 'Not supported';
        sub.textContent  = 'Your browser does not support location sharing.';
        return;
    }

    btn.textContent       = 'Getting...';
    btn.disabled          = true;
    icon.innerHTML        = '<i class="bi bi-arrow-repeat"></i>';
    icon.style.color      = '#B7770D';
    icon.style.background = '#FEF9E7';
    text.textContent      = 'Getting your location...';

    navigator.geolocation.getCurrentPosition(
        function(position) {
            pendingLat = position.coords.latitude;
            pendingLng = position.coords.longitude;

            btn.textContent  = 'Share';
            btn.disabled     = false;

            openLocationConfirm(pendingLat, pendingLng);
        },
        function(err) {
            icon.innerHTML        = '<i class="bi bi-geo-alt-fill"></i>';
            icon.style.color      = 'var(--rescue-red)';
            icon.style.background = 'var(--rescue-red-light)';

            let msg = 'Could not get location.';
            if (err.code === 1) msg = 'Location permission denied.';
            if (err.code === 2) msg = 'Location unavailable.';
            if (err.code === 3) msg = 'Location request timed out.';

            text.textContent             = msg;
            sub.textContent              = 'You can still submit without location.';
            status.style.borderColor     = '#F1948A';
            btn.textContent              = 'Try again';
            btn.disabled                 = false;
        },
        { timeout: 10000, maximumAge: 60000 }
    );
}

function openLocationConfirm(lat, lng) {
    document.getElementById('confirmCoordsText').textContent =
        lat.toFixed(5) + ', ' + lng.toFixed(5);

    openModal('locationConfirmModal');

    // Init or reuse map
    setTimeout(() => {
        if (!confirmMap) {
            confirmMap = L.map('confirmMap', { zoomControl: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
            }).addTo(confirmMap);
        }

        confirmMap.setView([lat, lng], 17);

        if (confirmMarker) confirmMap.removeLayer(confirmMarker);

        confirmMarker = L.marker([lat, lng], { draggable: true })
            .addTo(confirmMap)
            .bindPopup('Drag to adjust your location')
            .openPopup();

        confirmMarker.on('dragend', function() {
            const pos = confirmMarker.getLatLng();
            pendingLat = pos.lat;
            pendingLng = pos.lng;
            document.getElementById('confirmCoordsText').textContent =
                pos.lat.toFixed(5) + ', ' + pos.lng.toFixed(5);
        });

        confirmMap.invalidateSize();
    }, 150);
}

function confirmLocation() {
    const lat = pendingLat;
    const lng = pendingLng;

    document.getElementById('helpLat').value = lat.toFixed(7);
    document.getElementById('helpLng').value = lng.toFixed(7);

    // Update the location status UI in the help modal
    const icon   = document.getElementById('locationIcon');
    const text   = document.getElementById('locationText');
    const sub    = document.getElementById('locationSub');
    const status = document.getElementById('locationStatus');
    const btn    = document.getElementById('locationBtn');

    icon.innerHTML        = '<i class="bi bi-geo-alt-fill"></i>';
    icon.style.color      = '#1E8449';
    icon.style.background = '#EAFAF1';
    text.textContent      = 'Location confirmed';
    sub.textContent       = lat.toFixed(5) + ', ' + lng.toFixed(5);
    status.style.borderColor = '#A9DFBF';
    btn.textContent       = 'Recapture';
    btn.disabled          = false;
    btn.style.background  = '#1E8449';

    closeLocationConfirm();
}

function closeLocationConfirm() {
    closeModal('locationConfirmModal');
}

// ── View submitted location ──
let viewLocMap    = null;
let viewLocMarker = null;

function viewSubmittedLocation(lat, lng) {

    pendingViewLat = lat;   // ← add this
    pendingViewLng = lng;   // ← add this

    document.getElementById('viewCoordsText').textContent =
        parseFloat(lat).toFixed(5) + ', ' + parseFloat(lng).toFixed(5);

    openModal('viewLocationModal');

    setTimeout(() => {
        if (!viewLocMap) {
            viewLocMap = L.map('viewLocationMap', { zoomControl: true, dragging: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
            }).addTo(viewLocMap);
        }

        viewLocMap.setView([lat, lng], 17);

        if (viewLocMarker) viewLocMap.removeLayer(viewLocMarker);

        viewLocMarker = L.circleMarker([lat, lng], {
            radius: 10, color: '#C0392B', fillColor: '#E74C3C',
            fillOpacity: 0.9, weight: 2,
        })
        .addTo(viewLocMap)
        .bindPopup('<strong style="color:#C0392B;">⚠ Your Help Request</strong>')
        .openPopup();

        viewLocMap.invalidateSize();
    }, 150);
}

// ── Edit location ──
let editLocMap    = null;
let editLocMarker = null;
let editLat       = null;
let editLng       = null;

function openEditLocation() {
    // Seed with the currently viewed coords
    editLat = pendingViewLat;
    editLng = pendingViewLng;

    closeModal('viewLocationModal');
    openModal('editLocationModal');

    document.getElementById('editCoordsText').textContent =
        parseFloat(editLat).toFixed(5) + ', ' + parseFloat(editLng).toFixed(5);

    setTimeout(() => {
        if (!editLocMap) {
            editLocMap = L.map('editLocationMap', { zoomControl: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
            }).addTo(editLocMap);

            // Click map to reposition
            editLocMap.on('click', function(e) {
                editLat = e.latlng.lat;
                editLng = e.latlng.lng;
                editLocMarker.setLatLng([editLat, editLng]);
                document.getElementById('editCoordsText').textContent =
                    editLat.toFixed(5) + ', ' + editLng.toFixed(5);
            });
        }

        editLocMap.setView([editLat, editLng], 17);

        if (editLocMarker) editLocMap.removeLayer(editLocMarker);

        editLocMarker = L.marker([editLat, editLng], { draggable: true })
            .addTo(editLocMap)
            .bindPopup('Drag or click map to adjust')
            .openPopup();

        editLocMarker.on('dragend', function() {
            const pos = editLocMarker.getLatLng();
            editLat = pos.lat;
            editLng = pos.lng;
            document.getElementById('editCoordsText').textContent =
                pos.lat.toFixed(5) + ', ' + pos.lng.toFixed(5);
        });

        editLocMap.invalidateSize();
    }, 150);
}

function redetectLocation() {
    if (!navigator.geolocation) return;

    document.getElementById('editCoordsText').textContent = 'Detecting...';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            editLat = pos.coords.latitude;
            editLng = pos.coords.longitude;
            editLocMap.setView([editLat, editLng], 17);
            editLocMarker.setLatLng([editLat, editLng]);
            document.getElementById('editCoordsText').textContent =
                editLat.toFixed(5) + ', ' + editLng.toFixed(5);
        },
        function() {
            document.getElementById('editCoordsText').textContent = 'Could not detect. Drag pin manually.';
        },
        { timeout: 8000 }
    );
}

function saveEditedLocation() {
    // Show confirm modal instead of submitting directly
    document.getElementById('confirmSaveCoords').textContent =
        editLat.toFixed(5) + ', ' + editLng.toFixed(5);
    openModal('confirmSaveLocationModal');
}

function doSaveLocation() {
    document.getElementById('editLat').value = editLat.toFixed(7);
    document.getElementById('editLng').value = editLng.toFixed(7);
    document.getElementById('editLocationForm').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_resident.php'; ?>
