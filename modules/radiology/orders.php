<?php
// modules/radiology/orders.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Radiology Orders";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle New Order (Doctor Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    $patient_id = $_POST['patient_id'];
    $report_type = $_POST['report_type'];
    
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    
    if ($doctor) {
        $data = [
            'patient_id' => $patient_id,
            'doctor_id' => $doctor['id'],
            'report_type' => $report_type,
            'status' => 'ordered'
        ];
        
        try {
            db_insert('radiology_reports', $data);
            $success = "Radiology scan ordered successfully.";
        } catch (Exception $e) {
            $error = "Failed to order scan: " . $e->getMessage();
        }
    }
}

// Fetch Orders
$orders = [];
if ($role === 'doctor') {
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    if ($doctor) {
        $sql = "SELECT r.*, p.first_name, p.last_name 
                FROM radiology_reports r 
                JOIN patients p ON r.patient_id = p.id 
                WHERE r.doctor_id = $1 
                ORDER BY r.created_at DESC";
        $orders = db_select($sql, [$doctor['id']]);
    }
} elseif ($role === 'radiologist' || $role === 'admin') {
    $sql = "SELECT r.*, p.first_name, p.last_name, s.first_name as doc_first, s.last_name as doc_last 
            FROM radiology_reports r 
            JOIN patients p ON r.patient_id = p.id 
            JOIN staff s ON r.doctor_id = s.id 
            ORDER BY r.created_at DESC";
    $orders = db_select($sql);
}

$pre_patient_id = $_GET['patient_id'] ?? '';
?>

<div class="card">
    <div class="card-header">Radiology Orders</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($role === 'doctor'): ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <h5>Order New Scan</h5>
            <form method="POST" action="" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label>Patient ID</label>
                    <input type="text" name="patient_id" class="form-control" value="<?php echo htmlspecialchars($pre_patient_id); ?>" required placeholder="UUID">
                </div>
                <div style="flex: 2;">
                    <label>Scan Type</label>
                    <select name="report_type" class="form-control">
                        <option value="X-Ray (Chest)">X-Ray (Chest)</option>
                        <option value="X-Ray (Limb)">X-Ray (Limb)</option>
                        <option value="MRI (Brain)">MRI (Brain)</option>
                        <option value="CT Scan (Abdomen)">CT Scan (Abdomen)</option>
                        <option value="Ultrasound">Ultrasound</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Order Scan</button>
            </form>
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Date</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Scan Type</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Patient</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Status</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($order['report_type']); ?></td>
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
                        <?php if ($role === 'radiologist' || $role === 'admin'): ?>
                            <a href="upload.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white; padding: 2px 8px; font-size: 12px;">Upload/Process</a>
                        <?php else: ?>
                            <?php if ($order['status'] === 'completed'): ?>
                                <a href="<?php echo htmlspecialchars($order['image_url']); ?>" target="_blank" class="btn btn-sm" style="background: #28a745; color: white; padding: 2px 8px; font-size: 12px;">View Scan</a>
                            <?php else: ?>
                                <span style="color: #6c757d; font-size: 0.9em;">Pending</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
