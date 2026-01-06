<?php
// dashboards/pharmacist_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['pharmacist']);

$page_title = "Pharmacist Dashboard";
include '../includes/header.php';

// Stats
$low_stock = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory WHERE quantity < 10")['c'];
$total_meds = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory")['c'];
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <h3><?php echo $total_meds; ?></h3>
        <p>Total Medications</p>
    </div>
    <div class="stat-card" style="border-left: 5px solid #dc3545;">
        <h3><?php echo $low_stock; ?></h3>
        <p>Low Stock Items</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Quick Actions</div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="/modules/pharmacy/dispense.php" class="btn btn-primary">Dispense Medication</a>
            <a href="/modules/pharmacy/inventory.php" class="btn btn-primary">Manage Inventory</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Recent Prescriptions</div>
    <?php
    $recent_rx = db_select("SELECT pr.*, p.first_name, p.last_name 
                            FROM prescriptions pr 
                            JOIN patients p ON pr.patient_id = p.id 
                            ORDER BY pr.created_at DESC LIMIT 5");
    ?>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px;">Date</th>
                <th style="padding: 10px;">Patient</th>
                <th style="padding: 10px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recent_rx)): ?>
                <tr><td colspan="3" style="padding: 10px;">No recent prescriptions.</td></tr>
            <?php else: ?>
                <?php foreach ($recent_rx as $rx): ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td style="padding: 10px;"><?php echo date('M d, H:i', strtotime($rx['created_at'])); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']); ?></td>
                        <td style="padding: 10px;">
                            <a href="/modules/pharmacy/dispense.php" class="btn btn-sm" style="background: #28a745; color: white;">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
