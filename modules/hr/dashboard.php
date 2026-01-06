<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "HR Dashboard";
require_once '../../includes/header.php';

// Fetch Summary Stats
$total_staff = count(db_select("SELECT id FROM staff"));
$on_leave = count(db_select("SELECT id FROM leaves WHERE status = 'approved' AND CURRENT_DATE BETWEEN start_date AND end_date"));
$pending_leaves = count(db_select("SELECT id FROM leaves WHERE status = 'pending'"));
$unpaid_payroll = count(db_select("SELECT id FROM payroll WHERE status = 'unpaid'"));

?>

<div class="main-content">
    <div class="dashboard-header">
        <h1>HR & Payroll Dashboard</h1>
        <p>Manage staff, payroll, and leave requests.</p>
    </div>

    <!-- Stats Widgets -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon-wrapper" style="background: rgba(var(--primary-rgb), 0.1); color: var(--primary-color);">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $total_staff; ?></h3>
                <p>Total Staff</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="icon-wrapper" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c;">
                <i class="fas fa-user-clock"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $on_leave; ?></h3>
                <p>Staff on Leave Today</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="icon-wrapper" style="background: rgba(241, 196, 15, 0.1); color: #f1c40f;">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $pending_leaves; ?></h3>
                <p>Pending Leave Requests</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-wrapper" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $unpaid_payroll; ?></h3>
                <p>Pending Payrolls</p>
            </div>
        </div>
    </div>

    <div class="charts-grid">
        <!-- Quick Actions -->
        <div class="chart-card">
            <div class="card-header">
                <h3>Quick Management</h3>
            </div>
            <div class="quick-actions-grid">
                <a href="/modules/hr/payroll.php" class="action-btn">
                    <i class="fas fa-money-check-alt"></i> Process Payroll
                </a>
                <a href="/modules/hr/leaves.php" class="action-btn">
                    <i class="fas fa-calendar-alt"></i> Manage Leaves
                </a>
                <a href="/modules/admin/staff_management.php" class="action-btn">
                    <i class="fas fa-user-plus"></i> Add Staff
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-header { margin-bottom: 2rem; }
.stats-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
    gap: 1.5rem; 
    margin-bottom: 2rem; 
}
.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 15px;
    box-shadow: var(--card-shadow);
    display: flex;
    align-items: center;
    gap: 1.5rem;
    transition: transform 0.2s;
}
.stat-card:hover { transform: translateY(-5px); }
.icon-wrapper {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding: 1rem;
}
.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    background: var(--bg-light);
    border-radius: 10px;
    color: var(--text-color);
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s;
    border: 1px solid rgba(0,0,0,0.05);
}
.action-btn:hover {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}
</style>

<?php require_once '../../includes/footer.php'; ?>
