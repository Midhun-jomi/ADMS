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
    $invoice = db_select_one("SELECT b.*, p.user_id as patient_user_id FROM billing b JOIN patients p ON b.patient_id = p.id WHERE b.id = $1", [$invoice_id]);
    if (!$invoice && !$is_bulk) {
        echo "<div class='alert alert-danger'>Invoice not found.</div>";
        include '../../includes/footer.php';
        exit();
    }
    if ($invoice && get_user_role() === 'patient' && $invoice['patient_user_id'] != get_user_id()) {
        echo "<div class='alert alert-danger'>Unauthorized.</div>";
        include '../../includes/footer.php';
        exit();
    }
}

// Calculate grand total for bulk
$grand_total = 0;
if ($is_bulk && $billing_ids) {
    foreach (explode(',', $billing_ids) as $id) {
        $bill = db_select_one("SELECT total_amount FROM billing WHERE id = $1", [trim($id)]);
        $grand_total += (float)($bill['total_amount'] ?? 0);
    }
} elseif ($invoice) {
    $grand_total = (float)$invoice['total_amount'];
}

// Final Processing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['payment_method'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<div class='alert alert-danger'>Invalid request. Please refresh and try again.</div>";
        include '../../includes/footer.php';
        exit();
    }
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
        $success_msg = "Bulk settlement of " . count($ids) . " invoice(s) successful! Transaction ID: $transaction_id";
    } else {
        db_update('billing',
            ['status' => 'paid', 'payment_method' => $payment_method, 'transaction_id' => $transaction_id],
            ['id' => $invoice_id]);
        $success_msg = "Payment successful! Transaction ID: $transaction_id";
    }

    try {
        db_insert('notifications', [
            'user_id' => get_user_id(),
            'title'   => 'Payment Receipt',
            'message' => $success_msg . " Digital receipt is now available in your billing history.",
            'is_read' => 0
        ]);
    } catch (Exception $e) {}

    ?>
    <div style="max-width: 520px; margin: 50px auto; text-align: center; padding: 20px;">
        <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #bbf7d0; border-radius: 20px; padding: 50px 40px;">
            <div style="width: 72px; height: 72px; background: #16a34a; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-check" style="color: white; font-size: 2rem;"></i>
            </div>
            <h2 style="color: #15803d; margin-bottom: 8px;">Payment Successful!</h2>
            <p style="color: #166534; font-size: 1.6rem; font-weight: 800; margin-bottom: 5px;">₹<?php echo number_format($grand_total, 2); ?></p>
            <p style="color: #4ade80; font-size: 0.85em; margin-bottom: 25px;">via <?php echo htmlspecialchars($payment_method); ?></p>
            <div style="background: white; border-radius: 12px; padding: 14px 18px; margin-bottom: 25px; text-align: left; border: 1px solid #d1fae5;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: #6b7280; font-size: 0.88em;">Transaction ID</span>
                    <span style="font-weight: 600; color: #111827; font-size: 0.88em; font-family: monospace;"><?php echo $transaction_id; ?></span>
                </div>
            </div>
            <a href="invoices.php" class="btn btn-success" style="padding: 12px 28px; border-radius: 10px; font-weight: 600; text-decoration: none; display: inline-block; background: #16a34a; color: white; border: none;">
                <i class="fas fa-receipt"></i> View Billing History
            </a>
        </div>
    </div>
    <?php
    include '../../includes/footer.php';
    exit();
}
?>

