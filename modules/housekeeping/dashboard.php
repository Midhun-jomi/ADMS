<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Nurse (to report), Staff
$allowed_roles = ['admin', 'nurse']; // Simplified
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Housekeeping";
require_once '../../includes/header.php';

$success_msg = '';

// Create Task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    try {
        db_insert('housekeeping_tasks', [
            'location_name' => $_POST['location'],
            'task_type' => $_POST['task_type'],
            'status' => 'pending'
        ]);
        $success_msg = "Task added!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Complete Task
if (isset($_GET['complete'])) {
    db_update('housekeeping_tasks', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], ['id' => $_GET['complete']]);
    $success_msg = "Task marked completed.";
}

$tasks = db_select("SELECT * FROM housekeeping_tasks WHERE status != 'completed' ORDER BY created_at ASC");
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-broom"></i> Housekeeping Management</h1>
        <button class="btn-primary" onclick="showModal('taskModal')">
            <i class="fas fa-plus"></i> Request Cleaning
        </button>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Pending Tasks</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Task</th>
                        <th>Reported</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['location_name']); ?></td>
                        <td><?php echo htmlspecialchars($t['task_type']); ?></td>
                        <td><?php echo date('H:i', strtotime($t['created_at'])); ?></td>
                        <td>
                            <a href="?complete=<?php echo $t['id']; ?>" class="btn-small btn-success" onclick="return confirm('Mark Clean?')">
                                <i class="fas fa-check"></i> Mark Done
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($tasks)): ?><tr><td colspan="4">All clean! No pending tasks.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="taskModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('taskModal').style.display='none'">&times;</span>
        <h3>Request Cleaning</h3>
        <form method="POST">
            <div class="form-group">
                <label>Location (Room/Area)</label>
                <input type="text" name="location" required placeholder="e.g. Ward A, Room 102">
            </div>
            <div class="form-group">
                <label>Task Type</label>
                <select name="task_type">
                    <option value="cleaning">General Cleaning</option>
                    <option value="sanitization">Deep Sanitization</option>
                    <option value="spill">Spill Cleanup</option>
                    <option value="restroom">Restroom Maintenance</option>
                </select>
            </div>
            <button type="submit" name="add_task" class="btn-primary">Submit Request</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
