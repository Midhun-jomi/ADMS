<?php
// modules/ehr/request_certificate.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);

$page_title = "Request Medical Certificate";
include '../../includes/header.php';

$user_id = get_user_id();
$patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
$patient_id = $patient['id'];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type = $_POST['type'];
    $reason = $_POST['reason'];

    if (empty($type) || empty($reason)) {
        $error = "Please fill in all fields.";
    } else {
        try {
            db_insert('certificate_requests', [
                'patient_id' => $patient_id,
                'type' => $type,
                'reason' => $reason,
                'status' => 'pending'
            ]);
            $success = "Request submitted successfully.";
        } catch (Exception $e) {
            $error = "Error submitting request: " . $e->getMessage();
        }
    }
}

// Handle Status Updates (Admin Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status']) && ($role === 'admin' || $role === 'doctor')) {
    $req_id = $_POST['req_id'];
    $new_status = $_POST['status']; // 'approved' or 'rejected'
    
    db_update('certificate_requests', ['status' => $new_status], ['id' => $req_id]);
    
    // Notify Patient
    if ($new_status === 'approved') {
        $req = db_select_one("SELECT * FROM certificate_requests WHERE id = $1", [$req_id]);
        $pat = db_select_one("SELECT user_id FROM patients WHERE id = $1", [$req['patient_id']]);
        if ($pat) {
            db_insert('notifications', [
                'user_id' => $pat['user_id'],
                'title' => 'Certificate Approved',
                'message' => "Your request for a {$req['type']} has been approved. You can now collect it from the reception.",
                'is_read' => 0
            ]);
        }
    }
    $success = "Request updated to " . ucfirst($new_status);
}

// Fetch requests
$requests = [];
if ($role === 'patient') {
    $requests = db_select("SELECT * FROM certificate_requests WHERE patient_id = $1 ORDER BY created_at DESC", [$patient_id]);
} else {
    // Admin/Doctor sees all
    $requests = db_select("SELECT c.*, p.first_name, p.last_name FROM certificate_requests c JOIN patients p ON c.patient_id = p.id ORDER BY c.created_at DESC");
}
?>

