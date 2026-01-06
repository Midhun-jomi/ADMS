<?php
// dashboards/radiologist_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['radiologist']);

$page_title = "Radiologist Dashboard";
include '../includes/header.php';

// Stats
$pending_scans = db_select_one("SELECT COUNT(*) as c FROM radiology_reports WHERE status = 'ordered'")['c'];
$completed_scans = db_select_one("SELECT COUNT(*) as c FROM radiology_reports WHERE status = 'completed'")['c'];
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <h3><?php echo $pending_scans; ?></h3>
        <p>Pending Scans</p>
    </div>
    <div class="stat-card">
        <h3><?php echo $completed_scans; ?></h3>
        <p>Completed Scans</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Quick Actions</div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="/modules/radiology/orders.php" class="btn btn-primary">View Orders</a>
            <a href="/modules/radiology/upload.php" class="btn btn-primary">Upload Results</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Pending Orders</div>
    <?php
    $pending = db_select("SELECT r.*, p.first_name, p.last_name 
                          FROM radiology_reports r 
                          JOIN patients p ON r.patient_id = p.id 
                          WHERE r.status = 'ordered' 
                          ORDER BY r.created_at ASC LIMIT 5");
    ?>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px;">Date</th>
                <th style="padding: 10px;">Patient</th>
                <th style="padding: 10px;">Scan Type</th>
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
                        <td style="padding: 10px;"><?php echo htmlspecialchars($order['report_type']); ?></td>
                        <td style="padding: 10px;">
                            <a href="/modules/radiology/upload.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white;">Process</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
