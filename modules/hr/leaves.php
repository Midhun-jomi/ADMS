<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin or any Staff (if staff login existed separate from users, but schema links staff->users)
// Let's assume current user is admin for management, but staff can view if we extended logic.
// For now, focusing on Admin Management of leaves.

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "Leave Management";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Leave Request (Admin adding on behalf of staff, or approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_leave'])) {
    try {
        $data = [
            'staff_id' => $_POST['staff_id'],
            'leave_type' => $_POST['leave_type'],
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'reason' => $_POST['reason'],
            'status' => 'pending' // Admin can directly approve, but let's stick to flow
        ];
        // If admin adds, maybe auto-approve? Let's auto-approve for admin entry.
        $data['status'] = 'approved';
        $data['approved_by'] = $_SESSION['user_id'];
        
        db_insert('leaves', $data);
        $success_msg = "Leave recorded successfully!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Approval/Rejection
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['action'] == 'approve' ? 'approved' : 'rejected';
    try {
        db_update('leaves', 
            ['status' => $status, 'approved_by' => $_SESSION['user_id']], 
            ['id' => $id]
        );
        $success_msg = "Leave request $status!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

$staff_list = db_select("SELECT id, first_name, last_name FROM staff");
$leaves = db_select("
    SELECT l.*, s.first_name, s.last_name 
    FROM leaves l 
    JOIN staff s ON l.staff_id = s.id 
    ORDER BY l.start_date DESC
");

?>

<?php
// Calculate Summary Metrics
$total_requests = count($leaves);
$pending_requests = 0;
$approved_requests = 0;
$rejected_requests = 0;

foreach($leaves as $l) {
    if($l['status'] === 'pending') $pending_requests++;
    if($l['status'] === 'approved') $approved_requests++;
    if($l['status'] === 'rejected') $rejected_requests++;
}
?>

<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1 class="page-title"><i class="fas fa-calendar-alt text-primary mr-2"></i> Leave Management</h1>
            <p class="text-muted">Manage staff leave requests, approvals, and absences</p>
        </div>
        <button class="btn btn-primary shadow-sm" onclick="openModal('leaveModal')">
            <i class="fas fa-plus mr-2"></i> Record Leave
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
            <div class="stat-icon bg-warning-light text-warning">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $pending_requests; ?></h3>
                <p>Pending Approval</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-success-light text-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $approved_requests; ?></h3>
                <p>Approved Leaves</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-danger-light text-danger">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $rejected_requests; ?></h3>
                <p>Rejected Requests</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon bg-primary-light text-primary">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-details">
                <h3><?php echo $total_requests; ?></h3>
                <p>Total Requests</p>
            </div>
        </div>
    </div>

    <!-- Outstanding Tasks Table -->
    <div class="card shadow-sm border-0 premium-card">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-4 px-4">
            <h3 class="mb-0 font-weight-bold text-dark">Leave Requests</h3>
            <div class="search-box relative">
                <i class="fas fa-search absolute left-3 top-3 text-muted" style="position:absolute; left: 1rem; top: 10px; color: #94a3b8;"></i>
                <input type="text" id="leaveSearch" class="form-control pl-5 rounded-pill" placeholder="Search staff or type..." onkeyup="filterLeaves()" style="padding-left: 2.5rem; background-color: #f1f5f9;">
            </div>
        </div>
        <div class="table-responsive px-4 pb-4">
            <div style="margin-bottom: 14px;">
                <input type="text" id="filter-leaves" onkeyup="filterTable('filter-leaves','tbl-leaves')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
            </div>
            <table id="tbl-leaves" class="table premium-table">
                <thead>
                    <tr>
                        <th class="border-top-0">Staff Name</th>
                        <th class="border-top-0">Type</th>
                        <th class="border-top-0">Duration</th>
                        <th class="border-top-0" style="max-width: 200px;">Reason</th>
                        <th class="border-top-0 text-center">Status</th>
                        <th class="border-top-0 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-calendar fa-3x mb-3 text-light"></i><br>
                            No leave requests found.
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($leaves as $leave): ?>
                    <tr class="leave-row hover-shadow transition-all">
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-primary-light rounded-circle text-primary font-weight-bold d-flex justify-content-center align-items-center mr-3" style="width: 40px; height: 40px;">
                                    <?php echo strtoupper(substr($leave['first_name'], 0, 1) . substr($leave['last_name'], 0, 1)); ?>
                                </div>
                                <span class="font-weight-600 text-dark staff-name"><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $typeClass = 'badge-primary-soft';
                                $ltype = strtolower($leave['leave_type']);
                                if(strpos($ltype, 'sick') !== false) { $typeClass = 'badge-warning-soft'; }
                                if(strpos($ltype, 'annual') !== false) { $typeClass = 'badge-success-soft'; }
                                if(strpos($ltype, 'unpaid') !== false) { $typeClass = 'badge-danger-soft'; }
                            ?>
                            <span class="badge <?php echo $typeClass; ?> badge-pill px-3 py-1 font-weight-500 leave-type">
                                <?php echo htmlspecialchars($leave['leave_type']); ?>
                            </span>
                        </td>
                        <td class="text-muted">
                            <i class="far fa-calendar-alt mr-1"></i> <?php echo date('M d', strtotime($leave['start_date'])) . ' - ' . date('M d', strtotime($leave['end_date'])); ?>
                            <small class="d-block text-muted" style="font-size: 0.75rem; margin-top: 2px;">
                                <?php 
                                $diff = strtotime($leave['end_date']) - strtotime($leave['start_date']);
                                $days = round($diff / (60 * 60 * 24)) + 1;
                                echo $days . ' day' . ($days > 1 ? 's' : ''); 
                                ?> 
                            </small>
                        </td>
                        <td class="text-muted" style="max-width: 200px;">
                            <div class="text-truncate" title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                <?php echo htmlspecialchars($leave['reason']); ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <?php 
                                $statusClass = 'badge-secondary';
                                $statusIcon = 'fa-clock';
                                if($leave['status'] == 'approved') { $statusClass = 'badge-success-soft'; $statusIcon = 'fa-check'; }
                                if($leave['status'] == 'rejected') { $statusClass = 'badge-danger-soft'; $statusIcon = 'fa-times'; }
                                if($leave['status'] == 'pending') { $statusClass = 'badge-warning-soft'; }
                            ?>
                            <span class="badge <?php echo $statusClass; ?> badge-pill px-3 py-1 font-weight-500">
                                <i class="fas <?php echo $statusIcon; ?> mr-1"></i> <?php echo ucfirst($leave['status']); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($leave['status'] === 'pending'): ?>
                                <div class="d-flex justify-content-center">
                                    <a href="?action=approve&id=<?php echo $leave['id']; ?>" class="btn-icon text-success action-btn bg-success-light mr-2" data-toggle="tooltip" title="Approve" onclick="return confirm('Approve this leave request?')">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="?action=reject&id=<?php echo $leave['id']; ?>" class="btn-icon text-danger action-btn bg-danger-light" data-toggle="tooltip" title="Reject" onclick="return confirm('Reject this leave request?')">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Premium Record Leave Modal -->
<div id="leaveModal" class="modal-overlay" style="display: none;">
    <div class="modal-dialog-custom slide-in-bottom" style="max-width: 550px;">
        <div class="modal-content border-0 shadow-lg rounded-xl">
            <div class="modal-header custom-modal-header pt-4 px-4 pb-0">
                <h4 class="font-weight-bold text-dark mb-0">Record Staff Leave</h4>
                <button type="button" class="close-modal-btn text-muted" onclick="closeModal('leaveModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" class="custom-modal-form">
                    
                    <div class="form-group full-width">
                        <label class="form-label">Select Staff</label>
                        <select name="staff_id" class="form-control hover-bg-light transition-all custom-select-icon" required>
                            <option value="" disabled selected>Select Staff Member</option>
                            <?php foreach ($staff_list as $staff): ?>
                                <option value="<?php echo $staff['id']; ?>">
                                    <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Leave Type</label>
                        <select name="leave_type" class="form-control hover-bg-light transition-all custom-select-icon" required>
                            <option value="Annual">Annual Leave</option>
                            <option value="Sick">Sick Leave</option>
                            <option value="Casual">Casual Leave</option>
                            <option value="Unpaid">Unpaid Leave</option>
                            <option value="Maternity">Maternity Leave</option>
                            <option value="Paternity">Paternity Leave</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control hover-bg-light transition-all" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control hover-bg-light transition-all" required>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Reason / Comments</label>
                        <textarea name="reason" class="form-control hover-bg-light transition-all" rows="3" required placeholder="Brief description of the leave reason..."></textarea>
                    </div>

                    <div class="form-actions full-width d-flex justify-content-end align-items-center mt-3 pt-3 border-top w-100">
                        <button type="button" class="btn btn-light px-4 py-2 font-weight-600 rounded mr-2" onclick="closeModal('leaveModal')">Cancel</button>
                        <button type="submit" name="add_leave" class="btn btn-primary px-4 py-2 font-weight-600 rounded shadow-sm">
                            <i class="fas fa-save mr-2"></i> Submit Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom HR Leave Management Styles (Based on Premium Asset UI) */
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
.bg-primary-soft { background-color: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }

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

/* Truncate long reasons */
.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    max-width: 100%;
}

/* Action Buttons in Table */
.action-btn {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
    text-decoration: none;
}
.action-btn:hover {
    transform: scale(1.1);
    text-decoration: none;
    color: inherit;
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

/* Custom Modal Form HTML Structure Fixes */
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
    const modal = document.getElementById('leaveModal');
    if (event.target == modal) {
        closeModal('leaveModal');
    }
}

// Function to filter the table based on search input
function filterLeaves() {
    const input = document.getElementById('leaveSearch');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('leavesTable');
    const rows = table.getElementsByClassName('leave-row');

    for (let i = 0; i < rows.length; i++) {
        const staffName = rows[i].querySelector('.staff-name').textContent.toLowerCase();
        const leaveType = rows[i].querySelector('.leave-type').textContent.toLowerCase();
        
        if (staffName.includes(filter) || leaveType.includes(filter)) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}

// Enable tooltips if jQuery & Bootstrap bundle exists
if (typeof $ !== 'undefined' && $.fn.tooltip) {
    $(function () {
        $('[data-toggle="tooltip"]').tooltip()
    })
}
</script>

<?php require_once '../../includes/footer.php'; ?>
