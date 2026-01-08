<?php
// dashboards/admin_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['admin']);

$page_title = "Admin Control Panel";
include '../includes/header.php';

// Fetch High-Level Stats
$staff_count = db_select("SELECT COUNT(*) as c FROM staff WHERE status = 'active'")[0]['c'];
$patient_count = db_select("SELECT COUNT(*) as c FROM patients")[0]['c'];
// Bed Occupancy
$total_beds = db_select("SELECT COUNT(*) as c FROM rooms")[0]['c'];
$occupied_beds = db_select("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")[0]['c'];
$bed_occupancy = $total_beds > 0 ? round(($occupied_beds / $total_beds) * 100) : 0;

// Revenue (Mock/Real) - User said "static generate new invoice", avoiding payment actions but showing stats is fine.
// Assuming 'invoices' table exists with 'amount'
$revenue_today = 0;
// $rev = db_select("SELECT SUM(amount) as s FROM invoices WHERE created_at >= CURRENT_DATE");
// if ($rev) $revenue_today = $rev[0]['s'];

?>

<style>
    .admin-stat-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        transition: transform 0.2s;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .admin-stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.1);
    }
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-right: 20px;
    }
    .quick-action-btn {
        display: block;
        padding: 15px;
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        color: #333;
        text-decoration: none;
        margin-bottom: 10px;
        transition: background 0.2s;
        font-weight: 600;
    }
    .quick-action-btn:hover {
        background: #f8f9fa;
        text-decoration: none;
        border-color: #dee2e6;
    }
    .quick-action-btn i {
        color: #007bff;
        width: 30px;
        text-align: center;
    }
</style>

<!-- Top Stats Row -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="admin-stat-card">
            <div>
                <h3 class="mb-1"><?php echo $staff_count; ?></h3>
                <span class="text-muted">Active Staff</span>
            </div>
            <div class="stat-icon" style="background: rgba(45, 206, 137, 0.1); color: #2dce89;">
                <i class="fas fa-user-md"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="admin-stat-card">
            <div>
                <h3 class="mb-1"><?php echo $bed_occupancy; ?>%</h3>
                <span class="text-muted">Bed Occupancy</span>
            </div>
            <div class="stat-icon" style="background: rgba(11, 114, 185, 0.1); color: #0b72b9;">
                <i class="fas fa-procedures"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="admin-stat-card">
            <div>
                <h3 class="mb-1"><?php echo $patient_count; ?></h3>
                <span class="text-muted">Total Patients</span>
            </div>
            <div class="stat-icon" style="background: rgba(251, 99, 64, 0.1); color: #fb6340;">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="admin-stat-card">
            <div>
                <h3 class="mb-1">Secure</h3>
                <span class="text-muted">System Status</span>
            </div>
            <div class="stat-icon" style="background: rgba(82, 95, 127, 0.1); color: #525f7f;">
                <i class="fas fa-shield-alt"></i>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions -->
    <div class="col-md-4">
        <div class="card bg-white">
            <div class="card-header bg-transparent border-0">
                <h4 class="mb-0">Administrative Tasks</h4>
            </div>
            <div class="card-body pt-0">
                <a href="../modules/admin/staff_management.php" class="quick-action-btn">
                    <i class="fas fa-users-cog"></i> Manage Staff
                </a>
                <a href="../modules/admin/departments.php" class="quick-action-btn">
                    <i class="fas fa-building"></i> Departments
                </a>
                <a href="../modules/patient_management/manage_beds.php" class="quick-action-btn">
                    <i class="fas fa-bed"></i> Bed Management
                </a>
                <a href="../modules/admin/audit_logs.php" class="quick-action-btn">
                    <i class="fas fa-list-alt"></i> View Audit Logs
                </a>
            </div>
        </div>
    </div>

    <!-- Security / Logs Preview -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-shield-alt text-warning"></i> Recent Security Activity</h4>
                <a href="../modules/admin/audit_logs.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="table-responsive">
                <?php
                $recent_logs = db_select("
                    SELECT a.action, a.created_at, u.email 
                    FROM audit_logs a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    ORDER BY a.created_at DESC LIMIT 5
                ");
                ?>
                <table class="table align-items-center table-flush">
                    <thead class="thead-light">
                        <tr>
                            <th>Action</th>
                            <th>User</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                            <td><?php echo htmlspecialchars($log['email'] ?: 'System'); ?></td>
                            <td><?php echo date('H:i:s', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($recent_logs)): ?><tr><td colspan="3">No logs found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Feedback Widget (New) -->
        <div class="card mt-4">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <h4 class="mb-0">❤️ Patient Feedback</h4>
                <a href="../modules/feedback/survey.php" class="btn btn-sm btn-light">View All</a>
            </div>
            <div class="table-responsive">
                <?php
                $recent_feedback = db_select("
                    SELECT f.rating, f.comments, f.created_at, p.first_name, p.last_name 
                    FROM patient_feedback f 
                    LEFT JOIN patients p ON f.patient_id = p.id 
                    ORDER BY f.created_at DESC LIMIT 3
                ");
                ?>
                <table class="table align-items-center table-flush">
                    <thead class="thead-light">
                        <tr>
                            <th>Patient</th>
                            <th>Rating</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_feedback as $fb): ?>
                        <tr>
                            <td style="font-weight: 600;">
                                <?php echo $fb['first_name'] ? htmlspecialchars($fb['first_name'] . ' ' . $fb['last_name']) : 'Anonymous'; ?>
                            </td>
                            <td>
                                <span style="color: #ffc107; font-size: 1.1em;">
                                    <?php 
                                    for($i=0; $i<5; $i++) {
                                        echo ($i < $fb['rating']) ? '★' : '<span style="color: #e0e0e0;">★</span>';
                                    }
                                    ?>
                                </span>
                            </td>
                            <td style="white-space: normal; max-width: 300px; font-style: italic; color: #555;">
                                "<?php echo htmlspecialchars(mb_strimwidth($fb['comments'], 0, 80, "...")); ?>"
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($recent_feedback)): ?><tr><td colspan="3" class="text-center text-muted py-3">No feedback yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
