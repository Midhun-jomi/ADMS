<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Receptionist, Nurse
$allowed_roles = ['admin', 'receptionist', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Emergency Dashboard";
require_once '../../includes/header.php';

// Stats
$active_calls = count(db_select("SELECT id FROM emergency_calls WHERE status IN ('received', 'dispatched')"));
$available_ambulances = count(db_select("SELECT id FROM ambulance WHERE status = 'available'"));
$on_mission = count(db_select("SELECT id FROM ambulance WHERE status = 'on_mission'"));

$recent_calls = db_select("
    SELECT c.*, a.vehicle_number 
    FROM emergency_calls c 
    LEFT JOIN ambulance a ON c.ambulance_id = a.id 
    ORDER BY c.created_at DESC 
    LIMIT 5
");

// Map simulation or list
$ambulances = db_select("SELECT * FROM ambulance");
?>

<div class="main-content">
    <div class="dashboard-header">
        <h1>Emergency Response Center</h1>
        <a href="dispatch.php" class="btn-danger-lg pulse-button">
            <i class="fas fa-phone-alt"></i> New Emergency Call
        </a>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card urgent">
            <h3><?php echo $active_calls; ?></h3>
            <p>Active Emergencies</p>
        </div>
        <div class="stat-card success">
            <h3><?php echo $available_ambulances; ?></h3>
            <p>Ambulances Available</p>
        </div>
        <div class="stat-card warning">
            <h3><?php echo $on_mission; ?></h3>
            <p>On Mission</p>
        </div>
    </div>

    <div class="split-view">
        <!-- Live Fleet Status -->
        <div class="card">
            <div class="card-header">
                <h3>Fleet Status</h3>
            </div>
            <div class="fleet-grid">
                <?php foreach ($ambulances as $amb): ?>
                <div class="vehicle-card status-<?php echo $amb['status']; ?>">
                    <i class="fas fa-ambulance fa-2x"></i>
                    <h4><?php echo htmlspecialchars($amb['vehicle_number']); ?></h4>
                    <p class="status-text"><?php echo strtoupper(str_replace('_', ' ', $amb['status'])); ?></p>
                    <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($amb['current_location'] ?: 'Station'); ?></small>
                </div>
                <?php endforeach; ?>
                <?php if (empty($ambulances)): ?>
                    <p class="text-muted p-3">No ambulances registered. <a href="dispatch.php">Manage Fleet</a></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Calls -->
        <div class="card">
            <div class="card-header">
                <h3>Recent Emergency Calls</h3>
            </div>
            <div class="list-group">
                <?php foreach ($recent_calls as $call): ?>
                <div class="list-item">
                    <div class="item-icon <?php echo $call['status'] == 'resolved' ? 'bg-success' : 'bg-danger'; ?>">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div class="item-details">
                        <h4><?php echo htmlspecialchars($call['location']); ?></h4>
                        <p class="text-muted">
                            Caller: <?php echo htmlspecialchars($call['caller_name']); ?> | 
                            <?php echo date('H:i', strtotime($call['created_at'])); ?> 
                            (<?php echo ucfirst($call['status']); ?>)
                        </p>
                        <?php if ($call['vehicle_number']): ?>
                            <small class="text-primary"><i class="fas fa-ambulance"></i> <?php echo $call['vehicle_number']; ?> dispatched</small>
                        <?php endif; ?>
                    </div>
                    <?php if ($call['status'] !== 'resolved'): ?>
                    <a href="dispatch.php?manage=<?php echo $call['id']; ?>" class="btn-small">Manage</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}
.btn-danger-lg {
    background: #e74c3c;
    color: white;
    padding: 1rem 2rem;
    border-radius: 50px;
    font-size: 1.2rem;
    font-weight: bold;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
}
.pulse-button {
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7); }
    70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(231, 76, 60, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(231, 76, 60, 0); }
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}
.stat-card {
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    color: white;
}
.urgent { background: linear-gradient(135deg, #ff416c, #ff4b2b); }
.success { background: linear-gradient(135deg, #11998e, #38ef7d); }
.warning { background: linear-gradient(135deg, #f7971e, #ffd200); }

.split-view {
    display: grid;
    grid-template-columns: 2fr 3fr;
    gap: 1.5rem;
}
.fleet-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 1rem;
    padding: 1rem;
}
.vehicle-card {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 10px;
    text-align: center;
    border: 2px solid transparent;
}
.status-available { border-color: #2ecc71; color: #2ecc71; }
.status-on_mission { border-color: #e74c3c; color: #e74c3c; animation: flash 2s infinite; }
.status-maintenance { border-color: #95a5a6; color: #95a5a6; opacity: 0.7; }

@keyframes flash {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.list-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid #eee;
}
.item-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}
.bg-danger { background: #e74c3c; }
.bg-success { background: #2ecc71; }
.item-details { flex: 1; }
</style>

<?php require_once '../../includes/footer.php'; ?>
