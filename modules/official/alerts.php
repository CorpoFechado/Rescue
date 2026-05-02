<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Alerts & Announcements';
$activePage = 'alerts';

$success = '';
$error   = '';

// ── Ensure announcements table exists ────────────────────────────────────
$conn->exec("
    CREATE TABLE IF NOT EXISTS `announcements` (
        `announcement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `created_by`      int(10) UNSIGNED NOT NULL,
        `title`           varchar(150) NOT NULL,
        `content`         text NOT NULL,
        `category`        enum('general','health','relief','infrastructure','weather') NOT NULL DEFAULT 'general',
        `is_pinned`       tinyint(1) DEFAULT 0,
        `is_active`       tinyint(1) DEFAULT 1,
        `created_at`      timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`announcement_id`),
        KEY `fk_ann_official` (`created_by`),
        CONSTRAINT `fk_ann_official` FOREIGN KEY (`created_by`) REFERENCES `officials` (`official_id`) ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

// ── Get official_id ───────────────────────────────────────────────────────
$stmtOfficialId = $conn->prepare("SELECT official_id FROM officials WHERE user_id = ?");
$stmtOfficialId->execute([$_SESSION['user_id']]);
$officialRow = $stmtOfficialId->fetch();
$officialId  = $officialRow ? $officialRow['official_id'] : null;

// ── Active tab ────────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'alerts';

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ════ ALERTS ════ */
    if ($action === 'create_alert') {
        $title    = trim($_POST['title']    ?? '');
        $message  = trim($_POST['message']  ?? '');
        $type     = $_POST['type']     ?? 'general';
        $severity = $_POST['severity'] ?? 'low';
        if (empty($title) || empty($message)) {
            $error = 'Alert title and message are required.';
        } elseif (!$officialId) {
            $error = 'Could not identify official account.';
        } else {
            $conn->prepare("INSERT INTO alerts (created_by,title,message,type,severity,is_active) VALUES (?,?,?,?,?,1)")
                 ->execute([$officialId, $title, $message, $type, $severity]);
            $success   = 'Alert "' . htmlspecialchars($title) . '" sent to all residents.';
            $activeTab = 'alerts';
        }
    }
    if ($action === 'edit_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $title   = trim($_POST['title']     ?? '');
        $message = trim($_POST['message']   ?? '');
        $type    = $_POST['type']     ?? 'general';
        $sev     = $_POST['severity'] ?? 'low';
        if (empty($title) || empty($message)) { $error = 'Title and message are required.'; }
        elseif ($alertId) {
            $conn->prepare("UPDATE alerts SET title=?,message=?,type=?,severity=? WHERE alert_id=?")
                 ->execute([$title, $message, $type, $sev, $alertId]);
            $success = 'Alert updated.'; $activeTab = 'alerts';
        }
    }
    if ($action === 'toggle_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0); $ns = (int)($_POST['new_status'] ?? 0);
        if ($alertId) { $conn->prepare("UPDATE alerts SET is_active=? WHERE alert_id=?")->execute([$ns,$alertId]); $success='Alert status updated.'; $activeTab='alerts'; }
    }
    if ($action === 'resend_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if ($alertId) { $conn->prepare("UPDATE alerts SET is_active=1,created_at=NOW() WHERE alert_id=?")->execute([$alertId]); $success='Alert resent.'; $activeTab='alerts'; }
    }
    if ($action === 'delete_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if ($alertId) { $conn->prepare("DELETE FROM alerts WHERE alert_id=?")->execute([$alertId]); $success='Alert deleted.'; $activeTab='alerts'; }
    }

    /* ════ ANNOUNCEMENTS ════ */
    if ($action === 'create_announcement') {
        $title    = trim($_POST['title']   ?? '');
        $content  = trim($_POST['content'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $pinned   = isset($_POST['is_pinned']) ? 1 : 0;
        if (empty($title) || empty($content)) { $error = 'Title and content are required.'; }
        elseif (!$officialId) { $error = 'Could not identify official account.'; }
        else {
            $conn->prepare("INSERT INTO announcements (created_by,title,content,category,is_pinned,is_active) VALUES (?,?,?,?,?,1)")
                 ->execute([$officialId,$title,$content,$category,$pinned]);
            $success='Announcement "'.htmlspecialchars($title).'" published.'; $activeTab='announcements';
        }
    }
    if ($action === 'edit_announcement') {
        $annId   = (int)($_POST['announcement_id'] ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $content = trim($_POST['content'] ?? '');
        $cat     = $_POST['category'] ?? 'general';
        $pinned  = isset($_POST['is_pinned']) ? 1 : 0;
        if (empty($title)||empty($content)) { $error='Title and content are required.'; }
        elseif ($annId) {
            $conn->prepare("UPDATE announcements SET title=?,content=?,category=?,is_pinned=? WHERE announcement_id=?")
                 ->execute([$title,$content,$cat,$pinned,$annId]);
            $success='Announcement updated.'; $activeTab='announcements';
        }
    }
    if ($action === 'toggle_announcement') {
        $annId=(int)($_POST['announcement_id']??0); $ns=(int)($_POST['new_status']??0);
        if ($annId) { $conn->prepare("UPDATE announcements SET is_active=? WHERE announcement_id=?")->execute([$ns,$annId]); $success='Announcement status updated.'; $activeTab='announcements'; }
    }
    if ($action === 'toggle_pin') {
        $annId=(int)($_POST['announcement_id']??0); $pin=(int)($_POST['new_pin']??0);
        if ($annId) { $conn->prepare("UPDATE announcements SET is_pinned=? WHERE announcement_id=?")->execute([$pin,$annId]); $success=$pin?'Pinned.':'Unpinned.'; $activeTab='announcements'; }
    }
    if ($action === 'delete_announcement') {
        $annId=(int)($_POST['announcement_id']??0);
        if ($annId) { $conn->prepare("DELETE FROM announcements WHERE announcement_id=?")->execute([$annId]); $success='Announcement deleted.'; $activeTab='announcements'; }
    }
}

// ── Fetch Alerts ──────────────────────────────────────────────────────────
$filterType  = $_GET['type']   ?? 'all';
$filterSt    = $_GET['status'] ?? 'all';
$searchA     = trim($_GET['search_alert'] ?? '');
$sqlA = "SELECT a.*,o.first_name,o.last_name FROM alerts a JOIN officials o ON o.official_id=a.created_by WHERE 1=1";
$pA = [];
if ($filterType!=='all')    { $sqlA.=" AND a.type=?";      $pA[]=$filterType; }
if ($filterSt==='active')     $sqlA.=" AND a.is_active=1";
elseif ($filterSt==='inactive') $sqlA.=" AND a.is_active=0";
if (!empty($searchA))       { $sqlA.=" AND (a.title LIKE ? OR a.message LIKE ?)"; $pA[]="%$searchA%"; $pA[]="%$searchA%"; }
$sqlA.=" ORDER BY a.created_at DESC";
$stA=$conn->prepare($sqlA); $stA->execute($pA); $alerts=$stA->fetchAll();

// ── Fetch Announcements ───────────────────────────────────────────────────
$filterCat  = $_GET['category']   ?? 'all';
$filterAnnSt= $_GET['ann_status'] ?? 'all';
$searchN    = trim($_GET['search_ann'] ?? '');
$sqlN = "SELECT n.*,o.first_name,o.last_name FROM announcements n JOIN officials o ON o.official_id=n.created_by WHERE 1=1";
$pN = [];
if ($filterCat!=='all')       { $sqlN.=" AND n.category=?"; $pN[]=$filterCat; }
if ($filterAnnSt==='active')    $sqlN.=" AND n.is_active=1";
elseif($filterAnnSt==='inactive') $sqlN.=" AND n.is_active=0";
if (!empty($searchN))         { $sqlN.=" AND (n.title LIKE ? OR n.content LIKE ?)"; $pN[]="%$searchN%"; $pN[]="%$searchN%"; }
$sqlN.=" ORDER BY n.is_pinned DESC, n.created_at DESC";
$stN=$conn->prepare($sqlN); $stN->execute($pN); $announcements=$stN->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────
$alertStats = $conn->query("SELECT COUNT(*) AS total, SUM(is_active=1) AS active, SUM(severity='high') AS high_sev,
    SUM(type='flood') AS flood, SUM(type='fire') AS fire, SUM(type='landslide') AS landslide, SUM(type='general') AS general
    FROM alerts")->fetch();
$annStats = $conn->query("SELECT COUNT(*) AS total, SUM(is_active=1) AS active, SUM(is_pinned=1) AS pinned FROM announcements")->fetch();
$recipientCount = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='resident' AND is_active=1")->fetchColumn();

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
:root {
    --at-flood:#1A5276;  --at-flood-bg:#EBF5FB;
    --at-fire:#922B21;   --at-fire-bg:#FDEDEC;
    --at-land:#784212;   --at-land-bg:#FEF9E7;
    --at-gen:#1E8449;    --at-gen-bg:#EAFAF1;
    --sev-high:#C0392B;  --sev-high-bg:#FADBD8;
    --sev-med:#D68910;   --sev-med-bg:#FDEBD0;
    --sev-low:#1E8449;   --sev-low-bg:#EAFAF1;
}

.al-stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(148px,1fr)); gap:13px; margin-bottom:1.5rem; }

/* ── Tabs ── */
.tab-bar { display:flex; border-bottom:2px solid var(--rescue-border); margin-bottom:1.4rem; }
.tab-btn { padding:.65rem 1.4rem; font-size:.875rem; font-weight:600; font-family:inherit; background:none; border:none; border-bottom:3px solid transparent; margin-bottom:-2px; cursor:pointer; color:var(--rescue-muted); display:flex; align-items:center; gap:7px; transition:color .15s; }
.tab-btn.active { color:var(--rescue-red); border-bottom-color:var(--rescue-red); }
.tab-btn:hover:not(.active) { color:var(--rescue-text); }
.tab-count { background:#F2F3F4; color:var(--rescue-muted); font-size:.68rem; font-weight:700; padding:1px 7px; border-radius:20px; }
.tab-btn.active .tab-count { background:var(--rescue-red-light); color:var(--rescue-red); }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

/* ── Two-col grid (NO sticky on right) ── */
.al-grid { display:grid; grid-template-columns:1fr 350px; gap:20px; align-items:start; }
@media(max-width:991px){ .al-grid{grid-template-columns:1fr;} }
.right-panel { display:flex; flex-direction:column; gap:14px; }

/* ── Cards ── */
.compose-card { background:var(--rescue-white); border:1px solid var(--rescue-border); border-radius:14px; overflow:hidden; }
.compose-hdr { padding:1rem 1.25rem; color:#fff; }
.compose-hdr.red   { background:var(--rescue-red); }
.compose-hdr.green { background:#1E8449; }
.compose-hdr h2 { font-size:.95rem; font-weight:700; margin:0 0 2px; }
.compose-hdr p  { font-size:.78rem; margin:0; opacity:.85; }
.compose-body { padding:1.25rem; }

.preview-block { background:#FAFAFA; border:1px solid var(--rescue-border); border-radius:10px; padding:.85rem 1rem; margin:.75rem 0; }
.preview-lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--rescue-muted); margin-bottom:.5rem; }

.tip-card { border-radius:12px; padding:1rem 1.25rem; font-size:.8rem; line-height:1.6; }
.tip-card h4 { font-size:.82rem; font-weight:700; margin:0 0 .5rem; }

.filter-bar { background:var(--rescue-white); border:1px solid var(--rescue-border); border-radius:12px; padding:.9rem 1.15rem; display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:1rem; }

.item-card { background:var(--rescue-white); border:1px solid var(--rescue-border); border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:10px; transition:box-shadow .15s; }
.item-card:hover { box-shadow:0 2px 10px rgba(0,0,0,.07); }
.item-card.inactive { opacity:.6; }
.item-card.pinned { border-left:3px solid #F39C12; }

.card-hdr { display:flex; align-items:flex-start; gap:10px; margin-bottom:.5rem; }
.type-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:5px; }
.card-title { font-size:.92rem; font-weight:700; color:var(--rescue-text); flex:1; line-height:1.3; }
.card-body  { font-size:.83rem; color:var(--rescue-muted); line-height:1.55; margin-bottom:.7rem; }
.card-meta  { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.75rem; color:var(--rescue-muted); }
.card-acts  { display:flex; gap:5px; flex-wrap:wrap; margin-top:.7rem; padding-top:.7rem; border-top:1px solid var(--rescue-border); }

/* Badges */
.badge { font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; text-transform:uppercase; letter-spacing:.4px; display:inline-flex; align-items:center; gap:3px; }
.badge-flood   { background:#EBF5FB; color:#1A5276; }
.badge-fire    { background:#FDEDEC; color:#922B21; }
.badge-land    { background:#FEF9E7; color:#784212; }
.badge-gen     { background:#EAFAF1; color:#1E8449; }
.badge-health  { background:#F5EEF8; color:#6C3483; }
.badge-relief  { background:#EBF5FB; color:#1A5276; }
.badge-infra   { background:#FEF9E7; color:#784212; }
.badge-weather { background:#EAFAF1; color:#1E8449; }
.sev-high   { background:#FADBD8; color:#C0392B; }
.sev-medium { background:#FDEBD0; color:#D68910; }
.sev-low    { background:#EAFAF1; color:#1E8449; }
.badge-on   { background:#EAFAF1; color:#1E8449; }
.badge-off  { background:#F2F3F4; color:#7F8C8D; }
.badge-pin  { background:#FEF9E7; color:#B7770D; }

/* Dots */
.dot-flood{background:#1A5276;} .dot-fire{background:#922B21;} .dot-land{background:#784212;} .dot-gen{background:#1E8449;}
.dot-health{background:#6C3483;} .dot-relief{background:#1A5276;} .dot-infra{background:#784212;} .dot-weather{background:#1E8449;}

/* Buttons */
.action-btn { background:none; border:1px solid var(--rescue-border); border-radius:7px; padding:4px 10px; font-size:.78rem; cursor:pointer; font-family:inherit; color:var(--rescue-text); transition:all .15s; display:inline-flex; align-items:center; gap:4px; }
.action-btn:hover         { background:#F5F5F5; }
.action-btn.danger        { color:var(--rescue-red);  border-color:var(--rescue-red-light); }
.action-btn.danger:hover  { background:var(--rescue-red-light); }
.action-btn.warning       { color:#B7770D; border-color:#FAD7A0; }
.action-btn.warning:hover { background:#FEF9E7; }
.action-btn.success       { color:#1E8449; border-color:#A9DFBF; }
.action-btn.success:hover { background:#EAFAF1; }
.action-btn.blue          { color:#2980B9; border-color:#AED6F1; }
.action-btn.blue:hover    { background:#EBF5FB; }
.action-btn.amber         { color:#B7770D; border-color:#FAD7A0; }
.action-btn.amber:hover   { background:#FEF9E7; }
.action-btn.primary       { background:var(--rescue-red); color:#fff; border-color:var(--rescue-red); }
.action-btn.primary:hover { background:var(--rescue-red-dark); }

/* Modals */
.rescue-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; padding:1rem; }
.rescue-modal-backdrop.open { display:flex; }
.rescue-modal { background:#fff; border-radius:16px; width:100%; max-width:500px; max-height:90vh; overflow-y:auto; }
.modal-hdr { padding:1.25rem 1.5rem 1rem; border-bottom:1px solid var(--rescue-border); display:flex; align-items:center; justify-content:space-between; }
.modal-hdr h5 { font-size:1rem; font-weight:700; margin:0; color:var(--rescue-text); }
.modal-body { padding:1.25rem 1.5rem; }
.modal-ftr { padding:1rem 1.5rem; border-top:1px solid var(--rescue-border); display:flex; gap:10px; justify-content:flex-end; }
.btn-cancel { padding:.6rem 1.25rem; border:1.5px solid var(--rescue-border); border-radius:10px; background:#fff; font-size:.875rem; font-weight:600; color:var(--rescue-text); cursor:pointer; font-family:inherit; }
.btn-submit { padding:.6rem 1.25rem; border:none; border-radius:10px; background:var(--rescue-red); font-size:.875rem; font-weight:600; color:#fff; cursor:pointer; font-family:inherit; }

.empty-state { text-align:center; padding:3rem 1rem; color:var(--rescue-muted); }
.empty-state i { font-size:2.5rem; display:block; margin-bottom:.75rem; color:#D5D8DC; }
.empty-state p { font-size:.875rem; margin:0; }
</style>

<!-- Header -->
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.5rem;font-weight:700;color:var(--rescue-text);margin:0 0 4px;">Alerts &amp; Announcements</h1>
    <p style="font-size:.85rem;color:var(--rescue-muted);margin:0;">Manage disaster alerts and community announcements for <?= $recipientCount ?> resident<?= $recipientCount!==1?'s':'' ?></p>
</div>

<!-- Flash -->
<?php if ($success): ?>
<div style="background:#EAFAF1;border:1px solid #A9DFBF;color:#1E8449;border-radius:10px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.85rem;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-check-circle-fill"></i> <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:var(--rescue-red-light);border:1px solid #F1948A;color:var(--rescue-red-dark);border-radius:10px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.85rem;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-exclamation-circle-fill"></i> <?= $error ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="al-stat-grid">
    <div class="stat-card">
        <div><div class="stat-label">Total Alerts</div><div class="stat-value" style="color:var(--rescue-red);"><?= (int)($alertStats['total']??0) ?></div></div>
        <div class="stat-icon" style="background:var(--rescue-red-light);color:var(--rescue-red);"><i class="bi bi-bell-fill"></i></div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Active Alerts</div><div class="stat-value" style="color:#C0392B;"><?= (int)($alertStats['active']??0) ?></div></div>
        <div class="stat-icon" style="background:#FADBD8;color:#C0392B;"><i class="bi bi-exclamation-triangle-fill"></i></div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Announcements</div><div class="stat-value" style="color:#1E8449;"><?= (int)($annStats['total']??0) ?></div></div>
        <div class="stat-icon" style="background:#EAFAF1;color:#1E8449;"><i class="bi bi-megaphone-fill"></i></div>
    </div>
    <div class="stat-card">
        <div><div class="stat-label">Recipients</div><div class="stat-value" style="color:#2980B9;"><?= $recipientCount ?></div></div>
        <div class="stat-icon" style="background:#EBF5FB;color:#2980B9;"><i class="bi bi-people-fill"></i></div>
    </div>
</div>

<!-- Tabs -->
<div class="tab-bar">
    <button class="tab-btn <?= $activeTab==='alerts'?'active':'' ?>" onclick="switchTab('alerts')">
        <i class="bi bi-bell-fill"></i> Alerts <span class="tab-count"><?= (int)($alertStats['active']??0) ?> active</span>
    </button>
    <button class="tab-btn <?= $activeTab==='announcements'?'active':'' ?>" onclick="switchTab('announcements')">
        <i class="bi bi-megaphone-fill"></i> Announcements <span class="tab-count"><?= (int)($annStats['total']??0) ?></span>
    </button>
</div>

<?php
// ── helper maps ────────────────────────────────────────────────────────────
$typeBadge = ['flood'=>'badge-flood','fire'=>'badge-fire','landslide'=>'badge-land','general'=>'badge-gen'];
$typeDot   = ['flood'=>'dot-flood',  'fire'=>'dot-fire',  'landslide'=>'dot-land',  'general'=>'dot-gen'];
$sevBadge  = ['high'=>'sev-high','medium'=>'sev-medium','low'=>'sev-low'];
$catBadge  = ['general'=>'badge-gen','health'=>'badge-health','relief'=>'badge-relief','infrastructure'=>'badge-infra','weather'=>'badge-weather'];
$catDot    = ['general'=>'dot-gen',  'health'=>'dot-health',  'relief'=>'dot-relief',  'infrastructure'=>'dot-infra',  'weather'=>'dot-weather'];
$catLabel  = ['general'=>'General','health'=>'Health','relief'=>'Relief Goods','infrastructure'=>'Infrastructure','weather'=>'Weather'];
?>

<!-- ══════════════ ALERTS PANEL ══════════════ -->
<div class="tab-panel <?= $activeTab==='alerts'?'active':'' ?>" id="panel-alerts">
<div class="al-grid">

  <!-- Left: list -->
  <div>
    <div class="filter-bar">
      <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%;">
        <input type="hidden" name="tab" value="alerts">
        <div style="position:relative;flex:1;min-width:170px;">
          <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--rescue-muted);font-size:.85rem;"></i>
          <input type="text" name="search_alert" value="<?= htmlspecialchars($searchA) ?>" placeholder="Search alerts…"
            style="width:100%;padding:.55rem .75rem .55rem 2rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;"
            onfocus="this.style.borderColor='var(--rescue-red)'" onblur="this.style.borderColor='var(--rescue-border)'">
        </div>
        <select name="type" style="padding:.55rem .75rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;color:var(--rescue-text);cursor:pointer;">
          <option value="all" <?=$filterType==='all'?'selected':''?>>All Types</option>
          <option value="flood" <?=$filterType==='flood'?'selected':''?>>Flood</option>
          <option value="fire" <?=$filterType==='fire'?'selected':''?>>Fire</option>
          <option value="landslide" <?=$filterType==='landslide'?'selected':''?>>Landslide</option>
          <option value="general" <?=$filterType==='general'?'selected':''?>>General</option>
        </select>
        <select name="status" style="padding:.55rem .75rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;color:var(--rescue-text);cursor:pointer;">
          <option value="all" <?=$filterSt==='all'?'selected':''?>>All Status</option>
          <option value="active" <?=$filterSt==='active'?'selected':''?>>Active</option>
          <option value="inactive" <?=$filterSt==='inactive'?'selected':''?>>Inactive</option>
        </select>
        <button type="submit" class="btn-rescue" style="padding:.55rem 1.1rem;"><i class="bi bi-funnel-fill"></i> Filter</button>
        <?php if($searchA||$filterType!=='all'||$filterSt!=='all'): ?><a href="?tab=alerts" style="font-size:.82rem;color:var(--rescue-muted);text-decoration:none;font-weight:500;">Clear</a><?php endif; ?>
      </form>
    </div>

    <div style="font-size:.88rem;font-weight:700;color:var(--rescue-text);margin-bottom:.75rem;">
      Alert History <span style="font-size:.8rem;font-weight:500;color:var(--rescue-muted);margin-left:6px;">(<?= count($alerts) ?> result<?= count($alerts)!==1?'s':''?>)</span>
    </div>

    <?php if(empty($alerts)): ?>
    <div class="rescue-card"><div class="empty-state"><i class="bi bi-bell-slash"></i><p>No alerts found.</p><p style="font-size:.8rem;margin-top:4px;"><?=($searchA||$filterType!=='all'||$filterSt!=='all')?'Try adjusting your filters.':'Use the form to send your first alert.'?></p></div></div>
    <?php else: foreach($alerts as $a):
      $ad = htmlspecialchars(json_encode(['alert_id'=>(int)$a['alert_id'],'title'=>$a['title'],'message'=>$a['message'],'type'=>$a['type'],'severity'=>$a['severity'],'is_active'=>(int)$a['is_active']]),ENT_QUOTES,'UTF-8');
    ?>
    <div class="item-card <?=!$a['is_active']?'inactive':''?>">
      <div class="card-hdr">
        <div class="type-dot <?=$typeDot[$a['type']]??'dot-gen'?>"></div>
        <div class="card-title"><?=htmlspecialchars($a['title'])?></div>
        <span class="badge <?=$a['is_active']?'badge-on':'badge-off'?>"><?=$a['is_active']?'Active':'Inactive'?></span>
      </div>
      <div class="card-body"><?=htmlspecialchars($a['message'])?></div>
      <div class="card-meta">
        <span class="badge <?=$typeBadge[$a['type']]??'badge-gen'?>"><?=strtoupper($a['type'])?></span>
        <span class="badge <?=$sevBadge[$a['severity']]??'sev-low'?>"><?=strtoupper($a['severity'])?></span>
        <span><i class="bi bi-clock" style="font-size:.72rem;"></i> <?=date('M j, Y g:i A',strtotime($a['created_at']))?></span>
        <span><i class="bi bi-person" style="font-size:.72rem;"></i> <?=htmlspecialchars($a['first_name'].' '.$a['last_name'])?></span>
      </div>
      <div class="card-acts">
        <button class="action-btn blue"    onclick="openAlertView(JSON.parse(this.dataset.a))"   data-a="<?=$ad?>"><i class="bi bi-eye"></i> View</button>
        <button class="action-btn"         onclick="openAlertEdit(JSON.parse(this.dataset.a))"   data-a="<?=$ad?>"><i class="bi bi-pencil"></i> Edit</button>
        <?php if($a['is_active']): ?>
        <button class="action-btn warning" onclick="openAlertToggle(JSON.parse(this.dataset.a))" data-a="<?=$ad?>"><i class="bi bi-pause-circle"></i> Deactivate</button>
        <?php else: ?>
        <button class="action-btn success" onclick="openAlertToggle(JSON.parse(this.dataset.a))" data-a="<?=$ad?>"><i class="bi bi-play-circle"></i> Activate</button>
        <?php endif; ?>
        <button class="action-btn primary" onclick="openAlertResend(JSON.parse(this.dataset.a))" data-a="<?=$ad?>"><i class="bi bi-send-fill"></i> Resend</button>
        <button class="action-btn danger"  onclick="openAlertDelete(JSON.parse(this.dataset.a))" data-a="<?=$ad?>"><i class="bi bi-trash"></i> Delete</button>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Right: compose + stats (NO sticky, no fixed height) -->
  <div class="right-panel">
    <div class="compose-card">
      <div class="compose-hdr red"><h2><i class="bi bi-send-fill"></i> Send Alert</h2><p>Broadcast to <?=$recipientCount?> resident<?=$recipientCount!==1?'s':''?></p></div>
      <div class="compose-body">
        <form method="POST">
          <input type="hidden" name="action" value="create_alert">
          <div style="margin-bottom:.9rem;"><label class="form-label">Title *</label>
            <input type="text" name="title" id="a-title" class="form-control" required maxlength="150" placeholder="e.g., Typhoon Warning Signal #3" oninput="previewAlert()"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:.9rem;">
            <div><label class="form-label">Type *</label>
              <select name="type" id="a-type" class="form-control" onchange="previewAlert()">
                <option value="general">General</option><option value="flood">Flood</option><option value="fire">Fire</option><option value="landslide">Landslide</option>
              </select></div>
            <div><label class="form-label">Severity *</label>
              <select name="severity" id="a-severity" class="form-control" onchange="previewAlert()">
                <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
              </select></div>
          </div>
          <div style="margin-bottom:.9rem;"><label class="form-label">Message *</label>
            <textarea name="message" id="a-message" class="form-control" rows="3" required maxlength="1000"
              placeholder="Include specific safety instructions…" oninput="previewAlert();ucc('a-message','a-chars')" style="resize:vertical;"></textarea>
            <div style="text-align:right;font-size:.72rem;color:var(--rescue-muted);margin-top:2px;"><span id="a-chars">0</span>/1000</div></div>
          <div class="preview-block">
            <div class="preview-lbl"><i class="bi bi-eye"></i> Preview</div>
            <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:.35rem;">
              <div id="ap-dot" class="type-dot dot-gen" style="margin-top:4px;"></div>
              <div id="ap-title" style="font-size:.88rem;font-weight:700;color:var(--rescue-text);flex:1;">Alert Title</div>
              <span id="ap-sev" class="badge sev-low">LOW</span>
            </div>
            <div id="ap-msg" style="font-size:.78rem;color:var(--rescue-muted);margin-left:18px;line-height:1.5;">Message…</div>
            <div style="margin-top:.45rem;margin-left:18px;display:flex;gap:6px;">
              <span id="ap-type" class="badge badge-gen">GENERAL</span>
              <span style="font-size:.72rem;color:var(--rescue-muted);"><i class="bi bi-clock" style="font-size:.7rem;"></i> Just now</span>
            </div>
          </div>
          <button type="submit" class="btn-rescue" style="width:100%;" onclick="return confirm('Send this alert to all <?=$recipientCount?> resident(s)?')">
            <i class="bi bi-send-fill"></i> Send Alert
          </button>
        </form>
      </div>
    </div>

    <!-- Alert type breakdown -->
    <div class="rescue-card" style="padding:1rem 1.25rem;">
      <div style="font-size:.82rem;font-weight:700;color:var(--rescue-text);margin-bottom:.85rem;"><i class="bi bi-bar-chart-fill" style="color:var(--rescue-red);"></i> By Type</div>
      <?php
      $ts=[['label'=>'Flood','count'=>(int)($alertStats['flood']??0),'color'=>'#2980B9'],['label'=>'Fire','count'=>(int)($alertStats['fire']??0),'color'=>'#922B21'],['label'=>'Landslide','count'=>(int)($alertStats['landslide']??0),'color'=>'#784212'],['label'=>'General','count'=>(int)($alertStats['general']??0),'color'=>'#1E8449']];
      $mx=max(array_column($ts,'count')); $mx=$mx>0?$mx:1;
      foreach($ts as $t): $pct=round($t['count']/$mx*100); ?>
      <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:3px;"><span style="color:var(--rescue-muted);"><?=$t['label']?></span><span style="font-weight:700;"><?=$t['count']?></span></div>
        <div style="height:5px;background:#F2F3F4;border-radius:10px;overflow:hidden;"><div style="height:100%;width:<?=$pct?>%;background:<?=$t['color']?>;border-radius:10px;"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="tip-card" style="background:#FFFDE7;border:1px solid #F9E79F;color:#7D6608;">
      <h4 style="color:#6E2F1A;"><i class="bi bi-lightbulb-fill"></i> Alert Guidelines</h4>
      <strong>When to send:</strong><ul style="margin:4px 0 8px;padding-left:1.2rem;"><li>Imminent disasters</li><li>Evacuation orders</li><li>Road closures</li></ul>
      <strong>Best practices:</strong><ul style="margin:4px 0 0;padding-left:1.2rem;"><li>Be clear and concise</li><li>Include specific actions</li><li>Add contact info</li></ul>
    </div>
  </div>
</div>
</div><!-- /panel-alerts -->


<!-- ══════════════ ANNOUNCEMENTS PANEL ══════════════ -->
<div class="tab-panel <?=$activeTab==='announcements'?'active':''?>" id="panel-announcements">
<div class="al-grid">

  <!-- Left: list -->
  <div>
    <div class="filter-bar">
      <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;width:100%;">
        <input type="hidden" name="tab" value="announcements">
        <div style="position:relative;flex:1;min-width:170px;">
          <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--rescue-muted);font-size:.85rem;"></i>
          <input type="text" name="search_ann" value="<?=htmlspecialchars($searchN)?>" placeholder="Search announcements…"
            style="width:100%;padding:.55rem .75rem .55rem 2rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;"
            onfocus="this.style.borderColor='var(--rescue-red)'" onblur="this.style.borderColor='var(--rescue-border)'">
        </div>
        <select name="category" style="padding:.55rem .75rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;color:var(--rescue-text);cursor:pointer;">
          <option value="all" <?=$filterCat==='all'?'selected':''?>>All Categories</option>
          <option value="general" <?=$filterCat==='general'?'selected':''?>>General</option>
          <option value="health" <?=$filterCat==='health'?'selected':''?>>Health</option>
          <option value="relief" <?=$filterCat==='relief'?'selected':''?>>Relief Goods</option>
          <option value="infrastructure" <?=$filterCat==='infrastructure'?'selected':''?>>Infrastructure</option>
          <option value="weather" <?=$filterCat==='weather'?'selected':''?>>Weather</option>
        </select>
        <select name="ann_status" style="padding:.55rem .75rem;border:1.5px solid var(--rescue-border);border-radius:9px;font-size:.85rem;font-family:inherit;background:#FAFAFA;color:var(--rescue-text);cursor:pointer;">
          <option value="all" <?=$filterAnnSt==='all'?'selected':''?>>All Status</option>
          <option value="active" <?=$filterAnnSt==='active'?'selected':''?>>Active</option>
          <option value="inactive" <?=$filterAnnSt==='inactive'?'selected':''?>>Inactive</option>
        </select>
        <button type="submit" class="btn-rescue" style="padding:.55rem 1.1rem;"><i class="bi bi-funnel-fill"></i> Filter</button>
        <?php if($searchN||$filterCat!=='all'||$filterAnnSt!=='all'): ?><a href="?tab=announcements" style="font-size:.82rem;color:var(--rescue-muted);text-decoration:none;font-weight:500;">Clear</a><?php endif; ?>
      </form>
    </div>

    <div style="font-size:.88rem;font-weight:700;color:var(--rescue-text);margin-bottom:.75rem;">
      Announcements <span style="font-size:.8rem;font-weight:500;color:var(--rescue-muted);margin-left:6px;">(<?=count($announcements)?> result<?=count($announcements)!==1?'s':''?>)</span>
    </div>

    <?php if(empty($announcements)): ?>
    <div class="rescue-card"><div class="empty-state"><i class="bi bi-megaphone"></i><p>No announcements found.</p><p style="font-size:.8rem;margin-top:4px;"><?=($searchN||$filterCat!=='all'||$filterAnnSt!=='all')?'Try adjusting your filters.':'Use the form to post your first announcement.'?></p></div></div>
    <?php else: foreach($announcements as $n):
      $nd=htmlspecialchars(json_encode(['announcement_id'=>(int)$n['announcement_id'],'title'=>$n['title'],'content'=>$n['content'],'category'=>$n['category'],'is_pinned'=>(int)$n['is_pinned'],'is_active'=>(int)$n['is_active']]),ENT_QUOTES,'UTF-8');
    ?>
    <div class="item-card <?=!$n['is_active']?'inactive':''?> <?=$n['is_pinned']?'pinned':''?>">
      <div class="card-hdr">
        <div class="type-dot <?=$catDot[$n['category']]??'dot-gen'?>"></div>
        <div class="card-title">
          <?php if($n['is_pinned']): ?><i class="bi bi-pin-angle-fill" style="color:#F39C12;font-size:.8rem;margin-right:4px;"></i><?php endif; ?>
          <?=htmlspecialchars($n['title'])?>
        </div>
        <span class="badge <?=$n['is_active']?'badge-on':'badge-off'?>"><?=$n['is_active']?'Active':'Inactive'?></span>
      </div>
      <div class="card-body"><?=nl2br(htmlspecialchars($n['content']))?></div>
      <div class="card-meta">
        <span class="badge <?=$catBadge[$n['category']]??'badge-gen'?>"><?=strtoupper($catLabel[$n['category']]??$n['category'])?></span>
        <?php if($n['is_pinned']): ?><span class="badge badge-pin"><i class="bi bi-pin-angle-fill"></i> Pinned</span><?php endif; ?>
        <span><i class="bi bi-clock" style="font-size:.72rem;"></i> <?=date('M j, Y g:i A',strtotime($n['created_at']))?></span>
        <span><i class="bi bi-person" style="font-size:.72rem;"></i> <?=htmlspecialchars($n['first_name'].' '.$n['last_name'])?></span>
      </div>
      <div class="card-acts">
        <button class="action-btn blue"    onclick="openAnnView(JSON.parse(this.dataset.a))"   data-a="<?=$nd?>"><i class="bi bi-eye"></i> View</button>
        <button class="action-btn"         onclick="openAnnEdit(JSON.parse(this.dataset.a))"   data-a="<?=$nd?>"><i class="bi bi-pencil"></i> Edit</button>
        <?php if($n['is_pinned']): ?>
        <button class="action-btn amber"   onclick="openPinToggle(JSON.parse(this.dataset.a))" data-a="<?=$nd?>"><i class="bi bi-pin-angle"></i> Unpin</button>
        <?php else: ?>
        <button class="action-btn amber"   onclick="openPinToggle(JSON.parse(this.dataset.a))" data-a="<?=$nd?>"><i class="bi bi-pin-angle-fill"></i> Pin</button>
        <?php endif; ?>
        <?php if($n['is_active']): ?>
        <button class="action-btn warning" onclick="openAnnToggle(JSON.parse(this.dataset.a))" data-a="<?=$nd?>"><i class="bi bi-pause-circle"></i> Deactivate</button>
        <?php else: ?>
        <button class="action-btn success" onclick="openAnnToggle(JSON.parse(this.dataset.a))" data-a="<?=$nd?>"><i class="bi bi-play-circle"></i> Activate</button>
        <?php endif; ?>
        <button class="action-btn danger"  onclick="openAnnDelete(JSON.parse(this.dataset.a))" data-a="<?=$nd?>"><i class="bi bi-trash"></i> Delete</button>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Right: compose (no sticky) -->
  <div class="right-panel">
    <div class="compose-card">
      <div class="compose-hdr green"><h2><i class="bi bi-megaphone-fill"></i> Post Announcement</h2><p>Visible to all <?=$recipientCount?> resident<?=$recipientCount!==1?'s':''?></p></div>
      <div class="compose-body">
        <form method="POST">
          <input type="hidden" name="action" value="create_announcement">
          <div style="margin-bottom:.9rem;"><label class="form-label">Title *</label>
            <input type="text" name="title" id="n-title" class="form-control" required maxlength="150" placeholder="e.g., Relief Goods Distribution" oninput="previewAnn()"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:.9rem;">
            <div><label class="form-label">Category *</label>
              <select name="category" id="n-category" class="form-control" onchange="previewAnn()">
                <option value="general">General</option><option value="health">Health</option><option value="relief">Relief Goods</option><option value="infrastructure">Infrastructure</option><option value="weather">Weather</option>
              </select></div>
            <div style="display:flex;flex-direction:column;justify-content:flex-end;">
              <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;font-weight:600;color:var(--rescue-text);cursor:pointer;padding-bottom:.3rem;">
                <input type="checkbox" name="is_pinned" id="n-pinned" style="width:16px;height:16px;" onchange="previewAnn()">
                <i class="bi bi-pin-angle-fill" style="color:#F39C12;"></i> Pin to top
              </label></div>
          </div>
          <div style="margin-bottom:.9rem;"><label class="form-label">Content *</label>
            <textarea name="content" id="n-content" class="form-control" rows="4" required maxlength="2000"
              placeholder="Provide clear details — include location, date/time if applicable…"
              oninput="previewAnn();ucc('n-content','n-chars')" style="resize:vertical;"></textarea>
            <div style="text-align:right;font-size:.72rem;color:var(--rescue-muted);margin-top:2px;"><span id="n-chars">0</span>/2000</div></div>
          <div class="preview-block">
            <div class="preview-lbl"><i class="bi bi-eye"></i> Preview</div>
            <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:.35rem;">
              <div id="np-dot" class="type-dot dot-gen" style="margin-top:4px;"></div>
              <div id="np-title" style="font-size:.88rem;font-weight:700;color:var(--rescue-text);flex:1;">Announcement Title</div>
            </div>
            <div id="np-content" style="font-size:.78rem;color:var(--rescue-muted);margin-left:18px;line-height:1.5;">Content…</div>
            <div style="margin-top:.45rem;margin-left:18px;display:flex;gap:6px;align-items:center;">
              <span id="np-cat" class="badge badge-gen">GENERAL</span>
              <span id="np-pin" class="badge badge-pin" style="display:none;"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
            </div>
          </div>
          <button type="submit" class="btn-rescue" style="width:100%;background:#1E8449;" onclick="return confirm('Publish this announcement?')">
            <i class="bi bi-megaphone-fill"></i> Publish Announcement
          </button>
        </form>
      </div>
    </div>

    <!-- Ann summary -->
    <div class="rescue-card" style="padding:1rem 1.25rem;">
      <div style="font-size:.82rem;font-weight:700;color:var(--rescue-text);margin-bottom:.75rem;"><i class="bi bi-megaphone-fill" style="color:#1E8449;"></i> Summary</div>
      <?php foreach([['Total Published',(int)($annStats['total']??0),'var(--rescue-text)'],['Active',(int)($annStats['active']??0),'#1E8449'],['Pinned',(int)($annStats['pinned']??0),'#F39C12']] as [$lbl,$val,$col]): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:.83rem;padding:.35rem 0;border-bottom:1px solid #F5F5F5;">
        <span style="color:var(--rescue-muted);"><?=$lbl?></span><span style="font-weight:700;color:<?=$col?>;"><?=$val?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="tip-card" style="background:#F0FFF4;border:1px solid #A9DFBF;color:#1E5631;">
      <h4 style="color:#1E5631;"><i class="bi bi-lightbulb-fill"></i> Announcement Tips</h4>
      <strong>Good for:</strong><ul style="margin:4px 0 8px;padding-left:1.2rem;"><li>Relief goods schedules</li><li>Medical team deployments</li><li>Potable water availability</li><li>Community meetings</li></ul>
      <strong>Tips:</strong><ul style="margin:4px 0 0;padding-left:1.2rem;"><li>Include date, time &amp; location</li><li>Pin urgent announcements</li><li>Deactivate outdated posts</li></ul>
    </div>
  </div>
</div>
</div><!-- /panel-announcements -->


<!-- ═══════════ ALERT MODALS ═══════════ -->
<!-- View -->
<div class="rescue-modal-backdrop" id="alertViewModal">
  <div class="rescue-modal" style="max-width:480px;">
    <div class="modal-hdr"><h5><i class="bi bi-bell-fill" style="color:var(--rescue-red);"></i> Alert Details</h5>
      <button onclick="closeModal('alertViewModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;"><span id="av-type" class="badge"></span><span id="av-sev" class="badge"></span><span id="av-status"></span></div>
      <div style="margin-bottom:1rem;"><div style="font-size:.75rem;font-weight:700;color:var(--rescue-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Title</div><div id="av-title" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div></div>
      <div><div style="font-size:.75rem;font-weight:700;color:var(--rescue-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Message</div><div id="av-message" style="font-size:.875rem;color:var(--rescue-text);line-height:1.6;background:#FAFAFA;border:1px solid var(--rescue-border);border-radius:8px;padding:.75rem 1rem;"></div></div>
    </div>
    <div class="modal-ftr" style="justify-content:space-between;">
      <button class="action-btn danger" onclick="switchAlertViewToDelete()"><i class="bi bi-trash"></i> Delete</button>
      <div style="display:flex;gap:8px;"><button class="btn-cancel" onclick="closeModal('alertViewModal')">Close</button><button class="btn-submit" style="background:#2980B9;" onclick="switchAlertViewToEdit()"><i class="bi bi-pencil"></i> Edit</button></div>
    </div>
  </div>
</div>
<!-- Edit -->
<div class="rescue-modal-backdrop" id="alertEditModal">
  <div class="rescue-modal">
    <div class="modal-hdr"><h5><i class="bi bi-pencil-fill" style="color:#2980B9;"></i> Edit Alert</h5><button onclick="closeModal('alertEditModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <form method="POST"><input type="hidden" name="action" value="edit_alert"><input type="hidden" name="alert_id" id="ae-id">
      <div class="modal-body">
        <div style="margin-bottom:1rem;"><label class="form-label">Title *</label><input type="text" name="title" id="ae-title" class="form-control" required maxlength="150"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem;">
          <div><label class="form-label">Type *</label><select name="type" id="ae-type" class="form-control"><option value="general">General</option><option value="flood">Flood</option><option value="fire">Fire</option><option value="landslide">Landslide</option></select></div>
          <div><label class="form-label">Severity *</label><select name="severity" id="ae-severity" class="form-control"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
        </div>
        <div><label class="form-label">Message *</label><textarea name="message" id="ae-message" class="form-control" rows="4" required maxlength="1000" style="resize:vertical;"></textarea></div>
      </div>
      <div class="modal-ftr"><button type="button" class="btn-cancel" onclick="closeModal('alertEditModal')">Cancel</button><button type="submit" class="btn-submit" onclick="return confirm('Save changes?')"><i class="bi bi-check-lg"></i> Save</button></div>
    </form>
  </div>
</div>
<!-- Toggle -->
<div class="rescue-modal-backdrop" id="alertToggleModal">
  <div class="rescue-modal" style="max-width:400px;">
    <div class="modal-hdr"><h5 id="at-title"></h5><button onclick="closeModal('alertToggleModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" style="text-align:center;"><div id="at-icon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;"></div><p style="font-size:.9rem;font-weight:600;color:var(--rescue-text);margin-bottom:.4rem;" id="at-msg"></p><p style="font-size:.82rem;color:var(--rescue-muted);margin:0;" id="at-exp"></p></div>
    <form method="POST"><input type="hidden" name="action" value="toggle_alert"><input type="hidden" name="alert_id" id="at-id"><input type="hidden" name="new_status" id="at-status">
      <div class="modal-ftr"><button type="button" class="btn-cancel" onclick="closeModal('alertToggleModal')">Cancel</button><button type="submit" class="btn-submit" id="at-btn"></button></div>
    </form>
  </div>
</div>
<!-- Resend -->
<div class="rescue-modal-backdrop" id="alertResendModal">
  <div class="rescue-modal" style="max-width:390px;">
    <div class="modal-body" style="text-align:center;padding:2rem 1.5rem;">
      <div style="width:56px;height:56px;background:#EBF5FB;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:#2980B9;"><i class="bi bi-send-fill"></i></div>
      <h5 style="font-size:1rem;font-weight:700;margin-bottom:.5rem;">Resend Alert</h5>
      <p style="font-size:.85rem;color:var(--rescue-muted);margin:0;">Resend <strong id="ar-title" style="color:var(--rescue-text);"></strong> to all <strong><?=$recipientCount?></strong> resident(s)? This re-activates the alert and updates its timestamp.</p>
    </div>
    <form method="POST"><input type="hidden" name="action" value="resend_alert"><input type="hidden" name="alert_id" id="ar-id">
      <div class="modal-ftr" style="justify-content:center;"><button type="button" class="btn-cancel" onclick="closeModal('alertResendModal')">Cancel</button><button type="submit" class="btn-submit" style="background:#2980B9;"><i class="bi bi-send-fill"></i> Resend</button></div>
    </form>
  </div>
</div>
<!-- Delete Alert -->
<div class="rescue-modal-backdrop" id="alertDeleteModal">
  <div class="rescue-modal" style="max-width:370px;">
    <div class="modal-body" style="text-align:center;padding:2rem 1.5rem;">
      <div style="width:52px;height:52px;background:var(--rescue-red-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;"><i class="bi bi-trash-fill" style="font-size:1.3rem;color:var(--rescue-red);"></i></div>
      <h5 style="font-size:1rem;font-weight:700;margin-bottom:.5rem;">Delete Alert</h5>
      <p style="font-size:.85rem;color:var(--rescue-muted);margin:0;">Permanently delete <strong id="adel-title" style="color:var(--rescue-text);"></strong>? This cannot be undone.</p>
    </div>
    <form method="POST"><input type="hidden" name="action" value="delete_alert"><input type="hidden" name="alert_id" id="adel-id">
      <div class="modal-ftr" style="justify-content:center;"><button type="button" class="btn-cancel" onclick="closeModal('alertDeleteModal')">Cancel</button><button type="submit" class="btn-submit"><i class="bi bi-trash-fill"></i> Delete</button></div>
    </form>
  </div>
</div>


<!-- ═══════════ ANNOUNCEMENT MODALS ═══════════ -->
<!-- View -->
<div class="rescue-modal-backdrop" id="annViewModal">
  <div class="rescue-modal" style="max-width:500px;">
    <div class="modal-hdr"><h5><i class="bi bi-megaphone-fill" style="color:#1E8449;"></i> Announcement</h5><button onclick="closeModal('annViewModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;"><span id="anv-cat" class="badge"></span><span id="anv-pin" class="badge badge-pin" style="display:none;"><i class="bi bi-pin-angle-fill"></i> Pinned</span><span id="anv-status"></span></div>
      <div style="margin-bottom:1rem;"><div style="font-size:.75rem;font-weight:700;color:var(--rescue-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Title</div><div id="anv-title" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div></div>
      <div><div style="font-size:.75rem;font-weight:700;color:var(--rescue-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Content</div><div id="anv-content" style="font-size:.875rem;color:var(--rescue-text);line-height:1.6;background:#FAFAFA;border:1px solid var(--rescue-border);border-radius:8px;padding:.75rem 1rem;white-space:pre-wrap;"></div></div>
    </div>
    <div class="modal-ftr" style="justify-content:space-between;">
      <button class="action-btn danger" onclick="switchAnnViewToDelete()"><i class="bi bi-trash"></i> Delete</button>
      <div style="display:flex;gap:8px;"><button class="btn-cancel" onclick="closeModal('annViewModal')">Close</button><button class="btn-submit" style="background:#2980B9;" onclick="switchAnnViewToEdit()"><i class="bi bi-pencil"></i> Edit</button></div>
    </div>
  </div>
</div>
<!-- Edit -->
<div class="rescue-modal-backdrop" id="annEditModal">
  <div class="rescue-modal">
    <div class="modal-hdr"><h5><i class="bi bi-pencil-fill" style="color:#2980B9;"></i> Edit Announcement</h5><button onclick="closeModal('annEditModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <form method="POST"><input type="hidden" name="action" value="edit_announcement"><input type="hidden" name="announcement_id" id="ane-id">
      <div class="modal-body">
        <div style="margin-bottom:1rem;"><label class="form-label">Title *</label><input type="text" name="title" id="ane-title" class="form-control" required maxlength="150"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem;">
          <div><label class="form-label">Category *</label><select name="category" id="ane-category" class="form-control"><option value="general">General</option><option value="health">Health</option><option value="relief">Relief Goods</option><option value="infrastructure">Infrastructure</option><option value="weather">Weather</option></select></div>
          <div style="display:flex;flex-direction:column;justify-content:flex-end;"><label style="display:flex;align-items:center;gap:8px;font-size:.85rem;font-weight:600;color:var(--rescue-text);cursor:pointer;padding-bottom:.3rem;"><input type="checkbox" name="is_pinned" id="ane-pinned" style="width:16px;height:16px;"><i class="bi bi-pin-angle-fill" style="color:#F39C12;"></i> Pin to top</label></div>
        </div>
        <div><label class="form-label">Content *</label><textarea name="content" id="ane-content" class="form-control" rows="5" required maxlength="2000" style="resize:vertical;"></textarea></div>
      </div>
      <div class="modal-ftr"><button type="button" class="btn-cancel" onclick="closeModal('annEditModal')">Cancel</button><button type="submit" class="btn-submit" style="background:#1E8449;" onclick="return confirm('Save changes?')"><i class="bi bi-check-lg"></i> Save</button></div>
    </form>
  </div>
</div>
<!-- Toggle Ann -->
<div class="rescue-modal-backdrop" id="annToggleModal">
  <div class="rescue-modal" style="max-width:400px;">
    <div class="modal-hdr"><h5 id="ant-title"></h5><button onclick="closeModal('annToggleModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" style="text-align:center;"><div id="ant-icon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;"></div><p style="font-size:.9rem;font-weight:600;color:var(--rescue-text);margin-bottom:.4rem;" id="ant-msg"></p><p style="font-size:.82rem;color:var(--rescue-muted);margin:0;" id="ant-exp"></p></div>
    <form method="POST"><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="announcement_id" id="ant-id"><input type="hidden" name="new_status" id="ant-status">
      <div class="modal-ftr"><button type="button" class="btn-cancel" onclick="closeModal('annToggleModal')">Cancel</button><button type="submit" class="btn-submit" id="ant-btn"></button></div>
    </form>
  </div>
</div>
<!-- Pin toggle -->
<div class="rescue-modal-backdrop" id="pinModal">
  <div class="rescue-modal" style="max-width:380px;">
    <div class="modal-body" style="text-align:center;padding:2rem 1.5rem;">
      <div id="pin-icon" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;background:#FEF9E7;color:#B7770D;"><i class="bi bi-pin-angle-fill"></i></div>
      <h5 id="pin-heading" style="font-size:1rem;font-weight:700;margin-bottom:.5rem;"></h5>
      <p id="pin-msg" style="font-size:.85rem;color:var(--rescue-muted);margin:0;"></p>
    </div>
    <form method="POST"><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="announcement_id" id="pin-id"><input type="hidden" name="new_pin" id="pin-val">
      <div class="modal-ftr" style="justify-content:center;"><button type="button" class="btn-cancel" onclick="closeModal('pinModal')">Cancel</button><button type="submit" class="btn-submit" id="pin-btn" style="background:#B7770D;"></button></div>
    </form>
  </div>
</div>
<!-- Delete Ann -->
<div class="rescue-modal-backdrop" id="annDeleteModal">
  <div class="rescue-modal" style="max-width:370px;">
    <div class="modal-body" style="text-align:center;padding:2rem 1.5rem;">
      <div style="width:52px;height:52px;background:var(--rescue-red-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;"><i class="bi bi-trash-fill" style="font-size:1.3rem;color:var(--rescue-red);"></i></div>
      <h5 style="font-size:1rem;font-weight:700;margin-bottom:.5rem;">Delete Announcement</h5>
      <p style="font-size:.85rem;color:var(--rescue-muted);margin:0;">Permanently delete <strong id="andel-title" style="color:var(--rescue-text);"></strong>? This cannot be undone.</p>
    </div>
    <form method="POST"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" id="andel-id">
      <div class="modal-ftr" style="justify-content:center;"><button type="button" class="btn-cancel" onclick="closeModal('annDeleteModal')">Cancel</button><button type="submit" class="btn-submit"><i class="bi bi-trash-fill"></i> Delete</button></div>
    </form>
  </div>
</div>


<script>
// ── Tabs ──────────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b => {
        if ((tab === 'alerts' && b.textContent.trim().toLowerCase().startsWith('alert')) ||
            (tab === 'announcements' && b.textContent.trim().toLowerCase().startsWith('ann')))
            b.classList.add('active');
    });
}

// ── Modals ────────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.rescue-modal-backdrop').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.rescue-modal-backdrop.open').forEach(m => m.classList.remove('open'));
});

// ── Maps ──────────────────────────────────────────────────────────────────
const TB = {flood:'badge-flood',fire:'badge-fire',landslide:'badge-land',general:'badge-gen'};
const TD = {flood:'dot-flood',  fire:'dot-fire',  landslide:'dot-land',  general:'dot-gen'};
const SB = {high:'sev-high',medium:'sev-medium',low:'sev-low'};
const CB = {general:'badge-gen',health:'badge-health',relief:'badge-relief',infrastructure:'badge-infra',weather:'badge-weather'};
const CD = {general:'dot-gen',  health:'dot-health',  relief:'dot-relief',  infrastructure:'dot-infra',  weather:'dot-weather'};
const CL = {general:'General',health:'Health',relief:'Relief Goods',infrastructure:'Infrastructure',weather:'Weather'};

let curAlert = {}, curAnn = {};

/* ── Alert modals ── */
function openAlertView(a) {
    curAlert = a;
    const tb=document.getElementById('av-type'); tb.textContent=a.type.toUpperCase(); tb.className='badge '+(TB[a.type]||'badge-gen');
    const sb=document.getElementById('av-sev');  sb.textContent=a.severity.toUpperCase(); sb.className='badge '+(SB[a.severity]||'sev-low');
    document.getElementById('av-status').innerHTML = a.is_active ? '<span class="badge badge-on">Active</span>' : '<span class="badge badge-off">Inactive</span>';
    document.getElementById('av-title').textContent   = a.title;
    document.getElementById('av-message').textContent = a.message;
    openModal('alertViewModal');
}
function switchAlertViewToEdit()   { closeModal('alertViewModal'); openAlertEdit(curAlert); }
function switchAlertViewToDelete() { closeModal('alertViewModal'); openAlertDelete(curAlert); }

function openAlertEdit(a) {
    curAlert=a;
    document.getElementById('ae-id').value=a.alert_id; document.getElementById('ae-title').value=a.title;
    document.getElementById('ae-message').value=a.message; document.getElementById('ae-type').value=a.type;
    document.getElementById('ae-severity').value=a.severity;
    openModal('alertEditModal');
}
function openAlertToggle(a) {
    curAlert=a; document.getElementById('at-id').value=a.alert_id;
    const d=a.is_active==1;
    document.getElementById('at-status').value=d?0:1;
    document.getElementById('at-title').textContent=d?'Deactivate Alert':'Activate Alert';
    document.getElementById('at-icon').style.background=d?'#FEF9E7':'#EAFAF1';
    document.getElementById('at-icon').style.color=d?'#B7770D':'#1E8449';
    document.getElementById('at-icon').innerHTML=d?'<i class="bi bi-pause-circle-fill"></i>':'<i class="bi bi-play-circle-fill"></i>';
    document.getElementById('at-msg').innerHTML=(d?'Deactivate':'Activate')+' <strong>'+a.title+'</strong>?';
    document.getElementById('at-exp').textContent=d?'Residents will no longer see this as active.':'This alert will become visible again.';
    const btn=document.getElementById('at-btn'); btn.textContent=d?'Yes, Deactivate':'Yes, Activate'; btn.style.background=d?'#B7770D':'#1E8449';
    openModal('alertToggleModal');
}
function openAlertResend(a) {
    curAlert=a; document.getElementById('ar-id').value=a.alert_id;
    document.getElementById('ar-title').textContent='"'+a.title+'"';
    openModal('alertResendModal');
}
function openAlertDelete(a) {
    curAlert=a; document.getElementById('adel-id').value=a.alert_id;
    document.getElementById('adel-title').textContent='"'+a.title+'"';
    openModal('alertDeleteModal');
}

/* ── Announcement modals ── */
function openAnnView(n) {
    curAnn=n;
    const cb=document.getElementById('anv-cat'); cb.textContent=(CL[n.category]||n.category).toUpperCase(); cb.className='badge '+(CB[n.category]||'badge-gen');
    document.getElementById('anv-pin').style.display=n.is_pinned?'inline-flex':'none';
    document.getElementById('anv-status').innerHTML=n.is_active?'<span class="badge badge-on">Active</span>':'<span class="badge badge-off">Inactive</span>';
    document.getElementById('anv-title').textContent=n.title;
    document.getElementById('anv-content').textContent=n.content;
    openModal('annViewModal');
}
function switchAnnViewToEdit()   { closeModal('annViewModal'); openAnnEdit(curAnn); }
function switchAnnViewToDelete() { closeModal('annViewModal'); openAnnDelete(curAnn); }

function openAnnEdit(n) {
    curAnn=n;
    document.getElementById('ane-id').value=n.announcement_id; document.getElementById('ane-title').value=n.title;
    document.getElementById('ane-content').value=n.content; document.getElementById('ane-category').value=n.category;
    document.getElementById('ane-pinned').checked=n.is_pinned==1;
    openModal('annEditModal');
}
function openAnnToggle(n) {
    curAnn=n; document.getElementById('ant-id').value=n.announcement_id;
    const d=n.is_active==1;
    document.getElementById('ant-status').value=d?0:1;
    document.getElementById('ant-title').textContent=d?'Deactivate Announcement':'Activate Announcement';
    document.getElementById('ant-icon').style.background=d?'#FEF9E7':'#EAFAF1';
    document.getElementById('ant-icon').style.color=d?'#B7770D':'#1E8449';
    document.getElementById('ant-icon').innerHTML=d?'<i class="bi bi-pause-circle-fill"></i>':'<i class="bi bi-play-circle-fill"></i>';
    document.getElementById('ant-msg').innerHTML=(d?'Deactivate':'Activate')+' <strong>'+n.title+'</strong>?';
    document.getElementById('ant-exp').textContent=d?'Residents will no longer see this announcement.':'This announcement will become visible again.';
    const btn=document.getElementById('ant-btn'); btn.textContent=d?'Yes, Deactivate':'Yes, Activate'; btn.style.background=d?'#B7770D':'#1E8449';
    openModal('annToggleModal');
}
function openPinToggle(n) {
    curAnn=n; document.getElementById('pin-id').value=n.announcement_id;
    const pinning=n.is_pinned==0;
    document.getElementById('pin-val').value=pinning?1:0;
    document.getElementById('pin-heading').textContent=pinning?'Pin Announcement':'Unpin Announcement';
    document.getElementById('pin-msg').innerHTML=pinning
        ?'Pin <strong>'+n.title+'</strong> to the top of the announcements list?'
        :'Unpin <strong>'+n.title+'</strong>? It will return to its regular position.';
    document.getElementById('pin-btn').textContent=pinning?'📌 Pin':'Unpin';
    openModal('pinModal');
}
function openAnnDelete(n) {
    curAnn=n; document.getElementById('andel-id').value=n.announcement_id;
    document.getElementById('andel-title').textContent='"'+n.title+'"';
    openModal('annDeleteModal');
}

/* ── Live previews ── */
function previewAlert() {
    const t=document.getElementById('a-title').value||'Alert Title';
    const m=document.getElementById('a-message').value||'Message…';
    const ty=document.getElementById('a-type').value||'general';
    const sv=document.getElementById('a-severity').value||'low';
    document.getElementById('ap-title').textContent=t;
    document.getElementById('ap-msg').textContent=m;
    document.getElementById('ap-dot').className='type-dot '+(TD[ty]||'dot-gen');
    const tb=document.getElementById('ap-type'); tb.textContent=ty.toUpperCase(); tb.className='badge '+(TB[ty]||'badge-gen');
    const sb=document.getElementById('ap-sev');  sb.textContent=sv.toUpperCase(); sb.className='badge '+(SB[sv]||'sev-low');
}
function previewAnn() {
    const t=document.getElementById('n-title').value||'Announcement Title';
    const c=document.getElementById('n-content').value||'Content…';
    const cat=document.getElementById('n-category').value||'general';
    const pin=document.getElementById('n-pinned').checked;
    document.getElementById('np-title').textContent=t;
    document.getElementById('np-content').textContent=c;
    document.getElementById('np-dot').className='type-dot '+(CD[cat]||'dot-gen');
    const cb=document.getElementById('np-cat'); cb.textContent=(CL[cat]||cat).toUpperCase(); cb.className='badge '+(CB[cat]||'badge-gen');
    document.getElementById('np-pin').style.display=pin?'inline-flex':'none';
}
function ucc(tid,cid) {
    const len=document.getElementById(tid).value.length;
    const el=document.getElementById(cid); el.textContent=len;
    el.style.color=len>(tid==='n-content'?1800:900)?'var(--rescue-red)':'var(--rescue-muted)';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>