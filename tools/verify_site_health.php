<?php
// tools/verify_site_health.php
$files = [
    'dashboards/admin_dashboard.php',
    'dashboards/doctor_dashboard.php',
    'dashboards/patient_dashboard.php',
    'modules/ehr/appointments.php',
    'modules/lab/results.php',
    'modules/ehr/request_certificate.php',
    'modules/ehr/patients.php',
    'modules/admin/staff_management.php',
    'modules/admin/departments.php',
    'modules/patient_management/manage_beds.php',
    'modules/patient_management/nursing_station.php',
    'modules/hr/dashboard.php',
    'modules/emergency/dashboard.php',
    'modules/inventory/assets.php',
    'modules/blood_bank/dashboard.php',
    'modules/ot/schedule.php',
    'modules/telemedicine/dashboard.php',
    'modules/dietary/planner.php',
    'modules/housekeeping/dashboard.php',
    'modules/billing/invoices.php',
    'modules/pharmacy/inventory.php',
    'modules/ai/diagnosis_assist.php',
    'modules/queue/display.php',
    'modules/feedback/survey.php',
    'help_center.php'
];

$root = dirname(__DIR__); // /Users/mj/Downloads/Project_Main/Google

echo "Starting Site Health Check...\n";
echo "Root: $root\n";
echo "--------------------------------------------------\n";

$errors = 0;

foreach ($files as $f) {
    $path = $root . '/' . $f;
    
    // 1. Existence
    if (!file_exists($path)) {
        echo "[MISSING] $f\n";
        $errors++;
        // Attempt to create a placeholder if missing? 
        // No, let's just report first.
        continue;
    }
    
    // 2. Empty Check
    if (filesize($path) < 50) { // arbitrary small size for "empty"
        echo "[EMPTY]   $f (Size: " . filesize($path) . " bytes)\n";
        $errors++;
        continue;
    }
    
    // 3. Syntax Check
    $output = [];
    $return_var = 0;
    exec("php -l " . escapeshellarg($path), $output, $return_var);
    if ($return_var !== 0) {
        echo "[SYNTAX]  $f\n";
        $errors++;
    } else {
        echo "[OK]      $f\n";
    }
}

echo "--------------------------------------------------\n";
if ($errors === 0) {
    echo "✅ All checked pages exist and have valid syntax.\n";
} else {
    echo "❌ Found $errors issues.\n";
}
?>
