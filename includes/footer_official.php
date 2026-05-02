</div>
        <!-- /.page-content -->

        <!-- Footer -->
        <footer class="rescue-footer">
            <p>&copy; 2026 RESCUE &mdash; Barangay 12 Risk Reduction and Emergency Management System</p>
        </footer>

    </div>
    <!-- /.officials-main -->

</div>
<!-- /.officials-layout -->

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle for mobile
    const sidebarToggle  = document.getElementById('sidebarToggle');
    const officialsSidebar = document.getElementById('officialsSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        officialsSidebar.classList.add('open');
        sidebarOverlay.classList.add('open');
    }

    function closeSidebar() {
        officialsSidebar.classList.remove('open');
        sidebarOverlay.classList.remove('open');
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', openSidebar);
    }

    sidebarOverlay.addEventListener('click', closeSidebar);

    // Show hamburger on mobile
    function checkMobile() {
        if (window.innerWidth < 768) {
            sidebarToggle.style.display = 'block';
        } else {
            sidebarToggle.style.display = 'none';
            closeSidebar();
        }
    }

    checkMobile();
    window.addEventListener('resize', checkMobile);
</script>

<!-- Logout confirmation modal -->
<div id="logoutModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:14px; padding:2rem; max-width:360px; width:90%; text-align:center;">
        <div style="width:52px; height:52px; background:var(--rescue-red-light); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem;">
            <i class="bi bi-box-arrow-left" style="font-size:1.3rem; color:var(--rescue-red);"></i>
        </div>
        <h5 style="font-size:1rem; font-weight:700; color:var(--rescue-text); margin-bottom:0.5rem;">
            Sign Out
        </h5>
        <p style="font-size:0.85rem; color:var(--rescue-muted); margin-bottom:1.5rem;">
            Are you sure you want to sign out of the Officials Portal?
        </p>
        <div style="display:flex; gap:10px;">
            <button onclick="closeLogoutModal()"
                style="flex:1; padding:0.65rem; border:1.5px solid var(--rescue-border); border-radius:10px; background:#fff; font-size:0.875rem; font-weight:600; color:var(--rescue-text); cursor:pointer; font-family:inherit;">
                Cancel
            </button>
            <a href="<?= BASE_URL ?>/modules/auth/logout.php"
                style="flex:1; padding:0.65rem; background:var(--rescue-red); border:none; border-radius:10px; font-size:0.875rem; font-weight:600; color:#fff; cursor:pointer; font-family:inherit; text-decoration:none; display:flex; align-items:center; justify-content:center;">
                Sign Out
            </a>
        </div>
    </div>
</div>

<script>
    function confirmLogout(e) {
        e.preventDefault();
        document.getElementById('logoutModal').style.display = 'flex';
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').style.display = 'none';
    }

    // Close on backdrop click
    document.getElementById('logoutModal').addEventListener('click', function(e) {
        if (e.target === this) closeLogoutModal();
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogoutModal();
    });
</script>

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>