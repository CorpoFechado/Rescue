<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'User Management';
$activePage = 'user_management';

// ── Handle POST actions ──────────────────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── CREATE ACCOUNT ──
    if ($action === 'create') {
        $email     = trim($_POST['email'] ?? '');
        $password  = trim($_POST['password'] ?? '');
        $role      = $_POST['role'] ?? 'resident';
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        // Resident-specific
        // Resident-specific
        $houseNo = trim($_POST['house_no'] ?? '');
        $street  = trim($_POST['street']   ?? '');
        $area    = trim($_POST['area']     ?? '');
        $birthdate = $_POST['birthdate'] ?? null;
        $gender    = $_POST['gender'] ?? null;

        // Official-specific
        $position  = trim($_POST['position'] ?? '');

        if (empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Check email uniqueness
            $stmtCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) {
                $error = 'An account with that email already exists.';
            } else {
                try {
                    $conn->beginTransaction();

                    // Insert user
                    $stmtUser = $conn->prepare("INSERT INTO users (email, password, role, is_active) VALUES (?, ?, ?, 1)");
                    $stmtUser->execute([$email, $password, $role]);
                    $newUserId = $conn->lastInsertId();

                    // Insert profile
                    if ($role === 'resident') {
                        $stmtProfile = $conn->prepare("
                            INSERT INTO residents (user_id, first_name, last_name, phone, house_no, street, area, birthdate, gender)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmtProfile->execute([
                            $newUserId,
                            $firstName,
                            $lastName,
                            $phone ?: null,
                            $houseNo ?: null,
                            $street  ?: null,
                            $area    ?: null,
                            $birthdate ?: null,
                            $gender ?: null,
                        ]);
                    } else {
                        $stmtProfile = $conn->prepare("
                            INSERT INTO officials (user_id, first_name, last_name, phone, position)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmtProfile->execute([
                            $newUserId,
                            $firstName,
                            $lastName,
                            $phone ?: null,
                            $position ?: 'Barangay Official',
                        ]);
                    }

                    $conn->commit();
                    $success = 'Account for ' . htmlspecialchars($firstName . ' ' . $lastName) . ' created successfully.';

                } catch (Exception $e) {
                    $conn->rollBack();
                    $error = 'Failed to create account. Please try again.';
                }
            }
        }
    }
        // ── EDIT ACCOUNT ──
        if ($action === 'edit') {
        $userId    = (int) ($_POST['user_id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $email     = trim($_POST['email']      ?? '');
        $role      = $_POST['role']            ?? '';
        $houseNo = trim($_POST['house_no'] ?? '');
        $street  = trim($_POST['street']   ?? '');
        $area    = trim($_POST['area']     ?? '');
        $birthdate = $_POST['birthdate']       ?? null;
        $gender    = $_POST['gender']          ?? null;
        $position  = trim($_POST['position']   ?? '');

        if (empty($firstName) || empty($lastName) || empty($email)) {
            $error = 'Name and email are required.';
        } else {
            try {
                $conn->beginTransaction();
                $stmtCheck = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmtCheck->execute([$email, $userId]);
                if ($stmtCheck->fetch()) {
                    $error = 'That email is already used by another account.';
                    $conn->rollBack();
                } else {
                    $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?")->execute([$email, $userId]);
                    if ($role === 'resident') {
                        $conn->prepare("UPDATE residents SET first_name=?, last_name=?, phone=?, house_no=?, street=?, area=?, birthdate=?, gender=? WHERE user_id=?")
                            ->execute([$firstName, $lastName, $phone ?: null, $houseNo ?: null, $street ?: null, $area ?: null, $birthdate ?: null, $gender ?: null, $userId]);
                    } else {
                        $conn->prepare("UPDATE officials SET first_name=?, last_name=?, phone=?, position=? WHERE user_id=?")
                            ->execute([$firstName, $lastName, $phone ?: null, $position, $userId]);
                    }
                    $conn->commit();
                    $success = 'Account updated successfully.';
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = 'Failed to update account.';
            }
        }
    }

    // ── TOGGLE ACTIVE STATUS ──
    if ($action === 'toggle_status') {
        $userId    = (int) ($_POST['user_id'] ?? 0);
        $newStatus = (int) ($_POST['new_status'] ?? 0);
        if ($userId) {
            $stmtToggle = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
            $stmtToggle->execute([$newStatus, $userId]);
            $success = 'Account status updated.';
        }
    }

    // ── DELETE ACCOUNT ──
    if ($action === 'delete') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        // Prevent deleting own account
        if ($userId === (int) $_SESSION['user_id']) {
            $error = 'You cannot delete your own account.';
        } elseif ($userId) {
            $stmtDel = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmtDel->execute([$userId]);
            $success = 'Account deleted successfully.';
        }
    }

    // ── RESET PASSWORD ──
    if ($action === 'reset_password') {
        $userId      = (int) ($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');
        if ($userId && strlen($newPassword) >= 6) {
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$newPassword, $userId]);
            $success = 'Password reset successfully.';
        } else {
            $error = 'Password must be at least 6 characters.';
        }
    }
}

// ── Fetch all accounts ───────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$filterRole = $_GET['role'] ?? 'all';

$sql = "
    SELECT
        u.user_id, u.email, u.role, u.is_active, u.created_at,
        COALESCE(r.first_name, o.first_name) AS first_name,
        COALESCE(r.last_name,  o.last_name)  AS last_name,
        COALESCE(r.phone,      o.phone)      AS phone,
        o.position,
        r.house_no, r.street, r.area
    FROM users u
    LEFT JOIN residents r ON r.user_id = u.user_id
    LEFT JOIN officials o ON o.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (COALESCE(r.first_name, o.first_name) LIKE ?
               OR COALESCE(r.last_name, o.last_name) LIKE ?
               OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterRole !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}

$sql .= " ORDER BY u.created_at DESC";
$stmtUsers = $conn->prepare($sql);
$stmtUsers->execute($params);
$allUsers = $stmtUsers->fetchAll();


// Summary counts
$stmtCounts = $conn->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
$counts = ['resident' => 0, 'official' => 0];
foreach ($stmtCounts->fetchAll() as $row) {
    $counts[$row['role']] = $row['cnt'];
}
$totalUsers = array_sum($counts);

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
    .um-stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
        margin-bottom: 1.75rem;
    }

    .filter-bar {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .accounts-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .accounts-table th {
        background: #FAFAFA;
        border-bottom: 1.5px solid var(--rescue-border);
        padding: 0.75rem 1rem;
        text-align: left;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--rescue-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        white-space: nowrap;
    }

    .accounts-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        vertical-align: middle;
        color: var(--rescue-text);
    }

    .accounts-table tr:last-child td {
        border-bottom: none;
    }

    .accounts-table tr:hover td {
        background: #FAFAFA;
    }

    .role-badge-resident {
        background: #EBF5FB;
        color: #1A5276;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 20px;
    }

    .role-badge-official {
        background: #FEF9E7;
        color: #7D6608;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 20px;
    }

    .status-active {
        background: #EAFAF1;
        color: #1E8449;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 20px;
    }

    .status-inactive {
        background: #F2F3F4;
        color: var(--rescue-muted);
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 9px;
        border-radius: 20px;
    }

    .action-btn {
        background: none;
        border: 1px solid var(--rescue-border);
        border-radius: 7px;
        padding: 4px 9px;
        font-size: 0.78rem;
        cursor: pointer;
        font-family: inherit;
        color: var(--rescue-text);
        transition: all 0.15s;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .action-btn:hover { background: #F5F5F5; }
    .action-btn.danger { color: var(--rescue-red); border-color: var(--rescue-red-light); }
    .action-btn.danger:hover { background: var(--rescue-red-light); }

    /* Modal */
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

    .rescue-modal-backdrop.open {
        display: flex;
    }

    .rescue-modal {
        background: #fff;
        border-radius: 16px;
        width: 100%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
    }

    .rescue-modal-header {
        padding: 1.25rem 1.5rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .rescue-modal-header h5 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
        color: var(--rescue-text);
    }

    .rescue-modal-body {
        padding: 1.25rem 1.5rem;
    }

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
        transition: background 0.15s;
    }

    .btn-modal-submit:hover { background: var(--rescue-red-dark); }

    /* Role toggle in create form */
    .role-toggle {
        display: flex;
        gap: 8px;
        margin-bottom: 1rem;
    }

    .role-toggle-btn {
        flex: 1;
        padding: 0.6rem;
        border: 1.5px solid var(--rescue-border);
        border-radius: 10px;
        background: #fff;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--rescue-muted);
        cursor: pointer;
        font-family: inherit;
        text-align: center;
        transition: all 0.15s;
    }

    .role-toggle-btn.active {
        border-color: var(--rescue-red);
        background: var(--rescue-red-light);
        color: var(--rescue-red);
    }

    @media (max-width: 767.98px) {
        .accounts-table thead { display: none; }
        .accounts-table tr {
            display: block;
            border: 1px solid var(--rescue-border);
            border-radius: 10px;
            margin-bottom: 10px;
            padding: 0.5rem 0;
        }
        .accounts-table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #F5F5F5;
            font-size: 0.82rem;
        }
        .accounts-table td:last-child { border-bottom: none; }
        .accounts-table td::before {
            content: attr(data-label);
            font-weight: 700;
            color: var(--rescue-muted);
            font-size: 0.75rem;
            margin-right: 8px;
            flex-shrink: 0;
        }
        .action-btn.warning {
            color: #B7770D;
            border-color: #FAD7A0;
        }
        .action-btn.warning:hover {
            background: #FEF9E7;
        }
        .action-btn.success {
            color: #1E8449;
            border-color: #A9DFBF;
        }
        .action-btn.success:hover {
            background: #EAFAF1;
        }
        .action-btn.warning {
            color: #B7770D;
            border-color: #FAD7A0;
        }
        .action-btn.warning:hover { background: #FEF9E7; }

        .action-btn.success {
            color: #1E8449;
            border-color: #A9DFBF;
        }
        .action-btn.success:hover { background: #EAFAF1; }
        .action-btn.blue    { color:#2980B9; border-color:#AED6F1; }
        .action-btn.blue:hover    { background:#EBF5FB; }
        .action-btn.warning { color:#B7770D; border-color:#FAD7A0; }
        .action-btn.warning:hover { background:#FEF9E7; }
        .action-btn.success { color:#1E8449; border-color:#A9DFBF; }
        .action-btn.success:hover { background:#EAFAF1; }
        .detail-row {
            display: flex;
            padding: 0.65rem 0;
            border-bottom: 1px solid var(--rescue-border);
            gap: 8px;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label {
            width: 140px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--rescue-muted);
            flex-shrink: 0;
        }
        .detail-value {
            font-size: 0.85rem;
            color: var(--rescue-text);
            flex: 1;
        }
    }
</style>

<!-- Page header -->
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">
            User Management
        </h1>
        <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">
            Manage all resident and official accounts
        </p>
    </div>
    <button class="btn-rescue" onclick="openModal('createModal')">
        <i class="bi bi-person-plus-fill"></i>
        Add New Account
    </button>
</div>

<!-- Alerts -->
<?php if ($success): ?>
<div style="background:#EAFAF1; border:1px solid #A9DFBF; color:#1E8449; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem; display:flex; align-items:center; gap:8px;">
    <i class="bi bi-check-circle-fill"></i>
    <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div style="background:var(--rescue-red-light); border:1px solid #F1948A; color:var(--rescue-red-dark); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem; display:flex; align-items:center; gap:8px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= $error ?>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="um-stat-grid">
    <div class="stat-card">
        <div>
            <div class="stat-label">Total Accounts</div>
            <div class="stat-value" style="color:var(--rescue-text);"><?= $totalUsers ?></div>
        </div>
        <div class="stat-icon" style="background:#EBF5FB; color:#2980B9;">
            <i class="bi bi-people-fill"></i>
        </div>
    </div>
    <div class="stat-card">
        <div>
            <div class="stat-label">Residents</div>
            <div class="stat-value" style="color:#2980B9;"><?= $counts['resident'] ?></div>
        </div>
        <div class="stat-icon" style="background:#EBF5FB; color:#2980B9;">
            <i class="bi bi-person-fill"></i>
        </div>
    </div>
    <div class="stat-card">
        <div>
            <div class="stat-label">Officials</div>
            <div class="stat-value" style="color:#B7770D;"><?= $counts['official'] ?></div>
        </div>
        <div class="stat-icon" style="background:#FEF9E7; color:#B7770D;">
            <i class="bi bi-person-fill-gear"></i>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-bar">
    <form method="GET" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
        <div style="position:relative; flex:1; min-width:200px;">
            <i class="bi bi-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--rescue-muted); font-size:0.85rem;"></i>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                placeholder="Search by name or email..."
                style="width:100%; padding:0.55rem 0.75rem 0.55rem 2rem; border:1.5px solid var(--rescue-border); border-radius:9px; font-size:0.85rem; font-family:inherit; background:#FAFAFA;"
                onfocus="this.style.borderColor='var(--rescue-red)'"
                onblur="this.style.borderColor='var(--rescue-border)'">
        </div>
        <select name="role"
            style="padding:0.55rem 0.75rem; border:1.5px solid var(--rescue-border); border-radius:9px; font-size:0.85rem; font-family:inherit; background:#FAFAFA; color:var(--rescue-text); cursor:pointer;">
            <option value="all"      <?= $filterRole === 'all'      ? 'selected' : '' ?>>All Roles</option>
            <option value="resident" <?= $filterRole === 'resident' ? 'selected' : '' ?>>Residents</option>
            <option value="official" <?= $filterRole === 'official' ? 'selected' : '' ?>>Officials</option>
        </select>
        <button type="submit" class="btn-rescue" style="padding:0.55rem 1.1rem;">
            <i class="bi bi-funnel-fill"></i> Filter
        </button>
        <?php if ($search || $filterRole !== 'all'): ?>
        <a href="<?= BASE_URL ?>/modules/official/user_management.php"
            style="font-size:0.82rem; color:var(--rescue-muted); text-decoration:none; font-weight:500;">
            Clear
        </a>
        <?php endif; ?>
    </form>
</div>

<!-- Accounts table -->
<div class="rescue-card" style="padding:0; overflow:hidden;">
    <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--rescue-border); display:flex; align-items:center; justify-content:space-between;">
        <span style="font-size:0.9rem; font-weight:700; color:var(--rescue-text);">
            All Accounts
            <span style="font-size:0.8rem; font-weight:500; color:var(--rescue-muted); margin-left:6px;">
                (<?= count($allUsers) ?> <?= count($allUsers) === 1 ? 'result' : 'results' ?>)
            </span>
        </span>
    </div>

    <?php if (empty($allUsers)): ?>
    <div style="text-align:center; padding:3rem 1rem; color:var(--rescue-muted);">
        <i class="bi bi-people" style="font-size:2.5rem; display:block; margin-bottom:0.75rem; color:#D5D8DC;"></i>
        <p style="font-size:0.875rem; margin:0;">No accounts found.</p>
        <?php if ($search || $filterRole !== 'all'): ?>
        <p style="font-size:0.8rem; margin-top:4px;">Try adjusting your filters.</p>
        <?php else: ?>
        <p style="font-size:0.8rem; margin-top:4px;">Click "Add New Account" to get started.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="accounts-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Address / Position</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $u):
                    $fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $fullName = $fullName ?: '—';
                    $initials = strtoupper(
                        substr($u['first_name'] ?? '?', 0, 1) .
                        substr($u['last_name']  ?? '',  0, 1)
                    );
                    $addressDisplay = $u['role'] === 'resident'
                            ? implode(', ', array_filter([$u['house_no'], $u['street'], $u['area']]))
                            : ($u['position'] ?? '—');
                        if (!$addressDisplay) $addressDisplay = '—';
                        $userData = json_encode([
                        'user_id'          => $u['user_id'],
                        'first_name'       => $u['first_name'] ?? '',
                        'last_name'        => $u['last_name']  ?? '',
                        'email'            => $u['email'],
                        'role'             => $u['role'],
                        'phone'            => $u['phone']      ?? '',
                        'house_no'         => $u['house_no'] ?? '',
                        'street'           => $u['street']   ?? '',
                        'area'             => $u['area']     ?? '',
                        'position'         => $u['position']   ?? '',
                        'birthdate'        => $u['birthdate']  ?? '',
                        'gender'           => $u['gender']     ?? '',
                        'is_active'        => $u['is_active'],
                        'created'          => date('M j, Y', strtotime($u['created_at'])),
                        'address_display'  => $addressDisplay,
                        'initials'         => $initials,
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                <tr>
                    <td data-label="Name">
                        <div style="display:flex; align-items:center; gap:9px;">
                            <div style="width:32px; height:32px; border-radius:50%; background:<?= $u['role'] === 'official' ? '#FEF9E7' : '#EBF5FB' ?>; display:flex; align-items:center; justify-content:center; font-size:0.72rem; font-weight:700; color:<?= $u['role'] === 'official' ? '#B7770D' : '#2980B9' ?>; flex-shrink:0;">
                                <?= $initials ?>
                            </div>
                            <span style="font-weight:600;"><?= htmlspecialchars($fullName) ?></span>
                        </div>
                    </td>
                    <td data-label="Email" style="color:var(--rescue-muted);">
                        <?= htmlspecialchars($u['email']) ?>
                    </td>
                    <td data-label="Role">
                        <span class="role-badge-<?= $u['role'] ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td data-label="Address / Position" style="color:var(--rescue-muted); font-size:0.82rem;">
                        <?= htmlspecialchars($addressDisplay) ?>
                    </td>
                    <td data-label="Phone" style="color:var(--rescue-muted);">
                        <?= htmlspecialchars($u['phone'] ?? '—') ?>
                    </td>
                    <td data-label="Status">
                        <span class="<?= $u['is_active'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td data-label="Created" style="color:var(--rescue-muted); font-size:0.8rem;">
                        <?= date('M j, Y', strtotime($u['created_at'])) ?>
                    </td>
                    <td data-label="Actions">
                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                            <button class="action-btn blue" onclick='openViewModal(<?= $userData ?>)'>View Details</button>
                            <button class="action-btn <?= $u['is_active']?'warning':'success' ?>" onclick='openToggleModal(<?= $userData ?>)'>
                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                            <button class="action-btn" onclick='openResetModal(<?= $userData ?>)'>Reset Password</button>
                            <?php if ($u['user_id'] != $_SESSION['user_id']): ?>
                            <button class="action-btn danger" onclick='openDeleteModal(<?= $userData ?>)'>Delete</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════
     CREATE ACCOUNT MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="createModal">
    <div class="rescue-modal">
        <div class="rescue-modal-header">
            <h5><i class="bi bi-person-plus-fill" style="color:var(--rescue-red);"></i> Add New Account</h5>
            <button onclick="closeModal('createModal')"
                style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--rescue-muted); padding:0;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="rescue-modal-body">

                <!-- Role toggle -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Account Type *</label>
                    <div class="role-toggle">
                        <button type="button" class="role-toggle-btn active" id="btnResident"
                            onclick="switchCreateRole('resident')">
                            <i class="bi bi-person-fill"></i> Resident
                        </button>
                        <button type="button" class="role-toggle-btn" id="btnOfficial"
                            onclick="switchCreateRole('official')">
                            <i class="bi bi-person-fill-gear"></i> Official
                        </button>
                    </div>
                    <input type="hidden" name="role" id="roleInput" value="resident">
                </div>

                <!-- Name row -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
                    <div>
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required placeholder="Juan">
                    </div>
                    <div>
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required placeholder="Dela Cruz">
                    </div>
                </div>

                <!-- Email -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required placeholder="juan@email.com">
                </div>

                <!-- Password -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Password *</label>
                    <input type="text" name="password" class="form-control" required placeholder="Min. 6 characters"
                        style="font-family: monospace;">
                    <small style="color:var(--rescue-muted); font-size:0.75rem;">
                        This will be given to the account holder. They can change it later.
                    </small>
                </div>

                <!-- Phone -->
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone" class="form-control" placeholder="09XX-XXX-XXXX">
                </div>

                <!-- Resident fields -->
                <div id="residentFields">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
                        <div>
                            <label class="form-label">House No.</label>
                            <input type="text" name="house_no" class="form-control" placeholder="e.g. 123">
                        </div>
                        <div>
                            <label class="form-label">Street</label>
                            <input type="text" name="street" class="form-control" placeholder="e.g. Rizal St.">
                        </div>
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label class="form-label">Area</label>
                        <input type="text" name="area" class="form-control" placeholder="e.g. ACM, Looban, Highway">
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
                        <div>
                            <label class="form-label">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">— Select —</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Official fields -->
                <div id="officialFields" style="display:none;">
                    <div style="margin-bottom:1rem;">
                        <label class="form-label">Position</label>
                        <input type="text" name="position" class="form-control"
                            placeholder="e.g. Barangay Captain, BDRRMC Officer">
                    </div>
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('createModal')">Cancel</button>
                <button type="button" class="btn-modal-submit" onclick="openConfirmCreate()">
                    Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     RESET PASSWORD MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="resetModal">
    <div class="rescue-modal" style="max-width:430px;">
        <div class="rescue-modal-header">
            <h5>Reset Password</h5>
            <button onclick="closeModal('resetModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset-user-id">
            <div class="rescue-modal-body">

                <div style="background:#FEF9E7;border:1px solid #FAD7A0;border-radius:10px;padding:0.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;">
                    <div id="reset-avatar" style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;flex-shrink:0;"></div>
                    <div>
                        <div style="font-size:0.85rem;font-weight:700;color:var(--rescue-text);" id="reset-name"></div>
                        <div style="font-size:0.78rem;color:var(--rescue-muted);" id="reset-email-display"></div>
                    </div>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Current Password</label>
                    <div style="position:relative;">
                        <input type="password" id="current-pw-display" readonly
                            style="width:100%;padding:0.65rem 2.8rem 0.65rem 1rem;border:1.5px solid var(--rescue-border);border-radius:10px;font-size:0.875rem;background:#F8F9FA;font-family:monospace;cursor:default;">
                        <button type="button" onclick="toggleCurrentPw()"
                            style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--rescue-muted);font-size:1rem;"
                            id="current-pw-eye">&#128065;</button>
                    </div>
                    <small style="font-size:0.75rem;color:var(--rescue-muted);">Click the eye icon to reveal the current password.</small>
                </div>

                <div style="margin-bottom:0.5rem;">
                    <label class="form-label">New Password *</label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="new-pw-input" class="form-control" required
                            placeholder="Minimum 6 characters" style="padding-right:2.8rem;font-family:monospace;">
                        <button type="button" onclick="toggleNewPw()"
                            style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--rescue-muted);font-size:1rem;"
                            id="new-pw-eye">&#128065;</button>
                    </div>
                </div>

                <div style="margin-bottom:1rem;">
                    <div style="height:4px;background:#F0F3F4;border-radius:10px;overflow:hidden;">
                        <div id="pw-strength-bar" style="height:100%;width:0;border-radius:10px;transition:all 0.3s;"></div>
                    </div>
                    <small id="pw-strength-label" style="font-size:0.75rem;color:var(--rescue-muted);">Enter a password to check strength</small>
                </div>

                <div style="background:#EBF5FB;border:1px solid #AED6F1;border-radius:10px;padding:0.75rem 1rem;font-size:0.8rem;color:#1A5276;">
                    The new password will be provided to the account holder for their next login.
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('resetModal')">Cancel</button>
                <button type="button" class="btn-modal-submit" style="background:#B7770D;"
                    onclick="openConfirmReset()">
                    Save New Password
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     DELETE CONFIRMATION MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="deleteModal">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-body" style="text-align:center; padding:2rem 1.5rem;">
            <div style="width:52px; height:52px; background:var(--rescue-red-light); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem;">
                <i class="bi bi-trash-fill" style="font-size:1.3rem; color:var(--rescue-red);"></i>
            </div>
            <h5 style="font-size:1rem; font-weight:700; margin-bottom:0.5rem;">Delete Account</h5>
            <p style="font-size:0.85rem; color:var(--rescue-muted); margin-bottom:0;">
                Are you sure you want to delete the account of
                <strong id="deleteUserName" style="color:var(--rescue-text);"></strong>?
                This cannot be undone.
            </p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">
            <div class="rescue-modal-footer" style="justify-content:center;">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">
                    <i class="bi bi-trash-fill"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<!-- CONFIRM CREATE MODAL -->
<div class="rescue-modal-backdrop" id="confirmCreateModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-body" style="text-align:center; padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:#EAFAF1;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:#1E8449;">
                &#128100;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Create Account?</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to create an account for
                <strong id="confirm-create-name" style="color:var(--rescue-text);"></strong>?
                Their login credentials will be ready immediately.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center; gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;"
                onclick="closeModal('confirmCreateModal')">
                Cancel
            </button>
            <button type="button" class="btn-modal-submit" style="flex:1;"
                onclick="submitCreateForm()">
                Yes, Create Account
            </button>
        </div>
    </div>
</div>

<!-- CONFIRM EDIT MODAL -->
<div class="rescue-modal-backdrop" id="confirmEditModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-body" style="text-align:center; padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:#EBF5FB;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:#2980B9;">
                &#9998;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Save Changes?</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to update the details of
                <strong id="confirm-edit-name" style="color:var(--rescue-text);"></strong>?
                This will overwrite their current information.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center; gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;"
                onclick="closeModal('confirmEditModal')">
                Cancel
            </button>
            <button type="button" class="btn-modal-submit" style="flex:1; background:#2980B9;"
                onclick="submitEditForm()">
                Yes, Save Changes
            </button>
        </div>
    </div>
</div>

<!-- CONFIRM RESET PASSWORD MODAL -->
<div class="rescue-modal-backdrop" id="confirmResetModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-body" style="text-align:center; padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:#FEF9E7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:#B7770D;">
                &#128273;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Reset Password?</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to reset the password of
                <strong id="confirm-reset-name" style="color:var(--rescue-text);"></strong>?
                <br><br>
                They will need to use the new password on their next login.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center; gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;"
                onclick="closeModal('confirmResetModal')">
                Cancel
            </button>
            <button type="button" class="btn-modal-submit" style="flex:1; background:#B7770D;"
                onclick="submitResetForm()">
                Yes, Reset Password
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
    VIEW DETAILS MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="viewModal">
    <div class="rescue-modal" style="max-width:480px;">
        <div class="rescue-modal-header">
            <h5>Account Details</h5>
            <button onclick="closeModal('viewModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body">
            <div style="display:flex;align-items:center;gap:14px;padding-bottom:1.25rem;border-bottom:1px solid var(--rescue-border);margin-bottom:1.25rem;">
                <div id="view-avatar" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;flex-shrink:0;"></div>
                <div>
                    <div id="view-name" style="font-size:1.05rem;font-weight:700;color:var(--rescue-text);"></div>
                    <div id="view-role-badge" style="margin-top:5px;"></div>
                </div>
            </div>
            <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value" id="view-email"></span></div>
            <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value" id="view-phone"></span></div>
            <div class="detail-row"><span class="detail-label">Address / Position</span><span class="detail-value" id="view-address"></span></div>
            <div class="detail-row" id="view-gender-row"><span class="detail-label">Gender</span><span class="detail-value" id="view-gender"></span></div>
            <div class="detail-row" id="view-bday-row"><span class="detail-label">Birthdate</span><span class="detail-value" id="view-birthdate"></span></div>
            <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value" id="view-status"></span></div>
            <div class="detail-row"><span class="detail-label">Date Created</span><span class="detail-value" id="view-created"></span></div>
        </div>
        <div class="rescue-modal-footer" style="justify-content:space-between;">
            <div>
                <button class="action-btn danger" id="view-delete-btn" onclick="switchToDelete()">Delete Account</button>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('viewModal')">Close</button>
                <button class="btn-modal-submit" id="view-toggle-btn" onclick="switchToToggle()"></button>
                <button class="btn-modal-submit" onclick="switchToEdit()" style="background:#2980B9;">Edit Details</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
    TOGGLE STATUS MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="toggleModal">
    <div class="rescue-modal" style="max-width:400px;">
        <div class="rescue-modal-header">
            <h5 id="toggle-title">Deactivate Account</h5>
            <button onclick="closeModal('toggleModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body" style="text-align:center;">
            <div id="toggle-icon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.5rem;"></div>
            <p style="font-size:0.9rem;color:var(--rescue-text);font-weight:600;margin-bottom:0.5rem;" id="toggle-message"></p>
            <p style="font-size:0.82rem;color:var(--rescue-muted);line-height:1.6;margin-bottom:1rem;" id="toggle-explanation"></p>
            <div style="background:#F8F9FA;border:1px solid var(--rescue-border);border-radius:10px;padding:0.75rem 1rem;font-size:0.8rem;color:var(--rescue-muted);text-align:left;">
                <strong style="color:var(--rescue-text);">Note:</strong>
                <span id="toggle-note"></span>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="user_id" id="toggle-user-id">
            <input type="hidden" name="new_status" id="toggle-new-status">
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('toggleModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit" id="toggle-submit-btn"></button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
    EDIT MODAL
════════════════════════════════════════════ -->
<div class="rescue-modal-backdrop" id="editModal">
    <div class="rescue-modal" style="max-width:500px;">
        <div class="rescue-modal-header">
            <h5>Edit Account</h5>
            <button onclick="closeModal('editModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit-user-id">
            <input type="hidden" name="role" id="edit-role">
            <div class="rescue-modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem;">
                    <div><label class="form-label">First Name *</label><input type="text" name="first_name" id="edit-first-name" class="form-control" required></div>
                    <div><label class="form-label">Last Name *</label><input type="text" name="last_name" id="edit-last-name" class="form-control" required></div>
                </div>
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" id="edit-email" class="form-control" required>
                </div>
                <div style="margin-bottom:1rem;">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="edit-phone" class="form-control">
                </div>
                <div id="edit-resident-fields">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:1rem;">
                        <div>
                            <label class="form-label">House No.</label>
                            <input type="text" name="house_no" id="edit-house-no" class="form-control" placeholder="e.g. 123">
                        </div>
                        <div>
                            <label class="form-label">Street</label>
                            <input type="text" name="street" id="edit-street" class="form-control" placeholder="e.g. Rizal St.">
                        </div>
                    </div>
                    <div style="margin-bottom:1rem;">
                        <label class="form-label">Area / Subdivision / Sitio</label>
                        <input type="text" name="area" id="edit-area" class="form-control" placeholder="e.g. Poblacion">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div><label class="form-label">Birthdate</label><input type="date" name="birthdate" id="edit-birthdate" class="form-control"></div>
                        <div>
                            <label class="form-label">Gender</label>
                            <select name="gender" id="edit-gender" class="form-control">
                                <option value="">— Select —</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="edit-official-fields" style="display:none;">
                    <label class="form-label">Position</label>
                    <input type="text" name="position" id="edit-position" class="form-control">
                </div>
            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel"
                    onclick="closeModal('editModal'); openModal('viewModal')">Back</button>
                <button type="button" class="btn-modal-submit"
                    onclick="openConfirmEdit()">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentUser = {};

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.rescue-modal-backdrop').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.rescue-modal-backdrop.open')
            .forEach(m => m.classList.remove('open'));
});

// ── View Details ──
function openViewModal(u) {
    currentUser = u;
    const isOfficial = u.role === 'official';

    document.getElementById('view-avatar').style.background = isOfficial ? '#FEF9E7' : '#EBF5FB';
    document.getElementById('view-avatar').style.color      = isOfficial ? '#B7770D' : '#2980B9';
    document.getElementById('view-avatar').textContent      = u.initials;
    document.getElementById('view-name').textContent        = u.first_name + ' ' + u.last_name;
    document.getElementById('view-email').textContent       = u.email;
    document.getElementById('view-phone').textContent       = u.phone || '—';
    document.getElementById('view-address').textContent     = u.address_display || '—';
    document.getElementById('view-created').textContent     = u.created;
    document.getElementById('view-role-badge').innerHTML    =
        `<span class="role-badge-${u.role}">${u.role.charAt(0).toUpperCase()+u.role.slice(1)}</span>`;
    document.getElementById('view-status').innerHTML = u.is_active == 1
        ? '<span class="status-active">Active</span>'
        : '<span class="status-inactive">Inactive</span>';

    document.getElementById('view-gender-row').style.display = isOfficial ? 'none' : 'flex';
    document.getElementById('view-bday-row').style.display   = isOfficial ? 'none' : 'flex';
    document.getElementById('view-gender').textContent    = u.gender ? u.gender.charAt(0).toUpperCase()+u.gender.slice(1) : '—';
    document.getElementById('view-birthdate').textContent = u.birthdate || '—';

    const toggleBtn = document.getElementById('view-toggle-btn');
    toggleBtn.textContent      = u.is_active == 1 ? 'Deactivate' : 'Activate';
    toggleBtn.style.background = u.is_active == 1 ? '#B7770D' : '#1E8449';

    document.getElementById('view-delete-btn').style.display =
        (u.user_id == <?= $_SESSION['user_id'] ?>) ? 'none' : 'inline-flex';

    openModal('viewModal');
}

function switchToEdit()   { closeModal('viewModal'); openEditModal(currentUser); }
function switchToToggle() { closeModal('viewModal'); openToggleModal(currentUser); }
function switchToDelete() { closeModal('viewModal'); openDeleteModal(currentUser); }

// ── Edit ──
function openEditModal(u) {
    document.getElementById('edit-user-id').value    = u.user_id;
    document.getElementById('edit-role').value       = u.role;
    document.getElementById('edit-first-name').value = u.first_name;
    document.getElementById('edit-last-name').value  = u.last_name;
    document.getElementById('edit-email').value      = u.email;
    document.getElementById('edit-phone').value      = u.phone || '';

    const isOfficial = u.role === 'official';
    document.getElementById('edit-resident-fields').style.display = isOfficial ? 'none' : 'block';
    document.getElementById('edit-official-fields').style.display = isOfficial ? 'block' : 'none';

    if (isOfficial) {
        document.getElementById('edit-position').value = u.position || '';
    } else {
        document.getElementById('edit-house-no').value = u.house_no || '';
        document.getElementById('edit-street').value   = u.street   || '';
        document.getElementById('edit-area').value     = u.area     || '';
        document.getElementById('edit-birthdate').value  = u.birthdate  || '';
        document.getElementById('edit-gender').value     = u.gender     || '';
    }
    openModal('editModal');
}

// ── Toggle Status ──
function openToggleModal(u) {
    currentUser = u;
    document.getElementById('toggle-user-id').value = u.user_id;
    const deactivating = u.is_active == 1;
    document.getElementById('toggle-new-status').value = deactivating ? 0 : 1;

    if (deactivating) {
        document.getElementById('toggle-title').textContent       = 'Deactivate Account';
        document.getElementById('toggle-icon').style.background   = '#FEF9E7';
        document.getElementById('toggle-icon').style.color        = '#B7770D';
        document.getElementById('toggle-icon').innerHTML          = '&#128683;';
        document.getElementById('toggle-message').innerHTML       = `Deactivate <strong>${u.first_name} ${u.last_name}</strong>?`;
        document.getElementById('toggle-explanation').textContent = 'This account will not be able to log in until reactivated.';
        document.getElementById('toggle-note').textContent        = ' Their data is preserved and can be reactivated anytime.';
        document.getElementById('toggle-submit-btn').textContent  = 'Yes, Deactivate';
        document.getElementById('toggle-submit-btn').style.background = '#B7770D';
    } else {
        document.getElementById('toggle-title').textContent       = 'Activate Account';
        document.getElementById('toggle-icon').style.background   = '#EAFAF1';
        document.getElementById('toggle-icon').style.color        = '#1E8449';
        document.getElementById('toggle-icon').innerHTML          = '&#9989;';
        document.getElementById('toggle-message').innerHTML       = `Activate <strong>${u.first_name} ${u.last_name}</strong>?`;
        document.getElementById('toggle-explanation').textContent = 'This account will be able to log in and use the system again.';
        document.getElementById('toggle-note').textContent        = ' The account holder will regain full access immediately.';
        document.getElementById('toggle-submit-btn').textContent  = 'Yes, Activate';
        document.getElementById('toggle-submit-btn').style.background = '#1E8449';
    }
    openModal('toggleModal');
}

// ── Reset Password ──
function openResetModal(u) {
    currentUser = u;
    document.getElementById('reset-user-id').value         = u.user_id;
    document.getElementById('reset-name').textContent      = u.first_name + ' ' + u.last_name;
    document.getElementById('reset-email-display').textContent = u.email;

    const av = document.getElementById('reset-avatar');
    av.textContent      = u.initials;
    av.style.background = u.role === 'official' ? '#FEF9E7' : '#EBF5FB';
    av.style.color      = u.role === 'official' ? '#B7770D' : '#2980B9';

    const pwInput = document.getElementById('current-pw-display');
    pwInput.type  = 'password';
    pwInput.value = '';
    pwInput.dataset.loaded  = '0';
    pwInput.dataset.plainpw = '';
    document.getElementById('current-pw-eye').innerHTML    = '&#128065;';
    document.getElementById('new-pw-input').value          = '';
    document.getElementById('new-pw-input').type           = 'password';
    document.getElementById('new-pw-eye').innerHTML        = '&#128065;';
    document.getElementById('pw-strength-bar').style.width = '0';
    document.getElementById('pw-strength-label').textContent = 'Enter a password to check strength';
    document.getElementById('pw-strength-label').style.color = 'var(--rescue-muted)';

    openModal('resetModal');
}

function toggleCurrentPw() {
    const input = document.getElementById('current-pw-display');
    const eye   = document.getElementById('current-pw-eye');

    if (input.dataset.loaded !== '1') {
        fetch('<?= BASE_URL ?>/api/get_user_password.php?user_id=' + currentUser.user_id)
            .then(r => r.json())
            .then(data => {
                if (data.password) {
                    input.dataset.plainpw = data.password;
                    input.dataset.loaded  = '1';
                    input.type  = 'text';
                    input.value = data.password;
                    eye.innerHTML = '&#128064;';
                }
            });
    } else {
        const showing = input.type === 'text';
        input.type    = showing ? 'password' : 'text';
        input.value   = input.dataset.plainpw;
        eye.innerHTML = showing ? '&#128065;' : '&#128064;';
    }
}

function toggleNewPw() {
    const input = document.getElementById('new-pw-input');
    const eye   = document.getElementById('new-pw-eye');
    input.type    = input.type === 'password' ? 'text' : 'password';
    eye.innerHTML = input.type === 'text' ? '&#128064;' : '&#128065;';
}

document.getElementById('new-pw-input').addEventListener('input', function() {
    const val = this.value;
    const bar = document.getElementById('pw-strength-bar');
    const lbl = document.getElementById('pw-strength-label');

    if (!val) {
        bar.style.width   = '0';
        lbl.textContent   = 'Enter a password to check strength';
        lbl.style.color   = 'var(--rescue-muted)';
        return;
    }

    let s = 0;
    if (val.length >= 6)           s++;
    if (val.length >= 10)          s++;
    if (/[A-Z]/.test(val))         s++;
    if (/[0-9]/.test(val))         s++;
    if (/[^A-Za-z0-9]/.test(val))  s++;

    const levels = [
        { w:'20%',  color:'#E74C3C', label:'Very weak'   },
        { w:'40%',  color:'#E67E22', label:'Weak'        },
        { w:'60%',  color:'#F1C40F', label:'Fair'        },
        { w:'80%',  color:'#2ECC71', label:'Strong'      },
        { w:'100%', color:'#1E8449', label:'Very strong' },
    ];
    const lvl          = levels[Math.min(s - 1, 4)];
    bar.style.width      = lvl.w;
    bar.style.background = lvl.color;
    lbl.textContent      = lvl.label;
    lbl.style.color      = lvl.color;
});

// ── Delete ──
function openDeleteModal(u) {
    currentUser = u;
    document.getElementById('deleteUserId').value       = u.user_id;
    document.getElementById('deleteUserName').textContent = u.first_name + ' ' + u.last_name;
    openModal('deleteModal');
}

// ── Create form role toggle ──
function switchCreateRole(role) {
    document.getElementById('roleInput').value = role;
    document.getElementById('residentFields').style.display = role === 'resident' ? 'block' : 'none';
    document.getElementById('officialFields').style.display = role === 'official' ? 'block' : 'none';
    document.getElementById('btnResident').classList.toggle('active', role === 'resident');
    document.getElementById('btnOfficial').classList.toggle('active', role === 'official');
}

// ── Confirm Edit ──
function openConfirmEdit() {
    const firstName = document.getElementById('edit-first-name').value;
    const lastName  = document.getElementById('edit-last-name').value;
    document.getElementById('confirm-edit-name').textContent = firstName + ' ' + lastName;
    openModal('confirmEditModal');
}

function submitEditForm() {
    closeModal('confirmEditModal');
    // Find the edit form and submit it
    document.querySelector('#editModal form').submit();
}

// ── Confirm Reset ──
function openConfirmReset() {
    const newPw = document.getElementById('new-pw-input').value;
    if (!newPw || newPw.length < 6) {
        alert('Please enter a password of at least 6 characters first.');
        return;
    }
    document.getElementById('confirm-reset-name').textContent =
        document.getElementById('reset-name').textContent;
    openModal('confirmResetModal');
}

function submitResetForm() {
    closeModal('confirmResetModal');
    document.querySelector('#resetModal form').submit();
}

// ── Confirm Create ──
function openConfirmCreate() {
    const firstName = document.querySelector('#createModal [name="first_name"]').value.trim();
    const lastName  = document.querySelector('#createModal [name="last_name"]').value.trim();
    const email     = document.querySelector('#createModal [name="email"]').value.trim();
    const password  = document.querySelector('#createModal [name="password"]').value.trim();

    if (!firstName || !lastName) {
        alert('Please enter the full name first.');
        return;
    }
    if (!email) {
        alert('Please enter an email address.');
        return;
    }
    if (!password || password.length < 6) {
        alert('Please enter a password of at least 6 characters.');
        return;
    }

    document.getElementById('confirm-create-name').textContent = firstName + ' ' + lastName;
    openModal('confirmCreateModal');
}

function submitCreateForm() {
    closeModal('confirmCreateModal');
    document.querySelector('#createModal form').submit();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>