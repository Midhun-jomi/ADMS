<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /index.php");
    exit();
}

$page_title = "Notification Center";
require_once '../../includes/header.php';

// Simulate sending a test notification
if (isset($_POST['send_test'])) {
    $msg = "Appointment Reminder: Your visit is tomorrow at 10 AM.";
    db_insert('notification_logs', [
        'user_id' => $_SESSION['user_id'],
        'type' => $_POST['type'],
        'message' => $msg,
        'status' => 'sent'
    ]);
    $success = "Test notification logged.";
}

$logs = db_select("SELECT * FROM notification_logs ORDER BY sent_at DESC LIMIT 50");
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-bell"></i> Notification Logs</h1>
        <form method="POST" style="display:inline;">
            <select name="type" class="btn-secondary">
                <option value="SMS">SMS</option>
                <option value="Email">Email</option>
                <option value="WhatsApp">WhatsApp</option>
            </select>
            <button type="submit" name="send_test" class="btn-primary">Trigger Test Alert</button>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo date('M d, H:i:s', strtotime($log['sent_at'])); ?></td>
                        <td><span class="badge badge-info"><?php echo $log['type']; ?></span></td>
                        <td><?php echo htmlspecialchars($log['message']); ?></td>
                        <td><span class="text-success"><i class="fas fa-check-circle"></i> Sent</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
