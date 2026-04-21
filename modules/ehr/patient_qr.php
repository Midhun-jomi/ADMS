<?php
// modules/ehr/patient_qr.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role    = get_user_role();
$user_id = get_user_id();

$page_title = "Patient ID Card";
include '../../includes/header.php';

$patient    = null;
$patient_id = null;

if ($role === 'patient') {
    $patient = db_select_one(
        "SELECT p.*, u.email FROM patients p JOIN users u ON p.user_id = u.id WHERE p.user_id = $1",
        [$user_id]
    );
    if ($patient) $patient_id = $patient['id'];
} else {
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    $all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY first_name");
    if ($pid) {
        $patient = db_select_one(
            "SELECT p.*, u.email FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = $1",
            [$pid]
        );
        if ($patient) $patient_id = $patient['id'];
    }
}

if ($patient) {
    // Insurance info
    $insurance = db_select_one(
        "SELECT * FROM patient_insurance WHERE patient_id = $1 LIMIT 1",
        [$patient['id']]
    );

    // Calculate age
    $age = $patient['date_of_birth']
        ? (int)((time() - strtotime($patient['date_of_birth'])) / (365.25 * 86400))
        : null;

    $allergies  = $patient['allergies'] ?? null;
    $conditions = $patient['chronic_conditions'] ?? null;
}
?>

