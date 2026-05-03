<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('resident');

$pageTitle  = 'Evacuation Centers';
$activePage = 'map';

// ── Fetch evacuation centers with occupancy ───────────────────────────────
$stmtCenters = $conn->query("
    SELECT
        ec.center_id, ec.name, ec.address,
        ec.latitude, ec.longitude, ec.capacity, ec.is_active,
        COUNT(DISTINCT sr.resident_id) AS occupancy
    FROM evacuation_centers ec
    LEFT JOIN safety_reports sr
        ON sr.center_id = ec.center_id
        AND sr.status = 'evacuated'
        AND sr.report_id = (
            SELECT MAX(sr2.report_id) FROM safety_reports sr2
            WHERE sr2.resident_id = sr.resident_id
        )
    WHERE ec.is_active = 1
    GROUP BY ec.center_id
    ORDER BY ec.name ASC
");
$centers = $stmtCenters->fetchAll();

// ── Fetch hazard zones (for map overlay) ─────────────────────────────────
$stmtZones = $conn->query("
    SELECT z.zone_id, z.label, z.hazard_type, z.intensity,
           c.latitude, c.longitude
    FROM hazard_zones z
    LEFT JOIN hazard_zone_coordinates c ON c.zone_id = z.zone_id AND c.point_order = 0
    ORDER BY z.created_at DESC
");
$zones = $stmtZones->fetchAll();

// ── Summary stats ─────────────────────────────────────────────────────────
$totalCenters   = count($centers);
$availableCnt   = 0;
$totalCapacity  = 0;
$totalOccupancy = 0;
foreach ($centers as $c) {
    $cap = (int)$c['capacity'];
    $occ = (int)$c['occupancy'];
    $totalCapacity  += $cap;
    $totalOccupancy += $occ;
    if (!($cap > 0 && $occ >= $cap)) $availableCnt++;
}

$centersJson = json_encode(array_map(fn($c) => [
    'center_id' => (int)$c['center_id'],
    'name'      => $c['name'],
    'address'   => $c['address'] ?? '',
    'latitude'  => $c['latitude'],
    'longitude' => $c['longitude'],
    'capacity'  => (int)$c['capacity'],
    'occupancy' => (int)$c['occupancy'],
], $centers));

$zonesJson = json_encode(array_map(fn($z) => [
    'label'       => $z['label'],
    'hazard_type' => $z['hazard_type'],
    'intensity'   => (float)$z['intensity'],
    'latitude'    => $z['latitude'],
    'longitude'   => $z['longitude'],
], $zones));

// ── Get resident's current evacuation status ──────────────────────────────
$stmtMe = $conn->prepare("
    SELECT sr.status, sr.center_id, ec.name AS center_name
    FROM residents r
    LEFT JOIN safety_reports sr ON sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = r.resident_id
    )
    LEFT JOIN evacuation_centers ec ON ec.center_id = sr.center_id
    WHERE r.user_id = ?
");
$stmtMe->execute([$_SESSION['user_id']]);
$myStatus = $stmtMe->fetch();

$extraStyles = '
<link rel="stylesheet" href="' . BASE_URL . '/assets/css/leaflet.css">
<style>
    #evac-map {
        height: 340px;
        width: 100%;
        z-index: 1;
        display: block;
    }
</style>
';

require_once __DIR__ . '/../../includes/header_resident.php';
?>

<style>
    /* ── Map wrapper ── */
    .map-outer {
        background: var(--rescue-white);
        border-radius: 14px;
        border: 1px solid var(--rescue-border);
        margin-bottom: 1rem;
    }

    .map-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .75rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        gap: 8px;
        flex-wrap: wrap;
    }

    .map-toolbar-title {
        font-size: .875rem;
        font-weight: 700;
        color: var(--rescue-text);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .map-legend {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: .6rem 1rem;
        border-top: 1px solid var(--rescue-border);
        font-size: .72rem;
        color: var(--rescue-muted);
        background: #FAFAFA;
    }

    .legend-dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
        display: inline-block;
    }

    .legend-item { display: flex; align-items: center; gap: 4px; font-weight: 500; }

    /* ── Layer toggle pills ── */
    .layer-pills {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        padding: .6rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        background: #FAFAFA;
    }

    .layer-pill {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: .72rem; font-weight: 700;
        padding: 4px 10px; border-radius: 20px;
        border: 1.5px solid var(--rescue-border);
        background: #fff; color: var(--rescue-muted);
        cursor: pointer; font-family: inherit;
        transition: all .15s; white-space: nowrap;
    }
    .layer-pill.active-evac  { background:#EAFAF1; color:#1E8449; border-color:#A9DFBF; }
    .layer-pill.active-flood { background:#EBF5FB; color:#2980B9; border-color:#AED6F1; }
    .layer-pill.active-fire  { background:#FDEDEC; color:#C0392B; border-color:#F5B7B1; }

    /* ── Stats strip ── */
    .stats-strip {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1px;
        background: var(--rescue-border);
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid var(--rescue-border);
        margin-bottom: 1rem;
    }

    .stat-cell {
        background: var(--rescue-white);
        padding: .875rem .5rem;
        text-align: center;
    }

    .stat-cell-val   { font-size: 1.1rem; font-weight: 700; }
    .stat-cell-label { font-size: .68rem; color: var(--rescue-muted); margin-top: 2px; }

    /* ── My status banner ── */
    .my-status-banner {
        border-radius: 12px;
        padding: .875rem 1rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: .83rem;
    }

    /* ── Center list ── */
    .center-item {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem 1.1rem;
        margin-bottom: .75rem;
        transition: box-shadow .15s;
    }
    .center-item:last-child { margin-bottom: 0; }
    .center-item:hover { box-shadow: 0 2px 10px rgba(0,0,0,.07); }

    .center-item-header {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 10px; margin-bottom: .5rem;
    }

    .center-name {
        font-size: .9rem; font-weight: 700; color: var(--rescue-text);
        display: flex; align-items: center; gap: 7px;
    }

    .occ-bar-bg   { background:#F0F3F4; border-radius:10px; height:5px; overflow:hidden; margin: 5px 0 3px; }
    .occ-bar-fill { height:100%; border-radius:10px; }

    .locate-btn {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: .75rem; font-weight: 600;
        color: var(--rescue-red); background: var(--rescue-red-light);
        border: none; border-radius: 20px;
        padding: 4px 11px; cursor: pointer;
        font-family: inherit; transition: background .15s;
        white-space: nowrap;
    }
    .locate-btn:hover { background: #F5B7B1; }

    /* ── Section label ── */
    .section-label {
        font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .6px; color: var(--rescue-muted);
        margin: 1rem 0 .5rem; padding-left: 2px;
    }

    /* ── Center detail modal ── */
    .detail-row { display:flex; padding:.65rem 0; border-bottom:1px solid var(--rescue-border); gap:8px; }
    .detail-row:last-child { border-bottom:none; }
    .detail-label { width:90px; font-size:.75rem; font-weight:700; color:var(--rescue-muted); flex-shrink:0; padding-top:1px; }
    .detail-value { font-size:.85rem; color:var(--rescue-text); flex:1; }

    /* ── Empty state ── */
    .empty-state { text-align:center; padding:2rem 1rem; color:var(--rescue-muted); }
    .empty-state i { font-size:2rem; display:block; margin-bottom:.5rem; color:#D5D8DC; }
    .empty-state p { font-size:.85rem; margin:0; }
</style>

<!-- Page header -->
<div class="resident-content">
    <div class="page-header">
        <h1>Evacuation Centers</h1>
        <p>Find available centers and hazard zones near you</p>
    </div>

    <!-- Flash from session -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

    <!-- ── My evacuation status banner ── -->
    <?php if (($myStatus['status'] ?? '') === 'evacuated' && !empty($myStatus['center_name'])): ?>
    <div class="my-status-banner" style="background:#EBF5FB;border:1px solid #AED6F1;">
        <i class="bi bi-building-fill" style="color:#2980B9;font-size:1.1rem;flex-shrink:0;"></i>
        <div style="flex:1;">
            <div style="font-weight:700;color:#1A5276;">You are currently evacuated</div>
            <div style="font-size:.78rem;color:#2980B9;margin-top:1px;"><?= htmlspecialchars($myStatus['center_name']) ?></div>
        </div>
        <a href="<?= BASE_URL ?>/modules/resident/dashboard.php"
            style="font-size:.75rem;font-weight:700;color:#2980B9;text-decoration:none;white-space:nowrap;">
            Update <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    <?php endif; ?>

    <!-- ── Stats strip ── -->
    <div class="stats-strip">
        <div class="stat-cell">
            <div class="stat-cell-val" style="color:var(--rescue-text);"><?= $totalCenters ?></div>
            <div class="stat-cell-label">Centers</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-val" style="color:#1E8449;"><?= $availableCnt ?></div>
            <div class="stat-cell-label">Available</div>
        </div>
        <div class="stat-cell">
            <div class="stat-cell-val" style="color:#2980B9;"><?= $totalCapacity > 0 ? $totalCapacity : '—' ?></div>
            <div class="stat-cell-label">Total Capacity</div>
        </div>
    </div>

    <!-- ── Map ── -->
    <div class="map-outer">
        <div class="map-toolbar">
            <span class="map-toolbar-title">
                <i class="bi bi-map-fill" style="color:var(--rescue-red);"></i>
                Barangay 12, Nasugbu, Batangas
            </span>
            <button onclick="resetView()"
                style="background:none;border:1px solid var(--rescue-border);border-radius:8px;padding:4px 10px;font-size:.75rem;font-weight:600;color:var(--rescue-muted);cursor:pointer;font-family:inherit;">
                <i class="bi bi-arrows-fullscreen"></i> Reset
            </button>
        </div>

        <!-- Layer toggles -->
        <div class="layer-pills">
            <button class="layer-pill active-evac" id="pill-evac" onclick="toggleLayer('evac')">
                <span class="legend-dot" style="background:#2ECC71;"></span> Evac Centers
            </button>
            <button class="layer-pill active-flood" id="pill-flood" onclick="toggleLayer('flood')">
                <span class="legend-dot" style="background:#3498DB;"></span> Flood Zones
            </button>
            <button class="layer-pill active-fire" id="pill-fire" onclick="toggleLayer('fire')">
                <span class="legend-dot" style="background:#C0392B;"></span> Fire Zones
            </button>
        </div>

        <div id="evac-map"></div>

        <div class="map-legend">
            <span class="legend-item"><span class="legend-dot" style="background:#2ECC71;"></span> Available</span>
            <span class="legend-item"><span class="legend-dot" style="background:var(--rescue-red);"></span> Full</span>
            <span class="legend-item"><span class="legend-dot" style="background:#3498DB;"></span> Flood zone</span>
            <span class="legend-item"><span class="legend-dot" style="background:#C0392B;"></span> Fire zone</span>
            <span style="font-style:italic;">Tap a pin for details</span>
        </div>
    </div>

    <!-- ── Center list ── -->
    <div class="section-label">
        <i class="bi bi-building-fill" style="color:var(--rescue-red);"></i>
        Evacuation Centers
        <span style="background:#F2F3F4;color:var(--rescue-muted);font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;"><?= $totalCenters ?></span>
    </div>

    <?php if (empty($centers)): ?>
    <div class="r-card">
        <div class="empty-state">
            <i class="bi bi-building"></i>
            <p>No evacuation centers listed yet.</p>
            <p style="font-size:.8rem;margin-top:4px;">Check back later or contact your barangay.</p>
        </div>
    </div>
    <?php else: foreach ($centers as $c):
        $occ     = (int)$c['occupancy'];
        $cap     = (int)$c['capacity'];
        $isFull  = $cap > 0 && $occ >= $cap;
        $pct     = $cap > 0 ? min(100, round($occ / $cap * 100)) : 0;
        $barClr  = $isFull ? '#E74C3C' : ($pct >= 75 ? '#E67E22' : '#2ECC71');
        $dotClr  = $isFull ? '#E74C3C' : '#2ECC71';

        $cData = htmlspecialchars(json_encode([
            'center_id' => (int)$c['center_id'],
            'name'      => $c['name'],
            'address'   => $c['address'] ?? '',
            'capacity'  => $cap,
            'occupancy' => $occ,
            'lat'       => $c['latitude'],
            'lng'       => $c['longitude'],
        ]), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="center-item">
        <div class="center-item-header">
            <div class="center-name">
                <span style="width:10px;height:10px;border-radius:50%;background:<?= $dotClr ?>;flex-shrink:0;"></span>
                <?= htmlspecialchars($c['name']) ?>
            </div>
            <span style="font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:20px;flex-shrink:0;
                background:<?= $isFull?'#FADBD8':'#EAFAF1' ?>;
                color:<?= $isFull?'#922B21':'#1E8449' ?>;">
                <?= $isFull ? 'Full' : 'Available' ?>
            </span>
        </div>

        <?php if (!empty($c['address'])): ?>
        <div style="font-size:.78rem;color:var(--rescue-muted);margin-bottom:.4rem;display:flex;align-items:center;gap:4px;">
            <i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);font-size:.72rem;"></i>
            <?= htmlspecialchars($c['address']) ?>
        </div>
        <?php endif; ?>

        <!-- Occupancy bar -->
        <?php if ($cap > 0): ?>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--rescue-muted);">
            <span>Occupancy</span>
            <span style="font-weight:700;color:var(--rescue-text);"><?= $occ ?> / <?= $cap ?></span>
        </div>
        <div class="occ-bar-bg">
            <div class="occ-bar-fill" style="width:<?= $pct ?>%;background:<?= $barClr ?>;"></div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:7px;margin-top:.75rem;flex-wrap:wrap;">
            <button class="locate-btn" onclick="locateCenter(JSON.parse(this.dataset.c))" data-c="<?= $cData ?>">
                <i class="bi bi-geo-alt-fill"></i> Locate on Map
            </button>
            <button onclick="openCenterDetail(JSON.parse(this.dataset.c))" data-c="<?= $cData ?>"
                style="display:inline-flex;align-items:center;gap:5px;font-size:.75rem;font-weight:600;color:var(--rescue-muted);background:#F5F5F5;border:none;border-radius:20px;padding:4px 11px;cursor:pointer;font-family:inherit;">
                <i class="bi bi-info-circle"></i> Details
            </button>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ── Important notice ── -->
    <div style="background:#FFFDE7;border:1px solid #F9E79F;border-radius:12px;padding:1rem 1.1rem;margin-top:.5rem;">
        <div style="font-size:.82rem;font-weight:700;color:#7D6608;margin-bottom:.5rem;">
            <i class="bi bi-lightbulb-fill"></i> Important Reminders
        </div>
        <ul style="margin:0;padding-left:1.2rem;font-size:.78rem;color:#7D6608;line-height:1.7;">
            <li>Bring essential items and valid documents</li>
            <li>Follow all evacuation center rules</li>
            <li>Inform barangay officials upon arrival</li>
            <li>Update your status in the app when you evacuate</li>
            <li>Contact emergency hotlines if you need assistance</li>
        </ul>
    </div>

</div><!-- /resident-content -->


<!-- ════════════════════════════════════════
     CENTER DETAIL BOTTOM SHEET
════════════════════════════════════════ -->
<div class="r-modal-backdrop" id="centerDetailModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5 id="cd-name">Center Details</h5>
            <button onclick="closeModal('centerDetailModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body">
            <!-- Status + occupancy visual -->
            <div style="background:#F8F9FA;border:1px solid var(--rescue-border);border-radius:10px;padding:1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-size:.82rem;font-weight:700;color:var(--rescue-muted);">Current Occupancy</span>
                    <span id="cd-occ-text" style="font-size:.9rem;font-weight:700;"></span>
                </div>
                <div class="occ-bar-bg" style="height:8px;">
                    <div id="cd-occ-bar" class="occ-bar-fill" style="height:100%;transition:width .4s;"></div>
                </div>
                <div id="cd-occ-note" style="font-size:.72rem;color:var(--rescue-muted);margin-top:4px;"></div>
            </div>

            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="detail-value" id="cd-status"></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Address</span>
                <span class="detail-value" id="cd-address"></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Capacity</span>
                <span class="detail-value" id="cd-capacity"></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Coordinates</span>
                <span class="detail-value" id="cd-coords" style="font-family:monospace;font-size:.78rem;"></span>
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('centerDetailModal')">Close</button>
            <button type="button" class="btn-r-submit" id="cd-locate-btn"
                onclick="closeModal('centerDetailModal'); locateCenter(window._currentCenter);">
                <i class="bi bi-geo-alt-fill"></i> Show on Map
            </button>
        </div>
    </div>
</div>


<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<script>
const CENTERS = <?= $centersJson ?>;
const ZONES   = <?= $zonesJson ?>;

// ── Map setup ─────────────────────────────────────────────────────────────
const map = L.map('evac-map', {
    center: [14.0639, 120.6358],
    zoom: 16,
    zoomControl: true,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
}).addTo(map);

// Fix blinking/partial tiles — tell Leaflet to recalculate size
// once the DOM and all assets are fully painted
setTimeout(() => map.invalidateSize(), 100);
window.addEventListener('load', () => map.invalidateSize());

// ── Layer groups ──────────────────────────────────────────────────────────
const layers = {
    evac:  L.layerGroup().addTo(map),
    flood: L.layerGroup().addTo(map),
    fire:  L.layerGroup().addTo(map),
};

const layerState = { evac: true, flood: true, fire: true };

// ── Evacuation center markers ─────────────────────────────────────────────
const centerMarkerRefs = {};

CENTERS.forEach(c => {
    if (!c.latitude || !c.longitude) return;

    const cap    = parseInt(c.capacity) || 0;
    const occ    = parseInt(c.occupancy) || 0;
    const isFull = cap > 0 && occ >= cap;
    const color  = isFull ? '#E74C3C' : '#2ECC71';

    const marker = L.circleMarker([c.latitude, c.longitude], {
        radius: 11, color: color, fillColor: color,
        fillOpacity: 0.9, weight: 2.5,
    });

    const pct = cap > 0 ? Math.min(100, Math.round(occ / cap * 100)) : 0;
    marker.bindPopup(`
        <div style="font-family:inherit;min-width:160px;font-size:13px;">
            <strong style="color:#222;">${c.name}</strong><br>
            ${c.address ? `<span style="color:#888;font-size:11px;">${c.address}</span><br>` : ''}
            <span style="font-size:11px;color:#888;">
                Occupancy: <strong>${occ}${cap > 0 ? ' / ' + cap + ' (' + pct + '%)' : ''}</strong>
            </span><br>
            <span style="font-size:11px;font-weight:700;color:${color};">${isFull ? '🔴 Full' : '🟢 Available'}</span>
        </div>
    `, { maxWidth: 220 });

    layers.evac.addLayer(marker);
    centerMarkerRefs[c.center_id] = marker;
});

// ── Hazard zone markers ───────────────────────────────────────────────────
ZONES.forEach(z => {
    if (!z.latitude || !z.longitude) return;

    const type  = z.hazard_type;
    const color = type === 'flood' ? '#3498DB' : '#C0392B';
    const risk  = z.intensity >= 0.8 ? 'High' : (z.intensity >= 0.5 ? 'Medium' : 'Low');

    const marker = L.circleMarker([z.latitude, z.longitude], {
        radius: 8, color: color, fillColor: color,
        fillOpacity: 0.65, weight: 2,
        dashArray: '4 2',
    });

    marker.bindPopup(`
        <div style="font-family:inherit;min-width:140px;font-size:13px;">
            <strong style="color:${color};">${z.label}</strong><br>
            <span style="font-size:11px;color:#888;">
                Type: <strong>${type.charAt(0).toUpperCase() + type.slice(1)}</strong><br>
                Risk: <strong>${risk}</strong>
            </span>
        </div>
    `, { maxWidth: 200 });

    if (type === 'flood') layers.flood.addLayer(marker);
    else                  layers.fire.addLayer(marker);
});

// ── Layer toggle ──────────────────────────────────────────────────────────
function toggleLayer(type) {
    layerState[type] = !layerState[type];

    const pillMap = { evac: 'active-evac', flood: 'active-flood', fire: 'active-fire' };
    const pill    = document.getElementById('pill-' + type);

    if (layerState[type]) {
        layers[type].addTo(map);
        pill.classList.add(pillMap[type]);
    } else {
        map.removeLayer(layers[type]);
        pill.classList.remove(pillMap[type]);
    }
}

// ── Locate center ─────────────────────────────────────────────────────────
function locateCenter(c) {
    window._currentCenter = c;
    map.setView([c.lat, c.lng], 18);
    const marker = centerMarkerRefs[c.center_id];
    if (marker) marker.openPopup();

    // Scroll map into view on mobile
    document.getElementById('evac-map').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Reset view ────────────────────────────────────────────────────────────
function resetView() {
    map.setView([14.0639, 120.6358], 16);
}

// ── Center detail modal ───────────────────────────────────────────────────
function openCenterDetail(c) {
    window._currentCenter = c;

    const cap    = parseInt(c.capacity) || 0;
    const occ    = parseInt(c.occupancy) || 0;
    const isFull = cap > 0 && occ >= cap;
    const pct    = cap > 0 ? Math.min(100, Math.round(occ / cap * 100)) : 0;
    const barClr = isFull ? '#E74C3C' : (pct >= 75 ? '#E67E22' : '#2ECC71');

    document.getElementById('cd-name').textContent     = c.name;
    document.getElementById('cd-address').textContent  = c.address || '—';
    document.getElementById('cd-capacity').textContent = cap > 0 ? cap + ' persons' : 'Not specified';
    document.getElementById('cd-coords').textContent   =
        parseFloat(c.lat).toFixed(5) + ', ' + parseFloat(c.lng).toFixed(5);

    document.getElementById('cd-status').innerHTML = isFull
        ? '<span style="background:#FADBD8;color:#922B21;font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:20px;">Full</span>'
        : '<span style="background:#EAFAF1;color:#1E8449;font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:20px;">Available</span>';

    document.getElementById('cd-occ-text').textContent  = occ + (cap > 0 ? ' / ' + cap + ' (' + pct + '%)' : ' evacuees');
    document.getElementById('cd-occ-text').style.color  = barClr;
    document.getElementById('cd-occ-bar').style.width   = (cap > 0 ? pct : 0) + '%';
    document.getElementById('cd-occ-bar').style.background = barClr;
    document.getElementById('cd-occ-note').textContent  = occ > 0
        ? occ + ' resident' + (occ !== 1 ? 's' : '') + ' currently evacuated here'
        : 'No residents have evacuated here yet';

    openModal('centerDetailModal');
}

// ── Modal helpers ─────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.r-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.r-modal-backdrop.open').forEach(m => m.classList.remove('open'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer_resident.php'; ?>