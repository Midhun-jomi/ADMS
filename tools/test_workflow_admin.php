<?php
// tools/test_workflow_admin.php
define('ROOT_PATH', __DIR__ . '/..');
require_once ROOT_PATH . '/includes/db.php';

echo "👨‍💼 Running Admin Workflow Test (Staff -> Payroll)...\n";

$startTime = microtime(true);

try {
    // 1. Create Staff Member
    // Need User first
    $email = 'staff_test_' . uniqid() . '@example.com';
    $res = db_query("INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id",
        [$email, password_hash('test', PASSWORD_DEFAULT), 'nurse']);
    $userId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 1: User Created (ID: $userId)\n";
    
    $res = db_query("INSERT INTO staff (user_id, first_name, last_name, role, department) VALUES ($1, $2, $3, $4, $5) RETURNING id",
        [$userId, 'Nurse', 'Joy', 'nurse', 'Emergency']);
    $staffId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 2: Staff Profile Created (ID: $staffId)\n";
    
    // 2. Process Payroll
    $month = date('Y-m-01');
    $res = db_query("INSERT INTO payroll (staff_id, salary_month, basic_salary, status) VALUES ($1, $2, $3, $4) RETURNING id",
        [$staffId, $month, 5000.00, 'unpaid']);
    $payId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 3: Payroll Generated (ID: $payId)\n";
    
    // 3. Mark Paid
    db_query("UPDATE payroll SET status = 'paid', payment_date = NOW() WHERE id = $1", [$payId]);
    
    // Verify
    $check = db_select("SELECT status FROM payroll WHERE id = '$payId'");
    if ($check[0]['status'] !== 'paid') {
        throw new Exception("Payroll update failed verification.");
    }
    echo "✅ Step 4: Payroll Marked Paid & Verified\n";
    
    echo "\n🎉 Admin Workflow Test PASSED in " . round(microtime(true) - $startTime, 2) . "s\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
?>
