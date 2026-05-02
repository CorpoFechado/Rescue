<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('resident');

$pageTitle  = 'Alerts';
$activePage = 'alerts';

$activeTab = $_GET['tab'] ?? 'alerts';

// ── Fetch alerts ──
$stmtAlerts = $conn->query("
    SELECT alert_id, title, message, type, severity, is_active, created_at
    FROM alerts
    ORDER BY
        is_active DESC,
        CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,
        created_at DESC
");
$alerts      = $stmtAlerts->fetchAll();
$totalActive = (int) $conn->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1")->fetchColumn();

// ── Fetch announcements ──
$stmtAnn = $conn->query("
    SELECT a.announcement_id, a.title, a.content, a.category,
           a.is_pinned, a.created_at,
           o.first_name, o.last_name
    FROM announcements a
    JOIN officials o ON o.official_id = a.created_by
    WHERE a.is_active = 1
    ORDER BY a.is_pinned DESC, a.created_at DESC
");
$announcements = $stmtAnn->fetchAll();

require_once __DIR__ . '/../../includes/header_resident.php';
?>

<style>
    .tab-switcher {
        display: grid;
        grid-template-columns: 1fr 1fr;
        background: #F0F3F4;
        border-radius: 12px;
        padding: 4px;
        margin-bottom: 1.25rem;
        gap: 4px;
    }

    .tab-switch-btn {
        padding: 0.6rem;
        border-radius: 9px;
        border: none;
        background: none;
        font-size: 0.83rem;
        font-weight: 600;
        color: var(--rescue-muted);
        cursor: pointer;
        font-family: inherit;
        text-align: center;
        text-decoration: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.15s;
    }

    .tab-switch-btn.active {
        background: var(--rescue-white);
        color: var(--rescue-text);
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }

    .tab-count {
        font-size: 0.65rem;
        font-weight: 700;
        padding: 1px 5px;
        border-radius: 10px;
        min-width: 16px;
        text-align: center;
        background: var(--rescue-red);
        color: #fff;
    }

    .tab-switch-btn:not(.active) .tab-count {
        background: #D5D8DC;
        color: var(--rescue-muted);
    }

    /* Alert cards */
    .alert-card {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        cursor: pointer;
        transition: box-shadow 0.15s;
        position: relative;
        overflow: hidden;
    }

    .alert-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.08); }
    .alert-card:last-child { margin-bottom: 0; }

    .alert-card::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 4px;
    }

    .alert-card.high::before   { background: #C0392B; }
    .alert-card.medium::before { background: #E67E22; }
    .alert-card.low::before    { background: #2ECC71; }
    .alert-card.inactive       { opacity: 0.55; }

    .alert-inner { padding-left: 8px; }

    .alert-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 4px;
    }

    .alert-title {
        font-size: 0.875rem;
        font-weight: 700;
        color: var(--rescue-text);
        margin: 0;
        line-height: 1.3;
        flex: 1;
    }

    .alert-msg {
        font-size: 0.8rem;
        color: var(--rescue-muted);
        margin: 0 0 8px;
        line-height: 1.5;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .alert-footer {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .alert-time { font-size:0.72rem; color:var(--rescue-muted); margin-left:auto; }

    /* Badges */
    .b-type   { font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .b-high   { background:#FADBD8; color:#C0392B; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .b-medium { background:#FDEBD0; color:#E67E22; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .b-low    { background:#EAFAF1; color:#1E8449; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .b-active { background:#EAFAF1; color:#1E8449; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .b-resolved { background:#F2F3F4; color:var(--rescue-muted); font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }

    /* Announcement cards */
    .ann-card {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        cursor: pointer;
        transition: box-shadow 0.15s;
        position: relative;
    }

    .ann-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.08); }
    .ann-card:last-child { margin-bottom: 0; }
    .ann-card.pinned { border-color: #FAD7A0; background: #FFFDF7; }

    .ann-pin {
        position: absolute;
        top: 10px; right: 10px;
        background: #FEF9E7;
        color: #B7770D;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 20px;
        border: 1px solid #FAD7A0;
    }

    .ann-title {
        font-size: 0.875rem;
        font-weight: 700;
        color: var(--rescue-text);
        margin: 0 0 4px;
        padding-right: 56px;
        line-height: 1.3;
    }

    .ann-content {
        font-size: 0.8rem;
        color: var(--rescue-muted);
        margin: 0 0 8px;
        line-height: 1.5;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .ann-footer {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .ann-time { font-size:0.72rem; color:var(--rescue-muted); margin-left:auto; }

    .cat-badge { font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
    .cat-general        { background:#EAFAF1; color:#1E8449; }
    .cat-health         { background:#F5EEF8; color:#6C3483; }
    .cat-relief         { background:#EBF5FB; color:#1A5276; }
    .cat-infrastructure { background:#FEF9E7; color:#784212; }
    .cat-weather        { background:#E8F8F5; color:#117A65; }

    /* Modal detail rows */
    .detail-row {
        display: flex;
        padding: 0.6rem 0;
        border-bottom: 1px solid var(--rescue-border);
        gap: 8px;
    }

    .detail-row:last-child { border-bottom: none; }
    .detail-label { width: 80px; font-size:.75rem; font-weight:700; color:var(--rescue-muted); flex-shrink:0; padding-top:1px; }
    .detail-value { font-size:.85rem; color:var(--rescue-text); flex:1; line-height:1.5; }

    /* Empty */
    .empty-state { text-align:center; padding:3rem 1rem; color:var(--rescue-muted); }
    .empty-state .ei { font-size:2.5rem; display:block; margin-bottom:0.75rem; }

    /* High severity pulse */
    @keyframes pulse {
        0%   { box-shadow: 0 0 0 0 rgba(192,57,43,0.25); }
        70%  { box-shadow: 0 0 0 6px rgba(192,57,43,0); }
        100% { box-shadow: 0 0 0 0 rgba(192,57,43,0); }
    }

    .alert-card.high:not(.inactive) { animation: pulse 2.5s infinite; }
</style>

<div class="resident-content">

    <!-- Header -->
    <div style="margin-bottom:1.1rem;">
        <h1 style="font-size:1.3rem; font-weight:700; color:var(--rescue-text); margin:0 0 3px;">
            Alerts &amp; Announcements
        </h1>
        <p style="font-size:0.8rem; color:var(--rescue-muted); margin:0;">
            <?= $totalActive ?> active alert<?= $totalActive != 1 ? 's' : '' ?>
            &bull; <?= count($announcements) ?> announcement<?= count($announcements) != 1 ? 's' : '' ?>
        </p>
    </div>

    <!-- Tab switcher -->
    <div class="tab-switcher">
        <a href="?tab=alerts"
            class="tab-switch-btn <?= $activeTab === 'alerts' ? 'active' : '' ?>">
            <i class="bi bi-bell-fill"></i> Alerts
            <?php if ($totalActive > 0): ?>
            <span class="tab-count"><?= $totalActive ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=announcements"
            class="tab-switch-btn <?= $activeTab === 'announcements' ? 'active' : '' ?>">
            <i class="bi bi-megaphone-fill"></i> Announcements
            <?php if (count($announcements) > 0): ?>
            <span class="tab-count" style="background:#5D6D7E;"><?= count($announcements) ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- ALERTS TAB -->
    <?php if ($activeTab === 'alerts'): ?>

    <?php if (empty($alerts)): ?>
    <div class="empty-state">
        <span class="ei">&#128276;</span>
        <p style="font-weight:600; color:var(--rescue-text); margin-bottom:4px;">No alerts yet</p>
        <p style="font-size:0.82rem; margin:0;">No alerts have been issued by the barangay.</p>
    </div>
    <?php else:
        $typeMeta = [
            'flood'   => ['bg'=>'#EBF5FB','color'=>'#1A5276','label'=>'FLOOD'],
            'fire'    => ['bg'=>'#FDEDEC','color'=>'#922B21','label'=>'FIRE'],
            'general' => ['bg'=>'#EAFAF1','color'=>'#1E8449','label'=>'GENERAL'],
        ];
        foreach ($alerts as $a):
            $tm = $typeMeta[$a['type']] ?? $typeMeta['general'];
            $d  = json_encode([
                'title'      => $a['title'],
                'message'    => $a['message'],
                'type_label' => $tm['label'],
                'type_bg'    => $tm['bg'],
                'type_color' => $tm['color'],
                'severity'   => $a['severity'],
                'is_active'  => (int) $a['is_active'],
                'created_at' => date('F j, Y g:i A', strtotime($a['created_at'])),
            ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div class="alert-card <?= $a['severity'] ?> <?= !$a['is_active'] ? 'inactive' : '' ?>"
        onclick='openAlertModal(<?= $d ?>)'>
        <div class="alert-inner">
            <div class="alert-top">
                <p class="alert-title"><?= htmlspecialchars($a['title']) ?></p>
                <span class="<?= $a['is_active'] ? 'b-active' : 'b-resolved' ?>" style="flex-shrink:0;">
                    <?= $a['is_active'] ? 'Active' : 'Resolved' ?>
                </span>
            </div>
            <p class="alert-msg"><?= htmlspecialchars($a['message']) ?></p>
            <div class="alert-footer">
                <span class="b-type" style="background:<?= $tm['bg'] ?>;color:<?= $tm['color'] ?>;"><?= $tm['label'] ?></span>
                <span class="b-<?= $a['severity'] ?>"><?= strtoupper($a['severity']) ?></span>
                <span class="alert-time"><?= date('M j, g:i A', strtotime($a['created_at'])) ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ANNOUNCEMENTS TAB -->
    <?php else: ?>

    <?php if (empty($announcements)): ?>
    <div class="empty-state">
        <span class="ei">&#128227;</span>
        <p style="font-weight:600; color:var(--rescue-text); margin-bottom:4px;">No announcements yet</p>
        <p style="font-size:0.82rem; margin:0;">Check back later for updates from the barangay.</p>
    </div>
    <?php else:
        $catMeta = [
            'general'        => ['label'=>'General',        'class'=>'cat-general'],
            'health'         => ['label'=>'Health',         'class'=>'cat-health'],
            'relief'         => ['label'=>'Relief Goods',   'class'=>'cat-relief'],
            'infrastructure' => ['label'=>'Infrastructure', 'class'=>'cat-infrastructure'],
            'weather'        => ['label'=>'Weather',        'class'=>'cat-weather'],
        ];
        foreach ($announcements as $ann):
            $cm = $catMeta[$ann['category']] ?? $catMeta['general'];
            $d  = json_encode([
                'title'     => $ann['title'],
                'content'   => $ann['content'],
                'category'  => $cm['label'],
                'cat_class' => $cm['class'],
                'posted_by' => $ann['first_name'] . ' ' . $ann['last_name'],
                'date'      => date('F j, Y g:i A', strtotime($ann['created_at'])),
                'is_pinned' => (int) $ann['is_pinned'],
            ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div class="ann-card <?= $ann['is_pinned'] ? 'pinned' : '' ?>"
        onclick='openAnnModal(<?= $d ?>)'>
        <?php if ($ann['is_pinned']): ?>
        <span class="ann-pin">&#128204; Pinned</span>
        <?php endif; ?>
        <p class="ann-title"><?= htmlspecialchars($ann['title']) ?></p>
        <p class="ann-content"><?= htmlspecialchars($ann['content']) ?></p>
        <div class="ann-footer">
            <span class="cat-badge <?= $cm['class'] ?>"><?= $cm['label'] ?></span>
            <span class="ann-time"><?= date('M j, Y', strtotime($ann['created_at'])) ?></span>
        </div>
    </div>
    <?php endforeach; endif; ?>
    <?php endif; ?>

</div>


<!-- ALERT DETAIL MODAL -->
<div class="r-modal-backdrop" id="alertModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:0;">
                <span id="am-type-badge"></span>
                <h5 style="margin:0; font-size:0.95rem; font-weight:700;" id="am-title"></h5>
            </div>
            <button onclick="closeResidentModal('alertModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);flex-shrink:0;">&#x2715;</button>
        </div>
        <div class="r-modal-body">
            <div style="background:#F8F9FA;border-radius:10px;padding:1rem;margin-bottom:1.1rem;font-size:0.875rem;color:var(--rescue-text);line-height:1.6;" id="am-message"></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value" id="am-status"></span></div>
            <div class="detail-row"><span class="detail-label">Severity</span><span class="detail-value" id="am-severity"></span></div>
            <div class="detail-row"><span class="detail-label">Issued</span><span class="detail-value" id="am-date"></span></div>
            <div id="am-emergency" style="display:none;margin-top:1rem;background:var(--rescue-red-light);border:1px solid #F1948A;border-radius:10px;padding:0.875rem;font-size:0.82rem;color:var(--rescue-red);line-height:1.5;">
                <strong>&#9888; High Severity</strong> — If you are in immediate danger, call <strong>911</strong> right away.
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" style="flex:1;" onclick="closeResidentModal('alertModal')">Close</button>
        </div>
    </div>
</div>


<!-- ANNOUNCEMENT DETAIL MODAL -->
<div class="r-modal-backdrop" id="annModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5 style="margin:0; font-weight:700;" id="ann-modal-title"></h5>
            <button onclick="closeResidentModal('annModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);flex-shrink:0;">&#x2715;</button>
        </div>
        <div class="r-modal-body">
            <div id="ann-pin-note" style="display:none;background:#FEF9E7;border:1px solid #FAD7A0;border-radius:8px;padding:0.6rem 0.875rem;margin-bottom:1rem;font-size:0.78rem;color:#B7770D;font-weight:600;">
                &#128204; Pinned announcement
            </div>
            <div style="font-size:0.875rem;color:var(--rescue-text);line-height:1.7;margin-bottom:1.1rem;white-space:pre-wrap;" id="ann-modal-content"></div>
            <div class="detail-row"><span class="detail-label">Category</span><span class="detail-value" id="ann-modal-cat"></span></div>
            <div class="detail-row"><span class="detail-label">Posted by</span><span class="detail-value" id="ann-modal-by"></span></div>
            <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value" id="ann-modal-date"></span></div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" style="flex:1;" onclick="closeResidentModal('annModal')">Close</button>
        </div>
    </div>
</div>


<script>
function openAlertModal(a) {
    const sevMeta = {
        high:   {bg:'#FADBD8',color:'#C0392B',label:'HIGH'},
        medium: {bg:'#FDEBD0',color:'#E67E22',label:'MEDIUM'},
        low:    {bg:'#EAFAF1',color:'#1E8449',label:'LOW'},
    };
    const sev = sevMeta[a.severity] || sevMeta.low;

    document.getElementById('am-title').textContent   = a.title;
    document.getElementById('am-message').textContent = a.message;
    document.getElementById('am-date').textContent    = a.created_at;

    document.getElementById('am-type-badge').innerHTML =
        `<span style="background:${a.type_bg};color:${a.type_color};font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap;flex-shrink:0;">${a.type_label}</span>`;

    document.getElementById('am-status').innerHTML = a.is_active
        ? '<span class="b-active">Active</span>'
        : '<span class="b-resolved">Resolved</span>';

    document.getElementById('am-severity').innerHTML =
        `<span style="background:${sev.bg};color:${sev.color};font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;">${sev.label}</span>`;

    document.getElementById('am-emergency').style.display =
        (a.severity === 'high' && a.is_active) ? 'block' : 'none';

    openResidentModal('alertModal');
}

function openAnnModal(a) {
    document.getElementById('ann-modal-title').textContent   = a.title;
    document.getElementById('ann-modal-content').textContent = a.content;
    document.getElementById('ann-modal-by').textContent      = a.posted_by;
    document.getElementById('ann-modal-date').textContent    = a.date;
    document.getElementById('ann-modal-cat').innerHTML =
        `<span class="cat-badge ${a.cat_class}">${a.category}</span>`;
    document.getElementById('ann-pin-note').style.display = a.is_pinned ? 'block' : 'none';
    openResidentModal('annModal');
}

function openResidentModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeResidentModal(id) {
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.r-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) closeResidentModal(this.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.r-modal-backdrop.open')
            .forEach(m => closeResidentModal(m.id));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer_resident.php'; ?>