<?php
// modules/ehr/drug_interactions.php — Drug Interaction Checker
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$page_title = "Drug Interaction Checker";
include '../../includes/header.php';

// Fetch available drugs from pharmacy inventory for autocomplete
$inventory = db_select("SELECT DISTINCT medication_name FROM pharmacy_inventory ORDER BY medication_name");
$drug_names = array_column($inventory, 'medication_name');

// ── Known Interaction Database ─────────────────────────────────────────────
// Format: [drug_a_pattern, drug_b_pattern, severity, effect, recommendation]
$interactions = [
    // High severity
    ['warfarin',        'aspirin',         'High',   'Significantly increased bleeding risk. Both inhibit clotting through different mechanisms.', 'Avoid combination. Use acetaminophen for pain if on warfarin.'],
    ['warfarin',        'ibuprofen',       'High',   'NSAIDs displace warfarin from protein binding, increasing anticoagulation.', 'Avoid NSAIDs with warfarin. Monitor INR closely if unavoidable.'],
    ['warfarin',        'naproxen',        'High',   'NSAIDs increase bleeding risk and displace warfarin.', 'Avoid combination.'],
    ['ssri',            'maoi',            'Critical','Risk of life-threatening serotonin syndrome (high fever, seizures, coma).', 'CONTRAINDICATED. Allow 14-day washout between SSRI and MAOI.'],
    ['fluoxetine',      'tramadol',        'High',   'Serotonin syndrome risk and tramadol seizure threshold lowered.', 'Avoid. Choose a different analgesic.'],
    ['sertraline',      'tramadol',        'High',   'Risk of serotonin syndrome and seizures.', 'Avoid combination.'],
    ['metformin',       'alcohol',         'High',   'Risk of lactic acidosis, a rare but fatal condition.', 'Advise patient to avoid alcohol while on metformin.'],
    ['digoxin',         'amiodarone',      'High',   'Amiodarone increases digoxin levels by 50-100%, causing toxicity.', 'Reduce digoxin dose by 50% and monitor levels closely.'],
    ['methotrexate',    'nsaid',           'High',   'NSAIDs reduce methotrexate excretion, increasing toxicity risk.', 'Avoid. If necessary, monitor methotrexate levels frequently.'],
    ['methotrexate',    'ibuprofen',       'High',   'Ibuprofen reduces renal methotrexate clearance.', 'Avoid combination.'],
    ['clopidogrel',     'aspirin',         'Moderate','Additive antiplatelet effect increases bleeding risk.', 'Only use together when clinically indicated (e.g., ACS). Use PPI cover.'],
    ['ace inhibitor',   'potassium',       'High',   'Dangerous hyperkalemia (high potassium) that can cause fatal arrhythmia.', 'Monitor potassium levels closely. Avoid potassium supplements.'],
    ['ramipril',        'spironolactone',  'High',   'Both retain potassium — risk of fatal hyperkalemia.', 'Monitor electrolytes frequently.'],
    ['lisinopril',      'potassium',       'High',   'Hyperkalemia risk from combined potassium retention.', 'Avoid potassium supplements. Monitor K+ levels.'],
    // Moderate severity
    ['statin',          'fibrate',         'Moderate','Increased myopathy and rhabdomyolysis risk.', 'If necessary, use low-dose statin and monitor CK levels. Avoid gemfibrozil.'],
    ['atorvastatin',    'clarithromycin',  'Moderate','Clarithromycin raises statin levels, increasing myopathy risk.', 'Hold statin during short antibiotic course or use pravastatin.'],
    ['simvastatin',     'amlodipine',      'Moderate','Amlodipine increases simvastatin exposure, raising myopathy risk.', 'Cap simvastatin at 20mg with amlodipine.'],
    ['beta blocker',    'verapamil',       'Moderate','Additive AV node blockade — risk of bradycardia and heart block.', 'Avoid combination. Use a DHP calcium channel blocker instead.'],
    ['metoprolol',      'verapamil',       'Moderate','Bradycardia and AV block risk.', 'Avoid combination if possible.'],
    ['ciprofloxacin',   'antacid',         'Moderate','Antacids (Al/Mg) chelate fluoroquinolones, reducing absorption by up to 50%.', 'Take ciprofloxacin 2 hours before or 6 hours after antacid.'],
    ['ciprofloxacin',   'theophylline',    'Moderate','Ciprofloxacin inhibits theophylline metabolism, increasing toxicity.', 'Monitor theophylline levels. Reduce dose if needed.'],
    ['azithromycin',    'amiodarone',      'Moderate','Both prolong QT interval — risk of life-threatening arrhythmia.', 'Avoid. Use an alternative antibiotic.'],
    ['sildenafil',      'nitrate',         'Critical','Dangerous hypotension — can cause fatal drop in blood pressure.', 'CONTRAINDICATED. Do not use together under any circumstance.'],
    ['sildenafil',      'nitroglycerin',   'Critical','Severe hypotension. Combined vasodilation is life-threatening.', 'CONTRAINDICATED.'],
    ['lithium',         'ibuprofen',       'Moderate','NSAIDs reduce lithium excretion, causing toxicity.', 'Avoid NSAIDs. Use acetaminophen. Monitor lithium levels.'],
    ['lithium',         'naproxen',        'Moderate','NSAIDs raise lithium levels.', 'Monitor lithium levels closely.'],
    ['insulin',         'alcohol',         'Moderate','Alcohol potentiates hypoglycemia from insulin.', 'Educate patient on hypoglycemia risk. Monitor blood glucose.'],
    ['glipizide',       'alcohol',         'Moderate','Disulfiram-like reaction and enhanced hypoglycemia.', 'Avoid alcohol while on sulfonylureas.'],
    ['doxycycline',     'antacid',         'Moderate','Divalent cations (Ca, Mg, Al, Fe) chelate tetracyclines.', 'Take doxycycline 2 hours before or 6 hours after antacids/iron.'],
    ['levothyroxine',   'calcium',         'Low',    'Calcium reduces levothyroxine absorption.', 'Separate doses by at least 4 hours.'],
    ['levothyroxine',   'iron',            'Low',    'Iron reduces absorption of levothyroxine.', 'Take levothyroxine 4 hours apart from iron supplements.'],
    ['amlodipine',      'grapefruit',      'Low',    'Grapefruit inhibits CYP3A4, increasing amlodipine levels.', 'Advise patient to avoid grapefruit juice.'],
    ['codeine',         'benzodiazepine',  'High',   'Opioid + CNS depressant combination causes additive respiratory depression.', 'Avoid. If necessary, use lowest doses and monitor closely.'],
    ['morphine',        'benzodiazepine',  'High',   'Respiratory depression risk from combined CNS depression.', 'Avoid combination. Black box warning.'],
    ['tramadol',        'diazepam',        'High',   'Enhanced sedation and respiratory depression.', 'Avoid. Counsel patient.'],
    ['phenytoin',       'warfarin',        'High',   'Phenytoin first increases then decreases warfarin levels unpredictably.', 'Monitor INR very frequently during initiation and changes.'],
    ['carbamazepine',   'oral contraceptive', 'High','Carbamazepine is a strong inducer — reduces contraceptive efficacy.', 'Use alternative contraception (barrier method + OCP or IUD).'],
    ['rifampicin',      'oral contraceptive', 'High','Rifampicin strongly induces CYP450, making OCP unreliable.', 'Use non-hormonal contraception during rifampicin treatment.'],
    ['allopurinol',     'azathioprine',    'High',   'Allopurinol inhibits xanthine oxidase, causing azathioprine toxicity.', 'Reduce azathioprine to 25% of normal dose. Monitor blood count.'],
    ['spironolactone',  'potassium',       'Moderate','Hyperkalemia risk.', 'Avoid potassium supplements with spironolactone.'],
    ['furosemide',      'gentamicin',      'Moderate','Both are ototoxic — additive risk of permanent hearing loss.', 'Avoid combination if possible. Monitor hearing.'],
    ['nsaid',           'antihypertensive','Moderate','NSAIDs cause sodium retention and reduce antihypertensive efficacy.', 'Avoid NSAIDs in hypertensive patients. Use paracetamol.'],
    ['metformin',       'iodinated contrast', 'High','Risk of contrast-induced nephropathy and lactic acidosis.', 'Hold metformin 48h before and 48h after contrast administration.'],
];
?>

