<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Evacuation Centers';
$activePage = 'evacuation';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD CENTER ──
    if ($action === 'add_center') {
        $name     = trim($_POST['name'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        $capacity = (int) ($_POST['capacity'] ?? 0);
        $phone    = trim($_POST['phone'] ?? '');
        $lat      = $_POST['latitude'] ?? '';
        $lng      = $_POST['longitude'] ?? '';

        if (empty($name) || empty($lat) || empty($lng)) {
            $error = 'Please click on the map to place the center and provide a name.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO evacuation_centers (name, address, latitude, longitude, capacity, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$name, $address ?: null, $lat, $lng, $capacity ?: null]);
            $success = 'Evacuation center "' . htmlspecialchars($name) . '" added successfully.';
        }
    }

    // ── EDIT CENTER ──
    if ($action === 'edit_center') {
        $centerId = (int) ($_POST['center_id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        $capacity = (int) ($_POST['capacity'] ?? 0);
        $phone    = trim($_POST['phone'] ?? '');

        if (empty($name)) {
            $error = 'Center name is required.';
        } elseif ($centerId) {
            $stmt = $conn->prepare("
                UPDATE evacuation_centers
                SET name=?, address=?, capacity=?
                WHERE center_id=?
            ");
            $stmt->execute([$name, $address ?: null, $capacity ?: null, $centerId]);
            $success = 'Evacuation center updated successfully.';
        }
    }

    // ── TOGGLE STATUS ──
    if ($action === 'toggle_center') {
        $centerId  = (int) ($_POST['center_id'] ?? 0);
        $newStatus = (int) ($_POST['new_status'] ?? 0);
        if ($centerId) {
            $conn->prepare("UPDATE evacuation_centers SET is_active=? WHERE center_id=?")
                 ->execute([$newStatus, $centerId]);
            $success = $newStatus ? 'Center activated.' : 'Center deactivated.';
        }
    }

    // ── DELETE CENTER ──
    if ($action === 'delete_center') {
        $centerId = (int) ($_POST['center_id'] ?? 0);
        if ($centerId) {
            $conn->prepare("DELETE FROM evacuation_centers WHERE center_id=?")
                 ->execute([$centerId]);
            $success = 'Evacuation center deleted.';
        }
    }
}

// ── Fetch centers with occupancy ──
$stmtCenters = $conn->query("
    SELECT
        ec.center_id, ec.name, ec.address, ec.latitude, ec.longitude,
        ec.capacity, ec.is_active, ec.created_at,
        COUNT(DISTINCT sr.resident_id) AS occupancy
    FROM evacuation_centers ec
    LEFT JOIN safety_reports sr ON sr.center_id = ec.center_id
        AND sr.status = 'evacuated'
        AND sr.report_id = (
            SELECT MAX(sr2.report_id) FROM safety_reports sr2
            WHERE sr2.resident_id = sr.resident_id
        )
    GROUP BY ec.center_id
    ORDER BY ec.is_active DESC, ec.name ASC
");
$centers = $stmtCenters->fetchAll();

// Summary stats
$totalCenters    = count($centers);
$activeCenters   = 0;
$totalCapacity   = 0;
$totalOccupancy  = 0;

foreach ($centers as $c) {
    if ($c['is_active']) $activeCenters++;
    $totalCapacity  += (int) $c['capacity'];
    $totalOccupancy += (int) $c['occupancy'];
}

$centersJson = json_encode(array_map(function($c) {
    return [
        'center_id' => $c['center_id'],
        'name'      => $c['name'],
        'address'   => $c['address'] ?? '',
        'latitude'  => $c['latitude'],
        'longitude' => $c['longitude'],
        'capacity'  => $c['capacity'],
        'occupancy' => $c['occupancy'],
        'is_active' => $c['is_active'],
    ];
}, $centers));

require_once __DIR__ . '/../../includes/header_official.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leaflet.css">

<style>
    #evac-map {
        height: 420px;
        border-radius: 12px;
        z-index: 1;
    }

    .placing-hint {
        background: #EAFAF1;
        border: 1px solid #A9DFBF;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        font-size: 0.82rem;
        color: #1E8449;
        margin-bottom: 1rem;
        display: none;
        align-items: center;
        gap: 8px;
    }

    .placing-hint.visible { display: flex; }

    .center-card {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1.1rem 1.25rem;
        margin-bottom: 0.75rem;
        transition: box-shadow 0.2s;
    }

    .center-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
    .center-card:last-child { margin-bottom: 0; }

    .center-card.inactive { opacity: 0.6; }

    .occupancy-bar-wrap {
        height: 6px;
        background: #F0F3F4;
        border-radius: 10px;
        overflow: hidden;
        margin: 6px 0 4px;
    }

    .occupancy-bar-fill {
        height: 100%;
        border-radius: 10px;
        transition: width 0.5s ease;
    }

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
    .action-btn.blue:hover  { background:#EBF5FB; }
    .action-btn.warning { color:#B7770D; border-color:#FAD7A0; }
    .action-btn.warning:hover { background:#FEF9E7; }
    .action-btn.success { color:#1E8449; border-color:#A9DFBF; }
    .action-btn.success:hover { background:#EAFAF1; }
    .action-btn.danger  { color:var(--rescue-red); border-color:var(--rescue-red-light); }
    .action-btn.danger:hover  { background:var(--rescue-red-light); }

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
</style>

<!-- Page header -->
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">Evacuation Center Management</h1>
        <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">Manage evacuation centers and monitor occupancy</p>
    </div>
    <button class="btn-rescue" id="addCenterBtn" onclick="startPlacing()">
        + Add Center
    </button>
</div>

<!-- Feedback -->
<?php if ($success): ?>
<div style="background:#EAFAF1; border:1px solid #A9DFBF; color:#1E8449; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem;">
    <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:var(--rescue-red-light); border:1px solid #F1948A; color:var(--rescue-red-dark); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem;">
    <?= $error ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:14px; margin-bottom:1.75rem;">
    <div class="stat-card">
        <div><div class="stat-label">Total Centers</div><div class="stat-value" style="color:var(--rescue-text);"><?= $totalCenters ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB; color:#2980B9;">C</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Available</div><div class="stat-value" style="color:#1E8449;"><?= $activeCenters ?></div></div>
        <div class="stat-icon" style="background:#EAFAF1; color:#1E8449;">A</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Total Capacity</div><div class="stat-value" style="color:var(--rescue-text);"><?= $totalCapacity ?></div></div>
        <div class="stat-icon" style="background:#F8F9FA; color:#5D6D7E;">T</div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Current Occupancy</div><div class="stat-value" style="color:<?= $totalOccupancy >= $totalCapacity && $totalCapacity > 0 ? 'var(--rescue-red)' : '#B7770D' ?>;"><?= $totalOccupancy ?></div></div>
        <div class="stat-icon" style="background:#FEF9E7; color:#B7770D;">O</div>
    </div>
</div>

<!-- Placing hint -->
<div class="placing-hint" id="placingHint">
    <span style="font-size:1.1rem;">&#127968;</span>
    <span>Click anywhere on the map to place the evacuation center. Press <strong>ESC</strong> or click Cancel to stop.</span>
    <button type="button" onclick="cancelPlacing()"
        style="margin-left:auto; background:none; border:1px solid #A9DFBF; border-radius:7px; padding:3px 10px; font-size:0.78rem; color:#1E8449; cursor:pointer; font-family:inherit; white-space:nowrap; flex-shrink:0;">
        Cancel
    </button>
</div>

<div class="row g-4">

    <!-- Left: Map -->
    <div class="col-12 col-xl-7">
        <div class="rescue-card" style="padding:1.25rem;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                <span style="font-size:0.95rem; font-weight:700;">Evacuation Centers Map</span>
                <div style="display:flex; align-items:center; gap:8px; font-size:0.78rem; color:var(--rescue-muted);">
                    <span style="display:flex; align-items:center; gap:4px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:#2ECC71;display:inline-block;"></span> Available
                    </span>
                    <span style="display:flex; align-items:center; gap:4px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:var(--rescue-red);display:inline-block;"></span> Full
                    </span>
                    <span style="display:flex; align-items:center; gap:4px;">
                        <span style="width:10px;height:10px;border-radius:50%;background:#AAB7B8;display:inline-block;"></span> Inactive
                    </span>
                </div>
            </div>
            <div id="evac-map"></div>
        </div>
    </div>

    <!-- Right: Center list -->
    <div class="col-12 col-xl-5">
        <div class="rescue-card" style="padding:0; overflow:hidden;">
            <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--rescue-border); display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:0.9rem; font-weight:700;">Evacuation Centers</span>
                <span style="font-size:0.8rem; color:var(--rescue-muted);"><?= $totalCenters ?> total</span>
            </div>

            <div style="padding:1rem 1.25rem;">
                <?php if (empty($centers)): ?>
                <div style="text-align:center; padding:2rem 0; color:var(--rescue-muted); font-size:0.85rem;">
                    No evacuation centers yet.<br>Click "+ Add Center" to get started.
                </div>
                <?php else: ?>
                <?php foreach ($centers as $c):
                    $occupancy  = (int) $c['occupancy'];
                    $capacity   = (int) $c['capacity'];
                    $isFull     = $capacity > 0 && $occupancy >= $capacity;
                    $pct        = $capacity > 0 ? min(100, round($occupancy / $capacity * 100)) : 0;
                    $barColor   = $isFull ? 'var(--rescue-red)' : ($pct >= 75 ? '#E67E22' : '#2ECC71');
                    $dotColor   = !$c['is_active'] ? '#AAB7B8' : ($isFull ? 'var(--rescue-red)' : '#2ECC71');

                    $cData = json_encode([
                        'center_id' => $c['center_id'],
                        'name'      => $c['name'],
                        'address'   => $c['address'] ?? '',
                        'capacity'  => $capacity,
                        'occupancy' => $occupancy,
                        'is_active' => $c['is_active'],
                        'lat'       => $c['latitude'],
                        'lng'       => $c['longitude'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                <div class="center-card <?= !$c['is_active'] ? 'inactive' : '' ?>">

                    <!-- Header -->
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:0.6rem;">
                        <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                            <span style="width:10px; height:10px; border-radius:50%; background:<?= $dotColor ?>; flex-shrink:0;"></span>
                            <span style="font-size:0.9rem; font-weight:700; color:var(--rescue-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars($c['name']) ?>
                            </span>
                        </div>
                        <span style="font-size:0.72rem; font-weight:700; padding:2px 9px; border-radius:20px; flex-shrink:0;
                            background:<?= !$c['is_active'] ? '#F2F3F4' : ($isFull ? 'var(--rescue-red-light)' : '#EAFAF1') ?>;
                            color:<?= !$c['is_active'] ? 'var(--rescue-muted)' : ($isFull ? 'var(--rescue-red)' : '#1E8449') ?>;">
                            <?= !$c['is_active'] ? 'Inactive' : ($isFull ? 'Full' : 'Available') ?>
                        </span>
                    </div>

                    <?php if ($c['address']): ?>
                    <div style="font-size:0.78rem; color:var(--rescue-muted); margin-bottom:0.5rem;">
                        <?= htmlspecialchars($c['address']) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Occupancy bar -->
                    <div style="font-size:0.78rem; color:var(--rescue-muted); display:flex; justify-content:space-between; margin-bottom:2px;">
                        <span>Occupancy</span>
                        <span style="font-weight:700; color:var(--rescue-text);">
                            <?= $occupancy ?> / <?= $capacity ?: '—' ?>
                            <?php if ($capacity > 0): ?>
                            <span style="color:var(--rescue-muted); font-weight:400;">(<?= $pct ?>%)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($capacity > 0): ?>
                    <div class="occupancy-bar-wrap">
                        <div class="occupancy-bar-fill" style="width:<?= $pct ?>%; background:<?= $barColor ?>;"></div>
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:0.75rem;">
                        <button class="action-btn blue" onclick='viewCenter(<?= $cData ?>)'>View</button>
                        <button class="action-btn" onclick='openEditModal(<?= $cData ?>)'>Edit</button>
                        <button class="action-btn" onclick='focusCenter(<?= $c['latitude'] ?>, <?= $c['longitude'] ?>)'>
                            Locate
                        </button>
                        <button class="action-btn <?= $c['is_active'] ? 'warning' : 'success' ?>"
                            onclick='openToggleModal(<?= $cData ?>)'>
                            <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                        <form method="POST" id="delCenterForm<?= $c['center_id'] ?>">
                            <input type="hidden" name="action" value="delete_center">
                            <input type="hidden" name="center_id" value="<?= $c['center_id'] ?>">
                            <button type="button" class="action-btn danger"
                                onclick="confirmDeleteCenter(<?= $c['center_id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>


<!-- ── ADD CENTER MODAL ── -->
<div class="rescue-modal-backdrop" id="addCenterModal">
    <div class="rescue-modal">
        <div class="rescue-modal-header">
            <h5>Add Evacuation Center</h5>
            <button onclick="closeModal('addCenterModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_center">
            <input type="hidden" name="latitude"  id="centerLat">
            <input type="hidden" name="longitude" id="centerLng">
            <div class="rescue-modal-body">

                <div style="background:#EAFAF1; border:1px solid #A9DFBF; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.82rem; color:#1E8449;">
                    Placed at: <strong id="centerCoordsDisplay" style="font-family:monospace;"></strong>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Center Name *</label>
                    <input type="text" name="name" class="form-control" required
                        placeholder="e.g. Barangay Hall, Elementary School">
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control"
                        placeholder="e.g. Brgy 12, Nasugbu, Batangas">
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Maximum Capacity</label>
                    <input type="number" name="capacity" class="form-control" min="0"
                        placeholder="e.g. 200">
                    <small style="font-size:0.75rem; color:var(--rescue-muted);">
                        Leave blank if capacity is unknown.
                    </small>
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('addCenterModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">Save Center</button>
            </div>
        </form>
    </div>
</div>


<!-- ── VIEW CENTER MODAL ── -->
<div class="rescue-modal-backdrop" id="viewCenterModal">
    <div class="rescue-modal" style="max-width:440px;">
        <div class="rescue-modal-header">
            <h5>Center Details</h5>
            <button onclick="closeModal('viewCenterModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body">

            <!-- Name + status -->
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:1.25rem; padding-bottom:1rem; border-bottom:1px solid var(--rescue-border);">
                <div style="width:46px;height:46px;border-radius:50%;background:#EAFAF1;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;">
                    &#127968;
                </div>
                <div>
                    <div id="vc-name" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div>
                    <div id="vc-status-badge" style="margin-top:4px;"></div>
                </div>
            </div>

            <!-- Occupancy visual -->
            <div style="background:#F8F9FA; border:1px solid var(--rescue-border); border-radius:10px; padding:1rem; margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span style="font-size:0.82rem; font-weight:700; color:var(--rescue-muted);">Current Occupancy</span>
                    <span id="vc-occ-text" style="font-size:0.9rem; font-weight:700;"></span>
                </div>
                <div class="occupancy-bar-wrap" style="height:8px;">
                    <div id="vc-occ-bar" class="occupancy-bar-fill" style="height:100%;"></div>
                </div>
                <div style="font-size:0.75rem; color:var(--rescue-muted); margin-top:4px;" id="vc-occ-note"></div>
                <!-- Residents in this center -->
                <div style="margin-top:1.1rem; padding-top:1rem; border-top:1px solid var(--rescue-border);">
                    <div style="font-size:0.82rem; font-weight:700; color:var(--rescue-text); margin-bottom:0.75rem; display:flex; align-items:center; justify-content:space-between;">
                        <span>Residents Currently Here</span>
                        <span id="vc-resident-count" style="font-size:0.75rem; color:var(--rescue-muted); font-weight:500;"></span>
                    </div>
                    <div id="vc-residents-list">
                        <div style="text-align:center; padding:1rem; color:var(--rescue-muted); font-size:0.82rem;">
                            Loading...
                        </div>
                    </div>
                </div>
            </div>

            <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value" id="vc-address"></span></div>
            <div class="detail-row"><span class="detail-label">Capacity</span><span class="detail-value" id="vc-capacity"></span></div>
            <div class="detail-row"><span class="detail-label">Coordinates</span><span class="detail-value" id="vc-coords" style="font-family:monospace;font-size:0.8rem;"></span></div>

        </div>
        <div class="rescue-modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn-modal-cancel"
                style="color:var(--rescue-red); border-color:var(--rescue-red-light);"
                onclick="confirmDeleteFromView()">
                Delete Center
            </button>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('viewCenterModal')">Close</button>
                <button type="button" class="btn-modal-submit" style="background:#2980B9;"
                    onclick="editFromView()">
                    Edit Details
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ── EDIT CENTER MODAL ── -->
<div class="rescue-modal-backdrop" id="editCenterModal">
    <div class="rescue-modal">
        <div class="rescue-modal-header">
            <h5>Edit Evacuation Center</h5>
            <button onclick="closeModal('editCenterModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_center">
            <input type="hidden" name="center_id" id="editCenterId">
            <div class="rescue-modal-body">

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Center Name *</label>
                    <input type="text" name="name" id="editCenterName" class="form-control" required>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" id="editCenterAddress" class="form-control">
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Maximum Capacity</label>
                    <input type="number" name="capacity" id="editCenterCapacity" class="form-control" min="0">
                </div>

                <div style="background:#FEF9E7; border:1px solid #FAD7A0; border-radius:10px; padding:0.75rem 1rem; font-size:0.8rem; color:#7D6608;">
                    To move the center to a different location, delete it and add a new one by clicking on the map.
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('editCenterModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<!-- ── TOGGLE CONFIRM MODAL ── -->
<div class="rescue-modal-backdrop" id="toggleCenterModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:400px;">
        <div class="rescue-modal-header">
            <h5 id="toggle-center-title">Deactivate Center</h5>
            <button onclick="closeModal('toggleCenterModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body" style="text-align:center;">
            <div id="toggle-center-icon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.5rem;"></div>
            <p style="font-size:0.9rem;font-weight:600;color:var(--rescue-text);margin-bottom:0.5rem;" id="toggle-center-message"></p>
            <p style="font-size:0.82rem;color:var(--rescue-muted);line-height:1.6;margin:0;" id="toggle-center-explanation"></p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_center">
            <input type="hidden" name="center_id" id="toggleCenterId">
            <input type="hidden" name="new_status" id="toggleCenterNewStatus">
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('toggleCenterModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit" id="toggleCenterSubmitBtn"></button>
            </div>
        </form>
    </div>
</div>


<!-- ── DELETE CONFIRM MODAL ── -->
<div class="rescue-modal-backdrop" id="deleteCenterModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-body" style="text-align:center;padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:var(--rescue-red-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:var(--rescue-red);">
                &#128465;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Delete Evacuation Center</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to delete
                <strong id="deleteCenterName" style="color:var(--rescue-text);"></strong>?
                This cannot be undone.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center;gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;" onclick="closeModal('deleteCenterModal')">Cancel</button>
            <button type="button" class="btn-modal-submit" style="flex:1;" id="deleteCenterConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>


<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<script>
const CENTERS  = <?= $centersJson ?>;

// ── Map — Brgy 12 Nasugbu Batangas ──
const map = L.map('evac-map', {
    center: [14.0639, 120.6358],
    zoom: 16,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
}).addTo(map);

const markerRefs = {};

// Render existing centers
CENTERS.forEach(center => {
    if (!center.latitude || !center.longitude) return;

    const capacity  = parseInt(center.capacity) || 0;
    const occupancy = parseInt(center.occupancy) || 0;
    const isFull    = capacity > 0 && occupancy >= capacity;
    const color     = !center.is_active ? '#AAB7B8' : (isFull ? '#C0392B' : '#2ECC71');

    const marker = L.circleMarker([center.latitude, center.longitude], {
        radius: 10, color: color, fillColor: color,
        fillOpacity: 0.85, weight: 2,
    });

    marker.bindPopup(`
        <div style="font-family:inherit;min-width:160px;">
            <strong>${center.name}</strong><br>
            <span style="font-size:0.78rem;color:#666;">
                Occupancy: ${occupancy}${capacity > 0 ? ' / ' + capacity : ''}<br>
                Status: ${!center.is_active ? 'Inactive' : (isFull ? 'Full' : 'Available')}
            </span>
        </div>
    `);

    marker.addTo(map);
    markerRefs[center.center_id] = marker;
});

function focusCenter(lat, lng) {
    map.setView([lat, lng], 18);
}

// ── Place marker mode ──
let isPlacing  = false;
let tempMarker = null;

function startPlacing() {
    isPlacing = true;
    map.getContainer().style.cursor = 'crosshair';
    document.getElementById('placingHint').classList.add('visible');
    document.getElementById('addCenterBtn').textContent = 'Click on the map...';
    document.getElementById('addCenterBtn').disabled    = true;
}

function cancelPlacing() {
    isPlacing = false;
    map.getContainer().style.cursor = '';
    document.getElementById('placingHint').classList.remove('visible');
    document.getElementById('addCenterBtn').textContent = '+ Add Center';
    document.getElementById('addCenterBtn').disabled    = false;

    if (tempMarker) { map.removeLayer(tempMarker); tempMarker = null; }
}

map.on('click', function(e) {
    if (!isPlacing) return;

    if (tempMarker) map.removeLayer(tempMarker);
    tempMarker = L.marker([e.latlng.lat, e.latlng.lng]).addTo(map);

    document.getElementById('centerLat').value = e.latlng.lat.toFixed(7);
    document.getElementById('centerLng').value = e.latlng.lng.toFixed(7);
    document.getElementById('centerCoordsDisplay').textContent =
        e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);

    cancelPlacing();
    openModal('addCenterModal');
});

// ── View center ──
let currentCenter = null;

function viewCenter(c) {
    currentCenter = c;

    const capacity  = parseInt(c.capacity) || 0;
    const occupancy = parseInt(c.occupancy) || 0;
    const isFull    = capacity > 0 && occupancy >= capacity;
    const pct       = capacity > 0 ? Math.min(100, Math.round(occupancy / capacity * 100)) : 0;
    const barColor  = isFull ? 'var(--rescue-red)' : (pct >= 75 ? '#E67E22' : '#2ECC71');

    document.getElementById('vc-name').textContent    = c.name;
    document.getElementById('vc-address').textContent = c.address || '—';
    document.getElementById('vc-capacity').textContent = capacity > 0 ? capacity + ' persons' : 'Not specified';
    document.getElementById('vc-coords').textContent  =
        parseFloat(c.lat).toFixed(5) + ', ' + parseFloat(c.lng).toFixed(5);

    document.getElementById('vc-status-badge').innerHTML = c.is_active
        ? (isFull
            ? '<span style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:20px;background:var(--rescue-red-light);color:var(--rescue-red);">Full</span>'
            : '<span style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:20px;background:#EAFAF1;color:#1E8449;">Available</span>')
        : '<span style="font-size:0.72rem;font-weight:700;padding:2px 9px;border-radius:20px;background:#F2F3F4;color:var(--rescue-muted);">Inactive</span>';

    document.getElementById('vc-occ-text').textContent  =
        occupancy + (capacity > 0 ? ' / ' + capacity + ' (' + pct + '%)' : ' evacuees');
    document.getElementById('vc-occ-text').style.color  = barColor;
    document.getElementById('vc-occ-bar').style.width   = (capacity > 0 ? pct : 0) + '%';
    document.getElementById('vc-occ-bar').style.background = barColor;
    document.getElementById('vc-occ-note').textContent  =
        occupancy > 0
            ? occupancy + ' Brgy 12 resident' + (occupancy !== 1 ? 's' : '') + ' currently evacuated here'
            : 'No residents reported evacuated to this center yet';

    // Fetch residents in this center
    document.getElementById('vc-residents-list').innerHTML =
        '<div style="text-align:center;padding:1rem;color:var(--rescue-muted);font-size:0.82rem;">Loading...</div>';

    fetch('<?= BASE_URL ?>/api/get_center_residents.php?center_id=' + c.center_id)
        .then(r => r.json())
        .then(residents => {
            const countEl = document.getElementById('vc-resident-count');
            const listEl  = document.getElementById('vc-residents-list');

            countEl.textContent = residents.length + ' resident' + (residents.length !== 1 ? 's' : '');

            if (residents.length === 0) {
                listEl.innerHTML = `
                    <div style="text-align:center;padding:1rem;color:var(--rescue-muted);font-size:0.82rem;background:#F8F9FA;border-radius:8px;">
                        No residents have reported evacuating here yet.
                    </div>`;
                return;
            }

            listEl.innerHTML = residents.map(r => {
                const name    = r.first_name + ' ' + r.last_name;
                const addr = [r.house_no, r.street, r.area].filter(Boolean).join(', ');
                const time    = r.reported_at
                    ? new Date(r.reported_at).toLocaleString('en-PH', {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'})
                    : '—';
                const initials = (r.first_name[0] + r.last_name[0]).toUpperCase();

                return `
                    <div style="display:flex;align-items:center;gap:10px;padding:0.65rem 0;border-bottom:1px solid var(--rescue-border);">
                        <div style="width:32px;height:32px;border-radius:50%;background:#EBF5FB;display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;color:#2980B9;flex-shrink:0;">
                            ${initials}
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.85rem;font-weight:700;color:var(--rescue-text);">${name}</div>
                            <div style="font-size:0.75rem;color:var(--rescue-muted);">${addr || '—'}</div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            ${r.phone ? `<div style="font-size:0.78rem;color:var(--rescue-red);font-weight:600;">${r.phone}</div>` : ''}
                            <div style="font-size:0.72rem;color:var(--rescue-muted);">${time}</div>
                        </div>
                    </div>`;
            }).join('');

            // Remove last border
            const lastItem = listEl.querySelector('div:last-child');
            if (lastItem) lastItem.style.borderBottom = 'none';
        })
        .catch(() => {
            document.getElementById('vc-residents-list').innerHTML =
                '<div style="text-align:center;padding:1rem;color:var(--rescue-muted);font-size:0.82rem;">Could not load residents.</div>';
        });

    openModal('viewCenterModal');

        openModal('viewCenterModal');
    }

    function editFromView() {
        closeModal('viewCenterModal');
        openEditModal(currentCenter);
    }

    function confirmDeleteFromView() {
        closeModal('viewCenterModal');
        confirmDeleteCenter(currentCenter.center_id, currentCenter.name);
    }

    // ── Edit ──
    function openEditModal(c) {
        currentCenter = c;
        document.getElementById('editCenterId').value       = c.center_id;
        document.getElementById('editCenterName').value     = c.name;
        document.getElementById('editCenterAddress').value  = c.address || '';
        document.getElementById('editCenterCapacity').value = c.capacity || '';
        openModal('editCenterModal');
    }

    // ── Toggle ──
    function openToggleModal(c) {
        currentCenter = c;
        document.getElementById('toggleCenterId').value = c.center_id;
        const deactivating = c.is_active == 1;
        document.getElementById('toggleCenterNewStatus').value = deactivating ? 0 : 1;

        if (deactivating) {
            document.getElementById('toggle-center-title').textContent       = 'Deactivate Center';
            document.getElementById('toggle-center-icon').style.background   = '#FEF9E7';
            document.getElementById('toggle-center-icon').style.color        = '#B7770D';
            document.getElementById('toggle-center-icon').innerHTML          = '&#128683;';
            document.getElementById('toggle-center-message').innerHTML       = `Deactivate <strong>${c.name}</strong>?`;
            document.getElementById('toggle-center-explanation').textContent = 'This center will be marked as unavailable. Residents will no longer be able to select it when reporting evacuated status.';
            document.getElementById('toggleCenterSubmitBtn').textContent     = 'Yes, Deactivate';
            document.getElementById('toggleCenterSubmitBtn').style.background = '#B7770D';
        } else {
            document.getElementById('toggle-center-title').textContent       = 'Activate Center';
            document.getElementById('toggle-center-icon').style.background   = '#EAFAF1';
            document.getElementById('toggle-center-icon').style.color        = '#1E8449';
            document.getElementById('toggle-center-icon').innerHTML          = '&#9989;';
            document.getElementById('toggle-center-message').innerHTML       = `Activate <strong>${c.name}</strong>?`;
            document.getElementById('toggle-center-explanation').textContent = 'This center will be marked as available and residents can select it when reporting their evacuated status.';
            document.getElementById('toggleCenterSubmitBtn').textContent     = 'Yes, Activate';
            document.getElementById('toggleCenterSubmitBtn').style.background = '#1E8449';
        }

    openModal('toggleCenterModal');
}

// ── Delete ──
function confirmDeleteCenter(centerId, name) {
    document.getElementById('deleteCenterName').textContent   = name;
    document.getElementById('deleteCenterConfirmBtn').onclick = function() {
        document.getElementById('delCenterForm' + centerId).submit();
    };
    openModal('deleteCenterModal');
}

// ── Modal helpers ──
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.rescue-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (isPlacing) cancelPlacing();
        document.querySelectorAll('.rescue-modal-backdrop.open')
            .forEach(m => m.classList.remove('open'));
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>