<?php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['patient']);

$page_title = "Patient Dashboard";
include '../includes/header.php';

$user_id = get_user_id();
$patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
$patient_id = $patient['id'];

// 1. Upcoming Visits (Scheduled, Future)
$upcoming_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                                 WHERE patient_id = $1 AND appointment_time > NOW() AND status = 'scheduled'", 
                                 [$patient_id])['c'];

// 2. Active Prescriptions (Total for now, as schema lacks status)
$rx_count = db_select_one("SELECT COUNT(*) as c FROM prescriptions WHERE patient_id = $1", [$patient_id])['c'];

// 3. Past Visits (Completed or Past)
$past_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                             WHERE patient_id = $1 AND (status = 'completed' OR (appointment_time < NOW() AND status != 'cancelled'))", 
                             [$patient_id])['c'];

// 4. Pending Bills (Count of unpaid invoices)
$pending_bills_count = db_select_one("SELECT COUNT(*) as c FROM billing WHERE patient_id = $1 AND status = 'pending'", [$patient_id])['c'];
?>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        border-radius: 16px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 160px;
        position: relative;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: none;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    }
    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .stat-value {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #1e293b;
    }
    .stat-label {
        font-size: 14px;
        color: #64748b;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .stat-badge {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Card Variants */
    .card-purple { background-color: #EADCF8; }
    .card-purple .stat-icon { background-color: rgba(255,255,255,0.6); color: #7E22CE; }
    .card-purple .stat-badge { background-color: rgba(255,255,255,0.6); color: #6B21A8; }
    
    .card-blue { background-color: #D6E4FF; }
    .card-blue .stat-icon { background-color: rgba(255,255,255,0.6); color: #2563EB; }
    .card-blue .stat-badge { background-color: rgba(255,255,255,0.6); color: #1E40AF; }
    
    .card-yellow { background-color: #FEF9C3; }
    .card-yellow .stat-icon { background-color: rgba(255,255,255,0.6); color: #CA8A04; }
    .card-yellow .stat-badge { background-color: rgba(255,255,255,0.6); color: #854D0E; }
    
    .card-gray { background-color: #F1F5F9; }
    .card-gray .stat-icon { background-color: rgba(255,255,255,0.6); color: #475569; }
    .card-gray .stat-badge { background-color: rgba(255,255,255,0.6); color: #334155; }
</style>

<div class="dashboard-stats">
    <!-- 1. Upcoming Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-purple" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📅</div>
            <div style="color: #6B21A8;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $upcoming_count; ?></div>
            <div class="stat-label">
                Upcoming Visits <span class="stat-badge">Next</span>
            </div>
            <div style="font-size: 12px; color: #6B21A8; margin-top: 4px;">Scheduled appointments</div>
        </div>
    </a>

    <!-- 2. Prescriptions -->
    <a href="/modules/ehr/prescriptions.php" class="stat-card card-blue" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">💊</div>
            <div style="color: #1E40AF;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $rx_count; ?></div>
            <div class="stat-label">
                Prescriptions <span class="stat-badge">Active</span>
            </div>
            <div style="font-size: 12px; color: #1E40AF; margin-top: 4px;">Active medications</div>
        </div>
    </a>

    <!-- 3. Past Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-yellow" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📁</div>
            <div style="color: #854D0E;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $past_count; ?></div>
            <div class="stat-label">
                Past Visits <span class="stat-badge">Total</span>
            </div>
            <div style="font-size: 12px; color: #854D0E; margin-top: 4px;">Completed appointments</div>
        </div>
    </a>

    <!-- 4. Pending Bills -->
    <a href="/modules/billing/invoices.php" class="stat-card" style="text-decoration: none; color: inherit; background-color: #fee2e2;">
        <div class="stat-card-header">
            <div class="stat-icon" style="color: #dc2626; background: rgba(255,255,255,0.6);">💳</div>
            <div style="color: #991b1b;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $pending_bills_count; ?></div>
            <div class="stat-label">
                Pending Bills <span class="stat-badge" style="background: rgba(255,255,255,0.6); color: #7f1d1d;">Due</span>
            </div>
            <div style="font-size: 12px; color: #991b1b; margin-top: 4px;">Unpaid invoices</div>
        </div>
    </a>
    </div>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        My Health Overview
        <a href="/modules/ehr/edit_profile.php" class="btn-sm btn-primary" style="text-decoration: none;">
            <i class="fas fa-user-edit"></i> Edit Profile
        </a>
    </div>
    <div class="card-body">
        <p>Welcome to your health dashboard. Use the quick actions below to manage your care.</p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