<div style="max-width: 900px; margin: 20px auto; padding: 0 15px;">

    <!-- Header -->
    <div style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); color: white; border-radius: 16px; padding: 28px 30px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px;">
        <div style="width: 52px; height: 52px; background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4em; flex-shrink: 0;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-weight: 700; font-size: 1.3em;">Drug Interaction Checker</h2>
            <p style="margin: 4px 0 0; opacity: 0.85; font-size: 0.88em;">Enter up to 5 medications to check for dangerous interactions before prescribing.</p>
        </div>
    </div>

    <!-- Input Panel -->
    <div style="background: white; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); padding: 28px; margin-bottom: 20px; border: 1px solid #f3f4f6;">
        <div id="drugInputs">
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 10px; margin-bottom: 10px;">
                <input type="text" class="form-control drug-input" placeholder="Drug 1 (e.g. Warfarin, Aspirin, Metformin)" style="border-radius: 8px; height: 44px;" list="drugList">
                <button onclick="addDrugField()" class="btn" style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0 16px; font-weight: 600; white-space: nowrap;">
                    <i class="fas fa-plus"></i> Add Drug
                </button>
            </div>
        </div>

        <datalist id="drugList">
            <?php foreach ($drug_names as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>">
            <?php endforeach; ?>
        </datalist>

        <button onclick="checkInteractions()" class="btn" style="width: 100%; margin-top: 8px; padding: 13px; background: #dc2626; color: white; border: none; border-radius: 10px; font-size: 1em; font-weight: 700; cursor: pointer;">
            <i class="fas fa-search"></i> Check Interactions
        </button>
    </div>

    <!-- Results -->
    <div id="results" style="display: none;"></div>

    <!-- Disclaimer -->
    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 14px 18px; font-size: 0.82em; color: #92400e; margin-top: 16px;">
        <i class="fas fa-info-circle"></i> <strong>Clinical Reference Only.</strong> This tool provides general guidance based on common interactions. Always verify with current clinical references (BNF, Micromedex) before prescribing. Patient-specific factors must be considered.
    </div>
</div>

<script>
// Serialize interactions to JS for client-side checking
const INTERACTIONS = <?php echo json_encode($interactions); ?>;

function addDrugField() {
    const container = document.getElementById('drugInputs');
    const count = container.querySelectorAll('.drug-input').length + 1;
    if (count > 5) { alert('Maximum 5 drugs.'); return; }

    const row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:1fr auto;gap:10px;margin-bottom:10px;';
    row.innerHTML = `
        <input type="text" class="form-control drug-input" placeholder="Drug ${count} (e.g. Ibuprofen)" style="border-radius:8px;height:44px;" list="drugList">
        <button onclick="this.parentElement.remove()" class="btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:0 12px;">
            <i class="fas fa-times"></i>
        </button>`;
    container.appendChild(row);
}

function checkInteractions() {
    const inputs = document.querySelectorAll('.drug-input');
    const drugs = Array.from(inputs).map(i => i.value.trim().toLowerCase()).filter(d => d.length > 0);

    if (drugs.length < 2) {
        alert('Please enter at least 2 drug names to check interactions.');
        return;
    }

    const found = [];
    const severity_order = { Critical: 0, High: 1, Moderate: 2, Low: 3 };

    // Check all pairs
    for (let i = 0; i < drugs.length; i++) {
        for (let j = i + 1; j < drugs.length; j++) {
            const a = drugs[i], b = drugs[j];
            for (const rx of INTERACTIONS) {
                const ra = rx[0], rb = rx[1];
                if ((a.includes(ra) || ra.includes(a) || drugClass(a, ra)) &&
                    (b.includes(rb) || rb.includes(b) || drugClass(b, rb)) ||
                    (b.includes(ra) || ra.includes(b) || drugClass(b, ra)) &&
                    (a.includes(rb) || rb.includes(a) || drugClass(a, rb))) {
                    found.push({ drug_a: drugs[i], drug_b: drugs[j], severity: rx[2], effect: rx[3], recommendation: rx[4] });
                }
            }
        }
    }

    // Deduplicate
    const unique = found.filter((v, i, a) => a.findIndex(t => t.effect === v.effect) === i);
    unique.sort((a, b) => (severity_order[a.severity] ?? 4) - (severity_order[b.severity] ?? 4));

    renderResults(drugs, unique);
}

function drugClass(drugName, className) {
    const classes = {
        'nsaid': ['ibuprofen', 'naproxen', 'diclofenac', 'indomethacin', 'ketorolac', 'celecoxib', 'mefenamic'],
        'statin': ['atorvastatin', 'simvastatin', 'rosuvastatin', 'pravastatin', 'lovastatin', 'fluvastatin'],
        'ssri': ['fluoxetine', 'sertraline', 'escitalopram', 'paroxetine', 'citalopram', 'fluvoxamine'],
        'beta blocker': ['metoprolol', 'atenolol', 'propranolol', 'bisoprolol', 'carvedilol', 'nebivolol'],
        'ace inhibitor': ['ramipril', 'lisinopril', 'enalapril', 'captopril', 'perindopril', 'benazepril'],
        'benzodiazepine': ['diazepam', 'lorazepam', 'alprazolam', 'clonazepam', 'midazolam', 'temazepam'],
        'fibrate': ['gemfibrozil', 'fenofibrate', 'bezafibrate', 'ciprofibrate'],
        'nitrate': ['nitroglycerin', 'isosorbide', 'nitro'],
        'antihypertensive': ['amlodipine', 'metoprolol', 'ramipril', 'lisinopril', 'losartan', 'valsartan', 'telmisartan'],
        'antacid': ['omeprazole', 'pantoprazole', 'ranitidine', 'antacid', 'aluminum', 'magnesium', 'calcium carbonate'],
    };
    if (classes[className]) {
        return classes[className].some(d => drugName.includes(d));
    }
    return false;
}

function renderResults(drugs, found) {
    const div = document.getElementById('results');
    div.style.display = 'block';

    const sevColors = {
        Critical: { bg: '#fef2f2', border: '#fca5a5', text: '#991b1b', badge: '#dc2626', icon: 'fa-skull-crossbones' },
        High:     { bg: '#fff7ed', border: '#fdba74', text: '#9a3412', badge: '#ea580c', icon: 'fa-exclamation-triangle' },
        Moderate: { bg: '#fefce8', border: '#fde047', text: '#854d0e', badge: '#d97706', icon: 'fa-exclamation-circle'  },
        Low:      { bg: '#f0fdf4', border: '#86efac', text: '#166534', badge: '#16a34a', icon: 'fa-info-circle'         },
    };

    let html = `<div style="background:white;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,0.06);padding:28px;border:1px solid #f3f4f6;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h4 style="margin:0;font-weight:700;color:#111827;">Interaction Report</h4>
            <span style="font-size:0.82em;color:#6b7280;">Checked: ${drugs.join(', ')}</span>
        </div>`;

    if (found.length === 0) {
        html += `<div style="text-align:center;padding:40px;color:#16a34a;">
            <i class="fas fa-check-circle fa-3x" style="margin-bottom:15px;"></i>
            <h5 style="font-weight:700;">No Known Interactions Found</h5>
            <p style="color:#6b7280;font-size:0.9em;margin:0;">No significant interactions detected in our database for these medications. Always verify clinically.</p>
        </div>`;
    } else {
        const critCount = found.filter(f => f.severity === 'Critical').length;
        const highCount = found.filter(f => f.severity === 'High').length;

        if (critCount > 0) {
            html += `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
                <i class="fas fa-skull-crossbones" style="color:#dc2626;font-size:1.4em;"></i>
                <div><strong style="color:#991b1b;">${critCount} CRITICAL interaction(s) detected.</strong><br>
                <span style="font-size:0.85em;color:#b91c1c;">These combinations are contraindicated. Do not prescribe without specialist review.</span></div>
            </div>`;
        } else if (highCount > 0) {
            html += `<div style="background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
                <i class="fas fa-exclamation-triangle" style="color:#ea580c;"></i>
                <strong style="color:#9a3412;margin-left:8px;">${highCount} high-severity interaction(s) found. Review before prescribing.</strong>
            </div>`;
        }

        found.forEach(item => {
            const c = sevColors[item.severity] || sevColors.Low;
            html += `<div style="background:${c.bg};border:1px solid ${c.border};border-radius:12px;padding:18px 20px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="fas ${c.icon}" style="color:${c.badge};font-size:1.1em;"></i>
                        <strong style="color:${c.text};font-size:0.95em;text-transform:capitalize;">${item.drug_a}</strong>
                        <span style="color:#9ca3af;">+</span>
                        <strong style="color:${c.text};font-size:0.95em;text-transform:capitalize;">${item.drug_b}</strong>
                    </div>
                    <span style="background:${c.badge};color:white;padding:3px 10px;border-radius:99px;font-size:0.75em;font-weight:700;">${item.severity}</span>
                </div>
                <p style="margin:0 0 8px;color:${c.text};font-size:0.88em;"><i class="fas fa-flask" style="margin-right:6px;opacity:0.7;"></i>${item.effect}</p>
                <p style="margin:0;font-size:0.84em;color:#374151;background:rgba(255,255,255,0.7);padding:8px 12px;border-radius:6px;">
                    <i class="fas fa-lightbulb" style="color:#f59e0b;margin-right:6px;"></i><strong>Recommendation:</strong> ${item.recommendation}
                </p>
            </div>`;
        });
    }
    html += `</div>`;
    div.innerHTML = html;
    div.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<?php include '../../includes/footer.php'; ?>
