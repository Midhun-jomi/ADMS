<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Doctor, Nurse, Admin
$allowed_roles = ['admin', 'doctor', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Dietary Planning";
require_once '../../includes/header.php';

$success_msg = '';

// Add Diet Plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_diet'])) {
    try {
        db_insert('diet_plans', [
            'patient_id' => $_POST['patient_id'],
            'plan_name' => $_POST['plan_name'],
            'instructions' => $_POST['instructions'],
            'start_date' => date('Y-m-d'),
            'status' => 'active'
        ]);
        $success_msg = "Diet plan assigned!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$patients = db_select("SELECT * FROM patients");
$plans = db_select("
    SELECT d.*, p.first_name, p.last_name 
    FROM diet_plans d 
    JOIN patients p ON d.patient_id = p.id 
    ORDER BY d.created_at DESC
");
?>

<?php
// Calculate Summary Metrics
$total_plans = count($plans);
$active_plans = count(array_filter($plans, fn($p) => strtolower($p['status']) === 'active'));

// Categorize some basic diets for summary
$general_diets = count(array_filter($plans, fn($p) => strtolower($p['plan_name']) === 'general healthy'));
$special_diets = $total_plans - $general_diets;
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="fas fa-utensils text-primary mr-2"></i> Dietary Planning</h1>
            <p class="text-muted">Manage patient nutrition and special dietary requirements</p>
        </div>
        <button class="btn btn-primary shadow-sm" onclick="openModal('dietModal')">
            <i class="fas fa-plus mr-2"></i> Assign Plan
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
    <?php if (isset($error_msg) && $error_msg): ?>
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
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $total_plans; ?></h3>
                <p>Total Plans</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success-light text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $active_plans; ?></h3>
                <p>Active Plans</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-warning-light text-warning">
                <i class="fas fa-leaf"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $general_diets; ?></h3>
                <p>General Diets</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-info-light text-info">
                <i class="fas fa-apple-alt"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $special_diets; ?></h3>
                <p>Specialized Diets</p>
            </div>
        </div>
    </div>

    <!-- Diet Plans Table -->
    <div class="card shadow-sm border-0 premium-card">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-4 px-4">
            <h3 class="mb-0 font-weight-bold text-dark">Patient Dietary Plans</h3>
            <div class="search-box relative">
                <i class="fas fa-search absolute left-3 top-3 text-muted" style="position:absolute; left: 1rem; top: 10px; color: #94a3b8;"></i>
                <input type="text" id="planSearch" class="form-control pl-5 rounded-pill" placeholder="Search patient or plan..." onkeyup="filterPlans()" style="padding-left: 2.5rem; background-color: #f1f5f9;">
            </div>
        </div>
        <div class="table-responsive px-4 pb-4">
            <table class="table premium-table" id="plansTable">
                <thead>
                    <tr>
                        <th class="border-top-0">Patient</th>
                        <th class="border-top-0">Diet Plan</th>
                        <th class="border-top-0">Instructions</th>
                        <th class="border-top-0">Start Date</th>
                        <th class="border-top-0">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3 text-light"></i><br>
                            No dietary plans assigned. Click "Assign Plan" to begin.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($plans as $p): ?>
                    <tr class="plan-row hover-shadow transition-all">
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-primary-light rounded-circle text-primary font-weight-bold d-flex justify-content-center align-items-center mr-3" style="width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)); ?>
                                </div>
                                <span class="font-weight-600 text-dark patient-name"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $planClass = 'badge-info-soft';
                                if(strpos(strtolower($p['plan_name']), 'diabetic') !== false) $planClass = 'badge-warning-soft';
                                if(strpos(strtolower($p['plan_name']), 'liquid') !== false) $planClass = 'badge-danger-soft';
                            ?>
                            <span class="badge <?php echo $planClass; ?> badge-pill px-3 py-1 font-weight-500 plan-type">
                                <?php echo htmlspecialchars($p['plan_name']); ?>
                            </span>
                        </td>
                        <td class="text-muted" style="max-width: 300px;">
                            <div class="text-truncate" title="<?php echo htmlspecialchars($p['instructions']); ?>">
                                <?php echo htmlspecialchars($p['instructions']); ?>
                            </div>
                        </td>
                        <td class="text-muted"><?php echo date('M d, Y', strtotime($p['start_date'])); ?></td>
                        <td>
                            <?php if (strtolower($p['status']) == 'active'): ?>
                                <span class="badge badge-success-soft badge-pill px-3 py-1 font-weight-500"><i class="fas fa-check-circle mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary badge-pill px-3 py-1 font-weight-500"><?php echo ucfirst($p['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Premium Assign Plan Modal -->
<div id="dietModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog-custom slide-in-bottom" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg rounded-xl">
            <div class="modal-header custom-modal-header pt-4 px-4 pb-0">
                <h4 class="font-weight-bold text-dark mb-0">Assign Diet Plan</h4>
                <button type="button" class="close-modal-btn text-muted" onclick="closeModal('dietModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" class="custom-modal-form single-col">
                    
                    <div class="form-group full-width">
                        <label class="form-label">Patient</label>
                        <select name="patient_id" class="form-control hover-bg-light transition-all custom-select-icon" required>
                            <option value="" disabled selected>Select Patient</option>
                            <?php foreach ($patients as $pt): ?>
                                <option value="<?php echo $pt['id']; ?>"><?php echo htmlspecialchars($pt['first_name'] . ' ' . $pt['last_name']); ?> (ID: <?php echo $pt['id']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Plan Type</label>
                        <select name="plan_name" class="form-control hover-bg-light transition-all custom-select-icon" required>
                            <option value="General Healthy">General Healthy</option>
                            <option value="Diabetic">Diabetic (Low Sugar)</option>
                            <option value="Renal">Renal (Low Sodium/Protein)</option>
                            <option value="Liquid">Liquid Only</option>
                            <option value="Soft">Soft Diet</option>
                            <option value="Gluten Free">Gluten Free</option>
                            <option value="Vegan">Vegan</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Special Instructions</label>
                        <textarea name="instructions" class="form-control hover-bg-light transition-all" rows="3" required placeholder="e.g. No nuts, small portions, preference for mild spices..."></textarea>
                    </div>

                    <div class="form-actions full-width d-flex justify-content-end align-items-center mt-3 pt-3 border-top w-100">
                        <button type="button" class="btn btn-light px-4 py-2 font-weight-600 rounded mr-2" onclick="closeModal('dietModal')">Cancel</button>
                        <button type="submit" name="assign_diet" class="btn btn-primary px-4 py-2 font-weight-600 rounded shadow-sm">
                            <i class="fas fa-save mr-2"></i> Assign Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom Dietary Planner Styles (Based on Premium Asset UI) */
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
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

.badge-success-soft { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.badge-warning-soft { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-danger-soft { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.badge-info-soft { background-color: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

/* Truncate long instructions */
.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    max-width: 100%;
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
    margin: 1rem;
    background: white;
    border-radius: 1.25rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.rounded-xl {
    border-radius: 1.25rem !important;
}

.slide-in-bottom {
    animation: slideInBottom 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) both;
}

@keyframes slideInBottom {
    0% { transform: translateY(50px); opacity: 0; }
    100% { transform: translateY(0); opacity: 1; }
}

/* Custom Modal Form Grid */
.custom-modal-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    border-bottom: none !important;
}

.close-modal-btn {
    background: transparent !important;
    border: none !important;
    font-size: 1.25rem !important;
    color: #94a3b8 !important;
    cursor: pointer !important;
    padding: 0 !important;
    margin: 0 !important;
    transition: color 0.2s ease;
}

.close-modal-btn:hover {
    color: #ef4444 !important;
}

.custom-modal-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}
.custom-modal-form.single-col {
    grid-template-columns: 1fr;
    gap: 1.25rem;
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
.custom-select-icon {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    background-size: 1em;
    padding-right: 2.5rem !important;
}
.custom-modal-form .form-control:focus {
    background-color: #ffffff;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    outline: none;
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

/* Base Buttons overlay tweaks */
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
    const modal = document.getElementById('dietModal');
    if (event.target == modal) {
        closeModal('dietModal');
    }
}

// Function to filter the table based on search input
function filterPlans() {
    const input = document.getElementById('planSearch');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('plansTable');
    const rows = table.getElementsByClassName('plan-row');

    for (let i = 0; i < rows.length; i++) {
        const patientName = rows[i].querySelector('.patient-name').textContent.toLowerCase();
        const planType = rows[i].querySelector('.plan-type').textContent.toLowerCase();
        
        if (patientName.includes(filter) || planType.includes(filter)) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>
