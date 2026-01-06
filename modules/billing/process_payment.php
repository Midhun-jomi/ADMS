<?php
// modules/billing/process_payment.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$page_title = "Process Payment";
include '../../includes/header.php';

$invoice_id = $_GET['id'] ?? null;

if (!$invoice_id) {
    echo "<div class='alert alert-danger'>Invoice ID required.</div>";
    include '../../includes/footer.php';
    exit();
}

$invoice = db_select_one("SELECT * FROM billing WHERE id = $1", [$invoice_id]);

if (!$invoice) {
    echo "<div class='alert alert-danger'>Invoice not found.</div>";
    include '../../includes/footer.php';
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $payment_method = $_POST['payment_method'];
    // Mock transaction ID
    $transaction_id = 'TXN-' . strtoupper(uniqid());
    
    db_update('billing', 
              ['status' => 'paid', 'payment_method' => $payment_method, 'transaction_id' => $transaction_id], 
              ['id' => $invoice_id]);
              
    // Create Notification
    try {
        $bill = db_select_one("SELECT patient_id FROM billing WHERE id = $1", [$invoice_id]);
        $pat = db_select_one("SELECT user_id FROM patients WHERE id = $1", [$bill['patient_id']]);
        if ($pat) {
            db_insert('notifications', [
                'user_id' => $pat['user_id'],
                'title' => 'Payment Receipt Generated',
                'message' => "Payment of ₹" . $invoice['total_amount'] . " for Invoice #" . str_pad($invoice['id'], 5, '0', STR_PAD_LEFT) . " was successful. You can now download your receipt.",
                'is_read' => 0
            ]);
        }
    } catch (Exception $e) {
        // Silently fail for notifications to not block payment flow
    }
              
    echo "<div class='alert alert-success'>Payment successful! Transaction ID: $transaction_id</div>";
    echo "<p><a href='invoices.php' class='btn btn-primary'>Back to Invoices</a></p>";
    include '../../includes/footer.php';
    exit();
}
?>

<div class="card">
    <div class="card-header">Payment Details</div>
    
    <div class="form-row" style="margin-bottom: 20px;">
        <p><strong>Invoice ID:</strong> <?php echo htmlspecialchars($invoice['id']); ?></p>
        <p><strong>Service:</strong> <?php echo htmlspecialchars($invoice['service_description'] ?? 'N/A'); ?></p>
        <p><strong>Amount Due:</strong> ₹<?php echo htmlspecialchars($invoice['total_amount']); ?></p>
    </div>

    <form method="POST" action="">
        <div class="form-group">
            <label>Select Payment Method</label>
            <select name="payment_method" class="form-control">
                <option value="Credit Card">Credit Card</option>
                <option value="Debit Card">Debit Card</option>
                <option value="Insurance">Insurance Claim</option>
                <option value="Cash">Cash</option>
            </select>
        </div>

        <div class="form-group">
            <label>Card Number (Mock)</label>
            <input type="text" class="form-control" placeholder="**** **** **** 1234" disabled>
        </div>

        <button type="submit" class="btn btn-primary">Confirm Payment</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
