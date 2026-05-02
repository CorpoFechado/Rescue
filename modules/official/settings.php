<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/database.php';

require_role('official');

$pageTitle  = 'Settings';
$activePage = 'settings';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── SAVE SYSTEM SETTINGS ──
    if ($action === 'save_system_settings') {
        $settings = [
            'barangay_name'   => trim($_POST['barangay_name'] ?? ''),
            'contact_email'   => trim($_POST['contact_email'] ?? ''),
            'emergency_hotline' => trim($_POST['emergency_hotline'] ?? ''),
            'system_tagline'  => trim($_POST['system_tagline'] ?? ''),
        ];

        if (empty($settings['barangay_name'])) {
            $error = 'Barangay name is required.';
        } else {
            try {
                $stmtUpsert = $conn->prepare("
                    INSERT INTO system_settings (setting_key, setting_value)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                foreach ($settings as $key => $value) {
                    $stmtUpsert->execute([$key, $value]);
                }
                $success = 'System settings saved successfully.';
            } catch (Exception $e) {
                $error = 'Failed to save settings. Please try again.';
            }
        }
    }

    // ── ADD CONTACT ──
    if ($action === 'add_contact') {
        $name  = trim($_POST['name'] ?? '');
        $role  = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($phone)) {
            $error = 'Contact name and phone number are required.';
        } else {
            $conn->prepare("INSERT INTO emergency_contacts (name, role, phone, is_active) VALUES (?, ?, ?, 1)")
                 ->execute([$name, $role ?: null, $phone]);
            $success = 'Emergency contact "' . htmlspecialchars($name) . '" added.';
        }
    }

    // ── EDIT CONTACT ──
    if ($action === 'edit_contact') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $role      = trim($_POST['role'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($phone)) {
            $error = 'Contact name and phone number are required.';
        } elseif ($contactId) {
            $conn->prepare("UPDATE emergency_contacts SET name=?, role=?, phone=? WHERE contact_id=?")
                 ->execute([$name, $role ?: null, $phone, $contactId]);
            $success = 'Contact updated successfully.';
        }
    }

    // ── TOGGLE CONTACT ──
    if ($action === 'toggle_contact') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $newStatus = (int) ($_POST['new_status'] ?? 0);
        if ($contactId) {
            $conn->prepare("UPDATE emergency_contacts SET is_active=? WHERE contact_id=?")
                 ->execute([$newStatus, $contactId]);
            $success = $newStatus ? 'Contact activated.' : 'Contact deactivated.';
        }
    }

    // ── DELETE CONTACT ──
    if ($action === 'delete_contact') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        if ($contactId) {
            $conn->prepare("DELETE FROM emergency_contacts WHERE contact_id=?")->execute([$contactId]);
            $success = 'Contact deleted.';
        }
    }
}

