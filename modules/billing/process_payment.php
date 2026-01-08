<?php
// modules/billing/process_payment.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$page_title = "Process Payment";
include '../../includes/header.php';

$invoice_id = $_GET['id'] ?? null;
$is_bulk = isset($_GET['pay_all']) || isset($_POST['pay_all']);
$billing_ids = $_GET['billing_ids'] ?? $_POST['billing_ids'] ?? '';

if (!$invoice_id && !$is_bulk) {
    echo "<div class='alert alert-danger'>Invoice ID required for single payment.</div>";
    include '../../includes/footer.php';
    exit();
}

$invoice = null;
if ($invoice_id) {
    $invoice = db_select_one("SELECT * FROM billing WHERE id = $1", [$invoice_id]);
    if (!$invoice && !$is_bulk) {
        echo "<div class='alert alert-danger'>Invoice not found.</div>";
        include '../../includes/footer.php';
        exit();
    }
}

// Final Processing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['payment_method'])) {
    $payment_method = $_POST['payment_method'];
    $transaction_id = 'TXN-' . strtoupper(uniqid());
    
    if (isset($_POST['pay_all']) && !empty($_POST['billing_ids'])) {
        $ids = explode(',', $_POST['billing_ids']);
        foreach ($ids as $id) {
            $id = trim($id);
            if (!empty($id)) {
                db_update('billing', 
                          ['status' => 'paid', 'payment_method' => $payment_method, 'transaction_id' => $transaction_id], 
                          ['id' => $id]);
            }
        }
        $success_msg = "Bulk settlement of " . count($ids) . " invoices successful! Transaction ID: $transaction_id";
    } else {
        db_update('billing', 
                  ['status' => 'paid', 'payment_method' => $payment_method, 'transaction_id' => $transaction_id], 
                  ['id' => $invoice_id]);
        $success_msg = "Payment successful! Transaction ID: $transaction_id";
    }
              
    // Create Notification
    try {
        $user_id = get_user_id();
        db_insert('notifications', [
            'user_id' => $user_id,
            'title' => 'Unified Payment Receipt',
            'message' => $success_msg . ". Digital receipts are now available in your history.",
            'is_read' => 0
        ]);
    } catch (Exception $e) {}
              
    echo "<div class='alert alert-success' style='padding: 20px; border-radius: 12px; margin-top: 20px;'>
            <i class='fas fa-check-circle'></i> $success_msg
          </div>";
    echo "<p><a href='invoices.php' class='btn btn-primary' style='border-radius: 8px; padding: 10px 20px; display: inline-block; margin-top: 15px;'>Return to Billing History</a></p>";
    include '../../includes/footer.php';
    exit();
}
?>

<div class="card">
    <div class="card-header">Payment Details</div>
    
    <div class="form-row" style="margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 10px;">
        <?php if ($is_bulk): ?>
            <p><strong>Payment Type:</strong> Unified Bulk Settlement</p>
            <p><strong>Invoices:</strong> <?php echo count(explode(',', $billing_ids)); ?> Item(s)</p>
            <p style="font-size: 1.25em; color: #16a34a; font-weight: 700;">
                <strong>Grand Total:</strong> ₹<?php 
                    $total = 0;
                    $ids = explode(',', $billing_ids);
                    foreach($ids as $id) {
                        $bill = db_select_one("SELECT total_amount FROM billing WHERE id = $1", [trim($id)]);
                        $total += $bill['total_amount'] ?? 0;
                    }
                    echo number_format($total, 2);
                ?>
            </p>
        <?php else: ?>
            <p><strong>Invoice ID:</strong> #<?php echo htmlspecialchars($invoice['id']); ?></p>
            <p><strong>Service:</strong> <?php echo htmlspecialchars($invoice['service_description'] ?? 'Clinical Service'); ?></p>
            <p style="font-size: 1.25em; color: #16a34a; font-weight: 700;"><strong>Amount Due:</strong> ₹<?php echo number_format($invoice['total_amount'], 2); ?></p>
        <?php endif; ?>
    </div>

    <form method="POST" action="">
        <?php if ($is_bulk): ?>
            <input type="hidden" name="pay_all" value="1">
            <input type="hidden" name="billing_ids" value="<?php echo htmlspecialchars($billing_ids); ?>">
        <?php endif; ?>
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
