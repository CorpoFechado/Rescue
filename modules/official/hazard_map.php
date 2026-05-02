<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Hazard Map';
$activePage = 'hazard';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_marker') {
        $label       = trim($_POST['label'] ?? '');
        $hazardType  = $_POST['hazard_type'] ?? 'flood';
        $intensity   = $_POST['intensity'] ?? 'high';
        $description = trim($_POST['description'] ?? '');
        $lat         = $_POST['latitude'] ?? '';
        $lng         = $_POST['longitude'] ?? '';

        if (empty($label) || empty($lat) || empty($lng)) {
            $error = 'Please click on the map to place a marker and fill in the label.';
        } else {
            try {
                $conn->beginTransaction();

                $stmtOff = $conn->prepare("SELECT official_id FROM officials WHERE user_id = ?");
                $stmtOff->execute([$_SESSION['user_id']]);
                $official   = $stmtOff->fetch();
                $officialId = $official ? $official['official_id'] : null;

                $intensityVal = $intensity === 'high' ? 1.0 : ($intensity === 'medium' ? 0.6 : 0.3);

                $stmtZone = $conn->prepare("
                    INSERT INTO hazard_zones (added_by, label, description, hazard_type, intensity)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtZone->execute([$officialId, $label, $description ?: null, $hazardType, $intensityVal]);
                $zoneId = $conn->lastInsertId();

                $stmtCoord = $conn->prepare("
                    INSERT INTO hazard_zone_coordinates (zone_id, latitude, longitude, point_order)
                    VALUES (?, ?, ?, 0)
                ");
                $stmtCoord->execute([$zoneId, $lat, $lng]);

                $conn->commit();
                $success = 'Hazard marker "' . htmlspecialchars($label) . '" added successfully.';
            } catch (Exception $e) {
                $conn->rollBack();
                $error = 'Failed to save marker. Please try again.';
            }
        }
    }

    if ($action === 'delete_zone') {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId) {
            $conn->prepare("DELETE FROM hazard_zones WHERE zone_id = ?")->execute([$zoneId]);
            $success = 'Hazard marker deleted.';
        }
    }
}

