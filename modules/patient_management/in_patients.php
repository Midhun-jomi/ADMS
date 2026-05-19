<?php
// modules/patient_management/in_patients.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse', 'receptionist']);

$page_title = "In-Patient Directory";
include '../../includes/header.php';

// Fetch Admitted Patients with extended details and latest vitals
// Fetch Admitted Patients with extended details and specific vitals using subqueries
$sql = "SELECT a.id as admission_id, a.admission_date, a.diagnosis, a.status as admission_status,
               p.id as patient_id, p.first_name, p.last_name, p.date_of_birth, p.gender, p.blood_group, p.phone, p.uhid,
               r.room_number, r.room_type, r.location,
               (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image,
               (SELECT MAX(recorded_at) FROM patient_health_metrics WHERE patient_id = p.id) as last_vitals_time,
               (SELECT metric_value FROM patient_health_metrics WHERE patient_id = p.id AND metric_type = 'heart_rate' ORDER BY recorded_at DESC LIMIT 1) as hr_json,
               (SELECT metric_value FROM patient_health_metrics WHERE patient_id = p.id AND metric_type = 'bp_systolic' ORDER BY recorded_at DESC LIMIT 1) as bps_json,
               (SELECT metric_value FROM patient_health_metrics WHERE patient_id = p.id AND metric_type = 'bp_diastolic' ORDER BY recorded_at DESC LIMIT 1) as bpd_json
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        JOIN rooms r ON a.room_id = r.id
        WHERE a.status = 'admitted'
        ORDER BY r.room_number ASC";

$in_patients = db_select($sql);
$total_count = count($in_patients);

// Fetch actual available beds from rooms table
$available_beds = db_select_one("SELECT COUNT(*) as count FROM rooms WHERE status = 'available'")['count'];
$total_beds = db_select_one("SELECT COUNT(*) as count FROM rooms")['count'];
?>

<div class="main-content">
    <!-- Premium Command Header -->
    <div class="command-header mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="glow-text">In-Patient Command</h1>
                <p class="text-secondary">Real-time clinical oversight across all hospital wards.</p>
            </div>
          
        </div>
        
        <!-- Ward Pulse Summary -->
        <div class="ward-pulse-container mb-4">
            <div class="pulse-card">
                <div class="pulse-icon"><i class="fas fa-users"></i></div>
                <div class="pulse-data">
                    <span class="pulse-val"><?php echo $total_count; ?></span>
                    <span class="pulse-lab">Total Occupancy</span>
                </div>
                <div class="pulse-progress"><div class="bar" style="width: <?php echo $total_beds > 0 ? min(100, ($total_count/$total_beds)*100) : 0; ?>%; background: var(--primary);"></div></div>
            </div>
            <?php $icu_count = count(array_filter($in_patients, fn($p) => stripos($p['room_type'], 'ICU') !== false)); ?>
            <div class="pulse-card critical">
                <div class="pulse-icon"><i class="fas fa-heartbeat"></i></div>
                <div class="pulse-data">
                    <span class="pulse-val"><?php echo $icu_count; ?></span>
                    <span class="pulse-lab">Critical Care</span>
                </div>
                <div class="pulse-progress"><div class="bar" style="width: <?php echo min(100, $icu_count*10); ?>%; background: var(--danger);"></div></div>
            </div>
            <div class="pulse-card success">
                <div class="pulse-icon"><i class="fas fa-door-open"></i></div>
                <div class="pulse-data">
                    <span class="pulse-val"><?php echo $available_beds; ?></span>
                    <span class="pulse-lab">Available Beds</span>
                </div>
                <div class="pulse-progress"><div class="bar" style="width: <?php echo $total_beds > 0 ? min(100, ($available_beds/$total_beds)*100) : 0; ?>%; background: var(--success);"></div></div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="glass-toolbar mb-4">
        <div class="search-wrapper">
            <i class="fas fa-search"></i>
            <input type="text" id="patientSearch" placeholder="Search by name, room, or UHID..." onkeyup="filterInPatients()">
        </div>
        <div class="filter-pills">
            <span class="f-pill active">All Wards</span>
            <span class="f-pill">ICU Only</span>
            <span class="f-pill">Ready for Discharge</span>
        </div>
    </div>

    <!-- Patient Grid -->
    <div class="patient-tiles-container" id="patientTiles">
        <?php if (empty($in_patients)): ?>
            <div class="empty-state">
                <i class="fas fa-bed"></i>
                <h3>No Active Admissions</h3>
                <p>All wards are currently clear or no patients match your search.</p>
            </div>
        <?php else: ?>
            <?php foreach ($in_patients as $p): 
                $age = date_diff(date_create($p['date_of_birth']), date_create('today'))->y;
                $p_img = $p['p_image'] ?: "https://ui-avatars.com/api/?name=" . urlencode($p['first_name'] . ' ' . $p['last_name']);
                $is_critical = stripos($p['room_type'], 'ICU') !== false;
                
                $hr_data = json_decode($p['hr_json'] ?? '{}', true);
                $bps_data = json_decode($p['bps_json'] ?? '{}', true);
                $bpd_data = json_decode($p['bpd_json'] ?? '{}', true);
                $hr = $hr_data['value'] ?? null;
                $bp = ($bps_data['value'] ?? null) && ($bpd_data['value'] ?? null) ? $bps_data['value'] . '/' . $bpd_data['value'] : null;
            ?>
                <div class="patient-tile <?php echo $is_critical ? 'critical' : ''; ?>" data-search="<?php echo strtolower($p['first_name'] . ' ' . $p['last_name'] . ' ' . $p['room_number']); ?>">
                    <div class="tile-header">
                        <div class="room-indicator">
                            <span class="r-no">#<?php echo htmlspecialchars($p['room_number']); ?></span>
                            <span class="r-type"><?php echo htmlspecialchars($p['room_type']); ?></span>
                        </div>
                        <div class="tile-actions">
                            <button class="t-btn discharge" onclick="confirmDischarge('<?php echo $p['admission_id']; ?>')" title="Discharge"><i class="fas fa-sign-out-alt"></i></button>
                        </div>
                    </div>
                    
                    <div class="tile-body">
                        <div class="p-profile">
                            <div class="avatar-box">
                                <img src="<?php echo $p_img; ?>" alt="Patient">
                                <?php if($is_critical): ?><span class="pulse-dot"></span><?php endif; ?>
                            </div>
                            <div class="p-name-box">
                                <h4 class="m-0"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></h4>
                                <small>P-<?php echo str_pad($p['uhid'], 4, '0', STR_PAD_LEFT); ?> &bull; <?php echo $age; ?>Y / <?php echo $p['gender'][0]; ?></small>
                            </div>
                        </div>

                        <div class="clinical-summary">
                            <div class="c-label">Diagnosis</div>
                            <div class="c-val"><?php echo htmlspecialchars($p['diagnosis']); ?></div>
                        </div>

                        <div class="vitals-dashboard">
                            <div class="v-stat">
                                <span class="v-lab">Heart Rate</span>
                                <span class="v-val <?php echo ($hr && ($hr > 110 || $hr < 60)) ? 'alert' : ''; ?>">
                                    <i class="fas fa-heartbeat mr-1"></i><?php echo $hr ?: '--'; ?> <small>bpm</small>
                                </span>
                            </div>
                            <div class="v-stat">
                                <span class="v-lab">Blood Pressure</span>
                                <span class="v-val">
                                    <i class="fas fa-tachometer-alt mr-1"></i><?php echo $bp ?: '--'; ?> <small>mmHg</small>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="tile-footer">
                        <div class="last-update">
                            <i class="far fa-clock mr-1"></i> Updated <?php echo $p['last_vitals_time'] ? date('h:i A', strtotime($p['last_vitals_time'])) : 'N/A'; ?>
                        </div>
                        <div class="footer-links">
                            <a href="../ehr/history.php?patient_id=<?php echo $p['patient_id']; ?>">Records</a>
                            <a href="../patient_management/nursing_station.php" class="highlight">Nursing</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function filterInPatients() {
    let input = document.getElementById('patientSearch').value.toLowerCase();
    let tiles = document.querySelectorAll('.patient-tile');
    
    tiles.forEach(tile => {
        let text = tile.getAttribute('data-search');
        tile.style.display = text.includes(input) ? '' : 'none';
    });
}

function confirmDischarge(id) {
    if(confirm('Initiate discharge process for this patient?')) {
        window.location.href = '../patient_management/discharge.php?admission_id=' + id;
    }
}
</script>

<style>
    :root {
        --primary: #4f46e5;
        --secondary: #64748b;
        --success: #10b981;
        --danger: #f43f5e;
        --warning: #f59e0b;
        --indigo-soft: #eef2ff;
        --slate-50: #f8fafc;
        --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
    }

    .main-content { background: #f3f4f6; min-height: 100vh; padding: 40px; font-family: 'Inter', sans-serif; }
    
    /* Glow Text Header */
    .glow-text { font-size: 2.8rem; font-weight: 900; letter-spacing: -2px; color: #1e293b; margin: 0; }
    
    /* Ward Pulse */
    .ward-pulse-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
    .pulse-card { background: white; padding: 25px; border-radius: 20px; box-shadow: var(--card-shadow); display: flex; flex-direction: column; position: relative; border: 1px solid #e2e8f0; }
    .pulse-icon { width: 40px; height: 40px; border-radius: 12px; background: var(--indigo-soft); color: var(--primary); display: flex; align-items: center; justify-content: center; margin-bottom: 15px; }
    .pulse-val { font-size: 2rem; font-weight: 800; color: #1e293b; display: block; line-height: 1; }
    .pulse-lab { font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
    .pulse-progress { height: 6px; background: #f1f5f9; border-radius: 3px; margin-top: 15px; overflow: hidden; }
    .pulse-progress .bar { height: 100%; border-radius: 3px; transition: width 1s ease; }
    
    .pulse-card.critical .pulse-icon { background: #fff1f2; color: var(--danger); }
    .pulse-card.success .pulse-icon { background: #ecfdf5; color: var(--success); }

    /* Toolbar */
    .glass-toolbar { background: white; padding: 15px 25px; border-radius: 20px; box-shadow: var(--card-shadow); display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0; }
    .search-wrapper { position: relative; flex: 1; max-width: 450px; }
    .search-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
    .search-wrapper input { width: 100%; padding: 12px 12px 12px 45px; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; outline: none; transition: 0.3s; font-weight: 500; }
    .search-wrapper input:focus { border-color: var(--primary); background: white; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }
    
    .filter-pills { display: flex; gap: 10px; }
    .f-pill { padding: 10px 18px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; color: #64748b; background: #f1f5f9; cursor: pointer; transition: 0.2s; }
    .f-pill:hover { background: #e2e8f0; }
    .f-pill.active { background: var(--primary); color: white; }

    /* Glass Button */
    .glass-btn { padding: 12px 25px; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; transition: 0.3s; }
    .glass-btn.primary { background: #1e293b; color: white; }
    .glass-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }

    /* Patient Tiles Grid */
    .patient-tiles-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 25px; }
    
    .patient-tile { background: white; border-radius: 24px; border: 1px solid #e2e8f0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: var(--card-shadow); display: flex; flex-direction: column; overflow: hidden; }
    .patient-tile:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
    .patient-tile.critical { border-left: 6px solid var(--danger); }
    
    .tile-header { padding: 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; }
    .room-indicator { display: flex; flex-direction: column; }
    .r-no { font-size: 1.2rem; font-weight: 900; color: #1e293b; }
    .r-type { font-size: 0.7rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; }
    
    .t-btn { width: 36px; height: 36px; border-radius: 10px; border: none; background: #f8fafc; color: #64748b; transition: 0.2s; }
    .t-btn:hover { background: var(--danger); color: white; }

    .tile-body { padding: 20px; flex: 1; }
    .p-profile { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
    .avatar-box { position: relative; }
    .avatar-box img { width: 60px; height: 60px; border-radius: 18px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .pulse-dot { position: absolute; top: -5px; right: -5px; width: 14px; height: 14px; background: var(--danger); border-radius: 50%; border: 3px solid white; animation: pulse-red 2s infinite; }
    @keyframes pulse-red { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(244, 63, 94, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(244, 63, 94, 0); } }

    .p-name-box h4 { font-weight: 800; color: #1e293b; letter-spacing: -0.5px; }
    .p-name-box small { color: #64748b; font-weight: 600; }

    .clinical-summary { background: #f8fafc; padding: 12px 15px; border-radius: 14px; margin-bottom: 20px; }
    .c-label { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; margin-bottom: 4px; }
    .c-val { font-size: 0.9rem; font-weight: 600; color: #475569; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

    .vitals-dashboard { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .v-stat { display: flex; flex-direction: column; }
    .v-lab { font-size: 0.65rem; font-weight: 700; color: #94a3b8; margin-bottom: 2px; }
    .v-val { font-size: 1.1rem; font-weight: 800; color: #1e293b; }
    .v-val.alert { color: var(--danger); }
    .v-val small { font-size: 0.65rem; font-weight: 600; opacity: 0.6; }

    .tile-footer { padding: 15px 20px; background: #fafafa; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .last-update { font-size: 0.75rem; font-weight: 600; color: #94a3b8; }
    
    .footer-links { display: flex; gap: 12px; }
    .footer-links a { font-size: 0.8rem; font-weight: 700; color: #64748b; text-decoration: none; transition: 0.2s; }
    .footer-links a:hover { color: var(--primary); }
    .footer-links a.highlight { color: var(--primary); background: var(--indigo-soft); padding: 4px 10px; border-radius: 8px; }

    .empty-state { grid-column: 1 / -1; text-align: center; padding: 60px; color: #94a3b8; }
    .empty-state i { font-size: 4rem; margin-bottom: 20px; opacity: 0.2; }

    @media (max-width: 768px) {
        .glass-toolbar { flex-direction: column; gap: 15px; }
        .search-wrapper { max-width: 100%; }
        .filter-pills { overflow-x: auto; width: 100%; padding-bottom: 5px; }
    }
</style>

<?php include '../../includes/footer.php'; ?>
