<?php
// modules/billing/invoices.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Billing & Invoices";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle New Invoice Generation (Admin/Receptionist)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($role === 'admin' || $role === 'receptionist')) {
    $patient_id = $_POST['patient_id'];
    $amount = $_POST['amount'];
    $service_description = $_POST['service_description'];
    
    $data = [
        'patient_id' => $patient_id,
        'amount' => $amount,
        'status' => 'pending',
        'service_description' => $service_description // Assuming we add this column or store in JSON
    ];
    
    // Schema check: billing table has (patient_id, amount, status, payment_method, transaction_id).
    // It doesn't have 'service_description'. Let's add it or just omit for now.
    // Wait, the schema in `schema.sql` for `billing` is:
    // id, patient_id, appointment_id, amount, status (pending, paid, insurance_claim), payment_method, transaction_id, created_at
    // We should probably link to an appointment if possible, but let's keep it simple.
    
    try {
        // Fix: Use total_amount and include service_description
        $sql = "INSERT INTO billing (patient_id, total_amount, status, service_description) VALUES ($1, $2, $3, $4)";
        db_query($sql, [$patient_id, $amount, 'pending', $service_description]);
        $success = "Invoice generated successfully.";
    } catch (Exception $e) {
        $error = "Failed to generate invoice: " . $e->getMessage();
    }
}

// Fetch Invoices
$invoices = [];
if ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($patient) {
        $invoices = db_select("SELECT * FROM billing WHERE patient_id = $1 ORDER BY created_at DESC", [$patient['id']]);
    }
} else {
    // Admin/Staff view all
    $invoices = db_select("SELECT b.*, p.first_name, p.last_name FROM billing b JOIN patients p ON b.patient_id = p.id ORDER BY b.created_at DESC");
}
?>

