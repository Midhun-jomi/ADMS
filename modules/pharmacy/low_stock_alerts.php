<?php
// modules/pharmacy/low_stock_alerts.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin']);

// Handle restock POST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_restock'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $med_id  = trim($_POST['med_id'] ?? '');
        $add_qty = (int)($_POST['add_qty'] ?? 0);
        if ($med_id !== '' && $add_qty > 0) {
            $cur = db_select_one("SELECT quantity FROM pharmacy_inventory WHERE id = $1", [$med_id]);
            if ($cur) {
                $new_qty = (int)$cur['quantity'] + $add_qty;
                db_update('pharmacy_inventory', ['quantity' => $new_qty], ['id' => $med_id]);
                header("Location: low_stock_alerts.php?restocked=1");
                exit();
            }
        }
    }
}

$page_title = "Low Stock Alerts";
include '../../includes/header.php';

// Thresholds
define('CRITICAL_THRESHOLD', 5);
define('WARNING_THRESHOLD',  20);
define('EXPIRY_DAYS_WARN',   30); // flag items expiring within 30 days

// Fetch all inventory with stock + expiry status
$inventory = db_select(
    "SELECT id, medication_name, generic_name, quantity, unit_price, expiry_date, batch_number
     FROM pharmacy_inventory
     ORDER BY quantity ASC, expiry_date ASC"
);

// Categorize
$critical = [];  // qty = 0 or < CRITICAL_THRESHOLD
$warning  = [];  // qty < WARNING_THRESHOLD
$expiring = [];  // expiry within EXPIRY_DAYS_WARN
$ok       = 0;

$today = time();
foreach ($inventory as $item) {
    $qty = (int)$item['quantity'];
    $exp = $item['expiry_date'] ? strtotime($item['expiry_date']) : null;
    $days_to_exp = $exp ? (int)(($exp - $today) / 86400) : null;

    if ($qty === 0 || $qty < CRITICAL_THRESHOLD) {
        $critical[] = array_merge($item, ['days_to_exp' => $days_to_exp]);
    } elseif ($qty < WARNING_THRESHOLD) {
        $warning[] = array_merge($item, ['days_to_exp' => $days_to_exp]);
    } else {
        $ok++;
    }

    if ($days_to_exp !== null && $days_to_exp >= 0 && $days_to_exp <= EXPIRY_DAYS_WARN) {
        $expiring[] = array_merge($item, ['days_to_exp' => $days_to_exp]);
    }
}

// Sort expiring by soonest
usort($expiring, fn($a, $b) => $a['days_to_exp'] - $b['days_to_exp']);


?>