// Fetch zones
$stmtZones = $conn->query("
    SELECT z.zone_id, z.label, z.description, z.hazard_type, z.intensity,
           c.latitude, c.longitude
    FROM hazard_zones z
    LEFT JOIN hazard_zone_coordinates c ON c.zone_id = z.zone_id AND c.point_order = 0
    ORDER BY z.created_at DESC
");
$zones = $stmtZones->fetchAll();

// Fetch evacuation centers
$stmtCenters = $conn->query("SELECT name, latitude, longitude, capacity FROM evacuation_centers WHERE is_active = 1");
$centers = $stmtCenters->fetchAll();

$zonesJson   = json_encode($zones);
$centersJson = json_encode($centers);

$stats = ['flood' => 0, 'fire' => 0];
foreach ($zones as $z) {
    if (isset($stats[$z['hazard_type']])) $stats[$z['hazard_type']]++;
}

require_once __DIR__ . '/../../includes/header_official.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/leaflet.css">

<style>
    #hazard-map {
        height: 500px;
        border-radius: 12px;
        z-index: 1;
    }

    .placing-hint {
        background: #EBF5FB;
        border: 1px solid #AED6F1;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        font-size: 0.82rem;
        color: #1A5276;
        margin-bottom: 1rem;
        display: none;
        align-items: center;
        gap: 8px;
    }

    .placing-hint.visible { display: flex; }

    .layer-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 0;
        border-bottom: 1px solid var(--rescue-border);
        font-size: 0.85rem;
    }

    .layer-toggle:last-child { border-bottom: none; }

    .layer-toggle-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: var(--rescue-text);
    }

    .layer-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .toggle-switch {
        position: relative;
        width: 38px;
        height: 20px;
        flex-shrink: 0;
    }

    .toggle-switch input { opacity: 0; width: 0; height: 0; }

    .toggle-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #D5D8DC;
        border-radius: 20px;
        transition: 0.2s;
    }

    .toggle-slider:before {
        content: '';
        position: absolute;
        width: 14px;
        height: 14px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        border-radius: 50%;
        transition: 0.2s;
    }

    .toggle-switch input:checked + .toggle-slider { background: var(--rescue-red); }
    .toggle-switch input:checked + .toggle-slider:before { transform: translateX(18px); }

    .zone-item {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 0.9rem 1.1rem;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .zone-item:last-child { margin-bottom: 0; }

    .risk-badge-high   { background:#FADBD8; color:#922B21; font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .risk-badge-medium { background:#FDEBD0; color:#784212; font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .risk-badge-low    { background:#FEF9E7; color:#7D6608; font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:20px; }

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

    .action-btn:hover { background: #F5F5F5; }
    .action-btn.blue  { color:#2980B9; border-color:#AED6F1; }
    .action-btn.blue:hover { background:#EBF5FB; }
    .action-btn.danger { color:var(--rescue-red); border-color:var(--rescue-red-light); }
    .action-btn.danger:hover { background:var(--rescue-red-light); }

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
        max-width: 460px;
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
    .detail-label { width: 120px; font-size: 0.78rem; font-weight: 700; color: var(--rescue-muted); flex-shrink: 0; }
    .detail-value { font-size: 0.85rem; color: var(--rescue-text); flex: 1; }
</style>

<!-- Page header -->
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">Hazard Map Management</h1>
        <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">Click the map to place hazard markers on risk areas</p>
    </div>
    <button class="btn-rescue" id="addMarkerBtn" onclick="startPlacing()">
        + Add Hazard Marker
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

<!-- Placing hint -->
<div class="placing-hint" id="placingHint">
    <span style="font-size:1.1rem;">&#128205;</span>
    <span>Click anywhere on the map to place your hazard marker. Press <strong>ESC</strong> or click Cancel to stop.</span>
    <button type="button" onclick="cancelPlacing()"
        style="margin-left:auto; background:none; border:1px solid #AED6F1; border-radius:7px; padding:3px 10px; font-size:0.78rem; color:#1A5276; cursor:pointer; font-family:inherit; white-space:nowrap; flex-shrink:0;">
        Cancel
    </button>
</div>

<div class="row g-4">

    <!-- Left: Map -->
    <div class="col-12 col-xl-8">
        <div class="rescue-card" style="padding:1.25rem;">

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:8px;">
                <span style="font-size:0.95rem; font-weight:700; color:var(--rescue-text);">
                    Barangay 12, Nasugbu, Batangas
                </span>
                <button class="action-btn" onclick="resetMapView()">Reset View</button>
            </div>

            <div id="hazard-map"></div>

            <!-- Legend -->
            <div style="display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap; margin-top:0.75rem; font-size:0.8rem; color:var(--rescue-muted);">
                <span style="display:flex; align-items:center; gap:5px; font-weight:500;">
                    <span style="width:12px; height:12px; background:#3498DB; border-radius:50%; display:inline-block;"></span>
                    Flood
                </span>
                <span style="display:flex; align-items:center; gap:5px; font-weight:500;">
                    <span style="width:12px; height:12px; background:#C0392B; border-radius:50%; display:inline-block;"></span>
                    Fire
                </span>
                <span style="display:flex; align-items:center; gap:5px; font-weight:500;">
                    <span style="width:12px; height:12px; background:#2ECC71; border-radius:50%; display:inline-block;"></span>
                    Evacuation Centers
                </span>
                <span style="display:flex; align-items:center; gap:5px; color:#888; font-style:italic;">
                    Click a marker to view details
                </span>
            </div>

        </div>
    </div>

    <!-- Right sidebar -->
    <div class="col-12 col-xl-4">

        <!-- Map Layers -->
        <div class="rescue-card mb-4">
            <div class="rescue-card-title">Map Layers</div>

            <div class="layer-toggle">
                <span class="layer-toggle-label">
                    <span class="layer-dot" style="background:#3498DB;"></span>
                    Flood Markers
                </span>
                <label class="toggle-switch">
                    <input type="checkbox" checked onchange="toggleLayer('flood', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="layer-toggle">
                <span class="layer-toggle-label">
                    <span class="layer-dot" style="background:#C0392B;"></span>
                    Fire Markers
                </span>
                <label class="toggle-switch">
                    <input type="checkbox" checked onchange="toggleLayer('fire', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="layer-toggle">
                <span class="layer-toggle-label">
                    <span class="layer-dot" style="background:#2ECC71;"></span>
                    Evacuation Centers
                </span>
                <label class="toggle-switch">
                    <input type="checkbox" onchange="toggleLayer('evac', this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Stats -->
        <div class="rescue-card mb-4">
            <div class="rescue-card-title">Hazard Statistics</div>
            <div style="display:flex; flex-direction:column; gap:0.6rem;">
                <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                    <span style="color:var(--rescue-muted);">Total Markers</span>
                    <strong><?= count($zones) ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                    <span style="color:var(--rescue-muted);">Flood</span>
                    <strong style="color:#2980B9;"><?= $stats['flood'] ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:0.85rem;">
                    <span style="color:var(--rescue-muted);">Fire</span>
                    <strong style="color:#C0392B;"><?= $stats['fire'] ?></strong>
                </div>
            </div>
            <div style="background:#FEF9E7; border:1px solid #FAD7A0; border-radius:10px; padding:0.75rem 1rem; margin-top:1rem; font-size:0.8rem; color:#7D6608; line-height:1.5;">
                <strong>Note:</strong> Hazard markers are based on historical data and risk assessments. Regular updates are recommended.
            </div>
        </div>

        <!-- Zone list -->
        <div class="rescue-card">
            <div class="rescue-card-title" style="justify-content:space-between;">
                Defined Hazard Markers
                <span style="font-size:0.78rem; font-weight:500; color:var(--rescue-muted);"><?= count($zones) ?> total</span>
            </div>

            <?php if (empty($zones)): ?>
            <div style="text-align:center; padding:1.5rem 0; color:var(--rescue-muted); font-size:0.85rem;">
                No hazard markers yet.<br>
                Click "Add Hazard Marker" to get started.
            </div>
            <?php else: ?>
            <?php foreach ($zones as $z):
                $color = $z['hazard_type'] === 'flood' ? '#3498DB' : '#C0392B';
                $risk  = $z['intensity'] >= 0.8 ? 'high' : ($z['intensity'] >= 0.5 ? 'medium' : 'low');
                $zData = json_encode([
                    'zone_id'     => $z['zone_id'],
                    'label'       => $z['label'],
                    'description' => $z['description'] ?? '',
                    'hazard_type' => $z['hazard_type'],
                    'intensity'   => $z['intensity'],
                    'risk'        => $risk,
                    'lat'         => $z['latitude'],
                    'lng'         => $z['longitude'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>
            <div class="zone-item">
                <div style="display:flex; gap:10px; flex:1; min-width:0;">
                    <span style="width:10px; height:10px; border-radius:50%; background:<?= $color ?>; flex-shrink:0; margin-top:4px;"></span>
                    <div style="min-width:0;">
                        <div style="font-size:0.875rem; font-weight:700; color:var(--rescue-text); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= htmlspecialchars($z['label']) ?>
                        </div>
                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                            <span style="font-size:0.7rem; font-weight:700; padding:2px 7px; border-radius:20px; background:<?= $color ?>22; color:<?= $color ?>;">
                                <?= strtoupper($z['hazard_type']) ?>
                            </span>
                            <span class="risk-badge-<?= $risk ?>"><?= strtoupper($risk) ?></span>
                        </div>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px; flex-shrink:0;">
                    <button class="action-btn blue" onclick='viewMarker(<?= $zData ?>)'>View</button>
                    <form method="POST" id="delForm<?= $z['zone_id'] ?>">
                        <input type="hidden" name="action" value="delete_zone">
                        <input type="hidden" name="zone_id" value="<?= $z['zone_id'] ?>">
                        <button type="button" class="action-btn danger"
                            onclick="confirmDelete(<?= $z['zone_id'] ?>, '<?= htmlspecialchars($z['label'], ENT_QUOTES) ?>')">
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


<!-- ADD MARKER MODAL -->
<div class="rescue-modal-backdrop" id="addMarkerModal">
    <div class="rescue-modal">
        <div class="rescue-modal-header">
            <h5>Add Hazard Marker</h5>
            <button onclick="closeModal('addMarkerModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_marker">
            <input type="hidden" name="latitude"  id="markerLat">
            <input type="hidden" name="longitude" id="markerLng">
            <div class="rescue-modal-body">

                <div style="background:#F8F9FA; border:1px solid var(--rescue-border); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.82rem;">
                    Placed at:
                    <strong id="coordsDisplay" style="color:var(--rescue-text); font-family:monospace;"></strong>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Marker Label *</label>
                    <input type="text" name="label" class="form-control" required
                        placeholder="e.g. Riverside Flood Area">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
                    <div>
                        <label class="form-label">Hazard Type *</label>
                        <select name="hazard_type" class="form-control">
                            <option value="flood">Flood</option>
                            <option value="fire">Fire</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Risk Level *</label>
                        <select name="intensity" class="form-control">
                            <option value="high">High Risk</option>
                            <option value="medium">Medium Risk</option>
                            <option value="low">Low Risk</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label">Description (Optional)</label>
                    <textarea name="description" class="form-control" rows="3"
                        placeholder="Additional details about this hazard area..."
                        style="resize:vertical;"></textarea>
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('addMarkerModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">Save Marker</button>
            </div>
        </form>
    </div>
</div>


<!-- VIEW MARKER MODAL -->
<div class="rescue-modal-backdrop" id="viewMarkerModal">
    <div class="rescue-modal" style="max-width:420px;">
        <div class="rescue-modal-header">
            <h5>Hazard Marker Details</h5>
            <button onclick="closeModal('viewMarkerModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body">
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:1.25rem; padding-bottom:1rem; border-bottom:1px solid var(--rescue-border);">
                <div id="vm-icon" style="width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;"></div>
                <div>
                    <div id="vm-label" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div>
                    <div id="vm-badges" style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap;"></div>
                </div>
            </div>
            <div class="detail-row"><span class="detail-label">Hazard Type</span><span class="detail-value" id="vm-type"></span></div>
            <div class="detail-row"><span class="detail-label">Risk Level</span><span class="detail-value" id="vm-risk"></span></div>
            <div class="detail-row"><span class="detail-label">Description</span><span class="detail-value" id="vm-description"></span></div>
            <div class="detail-row"><span class="detail-label">Coordinates</span><span class="detail-value" id="vm-coords" style="font-family:monospace;font-size:0.8rem;"></span></div>
        </div>
        <div class="rescue-modal-footer" style="justify-content:space-between;">
            <button type="button" class="btn-modal-cancel"
                style="color:var(--rescue-red);border-color:var(--rescue-red-light);"
                onclick="confirmDeleteFromView()">
                Delete Marker
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('viewMarkerModal')">Close</button>
                <button type="button" class="btn-modal-submit" style="background:#2980B9;"
                    onclick="focusMarkerOnMap()">
                    Show on Map
                </button>
            </div>
        </div>
    </div>
</div>


<!-- DELETE CONFIRM MODAL -->
<div class="rescue-modal-backdrop" id="deleteMarkerModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:360px;">
        <div class="rescue-modal-body" style="text-align:center;padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:var(--rescue-red-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:var(--rescue-red);">
                &#128465;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Delete Hazard Marker</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to delete
                <strong id="deleteMarkerName" style="color:var(--rescue-text);"></strong>?
                This cannot be undone.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center;gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;"
                onclick="closeModal('deleteMarkerModal')">Cancel</button>
            <button type="button" class="btn-modal-submit" style="flex:1;"
                id="deleteMarkerConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>


<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<script>
const ZONES   = <?= $zonesJson ?>;
const CENTERS = <?= $centersJson ?>;

// ── Map — Brgy 12 Nasugbu Batangas ──
const map = L.map('hazard-map', {
    center: [14.0639, 120.6358],
    zoom: 16,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
}).addTo(map);

// Layer groups
const layers = {
    flood: L.layerGroup().addTo(map),
    fire:  L.layerGroup().addTo(map),
    evac:  L.layerGroup(),
};

const typeColors = { flood: '#3498DB', fire: '#C0392B' };
const markerRefs = {};

// Render existing zones
ZONES.forEach(zone => {
    if (!zone.latitude || !zone.longitude) return;

    const color = typeColors[zone.hazard_type] || '#888';
    const risk  = zone.intensity >= 0.8 ? 'high' : (zone.intensity >= 0.5 ? 'medium' : 'low');
    const zData = {
        zone_id: zone.zone_id, label: zone.label,
        description: zone.description || '',
        hazard_type: zone.hazard_type, intensity: zone.intensity,
        risk: risk, lat: zone.latitude, lng: zone.longitude,
    };

    const marker = L.circleMarker([zone.latitude, zone.longitude], {
        radius: 10, color: color, fillColor: color,
        fillOpacity: 0.85, weight: 2,
    });

    marker.on('click', function(e) {
        L.DomEvent.stopPropagation(e);
        viewMarker(zData);
    });

    layers[zone.hazard_type].addLayer(marker);
    markerRefs[zone.zone_id] = marker;
});

// Evacuation centers
CENTERS.forEach(center => {
    if (!center.latitude || !center.longitude) return;
    const m = L.circleMarker([center.latitude, center.longitude], {
        radius: 9, color: '#1E8449', fillColor: '#2ECC71',
        fillOpacity: 0.9, weight: 2,
    });
    m.bindPopup(`<strong>${center.name}</strong><br><small>Capacity: ${center.capacity || 'N/A'}</small>`);
    layers.evac.addLayer(m);
});

function toggleLayer(type, visible) {
    visible ? layers[type].addTo(map) : map.removeLayer(layers[type]);
}

function resetMapView() {
    map.setView([14.0639, 120.6358], 16);
}

// ── Place marker mode ──
let isPlacing    = false;
let tempMarker   = null;
let placedLatLng = null;

function startPlacing() {
    isPlacing = true;
    map.getContainer().style.cursor = 'crosshair';
    document.getElementById('placingHint').classList.add('visible');
    document.getElementById('addMarkerBtn').textContent = 'Click on the map...';
    document.getElementById('addMarkerBtn').disabled    = true;
}

function cancelPlacing() {
    isPlacing = false;
    map.getContainer().style.cursor = '';
    document.getElementById('placingHint').classList.remove('visible');
    document.getElementById('addMarkerBtn').textContent = '+ Add Hazard Marker';
    document.getElementById('addMarkerBtn').disabled    = false;

    if (tempMarker) {
        map.removeLayer(tempMarker);
        tempMarker = null;
    }
}

map.on('click', function(e) {
    if (!isPlacing) return;

    placedLatLng = e.latlng;

    if (tempMarker) map.removeLayer(tempMarker);
    tempMarker = L.marker([placedLatLng.lat, placedLatLng.lng]).addTo(map);

    document.getElementById('markerLat').value     = placedLatLng.lat.toFixed(7);
    document.getElementById('markerLng').value     = placedLatLng.lng.toFixed(7);
    document.getElementById('coordsDisplay').textContent =
        placedLatLng.lat.toFixed(5) + ', ' + placedLatLng.lng.toFixed(5);

    cancelPlacing();
    openModal('addMarkerModal');
});

// ── View marker ──
let currentViewZone = null;

function viewMarker(zone) {
    currentViewZone = zone;

    const color = typeColors[zone.hazard_type] || '#888';
    const riskLabels = { high: 'HIGH RISK', medium: 'MEDIUM RISK', low: 'LOW RISK' };
    const riskColors = { high: '#922B21', medium: '#784212', low: '#7D6608' };
    const riskBgs    = { high: '#FADBD8', medium: '#FDEBD0', low: '#FEF9E7' };

    document.getElementById('vm-label').textContent       = zone.label;
    document.getElementById('vm-icon').style.background   = color + '22';
    document.getElementById('vm-icon').style.color        = color;
    document.getElementById('vm-icon').innerHTML          = zone.hazard_type === 'flood' ? '&#127754;' : '&#128293;';
    document.getElementById('vm-type').textContent        = zone.hazard_type.charAt(0).toUpperCase() + zone.hazard_type.slice(1);
    document.getElementById('vm-risk').textContent        = riskLabels[zone.risk] || zone.risk;
    document.getElementById('vm-description').textContent = zone.description || '—';
    document.getElementById('vm-coords').textContent      =
        parseFloat(zone.lat).toFixed(5) + ', ' + parseFloat(zone.lng).toFixed(5);

    document.getElementById('vm-badges').innerHTML =
        `<span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:20px;background:${color}22;color:${color};">${zone.hazard_type.toUpperCase()}</span>
         <span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:20px;background:${riskBgs[zone.risk]};color:${riskColors[zone.risk]};">${riskLabels[zone.risk]}</span>`;

    openModal('viewMarkerModal');
}

function focusMarkerOnMap() {
    if (!currentViewZone) return;
    closeModal('viewMarkerModal');
    map.setView([currentViewZone.lat, currentViewZone.lng], 18);
}

function confirmDeleteFromView() {
    closeModal('viewMarkerModal');
    confirmDelete(currentViewZone.zone_id, currentViewZone.label);
}

// ── Delete ──
function confirmDelete(zoneId, label) {
    document.getElementById('deleteMarkerName').textContent  = label;
    document.getElementById('deleteMarkerConfirmBtn').onclick = function() {
        document.getElementById('delForm' + zoneId).submit();
    };
    openModal('deleteMarkerModal');
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