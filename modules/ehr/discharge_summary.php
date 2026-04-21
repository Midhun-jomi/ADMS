<?php
// modules/ehr/discharge_summary.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();
check_role(['admin', 'doctor', 'nurse', 'head_nurse', 'receptionist']);

$page_title = "Discharge Summary";
include '../../includes/header.php';

$role    = get_user_role();
$user_id = get_user_id();

$patient_id   = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
$admission_id = isset($_GET['admission_id']) ? trim($_GET['admission_id']) : '';

if ($role === 'doctor') {
    $staff_row = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    $doctor_staff_id = $staff_row['id'] ?? 0;
    $all_patients = db_select(
        "SELECT DISTINCT p.id, p.first_name, p.last_name
         FROM patients p
         JOIN appointments a ON a.patient_id = p.id
         WHERE a.doctor_id = $1
         ORDER BY p.first_name",
        [$doctor_staff_id]
    );
} else {
    $all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY first_name");
}
$admissions    = [];
$summary       = null;
$patient       = null;
$admission     = null;

if ($patient_id) {
    $patient = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);
    // Fetch admissions for this patient
    $admissions = db_select(
        "SELECT a.*, r.room_number, r.room_type FROM admissions a
         LEFT JOIN rooms r ON a.room_id = r.id
         WHERE a.patient_id = $1 ORDER BY a.admission_date DESC",
        [$patient_id]
    );
    if ($admission_id) {
        $admission = db_select_one(
            "SELECT a.*, r.room_number, r.room_type FROM admissions a
             LEFT JOIN rooms r ON a.room_id = r.id
             WHERE a.id = $1 AND a.patient_id = $2",
            [$admission_id, $patient_id]
        );
    }
}

// --- Generate Summary ---
if ($patient && $admission) {
    // Prescriptions during/around this admission
    $admit_date = $admission['admission_date'] ?? date('Y-m-d');
    $discharge_date = $admission['discharge_date'] ?? date('Y-m-d H:i:s');

    $prescriptions = db_select(
        "SELECT p.*, s.first_name || ' ' || s.last_name as doctor_name, s.specialization FROM prescriptions p
         LEFT JOIN staff s ON p.doctor_id = s.id
         WHERE p.patient_id = $1 ORDER BY p.created_at DESC LIMIT 10",
        [$patient_id]
    );

    $appointments = db_select(
        "SELECT a.*, s.first_name || ' ' || s.last_name as doctor_name, s.specialization FROM appointments a
         LEFT JOIN staff s ON a.doctor_id = s.id
         WHERE a.patient_id = $1 ORDER BY a.appointment_time DESC LIMIT 10",
        [$patient_id]
    );

    $billing = db_select(
        "SELECT * FROM billing WHERE patient_id = $1 ORDER BY created_at DESC LIMIT 5",
        [$patient_id]
    );

    // Calculate age
    $dob = $patient['date_of_birth'] ?? null;
    $age = $dob ? (int)((time() - strtotime($dob)) / (365.25 * 86400)) : null;

    // Collect all medications from prescriptions
    $all_meds = [];
    foreach ($prescriptions as $rx) {
        $meds = json_decode($rx['medication_details'] ?? '[]', true) ?: [];
        foreach ($meds as $med) {
            $all_meds[] = $med;
        }
    }

    // Unique doctors involved
    $doctors = [];
    foreach ($prescriptions as $rx) {
        if ($rx['doctor_name']) $doctors[$rx['doctor_name']] = $rx['specialization'];
    }
    foreach ($appointments as $ap) {
        if ($ap['doctor_name']) $doctors[$ap['doctor_name']] = $ap['specialization'];
    }

    // Determine primary complaint / notes from prescriptions
    $notes_samples = array_filter(array_column($prescriptions, 'notes'));

    // Build the structured summary data
    $summary = [
        'patient'       => $patient,
        'age'           => $age,
        'admission'     => $admission,
        'prescriptions' => $prescriptions,
        'appointments'  => $appointments,
        'billing'       => $billing,
        'all_meds'      => $all_meds,
        'doctors'       => $doctors,
        'notes_samples' => array_values($notes_samples),
    ];
}
?>