// ── Fetch system settings ──
$stmtSettings = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$rawSettings  = $stmtSettings->fetchAll();
$settings     = [];
foreach ($rawSettings as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// ── Fetch contacts ──
$stmtContacts = $conn->query("SELECT * FROM emergency_contacts ORDER BY is_active DESC, name ASC");
$contacts     = $stmtContacts->fetchAll();

require_once __DIR__ . '/../../includes/header_official.php';
?>

<style>
    .settings-section {
        background: var(--rescue-white);
        border: 1px solid var(--rescue-border);
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: 1.75rem;
    }

    .settings-section-header {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid var(--rescue-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #FAFAFA;
    }

    .settings-section-header h2 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--rescue-text);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .settings-section-body { padding: 1.5rem; }

    .setting-row {
        display: grid;
        grid-template-columns: 200px 1fr;
        gap: 1rem;
        align-items: start;
        padding: 1rem 0;
        border-bottom: 1px solid var(--rescue-border);
    }

    .setting-row:last-child { border-bottom: none; padding-bottom: 0; }
    .setting-row:first-child { padding-top: 0; }

    .setting-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--rescue-text);
        padding-top: 0.65rem;
    }

    .setting-desc {
        font-size: 0.75rem;
        color: var(--rescue-muted);
        margin-top: 3px;
        font-weight: 400;
    }

    @media (max-width: 767.98px) {
        .setting-row {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
        .setting-label { padding-top: 0; }
    }

    /* Contacts table */
    .contacts-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .contacts-table th {
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

    .contacts-table td {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--rescue-border);
        vertical-align: middle;
    }

    .contacts-table tr:last-child td { border-bottom: none; }
    .contacts-table tr:hover td { background: #FAFAFA; }

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

    .action-btn:hover   { background: #F5F5F5; }
    .action-btn.warning { color:#B7770D; border-color:#FAD7A0; }
    .action-btn.warning:hover { background:#FEF9E7; }
    .action-btn.success { color:#1E8449; border-color:#A9DFBF; }
    .action-btn.success:hover { background:#EAFAF1; }
    .action-btn.danger  { color:var(--rescue-red); border-color:var(--rescue-red-light); }
    .action-btn.danger:hover  { background:var(--rescue-red-light); }

    /* Modals */
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

    @media (max-width: 767.98px) {
        .contacts-table thead { display: none; }
        .contacts-table tr {
            display: block;
            border: 1px solid var(--rescue-border);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .contacts-table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid #F5F5F5;
            font-size: 0.82rem;
            flex-wrap: wrap;
            gap: 6px;
        }
        .contacts-table td:last-child { border-bottom: none; }
        .contacts-table td::before {
            content: attr(data-label);
            font-weight: 700;
            color: var(--rescue-muted);
            font-size: 0.75rem;
            width: 100%;
        }
    }
</style>

<!-- Page header -->
<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.5rem; font-weight:700; color:var(--rescue-text); margin:0 0 4px;">Settings</h1>
    <p style="font-size:0.85rem; color:var(--rescue-muted); margin:0;">Manage system preferences and emergency contacts</p>
</div>

<!-- Feedback -->
<?php if ($success): ?>
<div style="background:#EAFAF1; border:1px solid #A9DFBF; color:#1E8449; border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem; display:flex; align-items:center; gap:8px;">
    &#9989; <?= $success ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div style="background:var(--rescue-red-light); border:1px solid #F1948A; color:var(--rescue-red-dark); border-radius:10px; padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.85rem; display:flex; align-items:center; gap:8px;">
    &#9888; <?= $error ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════
     SECTION 1: SYSTEM SETTINGS
═══════════════════════════════════ -->
<div class="settings-section">
    <div class="settings-section-header">
        <h2>&#9881; System Settings</h2>
        <span style="font-size:0.78rem; color:var(--rescue-muted);">Displayed across the system</span>
    </div>
    <div class="settings-section-body">
        <form method="POST">
            <input type="hidden" name="action" value="save_system_settings">

            <div class="setting-row">
                <div class="setting-label">
                    Barangay Name
                    <div class="setting-desc">Displayed in headers and reports</div>
                </div>
                <input type="text" name="barangay_name" class="form-control"
                    value="<?= htmlspecialchars($settings['barangay_name'] ?? 'Barangay 12') ?>"
                    placeholder="e.g. Barangay 12" required>
            </div>

            <div class="setting-row">
                <div class="setting-label">
                    System Tagline
                    <div class="setting-desc">Short description shown on the landing page</div>
                </div>
                <input type="text" name="system_tagline" class="form-control"
                    value="<?= htmlspecialchars($settings['system_tagline'] ?? 'Risk Reduction & Emergency Management') ?>"
                    placeholder="e.g. Risk Reduction & Emergency Management">
            </div>

            <div class="setting-row">
                <div class="setting-label">
                    Contact Email
                    <div class="setting-desc">Official barangay email address</div>
                </div>
                <input type="email" name="contact_email" class="form-control"
                    value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>"
                    placeholder="rescue@barangay.gov.ph">
            </div>

            <div class="setting-row">
                <div class="setting-label">
                    Emergency Hotline
                    <div class="setting-desc">Primary barangay emergency number</div>
                </div>
                <input type="text" name="emergency_hotline" class="form-control"
                    value="<?= htmlspecialchars($settings['emergency_hotline'] ?? '') ?>"
                    placeholder="e.g. (043) 123-4567">
            </div>

            <!-- Save button -->
            <div style="display:flex; justify-content:flex-end; margin-top:1.5rem; padding-top:1rem; border-top:1px solid var(--rescue-border);">
                <button type="submit" class="btn-rescue">
                    Save System Settings
                </button>
            </div>

        </form>
    </div>
</div>


<!-- ══════════════════════════════════
     SECTION 2: EMERGENCY CONTACTS
═══════════════════════════════════ -->
<div class="settings-section">
    <div class="settings-section-header">
        <h2>&#128222; Emergency Contacts</h2>
        <button class="btn-rescue" style="font-size:0.8rem; padding:0.4rem 1rem;" onclick="openModal('addContactModal')">
            + Add Contact
        </button>
    </div>

    <?php if (empty($contacts)): ?>
    <div style="text-align:center; padding:2.5rem 1rem; color:var(--rescue-muted); font-size:0.85rem;">
        No emergency contacts yet. Click "Add Contact" to get started.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="contacts-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role / Description</th>
                    <th>Phone Number</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contacts as $c):
                $cData = json_encode([
                    'contact_id' => $c['contact_id'],
                    'name'       => $c['name'],
                    'role'       => $c['role'] ?? '',
                    'phone'      => $c['phone'],
                    'is_active'  => $c['is_active'],
                ], JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>
            <tr>
                <td data-label="Name">
                    <span style="font-weight:600; color:var(--rescue-text);">
                        <?= htmlspecialchars($c['name']) ?>
                    </span>
                </td>
                <td data-label="Role" style="color:var(--rescue-muted);">
                    <?= htmlspecialchars($c['role'] ?? '—') ?>
                </td>
                <td data-label="Phone">
                    <a href="tel:<?= htmlspecialchars($c['phone']) ?>"
                        style="color:var(--rescue-red); font-weight:700; text-decoration:none; font-size:0.85rem;">
                        <?= htmlspecialchars($c['phone']) ?>
                    </a>
                </td>
                <td data-label="Status">
                    <span style="font-size:0.72rem; font-weight:700; padding:3px 9px; border-radius:20px;
                        background:<?= $c['is_active'] ? '#EAFAF1' : '#F2F3F4' ?>;
                        color:<?= $c['is_active'] ? '#1E8449' : 'var(--rescue-muted)' ?>;">
                        <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td data-label="Actions">
                    <div style="display:flex; gap:5px; flex-wrap:wrap;">
                        <button class="action-btn" onclick='openEditContactModal(<?= $cData ?>)'>Edit</button>
                        <button class="action-btn <?= $c['is_active'] ? 'warning' : 'success' ?>"
                            onclick='openToggleContactModal(<?= $cData ?>)'>
                            <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                        <form method="POST" id="delContact<?= $c['contact_id'] ?>">
                            <input type="hidden" name="action" value="delete_contact">
                            <input type="hidden" name="contact_id" value="<?= $c['contact_id'] ?>">
                            <button type="button" class="action-btn danger"
                                onclick="confirmDeleteContact(<?= $c['contact_id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════
     ADD CONTACT MODAL
═════════════════════ -->
<div class="rescue-modal-backdrop" id="addContactModal">
    <div class="rescue-modal" style="max-width:420px;">
        <div class="rescue-modal-header">
            <h5>Add Emergency Contact</h5>
            <button onclick="closeModal('addContactModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_contact">
            <div class="rescue-modal-body">

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Contact Name *</label>
                    <input type="text" name="name" class="form-control" required
                        placeholder="e.g. Police Station, Fire Station">
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Role / Description</label>
                    <input type="text" name="role" class="form-control"
                        placeholder="e.g. Law Enforcement, Emergency Medical">
                    <small style="font-size:0.75rem; color:var(--rescue-muted);">
                        Optional — shown beside the contact name.
                    </small>
                </div>

                <div>
                    <label class="form-label">Phone Number *</label>
                    <input type="text" name="phone" class="form-control" required
                        placeholder="e.g. (043) 123-4567 or 09XX-XXX-XXXX">
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('addContactModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">Add Contact</button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════
     EDIT CONTACT MODAL
════════════════════ -->
<div class="rescue-modal-backdrop" id="editContactModal">
    <div class="rescue-modal" style="max-width:420px;">
        <div class="rescue-modal-header">
            <h5>Edit Emergency Contact</h5>
            <button onclick="closeModal('editContactModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_contact">
            <input type="hidden" name="contact_id" id="editContactId">
            <div class="rescue-modal-body">

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Contact Name *</label>
                    <input type="text" name="name" id="editContactName" class="form-control" required>
                </div>

                <div style="margin-bottom:1rem;">
                    <label class="form-label">Role / Description</label>
                    <input type="text" name="role" id="editContactRole" class="form-control">
                </div>

                <div>
                    <label class="form-label">Phone Number *</label>
                    <input type="text" name="phone" id="editContactPhone" class="form-control" required>
                </div>

            </div>
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('editContactModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<!-- ════════════════════════
     TOGGLE CONTACT MODAL
═════════════════════════ -->
<div class="rescue-modal-backdrop" id="toggleContactModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:380px;">
        <div class="rescue-modal-header">
            <h5 id="toggleContactTitle">Deactivate Contact</h5>
            <button onclick="closeModal('toggleContactModal')"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--rescue-muted);">&#x2715;</button>
        </div>
        <div class="rescue-modal-body" style="text-align:center;">
            <div id="toggleContactIcon" style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.5rem;"></div>
            <p style="font-size:0.9rem;font-weight:600;color:var(--rescue-text);margin-bottom:0.5rem;" id="toggleContactMessage"></p>
            <p style="font-size:0.82rem;color:var(--rescue-muted);line-height:1.6;margin:0;" id="toggleContactExplanation"></p>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_contact">
            <input type="hidden" name="contact_id" id="toggleContactId">
            <input type="hidden" name="new_status" id="toggleContactNewStatus">
            <div class="rescue-modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('toggleContactModal')">Cancel</button>
                <button type="submit" class="btn-modal-submit" id="toggleContactSubmitBtn"></button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════════
     DELETE CONTACT MODAL
════════════════════════ -->
<div class="rescue-modal-backdrop" id="deleteContactModal" style="z-index:10000;">
    <div class="rescue-modal" style="max-width:360px;">
        <div class="rescue-modal-body" style="text-align:center; padding:2rem 1.5rem;">
            <div style="width:56px;height:56px;background:var(--rescue-red-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.4rem;color:var(--rescue-red);">
                &#128465;
            </div>
            <h5 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Delete Contact</h5>
            <p style="font-size:0.85rem;color:var(--rescue-muted);margin:0;line-height:1.6;">
                Are you sure you want to delete
                <strong id="deleteContactName" style="color:var(--rescue-text);"></strong>?
                This will remove them from the emergency contacts list.
            </p>
        </div>
        <div class="rescue-modal-footer" style="justify-content:center; gap:12px;">
            <button type="button" class="btn-modal-cancel" style="flex:1;"
                onclick="closeModal('deleteContactModal')">Cancel</button>
            <button type="button" class="btn-modal-submit" style="flex:1;"
                id="deleteContactConfirmBtn">Yes, Delete</button>
        </div>
    </div>
</div>


<script>
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

// ── Edit Contact ──
function openEditContactModal(c) {
    document.getElementById('editContactId').value    = c.contact_id;
    document.getElementById('editContactName').value  = c.name;
    document.getElementById('editContactRole').value  = c.role || '';
    document.getElementById('editContactPhone').value = c.phone;
    openModal('editContactModal');
}

// ── Toggle Contact ──
function openToggleContactModal(c) {
    document.getElementById('toggleContactId').value = c.contact_id;
    const deactivating = c.is_active == 1;
    document.getElementById('toggleContactNewStatus').value = deactivating ? 0 : 1;

    if (deactivating) {
        document.getElementById('toggleContactTitle').textContent       = 'Deactivate Contact';
        document.getElementById('toggleContactIcon').style.background   = '#FEF9E7';
        document.getElementById('toggleContactIcon').style.color        = '#B7770D';
        document.getElementById('toggleContactIcon').innerHTML          = '&#128683;';
        document.getElementById('toggleContactMessage').innerHTML       = `Deactivate <strong>${c.name}</strong>?`;
        document.getElementById('toggleContactExplanation').textContent = 'This contact will no longer appear on the public emergency contacts list. You can reactivate it anytime.';
        document.getElementById('toggleContactSubmitBtn').textContent   = 'Yes, Deactivate';
        document.getElementById('toggleContactSubmitBtn').style.background = '#B7770D';
    } else {
        document.getElementById('toggleContactTitle').textContent       = 'Activate Contact';
        document.getElementById('toggleContactIcon').style.background   = '#EAFAF1';
        document.getElementById('toggleContactIcon').style.color        = '#1E8449';
        document.getElementById('toggleContactIcon').innerHTML          = '&#9989;';
        document.getElementById('toggleContactMessage').innerHTML       = `Activate <strong>${c.name}</strong>?`;
        document.getElementById('toggleContactExplanation').textContent = 'This contact will appear on the public emergency contacts list immediately.';
        document.getElementById('toggleContactSubmitBtn').textContent   = 'Yes, Activate';
        document.getElementById('toggleContactSubmitBtn').style.background = '#1E8449';
    }

    openModal('toggleContactModal');
}

// ── Delete Contact ──
function confirmDeleteContact(contactId, name) {
    document.getElementById('deleteContactName').textContent     = name;
    document.getElementById('deleteContactConfirmBtn').onclick   = function() {
        document.getElementById('delContact' + contactId).submit();
    };
    openModal('deleteContactModal');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer_official.php'; ?>