<?php
// dashboards/lab_tech_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['lab_tech']);

$page_title = "Lab Technician Dashboard";
include '../includes/header.php';

// Stats
$pending_tests = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE status = 'ordered'")['c'];
$completed_tests = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE status = 'completed'")['c'];
$tests_today = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE created_at >= CURRENT_DATE")['c'];

// Latest Request logic (Absolute latest)
$latest_req = db_select_one("SELECT l.*, p.first_name, p.last_name 
                             FROM laboratory_tests l 
                             JOIN patients p ON l.patient_id = p.id 
                             WHERE l.status = 'ordered'
                             ORDER BY l.created_at DESC LIMIT 1");
?>

<style>
    .smart-stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
    .smart-stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px solid #eee; text-align: center; }
    .smart-stat-card h3 { font-size: 2rem; margin: 0; color: #333; }
    .smart-stat-card p { margin: 5px 0 0; color: #888; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
    .latest-alert { background: #fff5f5; border-left: 5px solid #fc8181; padding: 15px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; }
</style>

<?php if ($latest_req): ?>
<div class="latest-alert">
    <div>
        <strong style="color: #c53030;">LATEST REQUEST:</strong> 
        <?php echo htmlspecialchars($latest_req['test_type']); ?> for <?php echo htmlspecialchars($latest_req['first_name']); ?> 
        <span class="text-muted" style="font-size: 0.8em; margin-left: 10px;">(Received: <?php echo date('M d, H:i', strtotime($latest_req['created_at'])); ?>)</span>
    </div>
    <a href="/modules/lab/results.php?id=<?php echo $latest_req['id']; ?>" class="btn btn-sm btn-danger">PROCESS NOW</a>
</div>
<?php endif; ?>

<div class="smart-stat-grid">
    <div class="smart-stat-card">
        <h3><?php echo $pending_tests; ?></h3>
        <p>Pending Orders</p>
    </div>
    <div class="smart-stat-card">
        <h3><?php echo $completed_tests; ?></h3>
        <p>Completed Reports</p>
    </div>
    <div class="smart-stat-card">
        <h3><?php echo $tests_today; ?></h3>
        <p>New Requests Today</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Quick Actions</div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="/modules/lab/orders.php" class="btn btn-primary">View Lab Orders</a>
            <a href="/modules/lab/results.php" class="btn btn-primary">Upload Results</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Pending Lab Orders</div>
    <?php
    $pending = db_select("SELECT l.*, p.first_name, p.last_name 
                          FROM laboratory_tests l 
                          JOIN patients p ON l.patient_id = p.id 
                          WHERE l.status = 'ordered' 
                          ORDER BY l.created_at ASC LIMIT 5");
    ?>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px;">Date</th>
                <th style="padding: 10px;">Patient</th>
                <th style="padding: 10px;">Test Type</th>
                <th style="padding: 10px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pending)): ?>
                <tr><td colspan="4" style="padding: 10px;">No pending orders.</td></tr>
            <?php else: ?>
                <?php foreach ($pending as $order): ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td style="padding: 10px;"><?php echo date('M d, H:i', strtotime($order['created_at'])); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($order['test_type']); ?></td>
                        <td style="padding: 10px;">
                            <a href="/modules/lab/results.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white;">Process</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
