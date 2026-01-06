<?php
// modules/billing/submit_claim.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $bill_id = $_POST['bill_id'];
    $insurance_id = $_POST['insurance_id'];
    
    // Get bill amount
    $bill = db_select_one("SELECT total_amount FROM billing WHERE id = $1", [$bill_id]);
    
    if ($bill) {
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
