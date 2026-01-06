<?php
// modules/ehr/prescriptions.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "My Prescriptions";
include '../../includes/header.php';

$patient_id = null;

// Get Patient ID
if ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    $patient_id = $patient['id'];
} elseif (isset($_GET['patient_id'])) {
    // Allow doctors/admins to view specific patient prescriptions
    $patient_id = $_GET['patient_id'];
}

if (!$patient_id) {
    echo "<div class='alert alert-danger'>Patient not identified.</div>";
    include '../../includes/footer.php';
    exit();
}

// Fetch Prescriptions
$prescriptions = db_select("SELECT pr.*, s.first_name as dr_fname, s.last_name as dr_lname, s.specialization
                            FROM prescriptions pr 
                            LEFT JOIN staff s ON pr.doctor_id = s.id 
                            WHERE pr.patient_id = $1
                            ORDER BY pr.created_at DESC", [$patient_id]);
?>

<div class="card">
    <div class="card-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span><i class="fas fa-pills"></i> Medication History</span>
        </div>
    </div>
    
    <?php if (empty($prescriptions)): ?>
        <div style="padding: 40px; text-align: center; color: #6c757d;">
            <i class="fas fa-prescription-bottle-alt fa-3x" style="margin-bottom: 20px; opacity: 0.5;"></i>
            <p>No prescriptions found in your records.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th width="15%">Date</th>
                        <th width="20%">Prescribed By</th>
                        <th width="45%">Medications</th>
                        <th width="20%">Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prescriptions as $rx): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500; color: #1e293b;">
                                    <?php echo date('M d, Y', strtotime($rx['created_at'])); ?>
                                </div>
                                <div style="font-size: 0.85em; color: #64748b;">
                                    <?php echo date('h:i A', strtotime($rx['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($rx['dr_fname']): ?>
                                    <div style="font-weight: 500;">Dr. <?php echo htmlspecialchars($rx['dr_fname'] . ' ' . $rx['dr_lname']); ?></div>
                                    <div style="font-size: 0.85em; color: #64748b;"><?php echo htmlspecialchars($rx['specialization']); ?></div>
                                <?php else: ?>
                                    <span style="color: #64748b;">Unknown Doctor</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    $meds = json_decode($rx['medication_details'], true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($meds)) {
                                        foreach ($meds as $m) {
                                            $name = htmlspecialchars($m['name'] ?? 'Unknown');
                                            $dosage = htmlspecialchars($m['dosage'] ?? '--');
                                            $qty = htmlspecialchars($m['quantity'] ?? '0');
                                            
                                            echo "<div style='margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #e2e8f0; last-child: border-bottom: none;'>";
                                            echo "<strong>$name</strong> <span class='badge badge-primary' style='float: right;'>Qty: $qty</span>";
                                            echo "<div style='font-size: 0.9em; color: #64748b; margin-top: 2px;'>Dosage: $dosage</div>";
                                            echo "</div>";
                                        }
                                    } else {
                                        echo "<span class='text-muted'>Invalid data format</span>";
                                    }
                                ?>
                            </td>
                            <td>
                                <!-- Placeholder for general instructions if added to schema later -->
                                <span style="font-style: italic; color: #94a3b8;">No specific notes</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
    .table {
        width: 100%;
        border-collapse: collapse;
    }
    .table th {
        text-align: left;
        padding: 12px 16px;
        background: #f8fafc;
        color: #64748b;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
    }
    .table td {
        padding: 16px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }
    .table tr:last-child td {
        border-bottom: none;
    }
    .badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75em;
        font-weight: 600;
    }
    .badge-primary {
        background: #e0f2fe;
        color: #0369a1;
    }
</style>

<?php include '../../includes/footer.php'; ?>
