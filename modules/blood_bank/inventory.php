<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

$allowed_roles = ['admin', 'doctor', 'nurse', 'lab_tech'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Blood Inventory & Donors";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Add Donor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_donor'])) {
    try {
        db_insert('blood_donors', [
            'name'               => trim($_POST['name']),
            'blood_group'        => $_POST['blood_group'],
            'age'                => $_POST['age'],
            'gender'             => $_POST['gender'],
            'contact_number'     => trim($_POST['contact_number']),
            'email'              => trim($_POST['email']),
            'last_donation_date' => $_POST['last_donation_date'] ?: null
        ]);
        $success_msg = "Donor registered successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Handle Add Stock (with optional donor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    try {
        $stock_data = [
            'blood_group' => $_POST['blood_group'],
            'quantity'    => $_POST['quantity'],
            'expiry_date' => $_POST['expiry_date'],
            'status'      => 'available'
        ];
        // Link donor if selected
        if (!empty($_POST['donor_id'])) {
            $stock_data['donor_id'] = $_POST['donor_id'];
            // Also update donor's last donation date
            db_query(
                "UPDATE blood_donors SET last_donation_date = CURRENT_DATE WHERE id = $1",
                [$_POST['donor_id']]
            );
        }
        db_insert('blood_inventory', $stock_data);
        $success_msg = "Blood stock added successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Auto-expire stock past expiry date
db_query("UPDATE blood_inventory SET status = 'expired' WHERE status = 'available' AND expiry_date < CURRENT_DATE");

// Fetch data — join donor name for inventory display
$donors      = db_select("SELECT * FROM blood_donors ORDER BY name");
$stock_items = db_select("
    SELECT bi.*, bd.name AS donor_name
    FROM blood_inventory bi
    LEFT JOIN blood_donors bd ON bi.donor_id = bd.id
    WHERE bi.status IN ('available', 'expired')
    ORDER BY bi.status ASC, bi.expiry_date ASC
");
?>


<div class="main-content">
    <div class="page-header" style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/blood_bank/dashboard.php" class="bb-btn bb-btn-ghost" style="margin-bottom:10px; display:inline-flex;">
                <i class="fas fa-arrow-left"></i> Blood Bank Dashboard
            </a>
            <h1 style="margin:0; font-size: 1.6rem;"><i class="fas fa-tint" style="color:#e53e3e;"></i> Blood Inventory &amp; Donors</h1>
            <p style="margin:4px 0 0; color:#6b7280; font-size:0.9em;">Manage blood stock and registered donors</p>
        </div>
        <div style="display:flex; gap:10px;">
            <button class="bb-btn bb-btn-primary" onclick="showModal('stockModal')">
                <i class="fas fa-plus"></i> Add Blood Stock
            </button>
            <button class="bb-btn bb-btn-secondary" onclick="showModal('donorModal')">
                <i class="fas fa-user-plus"></i> Register Donor
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="bb-alert bb-alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="bb-alert bb-alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="bb-tabs">
        <button class="bb-tab active" onclick="openTab(event,'inventory')"><i class="fas fa-boxes"></i> Current Inventory</button>
        <button class="bb-tab"        onclick="openTab(event,'donors')">  <i class="fas fa-users"></i> Donor List</button>
    </div>

    <!-- Inventory Tab -->
    <div id="inventory" class="bb-tab-content" style="display:block;">
        <div class="bb-card">
            <?php if (empty($stock_items)): ?>
                <div class="bb-empty"><i class="fas fa-box-open"></i><p>No blood stock available.</p></div>
            <?php else: ?>
            <div style="margin-bottom: 14px;">
                <input type="text" id="filter-blood" onkeyup="filterTable('filter-blood','tbl-blood')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
            </div>
            <div class="bb-table-wrap">
                <table id="tbl-blood" class="bb-table">
                    <thead>
                        <tr>
                            <th>Blood Group</th>
                            <th>Qty (Units)</th>
                            <th>Donor</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_items as $item): 
                            $expired = strtotime($item['expiry_date']) < time();
                            $expiring_soon = !$expired && strtotime($item['expiry_date']) < strtotime('+7 days');
                        ?>
                        <tr>
                            <td>
                                <span class="bb-blood-badge"><?php echo htmlspecialchars($item['blood_group']); ?></span>
                            </td>
                            <td><strong><?php echo $item['quantity']; ?></strong></td>
                            <td>
                                <?php if ($item['donor_name']): ?>
                                    <span class="bb-donor-tag"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($item['donor_name']); ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:0.85em;">— External</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?php echo $expired ? 'bb-expired' : ($expiring_soon ? 'bb-expiring' : ''); ?>">
                                    <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                    <?php if ($expired): ?><span class="bb-tag-pill red">Expired</span><?php endif; ?>
                                    <?php if ($expiring_soon): ?><span class="bb-tag-pill orange">Soon</span><?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($item['status'] === 'expired'): ?>
                                    <span class="bb-tag-pill red">Unavailable</span>
                                <?php else: ?>
                                    <span class="bb-tag-pill green">Available</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Donors Tab -->
    <div id="donors" class="bb-tab-content" style="display:none;">
        <div class="bb-card">
            <?php if (empty($donors)): ?>
                <div class="bb-empty"><i class="fas fa-user-slash"></i><p>No donors registered yet.</p></div>
            <?php else: ?>
            <div style="margin-bottom: 14px;">
                <input type="text" id="filter-blood-donors" onkeyup="filterTable('filter-blood-donors','tbl-blood-donors')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
            </div>
            <div class="bb-table-wrap">
                <table id="tbl-blood-donors" class="bb-table">
                    <thead>
                        <tr>
                            <th>Donor</th>
                            <th>Blood Group</th>
                            <th>Age / Gender</th>
                            <th>Contact</th>
                            <th>Last Donation</th>
                            <th>Eligible</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($donors as $d):
                            $last = $d['last_donation_date'];
                            // Eligible if no donation in last 90 days
                            $eligible = !$last || strtotime($last) < strtotime('-90 days');
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div class="bb-avatar"><?php echo strtoupper(substr($d['name'],0,1)); ?></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                                        <?php if (!empty($d['email'])): ?><br><small style="color:#6b7280;"><?php echo htmlspecialchars($d['email']); ?></small><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><span class="bb-blood-badge"><?php echo $d['blood_group']; ?></span></td>
                            <td><?php echo $d['age']; ?> / <?php echo $d['gender']; ?></td>
                            <td><?php echo htmlspecialchars($d['contact_number']); ?></td>
                            <td><?php echo $last ? date('M d, Y', strtotime($last)) : '<span style="color:#9ca3af;">Never</span>'; ?></td>
                            <td>
                                <?php if ($eligible): ?>
                                    <span class="bb-tag-pill green"><i class="fas fa-check"></i> Eligible</span>
                                <?php else: ?>
                                    <span class="bb-tag-pill orange">Waiting</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== ADD BLOOD STOCK MODAL ===== -->
<div id="stockModal" class="bb-modal-overlay" onclick="overlayClose(event,'stockModal')">
    <div class="bb-modal">
        <div class="bb-modal-header">
            <div>
                <h3><i class="fas fa-tint" style="color:#e53e3e;"></i> Add Blood Stock</h3>
                <p>Record a new blood donation to inventory</p>
            </div>
            <button class="bb-close-btn" onclick="closeModal('stockModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="bb-modal-body">

            <!-- Donor Section -->
            <div class="bb-form-section">
                <div class="bb-section-label"><i class="fas fa-user-circle"></i> Donor (Optional)</div>
                <div class="bb-form-group">
                    <label>Select Registered Donor</label>
                    <select name="donor_id" id="donor_select" onchange="autofillBloodGroup()" class="bb-select">
                        <option value="">— External / Walk-in Donation</option>
                        <?php foreach ($donors as $d): ?>
                            <option value="<?php echo $d['id']; ?>"
                                    data-group="<?php echo $d['blood_group']; ?>"
                                    data-name="<?php echo htmlspecialchars($d['name']); ?>">
                                <?php echo htmlspecialchars($d['name']); ?> — <?php echo $d['blood_group']; ?>
                                <?php if ($d['last_donation_date']): ?>
                                    (Last: <?php echo date('M d', strtotime($d['last_donation_date'])); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="donor-preview" style="display:none;" class="bb-donor-preview">
                    <i class="fas fa-user-check" style="color:#10b981;"></i>
                    <span id="donor-preview-text"></span>
                </div>
            </div>

            <!-- Blood Details -->
            <div class="bb-form-section">
                <div class="bb-section-label"><i class="fas fa-flask"></i> Blood Details</div>
                <div class="bb-form-row">
                    <div class="bb-form-group">
                        <label>Blood Group <span class="bb-required">*</span></label>
                        <select name="blood_group" id="stock_blood_group" required class="bb-select">
                            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                                <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bb-form-group">
                        <label>Quantity (Units) <span class="bb-required">*</span></label>
                        <input type="number" name="quantity" value="1" min="1" required class="bb-input">
                    </div>
                <div class="bb-form-row">
                    <div class="bb-form-group">
                        <label>Component Type <span class="bb-required">*</span></label>
                        <select name="component" id="stock_component" class="bb-select" onchange="predictExpiry()">
                            <option value="Whole Blood">Whole Blood</option>
                            <option value="PRBC">PRBC</option>
                            <option value="Platelets">Platelets</option>
                            <option value="Plasma">Plasma</option>
                        </select>
                    </div>
                    <div class="bb-form-group">
                        <label>Collection Date <span class="bb-required">*</span></label>
                        <input type="date" name="collection_date" id="stock_collection_date" class="bb-input"
                               value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="predictExpiry()">
                    </div>
                </div>
                <div class="bb-form-group">
                    <label>Expiry Date (AI Predicted) <span class="bb-required">*</span></label>
                    <input type="date" name="expiry_date" id="stock_expiry_date" required class="bb-input"
                           min="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo date('Y-m-d', strtotime('+42 days')); ?>">
                </div>
            </div>

            <div class="bb-modal-footer">
                <button type="button" onclick="closeModal('stockModal')" class="bb-btn bb-btn-ghost">Cancel</button>
                <button type="submit" name="add_stock" class="bb-btn bb-btn-danger">
                    <i class="fas fa-plus"></i> Add to Inventory
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== REGISTER DONOR MODAL ===== -->
<div id="donorModal" class="bb-modal-overlay" onclick="overlayClose(event,'donorModal')">
    <div class="bb-modal">
        <div class="bb-modal-header">
            <div>
                <h3><i class="fas fa-user-plus" style="color:#2563eb;"></i> Register Donor</h3>
                <p>Add a new blood donor to the system</p>
            </div>
            <button class="bb-close-btn" onclick="closeModal('donorModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="bb-modal-body">
            <div class="bb-form-group">
                <label>Full Name <span class="bb-required">*</span></label>
                <input type="text" name="name" required class="bb-input" placeholder="e.g. John Doe">
            </div>
            <div class="bb-form-row">
                <div class="bb-form-group">
                    <label>Blood Group <span class="bb-required">*</span></label>
                    <select name="blood_group" required class="bb-select">
                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                            <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bb-form-group">
                    <label>Age <span class="bb-required">*</span></label>
                    <input type="number" name="age" required class="bb-input" min="18" max="65" placeholder="18–65">
                </div>
                <div class="bb-form-group">
                    <label>Gender <span class="bb-required">*</span></label>
                    <select name="gender" class="bb-select">
                        <option>Male</option><option>Female</option><option>Other</option>
                    </select>
                </div>
            </div>
            <div class="bb-form-row">
                <div class="bb-form-group">
                    <label>Contact Number <span class="bb-required">*</span></label>
                    <input type="tel" name="contact_number" required class="bb-input" placeholder="+91 XXXXXXXXXX">
                </div>
                <div class="bb-form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="bb-input" placeholder="Optional">
                </div>
            </div>
            <div class="bb-form-group">
                <label>Last Donation Date</label>
                <input type="date" name="last_donation_date" class="bb-input" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="bb-modal-footer">
                <button type="button" onclick="closeModal('donorModal')" class="bb-btn bb-btn-ghost">Cancel</button>
                <button type="submit" name="add_donor" class="bb-btn bb-btn-primary">
                    <i class="fas fa-user-plus"></i> Register Donor
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* ---- Blood Bank Page Styles ---- */
.bb-card { background:#fff; border-radius:14px; box-shadow:0 1px 8px rgba(0,0,0,0.07); overflow:hidden; }
.bb-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid #e5e7eb; padding-bottom:0; }
.bb-tab { padding:10px 22px; background:none; border:none; font-size:0.95rem; cursor:pointer; border-bottom:3px solid transparent; color:#6b7280; display:flex; align-items:center; gap:7px; transition:all 0.2s; margin-bottom:-2px; }
.bb-tab:hover { color:#111; }
.bb-tab.active { border-bottom-color:#e53e3e; color:#e53e3e; font-weight:600; }

.bb-table-wrap { overflow-x:auto; }
.bb-table { width:100%; border-collapse:collapse; }
.bb-table th { background:#f9fafb; padding:12px 16px; text-align:left; font-size:0.82em; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; border-bottom:1px solid #e5e7eb; }
.bb-table td { padding:14px 16px; border-bottom:1px solid #f3f4f6; font-size:0.9em; vertical-align:middle; }
.bb-table tr:last-child td { border:none; }
.bb-table tr:hover td { background:#fafafa; }

.bb-blood-badge { background:linear-gradient(135deg,#e53e3e,#c53030); color:#fff; padding:4px 12px; border-radius:20px; font-weight:700; font-size:0.9em; display:inline-block; }
.bb-donor-tag { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:3px 10px; border-radius:20px; font-size:0.82em; display:inline-flex; align-items:center; gap:5px; }
.bb-tag-pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.78em; font-weight:600; }
.bb-tag-pill.green { background:#dcfce7; color:#15803d; }
.bb-tag-pill.red { background:#fee2e2; color:#b91c1c; }
.bb-tag-pill.orange { background:#fef3c7; color:#92400e; }
.bb-expired { color:#b91c1c; }
.bb-expiring { color:#b45309; }
.bb-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#e53e3e,#c53030); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1em; flex-shrink:0; }
.bb-empty { padding:60px 20px; text-align:center; color:#9ca3af; }
.bb-empty i { font-size:2.5rem; margin-bottom:10px; display:block; opacity:0.4; }

/* Buttons */
.bb-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:8px; border:none; font-size:0.9em; font-weight:600; cursor:pointer; transition:all 0.2s; }
.bb-btn-primary { background:#2563eb; color:#fff; } .bb-btn-primary:hover { background:#1d4ed8; }
.bb-btn-secondary { background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; } .bb-btn-secondary:hover { background:#e5e7eb; }
.bb-btn-danger { background:#e53e3e; color:#fff; } .bb-btn-danger:hover { background:#c53030; }
.bb-btn-ghost { background:transparent; color:#6b7280; border:1px solid #e5e7eb; } .bb-btn-ghost:hover { background:#f9fafb; }

/* Alerts */
.bb-alert { padding:12px 18px; border-radius:10px; margin-bottom:18px; display:flex; align-items:center; gap:10px; font-size:0.9em; }
.bb-alert-success { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.bb-alert-danger { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }

/* Modal */
.bb-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:2000; overflow-y:auto; padding:20px; display:none; align-items:flex-start; justify-content:center; }
.bb-modal-overlay.open { display:flex; }
.bb-modal { background:#fff; border-radius:16px; width:100%; max-width:560px; margin:auto; box-shadow:0 20px 60px rgba(0,0,0,0.2); overflow:hidden; }
.bb-modal-header { padding:22px 24px 16px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:flex-start; }
.bb-modal-header h3 { margin:0 0 4px; font-size:1.15rem; display:flex; align-items:center; gap:8px; }
.bb-modal-header p { margin:0; color:#6b7280; font-size:0.85em; }
.bb-close-btn { background:none; border:none; font-size:1.1rem; color:#9ca3af; cursor:pointer; padding:4px 8px; border-radius:6px; }
.bb-close-btn:hover { background:#f3f4f6; color:#374151; }
.bb-modal-body { padding:20px 24px; }
.bb-modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:22px; padding-top:16px; border-top:1px solid #f3f4f6; }

/* Form elements */
.bb-form-section { margin-bottom:20px; padding:16px; background:#f9fafb; border-radius:10px; border:1px solid #f3f4f6; }
.bb-section-label { font-size:0.8em; font-weight:700; text-transform:uppercase; color:#6b7280; letter-spacing:0.5px; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.bb-form-group { margin-bottom:14px; }
.bb-form-group:last-child { margin-bottom:0; }
.bb-form-group label { display:block; font-size:0.85em; font-weight:600; color:#374151; margin-bottom:5px; }
.bb-form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.bb-form-row.cols-3 { grid-template-columns:1fr 1fr 1fr; }
.bb-input, .bb-select { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.9em; color:#111; background:#fff; box-sizing:border-box; transition:border 0.2s; }
.bb-input:focus, .bb-select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
.bb-required { color:#e53e3e; }
.bb-hint { color:#9ca3af; font-size:0.78em; margin-top:4px; display:block; }
.bb-donor-preview { display:flex; align-items:center; gap:8px; padding:10px 12px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:8px; font-size:0.88em; color:#065f46; margin-top:8px; }
</style>

<script>
function openTab(evt, tabName) {
    document.querySelectorAll('.bb-tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.bb-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(tabName).style.display = 'block';
    evt.currentTarget.classList.add('active');
}

function showModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('open'), 10);
}

function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('open');
    m.style.display = 'none';
}

function overlayClose(event, id) {
    if (event.target === document.getElementById(id)) closeModal(id);
}

// Auto-fill blood group from donor selection
function autofillBloodGroup() {
    const sel = document.getElementById('donor_select');
    const opt = sel.options[sel.selectedIndex];
    const preview = document.getElementById('donor-preview');
    const previewText = document.getElementById('donor-preview-text');

    if (sel.value) {
        const group = opt.getAttribute('data-group');
        const name  = opt.getAttribute('data-name');
        document.getElementById('stock_blood_group').value = group;
        previewText.textContent = name + ' — ' + group + ' selected';
        preview.style.display = 'flex';
    } else {
        preview.style.display = 'none';
    }
}

// Predict Expiry Date via AI
function predictExpiry() {
    const comp = document.getElementById('stock_component').value;
    const colDate = document.getElementById('stock_collection_date').value;
    const expInput = document.getElementById('stock_expiry_date');
    const hint = document.getElementById('expiry_hint');
    
    if(!colDate) return;
    
    hint.innerHTML = '<i class="fas fa-spinner fa-spin text-primary"></i> AI calculating...';
    
    fetch('api_predict_blood_expiry.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ component: comp, collection_date: colDate })
    })
    .then(r => r.json())
    .then(data => {
        if(data.expiry_date) {
            expInput.value = data.expiry_date;
            expInput.min = colDate; // Reset min just in case
            hint.innerHTML = `<i class="fas fa-check-circle text-success"></i> Based on <strong>${data.calculated_days} day(s)</strong> shelf-life for ${comp}`;
        }
    })
    .catch(err => {
        console.error(err);
        hint.innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i> AI unavailable. Falling back to manual entry.';
    });
}

// Close modal on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.bb-modal-overlay').forEach(m => {
            if (m.classList.contains('open')) closeModal(m.id);
        });
    }
});

// Auto-open stock modal if successfully added
<?php if ($success_msg): ?>
// success — keep closed
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>
