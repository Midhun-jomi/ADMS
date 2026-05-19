<?php
// modules/lab/orders.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Laboratory Orders";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle New Order (Doctor Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
    $patient_id = $_POST['patient_id'];
    $test_type = $_POST['test_type'];
    
    // Get doctor ID
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    
    if ($doctor) {
        $status = 'ordered';
        // Insert Test with RETURNING id
        $sql = "INSERT INTO laboratory_tests (patient_id, doctor_id, test_type, status) VALUES ($1, $2, $3, $4) RETURNING id";
        try {
            $res = db_query($sql, [$patient_id, $doctor['id'], $test_type, $status]);
            $row = pg_fetch_assoc($res);
            $test_id = $row['id'];
            
            // Auto-bill based on test type
            $prices = [
                'Complete Blood Count (CBC)' => 20.00,
                'Lipid Profile' => 30.00,
                'Liver Function Test' => 40.00,
                'Blood Sugar (Fasting)' => 10.00,
                'Urinalysis' => 15.00
            ];
            
            $price = $prices[$test_type] ?? 0.00;
            
            if ($price > 0) {
                $bill_data = [
                    'patient_id' => $patient_id,
                    'total_amount' => $price,
                    'status' => 'pending',
                    'service_description' => "Lab Test: $test_type (Ref: $test_id)"
                ];
                db_insert('billing', $bill_data);
            }
            
            $success = "Lab test ordered and billed ($$price) successfully.";
        } catch (Exception $e) {
            $error = "Failed to order test: " . $e->getMessage();
        }
    }
    } // end CSRF check
}

// Fetch Orders
$orders = [];
if ($role === 'doctor') {
    $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    if ($doctor) {
        $sql = "SELECT l.*, p.first_name, p.last_name 
                FROM laboratory_tests l 
                JOIN patients p ON l.patient_id = p.id 
                WHERE l.doctor_id = $1 
                ORDER BY l.created_at DESC";
        $orders = db_select($sql, [$doctor['id']]);
    }
} elseif ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($patient) {
        $sql = "SELECT l.*, s.first_name as doc_first, s.last_name as doc_last 
                FROM laboratory_tests l 
                JOIN staff s ON l.doctor_id = s.id 
                WHERE l.patient_id = $1 
                ORDER BY l.created_at DESC";
        $orders = db_select($sql, [$patient['id']]);
    }
} elseif ($role === 'lab_tech' || $role === 'admin') {
    $sql = "SELECT l.*, p.first_name, p.last_name, s.first_name as doc_first, s.last_name as doc_last 
            FROM laboratory_tests l 
            JOIN patients p ON l.patient_id = p.id 
            JOIN staff s ON l.doctor_id = s.id 
            ORDER BY l.created_at DESC";
    $orders = db_select($sql);
}

// Pre-fill patient if passed in URL
$pre_patient_id = $_GET['patient_id'] ?? '';
?>

<div class="card">
    <div class="card-header">Lab Orders</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($role === 'doctor'): ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <h5>Order New Test</h5>
            <form method="POST" action="" style="display: flex; gap: 10px; align-items: flex-end;">
                <?php echo csrf_input(); ?>
                <div style="flex: 1;">
                    <label>Patient ID</label>
                    <input type="text" name="patient_id" class="form-control" value="<?php echo htmlspecialchars($pre_patient_id); ?>" required placeholder="UUID">
                </div>
                <div style="flex: 2;">
                    <label>Test Type</label>
                    <select name="test_type" class="form-control">
                        <option value="Complete Blood Count (CBC)">Complete Blood Count (CBC)</option>
                        <option value="Lipid Profile">Lipid Profile</option>
                        <option value="Liver Function Test">Liver Function Test</option>
                        <option value="Blood Sugar (Fasting)">Blood Sugar (Fasting)</option>
                        <option value="Urinalysis">Urinalysis</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Order Test</button>
            </form>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 14px;">
        <input type="text" id="filter-lab-orders" onkeyup="filterTable('filter-lab-orders','tbl-lab-orders')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
    </div>
    <table id="tbl-lab-orders" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Date</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Test Type</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Patient</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Status</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Actions</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">AI Intelligence</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): 
                // Fetch previous result for comparison
                $prev_order = db_select_one("SELECT result_data FROM laboratory_tests WHERE patient_id = $1 AND test_type = $2 AND status = 'completed' AND created_at < $3 ORDER BY created_at DESC LIMIT 1", [$order['patient_id'], $order['test_type'], $order['created_at']]);
                $prev_result = $prev_order ? $prev_order['result_data'] : '';
            ?>
                <tr style="border-bottom: 1px solid #dee2e6;">

                    <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($order['test_type']); ?></td>
                    <td style="padding: 10px;">
                        <?php echo htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')); ?>
                    </td>
                    <td style="padding: 10px;">
                        <span style="padding: 5px 10px; border-radius: 15px; font-size: 0.85em; 
                            background-color: <?php echo ($order['status'] === 'completed') ? '#d4edda' : '#fff3cd'; ?>;
                            color: <?php echo ($order['status'] === 'completed') ? '#155724' : '#856404'; ?>;">
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </td>
                    <td style="padding: 10px;">
                        <?php if ($order['status'] === 'completed'): ?>
                            <a href="results.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #28a745; color: white; padding: 2px 8px; font-size: 12px;">View Results</a>
                        <?php elseif ($role === 'lab_tech' || $role === 'admin'): ?>
                            <a href="results.php?id=<?php echo $order['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white; padding: 2px 8px; font-size: 12px;">Process</a>
                        <?php else: ?>
                            <span style="color: #6c757d; font-size: 0.9em;">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 10px;">
                        <?php if ($order['status'] === 'completed' && !empty($order['result_data'])): ?>
                            <div class="ai-finding-cell" 
                                 data-test-id="<?php echo $order['id']; ?>" 
                                 data-result='<?php echo htmlspecialchars($order['result_data'], ENT_QUOTES, 'UTF-8'); ?>'
                                 data-prev-result='<?php echo htmlspecialchars($prev_result, ENT_QUOTES, 'UTF-8'); ?>'>
                                <span class="badge" style="background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; cursor: pointer;" onclick="showAIDetails(this)">
                                    <i class="fas fa-microchip fa-spin"></i> Analyzing...
                                </span>
                            </div>

                        <?php else: ?>
                            <span style="color: #94a3b8; font-size: 0.85em;">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        </tbody>
    </table>
