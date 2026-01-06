<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Doctor, Nurse, Lab Tech
$allowed_roles = ['admin', 'doctor', 'nurse', 'lab_tech'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Blood Bank Dashboard";
require_once '../../includes/header.php';

// Stats
$total_units = db_select("SELECT SUM(quantity) as total FROM blood_inventory")[0]['total'] ?: 0;
$donors = count(db_select("SELECT id FROM blood_donors"));
$pending_requests = count(db_select("SELECT id FROM blood_requests WHERE status = 'pending'"));

// Blood Group Breakdown
$inventory = db_select("SELECT blood_group, quantity, status FROM blood_inventory ORDER BY blood_group");
$stock = [];
foreach ($inventory as $item) {
    if (!isset($stock[$item['blood_group']])) $stock[$item['blood_group']] = 0;
    $stock[$item['blood_group']] += $item['quantity'];
}

$recent_donors = db_select("SELECT * FROM blood_donors ORDER BY created_at DESC LIMIT 5");
?>

<div class="main-content">
    <div class="dashboard-header">
        <h1>Blood Bank Center</h1>
        <div class="header-actions">
            <a href="inventory.php" class="btn-primary">
                <i class="fas fa-boxes"></i> Manage Inventory
            </a>
            <a href="inventory.php?tab=donors" class="btn-secondary">
                <i class="fas fa-hand-holding-heart"></i> Register Donor
            </a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon-wrapper bg-danger text-white">
                <i class="fas fa-burn"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $total_units; ?></h3>
                <p>Total Units in Stock</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-wrapper bg-primary text-white">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $donors; ?></h3>
                <p>Registered Donors</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="icon-wrapper bg-warning text-white">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $pending_requests; ?></h3>
                <p>Pending Requests</p>
            </div>
        </div>
    </div>

    <div class="blood-grid">
        <!-- Stock Visualization -->
        <div class="card">
            <div class="card-header">
                <h3>Current Stock Levels</h3>
            </div>
            <div class="stock-container">
                <?php 
                $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                foreach ($groups as $group): 
                    $qty = $stock[$group] ?? 0;
                    $percent = min(100, ($qty / 20) * 100); // Assume 20 is "full" for viz
                    $color = $qty < 5 ? '#e74c3c' : ($qty < 10 ? '#f1c40f' : '#2ecc71');
                ?>
                <div class="blood-bag-item">
                    <div class="bag-icon" style="color: <?php echo $color; ?>;">
                        <i class="fas fa-tint fa-3x"></i>
                        <span class="bag-label"><?php echo $group; ?></span>
                    </div>
                    <div class="bag-info">
                        <strong><?php echo $qty; ?> Units</strong>
                        <div class="progress-bar">
                            <div class="fill" style="width: <?php echo $percent; ?>%; background: <?php echo $color; ?>;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Donors -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Donors</h3>
            </div>
            <div class="list-group">
                <?php foreach ($recent_donors as $donor): ?>
                <div class="list-item">
                    <div class="avatar-circle"><?php echo $donor['blood_group']; ?></div>
                    <div class="item-details">
                        <h4><?php echo htmlspecialchars($donor['name']); ?></h4>
                        <p class="text-muted">
                            <?php echo $donor['gender']; ?>, <?php echo $donor['age']; ?> yrs | 
                            Last Donation: <?php echo $donor['last_donation_date'] ? date('M d, Y', strtotime($donor['last_donation_date'])) : 'Never'; ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($recent_donors)): ?>
                    <p class="text-center p-3">No donors registered yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
.header-actions { display: flex; gap: 1rem; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.stat-card { background: white; padding: 1.5rem; border-radius: 12px; display: flex; gap: 1rem; align-items: center; box-shadow: var(--card-shadow); }
.icon-wrapper { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.bg-danger { background: #e74c3c; } .bg-primary { background: var(--primary-color); } .bg-warning { background: #f1c40f; } .text-white { color: white; }

.blood-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
@media(max-width: 900px) { .blood-grid { grid-template-columns: 1fr; } }

.stock-container { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; padding: 1rem; }
.blood-bag-item { text-align: center; background: #f8f9fa; padding: 1rem; border-radius: 10px; }
.bag-icon { position: relative; margin-bottom: 0.5rem; }
.bag-label { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: bold; font-size: 0.9rem; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
.progress-bar { height: 6px; background: #eee; border-radius: 3px; margin-top: 0.5rem; overflow: hidden; }
.fill { height: 100%; }

.avatar-circle { width: 40px; height: 40px; background: #e74c3c; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem; }
.list-item { display: flex; align-items: center; gap: 1rem; padding: 1rem; border-bottom: 1px solid #eee; }
</style>

<?php require_once '../../includes/footer.php'; ?>
