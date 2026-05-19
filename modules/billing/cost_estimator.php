<?php
// modules/billing/cost_estimator.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$page_title = "Cost Estimator";
include '../../includes/header.php';

$role = get_user_role();

// Procedure catalog with detailed cost breakdown
$procedures = [
    // Consultations
    'General Consultation'       => ['dept' => 'General Medicine',   'base' => 300,   'lab' => 0,    'meds' => 200,  'room' => 0,    'duration' => '30 min',  'icon' => 'fas fa-stethoscope',          'cat' => 'Consultation'],
    'Specialist Consultation'    => ['dept' => 'Specialty',          'base' => 700,   'lab' => 0,    'meds' => 300,  'room' => 0,    'duration' => '45 min',  'icon' => 'fas fa-user-md',              'cat' => 'Consultation'],
    'Emergency Consultation'     => ['dept' => 'Emergency',          'base' => 1500,  'lab' => 500,  'meds' => 600,  'room' => 800,  'duration' => 'Variable','icon' => 'fas fa-ambulance',            'cat' => 'Emergency'],
    // Diagnostics
    'Complete Blood Count (CBC)' => ['dept' => 'Laboratory',         'base' => 250,   'lab' => 250,  'meds' => 0,    'room' => 0,    'duration' => '2–4 hrs', 'icon' => 'fas fa-vial',                 'cat' => 'Lab Test'],
    'Lipid Profile'              => ['dept' => 'Laboratory',         'base' => 350,   'lab' => 350,  'meds' => 0,    'room' => 0,    'duration' => '4–6 hrs', 'icon' => 'fas fa-vial',                 'cat' => 'Lab Test'],
    'HbA1c (Diabetes)'          => ['dept' => 'Laboratory',         'base' => 400,   'lab' => 400,  'meds' => 0,    'room' => 0,    'duration' => '4 hrs',   'icon' => 'fas fa-tint',                 'cat' => 'Lab Test'],
    'Thyroid Profile (T3/T4)'    => ['dept' => 'Laboratory',         'base' => 650,   'lab' => 650,  'meds' => 0,    'room' => 0,    'duration' => '4 hrs',   'icon' => 'fas fa-vial',                 'cat' => 'Lab Test'],
    'Liver Function Test'        => ['dept' => 'Laboratory',         'base' => 550,   'lab' => 550,  'meds' => 0,    'room' => 0,    'duration' => '4 hrs',   'icon' => 'fas fa-vial',                 'cat' => 'Lab Test'],
    'Kidney Function Test'       => ['dept' => 'Laboratory',         'base' => 500,   'lab' => 500,  'meds' => 0,    'room' => 0,    'duration' => '4 hrs',   'icon' => 'fas fa-vial',                 'cat' => 'Lab Test'],
    'Chest X-Ray'                => ['dept' => 'Radiology',          'base' => 400,   'lab' => 400,  'meds' => 0,    'room' => 0,    'duration' => '30 min',  'icon' => 'fas fa-x-ray',                'cat' => 'Radiology'],
    'CT Scan (Chest/Abdomen)'    => ['dept' => 'Radiology',          'base' => 3500,  'lab' => 3500, 'meds' => 0,    'room' => 0,    'duration' => '1 hr',    'icon' => 'fas fa-x-ray',                'cat' => 'Radiology'],
    'MRI Brain'                  => ['dept' => 'Radiology',          'base' => 6000,  'lab' => 6000, 'meds' => 0,    'room' => 0,    'duration' => '1–2 hrs', 'icon' => 'fas fa-brain',                'cat' => 'Radiology'],
    'Ultrasound (Abdomen)'       => ['dept' => 'Radiology',          'base' => 800,   'lab' => 800,  'meds' => 0,    'room' => 0,    'duration' => '30 min',  'icon' => 'fas fa-heartbeat',            'cat' => 'Radiology'],
    'ECG'                        => ['dept' => 'Cardiology',         'base' => 300,   'lab' => 300,  'meds' => 0,    'room' => 0,    'duration' => '15 min',  'icon' => 'fas fa-heartbeat',            'cat' => 'Cardiology'],
    'Echocardiogram'             => ['dept' => 'Cardiology',         'base' => 2500,  'lab' => 2500, 'meds' => 0,    'room' => 0,    'duration' => '45 min',  'icon' => 'fas fa-heartbeat',            'cat' => 'Cardiology'],
    // Day Procedures
    'Minor Surgery'              => ['dept' => 'General Surgery',    'base' => 8000,  'lab' => 1500, 'meds' => 1500, 'room' => 3000, 'duration' => '1 day',   'icon' => 'fas fa-scalpel',              'cat' => 'Surgery'],
    'Major Surgery'              => ['dept' => 'General Surgery',    'base' => 50000, 'lab' => 5000, 'meds' => 8000, 'room' => 15000,'duration' => '5–7 days','icon' => 'fas fa-procedures',           'cat' => 'Surgery'],
    'Appendectomy'               => ['dept' => 'General Surgery',    'base' => 35000, 'lab' => 3000, 'meds' => 4000, 'room' => 10000,'duration' => '3–5 days','icon' => 'fas fa-procedures',           'cat' => 'Surgery'],
    'Cataract Surgery'           => ['dept' => 'Ophthalmology',      'base' => 25000, 'lab' => 2000, 'meds' => 3000, 'room' => 5000, 'duration' => '1–2 days','icon' => 'fas fa-eye',                  'cat' => 'Surgery'],
    'Knee Replacement'           => ['dept' => 'Orthopedics',        'base' => 120000,'lab' => 8000, 'meds' => 12000,'room' => 30000,'duration' => '7–10 days','icon' => 'fas fa-bone',                'cat' => 'Surgery'],
    'Dialysis (Single Session)'  => ['dept' => 'Nephrology',         'base' => 2500,  'lab' => 800,  'meds' => 500,  'room' => 500,  'duration' => '4–5 hrs', 'icon' => 'fas fa-tint',                 'cat' => 'Procedure'],
    'Chemotherapy Session'       => ['dept' => 'Oncology',           'base' => 15000, 'lab' => 2000, 'meds' => 8000, 'room' => 3000, 'duration' => '1 day',   'icon' => 'fas fa-capsules',             'cat' => 'Oncology'],
    'Delivery (Normal)'          => ['dept' => 'Gynaecology',        'base' => 15000, 'lab' => 2500, 'meds' => 2500, 'room' => 8000, 'duration' => '2–3 days','icon' => 'fas fa-baby',                 'cat' => 'Obstetrics'],
    'Delivery (C-Section)'       => ['dept' => 'Gynaecology',        'base' => 30000, 'lab' => 4000, 'meds' => 5000, 'room' => 12000,'duration' => '4–5 days','icon' => 'fas fa-baby',                 'cat' => 'Obstetrics'],
    'Physiotherapy Session'      => ['dept' => 'Physiotherapy',      'base' => 500,   'lab' => 0,    'meds' => 0,    'room' => 0,    'duration' => '45 min',  'icon' => 'fas fa-dumbbell',             'cat' => 'Therapy'],
    'Dental Extraction'          => ['dept' => 'Dental',             'base' => 800,   'lab' => 0,    'meds' => 200,  'room' => 0,    'duration' => '30 min',  'icon' => 'fas fa-tooth',                'cat' => 'Dental'],
    'Root Canal Treatment'       => ['dept' => 'Dental',             'base' => 4500,  'lab' => 200,  'meds' => 300,  'room' => 0,    'duration' => '1–2 hrs', 'icon' => 'fas fa-tooth',                'cat' => 'Dental'],
    'Vaccination'                => ['dept' => 'Preventive Medicine','base' => 400,   'lab' => 0,    'meds' => 300,  'room' => 0,    'duration' => '15 min',  'icon' => 'fas fa-syringe',              'cat' => 'Preventive'],
    'Colonoscopy'                => ['dept' => 'Gastroenterology',   'base' => 5000,  'lab' => 1000, 'meds' => 500,  'room' => 2000, 'duration' => '2–3 hrs', 'icon' => 'fas fa-procedures',           'cat' => 'Procedure'],
    'Endoscopy'                  => ['dept' => 'Gastroenterology',   'base' => 4000,  'lab' => 800,  'meds' => 400,  'room' => 1500, 'duration' => '1–2 hrs', 'icon' => 'fas fa-procedures',           'cat' => 'Procedure'],
    'LASIK Eye Surgery'          => ['dept' => 'Ophthalmology',      'base' => 45000, 'lab' => 2000, 'meds' => 2000, 'room' => 0,    'duration' => 'Day care','icon' => 'fas fa-eye',                  'cat' => 'Surgery'],
    'Angioplasty'                => ['dept' => 'Cardiology',         'base' => 85000, 'lab' => 5000, 'meds' => 10000,'room' => 15000,'duration' => '2–3 days','icon' => 'fas fa-heartbeat',            'cat' => 'Surgery'],
    'Hip Replacement'            => ['dept' => 'Orthopedics',        'base' => 110000,'lab' => 7000, 'meds' => 11000,'room' => 25000,'duration' => '7–10 days','icon' => 'fas fa-bone',                'cat' => 'Surgery'],
    'Kidney Stone Removal'       => ['dept' => 'Urology',            'base' => 45000, 'lab' => 3000, 'meds' => 4000, 'room' => 8000, 'duration' => '2–3 days','icon' => 'fas fa-procedures',           'cat' => 'Surgery'],
    'EEG (Brain Map)'            => ['dept' => 'Neurology',          'base' => 1200,  'lab' => 1200, 'meds' => 0,    'room' => 0,    'duration' => '1 hr',    'icon' => 'fas fa-brain',                'cat' => 'Lab Test'],
    'Newborn Checkup'            => ['dept' => 'Pediatrics',         'base' => 500,   'lab' => 0,    'meds' => 100,  'room' => 0,    'duration' => '30 min',  'icon' => 'fas fa-baby',                 'cat' => 'Consultation'],
    'ICU Stay (per day)'         => ['dept' => 'Critical Care',      'base' => 8000,  'lab' => 2000, 'meds' => 3000, 'room' => 8000, 'duration' => 'Per day', 'icon' => 'fas fa-heartbeat',            'cat' => 'ICU'],
];

