<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth(); // Allow all staff to see their own payroll
$role    = $_SESSION['role'];
$is_admin = ($role === 'admin');

// ─── PAYSLIP PRINT VIEW (Extracted from payslip.php) ────────────────────────
if (isset($_GET['payroll_id'])) {
    $payroll_id = trim($_GET['payroll_id']);

    $record = db_select_one(
        "SELECT p.*,
                s.first_name, s.last_name, s.role, s.department_id,
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

    $hra         = round($basic * 0.20, 2);
    $other_allow = max(0, $allowances - $hra);
    $pf          = round($basic * 0.12, 2);
    $tax         = round($basic * 0.05, 2);
    $other_deduct= max(0, $deductions - $pf - $tax);

    $gross       = $basic + $allowances;
    $net         = $gross - $deductions;

    $month_label = $record['salary_month'] ? date('F Y', strtotime($record['salary_month'])) : '—';
    $emp_id = 'EMP-' . strtoupper(substr($record['staff_id'], 0, 6));
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
        .net-salary-box { background: linear-gradient(135deg, #1e3a5f 0%, #2e6da4 100%); color: #fff; border-radius: 8px; padding: 18px 24px; text-align: center; margin-top: 20px; }
        .net-salary-box .amount { font-size: 2rem; font-weight: 800; margin-top: 4px; }
        .payslip-footer { text-align: center; padding: 16px 36px; background: #f9f9f9; border-top: 1px solid #eee; font-size: 0.78rem; color: #999; }
        .logo-placeholder { width: 54px; height: 54px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; }
        @media print {
            body { background: #fff; }
            .no-print-actions { display: none !important; }
            .payslip-wrapper { border: none; border-radius: 0; max-width: 100%; margin: 0; }
        }
    </style>
</head>
<body>
<div class="payslip-wrapper">
    <div class="no-print-actions" style="padding: 10px 36px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; gap:10px;">
        <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print / Save PDF</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.close()">Close</button>
    </div>
    <div class="payslip-header">
        <div class="d-flex align-items-center gap-3">
            <div class="logo-placeholder"><i class="fas fa-hospital"></i></div>
            <div>
                <h1>ADMS Hospital</h1>
                <div class="hospital-sub">Advanced Digital Management System &bull; Healthcare Excellence</div>
                <div class="slip-label">SALARY SLIP &mdash; <?php echo strtoupper($month_label); ?></div>
            </div>
        </div>
    </div>
    <div class="payslip-body">
        <div class="emp-details-grid">
            <div class="emp-detail-item"><label>Employee Name</label><span><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></span></div>
            <div class="emp-detail-item"><label>Employee ID</label><span><?php echo $emp_id; ?></span></div>
            <div class="emp-detail-item"><label>Designation</label><span><?php echo htmlspecialchars($record['role'] ?? '—'); ?></span></div>
            <div class="emp-detail-item"><label>Department</label><span><?php echo htmlspecialchars($record['dept_name'] ?? '—'); ?></span></div>
            <div class="emp-detail-item"><label>Pay Period</label><span><?php echo $month_label; ?></span></div>
            <div class="emp-detail-item"><label>Status</label><span><strong style="color:<?php echo $record['status']==='paid'?'#15803d':'#b45309'; ?>;"><?php echo ucfirst($record['status']); ?></strong></span></div>
        </div>
        <div class="row">
            <div class="col-6">
                <div class="pay-section earnings">
                    <h6>Earnings</h6>
                    <table class="pay-table">
                        <tr><td>Basic Salary</td><td>₹<?php echo number_format($basic, 2); ?></td></tr>
                        <tr><td>HRA (20%)</td><td>₹<?php echo number_format($hra, 2); ?></td></tr>
                        <tr><td>Other Allowances</td><td>₹<?php echo number_format($other_allow, 2); ?></td></tr>
                        <tr style="font-weight:700;"><td>Gross Earnings</td><td>₹<?php echo number_format($gross, 2); ?></td></tr>
                    </table>
                </div>
            </div>
            <div class="col-6">
                <div class="pay-section deductions">
                    <h6>Deductions</h6>
                    <table class="pay-table">
                        <tr><td>PF (12%)</td><td>₹<?php echo number_format($pf, 2); ?></td></tr>
                        <tr><td>Tax (5%)</td><td>₹<?php echo number_format($tax, 2); ?></td></tr>
                        <tr><td>Leave Deductions</td><td>₹<?php echo number_format($other_deduct, 2); ?></td></tr>
                        <tr style="font-weight:700;"><td>Total Deductions</td><td>₹<?php echo number_format($deductions, 2); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="net-salary-box">
            <div style="font-size:0.75rem; text-transform:uppercase; opacity:0.85;">Net Salary Payable</div>
            <div class="amount">₹<?php echo number_format($net, 2); ?></div>
        </div>
    </div>
    <div class="payslip-footer">Computer generated document. No signature required.</div>
</div>
</body>
</html>
<?php exit; }

$user_id = get_user_id();

// Default salary per role (used when no DB setting exists)
$role_defaults = [
    'doctor'       => ['basic' => 80000, 'allowances' => 15000],
    'nurse'        => ['basic' => 25000, 'allowances' => 5000],
    'head_nurse'   => ['basic' => 35000, 'allowances' => 7000],
    'receptionist' => ['basic' => 20000, 'allowances' => 4000],
    'pharmacist'   => ['basic' => 30000, 'allowances' => 6000],
    'lab_tech'     => ['basic' => 28000, 'allowances' => 5000],
    'radiologist'  => ['basic' => 45000, 'allowances' => 8000],
    'admin'        => ['basic' => 35000, 'allowances' => 7000],
];

// Create salary_settings table if it doesn't exist
try {
    db_query("CREATE TABLE IF NOT EXISTS salary_settings (
        role VARCHAR(50) PRIMARY KEY,
        basic_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
        allowances   DECIMAL(10,2) NOT NULL DEFAULT 0,
        updated_at   TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) {}

// Load salary settings from DB
$db_salary = [];
try {
    foreach (db_select("SELECT * FROM salary_settings") as $s) {
        $db_salary[$s['role']] = ['basic' => (float)$s['basic_salary'], 'allowances' => (float)$s['allowances']];
    }
} catch (Exception $e) {}

// Merge DB settings with defaults
$salary_cfg = $role_defaults;
foreach ($db_salary as $r => $v) { $salary_cfg[$r] = $v; }

// ── AJAX: calculate payroll for a staff member + month ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'calc') {
    header('Content-Type: application/json');
    $sid   = trim($_GET['staff_id'] ?? '');
    $month = trim($_GET['month']    ?? date('Y-m'));

    $staff = db_select_one("SELECT id, role FROM staff WHERE id = $1", [$sid]);
    if (!$staff) { echo json_encode(['error' => 'Staff not found']); exit; }

    $srole = $staff['role'];
    $cfg   = $salary_cfg[$srole] ?? ['basic' => 30000, 'allowances' => 5000];
    $basic = $cfg['basic'];
    $allow = $cfg['allowances'];

    $m_start = $month . '-01';
    $m_end   = date('Y-m-t', strtotime($m_start));
    $leave_days = 0;

    try {
        if ($srole === 'doctor') {
            // Doctor: approved leave days from doctor_availability
            $rows = db_select(
                "SELECT available_date FROM doctor_availability
                 WHERE doctor_id = $1 AND is_available = false
                   AND approval_status = 'approved'
                   AND available_date BETWEEN $2 AND $3",
                [$sid, $m_start, $m_end]
            );
            $leave_days = count($rows);
        } else {
            // Non-doctor: use leaves table (approved, overlapping the month)
            $rows = db_select(
                "SELECT start_date, end_date, leave_days FROM leaves
                 WHERE staff_id = $1 AND status = 'approved'
                   AND start_date <= $3 AND end_date >= $2",
                [$sid, $m_start, $m_end]
            );
            foreach ($rows as $l) {
                if ($l['leave_days']) {
                    // Clip to month boundary
                    $from = max(strtotime($l['start_date']), strtotime($m_start));
                    $to   = min(strtotime($l['end_date']),   strtotime($m_end));
                    $leave_days += max(0, round(($to - $from) / 86400) + 1);
                }
            }
        }
    } catch (Exception $e) {}

    $working_days = 26;
    $daily_rate   = round($basic / $working_days, 2);
    $deduction    = round($leave_days * $daily_rate, 2);
    $net          = $basic + $allow - $deduction;

    echo json_encode([
        'basic'        => $basic,
        'allowances'   => $allow,
        'leave_days'   => $leave_days,
        'daily_rate'   => $daily_rate,
        'deduction'    => $deduction,
        'net'          => $net,
        'role'         => $srole,
    ]);
    exit;
}

$success = '';
$error   = '';

// ── POST: Save salary settings ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    foreach ($role_defaults as $r => $d) {
        $basic = (float)($_POST['basic_'.$r] ?? $d['basic']);
        $allow = (float)($_POST['allow_'.$r] ?? $d['allowances']);
        try {
            db_query(
                "INSERT INTO salary_settings (role, basic_salary, allowances, updated_at)
                 VALUES ($1, $2, $3, NOW())
                 ON CONFLICT (role) DO UPDATE SET basic_salary=$2, allowances=$3, updated_at=NOW()",
                [$r, $basic, $allow]
            );
            $salary_cfg[$r] = ['basic' => $basic, 'allowances' => $allow];
        } catch (Exception $e) { $error = "Save failed: " . $e->getMessage(); }
    }
    if (!$error) $success = "Salary settings saved successfully.";
}

// ── POST: Generate payroll ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    $staff_id   = trim($_POST['staff_id'] ?? '');
    $month      = trim($_POST['salary_month'] ?? date('Y-m'));
    $basic      = (float)($_POST['basic_salary'] ?? 0);
    $allowances = (float)($_POST['allowances']   ?? 0);
    $deductions = (float)($_POST['deductions']   ?? 0);
    $net        = $basic + $allowances - $deductions;
    $month_date = $month . '-01';

    if ($staff_id && $basic > 0) {
        try {
            // Remove existing entry for same staff+month before inserting
            db_query("DELETE FROM payroll WHERE staff_id = $1 AND salary_month = $2", [$staff_id, $month_date]);
            db_query(
                "INSERT INTO payroll (staff_id, salary_month, basic_salary, allowances, deductions, status)
                 VALUES ($1, $2, $3, $4, $5, 'unpaid')",
                [$staff_id, $month_date, (string)$basic, (string)$allowances, (string)$deductions]
            );
            $success = "Payroll generated successfully.";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Please select staff and enter a valid salary.";
    }
}

// ── POST: Bulk generate for all staff ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_generate'])) {
    $month      = trim($_POST['bulk_month'] ?? date('Y-m'));
    $month_date = $month . '-01';
    $m_end      = date('Y-m-t', strtotime($month_date));
    $all_staff  = db_select("SELECT id, role FROM staff");
    $generated  = 0;

    foreach ($all_staff as $st) {
        $srole = $st['role'];
        $cfg   = $salary_cfg[$srole] ?? ['basic' => 30000, 'allowances' => 5000];
        $basic = $cfg['basic'];
        $allow = $cfg['allowances'];
        $leave_days = 0;

        try {
            if ($srole === 'doctor') {
                $rows = db_select(
                    "SELECT available_date FROM doctor_availability
                     WHERE doctor_id = $1 AND is_available = false
                       AND approval_status = 'approved'
                       AND available_date BETWEEN $2 AND $3",
                    [$st['id'], $month_date, $m_end]
                );
                $leave_days = count($rows);
            } else {
                $rows = db_select(
                    "SELECT start_date, end_date FROM leaves
                     WHERE staff_id = $1 AND status = 'approved'
                       AND start_date <= $3 AND end_date >= $2",
                    [$st['id'], $month_date, $m_end]
                );
                foreach ($rows as $l) {
                    $from = max(strtotime($l['start_date']), strtotime($month_date));
                    $to   = min(strtotime($l['end_date']),   strtotime($m_end));
                    $leave_days += max(0, round(($to - $from) / 86400) + 1);
                }
            }
        } catch (Exception $e) {}

        $deduction = round($leave_days * ($basic / 26), 2);
        $net       = $basic + $allow - $deduction;

        try {
            db_query("DELETE FROM payroll WHERE staff_id = $1 AND salary_month = $2", [$st['id'], $month_date]);
            db_query(
                "INSERT INTO payroll (staff_id, salary_month, basic_salary, allowances, deductions, status)
                 VALUES ($1, $2, $3, $4, $5, 'unpaid')",
                [$st['id'], $month_date, (string)$basic, (string)$allow, (string)$deduction]
            );
            $generated++;
        } catch (Exception $e) {}
    }
    $success = "Bulk payroll generated for $generated staff members for " . date('F Y', strtotime($month_date)) . ".";
}

// ── GET: Mark as paid ──────────────────────────────────────────────────────
if (isset($_GET['mark_paid'])) {
    try {
        db_query("UPDATE payroll SET status='paid', payment_date=NOW() WHERE id=$1", [$_GET['mark_paid']]);
        $success = "Marked as paid!";
    } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
}

// ── Fetch data ─────────────────────────────────────────────────────────────
$filter_month  = $_GET['fmonth']  ?? '';
$filter_status = $_GET['fstatus'] ?? '';

$staff_list = db_select("SELECT id, first_name, last_name, role FROM staff ORDER BY role, first_name");
$active_tab = $_GET['tab'] ?? 'records';

// Payroll records with filters
$p_sql = "SELECT p.*, s.first_name, s.last_name, s.role AS staff_role
          FROM payroll p JOIN staff s ON p.staff_id = s.id";
$p_where = []; $p_params = []; $p_i = 1;

if (!$is_admin) {
    $my_staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$_SESSION['user_id']]);
    $p_where[] = "p.staff_id = \$$p_i"; 
    $p_params[] = $my_staff['id'] ?? '00000000-0000-0000-0000-000000000000'; 
    $p_i++;
}

