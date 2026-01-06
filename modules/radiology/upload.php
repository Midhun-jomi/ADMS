<?php
// modules/radiology/upload.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['radiologist', 'admin']);

$page_title = "Upload Radiology Report";
include '../../includes/header.php';

$report_id = $_GET['id'] ?? null;

if (!$report_id) {
    echo "<div class='alert alert-danger'>Report ID required.</div>";
    include '../../includes/footer.php';
    exit();
}

$report = db_select_one("SELECT r.*, p.first_name, p.last_name FROM radiology_reports r JOIN patients p ON r.patient_id = p.id WHERE r.id = $1", [$report_id]);

if (!$report) {
    echo "<div class='alert alert-danger'>Report not found.</div>";
    include '../../includes/footer.php';
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $findings = $_POST['findings'];
    $image_url = $_POST['image_url']; // In real app, handle file upload to storage bucket
    
    db_update('radiology_reports', 
              ['findings' => $findings, 'image_url' => $image_url, 'status' => 'completed'], 
              ['id' => $report_id]);
              
    echo "<div class='alert alert-success'>Report uploaded successfully.</div>";
    $report = db_select_one("SELECT r.*, p.first_name, p.last_name FROM radiology_reports r JOIN patients p ON r.patient_id = p.id WHERE r.id = $1", [$report_id]);
}
?>

<div class="card">
    <div class="card-header">
        Process Scan: <?php echo htmlspecialchars($report['report_type']); ?>
    </div>
    
    <div class="form-row" style="margin-bottom: 20px;">
        <p><strong>Patient:</strong> <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></p>
        <p><strong>Status:</strong> <?php echo ucfirst($report['status']); ?></p>
    </div>

    <form method="POST" action="">
        <div class="form-group">
            <label for="image_url">Image URL (Mock Upload)</label>
            <input type="text" id="image_url" name="image_url" class="form-control" placeholder="https://example.com/scan.jpg" required value="<?php echo htmlspecialchars($report['image_url'] ?? ''); ?>">
            <small>Enter a dummy URL for now.</small>
        </div>

        <div class="form-group">
            <label for="findings">Radiologist Findings</label>
            <textarea id="findings" name="findings" class="form-control" rows="5" required><?php echo htmlspecialchars($report['findings'] ?? ''); ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Complete Report</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
