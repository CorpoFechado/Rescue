<!-- ── Bottom Tab Bar ── -->
<nav class="bottom-tab-bar">

    <a href="<?= BASE_URL ?>/modules/resident/dashboard.php"
       class="tab-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-house-fill"></i>
        Home
    </a>

    <a href="<?= BASE_URL ?>/modules/resident/alerts.php"
       class="tab-item <?= $activePage === 'alerts' ? 'active' : '' ?>">
        <i class="bi bi-bell-fill"></i>
        Alerts
        <?php if ($alertCount > 0): ?>
        <span class="tab-badge"><?= $alertCount > 9 ? '9+' : $alertCount ?></span>
        <?php endif; ?>
    </a>

    <a href="<?= BASE_URL ?>/modules/resident/evacuation_centers.php"
       class="tab-item <?= $activePage === 'map' ? 'active' : '' ?>">
        <i class="bi bi-map-fill"></i>
        Map
    </a>

    <a href="<?= BASE_URL ?>/modules/resident/contacts.php"
       class="tab-item <?= $activePage === 'contacts' ? 'active' : '' ?>">
        <i class="bi bi-telephone-fill"></i>
        Contacts
    </a>

    <a href="<?= BASE_URL ?>/modules/resident/profile.php"
       class="tab-item <?= $activePage === 'profile' ? 'active' : '' ?>">
        <i class="bi bi-person-fill"></i>
        Profile
    </a>

</nav>

<!-- ── LOGOUT MODAL ── -->
<div class="r-modal-backdrop" id="logoutModal" style="display:none;">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-box-arrow-right" style="color:var(--rescue-muted); margin-right:6px;"></i> Sign Out</h5>
            <button onclick="closeResidentModal('logoutModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="r-modal-body" style="text-align:center; padding:1.5rem 1.25rem;">
            <div style="width:52px;height:52px;background:#F2F3F4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:var(--rescue-muted);">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <p style="font-size:0.9rem;font-weight:700;color:var(--rescue-text);margin-bottom:0.5rem;">
                Sign out of RESCUE?
            </p>
            <p style="font-size:0.83rem;color:var(--rescue-muted);line-height:1.6;margin:0;">
                You will need to log in again to report your status or view alerts.
            </p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel"
                onclick="closeResidentModal('logoutModal')">
                Cancel
            </button>
            <button type="button"
                onclick="window.location.href='<?= BASE_URL ?>/modules/auth/logout.php'"
                style="flex:2;padding:0.75rem;border:none;border-radius:12px;background:#5D6D7E;font-size:0.875rem;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;">
                <i class="bi bi-box-arrow-right"></i> Yes, Sign Out
            </button>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>

<script>
function openLogoutModal() {
    const modal = document.getElementById('logoutModal');
    modal.style.display = 'flex';
}

function closeResidentModal(id) {
    document.getElementById(id).style.display = 'none';
}

document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('logoutModal').style.display = 'none';
    }
});
</script>
</body>
</html>