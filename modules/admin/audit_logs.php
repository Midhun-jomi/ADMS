<?php
// modules/admin/audit_logs.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Audit Logs";
include '../../includes/header.php';

// Fetch Logs
// Join with users to get email/role if available
$sql = "SELECT a.*, u.email, u.role 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC 
        LIMIT 100";
try {
    $logs = db_select($sql);
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error loading logs: " . $e->getMessage() . "</div>";
    $logs = [];
}
?>

<div class="card">
    <div class="card-header">System Audit Logs (Last 100)</div>
    
    <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Timestamp</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">User</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Action</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px; color: #666;">
                        <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                    </td>
                    <td style="padding: 10px;">
                        <?php 
                            if ($log['email']) {
                                echo htmlspecialchars($log['email']) . " <br><small style='color:#888'>(" . $log['role'] . ")</small>";
                            } else {
                                echo "<em>System / Guest</em>";
                            }
                        ?>
                    </td>
                    <td style="padding: 10px; font-weight: bold;">
                        <?php echo htmlspecialchars($log['action']); ?>
                    </td>
                    <td style="padding: 10px; color: #444;">
                        <?php echo htmlspecialchars($log['details'] ?? '-'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
