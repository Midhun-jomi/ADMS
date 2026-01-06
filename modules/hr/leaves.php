<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin or any Staff (if staff login existed separate from users, but schema links staff->users)
// Let's assume current user is admin for management, but staff can view if we extended logic.
// For now, focusing on Admin Management of leaves.

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "Leave Management";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Leave Request (Admin adding on behalf of staff, or approval)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_leave'])) {
    try {
        $data = [
            'staff_id' => $_POST['staff_id'],
            'leave_type' => $_POST['leave_type'],
            'start_date' => $_POST['start_date'],
            'end_date' => $_POST['end_date'],
            'reason' => $_POST['reason'],
            'status' => 'pending' // Admin can directly approve, but let's stick to flow
        ];
        // If admin adds, maybe auto-approve? Let's auto-approve for admin entry.
        $data['status'] = 'approved';
        $data['approved_by'] = $_SESSION['user_id'];
        
        db_insert('leaves', $data);
        $success_msg = "Leave recorded successfully!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Handle Approval/Rejection
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['action'] == 'approve' ? 'approved' : 'rejected';
    try {
        db_update('leaves', 
            ['status' => $status, 'approved_by' => $_SESSION['user_id']], 
            ['id' => $id]
        );
        $success_msg = "Leave request $status!";
    } catch (Exception $e) {
        $error_msg = "Error: " . $e->getMessage();
    }
}

$staff_list = db_select("SELECT id, first_name, last_name FROM staff");
$leaves = db_select("
    SELECT l.*, s.first_name, s.last_name 
    FROM leaves l 
    JOIN staff s ON l.staff_id = s.id 
    ORDER BY l.start_date DESC
");

?>

<div class="main-content">
    <div class="page-header">
        <h1>Leave Management</h1>
        <button class="btn-primary" onclick="document.getElementById('leaveModal').style.display='block'">
            <i class="fas fa-plus"></i> Record Leave
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Leave Requests</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Staff Name</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaves as $leave): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                        <td>
                            <?php echo date('M d', strtotime($leave['start_date'])) . ' - ' . date('M d', strtotime($leave['end_date'])); ?>
                            <small class="text-muted">(
                                <?php 
                                $diff = strtotime($leave['end_date']) - strtotime($leave['start_date']);
                                echo round($diff / (60 * 60 * 24)) + 1; 
                                ?> 
                            days)</small>
                        </td>
                        <td><?php echo htmlspecialchars($leave['reason']); ?></td>
                        <td>
                            <span class="badge badge-<?php 
                                echo $leave['status'] == 'approved' ? 'success' : ($leave['status'] == 'rejected' ? 'danger' : 'warning'); 
                            ?>">
                                <?php echo ucfirst($leave['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($leave['status'] === 'pending'): ?>
                                <a href="?action=approve&id=<?php echo $leave['id']; ?>" class="btn-icon text-success"  onclick="return confirm('Approve?')"><i class="fas fa-check"></i></a>
                                <a href="?action=reject&id=<?php echo $leave['id']; ?>" class="btn-icon text-danger" onclick="return confirm('Reject?')"><i class="fas fa-times"></i></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Leave Modal -->
<div id="leaveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Staff Leave</h3>
            <span class="close" onclick="document.getElementById('leaveModal').style.display='none'">&times;</span>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Select Staff</label>
                <select name="staff_id" required>
                    <?php foreach ($staff_list as $staff): ?>
                        <option value="<?php echo $staff['id']; ?>">
                            <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Leave Type</label>
                <select name="leave_type" required>
                    <option value="Annual">Annual</option>
                    <option value="Sick">Sick</option>
                    <option value="Casual">Casual</option>
                    <option value="Unpaid">Unpaid</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" required>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" required>
                </div>
            </div>

            <div class="form-group">
                <label>Reason</label>
                <textarea name="reason" rows="2" required></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_leave" class="btn-primary">Submit Record</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
