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

<?php
// Calculate Summary Metrics
$total_assets = count($assets);
$total_cost = array_sum(array_column($assets, 'cost'));
$active_assets = count(array_filter($assets, fn($a) => $a['status'] === 'active'));
$maintenance_assets = count(array_filter($assets, fn($a) => $a['status'] === 'maintenance'));
?>
<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title">Asset Management</h1>
            <p class="text-muted">Manage infrastructure, equipment, and resources</p>
        </div>
        <button class="btn btn-primary shadow-sm" onclick="openModal('assetModal')">
            <i class="fas fa-plus mr-2"></i> Add New Asset
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i> <?php echo $success_msg; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_msg; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="stat-card">
            <div class="stat-icon bg-primary-light text-primary">
                <i class="fas fa-boxes"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $total_assets; ?></h3>
                <p>Total Assets</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success-light text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $active_assets; ?></h3>
                <p>Active</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning-light text-warning">
                <i class="fas fa-tools"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $maintenance_assets; ?></h3>
                <p>In Maintenance</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-info-light text-info">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="stat-details">
                <h3>₹<?php echo number_format($total_cost, 2); ?></h3>
                <p>Total Value</p>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 premium-card">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-4 px-4">
            <h3 class="mb-0 font-weight-bold text-dark">Inventory List</h3>
            <div class="search-box relative">
                <i class="fas fa-search absolute left-3 top-3 text-muted" style="position:absolute; left: 1rem; top: 10px; color: #94a3b8;"></i>
                <input type="text" id="assetSearch" class="form-control pl-5 rounded-pill" placeholder="Search assets..." onkeyup="filterAssets()" style="padding-left: 2.5rem; background-color: #f1f5f9;">
            </div>
        </div>
        <div class="table-responsive px-4 pb-4">
            <div style="margin-bottom: 14px;">
                <input type="text" id="filter-assets" onkeyup="filterTable('filter-assets','tbl-assets')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
            </div>
            <table id="tbl-assets" class="table premium-table">
                <thead>
                    <tr>
                        <th class="border-top-0">Asset Details</th>
                        <th class="border-top-0">Category</th>
                        <th class="border-top-0">Location</th>
                        <th class="border-top-0">Purchase Info</th>
                        <th class="border-top-0">Cost</th>
                        <th class="border-top-0">Status</th>
                        <th class="border-top-0 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assets)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-box-open fa-3x mb-3 text-light"></i><br>
                            No assets found. Click "Add New Asset" to begin.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($assets as $item): ?>
                    <tr class="asset-row hover-shadow transition-all">
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-light rounded-circle text-primary font-weight-bold d-flex justify-content-center align-items-center mr-3" style="width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($item['name'], 0, 1)); ?>
                                </div>
                                <span class="font-weight-600 text-dark asset-name"><?php echo htmlspecialchars($item['name']); ?></span>
                            </div>
                        </td>
                        <td class="text-muted asset-cat"><?php echo htmlspecialchars($item['category']); ?></td>
                        <td class="text-muted"><i class="fas fa-map-marker-alt text-danger-light mr-1"></i> <?php echo htmlspecialchars($item['location']); ?></td>
                        <td class="text-muted"><?php echo date('M d, Y', strtotime($item['purchase_date'])); ?></td>
                        <td class="font-weight-600">₹<?php echo number_format($item['cost'], 2); ?></td>
                        <td>
                            <?php if ($item['status'] == 'active'): ?>
                                <span class="badge badge-success-soft badge-pill px-3 py-1 font-weight-500"><i class="fas fa-check-circle mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-warning-soft badge-pill px-3 py-1 font-weight-500"><i class="fas fa-tools mr-1"></i> Maintenance</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="action-buttons">
                                <?php if ($item['status'] == 'active'): ?>
                                <a href="?maintenance=<?php echo $item['id']; ?>" class="btn btn-sm btn-icon btn-light text-warning" data-toggle="tooltip" title="Send to Maintenance">
                                    <i class="fas fa-tools"></i>
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-icon btn-light text-danger" data-toggle="tooltip" title="Retire Asset" onclick="confirmRetire(<?php echo $item['id']; ?>)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Premium Add Asset Modal -->
<div id="assetModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog-custom slide-in-bottom">
        <div class="modal-content border-0 shadow-lg rounded-xl">
            <div class="modal-header border-bottom-0 pt-4 px-4 pb-0 d-flex justify-content-between">
                <h4 class="font-weight-bold text-dark mb-0">Add New Asset</h4>
                <button type="button" class="close text-muted" onclick="closeModal('assetModal')">&times;</button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" class="custom-modal-form">
                    <div class="form-group full-width">
                        <label class="form-label">Asset Name</label>
                        <input type="text" name="name" class="form-control hover-bg-light transition-all" required placeholder="e.g. Hitachi MRI Scanner">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control hover-bg-light transition-all" required>
                            <option value="" disabled selected>Select Category</option>
                            <option value="Medical Equipment">Medical Equipment</option>
                            <option value="IT Infrastructure">IT Infrastructure</option>
                            <option value="Furniture">Furniture</option>
                            <option value="Vehicle">Vehicle</option>
                            <option value="Facilities">Facilities</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Location / Department</label>
                        <input type="text" name="location" class="form-control hover-bg-light transition-all" required placeholder="e.g. Radiology Wing">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control hover-bg-light transition-all" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Cost ($)</label>
                        <div class="custom-input-group w-100 relative">
                            <span class="currency-symbol absolute left-3 font-weight-bold text-muted" style="top: 50%; transform: translateY(-50%); z-index: 10;">$</span>
                            <input type="number" step="0.01" name="cost" class="form-control hover-bg-light transition-all pl-5" required placeholder="0.00" style="padding-left: 2rem;">
                        </div>
                    </div>

                    <div class="form-actions full-width d-flex justify-content-end align-items-center mt-4 pt-3 border-top w-100">
                        <button type="button" class="btn btn-light px-4 py-2 font-weight-600 rounded mr-3" onclick="closeModal('assetModal')">Cancel</button>
                        <button type="submit" name="add_asset" class="btn btn-primary px-4 py-2 font-weight-600 rounded shadow-sm">
                            <i class="fas fa-save mr-2"></i> Save Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom Asset Management Styles */
