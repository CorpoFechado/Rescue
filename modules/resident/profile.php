<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('resident');

$pageTitle  = 'My Profile';
$activePage = 'profile';

$success = '';
$error   = '';

// ── Get resident_id ───────────────────────────────────────────────────────
$stmtRid = $conn->prepare("SELECT resident_id FROM residents WHERE user_id = ?");
$stmtRid->execute([$_SESSION['user_id']]);
$ridRow     = $stmtRid->fetch();
$residentId = $ridRow ? $ridRow['resident_id'] : null;

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $residentId) {
    $action = $_POST['action'] ?? '';

    // ── Update personal info ──
    if ($action === 'update_personal') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $birthdate = $_POST['birthdate']       ?? '';
        $gender    = $_POST['gender']          ?? '';

        if (empty($firstName) || empty($lastName)) {
            $error = 'First name and last name are required.';
        } else {
            $conn->prepare("
                UPDATE residents
                SET first_name=?, last_name=?, phone=?, birthdate=?, gender=?
                WHERE resident_id=?
            ")->execute([
                $firstName, $lastName,
                $phone    ?: null,
                $birthdate ?: null,
                in_array($gender, ['male','female','other']) ? $gender : null,
                $residentId,
            ]);
            header("Location: " . BASE_URL . "/modules/resident/profile.php?updated=personal");
            exit();
        }
    }

    // ── Update address ──
    if ($action === 'update_address') {
        $houseNo = trim($_POST['house_no'] ?? '');
        $street  = trim($_POST['street']   ?? '');
        $area    = trim($_POST['area']     ?? '');

        $conn->prepare("
            UPDATE residents SET house_no=?, street=?, area=? WHERE resident_id=?
        ")->execute([$houseNo ?: null, $street ?: null, $area ?: null, $residentId]);

        header("Location: " . BASE_URL . "/modules/resident/profile.php?updated=address");
        exit();
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = trim($_POST['new_password']     ?? '');
        $confirmPw = trim($_POST['confirm_password'] ?? '');

        if (empty($currentPw) || empty($newPw) || empty($confirmPw)) {
            $error = 'All password fields are required.';
        } elseif (strlen($newPw) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'New passwords do not match.';
        } else {
            $stmtPw = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmtPw->execute([$_SESSION['user_id']]);
            $userRow = $stmtPw->fetch();

            // Support plain-text passwords (dev) and hashed passwords
            $valid = password_verify($currentPw, $userRow['password'])
                  || $currentPw === $userRow['password'];

            if (!$valid) {
                $error = 'Current password is incorrect.';
            } else {
                $conn->prepare("UPDATE users SET password=? WHERE user_id=?")
                     ->execute([password_hash($newPw, PASSWORD_DEFAULT), $_SESSION['user_id']]);
                header("Location: " . BASE_URL . "/modules/resident/profile.php?updated=password");
                exit();
            }
        }
    }
}

// ── Success messages from redirect ───────────────────────────────────────
if (isset($_GET['updated'])) {
    $success = match($_GET['updated']) {
        'personal' => 'Personal information updated successfully.',
        'address'  => 'Address updated successfully.',
        'password' => 'Password changed successfully.',
        default    => 'Profile updated successfully.',
    };
}

