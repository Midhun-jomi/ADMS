<?php
// System Integrity Check
define('ROOT_PATH', __DIR__ . '/..');
$errors = [];
$warnings = [];

echo "Running System Integrity Check...\n";

// 1. Check Database Connection
try {
    require_once ROOT_PATH . '/includes/db.php';
    // Test simple query
    $res = db_query("SELECT 1");
    if ($res) echo "✅ Database Connection: OK\n";
} catch (Exception $e) {
    $errors[] = "❌ Database Error: " . $e->getMessage();
}

// 2. Check Critical Files
$critical_files = [
    'includes/db.php',
    'includes/auth_session.php',
    'includes/header.php',
    'includes/sidebar.php',
    'dashboards/admin_dashboard.php',
    'modules/blood_bank/dashboard.php',
    'modules/ot/schedule.php'
];

foreach ($critical_files as $file) {
    if (file_exists(ROOT_PATH . '/' . $file)) {
        echo "✅ Found: $file\n";
    } else {
        $errors[] = "❌ Missing File: $file";
    }
}

// 3. Check Table Existence (Sample of new tables)
$tables = ['users', 'patients', 'staff', 'blood_inventory', 'surgeries', 'ambulance'];
foreach ($tables as $table) {
    try {
        $check = db_query("SELECT 1 FROM $table LIMIT 1");
        echo "✅ Table Exists: $table\n";
    } catch (Exception $e) {
        $errors[] = "❌ Missing Table: $table";
    }
}

// Summary
echo "\n--- Summary ---\n";
if (empty($errors)) {
    echo "✅ System Integrity Validated. All checks passed.\n";
} else {
    echo "❌ Integrity Issues Found:\n";
    foreach ($errors as $err) echo "$err\n";
}
?>
