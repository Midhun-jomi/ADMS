<?php
// modules/billing/download_receipt.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$invoice_id = $_GET['id'] ?? null;
if (!$invoice_id) die("Invoice ID missing.");

// Fetch invoice and patient details
$invoice = db_select_one("SELECT b.*, p.first_name, p.last_name, u.email, p.phone 
                          FROM billing b 
                          JOIN patients p ON b.patient_id = p.id 
                          JOIN users u ON p.user_id = u.id
                          WHERE b.id = $1", [$invoice_id]);

if (!$invoice) die("Invoice not found.");

// Check access (Patient can only see own, Admin can see all)
$role = get_user_role();
if ($role === 'patient') {
    $user_id = $_SESSION['user_id'];
    $patient_check = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($invoice['patient_id'] != $patient_check['id']) {
        die("Unauthorized access.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?php echo $invoice['id']; ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; padding: 40px; color: #333; }
        .receipt-container { max-width: 800px; margin: 0 auto; border: 1px solid #eee; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; margin-bottom: 40px; border-bottom: 2px solid #f0f0f0; padding-bottom: 20px; }
        .logo { font-size: 24px; font-weight: bold; color: #2563EB; }
        .invoice-details { text-align: right; }
        .bill-to { margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #ddd; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        .total-row td { font-weight: bold; font-size: 1.1em; border-top: 2px solid #333; }
        .footer { text-align: center; margin-top: 50px; font-size: 0.85em; color: #777; border-top: 1px solid #eee; padding-top: 20px; }
        .status-stamp {
            transform: rotate(-15deg);
            border: 3px solid #155724;
            color: #155724;
            font-size: 2rem;
            font-weight: bold;
            display: inline-block;
            padding: 10px 20px;
            text-transform: uppercase;
            position: absolute;
            top: 180px;
            right: 100px;
            opacity: 0.3;
        }
        @media print {
            body { padding: 0; }
            .receipt-container { border: none; box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="receipt-container">
    <?php if ($invoice['status'] === 'paid'): ?>
        <div class="status-stamp">PAID</div>
    <?php endif; ?>

    <div class="header">
        <div class="logo">ADMS Hospital</div>
        <div class="invoice-details">
            <h2>RECEIPT</h2>
            <p><strong>Receipt #:</strong> <?php echo str_pad($invoice['id'], 6, '0', STR_PAD_LEFT); ?></p>
            <p><strong>Date:</strong> <?php echo date('F d, Y', strtotime($invoice['created_at'])); ?></p>
            <?php if ($invoice['transaction_id']): ?>
                <p><strong>Ref:</strong> <?php echo $invoice['transaction_id']; ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bill-to">
        <h3>Bill To:</h3>
        <p><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></p>
        <p><?php echo htmlspecialchars($invoice['email']); ?></p>
        <p><?php echo htmlspecialchars($invoice['phone']); ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo htmlspecialchars($invoice['service_description'] ?? 'Medical Services'); ?></td>
                <td style="text-align: right;">₹<?php echo number_format($invoice['total_amount'], 2); ?></td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td style="text-align: right;">Total</td>
                <td style="text-align: right;">₹<?php echo number_format($invoice['total_amount'], 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <p>Thank you for choosing ADMS Hospital.</p>
        <p>This is a computer-generated receipt.</p>
    </div>
    
    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563EB; color: white; border: none; border-radius: 5px; cursor: pointer;">Print Receipt</button>
    </div>
</div>

</body>
</html>
