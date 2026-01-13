<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Patient, Admin
$allowed_roles = ['patient', 'admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Patient Feedback";
require_once '../../includes/header.php';

$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    try {
        $patient_id = null;
        if ($_SESSION['role'] === 'patient') {
            $user_id = $_SESSION['user_id'];
            $patient = db_select("SELECT id FROM patients WHERE user_id = '$user_id'");
            if ($patient) $patient_id = $patient[0]['id'];
        }

        // Handle Tags
        $tags = isset($_POST['tags']) ? implode(', ', $_POST['tags']) : '';
        $comments = $_POST['comments'];
        $final_comments = $tags ? "[$tags] " . $comments : $comments;

        db_insert('patient_feedback', [
            'patient_id' => $patient_id,
            'rating' => $_POST['rating'],
            'comments' => $final_comments
        ]);
        $success_msg = "Thank you for your feedback!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Admin View: See all feedback
$feedbacks = [];
if ($_SESSION['role'] === 'admin') {
    $feedbacks = db_select("
        SELECT f.*, p.first_name, p.last_name 
        FROM patient_feedback f 
        SELECT f.*, p.first_name, p.last_name
        FROM patient_feedback f
        LEFT JOIN patients p ON f.patient_id = p.id
        ORDER BY f.created_at DESC
    ");
}
?>

<style>
    /* Modern Feedback UI */
    .feedback-container {
        width: 95%;
        max-width: 600px;
        margin: 40px auto;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        overflow: hidden;
        padding: 40px;
        text-align: center;
    }
    .rating-group {
        display: inline-flex;
        flex-direction: row-reverse;
        justify-content: center;
        margin-bottom: 20px;
    }
    .rating-group input { display: none; }
    .rating-group label {
        font-size: 40px;
        color: #ddd;
        cursor: pointer;
        transition: color 0.2s, transform 0.2s;
        padding: 0 5px;
    }
    .rating-group input:checked ~ label,
    .rating-group label:hover,
    .rating-group label:hover ~ label {
        color: #ffc107;
        transform: scale(1.1);
    }
    .tags-input {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: center;
        margin-bottom: 20px;
    }
    .tag-checkbox { display: none; }
    .tag-label {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        padding: 8px 16px;
        border-radius: 50px;
        cursor: pointer;
        font-size: 0.9em;
        transition: all 0.2s;
        color: #6c757d;
    }
    .tag-checkbox:checked + .tag-label {
        background: #e3f2fd;
        border-color: #90caf9;
        color: #1976d2;
        font-weight: 600;
    }
    textarea.modern-input {
        width: 100%;
        border: 2px solid #f0f0f0;
        border-radius: 12px;
        padding: 15px;
        font-size: 1rem;
        transition: border-color 0.2s;
        resize: vertical;
        background: #fafafa;
    }
    textarea.modern-input:focus {
        outline: none;
        border-color: #667eea;
        background: #fff;
    }
    .submit-btn {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 50px;
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        margin-top: 20px;
        width: 100%;
    }
    .submit-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(118, 75, 162, 0.3);
    }
</style>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-comment-medical"></i> Patient Feedback</h1>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success" style="max-width: 600px; margin: 20px auto; border-radius: 12px;">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'patient'): ?>
    <div class="feedback-container">
        <h2 style="margin-bottom: 10px; color: #333;">How was your experience?</h2>
        <p style="color: #666; margin-bottom: 30px;">Your feedback helps us improve our services.</p>

        <form method="POST">
            <div class="rating-group">
                <input type="radio" name="rating" value="5" id="r5"><label for="r5" title="Excellent">★</label>
                <input type="radio" name="rating" value="4" id="r4"><label for="r4" title="Good">★</label>
                <input type="radio" name="rating" value="3" id="r3"><label for="r3" title="Average">★</label>
                <input type="radio" name="rating" value="2" id="r2"><label for="r2" title="Poor">★</label>
                <input type="radio" name="rating" value="1" id="r1" required><label for="r1" title="Very Poor">★</label>
            </div>

            <div style="text-align: left; margin-bottom: 10px; font-weight: 600; color: #555;">What went well?</div>
            <div class="tags-input">
                <input type="checkbox" name="tags[]" value="Doctor" id="t1" class="tag-checkbox">
                <label for="t1" class="tag-label">👨‍⚕️ Doctor Care</label>

                <input type="checkbox" name="tags[]" value="Staff" id="t2" class="tag-checkbox">
                <label for="t2" class="tag-label">🏥 Staff Behavior</label>

                <input type="checkbox" name="tags[]" value="Wait Time" id="t3" class="tag-checkbox">
                <label for="t3" class="tag-label">⏱️ Wait Time</label>

                <input type="checkbox" name="tags[]" value="Facility" id="t4" class="tag-checkbox">
                <label for="t4" class="tag-label">🧼 Cleanliness</label>

                <input type="checkbox" name="tags[]" value="Billing" id="t5" class="tag-checkbox">
                <label for="t5" class="tag-label">💳 Billing</label>
            </div>

            <div class="form-group">
                <textarea name="comments" class="modern-input" rows="4" placeholder="Share more details about your visit..."></textarea>
            </div>

            <button type="submit" name="submit_feedback" class="submit-btn">Submit Feedback</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'admin'): ?>
    <div class="card">
        <h3>Recent Feedback</h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Patient</th>
                        <th>Rating</th>
                        <th>Comments</th>
                        <th>Tags</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedbacks as $f): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($f['created_at'])); ?></td>
                        <td><?php echo $f['first_name'] ? htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) : 'Anonymous'; ?></td>
                        <td>
                            <?php for($i=0; $i<$f['rating']; $i++) echo '★'; ?>
                        </td>
                        <td><?php echo htmlspecialchars($f['comments']); ?></td>
                        <td><?php echo htmlspecialchars($f['tags'] ?? 'N/A'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
