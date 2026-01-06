<?php
// modules/insurance/patient_insurance.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);

$page_title = "My Insurance";
include '../../includes/header.php';

$patient_id = db_select_one("SELECT id FROM patients WHERE user_id = $1", [get_user_id()])['id'];
$success = '';

// Handle Add Policy
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_policy'])) {
    $provider_id = $_POST['provider_id'];
    $policy_no = $_POST['policy_number'];
    $expiry = $_POST['expiry_date'];
    
    db_insert('patient_insurance', [
        'patient_id' => $patient_id,
        'provider_id' => $provider_id,
        'policy_number' => $policy_no,
        'expiry_date' => $expiry
    ]);
    $success = "Insurance policy added.";
}

// Fetch Data
$providers = db_select("SELECT * FROM insurance_providers ORDER BY name");
$my_policies = db_select("SELECT pi.*, ip.name as provider_name 
                          FROM patient_insurance pi 
                          JOIN insurance_providers ip ON pi.provider_id = ip.id 
                          WHERE pi.patient_id = $1", [$patient_id]);
?>

<div class="card">
    <div class="card-header">My Insurance Policies</div>
    <div class="card-body">
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div style="display: flex; gap: 20px;">
            <!-- List Policies -->
            <div style="flex: 2;">
                <h5>Active Policies</h5>
                <?php if (empty($my_policies)): ?>
                    <p>No insurance policies linked.</p>
                <?php else: ?>
                    <?php foreach ($my_policies as $p): ?>
                        <div style="border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 10px; background: #f8f9fa;">
                            <h4 style="margin: 0; color: #007bff;"><?php echo htmlspecialchars($p['provider_name']); ?></h4>
                            <p style="margin: 5px 0;">Policy #: <strong><?php echo htmlspecialchars($p['policy_number']); ?></strong></p>
                            <p style="margin: 0; font-size: 0.9em; color: #666;">Expires: <?php echo $p['expiry_date']; ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Add New Form -->
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #eee; border-radius: 8px;">
                <h5>Link New Insurance</h5>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Provider</label>
                        <select name="provider_id" class="form-control" required>
                            <option value="">-- Select Provider --</option>
                            <?php foreach ($providers as $prov): ?>
                                <option value="<?php echo $prov['id']; ?>"><?php echo htmlspecialchars($prov['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Policy Number</label>
                        <input type="text" name="policy_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" required>
                    </div>
                    <button type="submit" name="add_policy" class="btn btn-primary" style="width: 100%;">Add Policy</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
