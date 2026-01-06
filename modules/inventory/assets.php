<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Pharmacist (maybe?) - Let's keep it Admin for infrastructure assets
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "Asset Management";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Add Asset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
    try {
        db_insert('assets', [
            'name' => $_POST['name'],
            'category' => $_POST['category'],
            'purchase_date' => $_POST['purchase_date'],
            'cost' => $_POST['cost'],
            'location' => $_POST['location'],
            'status' => 'active'
        ]);
        $success_msg = "Asset added successfully!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Status Update
if (isset($_GET['maintenance'])) {
    try {
        db_update('assets', ['status' => 'maintenance'], ['id' => $_GET['maintenance']]);
        $success_msg = "Asset marked for maintenance.";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$assets = db_select("SELECT * FROM assets ORDER BY created_at DESC");

?>

<div class="main-content">
    <div class="page-header">
        <h1>Infrastructure & Assets</h1>
        <button class="btn-primary" onclick="document.getElementById('assetModal').style.display='block'">
            <i class="fas fa-plus"></i> Add New Asset
        </button>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Inventory List</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Asset Name</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Purchase Date</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                        <td><?php echo htmlspecialchars($item['location']); ?></td>
                        <td><?php echo date('M Y', strtotime($item['purchase_date'])); ?></td>
                        <td>$<?php echo number_format($item['cost'], 2); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $item['status'] == 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($item['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="?maintenance=<?php echo $item['id']; ?>" class="btn-icon text-warning" title="Maintenance"><i class="fas fa-tools"></i></a>
                            <a href="#" class="btn-icon text-danger" title="Retire"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Asset Modal -->
<div id="assetModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('assetModal').style.display='none'">&times;</span>
        <h3>Add New Asset</h3>
        <form method="POST">
            <div class="form-group">
                <label>Asset Name</label>
                <input type="text" name="name" required placeholder="e.g. MRI Machine X1">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="Medical Equipment">Medical Equipment</option>
                        <option value="IT Infrastructure">IT Infrastructure</option>
                        <option value="Furniture">Furniture</option>
                        <option value="Vehicle">Vehicle</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" required placeholder="e.g. Block A, Room 101">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Purchase Date</label>
                    <input type="date" name="purchase_date" required>
                </div>
                <div class="form-group">
                    <label>Cost ($)</label>
                    <input type="number" step="0.01" name="cost" required>
                </div>
            </div>

            <button type="submit" name="add_asset" class="btn-primary">Add Asset</button>
        </form>
    </div>
</div>

<style>
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>
