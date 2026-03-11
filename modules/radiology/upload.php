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
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<div class='alert alert-danger'>Invalid request. Please refresh and try again.</div>";
        include '../../includes/footer.php';
        exit();
    }

    $findings = $_POST['findings'];
    
    // Existing URLs parsing (backward compatible string or JSON array)
    $existing = $_POST['existing_image_url'] ?? '';
    $urls = json_decode($existing, true);
    if (!is_array($urls)) {
        $urls = !empty($existing) ? [$existing] : [];
    }

    // Handle Multiple File Uploads
    if (isset($_FILES['scan_file'])) {
        $upload_dir = __DIR__ . '/../../assets/uploads/radiology/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $max_file_size = 10 * 1024 * 1024; // 10 MB

        $total = count($_FILES['scan_file']['name']);
        for ($i = 0; $i < $total; $i++) {
            if ($_FILES['scan_file']['error'][$i] == 0) {
                $original_name = $_FILES['scan_file']['name'][$i];
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                // Check file size
                if ($_FILES['scan_file']['size'][$i] > $max_file_size) {
                    echo "<div class='alert alert-danger'>File too large (max 10 MB): " . htmlspecialchars($original_name) . "</div>";
                    continue;
                }

                // Check extension
                if (!in_array($ext, $allowed_exts)) {
                    echo "<div class='alert alert-danger'>Invalid file type for " . htmlspecialchars($original_name) . ". Allowed: JPG, PNG, GIF, PDF.</div>";
                    continue;
                }

                // Check actual MIME type to prevent disguised executables
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $_FILES['scan_file']['tmp_name'][$i]);
                finfo_close($finfo);

                if (!in_array($mime_type, $allowed_mimes)) {
                    echo "<div class='alert alert-danger'>File content does not match allowed types: " . htmlspecialchars($original_name) . "</div>";
                    continue;
                }

                // Safe filename — strip non-alphanumeric chars from report_id
                $report_id_safe = preg_replace('/[^a-zA-Z0-9\-]/', '', $report_id);
                $filename = "rad_" . $report_id_safe . "_" . time() . "_" . $i . "." . $ext;
                $target_file = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['scan_file']['tmp_name'][$i], $target_file)) {
                    $urls[] = "/assets/uploads/radiology/" . $filename;
                } else {
                    echo "<div class='alert alert-danger'>Failed to upload " . htmlspecialchars($original_name) . ".</div>";
                }
            }
        }
    }
    
    // Save as JSON if multiple, string if single, empty if none (for backward compatibility and neatness)
    $image_url = count($urls) > 1 ? json_encode($urls) : ($urls[0] ?? '');
    
    db_update('radiology_reports', 
              ['findings' => $findings, 'image_url' => $image_url, 'status' => 'completed'], 
              ['id' => $report_id]);
              
    echo "<div class='alert alert-success'>Report uploaded successfully.</div>";
    $report = db_select_one("SELECT r.*, p.first_name, p.last_name FROM radiology_reports r JOIN patients p ON r.patient_id = p.id WHERE r.id = $1", [$report_id]);
}
?>

