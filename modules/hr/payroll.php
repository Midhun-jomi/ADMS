<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Only Admin can manage payroll
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "Payroll Management";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Payroll Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    $staff_id = $_POST['staff_id'];
    $month = $_POST['salary_month'] . '-01'; // Ensure it's date format
    $basic = $_POST['basic_salary'];
    $allowances = $_POST['allowances'] ?: 0;
    $deductions = $_POST['deductions'] ?: 0;

    try {
        $data = [
            'staff_id' => $staff_id,
            'salary_month' => $month,
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'status' => 'unpaid'
        ];
        db_insert('payroll', $data);
        $success_msg = "Payroll generated successfully!";
    } catch (Exception $e) {
        $error_msg = "Error generating payroll: " . $e->getMessage();
    }
}

// Mark as Paid
if (isset($_GET['mark_paid'])) {
    $id = $_GET['mark_paid'];
    try {
        db_update('payroll', ['status' => 'paid', 'payment_date' => date('Y-m-d H:i:s')], ['id' => $id]);
        $success_msg = "Marked as paid!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Check for duplicate payrolls for this month to avoid double entry (optional enhancement)

$staff_list = db_select("SELECT id, first_name, last_name FROM staff");
$payrolls = db_select("
    SELECT p.*, s.first_name, s.last_name 
    FROM payroll p 
    JOIN staff s ON p.staff_id = s.id 
    ORDER BY p.salary_month DESC, p.created_at DESC
");

?>

<div class="main-content">
    <div class="page-header">
        <h1>Payroll Management</h1>
        <button class="btn-primary" onclick="document.getElementById('payrollModal').style.display='block'">
            <i class="fas fa-plus"></i> Process New Payroll
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Payroll History</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Staff Name</th>
                        <th>Month</th>
                        <th>Basic</th>
                        <th>Allowances</th>
                        <th>Deductions</th>
                        <th>Net Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payrolls as $pay): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']); ?></td>
                        <td><?php echo date('M Y', strtotime($pay['salary_month'])); ?></td>
                        <td>₹<?php echo number_format($pay['basic_salary'], 2); ?></td>
                        <td>₹<?php echo number_format($pay['allowances'], 2); ?></td>
                        <td>₹<?php echo number_format($pay['deductions'], 2); ?></td>
                        <td><strong>₹<?php echo number_format($pay['net_salary'] ?: ($pay['basic_salary'] + $pay['allowances'] - $pay['deductions']), 2); ?></strong></td>
                        <td>
                            <span class="badge badge-<?php echo $pay['status'] === 'paid' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($pay['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($pay['status'] === 'unpaid'): ?>
                                <a href="?mark_paid=<?php echo $pay['id']; ?>" class="btn-small btn-success" onclick="return confirm('Mark this as paid?')">
                                    <i class="fas fa-check"></i> Pay
                                </a>
                            <?php else: ?>
                                <button class="btn-small btn-secondary" disabled>Paid</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Payroll Modal -->
<div id="payrollModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Process Payroll</h3>
            <span class="close" onclick="document.getElementById('payrollModal').style.display='none'">&times;</span>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Select Staff</label>
                <select name="staff_id" required>
                    <option value="">-- Select Staff --</option>
                    <?php foreach ($staff_list as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>">
                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Salary Month</label>
                <input type="month" name="salary_month" required value="<?php echo date('Y-m'); ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Basic Salary ($)</label>
                    <input type="number" step="0.01" name="basic_salary" required>
                </div>
                <div class="form-group">
                    <label>Allowances ($)</label>
                    <input type="number" step="0.01" name="allowances" value="0">
                </div>
                <div class="form-group">
                    <label>Deductions ($)</label>
                    <input type="number" step="0.01" name="deductions" value="0">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="generate_payroll" class="btn-primary">Generate Slip</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('payrollModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Reusing core styles, adding modal specifics if needed */
.form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>
