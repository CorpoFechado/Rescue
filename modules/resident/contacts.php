<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('resident');

$pageTitle  = 'Emergency Contacts';
$activePage = 'contacts';

// ── Emergency contacts from DB ────────────────────────────────────────────
$stmtContacts = $conn->query("
    SELECT contact_id, name, role, phone
    FROM emergency_contacts
    WHERE is_active = 1
    ORDER BY name ASC
");
$contacts = $stmtContacts->fetchAll();

// ── Group contacts by role category ──────────────────────────────────────
// We'll display them grouped so it's easier to scan on mobile
$grouped = [];
foreach ($contacts as $c) {
    $grouped[] = $c; // flat list; grouping done in template
}

require_once __DIR__ . '/../../includes/header_resident.php';
?>

<style>
    /* ── 911 banner ── */
    .sos-banner {
        background: linear-gradient(135deg, #B03428 0%, #7B241C 100%);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
        overflow: hidden;
    }

    .sos-banner::before {
        content: '';
        position: absolute;
        width: 160px; height: 160px;
        border-radius: 50%;
        background: rgba(255,255,255,0.06);
        top: -60px; right: -40px;
    }

    .sos-icon {
        width: 52px; height: 52px;
        background: rgba(255,255,255,0.15);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem; color: #fff;
        flex-shrink: 0;
    }

    .sos-text { flex: 1; min-width: 0; }
    .sos-text h3 { font-size: 1rem; font-weight: 700; color: #fff; margin: 0 0 3px; }
    .sos-text p  { font-size: .78rem; color: rgba(255,255,255,.82); margin: 0; line-height: 1.4; }

    .sos-call-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff;
        color: #B03428;
        font-size: .875rem;
        font-weight: 700;
        padding: .6rem 1.1rem;
        border-radius: 50px;
        text-decoration: none;
        white-space: nowrap;
        flex-shrink: 0;
        transition: opacity .15s;
    }
    .sos-call-btn:hover { opacity: .9; color: #922B21; }

    /* ── Section label ── */
    .contacts-section-label {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: var(--rescue-muted);
        margin: 1.1rem 0 .5rem;
        padding-left: 2px;
    }

    /* ── Contact card ── */
    .contact-card {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: .75rem;
    }

    .contact-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: .95rem 1.1rem;
        border-bottom: 1px solid #F0F0F0;
        transition: background .15s;
    }
    .contact-item:last-child { border-bottom: none; }
    .contact-item:active     { background: #FAFAFA; }

    .contact-avatar {
        width: 42px; height: 42px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem;
        flex-shrink: 0;
    }

    .contact-info { flex: 1; min-width: 0; }
    .contact-name { font-size: .875rem; font-weight: 700; color: var(--rescue-text); margin: 0 0 2px; }
    .contact-role { font-size: .75rem; color: var(--rescue-muted); margin: 0; }

    .contact-actions {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
    }

    .call-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #EAFAF1;
        color: #1E8449;
        font-size: .78rem;
        font-weight: 700;
        padding: .45rem .85rem;
        border-radius: 50px;
        text-decoration: none;
        white-space: nowrap;
        border: none;
        cursor: pointer;
        font-family: inherit;
        transition: background .15s;
    }
    .call-btn:hover { background: #D5F5E3; color: #1E8449; }

    .info-btn {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: #F5F5F5;
        border: none;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        color: var(--rescue-muted);
        font-size: .95rem;
        transition: background .15s;
        flex-shrink: 0;
    }
    .info-btn:hover { background: #EBEBEB; }

    /* ── When to call ── */
    .when-card {
        background: #FFFDE7;
        border: 1px solid #F9E79F;
        border-radius: 14px;
        padding: 1rem 1.1rem;
        margin-bottom: .75rem;
    }
    .when-card h4 { font-size: .85rem; font-weight: 700; color: #7D6608; margin: 0 0 .6rem; display: flex; align-items: center; gap: 6px; }
    .when-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; }
    .when-list li { font-size: .8rem; color: #7D6608; display: flex; align-items: flex-start; gap: 7px; line-height: 1.4; }
    .when-list li i { flex-shrink: 0; margin-top: 1px; }

    /* ── Reminders ── */
    .reminder-card {
        background: #EBF5FB;
        border: 1px solid #AED6F1;
        border-radius: 14px;
        padding: 1rem 1.1rem;
        margin-bottom: .75rem;
    }
    .reminder-card h4 { font-size: .85rem; font-weight: 700; color: #1A5276; margin: 0 0 .6rem; display: flex; align-items: center; gap: 6px; }
    .reminder-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; }
    .reminder-list li { font-size: .8rem; color: #1A5276; display: flex; align-items: flex-start; gap: 7px; line-height: 1.4; }
    .reminder-list li i { flex-shrink: 0; margin-top: 1px; }

    /* ── Empty state ── */
    .empty-contacts {
        text-align: center;
        padding: 2.5rem 1rem;
        color: var(--rescue-muted);
    }
    .empty-contacts i { font-size: 2.5rem; display: block; margin-bottom: .75rem; color: #D5D8DC; }
    .empty-contacts p { font-size: .875rem; margin: 0; }

    /* ── Contact detail modal ── */
    .contact-detail-row {
        display: flex;
        padding: .65rem 0;
        border-bottom: 1px solid var(--rescue-border);
        gap: 8px;
    }
    .contact-detail-row:last-child { border-bottom: none; }
    .contact-detail-label { width: 90px; font-size: .75rem; font-weight: 700; color: var(--rescue-muted); flex-shrink: 0; padding-top: 1px; }
    .contact-detail-value { font-size: .85rem; color: var(--rescue-text); flex: 1; }
</style>

<div class="resident-content">

    <!-- Page header -->
    <div class="page-header">
        <h1>Emergency Contacts</h1>
        <p>Reach help quickly during disasters and emergencies</p>
    </div>

    <!-- ── 911 SOS Banner ── -->
    <div class="sos-banner">
        <div class="sos-icon">
            <i class="bi bi-telephone-fill"></i>
        </div>
        <div class="sos-text">
            <h3>Life-Threatening Emergency?</h3>
            <p>Call 911 immediately for police, fire, or medical emergencies</p>
        </div>
        <a href="tel:911" class="sos-call-btn">
            <i class="bi bi-telephone-fill"></i> 911
        </a>
    </div>

    <!-- ── When to call tips ── -->
    <div class="when-card">
        <h4><i class="bi bi-lightbulb-fill"></i> When to Call Emergency Services</h4>
        <ul class="when-list">
            <li><i class="bi bi-dot" style="color:#F39C12;"></i> Medical emergency — injury, illness, unconscious person</li>
            <li><i class="bi bi-dot" style="color:#F39C12;"></i> Fire or smoke in building or nearby area</li>
            <li><i class="bi bi-dot" style="color:#F39C12;"></i> Crime in progress or immediate danger</li>
            <li><i class="bi bi-dot" style="color:#F39C12;"></i> Natural disaster threat — flood, landslide, typhoon</li>
            <li><i class="bi bi-dot" style="color:#F39C12;"></i> Anyone trapped, missing, or in immediate danger</li>
        </ul>
    </div>

    <!-- ── Contacts list ── -->
    <?php if (empty($contacts)): ?>
    <div class="contact-card">
        <div class="empty-contacts">
            <i class="bi bi-telephone-x"></i>
            <p>No emergency contacts listed yet.</p>
            <p style="font-size:.8rem;margin-top:4px;color:var(--rescue-muted);">
                Contact your barangay officials to have hotlines added.
            </p>
        </div>
    </div>
    <?php else: ?>

    <div class="contacts-section-label">
        <i class="bi bi-telephone-fill" style="color:var(--rescue-red);"></i>
        Barangay Hotlines
        <span style="background:#F2F3F4;color:var(--rescue-muted);font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;"><?= count($contacts) ?></span>
    </div>

    <div class="contact-card">
        <?php
        // icon + colour per role keyword
        function contactStyle(string $role): array {
            $r = strtolower($role);
            if (str_contains($r, 'police') || str_contains($r, 'pnp'))
                return ['icon' => 'bi-shield-fill',        'bg' => '#EBF5FB', 'color' => '#2980B9'];
            if (str_contains($r, 'fire') || str_contains($r, 'bfp'))
                return ['icon' => 'bi-fire',               'bg' => '#FDEDEC', 'color' => '#C0392B'];
            if (str_contains($r, 'medical') || str_contains($r, 'health') || str_contains($r, 'hospital') || str_contains($r, 'ems'))
                return ['icon' => 'bi-heart-pulse-fill',   'bg' => '#FEF9E7', 'color' => '#D68910'];
            if (str_contains($r, 'barangay') || str_contains($r, 'brgy') || str_contains($r, 'hall'))
                return ['icon' => 'bi-house-fill',         'bg' => '#EAFAF1', 'color' => '#1E8449'];
            if (str_contains($r, 'ndrrmc') || str_contains($r, 'disaster') || str_contains($r, 'rescue'))
                return ['icon' => 'bi-life-preserver',     'bg' => '#FEF9E7', 'color' => '#B7770D'];
            if (str_contains($r, 'red cross') || str_contains($r, 'redcross'))
                return ['icon' => 'bi-heart-fill',         'bg' => '#FDEDEC', 'color' => '#C0392B'];
            return     ['icon' => 'bi-telephone-fill',     'bg' => '#F2F3F4', 'color' => '#7F8C8D'];
        }

        foreach ($contacts as $c):
            $style = contactStyle($c['role']);
            $cData = htmlspecialchars(json_encode([
                'name'  => $c['name'],
                'role'  => $c['role'],
                'phone' => $c['phone'],
            ]), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="contact-item">
            <div class="contact-avatar" style="background:<?= $style['bg'] ?>;color:<?= $style['color'] ?>;">
                <i class="bi <?= $style['icon'] ?>"></i>
            </div>
            <div class="contact-info">
                <p class="contact-name"><?= htmlspecialchars($c['name']) ?></p>
                <p class="contact-role"><?= htmlspecialchars($c['role']) ?></p>
            </div>
            <div class="contact-actions">
                <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $c['phone'])) ?>" class="call-btn">
                    <i class="bi bi-telephone-fill"></i>
                    <?= htmlspecialchars($c['phone']) ?>
                </a>
                <button class="info-btn" onclick="openContactDetail(JSON.parse(this.dataset.c))" data-c="<?= $cData ?>"
                    title="View details">
                    <i class="bi bi-info-circle"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- ── Calling reminders ── -->
    <div class="reminder-card">
        <h4><i class="bi bi-info-circle-fill"></i> Calling Tips</h4>
        <ul class="reminder-list">
            <li><i class="bi bi-check2" style="color:#2980B9;"></i> Stay calm when calling — speak clearly and slowly</li>
            <li><i class="bi bi-check2" style="color:#2980B9;"></i> State your exact location first</li>
            <li><i class="bi bi-check2" style="color:#2980B9;"></i> Describe the emergency and number of people affected</li>
            <li><i class="bi bi-check2" style="color:#2980B9;"></i> Follow all instructions from the dispatcher</li>
            <li><i class="bi bi-check2" style="color:#2980B9;"></i> Do not hang up until told to do so</li>
        </ul>
    </div>

    <!-- ── Barangay info ── -->
    <div class="r-card" style="margin-bottom:1rem;">
        <div class="r-card-title">
            <i class="bi bi-building-fill"></i> Barangay Information
        </div>
        <div style="font-size:.83rem;color:var(--rescue-muted);line-height:1.7;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:.4rem;">
                <i class="bi bi-clock-fill" style="color:var(--rescue-red);font-size:.8rem;"></i>
                <span><strong style="color:var(--rescue-text);">Operating Hours:</strong> 24/7 Emergency Response</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:.4rem;">
                <i class="bi bi-envelope-fill" style="color:var(--rescue-red);font-size:.8rem;"></i>
                <span><strong style="color:var(--rescue-text);">Email:</strong> rescue@barangay.gov.ph</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);font-size:.8rem;"></i>
                <span><strong style="color:var(--rescue-text);">Address:</strong> Barangay Hall, Main Street</span>
            </div>
        </div>
    </div>

</div>


<!-- ── Contact Detail Bottom Sheet ── -->
<div class="r-modal-backdrop" id="contactDetailModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5 id="cd-header-name">Contact Details</h5>
            <button onclick="closeModal('contactDetailModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body">
            <!-- Avatar + name -->
            <div style="display:flex;align-items:center;gap:14px;padding-bottom:1rem;border-bottom:1px solid var(--rescue-border);margin-bottom:1rem;">
                <div id="cd-avatar" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;"></div>
                <div>
                    <div id="cd-name" style="font-size:1rem;font-weight:700;color:var(--rescue-text);"></div>
                    <div id="cd-role" style="font-size:.78rem;color:var(--rescue-muted);margin-top:2px;"></div>
                </div>
            </div>
            <!-- Details -->
            <div class="contact-detail-row">
                <span class="contact-detail-label">Phone</span>
                <span class="contact-detail-value" id="cd-phone" style="font-weight:700;color:var(--rescue-red);font-size:.9rem;"></span>
            </div>
            <div class="contact-detail-row">
                <span class="contact-detail-label">Type</span>
                <span class="contact-detail-value" id="cd-type"></span>
            </div>
            <div class="contact-detail-row">
                <span class="contact-detail-label">Available</span>
                <span class="contact-detail-value" style="color:#1E8449;font-weight:600;">24/7 Emergency</span>
            </div>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('contactDetailModal')">Close</button>
            <a id="cd-call-btn" href="#" class="btn-r-submit"
               style="display:flex;align-items:center;justify-content:center;gap:7px;text-decoration:none;flex:2;">
                <i class="bi bi-telephone-fill"></i> Call Now
            </a>
        </div>
    </div>
</div>


<script>
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

// Icon/colour map (mirrors PHP contactStyle)
function contactStyle(role) {
    const r = role.toLowerCase();
    if (r.includes('police') || r.includes('pnp'))
        return { icon: 'bi-shield-fill',      bg: '#EBF5FB', color: '#2980B9' };
    if (r.includes('fire') || r.includes('bfp'))
        return { icon: 'bi-fire',              bg: '#FDEDEC', color: '#C0392B' };
    if (r.includes('medical') || r.includes('health') || r.includes('hospital') || r.includes('ems'))
        return { icon: 'bi-heart-pulse-fill',  bg: '#FEF9E7', color: '#D68910' };
    if (r.includes('barangay') || r.includes('brgy') || r.includes('hall'))
        return { icon: 'bi-house-fill',        bg: '#EAFAF1', color: '#1E8449' };
    if (r.includes('ndrrmc') || r.includes('disaster') || r.includes('rescue'))
        return { icon: 'bi-life-preserver',    bg: '#FEF9E7', color: '#B7770D' };
    if (r.includes('red cross') || r.includes('redcross'))
        return { icon: 'bi-heart-fill',        bg: '#FDEDEC', color: '#C0392B' };
    return { icon: 'bi-telephone-fill', bg: '#F2F3F4', color: '#7F8C8D' };
}

function openContactDetail(c) {
    const s = contactStyle(c.role);
    const av = document.getElementById('cd-avatar');
    av.style.background = s.bg;
    av.style.color      = s.color;
    av.innerHTML        = '<i class="bi ' + s.icon + '"></i>';

    document.getElementById('cd-header-name').textContent = c.name;
    document.getElementById('cd-name').textContent        = c.name;
    document.getElementById('cd-role').textContent        = c.role;
    document.getElementById('cd-phone').textContent       = c.phone;
    document.getElementById('cd-type').textContent        = c.role;

    const cleanPhone = c.phone.replace(/\s+/g, '');
    document.getElementById('cd-call-btn').href = 'tel:' + cleanPhone;

    openModal('contactDetailModal');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_resident.php'; ?>