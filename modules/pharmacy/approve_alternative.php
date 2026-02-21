<?php
// modules/pharmacy/approve_alternative.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin']);

if (!isset($_SESSION['dispense_shortage_data'])) {
    header("Location: dispense.php");
    exit();
}

$data = $_SESSION['dispense_shortage_data'];
$prescription_id = $data['prescription_id'];
$med_name = $data['med_name'];
$qty_needed = $data['qty_needed'];
$suggestion = $data['ai_suggestion'];

$page_title = "Approve Drug Substitution";
include '../../includes/header.php';
?>

<div class="container mt-4">
    <div class="card border-warning">
        <div class="card-header bg-warning text-dark">
            <h4><i class="fas fa-exclamation-triangle"></i> Medication Out of Stock / Low Stock</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-danger">
                <strong>Alert:</strong> Production of '<?php echo htmlspecialchars($med_name); ?>' is insufficient to fulfill the request of <strong><?php echo $qty_needed; ?></strong> units.
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header">Original Request</div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($med_name); ?></h5>
                            <p class="card-text">Quantity Needed: <?php echo $qty_needed; ?></p>
                            <p class="text-danger">Status: Not Available</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-3 border-success">
                        <div class="card-header bg-success text-white">AI Recommended Alternative</div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($suggestion['suggested_alternative']); ?></h5>
                            <p class="card-text"><strong>Reason:</strong> <?php echo htmlspecialchars($suggestion['reason']); ?></p>
                            <p class="card-text"><strong>Dosage:</strong> <?php echo htmlspecialchars($suggestion['dosage']); ?></p>
                            <p class="text-success"><i class="fas fa-check-circle"></i> In Stock (Assumed for Demo)</p>
                        </div>
                    </div>
                </div>
            </div>

            <form action="process_dispense.php" method="POST" class="mt-3 p-3 bg-light border rounded">
                <h5>Pharmacist Approval & Customer Consent</h5>
                <p>Please confirm that the customer has been informed and consents to the substitution.</p>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="customer_consent" id="customerConsent" required>
                    <label class="form-check-label" for="customerConsent">
                        <strong>I certify that the customer has consented to this substitution.</strong>
                    </label>
                </div>

                <input type="hidden" name="prescription_id" value="<?php echo $prescription_id; ?>">
                <input type="hidden" name="confirm_alternative" value="true">
                <input type="hidden" name="original_med" value="<?php echo htmlspecialchars($med_name); ?>">
                <input type="hidden" name="new_med" value="<?php echo htmlspecialchars($suggestion['suggested_alternative']); ?>">
                <input type="hidden" name="qty" value="<?php echo $qty_needed; ?>">
                <input type="hidden" name="price" value="10.00"> <!-- Placeholder price or fetch real price -->

                <div class="d-flex justify-content-between">
                    <a href="dispense.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-success">Approve & Dispense Alternative</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
