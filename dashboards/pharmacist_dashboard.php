<?php
// dashboards/pharmacist_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['pharmacist']);

$page_title = "Pharmacist Dashboard";
include '../includes/header.php';

// Stats
$critical_count   = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory WHERE quantity < 5")['c'];
$low_stock_count  = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory WHERE quantity < 20")['c'];
$out_of_stock     = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory WHERE quantity = 0")['c'];
$expiring_count   = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory WHERE expiry_date IS NOT NULL AND expiry_date <= CURRENT_DATE + INTERVAL '30 days' AND expiry_date >= CURRENT_DATE")['c'];
$total_meds       = db_select_one("SELECT COUNT(*) as c FROM pharmacy_inventory")['c'];
$pending_rx_count = db_select_one("SELECT COUNT(*) as c FROM prescriptions WHERE status = 'pending'")['c'];
$dispensed_today  = db_select_one("SELECT COUNT(*) as c FROM prescriptions WHERE status = 'completed' AND created_at >= CURRENT_DATE")['c'];

// Low stock items (tiered)
$low_stock_items = db_select("SELECT medication_name, quantity, unit_price, expiry_date FROM pharmacy_inventory WHERE quantity < 20 ORDER BY quantity ASC LIMIT 10");

// Pending prescriptions (awaiting dispense)
$pending_rx = db_select("SELECT pr.*, p.first_name, p.last_name
                         FROM prescriptions pr
                         JOIN patients p ON pr.patient_id = p.id
                         WHERE pr.status = 'pending'
                         ORDER BY pr.created_at ASC LIMIT 10");
?>

<?php if ($critical_count > 0 || $out_of_stock > 0): ?>
<div style="background: linear-gradient(135deg, #7f1d1d, #dc2626); color: white; border-radius: 14px; padding: 18px 24px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; box-shadow: 0 4px 16px rgba(220,38,38,0.3);">
    <div style="display: flex; align-items: center; gap: 14px;">
        <div style="width: 42px; height: 42px; background: rgba(255,255,255,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2em; flex-shrink: 0;">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div>
            <div style="font-weight: 800; font-size: 1em; margin-bottom: 3px;">
                <?php if ($out_of_stock > 0): ?>
                    <?php echo $out_of_stock; ?> medication<?php echo $out_of_stock > 1 ? 's' : ''; ?> OUT OF STOCK
                    <?php if ($critical_count > $out_of_stock): ?> &bull; <?php echo $critical_count - $out_of_stock; ?> critically low<?php endif; ?>
                <?php else: ?>
                    <?php echo $critical_count; ?> medication<?php echo $critical_count > 1 ? 's' : ''; ?> critically low (< 5 units)
                <?php endif; ?>
            </div>
            <div style="opacity: 0.8; font-size: 0.82em;">Immediate restock required to ensure patient care continuity.</div>
        </div>
    </div>
    <a href="/modules/pharmacy/low_stock_alerts.php" style="background: white; color: #dc2626; border-radius: 8px; padding: 9px 18px; font-weight: 700; font-size: 0.88em; text-decoration: none; flex-shrink: 0;">
        <i class="fas fa-arrow-right"></i> View Full Alert Report
    </a>
</div>
<?php elseif ($low_stock_count > 0 || $expiring_count > 0): ?>
<div style="background: linear-gradient(135deg, #78350f, #d97706); color: white; border-radius: 14px; padding: 16px 22px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; box-shadow: 0 4px 14px rgba(217,119,6,0.25);">
    <div style="display: flex; align-items: center; gap: 12px;">
        <i class="fas fa-exclamation-triangle" style="font-size: 1.4em;"></i>
        <div>
            <div style="font-weight: 700; font-size: 0.95em;">
                <?php echo $low_stock_count; ?> item<?php echo $low_stock_count > 1 ? 's' : ''; ?> running low
                <?php if ($expiring_count > 0): ?> &bull; <?php echo $expiring_count; ?> expiring soon<?php endif; ?>
            </div>
            <div style="opacity: 0.8; font-size: 0.8em;">Plan restocking before stock runs out.</div>
        </div>
    </div>
    <a href="/modules/pharmacy/low_stock_alerts.php" style="background: rgba(255,255,255,0.25); color: white; border-radius: 8px; padding: 8px 16px; font-weight: 700; font-size: 0.85em; text-decoration: none; border: 1px solid rgba(255,255,255,0.4);">
        View Alerts
    </a>
</div>
<?php endif; ?>

<style>
    .pharma-stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
    @media(max-width: 900px) { .pharma-stat-grid { grid-template-columns: repeat(2, 1fr); } }
    .pharma-stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #eee; }
    .pharma-stat-card h3 { font-size: 2rem; margin: 0 0 5px; color: #333; }
    .pharma-stat-card p { margin: 0; color: #888; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .low-stock-badge { background: #fee2e2; color: #dc2626; padding: 3px 10px; border-radius: 99px; font-size: 0.8em; font-weight: 700; }
    .ok-badge { background: #dcfce7; color: #16a34a; padding: 3px 10px; border-radius: 99px; font-size: 0.8em; font-weight: 700; }
</style>

<!-- Stat Cards -->
<div class="pharma-stat-grid">
    <div class="pharma-stat-card">
        <h3><?php echo $total_meds; ?></h3>
        <p>Total Medications</p>
    </div>
    <div class="pharma-stat-card" style="border-left: 4px solid #dc3545;">
        <h3 style="color: #dc3545;"><?php echo $low_stock_count; ?></h3>
        <p>Low Stock Items</p>
    </div>
    <div class="pharma-stat-card" style="border-left: 4px solid #f59e0b;">
        <h3 style="color: #f59e0b;"><?php echo $pending_rx_count; ?></h3>
        <p>Pending Dispense</p>
    </div>
    <div class="pharma-stat-card" style="border-left: 4px solid #16a34a;">
        <h3 style="color: #16a34a;"><?php echo $dispensed_today; ?></h3>
        <p>Dispensed Today</p>
    </div>
</div>

<!-- Quick Actions -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">Quick Actions</div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="/modules/pharmacy/dispense.php" class="btn btn-primary"><i class="fas fa-pills"></i> Dispense Medication</a>
            <a href="/modules/pharmacy/inventory.php" class="btn btn-primary"><i class="fas fa-boxes"></i> Manage Inventory</a>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">

    <!-- Pending Prescriptions -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <span>Pending Prescriptions</span>
            <a href="/modules/pharmacy/dispense.php" style="font-size: 0.85em; color: #2563eb; text-decoration: none;">View All</a>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #f8f9fa; text-align: left;">
                    <th style="padding: 10px; font-size: 0.85em;">Time</th>
                    <th style="padding: 10px; font-size: 0.85em;">Patient</th>
                    <th style="padding: 10px; font-size: 0.85em;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pending_rx)): ?>
                    <tr><td colspan="3" style="padding: 15px; text-align: center; color: #888;">No pending prescriptions.</td></tr>
                <?php else: ?>
                    <?php foreach ($pending_rx as $rx): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 10px; font-size: 0.9em;"><?php echo date('M d, H:i', strtotime($rx['created_at'])); ?></td>
                            <td style="padding: 10px;"><?php echo htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']); ?></td>
                            <td style="padding: 10px;">
                                <a href="/modules/pharmacy/dispense.php" class="btn btn-sm" style="background: #16a34a; color: white; padding: 4px 10px; font-size: 0.8em; border-radius: 6px;">Dispense</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Low Stock Alerts -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</span>
            <a href="/modules/pharmacy/low_stock_alerts.php" style="font-size: 0.85em; color: #dc2626; font-weight: 700; text-decoration: none;">Full Report →</a>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #f8f9fa; text-align: left;">
                    <th style="padding: 10px; font-size: 0.85em;">Medication</th>
                    <th style="padding: 10px; font-size: 0.85em;">Stock</th>
                    <th style="padding: 10px; font-size: 0.85em;">Expiry</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($low_stock_items)): ?>
                    <tr><td colspan="3" style="padding: 15px; text-align: center; color: #16a34a;"><i class="fas fa-check-circle"></i> All medications are well-stocked.</td></tr>
                <?php else: ?>
                    <?php foreach ($low_stock_items as $item): ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 10px; font-weight: 600; color: #111827;"><?php echo htmlspecialchars($item['medication_name']); ?></td>
                            <td style="padding: 10px;">
                                <?php if ($item['quantity'] == 0): ?>
                                    <span style="background: #1f2937; color: white; padding: 3px 9px; border-radius: 99px; font-size: 0.78em; font-weight: 700;">OUT OF STOCK</span>
                                <?php elseif ($item['quantity'] < 5): ?>
                                    <span class="low-stock-badge"><?php echo $item['quantity']; ?> left</span>
                                <?php else: ?>
                                    <span style="background: #fef9c3; color: #92400e; padding: 3px 9px; border-radius: 99px; font-size: 0.78em; font-weight: 700;"><?php echo $item['quantity']; ?> left</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px; color: #6b7280; font-size: 0.88em;">
                                <?php if ($item['expiry_date']): ?>
                                    <?php $dexp = (int)(( strtotime($item['expiry_date']) - time()) / 86400); ?>
                                    <?php if ($dexp < 0): ?>
                                        <span style="color: #dc2626; font-weight: 700;">EXPIRED</span>
                                    <?php elseif ($dexp <= 30): ?>
                                        <span style="color: #ea580c; font-weight: 600;"><?php echo $dexp; ?>d left</span>
                                    <?php else: ?>
                                        <?php echo date('d M Y', strtotime($item['expiry_date'])); ?>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php include '../includes/footer.php'; ?>