<style>
.alerts-wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }
.alert-section { margin-bottom: 30px; }
.section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid; }
.alert-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06); border: 1px solid #e5e7eb; }
.alert-table thead tr { background: #f9fafb; }
.alert-table th { padding: 12px 18px; text-align: left; font-size: 0.8em; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
.alert-table td { padding: 13px 18px; border-bottom: 1px solid #f3f4f6; font-size: 0.9em; }
.alert-table tr:last-child td { border-bottom: none; }
.alert-table tr:hover td { background: #fafafa; }
.stock-badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 0.8em; font-weight: 700; }
.restock-form { display: flex; gap: 6px; align-items: center; }
.restock-form input { width: 70px; padding: 5px 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 0.85em; }
.restock-form button { padding: 5px 12px; background: #16a34a; color: white; border: none; border-radius: 6px; font-size: 0.82em; font-weight: 600; cursor: pointer; white-space: nowrap; }
.restock-form button:hover { background: #15803d; }
.summary-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
.sum-card { background: white; border-radius: 12px; padding: 18px 22px; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.sum-card .num { font-size: 2rem; font-weight: 900; }
.sum-card .lbl { font-size: 0.78em; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
</style>

<div class="alerts-wrap">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 26px;">
        <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #dc2626, #ef4444); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2em;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 1.3em; font-weight: 800; color: #111827;">Low Stock Alerts</h2>
            <p style="margin: 0; color: #6b7280; font-size: 0.85em;">Real-time inventory monitoring with tiered alert levels</p>
        </div>
        <div style="margin-left: auto;">
            <a href="inventory.php" class="btn btn-primary" style="border-radius: 10px; font-weight: 600; font-size: 0.88em;">
                <i class="fas fa-boxes"></i> Full Inventory
            </a>
        </div>
    </div>

    <?php if (isset($_GET['restocked'])): ?>
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; color: #166534; font-weight: 600; font-size: 0.9em;">
        <i class="fas fa-check-circle"></i> Stock updated successfully!
    </div>
    <?php endif; ?>

    <!-- Summary Strip -->
    <div class="summary-strip">
        <div class="sum-card" style="border-left: 4px solid #dc2626;">
            <div class="num" style="color: #dc2626;"><?php echo count($critical); ?></div>
            <div class="lbl">Critical (< <?php echo CRITICAL_THRESHOLD; ?> units)</div>
        </div>
        <div class="sum-card" style="border-left: 4px solid #f59e0b;">
            <div class="num" style="color: #f59e0b;"><?php echo count($warning); ?></div>
            <div class="lbl">Low Warning (< <?php echo WARNING_THRESHOLD; ?> units)</div>
        </div>
        <div class="sum-card" style="border-left: 4px solid #8b5cf6;">
            <div class="num" style="color: #8b5cf6;"><?php echo count($expiring); ?></div>
            <div class="lbl">Expiring (≤ <?php echo EXPIRY_DAYS_WARN; ?> days)</div>
        </div>
        <div class="sum-card" style="border-left: 4px solid #16a34a;">
            <div class="num" style="color: #16a34a;"><?php echo $ok; ?></div>
            <div class="lbl">Well-Stocked</div>
        </div>
    </div>

    <!-- CRITICAL Section -->
    <?php if (!empty($critical)): ?>
    <div class="alert-section">
        <div class="section-header" style="border-color: #fca5a5;">
            <div style="width: 32px; height: 32px; background: #fee2e2; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #dc2626;">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h3 style="margin: 0; font-size: 1em; font-weight: 800; color: #dc2626;">CRITICAL — Immediate Restock Required</h3>
            <span style="background: #fee2e2; color: #dc2626; border-radius: 99px; padding: 2px 10px; font-size: 0.8em; font-weight: 700; margin-left: auto;"><?php echo count($critical); ?> items</span>
        </div>
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Medication</th>
                    <th>Generic Name</th>
                    <th>Batch</th>
                    <th>Stock Level</th>
                    <th>Expiry</th>
                    <th>Quick Restock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($critical as $item): ?>
                <tr>
                    <td>
                        <div style="font-weight: 700; color: #111827;"><?php echo htmlspecialchars($item['medication_name']); ?></div>
                    </td>
                    <td style="color: #6b7280;"><?php echo htmlspecialchars($item['generic_name'] ?? '—'); ?></td>
                    <td style="color: #9ca3af; font-size: 0.82em; font-family: monospace;"><?php echo htmlspecialchars($item['batch_number'] ?? '—'); ?></td>
                    <td>
                        <?php if ($item['quantity'] == 0): ?>
                            <span class="stock-badge" style="background: #1f2937; color: white;">OUT OF STOCK</span>
                        <?php else: ?>
                            <span class="stock-badge" style="background: #fee2e2; color: #dc2626;">
                                <i class="fas fa-arrow-down"></i> <?php echo $item['quantity']; ?> units
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['expiry_date']): ?>
                            <?php $dexp = $item['days_to_exp']; ?>
                            <span style="color: <?php echo $dexp < 0 ? '#dc2626' : ($dexp <= 7 ? '#ea580c' : '#374151'); ?>; font-weight: 600; font-size: 0.88em;">
                                <?php echo $dexp < 0 ? '⚠ EXPIRED' : date('d M Y', strtotime($item['expiry_date'])); ?>
                                <?php if ($dexp >= 0 && $dexp <= 30): ?>
                                    <br><span style="color: #ea580c; font-size: 0.85em;">(<?php echo $dexp; ?> days left)</span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #9ca3af;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="" class="restock-form">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="quick_restock" value="1">
                            <input type="hidden" name="med_id" value="<?php echo $item['id']; ?>">
                            <input type="number" name="add_qty" min="1" placeholder="Qty" required>
                            <button type="submit"><i class="fas fa-plus"></i> Add</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- WARNING Section -->
    <?php if (!empty($warning)): ?>
    <div class="alert-section">
        <div class="section-header" style="border-color: #fde68a;">
            <div style="width: 32px; height: 32px; background: #fef9c3; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #d97706;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 style="margin: 0; font-size: 1em; font-weight: 800; color: #b45309;">LOW WARNING — Restock Soon</h3>
            <span style="background: #fef9c3; color: #b45309; border-radius: 99px; padding: 2px 10px; font-size: 0.8em; font-weight: 700; margin-left: auto;"><?php echo count($warning); ?> items</span>
        </div>
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Medication</th>
                    <th>Generic Name</th>
                    <th>Stock Level</th>
                    <th>Unit Price</th>
                    <th>Expiry</th>
                    <th>Quick Restock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($warning as $item): ?>
                <tr>
                    <td style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($item['medication_name']); ?></td>
                    <td style="color: #6b7280;"><?php echo htmlspecialchars($item['generic_name'] ?? '—'); ?></td>
                    <td>
                        <span class="stock-badge" style="background: #fef9c3; color: #92400e;">
                            <?php echo $item['quantity']; ?> units
                        </span>
                    </td>
                    <td style="color: #374151;">₹<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td style="color: #374151; font-size: 0.88em;">
                        <?php echo $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '—'; ?>
                        <?php if ($item['days_to_exp'] !== null && $item['days_to_exp'] <= 30 && $item['days_to_exp'] >= 0): ?>
                            <br><span style="color: #ea580c; font-size: 0.82em;">(<?php echo $item['days_to_exp']; ?> days)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="" class="restock-form">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="quick_restock" value="1">
                            <input type="hidden" name="med_id" value="<?php echo $item['id']; ?>">
                            <input type="number" name="add_qty" min="1" placeholder="Qty" required>
                            <button type="submit" style="background: #d97706;"><i class="fas fa-plus"></i> Add</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- EXPIRY Section -->
    <?php if (!empty($expiring)): ?>
    <div class="alert-section">
        <div class="section-header" style="border-color: #d8b4fe;">
            <div style="width: 32px; height: 32px; background: #ede9fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #7c3aed;">
                <i class="fas fa-calendar-times"></i>
            </div>
            <h3 style="margin: 0; font-size: 1em; font-weight: 800; color: #6d28d9;">EXPIRY ALERT — Expiring Within <?php echo EXPIRY_DAYS_WARN; ?> Days</h3>
            <span style="background: #ede9fe; color: #6d28d9; border-radius: 99px; padding: 2px 10px; font-size: 0.8em; font-weight: 700; margin-left: auto;"><?php echo count($expiring); ?> items</span>
        </div>
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Medication</th>
                    <th>Batch</th>
                    <th>Stock</th>
                    <th>Expiry Date</th>
                    <th>Days Remaining</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expiring as $item): ?>
                <tr>
                    <td style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($item['medication_name']); ?></td>
                    <td style="color: #9ca3af; font-family: monospace; font-size: 0.82em;"><?php echo htmlspecialchars($item['batch_number'] ?? '—'); ?></td>
                    <td>
                        <span class="stock-badge" style="background: #f1f5f9; color: #374151;"><?php echo $item['quantity']; ?> units</span>
                    </td>
                    <td style="color: #374151; font-weight: 600;"><?php echo date('d M Y', strtotime($item['expiry_date'])); ?></td>
                    <td>
                        <?php $d = $item['days_to_exp']; ?>
                        <?php if ($d < 0): ?>
                            <span class="stock-badge" style="background: #1f2937; color: white;">EXPIRED</span>
                        <?php elseif ($d <= 7): ?>
                            <span class="stock-badge" style="background: #fee2e2; color: #dc2626;"><?php echo $d; ?> days</span>
                        <?php elseif ($d <= 15): ?>
                            <span class="stock-badge" style="background: #fef9c3; color: #b45309;"><?php echo $d; ?> days</span>
                        <?php else: ?>
                            <span class="stock-badge" style="background: #ede9fe; color: #6d28d9;"><?php echo $d; ?> days</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="inventory.php" class="btn btn-sm" style="background: #7c3aed; color: white; padding: 5px 12px; border-radius: 6px; font-size: 0.82em; font-weight: 600; text-decoration: none;">
                            <i class="fas fa-edit"></i> Manage
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (empty($critical) && empty($warning) && empty($expiring)): ?>
    <div style="text-align: center; padding: 80px 20px; color: #9ca3af;">
        <div style="width: 80px; height: 80px; background: #f0fdf4; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
            <i class="fas fa-check-circle" style="font-size: 2.5rem; color: #16a34a;"></i>
        </div>
        <h3 style="color: #374151;">All Clear!</h3>
        <p>All medications are well-stocked and none are expiring soon.</p>
        <a href="inventory.php" class="btn btn-primary" style="margin-top: 10px;">View Full Inventory</a>
    </div>
    <?php endif; ?>

    <!-- Reorder Suggestions -->
    <?php if (!empty($critical) || !empty($warning)): ?>
    <div style="background: white; border-radius: 14px; border: 1px solid #e5e7eb; padding: 22px 26px; margin-top: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <h4 style="margin: 0 0 14px; font-size: 0.9em; font-weight: 800; color: #374151; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="fas fa-clipboard-list" style="color: #6366f1;"></i> Reorder Summary
        </h4>
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <?php foreach (array_merge($critical, $warning) as $item): ?>
            <div style="background: #f1f5f9; border-radius: 8px; padding: 8px 14px; font-size: 0.82em; color: #374151;">
                <strong><?php echo htmlspecialchars($item['medication_name']); ?></strong>
                — <?php echo $item['quantity']; ?> left
                — Suggest reorder: <strong><?php echo max(50, (20 - (int)$item['quantity']) * 3); ?> units</strong>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top: 14px;">
            <button onclick="printReorder()" style="background: #6366f1; color: white; border: none; border-radius: 8px; padding: 10px 20px; font-weight: 700; font-size: 0.88em; cursor: pointer;">
                <i class="fas fa-print"></i> Print Reorder List
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function printReorder() {
    window.print();
}
</script>

<?php include '../../includes/footer.php'; ?>