<style>
.ds-wrap { max-width: 1000px; margin: 0 auto; padding: 20px; }
.ds-selector { background: white; border-radius: 14px; padding: 22px 26px; border: 1px solid #e5e7eb; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
.ds-document { background: white; border-radius: 14px; border: 1px solid #e5e7eb; box-shadow: 0 4px 16px rgba(0,0,0,0.07); overflow: hidden; }
.ds-header { background: linear-gradient(135deg, #0f172a, #1e3a5f); color: white; padding: 36px 40px; }
.ds-section { padding: 24px 40px; border-bottom: 1px solid #f3f4f6; }
.ds-section:last-child { border-bottom: none; }
.ds-section-title { font-size: 0.78em; font-weight: 700; color: #6366f1; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
.ds-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ds-field { font-size: 0.9em; }
.ds-field .lbl { color: #9ca3af; font-size: 0.82em; margin-bottom: 2px; }
.ds-field .val { color: #111827; font-weight: 600; }
.med-pill { display: inline-block; background: #ede9fe; color: #5b21b6; border-radius: 99px; padding: 3px 12px; font-size: 0.82em; font-weight: 600; margin: 3px; }
.print-btn { background: #6366f1; color: white; border: none; border-radius: 10px; padding: 12px 26px; font-weight: 700; cursor: pointer; font-size: 0.95em; }
.print-btn:hover { background: #4f46e5; }
@media print {
    .ds-wrap > *:not(.ds-document) { display: none !important; }
    .ds-document { box-shadow: none; border: none; }
    .no-print { display: none !important; }
}
@media (max-width: 600px) { .ds-grid-2 { grid-template-columns: 1fr; } .ds-section { padding: 20px 24px; } }
</style>

<div class="ds-wrap">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #0f172a, #1e3a5f); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2em;">
                <i class="fas fa-file-medical"></i>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 1.3em; font-weight: 800; color: #111827;">Discharge Summary</h2>
                <p style="margin: 0; color: #6b7280; font-size: 0.85em;">Auto-generated clinical discharge document</p>
            </div>
        </div>
        <?php if ($summary): ?>
        <button class="print-btn no-print" onclick="window.print()"><i class="fas fa-print" style="margin-right: 6px;"></i>Print / Save PDF</button>
        <?php endif; ?>
    </div>

    <!-- Patient & Admission Selector -->
    <div class="ds-selector no-print">
        <form method="GET" action="" style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;">
            <div>
                <label style="font-weight: 600; color: #374151; font-size: 0.88em; display: block; margin-bottom: 6px;">Patient</label>
                <select name="patient_id" class="form-control" style="border-radius: 8px; height: 40px; min-width: 220px;"
                    onchange="this.form.submit()">
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($all_patients as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo ($patient_id == $p['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($patient_id && !empty($admissions)): ?>
            <div>
                <label style="font-weight: 600; color: #374151; font-size: 0.88em; display: block; margin-bottom: 6px;">Admission Record</label>
                <select name="admission_id" class="form-control" style="border-radius: 8px; height: 40px; min-width: 260px;">
                    <option value="">-- Select Admission --</option>
                    <?php foreach ($admissions as $adm): ?>
                        <option value="<?php echo $adm['id']; ?>" <?php echo ($admission_id == $adm['id']) ? 'selected' : ''; ?>>
                            <?php echo date('M d, Y', strtotime($adm['admission_date'])); ?>
                            — <?php echo htmlspecialchars($adm['room_number'] ?? 'Room'); ?>
                            (<?php echo $adm['discharge_date'] ? 'Discharged' : 'Active'; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
                <button type="submit" class="btn btn-primary" style="height: 40px; border-radius: 8px; font-weight: 600; padding: 0 20px;">
                    Generate Summary
                </button>
            </div>
            <?php elseif ($patient_id && empty($admissions)): ?>
            <div style="align-self: center; color: #9ca3af; font-size: 0.88em; font-style: italic;">No admission records found for this patient.</div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($summary): ?>
    <?php
        $p  = $summary['patient'];
        $adm = $summary['admission'];
        $admit_dt = date('d M Y, H:i', strtotime($adm['admission_date']));
        $discharge_dt = $adm['discharge_date'] ? date('d M Y, H:i', strtotime($adm['discharge_date'])) : 'Still Admitted';
        $days = $adm['discharge_date']
            ? max(1, (int)ceil((strtotime($adm['discharge_date']) - strtotime($adm['admission_date'])) / 86400))
            : (int)ceil((time() - strtotime($adm['admission_date'])) / 86400);
    ?>
    <div class="ds-document" id="summaryDoc">

        <!-- Header -->
        <div class="ds-header">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;">
                <div>
                    <div style="font-size: 0.75em; opacity: 0.6; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">ADMS Hospital — Medical Records</div>
                    <h1 style="margin: 0; font-size: 1.6em; font-weight: 900; letter-spacing: -0.5px;">Discharge Summary</h1>
                    <div style="margin-top: 6px; opacity: 0.7; font-size: 0.88em;">Document generated: <?php echo date('d M Y, H:i'); ?></div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.75em; opacity: 0.6; text-transform: uppercase; letter-spacing: 1px;">Admission ID</div>
                    <div style="font-size: 1.4em; font-weight: 800; font-family: monospace;">#<?php echo str_pad($adm['id'], 6, '0', STR_PAD_LEFT); ?></div>
                    <div style="margin-top: 6px;">
                        <?php if ($adm['discharge_date']): ?>
                            <span style="background: rgba(16,185,129,0.3); color: #6ee7b7; border-radius: 99px; padding: 4px 12px; font-size: 0.8em; font-weight: 700;">Discharged</span>
                        <?php else: ?>
                            <span style="background: rgba(245,158,11,0.3); color: #fcd34d; border-radius: 99px; padding: 4px 12px; font-size: 0.8em; font-weight: 700;">Active Admission</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Patient Information -->
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-user-injured"></i> Patient Information</div>
            <div class="ds-grid-2">
                <div class="ds-field">
                    <div class="lbl">Full Name</div>
                    <div class="val"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Age / Gender</div>
                    <div class="val"><?php echo $summary['age'] ? $summary['age'] . ' years' : 'N/A'; ?> / <?php echo ucfirst($p['gender'] ?? 'N/A'); ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Date of Birth</div>
                    <div class="val"><?php echo $p['date_of_birth'] ? date('d M Y', strtotime($p['date_of_birth'])) : 'N/A'; ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Blood Group</div>
                    <div class="val"><?php echo htmlspecialchars($p['blood_group'] ?? 'Not recorded'); ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Contact</div>
                    <div class="val"><?php echo htmlspecialchars($p['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Address</div>
                    <div class="val"><?php echo htmlspecialchars($p['address'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Admission Details -->
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-hospital"></i> Admission Details</div>
            <div class="ds-grid-2">
                <div class="ds-field">
                    <div class="lbl">Date of Admission</div>
                    <div class="val"><?php echo $admit_dt; ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Date of Discharge</div>
                    <div class="val"><?php echo $discharge_dt; ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Length of Stay</div>
                    <div class="val"><?php echo $days; ?> day<?php echo $days != 1 ? 's' : ''; ?></div>
                </div>
                <div class="ds-field">
                    <div class="lbl">Room / Ward</div>
                    <div class="val"><?php echo htmlspecialchars(($adm['room_number'] ?? 'N/A') . ' (' . ucfirst($adm['room_type'] ?? 'General') . ')'); ?></div>
                </div>
                <?php if (!empty($adm['reason'])): ?>
                <div class="ds-field" style="grid-column: 1/-1;">
                    <div class="lbl">Reason for Admission</div>
                    <div class="val"><?php echo htmlspecialchars($adm['reason']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($adm['diagnosis'])): ?>
                <div class="ds-field" style="grid-column: 1/-1;">
                    <div class="lbl">Primary Diagnosis</div>
                    <div class="val" style="color: #dc2626;"><?php echo htmlspecialchars($adm['diagnosis']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Clinical Team -->
        <?php if (!empty($summary['doctors'])): ?>
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-user-md"></i> Treating Physicians</div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <?php foreach ($summary['doctors'] as $doc_name => $spec): ?>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 10px 16px; min-width: 180px;">
                    <div style="font-weight: 700; color: #1e1b4b; font-size: 0.9em;"><?php echo htmlspecialchars($doc_name); ?></div>
                    <div style="color: #6366f1; font-size: 0.8em;"><?php echo htmlspecialchars($spec ?? 'General Medicine'); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Clinical Notes -->
        <?php if (!empty($summary['notes_samples'])): ?>
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-notes-medical"></i> Clinical Notes</div>
            <?php foreach ($summary['notes_samples'] as $i => $note): ?>
                <?php if ($i >= 3) break; ?>
                <div style="background: #f9fafb; border-left: 3px solid #6366f1; border-radius: 0 8px 8px 0; padding: 12px 16px; margin-bottom: 10px; font-size: 0.9em; color: #374151;">
                    <?php echo nl2br(htmlspecialchars($note)); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Medications -->
        <?php if (!empty($summary['all_meds'])): ?>
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-pills"></i> Medications Prescribed During Stay</div>
            <div style="margin-bottom: 12px;">
                <?php
                $seen = [];
                foreach ($summary['all_meds'] as $med):
                    $key = strtolower($med['name'] ?? '');
                    if (!$key || in_array($key, $seen)) continue;
                    $seen[] = $key;
                ?>
                    <span class="med-pill"><?php echo htmlspecialchars($med['name']); ?></span>
                <?php endforeach; ?>
            </div>
            <!-- Detailed table -->
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88em; margin-top: 10px;">
                <thead>
                    <tr style="background: #f1f5f9;">
                        <th style="padding: 10px 14px; text-align: left; font-weight: 600; color: #374151; border-radius: 6px 0 0 6px;">Medication</th>
                        <th style="padding: 10px 14px; text-align: left; font-weight: 600; color: #374151;">Dosage</th>
                        <th style="padding: 10px 14px; text-align: left; font-weight: 600; color: #374151; border-radius: 0 6px 6px 0;">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['all_meds'] as $med): ?>
                        <?php if (empty($med['name'])) continue; ?>
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 9px 14px; color: #111827; font-weight: 500;"><?php echo htmlspecialchars($med['name']); ?></td>
                            <td style="padding: 9px 14px; color: #6b7280;"><?php echo htmlspecialchars($med['dosage'] ?? '—'); ?></td>
                            <td style="padding: 9px 14px; color: #6b7280;"><?php echo htmlspecialchars($med['quantity'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Recent Appointments -->
        <?php if (!empty($summary['appointments'])): ?>
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-calendar-check"></i> Consultation History (Recent)</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.88em;">
                <thead>
                    <tr style="background: #f1f5f9;">
                        <th style="padding: 9px 14px; text-align: left; font-weight: 600; color: #374151;">Date</th>
                        <th style="padding: 9px 14px; text-align: left; font-weight: 600; color: #374151;">Doctor</th>
                        <th style="padding: 9px 14px; text-align: left; font-weight: 600; color: #374151;">Department</th>
                        <th style="padding: 9px 14px; text-align: left; font-weight: 600; color: #374151;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($summary['appointments'], 0, 6) as $ap): ?>
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 9px 14px; color: #111827;"><?php echo date('d M Y', strtotime($ap['appointment_time'])); ?></td>
                        <td style="padding: 9px 14px; color: #374151;"><?php echo htmlspecialchars($ap['doctor_name'] ?? 'N/A'); ?></td>
                        <td style="padding: 9px 14px; color: #6b7280;"><?php echo htmlspecialchars($ap['specialization'] ?? '—'); ?></td>
                        <td style="padding: 9px 14px;">
                            <?php $sc = ['completed' => '#dcfce7', 'scheduled' => '#dbeafe', 'cancelled' => '#fee2e2']; ?>
                            <span style="background: <?php echo $sc[$ap['status']] ?? '#f3f4f6'; ?>; padding: 2px 10px; border-radius: 99px; font-size: 0.82em; font-weight: 600;">
                                <?php echo ucfirst($ap['status'] ?? 'unknown'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Billing Summary -->
        <?php
        $total_billed = array_sum(array_column($summary['billing'], 'total_amount'));
        $total_paid   = array_sum(array_map(fn($b) => $b['status'] === 'paid' ? $b['total_amount'] : 0, $summary['billing']));
        ?>
        <?php if (!empty($summary['billing'])): ?>
        <div class="ds-section">
            <div class="ds-section-title"><i class="fas fa-receipt"></i> Billing Summary</div>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 14px;">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 20px; text-align: center;">
                    <div style="font-size: 0.78em; color: #6b7280; margin-bottom: 4px;">Total Billed</div>
                    <div style="font-size: 1.3em; font-weight: 800; color: #15803d;">₹<?php echo number_format($total_billed, 2); ?></div>
                </div>
                <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 14px 20px; text-align: center;">
                    <div style="font-size: 0.78em; color: #6b7280; margin-bottom: 4px;">Total Paid</div>
                    <div style="font-size: 1.3em; font-weight: 800; color: #4f46e5;">₹<?php echo number_format($total_paid, 2); ?></div>
                </div>
                <?php if ($total_billed - $total_paid > 0): ?>
                <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; padding: 14px 20px; text-align: center;">
                    <div style="font-size: 0.78em; color: #6b7280; margin-bottom: 4px;">Outstanding</div>
                    <div style="font-size: 1.3em; font-weight: 800; color: #c2410c;">₹<?php echo number_format($total_billed - $total_paid, 2); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Auto-generated Narrative -->
        <div class="ds-section" style="background: #fafafa;">
            <div class="ds-section-title"><i class="fas fa-robot"></i> AI-Generated Clinical Narrative</div>
            <?php
            $name_full = htmlspecialchars($p['first_name'] . ' ' . $p['last_name']);
            $age_str   = $summary['age'] ? $summary['age'] . '-year-old' : '';
            $gender    = strtolower($p['gender'] ?? 'patient');
            $pronoun   = $gender === 'female' ? 'She' : ($gender === 'male' ? 'He' : 'They');
            $diag      = htmlspecialchars($adm['diagnosis'] ?? 'the presenting condition');
            $doc_list  = implode(', ', array_map(fn($n) => htmlspecialchars($n), array_keys($summary['doctors'])));
            $med_names = implode(', ', array_map(fn($m) => htmlspecialchars($m['name'] ?? ''), array_slice($summary['all_meds'], 0, 5)));
            $outcome   = $adm['discharge_date'] ? 'was discharged in stable condition' : 'remains admitted under observation';
            ?>
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px 24px; line-height: 1.8; color: #374151; font-size: 0.92em;">
                <p>
                    <?php echo "$name_full, a $age_str " . ucfirst($gender); ?>, was admitted to ADMS Hospital on
                    <strong><?php echo $admit_dt; ?></strong> and <?php echo $outcome; ?> on
                    <strong><?php echo $discharge_dt; ?></strong> — a total stay of <strong><?php echo $days; ?> day<?php echo $days != 1 ? 's' : ''; ?></strong>.
                </p>
                <?php if (!empty($adm['reason'])): ?>
                <p>
                    <?php echo $pronoun; ?> presented with <em><?php echo htmlspecialchars($adm['reason']); ?></em>.
                    <?php if (!empty($adm['diagnosis'])): ?>
                    The clinical team established a primary diagnosis of <strong><?php echo $diag; ?></strong>.
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($summary['doctors'])): ?>
                <p>
                    Care was provided by <?php echo $doc_list ?: 'the medical team'; ?>.
                    <?php if (count($summary['prescriptions']) > 0): ?>
                    During the course of treatment, <?php echo $pronoun; ?> received <strong><?php echo count($summary['prescriptions']); ?></strong> prescription(s).
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php if ($med_names): ?>
                <p>
                    Medications administered included: <strong><?php echo $med_names; ?></strong><?php echo count($summary['all_meds']) > 5 ? ', and others' : ''; ?>.
                </p>
                <?php endif; ?>
                <p>
                    Total charges incurred during this admission amounted to <strong>₹<?php echo number_format($total_billed, 2); ?></strong>,
                    of which <strong>₹<?php echo number_format($total_paid, 2); ?></strong> has been settled.
                    <?php if ($total_billed - $total_paid > 0): ?>
                    An outstanding balance of <strong>₹<?php echo number_format($total_billed - $total_paid, 2); ?></strong> remains.
                    <?php endif; ?>
                </p>
                <p style="color: #9ca3af; font-size: 0.85em; margin-top: 16px; border-top: 1px solid #f3f4f6; padding-top: 12px;">
                    <i class="fas fa-info-circle"></i> This summary was auto-generated by ADMS AI on <?php echo date('d M Y \a\t H:i'); ?>.
                    It is intended to assist clinical staff and does not replace physician-authored documentation.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding: 22px 40px; background: #f9fafb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 0.8em; color: #9ca3af;">ADMS Hospital Management System &bull; Confidential Medical Record</div>
            <div style="font-size: 0.8em; color: #9ca3af;">Page 1 of 1</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
