<?php
// modules/lab/results.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$page_title = "Lab Results";
include '../../includes/header.php';

$user_id = get_user_id(); // Ensure user_id is fetched
$test_id = $_GET['id'] ?? null;

// If no ID is provided, show list of lab tests for the user
if (!$test_id) {
    echo '<div class="card"><div class="card-header">My Lab Results</div>';
    
    $tests = [];
    if ($role === 'patient') {
        $pat = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
        if ($pat) {
            $tests = db_select("SELECT * FROM laboratory_tests WHERE patient_id = $1 ORDER BY created_at DESC", [$pat['id']]);
        }
    } elseif ($role === 'lab_tech' || $role === 'admin' || $role === 'doctor') {
         // Show recent 20 for staff
         $tests = db_select("SELECT l.*, p.first_name, p.last_name FROM laboratory_tests l JOIN patients p ON l.patient_id = p.id ORDER BY l.created_at DESC LIMIT 20");
    }

    if (empty($tests)) {
        echo '<div style="padding: 20px;">No lab tests found.</div>';
    } else {
        echo '<table class="table">';
        echo '<thead><tr><th>Date</th><th>Test Type</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($tests as $t) {
            $p_name = isset($t['first_name']) ? " (" . htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) . ")" : "";
            echo '<tr>';
            echo '<td>' . date('M d, Y', strtotime($t['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($t['test_type']) . $p_name . '</td>';
            echo '<td><span class="badge badge-' . ($t['status'] === 'completed' ? 'success' : 'warning') . '">' . ucfirst($t['status']) . '</span></td>';
            echo '<td><a href="?id=' . $t['id'] . '" class="btn btn-sm btn-primary">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    include '../../includes/footer.php';
    exit();
}

// Fetch test details
$test = db_select_one("SELECT l.*, p.first_name, p.last_name 
                       FROM laboratory_tests l 
                       JOIN patients p ON l.patient_id = p.id 
                       WHERE l.id = $1", [$test_id]);

if (!$test) {
    echo "<div class='alert alert-danger'>Test not found.</div>";
    include '../../includes/footer.php';
    exit();
}

// Handle Result Upload (Lab Tech/Admin)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($role === 'lab_tech' || $role === 'admin')) {
    $result_text = $_POST['result_text'];
    
    // Create a simple JSON structure for the result
    $result_json = json_encode(['summary' => $result_text, 'date' => date('Y-m-d')]);
    
    db_update('laboratory_tests', 
              ['result_data' => $result_json, 'status' => 'completed'], 
              ['id' => $test_id]);
              
    echo "<div class='alert alert-success'>Results uploaded successfully.</div>";
    // Refresh
    $test = db_select_one("SELECT l.*, p.first_name, p.last_name FROM laboratory_tests l JOIN patients p ON l.patient_id = p.id WHERE l.id = $1", [$test_id]);
}

$result_data = json_decode($test['result_data'] ?? '{}', true);
?>

<div class="card">
    <div class="card-header">
        Test Details: <?php echo htmlspecialchars($test['test_type']); ?>
    </div>
    
    <div class="form-row" style="margin-bottom: 20px;">
        <p><strong>Patient:</strong> <?php echo htmlspecialchars($test['first_name'] . ' ' . $test['last_name']); ?></p>
        <p><strong>Status:</strong> <?php echo ucfirst($test['status']); ?></p>
        <p><strong>Ordered Date:</strong> <?php echo date('M d, Y', strtotime($test['created_at'])); ?></p>
    </div>

    <?php 
    $show_results = true;
    if ($role === 'patient') {
        // Check if paid
        $show_results = false;
        
        // 1. Check for specific bill with Ref ID
        $ref_desc = "%(Ref: " . $test_id . ")";
        $bill = db_select_one("SELECT * FROM billing WHERE patient_id = $1 AND service_description LIKE $2", [$test['patient_id'], $ref_desc]);
        
        if ($bill) {
            if ($bill['status'] === 'paid') {
                $show_results = true;
            }
        } else {
            // 2. Fallback: Check for generic bill of same type that is paid (Legacy support or if Ref missing)
            // We assume if ANY bill for this test type is paid, we might allow (or strictly require unique).
            // Let's be strict but reasonable: Find if there's a paid bill for this test type created around the same time?
            // For now, let's just check generic description + paid.
            $generic_desc = "Lab Test: " . $test['test_type'];
             // Find latest paid bill of this type
            $bill = db_select_one("SELECT * FROM billing WHERE patient_id = $1 AND service_description = $2 AND status = 'paid'", [$test['patient_id'], $generic_desc]);
            if ($bill) {
                $show_results = true;
            }
        }
    }
    ?>

    <?php if ($test['status'] === 'completed'): ?>
        <div class="form-group">
            <label><strong>Results:</strong></label>
            <?php if ($show_results): ?>
                <div style="background: #e9ecef; padding: 15px; border-radius: 5px; border: 1px solid #ced4da;">
                    <?php echo nl2br(htmlspecialchars($result_data['summary'] ?? 'No data')); ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-lock"></i> <strong>Payment Required</strong><br>
                    You must pay the lab fee to view the digital copy of these results.<br>
                    <a href="/modules/billing/invoices.php" class="btn btn-sm btn-primary" style="margin-top: 10px;">Go to Payments</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (($role === 'lab_tech' || $role === 'admin') && $test['status'] !== 'completed'): ?>
        <hr>
        <form method="POST" action="">
            <div class="form-group">
                <label for="result_text">Enter Test Results</label>
                <textarea id="result_text" name="result_text" class="form-control" rows="5" required placeholder="Enter detailed findings here..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Results</button>
        </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
