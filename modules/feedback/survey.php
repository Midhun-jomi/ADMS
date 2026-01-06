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

        db_insert('patient_feedback', [
            'patient_id' => $patient_id,
            'rating' => $_POST['rating'],
            'comments' => $_POST['comments']
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
        LEFT JOIN patients p ON f.patient_id = p.id 
        ORDER BY f.created_at DESC
    ");
}
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-comment-medical"></i> Patient Feedback</h1>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <?php if ($_SESSION['role'] === 'patient'): ?>
    <div class="card center-box">
        <h3>Rate Your Experience</h3>
        <form method="POST">
            <div class="rating-stars">
                <input type="radio" name="rating" value="5" id="s5"><label for="s5">★</label>
                <input type="radio" name="rating" value="4" id="s4"><label for="s4">★</label>
                <input type="radio" name="rating" value="3" id="s3"><label for="s3">★</label>
                <input type="radio" name="rating" value="2" id="s2"><label for="s2">★</label>
                <input type="radio" name="rating" value="1" id="s1" required><label for="s1">★</label>
            </div>
            <div class="form-group">
                <label>Comments or Suggestions</label>
                <textarea name="comments" rows="4" required placeholder="Tell us how we did..."></textarea>
            </div>
            <button type="submit" name="submit_feedback" class="btn-primary">Submit Feedback</button>
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
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.center-box { max-width: 600px; margin: 0 auto; text-align: center; }
.rating-stars { display: flex; flex-direction: row-reverse; justify-content: center; font-size: 2rem; margin-bottom: 1rem; }
.rating-stars input { display: none; }
.rating-stars label { color: #ddd; cursor: pointer; transition: color 0.2s; }
.rating-stars input:checked ~ label, .rating-stars label:hover, .rating-stars label:hover ~ label { color: #f1c40f; }
</style>

<?php require_once '../../includes/footer.php'; ?>
