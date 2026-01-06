<?php
// modules/lab/orders.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Laboratory Orders";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle New Order (Doctor Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    $patient_id = $_POST['patient_id'];
    $test_type = $_POST['test_type'];
    
    // Get doctor ID
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    
    if ($doctor) {
        $status = 'ordered';
        // Insert Test with RETURNING id
        $sql = "INSERT INTO laboratory_tests (patient_id, doctor_id, test_type, status) VALUES ($1, $2, $3, $4) RETURNING id";
        try {
            $res = db_query($sql, [$patient_id, $doctor['id'], $test_type, $status]);
            $row = pg_fetch_assoc($res);
            $test_id = $row['id'];
            
            // Auto-bill based on test type
            $prices = [
                'Complete Blood Count (CBC)' => 20.00,
                'Lipid Profile' => 30.00,
                'Liver Function Test' => 40.00,
                'Blood Sugar (Fasting)' => 10.00,
                'Urinalysis' => 15.00
            ];
            
            $price = $prices[$test_type] ?? 0.00;
            
            if ($price > 0) {
                $bill_data = [
                    'patient_id' => $patient_id,
                    'total_amount' => $price,
                    'status' => 'pending',
                    'service_description' => "Lab Test: $test_type (Ref: $test_id)"
                ];
                db_insert('billing', $bill_data);
            }
            
            $success = "Lab test ordered and billed ($$price) successfully.";
        } catch (Exception $e) {
            $error = "Failed to order test: " . $e->getMessage();
        }
    }
}

// Fetch Orders
$orders = [];
if ($role === 'doctor') {
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    if ($doctor) {
        $sql = "SELECT l.*, p.first_name, p.last_name 
                FROM laboratory_tests l 
                JOIN patients p ON l.patient_id = p.id 
                WHERE l.doctor_id = $1 
                ORDER BY l.created_at DESC";
        $orders = db_select($sql, [$doctor['id']]);
    }
} elseif ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($patient) {
        $sql = "SELECT l.*, s.first_name as doc_first, s.last_name as doc_last 
                FROM laboratory_tests l 
                JOIN staff s ON l.doctor_id = s.id 
                WHERE l.patient_id = $1 
                ORDER BY l.created_at DESC";
        $orders = db_select($sql, [$patient['id']]);
    }
} elseif ($role === 'lab_tech' || $role === 'admin') {
    $sql = "SELECT l.*, p.first_name, p.last_name, s.first_name as doc_first, s.last_name as doc_last 
            FROM laboratory_tests l 
            JOIN patients p ON l.patient_id = p.id 
            JOIN staff s ON l.doctor_id = s.id 
            ORDER BY l.created_at DESC";
    $orders = db_select($sql);
}

// Pre-fill patient if passed in URL
$pre_patient_id = $_GET['patient_id'] ?? '';
?>

<div class="card">
    <div class="card-header">Lab Orders</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($role === 'doctor'): ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <h5>Order New Test</h5>
            <form method="POST" action="" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label>Patient ID</label>
                    <input type="text" name="patient_id" class="form-control" value="<?php echo htmlspecialchars($pre_patient_id); ?>" required placeholder="UUID">
                </div>
                <div style="flex: 2;">
                    <label>Test Type</label>
                    <select name="test_type" class="form-control">
                        <option value="Complete Blood Count (CBC)">Complete Blood Count (CBC)</option>
                        <option value="Lipid Profile">Lipid Profile</option>
                        <option value="Liver Function Test">Liver Function Test</option>
                        <option value="Blood Sugar (Fasting)">Blood Sugar (Fasting)</option>
                        <option value="Urinalysis">Urinalysis</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Order Test</button>
            </form>
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Date</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Test Type</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Patient</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Status</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($order['test_type']); ?></td>
                    <td style="padding: 10px;">
                        <?php echo htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')); ?>
                    </td>
                    <td style="padding: 10px;">
                        <span style="padding: 5px 10px; border-radius: 15px; font-size: 0.85em; 
                            background-color: <?php echo ($order['status'] === 'completed') ? '#d4edda' : '#fff3cd'; ?>;
                            color: <?php echo ($order['status'] === 'completed') ? '#155724' : '#856404'; ?>;">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </td>
                    <td style="padding: 10px;">
                        <?php if ($order['status'] === 'completed'): ?>
                            <a href="results.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #28a745; color: white; padding: 2px 8px; font-size: 12px;">View Results</a>
                        <?php elseif ($role === 'lab_tech' || $role === 'admin'): ?>
                            <a href="results.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white; padding: 2px 8px; font-size: 12px;">Process</a>
                        <?php else: ?>
                            <span style="color: #6c757d; font-size: 0.9em;">Pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