<style>
.card-wrap { max-width: 960px; margin: 0 auto; padding: 20px; }
.card-selector { background: white; border-radius: 14px; padding: 20px 24px; border: 1px solid #e5e7eb; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
.patient-card {
    width: 100%; max-width: 680px; margin: 0 auto;
    background: white; border-radius: 20px; overflow: hidden;
    box-shadow: 0 8px 40px rgba(0,0,0,0.12); border: 1px solid #e5e7eb;
}
.card-header-band {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #1e4d8f 100%);
    padding: 28px 30px 22px; color: white;
    display: flex; align-items: center; gap: 20px;
}
.card-avatar {
    width: 72px; height: 72px; border-radius: 50%;
    background: rgba(255,255,255,0.15);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; flex-shrink: 0;
    border: 3px solid rgba(255,255,255,0.3);
}
.card-info-panel { padding: 24px 28px; }
.info-row { margin-bottom: 14px; }
.info-label { font-size: 0.72em; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; }
.info-value { font-size: 0.9em; font-weight: 600; color: #111827; margin-top: 2px; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.blood-badge {
    display: inline-block; background: #fee2e2; color: #b91c1c;
    border-radius: 8px; padding: 6px 14px; font-size: 1.1em; font-weight: 800;
    border: 2px solid #fca5a5;
}
.card-footer-band {
    background: #f9fafb; border-top: 1px solid #f3f4f6;
    padding: 14px 28px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px;
}
.hospital-logo-text {
    font-size: 0.8em; font-weight: 800; color: #374151; letter-spacing: 1px;
    display: flex; align-items: center; gap: 6px;
}
.print-area { max-width: 720px; margin: 0 auto; }
@media print {
    .card-wrap > *:not(.print-area) { display: none !important; }
    .print-area { max-width: none; }
    .patient-card { box-shadow: none; page-break-inside: avoid; }
    .no-print { display: none !important; }
}
@media (max-width: 600px) {
    .info-grid { grid-template-columns: 1fr; }
}
</style>

<div class="card-wrap">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #0f172a, #1e3a5f); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2em;">
                <i class="fas fa-id-card"></i>
            </div>
            <div>
                <h2 style="margin: 0; font-size: 1.3em; font-weight: 800; color: #111827;">Patient ID Card</h2>
                <p style="margin: 0; color: #6b7280; font-size: 0.85em;">Printable patient identification card</p>
            </div>
        </div>
        <?php if ($patient): ?>
        <div class="no-print">
            <button onclick="window.print()" class="btn btn-primary" style="border-radius: 10px; font-weight: 700; padding: 10px 22px;">
                <i class="fas fa-print" style="margin-right: 6px;"></i>Print Card
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($role !== 'patient'): ?>
    <div class="card-selector no-print">
        <form method="GET" action="" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <label style="font-weight: 600; color: #374151; font-size: 0.88em;">Select Patient:</label>
            <select name="patient_id" class="form-control" style="border-radius: 8px; height: 40px; min-width: 240px;" onchange="this.form.submit()">
                <option value="">-- Choose Patient --</option>
                <?php foreach ($all_patients as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo ($patient_id == $p['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$patient): ?>
    <div style="text-align: center; padding: 70px 20px; color: #9ca3af;">
        <i class="fas fa-id-card" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
        <h4 style="color: #374151;">No Patient Selected</h4>
        <?php if ($role !== 'patient'): ?>
        <p>Select a patient above to view their ID card.</p>
        <?php else: ?>
        <p>Your patient profile is not yet set up. Please contact reception.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="print-area">
        <div class="patient-card">
            <!-- Header -->
            <div class="card-header-band">
                <div class="card-avatar">
                    <?php echo strtoupper(substr($patient['first_name'] ?? 'P', 0, 1)); ?>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 0.72em; opacity: 0.6; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">ADMS Hospital — Patient ID Card</div>
                    <div style="font-size: 1.4em; font-weight: 900; letter-spacing: -0.3px;">
                        <?php echo htmlspecialchars(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')); ?>
                    </div>
                    <div style="opacity: 0.7; font-size: 0.85em; margin-top: 3px;">
                        Patient ID: <strong style="font-family: monospace;"><?php echo htmlspecialchars($patient['id']); ?></strong>
                    </div>
                </div>
                <?php if ($patient['blood_group']): ?>
                <div style="text-align: center;">
                    <div style="font-size: 0.65em; opacity: 0.6; text-transform: uppercase; margin-bottom: 4px;">Blood</div>
                    <div class="blood-badge"><?php echo htmlspecialchars($patient['blood_group']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Body -->
            <div class="card-info-panel">
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Date of Birth</div>
                        <div class="info-value">
                            <?php echo $patient['date_of_birth'] ? date('d M Y', strtotime($patient['date_of_birth'])) : '—'; ?>
                            <?php if ($age): ?><span style="color: #9ca3af; font-weight: 400;"> (<?php echo $age; ?> yrs)</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Gender</div>
                        <div class="info-value"><?php echo ucfirst($patient['gender'] ?? '—'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($patient['phone'] ?? '—'); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email</div>
                        <div class="info-value" style="font-size: 0.82em; word-break: break-all;"><?php echo htmlspecialchars($patient['email'] ?? '—'); ?></div>
                    </div>
                </div>

                <?php if ($patient['address']): ?>
                <div class="info-row">
                    <div class="info-label">Address</div>
                    <div class="info-value" style="font-size: 0.85em;"><?php echo htmlspecialchars($patient['address']); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($allergies): ?>
                <div class="info-row" style="margin-top: 6px;">
                    <div class="info-label" style="color: #dc2626;"><i class="fas fa-exclamation-triangle"></i> Allergies</div>
                    <div class="info-value" style="color: #dc2626;"><?php echo htmlspecialchars($allergies); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($conditions): ?>
                <div class="info-row">
                    <div class="info-label" style="color: #7c3aed;">Chronic Conditions</div>
                    <div class="info-value" style="color: #7c3aed; font-size: 0.85em;"><?php echo htmlspecialchars($conditions); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($insurance): ?>
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; margin-top: 8px;">
                    <div class="info-label" style="color: #166534;"><i class="fas fa-shield-alt"></i> Insurance Coverage</div>
                    <div style="font-size: 0.88em; color: #15803d; font-weight: 600; margin-top: 3px;">
                        <?php echo htmlspecialchars($insurance['provider_name'] ?? 'Insured'); ?>
                        <?php if ($insurance['policy_number']): ?>
                        — Policy: <?php echo htmlspecialchars($insurance['policy_number']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="card-footer-band">
                <div class="hospital-logo-text">
                    <i class="fas fa-hospital-alt" style="color: #6366f1;"></i>
                    ADMS Hospital Management System
                </div>
                <div style="font-size: 0.75em; color: #9ca3af;">
                    Issued: <?php echo date('d M Y'); ?> &bull; Confidential
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
