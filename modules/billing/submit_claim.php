<?php
// modules/billing/submit_claim.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: invoices.php?error=Invalid+request.");
        exit();
    }

    $bill_id = $_POST['bill_id'];
    $insurance_id = $_POST['insurance_id'];

    // Get bill and verify ownership for patients
    $bill = db_select_one("SELECT b.*, p.user_id as patient_user_id FROM billing b JOIN patients p ON b.patient_id = p.id WHERE b.id = $1", [$bill_id]);

    if ($bill) {
        // Patients can only submit claims for their own bills
        if (get_user_role() === 'patient' && $bill['patient_user_id'] != get_user_id()) {
            header("Location: invoices.php?error=Unauthorized.");
            exit();
        }

        // Create Claim
        db_insert('insurance_claims', [
            'bill_id' => $bill_id,
            'patient_insurance_id' => $insurance_id,
            'claim_amount' => $bill['total_amount'],
            'status' => 'submitted'
        ]);

        // Update Bill Status
        db_update('billing', ['status' => 'insurance_claim'], ['id' => $bill_id]);

        header("Location: invoices.php?success=Insurance claim submitted.");
        exit();
    }
}
header("Location: invoices.php?error=Failed to submit claim.");
?>