// ── Fetch full resident data ──────────────────────────────────────────────
$stmtR = $conn->prepare("
    SELECT r.first_name, r.last_name, r.phone, r.birthdate, r.gender,
           r.house_no, r.street, r.area,
           u.email, u.created_at AS member_since
    FROM residents r
    JOIN users u ON u.user_id = r.user_id
    WHERE r.user_id = ?
");
$stmtR->execute([$_SESSION['user_id']]);
$resident = $stmtR->fetch();

// ── Derived display values ────────────────────────────────────────────────
$fullName  = trim(($resident['first_name'] ?? '') . ' ' . ($resident['last_name'] ?? ''));
$initials  = strtoupper(substr($resident['first_name'] ?? '?', 0, 1) . substr($resident['last_name'] ?? '', 0, 1));
$address   = trim(implode(', ', array_filter([
    $resident['house_no'] ?? '',
    $resident['street']   ?? '',
    $resident['area']     ?? '',
]))) ?: 'Not set';

$age = null;
if (!empty($resident['birthdate'])) {
    $age = (int) date_diff(date_create($resident['birthdate']), date_create('today'))->y;
}

// ── Latest safety report ──────────────────────────────────────────────────
$stmtStatus = $conn->prepare("
    SELECT status, reported_at FROM safety_reports
    WHERE resident_id = ?
    ORDER BY report_id DESC LIMIT 1
");
$stmtStatus->execute([$residentId]);
$latestReport = $stmtStatus->fetch();

require_once __DIR__ . '/../../includes/header_resident.php';
?>

<style>
    /* ── Profile hero ── */
    .profile-hero {
        background: linear-gradient(135deg, #1a0a09 0%, #6b2318 60%, #3d1410 100%);
        border-radius: 16px;
        padding: 1.5rem 1.25rem 4rem;
        margin-bottom: -3rem;
        position: relative;
        overflow: hidden;
        text-align: center;
    }
    .profile-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 20% 50%, rgba(176,52,40,.3) 0%, transparent 60%),
                    radial-gradient(ellipse at 80% 20%, rgba(100,30,80,.2) 0%, transparent 50%);
        pointer-events: none;
    }
    .profile-avatar-wrap {
        position: relative;
        display: inline-block;
        margin-bottom: .75rem;
    }
    .profile-avatar {
        width: 76px; height: 76px;
        border-radius: 50%;
        background: var(--rescue-red);
        color: #fff;
        font-size: 1.6rem;
        font-weight: 700;
        display: flex; align-items: center; justify-content: center;
        border: 3px solid rgba(255,255,255,.3);
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }
    .profile-hero h2 { font-size: 1.1rem; font-weight: 700; color: #fff; margin: 0 0 4px; position: relative; z-index: 1; }
    .profile-hero p  { font-size: .78rem; color: rgba(255,255,255,.75); margin: 0; position: relative; z-index: 1; }

    /* ── Status pill on hero ── */
    .hero-status-pill {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: .72rem; font-weight: 700;
        padding: 4px 12px; border-radius: 20px;
        margin-top: 8px; position: relative; z-index: 1;
    }

    /* ── Section card ── */
    .profile-section {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: .875rem;
    }
    .profile-section-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: .875rem 1.1rem;
        border-bottom: 1px solid var(--rescue-border);
    }
    .profile-section-title {
        font-size: .85rem; font-weight: 700; color: var(--rescue-text);
        display: flex; align-items: center; gap: 7px; margin: 0;
    }
    .profile-section-title i { color: var(--rescue-red); }

    .edit-btn {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: .75rem; font-weight: 600; color: var(--rescue-red);
        background: var(--rescue-red-light); border: none;
        padding: 5px 12px; border-radius: 20px; cursor: pointer;
        font-family: inherit; transition: background .15s; text-decoration: none;
    }
    .edit-btn:hover { background: #F5B7B1; color: var(--rescue-red); }

    /* ── Info rows ── */
    .info-row {
        display: flex; align-items: flex-start;
        padding: .75rem 1.1rem;
        border-bottom: 1px solid #F5F5F5;
        gap: 10px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: .85rem; flex-shrink: 0; margin-top: 1px;
    }
    .info-label { font-size: .72rem; font-weight: 700; color: var(--rescue-muted); margin: 0 0 2px; text-transform: uppercase; letter-spacing: .4px; }
    .info-value { font-size: .875rem; color: var(--rescue-text); margin: 0; font-weight: 500; }
    .info-value.empty { color: var(--rescue-muted); font-style: italic; font-weight: 400; }

    /* ── Password row ── */
    .pw-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: .875rem 1.1rem; gap: 10px;
    }

    /* ── Stats row ── */
    .stats-row {
        display: grid; grid-template-columns: 1fr 1fr 1fr;
        gap: 1px; background: var(--rescue-border);
    }
    .stat-cell {
        background: var(--rescue-white);
        padding: .875rem .5rem; text-align: center;
    }
    .stat-cell-val   { font-size: 1.1rem; font-weight: 700; color: var(--rescue-text); }
    .stat-cell-label { font-size: .7rem; color: var(--rescue-muted); margin-top: 2px; }

    /* ── Danger zone ── */
    .danger-zone {
        background: var(--rescue-white);
        border: 1px solid #F5B7B1;
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: .875rem;
    }
    .danger-zone-header {
        padding: .875rem 1.1rem;
        border-bottom: 1px solid #F5B7B1;
        font-size: .85rem; font-weight: 700; color: var(--rescue-red);
        display: flex; align-items: center; gap: 7px;
    }
    .danger-btn {
        display: flex; align-items: center; justify-content: space-between;
        padding: .875rem 1.1rem;
        border-bottom: 1px solid #F5F5F5;
        background: none; border-left: none; border-right: none; border-top: none;
        width: 100%; cursor: pointer; font-family: inherit;
        transition: background .15s;
    }
    .danger-btn:last-child { border-bottom: none; }
    .danger-btn:hover { background: #FEF9F9; }
    .danger-btn-label { font-size: .85rem; font-weight: 600; color: var(--rescue-text); display: flex; align-items: center; gap: 8px; }
    .danger-btn-label i { color: var(--rescue-red); }

    /* ── Password strength bar ── */
    #pw-strength-bar-wrap { height: 4px; background: #F0F3F4; border-radius: 10px; overflow: hidden; margin-top: 4px; }
    #pw-strength-bar      { height: 100%; width: 0; border-radius: 10px; transition: all .3s; }

    /* ── Form helpers ── */
    .form-group { margin-bottom: .9rem; }
    .form-group:last-child { margin-bottom: 0; }
</style>

<div class="resident-content">

    <!-- Flash -->
    <?php if ($success): ?>
    <div class="alert-success"><i class="bi bi-check-circle-fill"></i> <?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert-error"><i class="bi bi-exclamation-circle-fill"></i> <?= $error ?></div>
    <?php endif; ?>

    <!-- ── Profile Hero ── -->
    <div class="profile-hero">
        <div class="profile-avatar-wrap">
            <div class="profile-avatar"><?= $initials ?></div>
        </div>
        <h2><?= htmlspecialchars($fullName) ?></h2>
        <p><?= htmlspecialchars($resident['email'] ?? '') ?></p>

        <?php
        $status = $latestReport['status'] ?? null;
        if ($status === 'safe_at_home'): ?>
        <div class="hero-status-pill" style="background:rgba(46,204,113,.2);color:#A9DFBF;">
            <i class="bi bi-house-check-fill"></i> Safe at Home
        </div>
        <?php elseif ($status === 'evacuated'): ?>
        <div class="hero-status-pill" style="background:rgba(52,152,219,.2);color:#AED6F1;">
            <i class="bi bi-building-fill"></i> Evacuated
        </div>
        <?php elseif ($status === 'need_help'): ?>
        <div class="hero-status-pill" style="background:rgba(192,57,43,.3);color:#F1948A;">
            <i class="bi bi-exclamation-triangle-fill"></i> Need Help
        </div>
        <?php else: ?>
        <div class="hero-status-pill" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.6);">
            <i class="bi bi-question-circle"></i> Status not reported
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Member stats ── -->
    <div class="profile-section" style="margin-top:3.5rem;">
        <div class="stats-row">
            <div class="stat-cell">
                <div class="stat-cell-val"><?= $age !== null ? $age : '—' ?></div>
                <div class="stat-cell-label">Age</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="font-size:.85rem;">
                    <?= !empty($resident['gender']) ? ucfirst($resident['gender']) : '—' ?>
                </div>
                <div class="stat-cell-label">Gender</div>
            </div>
            <div class="stat-cell">
                <div class="stat-cell-val" style="font-size:.85rem;">
                    <?= !empty($resident['member_since']) ? date('M Y', strtotime($resident['member_since'])) : '—' ?>
                </div>
                <div class="stat-cell-label">Joined</div>
            </div>
        </div>
    </div>

    <!-- ── Personal Information ── -->
    <div class="profile-section">
        <div class="profile-section-header">
            <h3 class="profile-section-title"><i class="bi bi-person-fill"></i> Personal Information</h3>
            <button class="edit-btn" onclick="openPersonalModal()">
                <i class="bi bi-pencil-fill"></i> Edit
            </button>
        </div>

        <div class="info-row">
            <div class="info-icon" style="background:#EBF5FB;color:#2980B9;"><i class="bi bi-person-fill"></i></div>
            <div>
                <p class="info-label">Full Name</p>
                <p class="info-value"><?= htmlspecialchars($fullName) ?></p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#EAFAF1;color:#1E8449;"><i class="bi bi-envelope-fill"></i></div>
            <div>
                <p class="info-label">Email</p>
                <p class="info-value"><?= htmlspecialchars($resident['email'] ?? '—') ?></p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#FEF9E7;color:#B7770D;"><i class="bi bi-telephone-fill"></i></div>
            <div>
                <p class="info-label">Phone Number</p>
                <p class="info-value <?= empty($resident['phone'])?'empty':'' ?>">
                    <?= !empty($resident['phone']) ? htmlspecialchars($resident['phone']) : 'Not set' ?>
                </p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#F5EEF8;color:#6C3483;"><i class="bi bi-calendar-fill"></i></div>
            <div>
                <p class="info-label">Birthdate</p>
                <p class="info-value <?= empty($resident['birthdate'])?'empty':'' ?>">
                    <?= !empty($resident['birthdate'])
                        ? date('F j, Y', strtotime($resident['birthdate'])) . ($age !== null ? ' (' . $age . ' yrs)' : '')
                        : 'Not set' ?>
                </p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#FDEDEC;color:#922B21;"><i class="bi bi-gender-ambiguous"></i></div>
            <div>
                <p class="info-label">Gender</p>
                <p class="info-value <?= empty($resident['gender'])?'empty':'' ?>">
                    <?= !empty($resident['gender']) ? ucfirst($resident['gender']) : 'Not set' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ── Address ── -->
    <div class="profile-section">
        <div class="profile-section-header">
            <h3 class="profile-section-title"><i class="bi bi-geo-alt-fill"></i> Address</h3>
            <button class="edit-btn" onclick="openAddressModal()">
                <i class="bi bi-pencil-fill"></i> Edit
            </button>
        </div>

        <div class="info-row">
            <div class="info-icon" style="background:#FADBD8;color:#C0392B;"><i class="bi bi-house-fill"></i></div>
            <div>
                <p class="info-label">House / Unit No.</p>
                <p class="info-value <?= empty($resident['house_no'])?'empty':'' ?>">
                    <?= !empty($resident['house_no']) ? htmlspecialchars($resident['house_no']) : 'Not set' ?>
                </p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#EBF5FB;color:#2980B9;"><i class="bi bi-signpost-fill"></i></div>
            <div>
                <p class="info-label">Street</p>
                <p class="info-value <?= empty($resident['street'])?'empty':'' ?>">
                    <?= !empty($resident['street']) ? htmlspecialchars($resident['street']) : 'Not set' ?>
                </p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#EAFAF1;color:#1E8449;"><i class="bi bi-geo-alt-fill"></i></div>
            <div>
                <p class="info-label">Area / Subdivision</p>
                <p class="info-value <?= empty($resident['area'])?'empty':'' ?>">
                    <?= !empty($resident['area']) ? htmlspecialchars($resident['area']) : 'Not set' ?>
                </p>
            </div>
        </div>
        <?php if (!empty($address) && $address !== 'Not set'): ?>
        <div style="padding:.6rem 1.1rem; background:#FAFAFA; border-top:1px solid #F5F5F5;">
            <div style="font-size:.75rem;color:var(--rescue-muted);display:flex;align-items:center;gap:5px;">
                <i class="bi bi-pin-map-fill" style="color:var(--rescue-red);"></i>
                Full address: <strong style="color:var(--rescue-text);"><?= htmlspecialchars($address) ?></strong>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Account & Security ── -->
    <div class="profile-section">
        <div class="profile-section-header">
            <h3 class="profile-section-title"><i class="bi bi-shield-lock-fill"></i> Account & Security</h3>
        </div>
        <div class="pw-row">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="info-icon" style="background:#F2F3F4;color:#7F8C8D;"><i class="bi bi-key-fill"></i></div>
                <div>
                    <p class="info-label" style="margin:0 0 2px;">Password</p>
                    <p style="font-size:.875rem;color:var(--rescue-muted);margin:0;letter-spacing:2px;">••••••••</p>
                </div>
            </div>
            <button class="edit-btn" onclick="openPasswordModal()">
                <i class="bi bi-pencil-fill"></i> Change
            </button>
        </div>
        <div class="info-row" style="border-top:1px solid #F5F5F5;">
            <div class="info-icon" style="background:#EBF5FB;color:#2980B9;"><i class="bi bi-person-badge-fill"></i></div>
            <div>
                <p class="info-label">Account Role</p>
                <p class="info-value">
                    <span style="background:#EBF5FB;color:#1A5276;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;">Resident</span>
                </p>
            </div>
        </div>
        <div class="info-row">
            <div class="info-icon" style="background:#EAFAF1;color:#1E8449;"><i class="bi bi-calendar-check-fill"></i></div>
            <div>
                <p class="info-label">Member Since</p>
                <p class="info-value">
                    <?= !empty($resident['member_since']) ? date('F j, Y', strtotime($resident['member_since'])) : '—' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ── Danger Zone ── -->
    <div class="danger-zone">
        <div class="danger-zone-header">
            <i class="bi bi-exclamation-triangle-fill"></i> Account Actions
        </div>
        <button class="danger-btn" onclick="openLogoutConfirm()">
            <span class="danger-btn-label">
                <i class="bi bi-box-arrow-right"></i> Sign Out
            </span>
            <i class="bi bi-chevron-right" style="color:var(--rescue-muted);font-size:.8rem;"></i>
        </button>
    </div>

</div><!-- /resident-content -->


<!-- ════════════════════════════════════════
     EDIT PERSONAL MODAL
════════════════════════════════════════ -->
<div class="r-modal-backdrop" id="personalModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-person-fill" style="color:var(--rescue-red);margin-right:6px;"></i> Edit Personal Info</h5>
            <button onclick="closeModal('personalModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST" id="personalForm">
            <input type="hidden" name="action" value="update_personal">
            <div class="r-modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" class="form-group">
                    <div>
                        <label class="form-label" style="font-size:.82rem;">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required
                            value="<?= htmlspecialchars($resident['first_name'] ?? '') ?>"
                            style="font-size:.875rem;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:.82rem;">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required
                            value="<?= htmlspecialchars($resident['last_name'] ?? '') ?>"
                            style="font-size:.875rem;">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Phone Number</label>
                    <input type="text" name="phone" class="form-control"
                        value="<?= htmlspecialchars($resident['phone'] ?? '') ?>"
                        placeholder="09XX-XXX-XXXX" style="font-size:.875rem;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Birthdate</label>
                    <input type="date" name="birthdate" class="form-control"
                        value="<?= htmlspecialchars($resident['birthdate'] ?? '') ?>"
                        style="font-size:.875rem;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Gender</label>
                    <select name="gender" class="form-control" style="font-size:.875rem;">
                        <option value="">— Select —</option>
                        <option value="male"   <?= ($resident['gender'] ?? '')==='male'   ?'selected':'' ?>>Male</option>
                        <option value="female" <?= ($resident['gender'] ?? '')==='female' ?'selected':'' ?>>Female</option>
                        <option value="other"  <?= ($resident['gender'] ?? '')==='other'  ?'selected':'' ?>>Other</option>
                    </select>
                </div>
            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('personalModal')">Cancel</button>
                <button type="button" class="btn-r-submit" onclick="openConfirmModal('personalConfirm')">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm personal -->
<div class="r-modal-backdrop" id="personalConfirm">
    <div class="r-modal" style="border-radius:20px 20px 0 0;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-body" style="text-align:center;padding:1.75rem 1.25rem;">
            <div style="width:52px;height:52px;background:#EBF5FB;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:#2980B9;">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <p style="font-size:.95rem;font-weight:700;color:var(--rescue-text);margin-bottom:.5rem;">Save personal info?</p>
            <p style="font-size:.82rem;color:var(--rescue-muted);margin:0;line-height:1.5;">Your profile details will be updated and visible to barangay officials.</p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('personalConfirm')">Cancel</button>
            <button type="button" class="btn-r-submit" onclick="closeModal('personalConfirm'); document.getElementById('personalForm').submit();">
                <i class="bi bi-check-lg"></i> Yes, Save
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     EDIT ADDRESS MODAL
════════════════════════════════════════ -->
<div class="r-modal-backdrop" id="addressModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-geo-alt-fill" style="color:var(--rescue-red);margin-right:6px;"></i> Edit Address</h5>
            <button onclick="closeModal('addressModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST" id="addressForm">
            <input type="hidden" name="action" value="update_address">
            <div class="r-modal-body">
                <div style="background:#FEF9E7;border:1px solid #F9E79F;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.8rem;color:#7D6608;">
                    <i class="bi bi-info-circle-fill"></i>
                    Keep your address updated so emergency responders can locate you quickly.
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">House / Unit No.</label>
                    <input type="text" name="house_no" class="form-control"
                        value="<?= htmlspecialchars($resident['house_no'] ?? '') ?>"
                        placeholder="e.g. Blk. 15 Lot 17" style="font-size:.875rem;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Street</label>
                    <input type="text" name="street" class="form-control"
                        value="<?= htmlspecialchars($resident['street'] ?? '') ?>"
                        placeholder="e.g. Duhat St." style="font-size:.875rem;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Area / Subdivision</label>
                    <input type="text" name="area" class="form-control"
                        value="<?= htmlspecialchars($resident['area'] ?? '') ?>"
                        placeholder="e.g. ACM Green" style="font-size:.875rem;">
                </div>
            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('addressModal')">Cancel</button>
                <button type="button" class="btn-r-submit" onclick="openConfirmModal('addressConfirm')">
                    <i class="bi bi-geo-alt-fill"></i> Save Address
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm address -->
<div class="r-modal-backdrop" id="addressConfirm">
    <div class="r-modal" style="border-radius:20px 20px 0 0;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-body" style="text-align:center;padding:1.75rem 1.25rem;">
            <div style="width:52px;height:52px;background:#EAFAF1;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:#1E8449;">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <p style="font-size:.95rem;font-weight:700;color:var(--rescue-text);margin-bottom:.5rem;">Update your address?</p>
            <p style="font-size:.82rem;color:var(--rescue-muted);margin:0;line-height:1.5;">Your address will be updated. Barangay officials use this to locate you during emergencies.</p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('addressConfirm')">Cancel</button>
            <button type="button" class="btn-r-submit" style="background:#1E8449;" onclick="closeModal('addressConfirm'); document.getElementById('addressForm').submit();">
                <i class="bi bi-check-lg"></i> Yes, Update
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     CHANGE PASSWORD MODAL
════════════════════════════════════════ -->
<div class="r-modal-backdrop" id="passwordModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-header">
            <h5><i class="bi bi-key-fill" style="color:var(--rescue-red);margin-right:6px;"></i> Change Password</h5>
            <button onclick="closeModal('passwordModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST" id="passwordForm">
            <input type="hidden" name="action" value="change_password">
            <div class="r-modal-body">
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Current Password *</label>
                    <div style="position:relative;">
                        <input type="password" name="current_password" id="cur-pw" class="form-control" required
                            style="font-size:.875rem;padding-right:2.5rem;font-family:monospace;">
                        <button type="button" onclick="togglePw('cur-pw','cur-eye')"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--rescue-muted);font-size:1rem;"
                            id="cur-eye">&#128065;</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">New Password *</label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="new-pw" class="form-control" required
                            placeholder="Minimum 6 characters"
                            style="font-size:.875rem;padding-right:2.5rem;font-family:monospace;"
                            oninput="checkStrength(this.value)">
                        <button type="button" onclick="togglePw('new-pw','new-eye')"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--rescue-muted);font-size:1rem;"
                            id="new-eye">&#128065;</button>
                    </div>
                    <div id="pw-strength-bar-wrap"><div id="pw-strength-bar"></div></div>
                    <small id="pw-strength-label" style="font-size:.72rem;color:var(--rescue-muted);">Enter a new password</small>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.82rem;">Confirm New Password *</label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="conf-pw" class="form-control" required
                            placeholder="Re-enter new password"
                            style="font-size:.875rem;padding-right:2.5rem;font-family:monospace;"
                            oninput="checkMatch()">
                        <button type="button" onclick="togglePw('conf-pw','conf-eye')"
                            style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--rescue-muted);font-size:1rem;"
                            id="conf-eye">&#128065;</button>
                    </div>
                    <small id="pw-match-label" style="font-size:.72rem;color:var(--rescue-muted);">Re-enter your new password</small>
                </div>
            </div>
            <div class="r-modal-footer">
                <button type="button" class="btn-r-cancel" onclick="closeModal('passwordModal')">Cancel</button>
                <button type="button" class="btn-r-submit" onclick="openConfirmModal('passwordConfirm')">
                    <i class="bi bi-key-fill"></i> Change Password
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm password -->
<div class="r-modal-backdrop" id="passwordConfirm">
    <div class="r-modal" style="border-radius:20px 20px 0 0;">
        <div class="r-modal-handle"></div>
        <div class="r-modal-body" style="text-align:center;padding:1.75rem 1.25rem;">
            <div style="width:52px;height:52px;background:#FEF9E7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:#B7770D;">
                <i class="bi bi-key-fill"></i>
            </div>
            <p style="font-size:.95rem;font-weight:700;color:var(--rescue-text);margin-bottom:.5rem;">Change your password?</p>
            <p style="font-size:.82rem;color:var(--rescue-muted);margin:0;line-height:1.5;">Make sure you remember your new password. You will need it to log in next time.</p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('passwordConfirm')">Cancel</button>
            <button type="button" class="btn-r-submit" style="background:#B7770D;"
                onclick="closeModal('passwordConfirm'); document.getElementById('passwordForm').submit();">
                <i class="bi bi-check-lg"></i> Yes, Change
            </button>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════
     LOGOUT CONFIRM MODAL