:root {
    --primary-color: #4f46e5;
    --primary-light: #e0e7ff;
    --success-color: #10b981;
    --success-light: #d1fae5;
    --warning-color: #f59e0b;
    --warning-light: #fef3c7;
    --danger-color: #ef4444;
    --danger-light: #fee2e2;
    --info-color: #3b82f6;
    --info-light: #dbeafe;
}

body {
    background-color: #f8fafc;
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.25rem;
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: #ffffff;
    border-radius: 1rem;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1.25rem;
}

.bg-primary-light { background-color: var(--primary-light); }
.text-primary { color: var(--primary-color) !important; }
.bg-success-light { background-color: var(--success-light); }
.text-success { color: var(--success-color) !important; }
.bg-warning-light { background-color: var(--warning-light); }
.text-warning { color: var(--warning-color) !important; }
.bg-danger-light { background-color: var(--danger-light); }
.text-danger { color: var(--danger-color) !important; }
.bg-info-light { background-color: var(--info-light); }
.text-info { color: var(--info-color) !important; }

.stat-details h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
}

.stat-details p {
    margin: 0;
    color: #64748b;
    font-size: 0.875rem;
    font-weight: 500;
}

/* Premium Card & Table */
.premium-card {
    border-radius: 1rem;
    overflow: hidden;
}

.search-box {
    position: relative;
    width: 300px;
}

.premium-table th {
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    padding: 1rem 1.5rem;
    background-color: #f8fafc;
}

.premium-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    color: #334155;
    border-top: 1px solid #f1f5f9;
}

.hover-shadow:hover td {
    background-color: #f8fafc;
}

.font-weight-500 { font-weight: 500; }
.font-weight-600 { font-weight: 600; }

.badge-success-soft {
    background-color: #d1fae5;
    color: #065f46;
}

.badge-warning-soft {
    background-color: #fef3c7;
    color: #92400e;
}

.btn-icon {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.375rem;
    transition: all 0.2s;
    border: none;
    background: transparent;
    cursor: pointer;
}

.btn-icon:hover {
    background-color: #e2e8f0;
}

/* Modal Overlay */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 1050;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-dialog-custom {
    width: 100%;
    max-width: 600px;
    background: white;
    border-radius: 1.25rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.rounded-xl {
    border-radius: 1.25rem !important;
}

.spacing-1 {
    letter-spacing: 0.05em;
}

/* Custom Modal Form Grid */
.custom-modal-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}
.custom-modal-form .full-width {
    grid-column: 1 / -1;
}
.custom-modal-form .form-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 0;
}
.custom-modal-form .form-label {
    font-weight: 600;
    color: #475569;
    font-size: 0.75rem;
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.custom-modal-form .form-control {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    color: #1e293b;
    height: auto;
}
.custom-modal-form .form-control:focus {
    background-color: #ffffff;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}
@media (max-width: 640px) {
    .custom-modal-form {
        grid-template-columns: 1fr;
    }
}
.hover-bg-light:hover {
    background-color: #f1f5f9;
}
.transition-all {
    transition: all 0.2s ease;
}

.slide-in-bottom {
    animation: slideInBottom 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) both;
}

@keyframes slideInBottom {
    0% { transform: translateY(50px); opacity: 0; }
    100% { transform: translateY(0); opacity: 1; }
}

.form-control:focus {
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    border-color: #4f46e5 !important;
    outline: none;
}

.btn-primary {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    color: white;
}
.btn-primary:hover {
    background-color: #4338ca;
    border-color: #4338ca;
}
.btn-light {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
}
.btn-light:hover {
    background-color: #f1f5f9;
}
</style>

<script>
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('assetModal');
    if (event.target == modal) {
        closeModal('assetModal');
    }
}

function filterAssets() {
    const input = document.getElementById('assetSearch');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('assetTable');
    const rows = table.getElementsByClassName('asset-row');

    for (let i = 0; i < rows.length; i++) {
        const name = rows[i].querySelector('.asset-name').textContent.toLowerCase();
        const cat = rows[i].querySelector('.asset-cat').textContent.toLowerCase();
        
        if (name.includes(filter) || cat.includes(filter)) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}

function confirmRetire(id) {
    if(confirm('Are you sure you want to retire this asset? This action cannot be undone.')) {
        // Implement retire logic or redirect
        alert('Retire functionality to be implemented in backend.');
    }
}

// Ensure tooltips work if Bootstrap is loaded
document.addEventListener('DOMContentLoaded', function() {
    if(typeof $ !== 'undefined' && $.fn.tooltip) {
        $('[data-toggle="tooltip"]').tooltip();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