if ($filter_month)  { $p_where[] = "TO_CHAR(p.salary_month,'YYYY-MM') = \$$p_i"; $p_params[] = $filter_month;  $p_i++; }
if ($filter_status) { $p_where[] = "p.status = \$$p_i";                           $p_params[] = $filter_status; $p_i++; }
if ($p_where) $p_sql .= " WHERE " . implode(" AND ", $p_where);
$p_sql .= " ORDER BY p.salary_month DESC, s.last_name";
$payrolls = db_select($p_sql, $p_params);

// Stats — computed from $payrolls (proven to work since table renders from it)
// For accurate global stats when filters are active, fetch a clean unfiltered set
$cur_month = date('Y-m'); // e.g. '2026-04'
$stats = ['disbursed' => 0, 'pending' => 0, 'this_month' => 0, 'avg_net' => 0];
$_nets = [];
$_stat_rows = db_select("SELECT TO_CHAR(salary_month,'YYYY-MM') AS ym, basic_salary, allowances, deductions, status FROM payroll");
foreach ($_stat_rows as $_r) {
    $_net = (float)$_r['basic_salary'] + (float)$_r['allowances'] - (float)$_r['deductions'];
    if ($_r['status'] === 'unpaid')   $stats['pending']++;
    if ($_r['ym']    === $cur_month) {
        $stats['this_month']++;
        $_nets[] = $_net;
        if ($_r['status'] === 'paid') $stats['disbursed'] += $_net;
    }
}
$stats['avg_net'] = $_nets ? round(array_sum($_nets) / count($_nets)) : 0;