════════════════════════════════════════ -->
<div class="r-modal-backdrop" id="logoutConfirmModal">
    <div class="r-modal">
        <div class="r-modal-handle"></div>
        <div class="r-modal-body" style="text-align:center;padding:1.75rem 1.25rem;">
            <div style="width:52px;height:52px;background:#F2F3F4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.3rem;color:var(--rescue-muted);">
                <i class="bi bi-box-arrow-right"></i>
            </div>
            <p style="font-size:.95rem;font-weight:700;color:var(--rescue-text);margin-bottom:.5rem;">Sign out of RESCUE?</p>
            <p style="font-size:.82rem;color:var(--rescue-muted);margin:0;line-height:1.5;">You will need to log in again to report your status or view alerts.</p>
        </div>
        <div class="r-modal-footer">
            <button type="button" class="btn-r-cancel" onclick="closeModal('logoutConfirmModal')">Cancel</button>
            <button type="button"
                style="flex:2;padding:.75rem;border:none;border-radius:12px;background:#5D6D7E;font-size:.875rem;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;"
                onclick="window.location.href='<?= BASE_URL ?>/modules/auth/logout.php'">
                <i class="bi bi-box-arrow-right"></i> Yes, Sign Out
            </button>
        </div>
    </div>
</div>


<script>
// ── Modal helpers ─────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openConfirmModal(id) {
    // Validate password form before opening confirm
    if (id === 'passwordConfirm') {
        const newPw  = document.getElementById('new-pw').value.trim();
        const confPw = document.getElementById('conf-pw').value.trim();
        const curPw  = document.getElementById('cur-pw').value.trim();
        if (!curPw || !newPw || !confPw) {
            alert('Please fill in all password fields.');
            return;
        }
        if (newPw.length < 6) {
            alert('New password must be at least 6 characters.');
            return;
        }
        if (newPw !== confPw) {
            alert('New passwords do not match.');
            return;
        }
    }
    openModal(id);
}