</div>

<!-- AI Details Modal -->
<div id="aiModal" style="display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);">
    <div style="background: white; margin: 10% auto; padding: 30px; border-radius: 20px; width: 550px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); border: 1px solid #e2e8f0; position: relative; animation: modalSlideUp 0.3s ease-out;">
        <span onclick="closeAIModal()" style="position: absolute; right: 20px; top: 20px; font-size: 24px; font-weight: bold; cursor: pointer; color: #94a3b8;">&times;</span>
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
            <div style="width: 45px; height: 45px; background: #e0e7ff; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #4338ca;">
                <i class="fas fa-robot fa-lg"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #1e293b;">AI Clinical Insights</h3>
                <p id="modal-test-type" style="margin: 0; font-size: 0.85em; color: #64748b; font-weight: 600;"></p>
            </div>
        </div>
        
        <div id="modal-content" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
            <div id="modal-issues" style="margin-bottom: 20px;"></div>
            <div id="modal-summary" style="background: #f8fafc; padding: 15px; border-radius: 12px; border-left: 4px solid #6366f1;">
                <h4 style="margin: 0 0 8px 0; font-size: 0.75em; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Impression</h4>
                <p style="margin: 0; font-size: 0.95em; color: #334155; line-height: 1.6; font-weight: 500;"></p>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes modalSlideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.finding-item {
    background: #fef2f2;
    border: 1px solid #fee2e2;
    padding: 12px 15px;
    border-radius: 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
</style>

<script>
let currentAIData = {};

function showAIDetails(badge) {
    const cell = badge.closest('.ai-finding-cell');
    const data = JSON.parse(cell.getAttribute('data-ai-response') || '{}');
    const prevResultRaw = cell.getAttribute('data-prev-result');
    const prevData = prevResultRaw ? JSON.parse(prevResultRaw) : null;
    const testType = cell.closest('tr').cells[1].innerText;
    
    if (!data.overall_impression) return;

    document.getElementById('modal-test-type').innerText = testType;
    const issuesDiv = document.getElementById('modal-issues');
    issuesDiv.innerHTML = '';
    
    if (data.lab_findings) {
        data.lab_findings.forEach(f => {
            if (f.status === 'Normal' && !prevData) return; // Skip normal if no history to compare

            // Find previous value for this marker
            let prevValue = null;
            if (prevData && prevData.details) {
                const prevMetric = prevData.details.find(d => d.name.toLowerCase().includes(f.marker.toLowerCase()));
                if (prevMetric) prevValue = parseFloat(prevMetric.value);
            }

            const currValue = parseFloat(f.value);
            let trendHtml = '';
            
            if (prevValue !== null && !isNaN(currValue) && !isNaN(prevValue)) {
                const diff = currValue - prevValue;
                const percentChange = ((diff / prevValue) * 100).toFixed(1);
                const color = diff > 0 ? '#dc2626' : (diff < 0 ? '#2563eb' : '#64748b');
                const icon = diff > 0 ? 'fa-arrow-trend-up' : (diff < 0 ? 'fa-arrow-trend-down' : 'fa-minus');
                
                trendHtml = `
                    <div style="text-align: right;">
                        <span style="color: ${color}; font-size: 0.8em; font-weight: 800;">
                            <i class="fas ${icon}"></i> ${Math.abs(percentChange)}%
                        </span>
                        <span style="display: block; font-size: 0.65em; color: #94a3b8; font-weight: 600;">Prev: ${prevValue}</span>
                    </div>
                `;
            }

            if (f.status !== 'Normal' || trendHtml !== '') {
                issuesDiv.innerHTML += `
                    <div class="finding-item" style="border-left: 4px solid ${f.status === 'Normal' ? '#e2e8f0' : '#fee2e2'}; background: ${f.status === 'Normal' ? '#f8fafc' : '#fef2f2'};">
                        <div>
                            <span style="font-size: 0.7em; font-weight: 800; color: #64748b; display: block; text-transform: uppercase;">${f.marker}</span>
                            <span style="font-size: 1.1rem; font-weight: 800; color: #1e293b;">${f.value}</span>
                            <span style="background: ${f.status === 'Normal' ? '#f1f5f9' : '#fee2e2'}; color: ${f.status === 'Normal' ? '#64748b' : '#b91c1c'}; padding: 2px 8px; border-radius: 12px; font-size: 0.7em; font-weight: 800; margin-left: 5px;">${f.status}</span>
                        </div>
                        ${trendHtml}
                    </div>
                `;
            }
        });
    }
    
    document.querySelector('#modal-summary p').innerText = data.overall_impression;
    document.getElementById('aiModal').style.display = 'block';
}


function closeAIModal() {
    document.getElementById('aiModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const aiCells = document.querySelectorAll('.ai-finding-cell');
    
    aiCells.forEach(async cell => {
        try {
            const resultData = JSON.parse(cell.getAttribute('data-result'));
            const prevResultRaw = cell.getAttribute('data-prev-result');
            const prevData = prevResultRaw ? JSON.parse(prevResultRaw) : null;
            const labResults = {};

            
            if (resultData.details) {
                resultData.details.forEach(d => {
                    const name = d.name.toLowerCase();
                    if (name.includes('hemoglobin')) labResults.hemoglobin = d.value;
                    if (name.includes('wbc')) labResults.wbc = d.value;
                    if (name.includes('glucose') || name.includes('sugar')) labResults.glucose = d.value;
                    if (name.includes('creatinine')) labResults.creatinine = d.value;
                    if (name.includes('platelet')) labResults.platelets = d.value;
                    if (name.includes('potassium')) labResults.potassium = d.value;
                });
            }

            const response = await fetch('http://localhost:5001/analyze_results', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lab_results: labResults,
                    radiology_report: resultData.summary || resultData.findings || ""
                })
            });
            
            const data = await response.json();
            cell.setAttribute('data-ai-response', JSON.stringify(data));
            
            // Calculate Trends for the main table view
            let trendSummary = '';
            if (prevData && prevData.details && data.lab_findings) {
                const changes = [];
                data.lab_findings.forEach(f => {
                    const prevMetric = prevData.details.find(d => d.name.toLowerCase().includes(f.marker.toLowerCase()));
                    if (prevMetric) {
                        const currVal = parseFloat(f.value);
                        const prevVal = parseFloat(prevMetric.value);
                        if (!isNaN(currVal) && !isNaN(prevVal)) {
                            if (currVal > prevVal) changes.push(`<span style="color: #dc2626;">↑</span>${f.marker.substring(0,2)}`);
                            else if (currVal < prevVal) changes.push(`<span style="color: #2563eb;">↓</span>${f.marker.substring(0,2)}`);
                        }
                    }
                });
                if (changes.length > 0) {
                    trendSummary = `<div style="font-size: 0.7em; margin-top: 4px; color: #64748b; font-weight: 600; display: flex; gap: 5px; flex-wrap: wrap;">
                        ${changes.slice(0, 3).join(' ')}${changes.length > 3 ? '...' : ''}
                    </div>`;
                }
            }

            let statusBadge = '';
            if (data.lab_findings && data.lab_findings.length > 0) {
                const abnormals = data.lab_findings.filter(f => f.status !== 'Normal');
                if (abnormals.length > 0) {
                    statusBadge = `<span class="badge" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-size: 0.8em; padding: 4px 8px; border-radius: 12px; cursor: pointer;" onclick="showAIDetails(this)" title="Click for details">
                        <i class="fas fa-exclamation-circle"></i> ${abnormals.length} Issues
                    </span>`;
                } else {
                    statusBadge = `<span class="badge" style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-size: 0.8em; padding: 4px 8px; border-radius: 12px; cursor: pointer;" onclick="showAIDetails(this)">
                        <i class="fas fa-check-circle"></i> Normal
                    </span>`;
                }
            } else {
                statusBadge = `<span class="badge" style="background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; font-size: 0.8em; padding: 4px 8px; border-radius: 12px; cursor: pointer;" onclick="showAIDetails(this)">Review Report</span>`;
            }
            
            cell.innerHTML = statusBadge + trendSummary;

        } catch (err) {
            console.error(err);
            cell.innerHTML = '<span style="color: #94a3b8; font-size: 0.8em;">AI Offline</span>';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>


