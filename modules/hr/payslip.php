<?php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$is_admin = ($role === 'admin');

// --- PAYSLIP PRINT VIEW ---
if (isset($_GET['payroll_id'])) {
    $payroll_id = trim($_GET['payroll_id']);

    $record = db_select_one(
        "SELECT p.*,
                s.first_name, s.last_name, s.designation, s.department_id, s.employee_id,
                d.name AS dept_name
         FROM payroll p
         JOIN staff s ON p.staff_id = s.id
         LEFT JOIN departments d ON s.department_id = d.id
         WHERE p.id = $1",
        [$payroll_id]
    );

    if (!$record) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red;">Payslip not found or access denied.</div>');
    }

    // Access control: admin sees all; staff sees only own
    if (!$is_admin) {
        $my_staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
        if (!$my_staff || $my_staff['id'] !== $record['staff_id']) {
            die('<div style="font-family:sans-serif;padding:2rem;color:red;">Access denied.</div>');
        }
    }

    // Compute values safely
    $basic       = (float)($record['basic_salary'] ?? 0);
    $allowances  = (float)($record['allowances'] ?? 0);
    $deductions  = (float)($record['deductions'] ?? 0);

    // Break down allowances and deductions into sub-items for display
    $hra         = round($basic * 0.20, 2);
    $other_allow = max(0, $allowances - $hra);

    $pf          = round($basic * 0.12, 2);
    $tax         = round($basic * 0.05, 2);
    $other_deduct= max(0, $deductions - $pf - $tax);

    $gross       = $basic + $allowances;
    $net         = $gross - $deductions;

    $month_label = $record['salary_month']
        ? date('F Y', strtotime($record['salary_month']))
        : '—';

    $emp_id = htmlspecialchars($record['employee_id'] ?? ('EMP-' . strtoupper(substr($record['staff_id'], 0, 6))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip &mdash; <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?> &mdash; <?php echo $month_label; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f5f5; font-family: 'Segoe UI', Arial, sans-serif; }
        .payslip-wrapper { max-width: 780px; margin: 30px auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .payslip-header { background: linear-gradient(135deg, #1e3a5f 0%, #2e6da4 100%); color: #fff; padding: 28px 36px; }
        .payslip-header h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: 2px; margin: 0; }
        .payslip-header .hospital-sub { font-size: 0.85rem; opacity: 0.8; margin-top: 2px; }
        .payslip-header .slip-label { font-size: 1rem; font-weight: 600; letter-spacing: 3px; margin-top: 6px; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 8px; }
        .payslip-body { padding: 28px 36px; }
        .emp-details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 32px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #e5e5e5; }
        .emp-detail-item label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: #777; display: block; margin-bottom: 2px; }
        .emp-detail-item span { font-size: 0.92rem; color: #222; }
        .pay-section { margin-bottom: 20px; }
        .pay-section h6 { font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; padding: 6px 10px; border-radius: 4px; margin-bottom: 0; }
        .pay-section.earnings h6 { background: #e8f5e9; color: #2e7d32; }
        .pay-section.deductions h6 { background: #fce4ec; color: #c62828; }
        .pay-table { width: 100%; border-collapse: collapse; }
        .pay-table td { padding: 7px 10px; font-size: 0.9rem; border-bottom: 1px solid #f0f0f0; }
        .pay-table td:last-child { text-align: right; font-weight: 500; }
        .pay-table tr:last-child td { border-bottom: none; }
        .pay-tables-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .net-salary-box { background: linear-gradient(135deg, #1e3a5f 0%, #2e6da4 100%); color: #fff; border-radius: 8px; padding: 18px 24px; text-align: center; margin-top: 20px; }
        .net-salary-box .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 2px; opacity: 0.85; }
        .net-salary-box .amount { font-size: 2rem; font-weight: 800; margin-top: 4px; }
        .payslip-footer { text-align: center; padding: 16px 36px; background: #f9f9f9; border-top: 1px solid #eee; font-size: 0.78rem; color: #999; }
        .logo-placeholder { width: 54px; height: 54px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        .no-print-actions { background: #f0f4f8; padding: 12px 36px; display: flex; gap: 10px; align-items: center; border-bottom: 1px solid #ddd; }
        @media print {
            body { background: #fff; }
            .no-print-actions { display: none !important; }
            .payslip-wrapper { border: none; border-radius: 0; max-width: 100%; margin: 0; }
            .payslip-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .net-salary-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="payslip-wrapper">
    <!-- Action bar (hidden on print) -->
    <div class="no-print-actions">
        <a href="payslip.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to List
        </a>
        <button class="btn btn-sm btn-primary" onclick="window.print()">
            <i class="fas fa-print me-1"></i> Print / Download PDF
        </button>
        <small class="text-muted ms-2">Use browser's "Save as PDF" option to download as PDF.</small>
    </div>

    <!-- Header -->
    <div class="payslip-header">
        <div class="d-flex align-items-center gap-3">
            <div class="logo-placeholder">
                <i class="fas fa-hospital"></i>
            </div>
            <div>
                <h1>ADMS Hospital</h1>
                <div class="hospital-sub">Advanced Digital Management System &bull; Healthcare Excellence</div>
                <div class="slip-label">SALARY SLIP &mdash; <?php echo strtoupper($month_label); ?></div>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="payslip-body">
        <!-- Employee Details -->
        <div class="emp-details-grid">
            <div class="emp-detail-item">
                <label>Employee Name</label>
                <span><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></span>
            </div>
            <div class="emp-detail-item">
                <label>Employee ID</label>
                <span><?php echo $emp_id; ?></span>
            </div>
            <div class="emp-detail-item">
                <label>Designation</label>
                <span><?php echo htmlspecialchars($record['designation'] ?? '—'); ?></span>
            </div>
            <div class="emp-detail-item">
                <label>Department</label>
                <span><?php echo htmlspecialchars($record['dept_name'] ?? '—'); ?></span>
            </div>
            <div class="emp-detail-item">
                <label>Pay Period</label>
                <span><?php echo $month_label; ?></span>
            </div>
            <div class="emp-detail-item">
                <label>Payment Status</label>
                <span><?php
                    $status = $record['status'] ?? 'unpaid';
                    $sc = $status === 'paid' ? 'success' : 'warning';
                    echo '<span style="color:' . ($status === 'paid' ? '#2e7d32' : '#b45309') . ';font-weight:600;">' . ucfirst(htmlspecialchars($status)) . '</span>';
                ?></span>
            </div>
            <?php if (!empty($record['payment_date'])): ?>
            <div class="emp-detail-item">
                <label>Payment Date</label>
                <span><?php echo htmlspecialchars(date('d M Y', strtotime($record['payment_date']))); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Earnings + Deductions side by side -->
        <div class="pay-tables-row">
            <!-- Earnings -->
            <div class="pay-section earnings">
                <h6><i class="fas fa-plus-circle me-1"></i>Earnings</h6>
                <table class="pay-table">
                    <tr>
                        <td>Basic Salary</td>
                        <td>&#8377;<?php echo number_format($basic, 2); ?></td>
                    </tr>
                    <tr>
                        <td>HRA Allowance</td>
                        <td>&#8377;<?php echo number_format($hra, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Other Allowances</td>
                        <td>&#8377;<?php echo number_format($other_allow, 2); ?></td>
                    </tr>
                    <tr style="font-weight:700;background:#f9fbe7;">
                        <td>Gross Earnings</td>
                        <td>&#8377;<?php echo number_format($gross, 2); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Deductions -->
            <div class="pay-section deductions">
                <h6><i class="fas fa-minus-circle me-1"></i>Deductions</h6>
                <table class="pay-table">
                    <tr>
                        <td>PF Deduction (12%)</td>
                        <td>&#8377;<?php echo number_format($pf, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Tax Deduction (5%)</td>
                        <td>&#8377;<?php echo number_format($tax, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Other Deductions</td>
                        <td>&#8377;<?php echo number_format($other_deduct, 2); ?></td>
                    </tr>
                    <tr style="font-weight:700;background:#fff8f8;">
                        <td>Total Deductions</td>
                        <td>&#8377;<?php echo number_format($deductions, 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Net Salary -->
        <div class="net-salary-box">
            <div class="label">Net Salary Payable</div>
            <div class="amount">&#8377;<?php echo number_format($net, 2); ?></div>
            <div style="font-size:0.8rem;opacity:0.8;margin-top:4px;">
                (<?php echo htmlspecialchars(number_to_words($net)); ?> Rupees Only)
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="payslip-footer">
        <i class="fas fa-shield-alt me-1"></i>
        This is a computer-generated payslip and does not require a signature. &bull;
        Generated on <?php echo date('d M Y, h:i A'); ?> &bull; ADMS Hospital Management System
    </div>
</div>
</body>
</html>
<?php
    // Helper: simple number to words (for amounts)
    function number_to_words($num) {
        $num = (int)$num;
        if ($num == 0) return 'Zero';
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $result = '';
        if ($num >= 100000) {
            $result .= $ones[(int)($num / 100000)] . ' Lakh ';
            $num %= 100000;
        }
        if ($num >= 1000) {
            $t = (int)($num / 1000);
            if ($t < 20) $result .= $ones[$t] . ' Thousand ';
            else $result .= $tens[(int)($t / 10)] . ($t % 10 ? ' ' . $ones[$t % 10] : '') . ' Thousand ';
            $num %= 1000;
        }
        if ($num >= 100) {
            $result .= $ones[(int)($num / 100)] . ' Hundred ';
            $num %= 100;
        }
        if ($num > 0) {
            if ($num < 20) $result .= $ones[$num];
            else $result .= $tens[(int)($num / 10)] . ($num % 10 ? ' ' . $ones[$num % 10] : '');
        }
        return trim($result);
    }

    exit;
}

// --- LIST VIEW ---
$page_title = "Payslips";
include '../../includes/header.php';

if ($is_admin) {
    $payrolls = db_select(
        "SELECT p.*,
                s.first_name, s.last_name, s.designation, s.department_id, s.employee_id,
                d.name AS dept_name
         FROM payroll p
         JOIN staff s ON p.staff_id = s.id
         LEFT JOIN departments d ON s.department_id = d.id
         ORDER BY p.salary_month DESC, s.first_name",
        []
    );
} else {
    $my_staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    $my_staff_id = $my_staff['id'] ?? null;
    if ($my_staff_id) {
        $payrolls = db_select(
            "SELECT p.*,
                    s.first_name, s.last_name, s.designation, s.department_id, s.employee_id,
                    d.name AS dept_name
             FROM payroll p
             JOIN staff s ON p.staff_id = s.id
             LEFT JOIN departments d ON s.department_id = d.id
             WHERE p.staff_id = $1
             ORDER BY p.salary_month DESC",
            [$my_staff_id]
        );
    } else {
        $payrolls = [];
    }
}

// Filter by staff (admin only)
$filter_staff = trim($_GET['filter_staff'] ?? '');
$filter_month = trim($_GET['filter_month'] ?? '');
if ($is_admin && ($filter_staff || $filter_month)) {
    $sql = "SELECT p.*,
                   s.first_name, s.last_name, s.designation, s.employee_id,
                   d.name AS dept_name
            FROM payroll p
            JOIN staff s ON p.staff_id = s.id
            LEFT JOIN departments d ON s.department_id = d.id
            WHERE 1=1";
    $params = [];
    $idx = 1;
    if ($filter_staff) {
        $sql .= " AND p.staff_id = \$$idx";
        $params[] = $filter_staff;
        $idx++;
    }
    if ($filter_month) {
        $sql .= " AND TO_CHAR(p.salary_month, 'YYYY-MM') = \$$idx";
        $params[] = $filter_month;
        $idx++;
    }
    $sql .= " ORDER BY p.salary_month DESC, s.first_name";
    $payrolls = db_select($sql, $params);
}

$staff_list = $is_admin ? db_select("SELECT id, first_name, last_name FROM staff ORDER BY first_name, last_name") : [];
?>

<div class="container-fluid px-4">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Payslips</h1>
            <small class="text-muted"><?php echo $is_admin ? 'All Staff Payslips' : 'Your Payslips'; ?></small>
        </div>
        <?php if ($is_admin): ?>
        <a href="payroll.php" class="btn btn-outline-secondary">
            <i class="fas fa-cog me-1"></i> Manage Payroll
        </a>
        <?php endif; ?>
    </div>

    <?php if ($is_admin && !empty($staff_list)): ?>
    <!-- Filter Bar (Admin) -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-1">Filter by Staff</label>
                    <select name="filter_staff" class="form-control form-control-sm">
                        <option value="">All Staff</option>
                        <?php foreach ($staff_list as $st): ?>
                        <option value="<?php echo htmlspecialchars($st['id']); ?>"
                            <?php echo $filter_staff === $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-1">Filter by Month</label>
                    <input type="month" name="filter_month" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_month); ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="payslip.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payslips Table -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fas fa-list me-2"></i>Payslip Records</span>
            <span class="badge bg-secondary"><?php echo count($payrolls); ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php if ($is_admin): ?>
                            <th>Employee</th>
                            <th>Department</th>
                            <?php endif; ?>
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
                        <?php if (empty($payrolls)): ?>
                        <tr>
                            <td colspan="<?php echo $is_admin ? 9 : 7; ?>" class="text-center text-muted py-4">
                                <i class="fas fa-file-invoice fa-2x mb-2 d-block"></i>
                                No payslips found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($payrolls as $p):
                            $basic_s  = (float)($p['basic_salary'] ?? 0);
                            $allow_s  = (float)($p['allowances'] ?? 0);
                            $deduct_s = (float)($p['deductions'] ?? 0);
                            $net_s    = $basic_s + $allow_s - $deduct_s;
                            $status   = $p['status'] ?? 'unpaid';
                            $status_badge = $status === 'paid' ? 'success' : 'warning';
                        ?>
                        <tr>
                            <?php if ($is_admin): ?>
                            <td>
                                <div class="fw-semibold"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($p['designation'] ?? ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($p['dept_name'] ?? '—'); ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars(date('F Y', strtotime($p['salary_month']))); ?></td>
                            <td>&#8377;<?php echo number_format($basic_s, 2); ?></td>
                            <td>&#8377;<?php echo number_format($allow_s, 2); ?></td>
                            <td>&#8377;<?php echo number_format($deduct_s, 2); ?></td>
                            <td class="fw-bold text-success">&#8377;<?php echo number_format($net_s, 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $status_badge; ?>">
                                    <?php echo ucfirst(htmlspecialchars($status)); ?>
                                </span>
                            </td>
                            <td>
                                <a href="payslip.php?payroll_id=<?php echo htmlspecialchars($p['id']); ?>"
                                   class="btn btn-sm btn-outline-primary" target="_blank"
                                   title="View & Print Payslip">
                                    <i class="fas fa-print me-1"></i> View / Print
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($payrolls)): ?>
        <div class="card-footer text-muted small">
            Showing <?php echo count($payrolls); ?> payslip(s).
            Click "View / Print" to open the print-ready payslip in a new tab.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