<style>
    .billing-stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 15px;
        border: 1px solid #f3f4f6;
    }
    .billing-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    .billing-info h4 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #111827; }
    .billing-info p { margin: 0; color: #6b7280; font-size: 0.875rem; font-weight: 500; }
</style>

<div class="billing-container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    
    <!-- Summary Stats -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <?php
        // Calculate totals
        $total_pending = 0;
        $total_paid = 0;
        foreach ($invoices as $inv) {
            if ($inv['status'] == 'paid') $total_paid += $inv['total_amount'];
            elseif ($inv['status'] == 'pending') $total_pending += $inv['total_amount'];
        }
        ?>
        <div class="billing-stat-card">
            <div class="billing-icon" style="background: #fee2e2; color: #dc2626;">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="billing-info">
                <h4>₹<?php echo number_format($total_pending, 2); ?></h4>
                <p>Pending Bills</p>
            </div>
        </div>
        <div class="billing-stat-card">
            <div class="billing-icon" style="background: #dcfce7; color: #16a34a;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="billing-info">
                <h4>₹<?php echo number_format($total_paid, 2); ?></h4>
                <p>Total Paid</p>
            </div>
        </div>
        <div class="billing-stat-card">
            <div class="billing-icon" style="background: #e0e7ff; color: #4f46e5;">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="billing-info">
                <h4><?php echo count($invoices); ?></h4>
                <p>Total Invoices</p>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius: 8px; margin-bottom: 20px;"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="border-radius: 8px; margin-bottom: 20px;"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($role === 'admin' || $role === 'receptionist'): 
        // Fetch patients for dropdown
        $patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name");
    ?>
        <div class="card" style="border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; border-radius: 12px; overflow: hidden;">
            <div class="card-header" style="background: #fff; border-bottom: 1px solid #f3f4f6; padding: 20px;">
                <h5 style="margin: 0; color: #1f2937; font-weight: 600;"><i class="fas fa-plus-circle" style="color: #4f46e5; margin-right: 8px;"></i> Generate New Invoice</h5>
            </div>
            <div class="card-body" style="padding: 25px;">
                <form method="POST" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end;">
                    <div>
                        <label style="font-weight: 500; color: #4b5563; display: block; margin-bottom: 8px;">Patient</label>
                        <select name="patient_id" class="form-control" required style="border-radius: 8px; height: 45px;">
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: 500; color: #4b5563; display: block; margin-bottom: 8px;">Service / Description</label>
                        <select name="service_description" class="form-control" required style="border-radius: 8px; height: 45px;">
                            <option value="">-- Select Service --</option>
                            <option value="Consultation">Consultation</option>
                            <option value="General Checkup">General Checkup</option>
                            <option value="Blood Test">Blood Test</option>
                            <option value="X-Ray">X-Ray</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Emergency Care">Emergency Care</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight: 500; color: #4b5563; display: block; margin-bottom: 8px;">Amount (₹)</label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 15px; top: 12px; color: #9ca3af;">₹</span>
                            <input type="number" step="0.01" name="amount" class="form-control" required style="border-radius: 8px; height: 45px; padding-left: 30px;">
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 45px; border-radius: 8px; font-weight: 600;">
                            Create Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card" style="border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 12px; overflow: hidden;">
        <div class="card-header" style="background: #fff; border-bottom: 1px solid #f3f4f6; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
            <h5 style="margin: 0; color: #1f2937; font-weight: 600;"><i class="fas fa-list-alt" style="color: #6b7280; margin-right: 8px;"></i> Invoice History</h5>
        </div>
        <div class="card-body" style="padding: 0; overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; margin: 0;">
                <thead style="background: #f9fafb;">
                    <tr>
                        <th style="padding: 16px 24px; text-align: left; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Date</th>
                        <?php if ($role !== 'patient'): ?>
                            <th style="padding: 16px 24px; text-align: left; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Patient</th>
                        <?php endif; ?>
                        <th style="padding: 16px 24px; text-align: left; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Description</th>
                        <th style="padding: 16px 24px; text-align: left; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Amount</th>
                        <th style="padding: 16px 24px; text-align: left; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Status</th>
                        <th style="padding: 16px 24px; text-align: right; font-size: 0.85em; font-weight: 600; color: #6b7280; text-transform: uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" style="padding: 40px; text-align: center; color: #9ca3af;">No invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                            <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s;">
                                <td style="padding: 16px 24px; color: #111827; font-weight: 500;">
                                    <?php echo date('M d, Y', strtotime($inv['created_at'])); ?>
                                    <div style="font-size: 0.85em; color: #9ca3af;">#<?php echo str_pad($inv['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                </td>
                                <?php if ($role !== 'patient'): ?>
                                    <td style="padding: 16px 24px; color: #374151;">
                                        <?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?>
                                    </td>
                                <?php endif; ?>
                                <td style="padding: 16px 24px; color: #374151;">
                                    <?php echo htmlspecialchars($inv['service_description'] ?? 'Medical Service'); ?>
                                </td>
                                <td style="padding: 16px 24px; font-weight: 600; color: #111827;">
                                    ₹<?php echo number_format($inv['total_amount'], 2); ?>
                                </td>
                                <td style="padding: 16px 24px;">
                                    <?php
                                    $status_styles = [
                                        'paid' => ['bg' => '#dcfce7', 'color' => '#166534', 'label' => 'Paid'],
                                        'pending' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Pending'],
                                        'insurance_claim' => ['bg' => '#e0f2fe', 'color' => '#075985', 'label' => 'Processing']
                                    ];
                                    $s = $status_styles[$inv['status']] ?? $status_styles['pending'];
                                    ?>
                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 99px; font-size: 0.85em; font-weight: 600; background: <?php echo $s['bg']; ?>; color: <?php echo $s['color']; ?>;">
                                        <?php echo $s['label']; ?>
                                    </span>
                                </td>
                                <td style="padding: 16px 24px; text-align: right;">
                                    <?php if ($inv['status'] === 'pending'): ?>
                                        <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                            <?php if ($role !== 'admin'): // Admin cannot make payments ?>
                                                <a href="process_payment.php?id=<?php echo $inv['id']; ?>" class="btn btn-primary btn-sm" style="padding: 6px 14px; font-size: 0.85em;">Pay Now</a>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            // Check insurance
                                            $has_insurance = db_select_one("SELECT id FROM patient_insurance WHERE patient_id = $1", [$inv['patient_id']]);
                                            if ($has_insurance): 
                                            ?>
                                                <form method="POST" action="submit_claim.php" onsubmit="return confirm('Submit claim for this invoice?');">
                                                    <input type="hidden" name="bill_id" value="<?php echo $inv['id']; ?>">
                                                    <input type="hidden" name="insurance_id" value="<?php echo $has_insurance['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" style="padding: 6px 14px; font-size: 0.85em; background: white; border: 1px solid #d1d5db; color: #374151;">
                                                        Claim
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($inv['status'] === 'insurance_claim'): ?>
                                        <span style="color: #6b7280; font-size: 0.9em; font-style: italic;">Claim Submitted</span>
                                    <?php else: ?>
                                        <a href="download_receipt.php?id=<?php echo $inv['id']; ?>" class="btn btn-outline-secondary btn-sm" style="padding: 6px 14px; font-size: 0.85em; background: white; border: 1px solid #d1d5db; color: #374151; text-decoration: none;" target="_blank">
                                            <i class="fas fa-download"></i> Receipt
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