// Group by category
$categories = [];
foreach ($procedures as $name => $info) {
    $categories[$info['cat']][$name] = $info;
}
ksort($categories);

// Check if patient has insurance
$has_insurance = false;
if ($role === 'patient') {
    $pat = db_select_one("SELECT id FROM patients WHERE user_id = $1", [get_user_id()]);
    if ($pat) {
        $ins = db_select_one("SELECT id FROM patient_insurance WHERE patient_id = $1", [$pat['id']]);
        $has_insurance = (bool)$ins;
    }
}
?>

<style>
.est-wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }
.est-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; }
@media (max-width: 900px) { .est-grid { grid-template-columns: 1fr; } }

/* Left panel */
.proc-search { position: sticky; top: 20px; }
.search-input-wrap { position: relative; margin-bottom: 16px; }
.search-input-wrap input { width: 100%; padding: 12px 16px 12px 42px; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 0.95em; outline: none; transition: border-color 0.2s; }
.search-input-wrap input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.search-input-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; }

.cat-section { margin-bottom: 20px; }
.cat-label { font-size: 0.75em; font-weight: 800; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; padding-left: 4px; }
.proc-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; cursor: pointer; border: 1px solid transparent; transition: all 0.15s; margin-bottom: 6px; background: white; }
.proc-item:hover { border-color: #e5e7eb; background: #f9fafb; }
.proc-item.selected { border-color: #6366f1; background: #ede9fe; }
.proc-item.selected .proc-price { color: #6366f1; }
.proc-icon { width: 36px; height: 36px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #6366f1; font-size: 0.9em; flex-shrink: 0; }
.proc-name { font-weight: 600; color: #111827; font-size: 0.88em; flex: 1; }
.proc-dept { font-size: 0.75em; color: #9ca3af; }
.proc-price { font-weight: 700; color: #374151; font-size: 0.88em; white-space: nowrap; }

/* Right panel */
.est-panel { background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 4px 20px rgba(0,0,0,0.07); }
.est-panel-header { padding: 20px 24px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 10px; }
.est-body { padding: 24px; }
.breakdown-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.9em; }
.breakdown-row:last-child { border-bottom: none; }
.breakdown-label { color: #6b7280; }
.breakdown-val { font-weight: 600; color: #111827; }
.total-row { display: flex; justify-content: space-between; align-items: center; background: #ede9fe; border-radius: 10px; padding: 16px 18px; margin-top: 14px; }
.ins-strip { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; margin-top: 10px; font-size: 0.85em; color: #166534; }
.selected-list { min-height: 60px; }
.sel-chip { display: inline-flex; align-items: center; gap: 6px; background: #ede9fe; color: #4f46e5; border-radius: 99px; padding: 4px 12px; font-size: 0.8em; font-weight: 600; margin: 3px; cursor: pointer; }
.sel-chip:hover { background: #ddd6fe; }
.clear-btn { background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 0.82em; padding: 0; margin-left: 4px; }
.empty-est { text-align: center; padding: 30px; color: #d1d5db; }
.book-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: white; border: none; border-radius: 10px; font-size: 0.95em; font-weight: 700; cursor: pointer; margin-top: 12px; transition: all 0.2s; }
.book-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,0.3); }
.book-btn:disabled { background: #e5e7eb; color: #9ca3af; transform: none; box-shadow: none; cursor: not-allowed; }

/* Sticky sidebar on desktop */
@media (min-width: 901px) {
    .est-sidebar { position: sticky; top: 20px; max-height: calc(100vh - 40px); overflow-y: auto; }
}
</style>

<div class="est-wrap">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 24px;">
        <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #6366f1, #4f46e5); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2em;">
            <i class="fas fa-calculator"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 1.3em; font-weight: 800; color: #111827;">Pre-Procedure Cost Estimator</h2>
            <p style="margin: 0; color: #6b7280; font-size: 0.85em;">Select procedures to see a detailed cost breakdown before booking</p>
        </div>
    </div>

    <?php if ($has_insurance): ?>
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; font-size: 0.88em; color: #166534; display: flex; align-items: center; gap: 8px;">
        <i class="fas fa-shield-alt"></i>
        <span>You have active insurance coverage. Estimated out-of-pocket may be lower — contact billing for exact coverage details.</span>
    </div>
    <?php endif; ?>

    <div class="est-grid">
        <!-- Left: Procedure List -->
        <div>
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="procSearch" placeholder="Search procedures, departments..." oninput="filterProcedures(this.value)">
            </div>

            <div id="procList">
                <?php foreach ($categories as $cat => $procs): ?>
                <div class="cat-section" data-cat="<?php echo htmlspecialchars($cat); ?>">
                    <div class="cat-label"><?php echo htmlspecialchars($cat); ?></div>
                    <?php foreach ($procs as $name => $info): ?>
                    <div class="proc-item" id="item-<?php echo md5($name); ?>"
                         data-name="<?php echo htmlspecialchars($name); ?>"
                         data-dept="<?php echo htmlspecialchars($info['dept']); ?>"
                         data-base="<?php echo $info['base']; ?>"
                         data-lab="<?php echo $info['lab']; ?>"
                         data-meds="<?php echo $info['meds']; ?>"
                         data-room="<?php echo $info['room']; ?>"
                         data-dur="<?php echo htmlspecialchars($info['duration']); ?>"
                         data-icon="<?php echo htmlspecialchars($info['icon']); ?>"
                         onclick="toggleProcedure(this)">
                        <div class="proc-icon"><i class="<?php echo $info['icon']; ?>"></i></div>
                        <div style="flex: 1;">
                            <div class="proc-name"><?php echo htmlspecialchars($name); ?></div>
                            <div class="proc-dept"><?php echo htmlspecialchars($info['dept']); ?> &bull; <?php echo htmlspecialchars($info['duration']); ?></div>
                        </div>
                        <div class="proc-price">₹<?php echo number_format($info['base'] + $info['lab'] + $info['meds'] + $info['room']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right: Estimate Panel -->
        <div class="est-sidebar">
            <div class="est-panel">
                <div class="est-panel-header">
                    <i class="fas fa-file-invoice-dollar" style="color: #6366f1;"></i>
                    <span style="font-weight: 700; color: #111827; font-size: 0.95em;">Estimate Summary</span>
                    <button onclick="clearAll()" style="margin-left: auto; background: none; border: none; color: #9ca3af; font-size: 0.82em; cursor: pointer; font-weight: 600;">Clear All</button>
                </div>
                <div class="est-body">
                    <!-- Selected procedures chips -->
                    <div id="selectedChips" class="selected-list">
                        <div id="chipsContainer"></div>
                        <div class="empty-est" id="emptyMsg">
                            <i class="fas fa-mouse-pointer" style="display: block; font-size: 2rem; margin-bottom: 8px;"></i>
                            <div style="font-size: 0.85em;">Click procedures on the left to add them to your estimate</div>
                        </div>
                    </div>

                    <div id="breakdownSection" style="display: none; margin-top: 16px;">
                        <div style="font-size: 0.78em; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">Cost Breakdown</div>
                        <div class="breakdown-row"><span class="breakdown-label"><i class="fas fa-user-md" style="width:16px; color:#6366f1;"></i> Consultation / Procedure Fee</span><span class="breakdown-val" id="bdBase">₹0</span></div>
                        <div class="breakdown-row"><span class="breakdown-label"><i class="fas fa-vial" style="width:16px; color:#10b981;"></i> Lab &amp; Diagnostics</span><span class="breakdown-val" id="bdLab">₹0</span></div>
                        <div class="breakdown-row"><span class="breakdown-label"><i class="fas fa-pills" style="width:16px; color:#f59e0b;"></i> Medications</span><span class="breakdown-val" id="bdMeds">₹0</span></div>
                        <div class="breakdown-row"><span class="breakdown-label"><i class="fas fa-bed" style="width:16px; color:#0ea5e9;"></i> Room &amp; Facilities</span><span class="breakdown-val" id="bdRoom">₹0</span></div>

                        <div class="total-row">
                            <div>
                                <div style="font-size: 0.8em; color: #6366f1; font-weight: 600;">ESTIMATED TOTAL</div>
                                <div style="font-size: 0.75em; color: #9ca3af;">Approx. cost ±15%</div>
                            </div>
                            <div style="font-size: 1.6rem; font-weight: 900; color: #4f46e5;" id="grandTotal">₹0</div>
                        </div>

                        <?php if ($has_insurance): ?>
                        <div class="ins-strip">
                            <i class="fas fa-shield-alt"></i> Insurance may cover up to <strong>80%</strong> of eligible charges.
                            Estimated out-of-pocket: <strong id="outOfPocket">₹0</strong>
                        </div>
                        <?php endif; ?>

                        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; margin-top: 12px; font-size: 0.8em; color: #92400e;">
                            <i class="fas fa-exclamation-triangle"></i>
                            This is an <strong>estimate only</strong>. Actual charges may vary based on clinical complexity, stay duration, and consumables.
                        </div>



                        <!-- Print estimate -->
                        <button onclick="printEstimate()" style="width: 100%; padding: 10px; background: white; color: #6366f1; border: 1px solid #c7d2fe; border-radius: 10px; font-size: 0.88em; font-weight: 600; cursor: pointer; margin-top: 8px;">
                            <i class="fas fa-print" style="margin-right: 6px;"></i>Print Estimate
                        </button>
                    </div>
                </div>
            </div>

            <!-- Duration estimate -->
            <div id="durationCard" style="display: none; background: white; border-radius: 12px; border: 1px solid #e5e7eb; padding: 16px 20px; margin-top: 14px;">
                <div style="font-size: 0.78em; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">Selected Procedures</div>
                <div id="durationList"></div>
            </div>
        </div>
    </div>
</div>

<script>
const selected = {}; // name → data object

function toggleProcedure(el) {
    const name = el.dataset.name;
    if (selected[name]) {
        delete selected[name];
        el.classList.remove('selected');
    } else {
        selected[name] = {
            base: parseInt(el.dataset.base),
            lab:  parseInt(el.dataset.lab),
            meds: parseInt(el.dataset.meds),
            room: parseInt(el.dataset.room),
            dept: el.dataset.dept,
            dur:  el.dataset.dur,
            icon: el.dataset.icon,
        };
        el.classList.add('selected');
    }
    updateEstimate();
}

function clearAll() {
    document.querySelectorAll('.proc-item.selected').forEach(el => el.classList.remove('selected'));
    Object.keys(selected).forEach(k => delete selected[k]);
    updateEstimate();
}

function updateEstimate() {
    const names = Object.keys(selected);
    const chipsContainer = document.getElementById('chipsContainer');
    const emptyMsg = document.getElementById('emptyMsg');
    const breakdown = document.getElementById('breakdownSection');
    const durCard = document.getElementById('durationCard');
    const durList = document.getElementById('durationList');

    if (names.length === 0) {
        chipsContainer.innerHTML = '';
        emptyMsg.style.display = '';
        breakdown.style.display = 'none';
        durCard.style.display = 'none';
        return;
    }

    emptyMsg.style.display = 'none';
    breakdown.style.display = '';
    durCard.style.display = '';

    // Chips
    let chips = '';
    names.forEach(n => {
        chips += `<span class="sel-chip" onclick="removeProcedure('${n.replace(/'/g,"\\'")}')">
            <i class="${selected[n].icon}"></i> ${n}
            <span class="clear-btn"><i class="fas fa-times"></i></span>
        </span>`;
    });
    chipsContainer.innerHTML = chips;

    // Totals
    let totBase = 0, totLab = 0, totMeds = 0, totRoom = 0;
    names.forEach(n => {
        totBase += selected[n].base;
        totLab  += selected[n].lab;
        totMeds += selected[n].meds;
        totRoom += selected[n].room;
    });
    const grand = totBase + totLab + totMeds + totRoom;

    document.getElementById('bdBase').textContent   = '₹' + totBase.toLocaleString('en-IN');
    document.getElementById('bdLab').textContent    = '₹' + totLab.toLocaleString('en-IN');
    document.getElementById('bdMeds').textContent   = '₹' + totMeds.toLocaleString('en-IN');
    document.getElementById('bdRoom').textContent   = '₹' + totRoom.toLocaleString('en-IN');
    document.getElementById('grandTotal').textContent = '₹' + grand.toLocaleString('en-IN');

    const oop = document.getElementById('outOfPocket');
    if (oop) oop.textContent = '₹' + Math.round(grand * 0.2).toLocaleString('en-IN');

    // Duration list
    let durHtml = '';
    names.forEach(n => {
        durHtml += `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:0.85em;">
            <span style="color:#374151;font-weight:500;">${n}</span>
            <span style="color:#9ca3af;">${selected[n].dur}</span>
        </div>`;
    });
    durList.innerHTML = durHtml;
}

function removeProcedure(name) {
    // Deselect the item in the list
    const items = document.querySelectorAll('.proc-item');
    items.forEach(el => { if (el.dataset.name === name) el.classList.remove('selected'); });
    delete selected[name];
    updateEstimate();
}

function filterProcedures(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('.proc-item').forEach(el => {
        const match = el.dataset.name.toLowerCase().includes(q) || el.dataset.dept.toLowerCase().includes(q);
        el.style.display = match ? '' : 'none';
    });
    // Hide empty category sections
    document.querySelectorAll('.cat-section').forEach(sec => {
        const visible = [...sec.querySelectorAll('.proc-item')].some(e => e.style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
    });
}

// Simple deterministic hash for element IDs (mirrors PHP md5)
function md5hex(str) {
    // We use PHP-generated IDs, so we pass the id directly
    // This function is a placeholder; actual selection is done via data attributes
    return str;
}

function printEstimate() {
    const names = Object.keys(selected);
    if (!names.length) return;

    let totBase = 0, totLab = 0, totMeds = 0, totRoom = 0;
    names.forEach(n => { totBase+=selected[n].base; totLab+=selected[n].lab; totMeds+=selected[n].meds; totRoom+=selected[n].room; });
    const grand = totBase + totLab + totMeds + totRoom;

    const rows = names.map(n => `<tr><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6;">${n}</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6;color:#6b7280;">${selected[n].dept}</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600;">₹${(selected[n].base+selected[n].lab+selected[n].meds+selected[n].room).toLocaleString('en-IN')}</td></tr>`).join('');

    const w = window.open('', '_blank', 'width=700,height=900');
    w.document.write(`<!DOCTYPE html><html><head><title>Cost Estimate - ADMS Hospital</title>
    <style>body{font-family:Inter,sans-serif;padding:40px;color:#111827}h1{font-size:1.4em;color:#1e1b4b;margin-bottom:4px}
    .sub{color:#6b7280;font-size:0.85em;margin-bottom:30px}table{width:100%;border-collapse:collapse}
    th{background:#f9fafb;padding:10px 12px;text-align:left;font-size:0.8em;color:#6b7280;text-transform:uppercase}
    .total-bar{display:flex;justify-content:space-between;background:#ede9fe;border-radius:8px;padding:14px 18px;margin-top:16px}
    .footer{color:#9ca3af;font-size:0.75em;margin-top:30px;text-align:center}</style></head>
    <body>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
      <div><h1>Pre-Procedure Cost Estimate</h1><div class="sub">ADMS Hospital &bull; Generated: ${new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})}</div></div>
    </div>
    <table><thead><tr><th>Procedure</th><th>Department</th><th style="text-align:right">Estimated Cost</th></tr></thead>
    <tbody>${rows}</tbody></table>
    <div class="total-bar"><span style="font-weight:700;color:#4f46e5">ESTIMATED TOTAL</span><span style="font-size:1.3em;font-weight:900;color:#4f46e5">₹${grand.toLocaleString('en-IN')}</span></div>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-top:12px;font-size:0.82em;color:#92400e;">⚠ This is an estimate only. Actual charges may vary ±15% based on clinical factors.</div>
    <div class="footer">ADMS Hospital Management System &bull; Confidential Document</div>
    </body></html>`);
    w.document.close();
    w.print();
}
</script>

<?php include '../../includes/footer.php'; ?>
