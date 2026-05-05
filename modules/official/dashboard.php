<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// ── Resident counts ───────────────────────────────────────────────────────
$totalResidents = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'resident' AND is_active = 1")->fetchColumn();

// Latest safety report per resident
$safeCnt = (int)$conn->query("
    SELECT COUNT(DISTINCT sr.resident_id)
    FROM safety_reports sr
    WHERE sr.status = 'safe_at_home'
      AND sr.report_id = (
          SELECT MAX(sr2.report_id) FROM safety_reports sr2
          WHERE sr2.resident_id = sr.resident_id
      )
")->fetchColumn();

$helpCnt = (int)$conn->query("
    SELECT COUNT(DISTINCT sr.resident_id)
    FROM safety_reports sr
    WHERE sr.status = 'need_help'
      AND sr.report_id = (
          SELECT MAX(sr2.report_id) FROM safety_reports sr2
          WHERE sr2.resident_id = sr.resident_id
      )
")->fetchColumn();

// ── Active alerts count ───────────────────────────────────────────────────
$activeAlertCnt = (int)$conn->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1")->fetchColumn();

// ── Active alerts list (for right panel) ─────────────────────────────────
$stmtActiveAlerts = $conn->query("
    SELECT title, message, type, severity, created_at
    FROM alerts
    WHERE is_active = 1
    ORDER BY CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, created_at DESC
    LIMIT 5
");
$activeAlerts = $stmtActiveAlerts->fetchAll();

// ── Recent activity (last 10 safety reports) ─────────────────────────────
$stmtActivity = $conn->query("
    SELECT
        r.first_name, r.last_name,
        COALESCE(r.house_no, '') AS house_no,
        COALESCE(r.street, '')   AS street,
        COALESCE(r.area, '')     AS area,
        sr.status, sr.reported_at
    FROM safety_reports sr
    JOIN residents r ON r.resident_id = sr.resident_id
    WHERE sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = sr.resident_id
    )
    ORDER BY sr.reported_at DESC
    LIMIT 10
");
$recentActivity = $stmtActivity->fetchAll();

// ── Evacuation centers ────────────────────────────────────────────────────
$stmtCenters = $conn->query("
    SELECT
        ec.name, ec.capacity,
        COALESCE((
            SELECT COUNT(DISTINCT sr.resident_id)
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
    LIMIT 4
");
$centers = $stmtCenters->fetchAll();
$totalCenters   = count($centers);

// ── Map legend counts ─────────────────────────────────────────────────────
$evacCnt = (int)$conn->query("SELECT COUNT(*) FROM evacuation_centers WHERE is_active = 1")->fetchColumn();

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 1.75rem;
    }

    /* Map placeholder */
    .map-placeholder {
        background: #F0F3F4;
        border-radius: 10px;
        height: 300px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #AAB7B8;
        gap: 8px;
        margin-bottom: .75rem;
    }
    .map-placeholder i { font-size: 2.5rem; }
    .map-placeholder p { font-size: .82rem; margin: 0; }

    .map-legend { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; font-size: .8rem; color: var(--rescue-muted); padding: .5rem 0; }
    .map-legend-item { display: flex; align-items: center; gap: 5px; font-weight: 500; }
    .legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

    /* Recent activity */
    .activity-item { display: flex; align-items: flex-start; gap: 12px; padding: .75rem 0; border-bottom: 1px solid var(--rescue-border); }
    .activity-item:last-child { border-bottom: none; padding-bottom: 0; }
    .activity-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
    .activity-name    { font-size: .875rem; font-weight: 700; color: var(--rescue-text); margin: 0 0 2px; }
    .activity-address { font-size: .78rem; color: var(--rescue-muted); margin: 0 0 5px; }
    .activity-meta    { display: flex; align-items: center; gap: 8px; }
    .activity-time    { font-size: .73rem; color: var(--rescue-muted); }

    /* Status badges */
    .badge-safe     { background: #EAFAF1; color: #1E8449; font-size: .72rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; }
    .badge-help     { background: var(--rescue-red-light); color: var(--rescue-red); font-size: .72rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; }
    .badge-evacuated{ background: #EBF5FB; color: #2980B9; font-size: .72rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; }

    /* Alert severity badges */
    .sev-high   { background: #FADBD8; color: #C0392B; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
    .sev-medium { background: #FDEBD0; color: #D68910; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
    .sev-low    { background: #EAFAF1; color: #1E8449; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }
    .type-badge { font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; }

    /* Evac progress */
    .evac-progress-item { margin-bottom: .9rem; }
    .evac-progress-item:last-child { margin-bottom: 0; }
    .evac-progress-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; gap: 8px; }
    .evac-progress-name  { font-size: .82rem; font-weight: 600; color: var(--rescue-text); }
    .evac-progress-count { font-size: .78rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; white-space: nowrap; }
    .progress-bar-wrap   { height: 6px; background: #F0F3F4; border-radius: 10px; overflow: hidden; }
    .progress-bar-fill   { height: 100%; border-radius: 10px; transition: width .6s ease; }

    /* Active alert items in dashboard */
    .dash-alert-item { padding: .75rem 0; border-bottom: 1px solid var(--rescue-border); }
    .dash-alert-item:last-child { border-bottom: none; }
    .dash-alert-title { font-size: .875rem; font-weight: 700; color: var(--rescue-text); margin: 0 0 3px; }
    .dash-alert-msg   { font-size: .78rem; color: var(--rescue-muted); margin: 0 0 6px; line-height: 1.45; }
    .dash-alert-meta  { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
</style>

<!-- Page header -->
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.5rem;font-weight:700;color:var(--rescue-text);margin:0 0 4px;">Dashboard</h1>
    <p style="font-size:.85rem;color:var(--rescue-muted);margin:0;">Real-time emergency management overview</p>
</div>

<!-- ── Stat Cards ── -->
<div class="stat-grid">
    <div class="stat-card">
        <div>
            <div class="stat-label">Total Residents</div>
            <div class="stat-value" style="color:var(--rescue-text);"><?= $totalResidents ?></div>
            <div style="font-size:.75rem;color:var(--rescue-muted);margin-top:3px;">Registered residents</div>
        </div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;"><i class="bi bi-people-fill"></i></div>
    </div>
    <div class="stat-card">
        <div>
            <div class="stat-label">Safe</div>
            <div class="stat-value" style="color:#1E8449;"><?= $safeCnt ?></div>
            <div style="font-size:.75rem;color:var(--rescue-muted);margin-top:3px;">Reported safe</div>
        </div>
        <div class="stat-icon" style="background:#EAFAF1;color:#1E8449;"><i class="bi bi-check-circle-fill"></i></div>
    </div>
    <div class="stat-card">
        <div>
            <div class="stat-label">Need Help</div>
            <div class="stat-value" style="color:var(--rescue-red);"><?= $helpCnt ?></div>
            <div style="font-size:.75rem;color:var(--rescue-muted);margin-top:3px;">Require assistance</div>
        </div>
        <div class="stat-icon" style="background:var(--rescue-red-light);color:var(--rescue-red);"><i class="bi bi-exclamation-triangle-fill"></i></div>
    </div>
    <div class="stat-card">
        <div>
            <div class="stat-label">Active Alerts</div>
            <div class="stat-value" style="color:#B7770D;"><?= $activeAlertCnt ?></div>
            <div style="font-size:.75rem;color:var(--rescue-muted);margin-top:3px;">Current warnings</div>
        </div>
        <div class="stat-icon" style="background:#FEF9E7;color:#B7770D;"><i class="bi bi-bell-fill"></i></div>
    </div>
</div>

<!-- ── Bottom two columns ── -->
<div class="row g-4">

    <!-- Left: Map + Recent Activity -->
    <div class="col-12 col-xl-7">

        <!-- Live Status Map -->
        <div class="rescue-card mb-4">
            <div class="rescue-card-title">
                <i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);"></i> Live Status Map
            </div>
            <div id="dashboard-map" style="height:300px; border-radius:10px; z-index:1;"></div>
            <div class="map-legend">
                <div class="map-legend-item">
                    <span class="legend-dot" style="background:#2ECC71;"></span>
                    Safe Residents <strong style="color:var(--rescue-text);"><?= $safeCnt ?></strong>
                </div>
                <div class="map-legend-item">
                    <span class="legend-dot" style="background:var(--rescue-red);"></span>
                    Need Help <strong style="color:var(--rescue-text);"><?= $helpCnt ?></strong>
                </div>
                <div class="map-legend-item">
                    <span class="legend-dot" style="background:#3498DB;"></span>
                    Evac Centers <strong style="color:var(--rescue-text);"><?= $evacCnt ?></strong>
                </div>
                <div class="map-legend-item">
                    <span class="legend-dot" style="background:#E74C3C; border:2px solid #922B21;"></span>
                    Need Help (with location) <strong style="color:var(--rescue-text);"><?= $helpCnt ?></strong>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="rescue-card">
            <div class="rescue-card-title">
                <i class="bi bi-activity" style="color:var(--rescue-red);"></i> Recent Activity
            </div>

            <?php if (empty($recentActivity)): ?>
            <div style="font-size:.82rem;color:var(--rescue-muted);text-align:center;padding:1.5rem 0;">
                <i class="bi bi-clock-history" style="font-size:1.75rem;display:block;margin-bottom:8px;color:#D5D8DC;"></i>
                No recent activity yet.<br>Activity will appear here once residents start reporting.
            </div>
            <?php else: ?>
                <?php
                $dotColors = ['safe'=>'#2ECC71','need_help'=>'#E74C3C','evacuated'=>'#3498DB'];
                foreach ($recentActivity as $act):
                    $dot  = $dotColors[$act['status']] ?? '#AAB7B8';
                    $addr = implode(', ', array_filter([$act['house_no'], $act['street'], $act['area']])) ?: 'Address not set';
                ?>
                <div class="activity-item">
                    <div class="activity-dot" style="background:<?= $dot ?>;"></div>
                    <div style="flex:1;min-width:0;">
                        <p class="activity-name"><?= htmlspecialchars($act['first_name'].' '.$act['last_name']) ?></p>
                        <p class="activity-address"><?= htmlspecialchars($addr) ?></p>
                        <div class="activity-meta">
                            <?php if ($act['status'] === 'safe_at_home'): ?>
                                <span class="badge-safe"><i class="bi bi-check-circle-fill"></i> Safe</span>
                            <?php elseif ($act['status'] === 'need_help'): ?>
                                <span class="badge-help"><i class="bi bi-exclamation-triangle-fill"></i> Need Help</span>
                            <?php else: ?>
                                <span class="badge-evacuated"><i class="bi bi-house-fill"></i> Evacuated</span>
                            <?php endif; ?>
                            <span class="activity-time">
                                <i class="bi bi-clock" style="font-size:.7rem;"></i>
                                <?= date('M j, g:i A', strtotime($act['reported_at'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- Right: Active Alerts + Evac Centers -->
    <div class="col-12 col-xl-5">

        <!-- Active Alerts -->
        <div class="rescue-card mb-4">
            <div class="rescue-card-title" style="justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-bell-fill" style="color:#B7770D;"></i> Active Alerts
                    <?php if ($activeAlertCnt > 0): ?>
                    <span style="background:#B7770D;color:#fff;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:20px;"><?= $activeAlertCnt ?></span>
                    <?php endif; ?>
                </span>
                <a href="<?= BASE_URL ?>/modules/official/alerts.php"
                   style="font-size:.75rem;color:var(--rescue-red);text-decoration:none;font-weight:600;">
                    Manage <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($activeAlerts)): ?>
            <div style="font-size:.82rem;color:var(--rescue-muted);text-align:center;padding:1.5rem 0;">
                <i class="bi bi-bell-slash" style="font-size:1.75rem;display:block;margin-bottom:8px;color:#D5D8DC;"></i>
                No active alerts.
            </div>
            <?php else:
                $typeMeta = [
                    'flood'     => ['bg'=>'#EBF5FB','color'=>'#1A5276'],
                    'fire'      => ['bg'=>'#FDEDEC','color'=>'#922B21'],
                    'landslide' => ['bg'=>'#FEF9E7','color'=>'#784212'],
                    'general'   => ['bg'=>'#EAFAF1','color'=>'#1E8449'],
                ];
                foreach ($activeAlerts as $al):
                    $tm = $typeMeta[$al['type']] ?? $typeMeta['general'];
                    $sevClass = 'sev-'.($al['severity'] ?? 'low');
            ?>
            <div class="dash-alert-item">
                <p class="dash-alert-title"><?= htmlspecialchars($al['title']) ?></p>
                <p class="dash-alert-msg"><?= htmlspecialchars($al['message']) ?></p>
                <div class="dash-alert-meta">
                    <span class="type-badge" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;"><?= strtoupper($al['type']) ?></span>
                    <span class="<?= $sevClass ?>"><?= strtoupper($al['severity']) ?></span>
                    <span style="font-size:.72rem;color:var(--rescue-muted);">
                        <i class="bi bi-clock" style="font-size:.7rem;"></i>
                        <?= date('M j, g:i A', strtotime($al['created_at'])) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Evacuation Centers -->
        <div class="rescue-card">
            <div class="rescue-card-title" style="justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-building-fill" style="color:var(--rescue-red);"></i> Evacuation Centers
                </span>
                <a href="<?= BASE_URL ?>/modules/official/evacuation_centers.php"
                   style="font-size:.75rem;color:var(--rescue-red);text-decoration:none;font-weight:600;">
                    Manage <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($centers)): ?>
            <div style="font-size:.82rem;color:var(--rescue-muted);text-align:center;padding:1rem 0;">
                <i class="bi bi-building" style="font-size:1.75rem;display:block;margin-bottom:8px;color:#D5D8DC;"></i>
                No evacuation centers added yet.
            </div>
            <?php else: foreach ($centers as $c):
                $pct     = $c['capacity'] > 0 ? min(round($c['occupancy'] / $c['capacity'] * 100), 100) : 0;
                $isFull  = $pct >= 100;
                $barColor = $pct >= 100 ? '#E74C3C' : ($pct >= 75 ? '#F39C12' : '#2ECC71');
                $badgeBg  = $isFull ? '#FADBD8' : '#EAFAF1';
                $badgeClr = $isFull ? '#922B21' : '#1E8449';
                $badgeTxt = $isFull ? 'Full'    : 'Available';
            ?>
            <div class="evac-progress-item">
                <div class="evac-progress-header">
                    <span class="evac-progress-name"><?= htmlspecialchars($c['name']) ?></span>
                    <span class="evac-progress-count" style="background:<?= $badgeBg ?>;color:<?= $badgeClr ?>;">
                        <?= (int)$c['occupancy'] ?>/<?= (int)$c['capacity'] ?>
                        &nbsp;<?= $badgeTxt ?>
                    </span>
                </div>
                <div class="progress-bar-wrap">
                    <div class="progress-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

    </div>

</div>

<?php
// Build JSON for map
$stmtMapResidents = $conn->query("
    SELECT
        r.first_name, r.last_name,
        r.house_no, r.street, r.area,
        sr.status,
        ec.latitude AS evac_lat,
        ec.longitude AS evac_lng
    FROM safety_reports sr
    JOIN residents r ON r.resident_id = sr.resident_id
    LEFT JOIN evacuation_centers ec ON ec.center_id = sr.center_id
    WHERE sr.report_id = (
        SELECT MAX(sr2.report_id) FROM safety_reports sr2
        WHERE sr2.resident_id = sr.resident_id
    )
");
$mapResidents = $stmtMapResidents->fetchAll();

$stmtMapCenters = $conn->query("
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
$mapCenters = $stmtMapCenters->fetchAll();

$stmtMapHazards = $conn->query("
    SELECT z.label, z.hazard_type, z.intensity,
           c.latitude, c.longitude
    FROM hazard_zones z
    JOIN hazard_zone_coordinates c ON c.zone_id = z.zone_id AND c.point_order = 0
");
$mapHazards = $stmtMapHazards->fetchAll();
?>

<script src="<?= BASE_URL ?>/assets/js/leaflet.js"></script>
<script>
const MAP_CENTERS = <?= json_encode($mapCenters) ?>;
const MAP_HAZARDS = <?= json_encode($mapHazards) ?>;

const dashMap = L.map('dashboard-map', {
    center: [14.0639, 120.6358],
    zoom: 15,
    zoomControl: true,
    scrollWheelZoom: false,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19,
}).addTo(dashMap);

// Evacuation centers
MAP_CENTERS.forEach(c => {
    if (!c.latitude || !c.longitude) return;
    const isFull  = c.capacity > 0 && c.occupancy >= c.capacity;
    const color   = isFull ? '#C0392B' : '#2ECC71';
    L.circleMarker([c.latitude, c.longitude], {
        radius: 10, color: color, fillColor: color,
        fillOpacity: 0.85, weight: 2,
    })
    .bindPopup(`<strong>${c.name}</strong><br><small>Occupancy: ${c.occupancy}/${c.capacity || '?'} — ${isFull ? 'Full' : 'Available'}</small>`)
    .addTo(dashMap);
});

// Hazard markers
MAP_HAZARDS.forEach(h => {
    if (!h.latitude || !h.longitude) return;
    const color = h.hazard_type === 'flood' ? '#3498DB' : '#C0392B';
    L.circleMarker([h.latitude, h.longitude], {
        radius: 7, color: color, fillColor: color,
        fillOpacity: 0.7, weight: 2,
    })
    .bindPopup(`<strong>${h.label}</strong><br><small>${h.hazard_type.toUpperCase()} ZONE</small>`)
    .addTo(dashMap);
});

// Hazard markers
MAP_HAZARDS.forEach(h => {
    if (!h.latitude || !h.longitude) return;
    const color = h.hazard_type === 'flood' ? '#3498DB' : '#C0392B';
    L.circleMarker([h.latitude, h.longitude], {
        radius: 7, color: color, fillColor: color,
        fillOpacity: 0.7, weight: 2,
    })
    .bindPopup(`<strong>${h.label}</strong><br><small>${h.hazard_type.toUpperCase()} ZONE</small>`)
    .addTo(dashMap);
});

// ← ADD THIS BLOCK HERE ──────────────────────────────
const NEED_HELP = <?php
    $stmtNeedHelp = $conn->query("
        SELECT r.first_name, r.last_name,
               r.house_no, r.street, r.area,
               sr.latitude, sr.longitude,
               sr.notes, sr.reported_at
        FROM safety_reports sr
        JOIN residents r ON r.resident_id = sr.resident_id
        WHERE sr.status = 'need_help'
          AND sr.latitude IS NOT NULL
          AND sr.longitude IS NOT NULL
          AND sr.report_id = (
              SELECT MAX(sr2.report_id) FROM safety_reports sr2
              WHERE sr2.resident_id = sr.resident_id
          )
    ");
    echo json_encode($stmtNeedHelp->fetchAll());
?>;

NEED_HELP.forEach(r => {
    if (!r.latitude || !r.longitude) return;

    const addr = [r.house_no, r.street, r.area].filter(Boolean).join(', ') || 'Address not set';
    const time = new Date(r.reported_at).toLocaleString('en-PH', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });

    const marker = L.circleMarker([r.latitude, r.longitude], {
        radius: 11,
        color: '#922B21',
        fillColor: '#E74C3C',
        fillOpacity: 0.9,
        weight: 2,
    });

    marker.bindPopup(`
        <div style="font-family:inherit; min-width:160px;">
            <div style="font-weight:700; color:#C0392B; margin-bottom:3px;">
                &#9888; ${r.first_name} ${r.last_name}
            </div>
            <div style="font-size:0.78rem; color:#666; margin-bottom:2px;">${addr}</div>
            ${r.notes ? `<div style="font-size:0.78rem; color:#333; margin-bottom:2px;">${r.notes}</div>` : ''}
            <div style="font-size:0.72rem; color:#999;">${time}</div>
        </div>
    `);

    marker.addTo(dashMap);
});
// ────────────────────────────────────────────────────
</script>

</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>
