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
                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                    </td>
                    <td style="padding: 10px;">
                        <?php 
                            if ($log['email']) {
                                echo htmlspecialchars($log['email']) . " <br><small style='color:#888'>(" . $log['role'] . ")</small>";
                            } else {
                                // Try to get email from details if failed login
                                $d = json_decode($log['details'], true);
                                if (is_array($d) && isset($d['attempted_email'])) {
                                    echo htmlspecialchars($d['attempted_email']) . " <br><small style='color:#e53e3e'>(Failed Attempt)</small>";
                                } else {
                                    echo "<em>System / Guest</em>";
                                }
                            }
                        ?>
                    </td>
                    <td style="padding: 10px; font-weight: bold;">
                        <?php 
                            $act = htmlspecialchars($log['action']);
                            if (strpos($act, 'FAILED') !== false) echo "<span style='color:#e53e3e'>$act</span>";
                            elseif (strpos($act, 'SUCCESS') !== false) echo "<span style='color:#38a169'>$act</span>";
                            else echo $act;
                        ?>
                    </td>
                    <td style="padding: 10px; color: #444;">
                        <?php 
                            $json = json_decode($log['details'], true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                                // Parse Device Info
                                $info_parts = [];
                                if (isset($json['ip'])) $info_parts[] = "<b>IP:</b> " . htmlspecialchars($json['ip']);
                                if (isset($json['browser'])) {
                                    // Parse Browser
                                    $agent = $json['browser'];
                                    $b = "Unknown";
                                    if (strpos($agent, 'Chrome') !== false) $b = "Chrome";
                                    elseif (strpos($agent, 'Firefox') !== false) $b = "Firefox";
                                    elseif (strpos($agent, 'Safari') !== false) $b = "Safari";
                                    elseif (strpos($agent, 'Edge') !== false) $b = "Edge";
                                    $info_parts[] = "<b>Device:</b> " . $b;
                                }
                                
                                if (!empty($info_parts)) {
                                    echo implode(" | ", $info_parts);
                                    if (isset($json['reason'])) echo "<br><span class='text-danger'>" . htmlspecialchars($json['reason']) . "</span>";
                                } else {
                                    // Fallback
                                    echo htmlspecialchars($json['message'] ?? $json['reason'] ?? $log['details']);
                                }
                            } else {
                                echo htmlspecialchars($log['details'] ?? '-'); 
                            }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
