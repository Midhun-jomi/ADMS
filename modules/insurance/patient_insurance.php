<?php
// modules/insurance/patient_insurance.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);

$page_title = "My Insurance";
include '../../includes/header.php';

$patient_id = db_select_one("SELECT id FROM patients WHERE user_id = $1", [get_user_id()])['id'];
$success = '';
$error = '';

// Handle Add Policy
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_policy'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
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
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

        <div style="display: flex; gap: 20px;">
            <!-- List Policies -->
            <div style="flex: 2;">
                <h5>Active Policies</h5>
                <div style="margin-bottom: 14px;">
                    <input type="text" id="filter-ins-patients" onkeyup="filterTable('filter-ins-patients','tbl-ins-patients')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
                </div>
                <table id="tbl-ins-patients" style="width:100%; border-collapse:collapse;">
                <tbody>
                <?php if (empty($my_policies)): ?>
                    <tr><td><p>No insurance policies linked.</p></td></tr>
                <?php else: ?>
                    <?php foreach ($my_policies as $p): ?>
                    <tr><td>
                        <div style="border: 1px solid #ddd; padding: 15px; border-radius: 8px; margin-bottom: 10px; background: #f8f9fa;">
                            <h4 style="margin: 0; color: #007bff;"><?php echo htmlspecialchars($p['provider_name']); ?></h4>
                            <p style="margin: 5px 0;">Policy #: <strong><?php echo htmlspecialchars($p['policy_number']); ?></strong></p>
                            <p style="margin: 0; font-size: 0.9em; color: #666;">Expires: <?php echo $p['expiry_date']; ?></p>
                        </div>
                    </td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                </table>
            </div>

            <!-- Add New Form -->
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #eee; border-radius: 8px;">
                <h5>Link New Insurance</h5>
                <form method="POST" action="">
                    <?php echo csrf_input(); ?>
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