<style>
.pay-wrap { max-width: 540px; margin: 30px auto; padding: 0 15px; }
.pay-card { background: white; border-radius: 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid #e5e7eb; }
.pay-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 26px 30px; color: white; display: flex; align-items: center; gap: 14px; }
.pay-body { padding: 28px 30px; }
.method-card { border: 2px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 14px; }
.method-card:hover { border-color: #21a9af; background: #f0fdfe; }
.method-card input[type=radio] { accent-color: #21a9af; width: 18px; height: 18px; }
.method-card.selected { border-color: #21a9af; background: #f0fdfe; }
.pay-btn { width: 100%; padding: 15px; background: #21a9af; color: white; border: none; border-radius: 12px; font-size: 1.05em; font-weight: 700; cursor: pointer; transition: all 0.2s; margin-top: 8px; }
.pay-btn:hover { background: #148f94; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(33,169,175,0.3); }
.invoice-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 0.9em; }
.invoice-item:last-child { border-bottom: none; }
</style>

<div class="pay-wrap">
    <div class="pay-card">
        <div class="pay-header">
            <div style="width: 44px; height: 44px; background: rgba(255,255,255,0.1); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3em;">
                <i class="fas fa-credit-card"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.15em; font-weight: 700;">Payment Checkout</h3>
                <p style="margin: 2px 0 0 0; font-size: 0.82em; opacity: 0.65;">ADMS Hospital — Billing Department</p>
            </div>
        </div>

        <div class="pay-body">
            <!-- Invoice Summary -->
            <div style="margin-bottom: 20px;">
                <div style="font-size: 0.78em; font-weight: 700; color: #9ca3af; text-transform: uppercase; margin-bottom: 10px; letter-spacing: 0.5px;">Invoice Summary</div>
                <?php if ($is_bulk): ?>
                    <?php foreach (explode(',', $billing_ids) as $id): ?>
                        <?php $b = db_select_one("SELECT * FROM billing WHERE id = $1", [trim($id)]); ?>
                        <?php if ($b): ?>
                            <div class="invoice-item">
                                <div>
                                    <div style="font-weight: 500; color: #111827;"><?php echo htmlspecialchars($b['service_description'] ?? 'Medical Service'); ?></div>
                                    <div style="font-size: 0.78em; color: #9ca3af;">#<?php echo str_pad($b['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                </div>
                                <div style="font-weight: 600;">₹<?php echo number_format($b['total_amount'], 2); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="invoice-item">
                        <div>
                            <div style="font-weight: 500; color: #111827;"><?php echo htmlspecialchars($invoice['service_description'] ?? 'Medical Service'); ?></div>
                            <div style="font-size: 0.78em; color: #9ca3af;">#<?php echo str_pad($invoice['id'], 5, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div style="font-weight: 600;">₹<?php echo number_format($invoice['total_amount'], 2); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Total -->
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 14px 18px; margin-bottom: 22px;">
                <span style="font-weight: 600; color: #166534; font-size: 0.9em;">Total Amount Due</span>
                <span style="font-size: 1.5rem; font-weight: 800; color: #15803d;">₹<?php echo number_format($grand_total, 2); ?></span>
            </div>

            <!-- Payment Form -->
            <form method="POST" action="">
                <?php echo csrf_input(); ?>
                <?php if ($is_bulk): ?>
                    <input type="hidden" name="pay_all" value="1">
                    <input type="hidden" name="billing_ids" value="<?php echo htmlspecialchars($billing_ids); ?>">
                <?php endif; ?>

                <div style="font-size: 0.78em; font-weight: 700; color: #9ca3af; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px;">Select Payment Method</div>

                <?php
                $methods = [
                    ['value' => 'UPI',         'icon' => 'fas fa-mobile-alt',     'label' => 'UPI',         'sub' => 'GPay, PhonePe, Paytm'],
                    ['value' => 'Debit Card',  'icon' => 'fas fa-credit-card',    'label' => 'Debit Card',  'sub' => 'Visa, Mastercard, RuPay'],
                    ['value' => 'Credit Card', 'icon' => 'far fa-credit-card',    'label' => 'Credit Card', 'sub' => 'Visa, Mastercard, Amex'],
                    ['value' => 'Net Banking', 'icon' => 'fas fa-university',     'label' => 'Net Banking', 'sub' => 'All major banks'],
                    ['value' => 'Cash',        'icon' => 'fas fa-money-bill-wave','label' => 'Cash',        'sub' => 'Pay at counter'],
                    ['value' => 'Insurance',   'icon' => 'fas fa-shield-alt',     'label' => 'Insurance',   'sub' => 'Submit insurance claim'],
                ];
                foreach ($methods as $m):
                ?>
                    <label class="method-card" onclick="this.classList.add('selected'); document.querySelectorAll('.method-card').forEach(c => { if(c!==this) c.classList.remove('selected'); })">
                        <input type="radio" name="payment_method" value="<?php echo $m['value']; ?>" required>
                        <div style="width: 38px; height: 38px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #21a9af; font-size: 1.1em; flex-shrink: 0;">
                            <i class="<?php echo $m['icon']; ?>"></i>
                        </div>
                        <div>
                            <div style="font-weight: 600; color: #111827; font-size: 0.95em;"><?php echo $m['label']; ?></div>
                            <div style="font-size: 0.78em; color: #9ca3af;"><?php echo $m['sub']; ?></div>
                        </div>
                    </label>
                <?php endforeach; ?>

                <button type="submit" class="pay-btn">
                    <i class="fas fa-lock" style="margin-right: 6px;"></i>
                    Confirm Payment · ₹<?php echo number_format($grand_total, 2); ?>
                </button>

                <div style="text-align: center; margin-top: 14px;">
                    <a href="invoices.php" style="color: #9ca3af; font-size: 0.82em; text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Cancel &amp; go back
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
