<?php
// modules/radiology/upload.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['radiologist', 'admin']);

$page_title = "Upload Radiology Report";
include '../../includes/header.php';

$report_id = $_GET['id'] ?? null;

// If no ID is provided, show list of radiology orders for the radiologist/admin
if (!$report_id) {
    echo '<div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">';
    echo '    <div class="card-header bg-white pt-4 pb-3" style="border-bottom: 2px solid #f1f5f9;">';
    echo '        <h4 class="mb-0" style="color: #1e293b; font-weight: 700;">';
    echo '            <i class="fas fa-list text-primary mr-2"></i> Select Radiology Order to Process';
    echo '        </h4>';
    echo '    </div>';
    
    // Fetch all orders for list view
    $orders = db_select("SELECT r.*, p.first_name, p.last_name, s.first_name as doc_first, s.last_name as doc_last 
                         FROM radiology_reports r 
                         JOIN patients p ON r.patient_id = p.id 
                         JOIN staff s ON r.doctor_id = s.id 
                         ORDER BY r.created_at DESC");

    if (empty($orders)) {
        echo '<div class="card-body p-4"><div class="alert alert-info">No radiology orders found.</div></div>';
    } else {
        echo '<div class="card-body p-4">';
        echo '    <div class="mb-4 d-flex justify-content-between align-items-center">';
        echo '        <input type="text" id="filter-rad-list" onkeyup="filterTable(\'filter-rad-list\',\'tbl-rad-list\')" placeholder="Search patient or scan type..." class="form-control" style="max-width: 300px; border-radius: 8px;">';
        echo '    </div>';
        echo '    <div class="table-responsive">';
        echo '        <table id="tbl-rad-list" class="table table-hover">';
        echo '            <thead class="bg-light"><tr><th>Date</th><th>Patient</th><th>Scan Type</th><th>Doctor</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            $status_class = ($o['status'] === 'completed') ? 'success' : 'warning';
            echo '<tr>';
            echo '    <td class="align-middle">' . date('M d, Y', strtotime($o['created_at'])) . '</td>';
            echo '    <td class="align-middle font-weight-bold">' . htmlspecialchars($o['first_name'] . ' ' . $o['last_name']) . '</td>';
            echo '    <td class="align-middle">' . htmlspecialchars($o['report_type']) . '</td>';
            echo '    <td class="align-middle small text-muted">Dr. ' . htmlspecialchars($o['doc_first'] . ' ' . $o['doc_last']) . '</td>';
            echo '    <td class="align-middle"><span class="badge badge-' . $status_class . ' px-2 py-1" style="border-radius: 10px;">' . ucfirst($o['status']) . '</span></td>';
            echo '    <td class="align-middle"><a href="?id=' . $o['id'] . '" class="btn btn-sm btn-primary" style="border-radius: 5px;">' . ($o['status'] === 'completed' ? 'Edit/View' : 'Process') . '</a></td>';
            echo '</tr>';
        }
        echo '            </tbody></table>';
        echo '    </div>';
        echo '</div>';
    }
    echo '</div>';
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
                    <input type="hidden" id="existing_image_url" name="existing_image_url" value="<?php echo htmlspecialchars($existing_json); ?>">
                    
                    <div class="form-group mb-5">
                        <label for="scan_file" class="font-weight-bold text-dark" style="font-size: 1.1rem;">Upload Scan Images/PDFs</label>
                        
                        <?php if (!empty($saved_urls)): ?>
                            <div class="p-4 mb-4 rounded border shadow-sm" style="background-color: #ffffff; border-color: #e2e8f0;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-success font-weight-bold" style="font-size: 1.05rem;"><i class="fas fa-check-circle mr-1"></i> <?php echo count($saved_urls); ?> File(s) officially attached</span>
                                </div>
                                <div class="d-flex flex-wrap" style="gap: 12px;" id="existing-files-container">
                                    <?php foreach($saved_urls as $idx => $s_url): ?>
                                        <div class="existing-file-badge d-flex align-items-center" data-url="<?php echo htmlspecialchars($s_url); ?>" 
                                             style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 25px; padding: 5px 15px; transition: all 0.2s;">
                                            <a href="<?php echo htmlspecialchars($s_url); ?>" target="_blank" class="mr-2 text-primary font-weight-medium">
                                                <i class="fas fa-file-image mr-1"></i> Scan <?php echo $idx+1; ?>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0 delete-file-btn" title="Remove this file" 
                                                    onclick="removeExistingFile(this, '<?php echo addslashes($s_url); ?>')">
                                                <i class="fas fa-times-circle"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted mt-3 d-block"><i class="fas fa-info-circle mr-1"></i> Note: Clicking the red 'X' will remove the file upon saving.</small>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Custom File Upload stylings -->
                        <div class="row">
                            <div class="col-md-8">
                                <div class="custom-file-upload mt-2" style="position: relative; overflow: hidden; display: block; width: 100%;">
                                    <label for="scan_file" class="w-100 p-5 text-center rounded d-flex flex-column align-items-center justify-content-center" 
                                           style="border: 2px dashed #94a3b8; background: #f1f5f9; cursor: pointer; transition: all 0.2s; min-height: 200px;">
                                        <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-primary"></i>
                                        <h5 class="mb-2 text-dark font-weight-bold">Select files or drag and drop</h5>
                                        <p class="text-muted small mb-0">Supported formats: JPG, PNG, GIF, PDF</p>
                                    </label>
                                    <input type="file" id="scan_file" name="scan_file[]" accept="image/*,.pdf" multiple <?php echo empty($saved_urls) ? 'required' : ''; ?> 
                                           style="position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer;"
                                           onchange="updateFileCountDisplay(this)">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="camera-upload mt-2 h-100">
                                    <label for="camera_capture" class="w-100 p-5 text-center rounded d-flex flex-column align-items-center justify-content-center h-100" 
                                           style="border: 2px solid #3b82f6; background: #eff6ff; cursor: pointer; transition: all 0.2s; min-height: 200px;">
                                        <i class="fas fa-camera fa-3x mb-3 text-primary"></i>
                                        <h5 class="mb-2 text-primary font-weight-bold">Use Camera</h5>
                                        <p class="text-muted small mb-0">Capture photo directly</p>
                                    </label>
                                    <input type="file" id="camera_capture" name="scan_file[]" accept="image/*" capture="environment" 
                                           style="display: none;" onchange="updateFileCountDisplay(this)">
                                </div>
                            </div>
                        </div>
                        <div id="file-count-display" class="mt-2 text-primary font-weight-bold" style="min-height: 24px;"></div>
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

<script>
function removeExistingFile(btn, url) {
    if (!confirm('Are you sure you want to remove this file?')) return;
    
    // Remove from UI
    btn.parentElement.remove();
    
    // Update hidden field
    const hiddenInput = document.getElementById('existing_image_url');
    let urls = [];
    try {
        const val = hiddenInput.value;
        if (val.startsWith('[')) {
            urls = JSON.parse(val);
        } else if (val) {
            urls = [val];
        }
    } catch(e) { console.error(e); }
    
    const newUrls = urls.filter(u => u !== url);
    hiddenInput.value = newUrls.length > 1 ? JSON.stringify(newUrls) : (newUrls[0] || '');
    
    // Update count display if needed
    const container = document.getElementById('existing-files-container');
    if (container && container.children.length === 0) {
        container.closest('.p-4').innerHTML = '<div class="alert alert-info">All files marked for removal. Save to confirm.</div>';
        // Also make file upload required if no files left
        document.getElementById('scan_file').required = true;
    }
}

function updateFileCountDisplay(input) {
    const display = document.getElementById('file-count-display');
    const totalFiles = (document.getElementById('scan_file').files.length || 0) + (document.getElementById('camera_capture').files.length || 0);
    display.innerText = totalFiles > 0 ? totalFiles + ' file(s) ready for upload.' : '';
}
</script>

<?php include '../../includes/footer.php'; ?>
