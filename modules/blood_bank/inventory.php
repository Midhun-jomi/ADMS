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
            'name' => $_POST['name'],
            'blood_group' => $_POST['blood_group'],
            'age' => $_POST['age'],
            'gender' => $_POST['gender'],
            'contact_number' => $_POST['contact_number'],
            'email' => $_POST['email'],
            'last_donation_date' => $_POST['last_donation_date'] ?: null
        ]);
        $success_msg = "Donor registered successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Handle Add Stock (Donation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock'])) {
    try {
        db_insert('blood_inventory', [
            'blood_group' => $_POST['blood_group'],
            'quantity' => $_POST['quantity'],
            'expiry_date' => $_POST['expiry_date'],
            'status' => 'available'
        ]);
        // Ideally link this to donor if selected, but simple inventory for now
        $success_msg = "Blood stock added successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$donors = db_select("SELECT * FROM blood_donors ORDER BY name");
$stock_items = db_select("SELECT * FROM blood_inventory WHERE status = 'available' ORDER BY expiry_date ASC");

?>

<div class="main-content">
    <div class="page-header">
        <h1>Inventory & Donor Management</h1>
        <div>
            <button class="btn-primary" onclick="showModal('stockModal')">
                <i class="fas fa-plus"></i> Add Blood Stock
            </button>
            <button class="btn-secondary" onclick="showModal('donorModal')">
                <i class="fas fa-user-plus"></i> Register Donor
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="openTab(event, 'inventory')">Current Inventory</button>
        <button class="tab-btn" onclick="openTab(event, 'donors')">Donors List</button>
    </div>

    <div id="inventory" class="tab-content" style="display: block;">
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Blood Group</th>
                            <th>Quantity (Units)</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_items as $item): ?>
                        <tr>
                            <td><span class="badge badge-danger"><?php echo $item['blood_group']; ?></span></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td>
                                <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                <?php if (strtotime($item['expiry_date']) < time()): ?>
                                    <span class="text-danger">(Expired)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ucfirst($item['status']); ?></td>
                            <td><button class="btn-icon text-danger"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="donors" class="tab-content" style="display: none;">
        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Group</th>
                            <th>Age/Gender</th>
                            <th>Contact</th>
                            <th>Last Donation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($donors as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['name']); ?></td>
                            <td><strong><?php echo $d['blood_group']; ?></strong></td>
                            <td><?php echo $d['age']; ?> / <?php echo $d['gender']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($d['contact_number']); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($d['email']); ?></small>
                            </td>
                            <td><?php echo $d['last_donation_date'] ? date('Y-m-d', strtotime($d['last_donation_date'])) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div id="stockModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('stockModal')">&times;</span>
        <h3>Add Blood Stock</h3>
        <form method="POST">
            <div class="form-group">
                <label>Blood Group</label>
                <select name="blood_group" required>
                    <option value="A+">A+</option><option value="A-">A-</option>
                    <option value="B+">B+</option><option value="B-">B-</option>
                    <option value="AB+">AB+</option><option value="AB-">AB-</option>
                    <option value="O+">O+</option><option value="O-">O-</option>
                </select>
            </div>
            <div class="form-group">
                <label>Quantity (Units)</label>
                <input type="number" name="quantity" value="1" min="1" required>
            </div>
            <div class="form-group">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" required>
            </div>
            <button type="submit" name="add_stock" class="btn-primary">Add to Inventory</button>
        </form>
    </div>
</div>

<div id="donorModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('donorModal')">&times;</span>
        <h3>Register New Donor</h3>
        <form method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Blood Group</label>
                    <select name="blood_group" required>
                        <option value="A+">A+</option><option value="A-">A-</option>
                        <option value="B+">B+</option><option value="B-">B-</option>
                        <option value="AB+">AB+</option><option value="AB-">AB-</option>
                        <option value="O+">O+</option><option value="O-">O-</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Age</label>
                    <input type="number" name="age" required>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Contact Number</label>
                <input type="tel" name="contact_number" required>
            </div>
            <button type="submit" name="add_donor" class="btn-primary">Register Donor</button>
        </form>
    </div>
</div>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}
function showModal(id) { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<style>
.tabs { display: flex; border-bottom: 2px solid #ddd; margin-bottom: 1.5rem; }
.tab-btn { padding: 1rem 2rem; background: none; border: none; font-size: 1rem; cursor: pointer; border-bottom: 3px solid transparent; opacity: 0.6; transition: all 0.3s; }
.tab-btn:hover { opacity: 1; }
.tab-btn.active { border-bottom-color: var(--primary-color); opacity: 1; font-weight: bold; color: var(--primary-color); }
.form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>