<div style="max-width: 1000px; margin: 0 auto; padding: 20px;">
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Left Column: Request Form -->
        <div class="card" style="border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
            <div class="card-header" style="background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px;">
                <h5 style="margin: 0; font-weight: 600; display: flex; align-items: center;">
                    <i class="fas fa-file-signature" style="margin-right: 10px; font-size: 1.2em;"></i> New Request
                </h5>
                <p style="margin: 5px 0 0 0; font-size: 0.85em; opacity: 0.9;">Submit a formal request for official documents.</p>
            </div>
            <div class="card-body" style="padding: 25px;">
                <?php if ($success): ?>
                    <div class="alert alert-success" style="border-left: 4px solid #28a745;">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger" style="border-left: 4px solid #dc3545;">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="type" style="font-weight: 500; color: #4b5563; margin-bottom: 8px; display: block;">Certificate Type</label>
                        <div style="position: relative;">
                            <i class="fas fa-notes-medical" style="position: absolute; left: 12px; top: 14px; color: #9ca3af;"></i>
                            <select name="type" id="type" class="form-control" required style="padding-left: 35px; height: 45px; border-radius: 8px;">
                                <option value="">-- Select Document --</option>
                                <option value="Medical Certificate">Medical Certificate</option>
                                <option value="Birth Certificate">Birth Certificate</option>
                                <option value="Death Certificate">Death Certificate</option>
                                <option value="Fitness Certificate">Fitness Certificate</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label for="reason" style="font-weight: 500; color: #4b5563; margin-bottom: 8px; display: block;">Purpose / Details</label>
                        <textarea name="reason" id="reason" class="form-control" rows="5" placeholder="Please describe why you need this document (e.g., insurance claim, leave application)..." required style="border-radius: 8px; padding: 12px;"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-weight: 600; border-radius: 8px; background: #4f46e5; border: none;">
                        <i class="fas fa-paper-plane" style="margin-right: 5px;"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>

        <!-- Right Column: History -->
        <div class="card" style="border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
            <div class="card-header" style="background: #fff; border-bottom: 1px solid #f3f4f6; padding: 20px;">
                <h5 style="margin: 0; color: #1f2937; font-weight: 600; display: flex; align-items: center;">
                    <i class="fas fa-history" style="color: #6b7280; margin-right: 10px;"></i> Recent Requests
                </h5>
            </div>
            <div class="card-body" style="padding: 0;">
                <div style="max-height: 500px; overflow-y: auto;">
                    <?php if (empty($requests)): ?>
                        <div style="padding: 40px; text-align: center; color: #9ca3af;">
                            <i class="far fa-folder-open fa-3x" style="margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>No request history found.</p>
                        </div>
                    <?php else: ?>
                        <table class="table table-hover" style="margin: 0;">
                            <thead style="background: #f9fafb;">
                                <tr>
                                    <th style="padding: 15px; font-weight: 600; color: #6b7280; font-size: 0.85em; text-transform: uppercase;">Date</th>
                                    <th style="padding: 15px; font-weight: 600; color: #6b7280; font-size: 0.85em; text-transform: uppercase;">Type</th>
                                    <th style="padding: 15px; font-weight: 600; color: #6b7280; font-size: 0.85em; text-transform: uppercase; text-align: right;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $r): ?>
                                    <tr>
                                        <td style="padding: 15px; border-bottom: 1px solid #f3f4f6;">
                                            <div style="font-weight: 500; color: #111827;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
                                            <div style="font-size: 0.8em; color: #9ca3af;"><?php echo date('h:i A', strtotime($r['created_at'])); ?></div>
                                        </td>
                                        <td style="padding: 15px; border-bottom: 1px solid #f3f4f6;">
                                            <div style="color: #374151; font-weight: 500;"><?php echo htmlspecialchars($r['type']); ?></div>
                                            <div style="font-size: 0.85em; color: #6b7280; width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($r['reason']); ?>">
                                                <?php echo htmlspecialchars($r['reason']); ?>
                                            </div>
                                        </td>
                                        <td style="padding: 15px; border-bottom: 1px solid #f3f4f6; text-align: right;">
                                            <?php 
                                            $status_colors = [
                                                'pending' => ['bg' => '#fff7ed', 'text' => '#c2410c', 'icon' => 'fa-clock'],
                                                'approved' => ['bg' => '#f0fdf4', 'text' => '#15803d', 'icon' => 'fa-check'],
                                                'rejected' => ['bg' => '#fef2f2', 'text' => '#b91c1c', 'icon' => 'fa-times']
                                            ];
                                            $curr = $status_colors[$r['status']] ?? $status_colors['pending'];
                                            ?>
                                            <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 99px; font-size: 0.75em; font-weight: 600; background: <?php echo $curr['bg']; ?>; color: <?php echo $curr['text']; ?>;">
                                                <i class="fas <?php echo $curr['icon']; ?>" style="margin-right: 4px;"></i> <?php echo ucfirst($r['status']); ?>
                                            </span>
                                        </td>
                                        <?php if ($role !== 'patient' && $r['status'] === 'pending'): ?>
                                            <td style="padding: 15px; border-bottom: 1px solid #f3f4f6; text-align: right;">
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="req_id" value="<?php echo $r['id']; ?>">
                                                    <input type="hidden" name="update_status" value="1">
                                                    <button type="submit" name="status" value="approved" class="btn btn-sm" style="background: #16a34a; color: white; padding: 4px 8px; border: none; border-radius: 4px; font-size: 0.8em;">Approve</button>
                                                    <button type="submit" name="status" value="rejected" class="btn btn-sm" style="background: #dc2626; color: white; padding: 4px 8px; border: none; border-radius: 4px; font-size: 0.8em;">Reject</button>
                                                </form>
                                            </td>
                                        <?php elseif ($role !== 'patient'): ?>
                                            <td style="padding: 15px; border-bottom: 1px solid #f3f4f6; text-align: right;">-</td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