document.querySelectorAll('.r-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.r-modal-backdrop.open').forEach(m => m.classList.remove('open'));
});

// ── Open helpers ──────────────────────────────────────────────────────────
function openPersonalModal() { openModal('personalModal'); }
function openAddressModal()  { openModal('addressModal');  }
function openPasswordModal() { openModal('passwordModal'); }
function openLogoutConfirm() { openModal('logoutConfirmModal'); }

// ── Password toggle ───────────────────────────────────────────────────────
function togglePw(inputId, eyeId) {
    const inp = document.getElementById(inputId);
    const eye = document.getElementById(eyeId);
    inp.type      = inp.type === 'password' ? 'text' : 'password';
    eye.innerHTML = inp.type === 'text' ? '&#128064;' : '&#128065;';
}

// ── Password strength ─────────────────────────────────────────────────────
function checkStrength(val) {
    const bar = document.getElementById('pw-strength-bar');
    const lbl = document.getElementById('pw-strength-label');
    if (!val) {
        bar.style.width = '0'; lbl.textContent = 'Enter a new password'; lbl.style.color = 'var(--rescue-muted)'; return;
    }
    let s = 0;
    if (val.length >= 6)           s++;
    if (val.length >= 10)          s++;
    if (/[A-Z]/.test(val))         s++;
    if (/[0-9]/.test(val))         s++;
    if (/[^A-Za-z0-9]/.test(val))  s++;
    const lvls = [
        { w:'20%',  color:'#E74C3C', label:'Very weak'   },
        { w:'40%',  color:'#E67E22', label:'Weak'        },
        { w:'60%',  color:'#F1C40F', label:'Fair'        },
        { w:'80%',  color:'#2ECC71', label:'Strong'      },
        { w:'100%', color:'#1E8449', label:'Very strong' },
    ];
    const lvl = lvls[Math.min(s - 1, 4)];
    bar.style.width      = lvl.w;
    bar.style.background = lvl.color;
    lbl.textContent      = lvl.label;
    lbl.style.color      = lvl.color;
}

// ── Password match ────────────────────────────────────────────────────────
function checkMatch() {
    const newPw  = document.getElementById('new-pw').value;
    const confPw = document.getElementById('conf-pw').value;
    const lbl    = document.getElementById('pw-match-label');
    if (!confPw) { lbl.textContent = 'Re-enter your new password'; lbl.style.color = 'var(--rescue-muted)'; return; }
    if (newPw === confPw) {
        lbl.textContent = '✓ Passwords match'; lbl.style.color = '#1E8449';
    } else {
        lbl.textContent = '✗ Passwords do not match'; lbl.style.color = '#E74C3C';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_resident.php'; ?>