$page_title = "Payroll";
include '../../includes/header.php';
?>

<style>
.pr-wrap { max-width:1320px; margin:0 auto; padding:20px; }

/* Tabs */
.pr-tabs { display:flex; gap:4px; background:#f3f4f6; border-radius:12px; padding:4px; width:fit-content; margin-bottom:24px; }
.pr-tab  { padding:8px 22px; border-radius:9px; font-size:0.88em; font-weight:600; color:#6b7280; text-decoration:none; display:flex; align-items:center; gap:7px; transition:all 0.18s; }
.pr-tab:hover { color:#374151; }
.pr-tab.active { background:white; color:#6366f1; box-shadow:0 1px 6px rgba(0,0,0,.1); }

/* Stat cards */
.pr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
.pr-stat  { background:white; border-radius:14px; border:1px solid #e5e7eb; padding:18px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 2px 8px rgba(0,0,0,.05); }
.pr-stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2em; flex-shrink:0; }
.pr-stat-val  { font-size:1.4em; font-weight:800; color:#111827; line-height:1; }
.pr-stat-lbl  { font-size:0.78em; color:#6b7280; font-weight:500; margin-top:3px; }

/* Card */
.pr-card { background:white; border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 2px 10px rgba(0,0,0,.05); overflow:hidden; }
.pr-card-hd { padding:18px 22px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }

/* Table */
.pr-table { width:100%; border-collapse:collapse; font-size:0.88em; }
.pr-table th { background:#f9fafb; padding:10px 16px; text-align:left; font-size:0.77em; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.pr-table td { padding:13px 16px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; }
.pr-table tr:hover td { background:#f9fafb; }
.pr-table tr:last-child td { border-bottom:none; }

/* Badges */
.st-pill { display:inline-block; padding:2px 10px; border-radius:99px; font-size:0.78em; font-weight:600; }
.st-paid   { background:#d1fae5; color:#065f46; }
.st-unpaid { background:#fef9c3; color:#854d0e; }
.role-pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:0.75em; font-weight:600; background:#e0e7ff; color:#3730a3; }
.role-pill.doctor       { background:#dbeafe; color:#1e40af; }
.role-pill.nurse        { background:#ede9fe; color:#5b21b6; }
.role-pill.head_nurse   { background:#fce7f3; color:#9d174d; }
.role-pill.pharmacist   { background:#d1fae5; color:#065f46; }
.role-pill.lab_tech     { background:#fef3c7; color:#92400e; }
.role-pill.radiologist  { background:#ffedd5; color:#c2410c; }
.role-pill.receptionist { background:#f3e8ff; color:#6d28d9; }
.role-pill.admin        { background:#f1f5f9; color:#475569; }

/* Buttons */
.btn-primary { background:#6366f1; color:white; border:none; border-radius:10px; padding:9px 18px; font-weight:700; cursor:pointer; font-size:0.88em; display:inline-flex; align-items:center; gap:6px; }
.btn-primary:hover { background:#4f46e5; }
.btn-sm { padding:5px 12px; border-radius:7px; font-size:0.8em; font-weight:600; border:none; cursor:pointer; }
.btn-pay   { background:#d1fae5; color:#065f46; }
.btn-pay:hover { background:#a7f3d0; }
.btn-bulk  { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white; border:none; border-radius:10px; padding:9px 18px; font-weight:700; cursor:pointer; font-size:0.88em; display:inline-flex; align-items:center; gap:6px; }
.btn-bulk:hover { opacity:0.9; }

/* Filters */
.pr-filters { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.pr-filters select, .pr-filters input[type=text] { padding:7px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.85em; outline:none; color:#374151; }
.pr-filters select:focus { border-color:#6366f1; }

/* Settings form */
.settings-grid { display:grid; gap:0; }
.setting-row { display:grid; grid-template-columns:160px 1fr 1fr; gap:12px; align-items:center; padding:14px 22px; border-bottom:1px solid #f3f4f6; }
.setting-row:last-child { border-bottom:none; }
.setting-row:hover { background:#f9fafb; }
.setting-role { font-weight:600; font-size:0.88em; color:#374151; }
.setting-input { padding:8px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.88em; outline:none; width:100%; box-sizing:border-box; text-align:right; }
.setting-input:focus { border-color:#6366f1; }
.setting-label { font-size:0.75em; color:#9ca3af; font-weight:500; text-align:right; }
.settings-header { display:grid; grid-template-columns:160px 1fr 1fr; gap:12px; padding:10px 22px; background:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:0.77em; font-weight:700; color:#6b7280; text-transform:uppercase; }

/* Modal */
.pr-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); backdrop-filter:blur(4px); z-index:1000; align-items:center; justify-content:center; }
.pr-overlay.open { display:flex; }
.pr-modal { background:white; border-radius:20px; width:520px; max-width:95vw; box-shadow:0 20px 40px rgba(0,0,0,.15); overflow:hidden; animation:modalIn .2s ease; }
@keyframes modalIn { from{transform:translateY(30px);opacity:0} to{transform:none;opacity:1} }
.modal-hd { padding:22px 24px 0; display:flex; justify-content:space-between; align-items:center; }
.modal-bd { padding:20px 24px 24px; }
.modal-close { background:none; border:none; font-size:1.2em; color:#9ca3af; cursor:pointer; }
.modal-close:hover { color:#374151; }
.fg { margin-bottom:14px; }
.fg label { display:block; font-size:0.81em; font-weight:700; color:#374151; margin-bottom:5px; text-transform:uppercase; letter-spacing:.03em; }
.fg input, .fg select { width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:9px; font-size:0.9em; outline:none; transition:border .2s; box-sizing:border-box; }
.fg input:focus, .fg select:focus { border-color:#6366f1; }
.fg-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
.leave-info { background:#fef9c3; border:1px solid #fde047; border-radius:9px; padding:10px 13px; font-size:0.83em; color:#854d0e; margin-bottom:14px; display:none; }
.leave-info.show { display:block; }
.net-preview { background:#d1fae5; border:1px solid #6ee7b7; border-radius:9px; padding:12px 14px; margin-top:14px; display:none; }
.net-preview.show { display:block; }
.net-preview .net-val { font-size:1.3em; font-weight:800; color:#065f46; }

@media(max-width:700px) { .fg-row { grid-template-columns:1fr; } .setting-row { grid-template-columns:1fr 1fr; } }
</style>

<div class="pr-wrap">

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
        <div style="width:46px;height:46px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.3em;flex-shrink:0;">
            <i class="fas fa-money-check-alt"></i>
        </div>
        <div>
            <h2 style="margin:0;font-size:1.4em;font-weight:800;color:#111827;">Payroll Management</h2>
            <p style="margin:0;color:#6b7280;font-size:0.88em;">Dynamic payroll with leave-based deductions</p>
        </div>
        <?php if ($is_admin): ?>
        <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn-bulk" onclick="document.getElementById('bulkModal').classList.add('open')">
                <i class="fas fa-bolt"></i> Bulk Generate
            </button>
            <button class="btn-primary" onclick="document.getElementById('genModal').classList.add('open')">
                <i class="fas fa-plus"></i> Generate Payroll
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="pr-stats">
        <div class="pr-stat">
            <div class="pr-stat-icon" style="background:rgba(99,102,241,.1);color:#6366f1;"><i class="fas fa-rupee-sign"></i></div>
            <div><div class="pr-stat-val">₹<?= number_format($stats['disbursed'] ?? 0, 0) ?></div><div class="pr-stat-lbl">Disbursed This Month</div></div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-icon" style="background:rgba(234,179,8,.1);color:#ca8a04;"><i class="fas fa-hourglass-half"></i></div>
            <div><div class="pr-stat-val"><?= $stats['pending'] ?? 0 ?></div><div class="pr-stat-lbl">Pending Payments</div></div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-icon" style="background:rgba(16,185,129,.1);color:#059669;"><i class="fas fa-calendar-check"></i></div>
            <div><div class="pr-stat-val"><?= $stats['this_month'] ?? 0 ?></div><div class="pr-stat-lbl">Records This Month</div></div>
        </div>
        <div class="pr-stat">
            <div class="pr-stat-icon" style="background:rgba(139,92,246,.1);color:#7c3aed;"><i class="fas fa-chart-bar"></i></div>
            <div><div class="pr-stat-val">₹<?= number_format($stats['avg_net'] ?? 0, 0) ?></div><div class="pr-stat-lbl">Avg Net Salary</div></div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-size:0.9em;"><i class="fas fa-check-circle" style="margin-right:6px;"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#dc2626;font-size:0.9em;"><i class="fas fa-exclamation-circle" style="margin-right:6px;"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="pr-tabs">
        <a href="?tab=records" class="pr-tab <?= $active_tab === 'records' ? 'active' : '' ?>"><i class="fas fa-list"></i> Payroll Records</a>
        <?php if ($is_admin): ?>
        <a href="?tab=settings" class="pr-tab <?= $active_tab === 'settings' ? 'active' : '' ?>"><i class="fas fa-sliders-h"></i> Salary Settings</a>
        <?php endif; ?>
    </div>

    <?php if ($active_tab === 'records'): ?>
    <!-- ═══════════════ PAYROLL RECORDS ═══════════════ -->
    <div class="pr-card">
        <div class="pr-card-hd">
            <div style="font-weight:700;color:#111827;font-size:1em;"><i class="fas fa-table" style="color:#6366f1;margin-right:7px;"></i>Payroll History</div>
            <div class="pr-filters">
                <form method="GET" style="display:contents;">
                    <input type="hidden" name="tab" value="records">
                    <input type="month" name="fmonth" value="<?= htmlspecialchars($filter_month) ?>"
                           style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85em;outline:none;">
                    <select name="fstatus" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85em;outline:none;">
                        <option value="">All Statuses</option>
                        <option value="unpaid" <?= $filter_status==='unpaid'?'selected':'' ?>>Unpaid</option>
                        <option value="paid"   <?= $filter_status==='paid'  ?'selected':'' ?>>Paid</option>
                    </select>
                    <button type="submit" style="padding:7px 14px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.85em;font-weight:600;cursor:pointer;">Filter</button>
                    <a href="?tab=records" style="padding:7px 14px;background:#f3f4f6;color:#374151;border-radius:8px;font-size:0.85em;font-weight:600;text-decoration:none;">Reset</a>
                </form>
                <input type="text" id="srch" onkeyup="filterRows()" placeholder="Search name..." style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85em;outline:none;width:160px;">
            </div>
        </div>

        <?php if (empty($payrolls)): ?>
        <div style="text-align:center;padding:60px;color:#9ca3af;">
            <i class="fas fa-file-invoice-dollar fa-3x" style="color:#e5e7eb;margin-bottom:14px;display:block;"></i>
            No payroll records found. Generate payroll to get started.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="pr-table" id="pr-tbl">
            <thead>
                <tr>
                    <th>Staff Member</th>
                    <th>Role</th>
                    <th>Month</th>
                    <th>Basic</th>
                    <th>Allowances</th>
                    <th>Leave Deduction</th>
                    <th>Net Salary</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payrolls as $pay):
                $net = (float)($pay['net_salary'] ?: ($pay['basic_salary'] + $pay['allowances'] - $pay['deductions']));
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-size:0.8em;font-weight:700;flex-shrink:0;">
                            <?= strtoupper(substr($pay['first_name'],0,1).substr($pay['last_name'],0,1)) ?>
                        </div>
                        <span style="font-weight:600;color:#111827;" class="staff-name"><?= htmlspecialchars($pay['first_name'].' '.$pay['last_name']) ?></span>
                    </div>
                </td>
                <td><span class="role-pill <?= htmlspecialchars($pay['staff_role']) ?>"><?= ucfirst(str_replace('_',' ',$pay['staff_role'])) ?></span></td>
                <td style="font-weight:500;"><?= date('M Y', strtotime($pay['salary_month'])) ?></td>
                <td>₹<?= number_format($pay['basic_salary'], 0) ?></td>
                <td style="color:#059669;">+₹<?= number_format($pay['allowances'], 0) ?></td>
                <td style="color:<?= $pay['deductions'] > 0 ? '#dc2626' : '#9ca3af' ?>;">
                    <?php if ($pay['deductions'] > 0): ?>
                        <span title="Leave deduction">−₹<?= number_format($pay['deductions'], 0) ?></span>
                    <?php else: ?>
                        <span>—</span>
                    <?php endif; ?>
                </td>
                <td><strong style="color:#111827;font-size:0.95em;">₹<?= number_format($net, 0) ?></strong></td>
                <td><span class="st-pill st-<?= $pay['status'] ?>"><?= ucfirst($pay['status']) ?></span></td>
                <td>
                    <a href="?payroll_id=<?= $pay['id'] ?>" target="_blank" class="btn-sm" style="background:#e0e7ff; color:#3730a3; text-decoration:none;">
                        <i class="fas fa-print"></i> View
                    </a>
                    <?php if ($is_admin && $pay['status'] === 'unpaid'): ?>
                    <a href="?mark_paid=<?= $pay['id'] ?>&tab=records" class="btn-sm btn-pay" onclick="return confirm('Mark as paid?')">
                        <i class="fas fa-check"></i> Pay
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ═══════════════ SALARY SETTINGS ═══════════════ -->
    <div class="pr-card">
        <div class="pr-card-hd">
            <div>
                <div style="font-weight:700;color:#111827;font-size:1em;"><i class="fas fa-sliders-h" style="color:#6366f1;margin-right:7px;"></i>Standard Salary by Role</div>
                <div style="font-size:0.8em;color:#6b7280;margin-top:3px;">These values auto-fill when generating payroll. Leave deductions are calculated from these base salaries (÷ 26 working days).</div>
            </div>
        </div>
        <form method="POST">
            <div class="settings-header">
                <div>Role</div>
                <div style="text-align:right;">Basic Salary (₹/month)</div>
                <div style="text-align:right;">Allowances (₹/month)</div>
            </div>
            <div class="settings-grid">
            <?php
            $role_icons = [
                'doctor' => 'fa-user-md', 'nurse' => 'fa-user-nurse', 'head_nurse' => 'fa-star-of-life',
                'receptionist' => 'fa-headset', 'pharmacist' => 'fa-pills', 'lab_tech' => 'fa-flask',
                'radiologist' => 'fa-x-ray', 'admin' => 'fa-user-shield',
            ];
            foreach ($salary_cfg as $r => $cfg):
                $icon = $role_icons[$r] ?? 'fa-user';
                $net_example = $cfg['basic'] + $cfg['allowances'];
            ?>
            <div class="setting-row">
                <div class="setting-role">
                    <span class="role-pill <?= $r ?>" style="margin-right:6px;"><i class="fas <?= $icon ?>"></i></span>
                    <?= ucfirst(str_replace('_',' ',$r)) ?>
                    <div style="font-size:0.76em;color:#9ca3af;margin-top:2px;">Net: ₹<?= number_format($net_example, 0) ?></div>
                </div>
                <div>
                    <div class="setting-label">Basic</div>
                    <input type="number" class="setting-input" name="basic_<?= $r ?>" value="<?= $cfg['basic'] ?>" step="500" min="0">
                </div>
                <div>
                    <div class="setting-label">Allowances</div>
                    <input type="number" class="setting-input" name="allow_<?= $r ?>" value="<?= $cfg['allowances'] ?>" step="500" min="0">
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <div style="padding:18px 22px;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="font-size:0.83em;color:#6b7280;">
                    <i class="fas fa-info-circle" style="color:#6366f1;"></i>
                    Deduction = (Basic ÷ 26) × leave days taken in the month
                </div>
                <button type="submit" name="save_settings" class="btn-primary">
                    <i class="fas fa-save"></i> Save Salary Settings
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<!-- ═══════ Generate Payroll Modal ═══════ -->
<div id="genModal" class="pr-overlay">
    <div class="pr-modal">
        <div class="modal-hd">
            <div style="font-weight:800;font-size:1.1em;color:#111827;"><i class="fas fa-file-invoice-dollar" style="color:#6366f1;margin-right:8px;"></i>Generate Payroll</div>
            <button class="modal-close" onclick="closeGen()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-bd">
            <form method="POST" id="genForm">
                <div class="fg">
                    <label>Staff Member</label>
                    <select name="staff_id" id="gen-staff" required onchange="calcPayroll()">
                        <option value="">-- Select Staff --</option>
                        <?php
                        $prev_role = '';
                        foreach ($staff_list as $st):
                            if ($st['role'] !== $prev_role) {
                                if ($prev_role) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars(ucfirst(str_replace('_',' ',$st['role']))) . '">';
                                $prev_role = $st['role'];
                            }
                        ?>
                        <option value="<?= $st['id'] ?>" data-role="<?= $st['role'] ?>">
                            <?= htmlspecialchars($st['first_name'].' '.$st['last_name']) ?>
                        </option>
                        <?php endforeach; if ($prev_role) echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Salary Month</label>
                    <input type="month" name="salary_month" id="gen-month" value="<?= date('Y-m') ?>" required onchange="calcPayroll()">
                </div>

                <div id="leave-info" class="leave-info"></div>

                <div class="fg-row">
                    <div class="fg">
                        <label>Basic Salary (₹)</label>
                        <input type="number" name="basic_salary" id="gen-basic" step="0.01" required>
                    </div>
                    <div class="fg">
                        <label>Allowances (₹)</label>
                        <input type="number" name="allowances" id="gen-allow" step="0.01" value="0">
                    </div>
                    <div class="fg">
                        <label>Deductions (₹)</label>
                        <input type="number" name="deductions" id="gen-ded" step="0.01" value="0">
                    </div>
                </div>

                <div id="net-preview" class="net-preview">
                    <div style="font-size:0.78em;color:#065f46;font-weight:600;margin-bottom:4px;">NET SALARY</div>
                    <div class="net-val" id="net-val">₹0</div>
                </div>

                <div style="display:flex;gap:10px;margin-top:18px;">
                    <button type="button" onclick="calcPayroll()" style="flex:1;background:#f3f4f6;border:1px solid #d1d5db;border-radius:9px;padding:9px;font-weight:600;cursor:pointer;font-size:0.88em;">
                        <i class="fas fa-sync-alt"></i> Auto-Calculate
                    </button>
                    <button type="submit" name="generate_payroll" class="btn-primary" style="flex:2;">
                        <i class="fas fa-save"></i> Generate Payslip
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════ Bulk Generate Modal ═══════ -->
<div id="bulkModal" class="pr-overlay">
    <div class="pr-modal" style="max-width:420px;">
        <div class="modal-hd">
            <div style="font-weight:800;font-size:1.1em;color:#111827;"><i class="fas fa-bolt" style="color:#8b5cf6;margin-right:8px;"></i>Bulk Generate Payroll</div>
            <button class="modal-close" onclick="closeBulk()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-bd">
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:9px;padding:12px 14px;margin-bottom:18px;font-size:0.84em;color:#854d0e;">
                <i class="fas fa-info-circle"></i>
                This will generate payroll for <strong>all staff</strong> using their role's standard salary and auto-deduct approved leaves for the selected month. Existing records for the month will be replaced.
            </div>
            <form method="POST">
                <div class="fg">
                    <label>Payroll Month</label>
                    <input type="month" name="bulk_month" value="<?= date('Y-m') ?>" required>
                </div>
                <div style="display:flex;gap:10px;margin-top:6px;">
                    <button type="button" onclick="closeBulk()" style="flex:1;background:#f3f4f6;border:1px solid #d1d5db;border-radius:9px;padding:9px;font-weight:600;cursor:pointer;font-size:0.88em;">Cancel</button>
                    <button type="submit" name="bulk_generate" class="btn-bulk" style="flex:2;justify-content:center;" onclick="return confirm('Generate payroll for all staff for this month?')">
                        <i class="fas fa-bolt"></i> Generate for All Staff
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function closeGen()  { document.getElementById('genModal').classList.remove('open'); }
function closeBulk() { document.getElementById('bulkModal').classList.remove('open'); }

// Close on outside click
document.querySelectorAll('.pr-overlay').forEach(o => {
    o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.pr-overlay.open').forEach(o => o.classList.remove('open'));
});

// Auto-calculate from server
function calcPayroll() {
    const sid   = document.getElementById('gen-staff').value;
    const month = document.getElementById('gen-month').value;
    if (!sid || !month) return;

    fetch(`?ajax=calc&staff_id=${encodeURIComponent(sid)}&month=${encodeURIComponent(month)}`)
        .then(r => r.json())
        .then(d => {
            if (d.error) return;
            document.getElementById('gen-basic').value = d.basic;
            document.getElementById('gen-allow').value = d.allowances;
            document.getElementById('gen-ded').value   = d.deduction;

            const infoBox = document.getElementById('leave-info');
            if (d.leave_days > 0) {
                infoBox.innerHTML = `<i class="fas fa-calendar-times" style="margin-right:6px;"></i>
                    <strong>${d.leave_days} leave day(s)</strong> detected this month —
                    deduction: ₹${d.daily_rate}/day × ${d.leave_days} = <strong>₹${d.deduction.toLocaleString()}</strong>`;
                infoBox.classList.add('show');
            } else {
                infoBox.innerHTML = `<i class="fas fa-check-circle" style="color:#065f46;margin-right:6px;"></i>No approved leaves this month — full salary applicable.`;
                infoBox.classList.add('show');
            }
            updateNet();
        });
}

function updateNet() {
    const basic = parseFloat(document.getElementById('gen-basic').value) || 0;
    const allow = parseFloat(document.getElementById('gen-allow').value) || 0;
    const ded   = parseFloat(document.getElementById('gen-ded').value) || 0;
    const net   = basic + allow - ded;
    document.getElementById('net-val').textContent = '₹' + net.toLocaleString('en-IN');
    document.getElementById('net-preview').classList.add('show');
}

['gen-basic','gen-allow','gen-ded'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updateNet);
});

// Table search
function filterRows() {
    const q = document.getElementById('srch').value.toLowerCase();
    document.querySelectorAll('#pr-tbl tbody tr').forEach(row => {
        const name = row.querySelector('.staff-name')?.textContent.toLowerCase() || '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