<div class="row my-4">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header bg-white pt-4 pb-3" style="border-bottom: 2px solid #f1f5f9;">
                <h4 class="mb-0" style="color: #1e293b; font-weight: 700;">
                    <i class="fas fa-layer-group text-primary mr-2"></i> Radiology Report: <?php echo htmlspecialchars($report['report_type']); ?>
                </h4>
            </div>
            
            <div class="card-body p-4 p-md-5">
                
                <div class="p-4 mb-5 rounded" style="background-color: #f8fafc; border-left: 5px solid #3b82f6;">
                    <div class="row align-items-center">
                        <div class="col-sm-6">
                            <p class="mb-1 text-muted small text-uppercase font-weight-bold" style="letter-spacing: 0.5px;">Patient Name</p>
                            <p class="mb-0 font-weight-bold" style="font-size: 1.15rem; color: #0f172a;"><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></p>
                        </div>
                        <div class="col-sm-6 text-sm-right mt-3 mt-sm-0">
                            <p class="mb-1 text-muted small text-uppercase font-weight-bold" style="letter-spacing: 0.5px;">Current Status</p>
                            <span class="badge badge-<?php echo $report['status'] === 'completed' ? 'success' : 'warning'; ?> px-3 py-2 shadow-sm" style="font-size: 0.9em; border-radius: 20px;">
                                <?php echo ucfirst($report['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" enctype="multipart/form-data">
                    <?php echo csrf_input(); ?>
                    <?php
                        $existing_json = $report['image_url'] ?? '';
                        $saved_urls = json_decode($existing_json, true);
                        if (!is_array($saved_urls) && !empty($existing_json)) {
                            $saved_urls = [$existing_json];
                        }
                    ?>
                    <input type="hidden" name="existing_image_url" value="<?php echo htmlspecialchars($existing_json); ?>">
                    
                    <div class="form-group mb-5">
                        <label for="scan_file" class="font-weight-bold text-dark" style="font-size: 1.1rem;">Upload Scan Images/PDFs</label>
                        
                        <?php if (!empty($saved_urls)): ?>
                            <div class="p-4 mb-4 rounded border shadow-sm" style="background-color: #ffffff; border-color: #e2e8f0;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-success font-weight-bold" style="font-size: 1.05rem;"><i class="fas fa-check-circle mr-1"></i> <?php echo count($saved_urls); ?> File(s) officially attached</span>
                                </div>
                                <div class="d-flex flex-wrap" style="gap: 10px;">
                                    <?php foreach($saved_urls as $idx => $s_url): ?>
                                        <a href="<?php echo htmlspecialchars($s_url); ?>" target="_blank" class="btn btn-outline-primary" style="border-radius: 25px; font-weight: 500; transition: all 0.2s;">
                                            <i class="fas fa-file-image mr-1"></i> View Scan <?php echo $idx+1; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted mt-3 d-block"><i class="fas fa-info-circle mr-1"></i> Note: Uploading new files below will safely append them to this existing report.</small>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Custom File Upload stylings -->
                        <div class="custom-file-upload mt-2" style="position: relative; overflow: hidden; display: block; width: 100%;">
                            <label for="scan_file" class="w-100 p-5 text-center rounded d-flex flex-column align-items-center justify-content-center" 
                                   style="border: 2px dashed #94a3b8; background: #f1f5f9; cursor: pointer; transition: all 0.2s; min-height: 200px;">
                                <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-primary"></i>
                                <h5 class="mb-2 text-dark font-weight-bold">Click to select files or drag and drop</h5>
                                <p class="text-muted small mb-0">Supported formats: JPG, PNG, GIF, PDF (Multiple files allowed)</p>
                            </label>
                            <input type="file" id="scan_file" name="scan_file[]" accept="image/*,.pdf" multiple <?php echo empty($saved_urls) ? 'required' : ''; ?> 
                                   style="position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer;"
                                   onchange="document.getElementById('file-count-display').innerText = this.files.length > 0 ? this.files.length + ' file(s) selected for upload.' : '';">
                            <div id="file-count-display" class="mt-2 text-primary font-weight-bold" style="min-height: 24px;"></div>
                        </div>
                    </div>

                    <div class="form-group mb-5">
                        <label for="findings" class="font-weight-bold text-dark" style="font-size: 1.1rem;">Radiologist Findings & Interpretation</label>
                        <textarea id="findings" name="findings" class="form-control p-3" rows="7" required 
                                  style="border-radius: 10px; border: 1px solid #cbd5e1; font-size: 1.05rem;" 
                                  placeholder="Enter detailed diagnostic findings, impression, and clinical notes here..."><?php echo htmlspecialchars($report['findings'] ?? ''); ?></textarea>
                    </div>

                    <hr class="mb-4" style="border-top: 1px solid #e2e8f0;">
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-lg shadow" style="border-radius: 30px; font-weight: 600; padding: 12px 35px; letter-spacing: 0.5px;">
                            <i class="fas fa-save mr-2"></i> Save & Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
