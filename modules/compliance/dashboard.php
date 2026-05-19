<?php
// modules/compliance/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Compliance & Security Dashboard";

$error   = '';
$success = '';
$user_id = get_user_id();

// ─── CSV Export ───────────────────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        die("Invalid request.");
    }
    $from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $to_date   = $_GET['to_date']   ?? date('Y-m-d');
    $csv_logs  = db_select(
        "SELECT al.action, al.details, al.ip_address, al.created_at,
                u.email AS user_email
         FROM audit_logs al
         LEFT JOIN users u ON al.user_id = u.id
         WHERE al.created_at::date BETWEEN $1 AND $2
         ORDER BY al.created_at DESC",
        [$from_date, $to_date]
    );
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['User Email', 'Action', 'Details', 'IP Address', 'Timestamp']);
    foreach ($csv_logs as $row) {
        fputcsv($out, [
            $row['user_email']  ?? '',
            $row['action']      ?? '',
            $row['details']     ?? '',
            $row['ip_address']  ?? '',
            $row['created_at']  ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ─── POST: update compliance item ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $item_id = $_POST['item_id'] ?? '';
        $status  = $_POST['status']  ?? 'pending';
        $notes   = trim($_POST['notes'] ?? '');
        $allowed = ['pending', 'completed', 'not_applicable'];
        if (!in_array($status, $allowed)) $status = 'pending';
        try {
            if (!empty($item_id)) {
                db_update('compliance_items',
                    ['status' => $status, 'notes' => $notes, 'updated_by' => $user_id, 'updated_at' => 'NOW()'],
                    ['id' => $item_id]
                );
            } else {
                $item_name = $_POST['item_name'] ?? '';
                if ($item_name) {
                    db_insert('compliance_items', [
                        'item_name' => $item_name,
                        'status' => $status,
                        'notes' => $notes,
                        'updated_by' => $user_id
                    ]);
                }
            }
            $success = "Compliance item updated.";
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }
}

// ─── Date range filter ────────────────────────────────────────────────────────
$from_date = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
$to_date   = $_GET['to_date']   ?? date('Y-m-d');

// ─── Stats ────────────────────────────────────────────────────────────────────
$total_audit  = db_select_one("SELECT COUNT(*) AS cnt FROM audit_logs WHERE created_at::date BETWEEN $1 AND $2", [$from_date, $to_date]);
$data_access  = db_select_one("SELECT COUNT(*) AS cnt FROM audit_logs WHERE (action ILIKE '%view%' OR action ILIKE '%access%' OR action ILIKE '%read%') AND created_at::date BETWEEN $1 AND $2", [$from_date, $to_date]);
$security_ev  = db_select_one("SELECT COUNT(*) AS cnt FROM audit_logs WHERE (action ILIKE '%login%' OR action ILIKE '%logout%' OR action ILIKE '%password%' OR action ILIKE '%auth%') AND created_at::date BETWEEN $1 AND $2", [$from_date, $to_date]);
$policy_viol  = db_select_one("SELECT COUNT(*) AS cnt FROM audit_logs WHERE (action ILIKE '%fail%' OR action ILIKE '%unauthorized%' OR action ILIKE '%denied%' OR action ILIKE '%violation%') AND created_at::date BETWEEN $1 AND $2", [$from_date, $to_date]);

// ─── Recent audit logs ────────────────────────────────────────────────────────
$audit_logs = db_select(
    "SELECT al.action, al.details, al.ip_address, al.created_at,
            u.email AS user_email
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE al.created_at::date BETWEEN $1 AND $2
     ORDER BY al.created_at DESC LIMIT 50",
    [$from_date, $to_date]
);

// ─── Security alerts (last 7 days) ───────────────────────────────────────────
$alert_from  = date('Y-m-d', strtotime('-7 days'));
$sec_alerts  = db_select(
    "SELECT al.action, al.details, al.ip_address, al.created_at,
            u.email AS user_email
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE (al.action ILIKE '%fail%' OR al.action ILIKE '%unauthorized%' OR al.action ILIKE '%error%' OR al.action ILIKE '%denied%')
       AND al.created_at::date >= $1
     ORDER BY al.created_at DESC LIMIT 20",
    [$alert_from]
);

// ─── Compliance items (DB + static fallback) ──────────────────────────────────
$db_items = db_select("SELECT * FROM compliance_items ORDER BY item_name ASC");
$db_map   = [];
foreach ($db_items as $di) {
    $db_map[$di['item_name']] = $di;
}

// Static standard items — system-confirmed ones are read-only
$static_items = [
    ['item_name' => 'Session timeout enforced',        'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'CSRF protection active',          'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'Password minimum 8 characters',   'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'SSL/TLS encryption',              'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'Audit logging active',            'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'Role-based access control',       'system_confirmed' => true,  'confirmed_value' => true],
    ['item_name' => 'Patient data encrypted at rest',  'system_confirmed' => false, 'confirmed_value' => false],
    ['item_name' => 'Regular backup policy',           'system_confirmed' => false, 'confirmed_value' => false],
    ['item_name' => 'Staff security training',         'system_confirmed' => false, 'confirmed_value' => false],
    ['item_name' => 'Data breach response plan',       'system_confirmed' => false, 'confirmed_value' => false],
];

// Merge DB status into static list
$compliance_items = [];
foreach ($static_items as $si) {
    $name = $si['item_name'];
    if (isset($db_map[$name])) {
        $si['db_row'] = $db_map[$name];
        $si['effective_status'] = $si['system_confirmed'] ? 'completed' : $db_map[$name]['status'];
    } else {
        $si['db_row'] = null;
        $si['effective_status'] = $si['system_confirmed'] ? 'completed' : 'pending';
    }
    $compliance_items[] = $si;
}

$completed_count = count(array_filter($compliance_items, fn($i) => $i['effective_status'] === 'completed'));
$total_items      = count($compliance_items);
$compliance_score = $total_items > 0 ? round(($completed_count / $total_items) * 100) : 0;

include '../../includes/header.php';
?>

<style>
.comp-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #f0f0f0;
}
.comp-stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.comp-stat-val  { font-size: 1.7rem; font-weight: 700; color: #1e293b; }
.comp-stat-lbl  { font-size: 0.82rem; color: #64748b; margin-top: 2px; }
.score-ring-wrap { display: flex; align-items: center; gap: 20px; }
.score-circle {
    width: 90px; height: 90px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
}
.checklist-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border-radius: 8px; margin-bottom: 6px;
    background: #f8fafc; border: 1px solid #e8edf2;
}
.checklist-item:last-child { margin-bottom: 0; }
.badge-completed    { background: #d1fae5; color: #065f46; }
.badge-pending      { background: #fef3c7; color: #92400e; }
.badge-not_applicable { background: #f1f5f9; color: #475569; }
.alert-banner {
    background: #fef2f2; border: 1px solid #fecaca;
    border-radius: 10px; padding: 12px 16px; margin-bottom: 8px;
    font-size: 0.88rem; color: #991b1b;
}
.section-title {
    font-size: 1rem; font-weight: 700; color: #1e293b;
    margin-bottom: 14px; padding-bottom: 8px;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; gap: 8px;
}
</style>

<div style="padding: 24px;">

    <!-- Page header -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="font-size:1.5rem; font-weight:800; color:#1e293b; margin:0;">
                <i class="fas fa-shield-alt" style="color:#6366f1; margin-right:8px;"></i>
                Compliance &amp; Security Dashboard
            </h1>
            <p style="color:#64748b; font-size:0.88rem; margin:4px 0 0;">HIPAA / Data Protection Compliance Overview</p>
        </div>
        <a href="?export_csv=1&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>&csrf_token=<?php echo htmlspecialchars(generate_csrf_token()); ?>"
           class="btn btn-primary" style="font-size:0.85rem;">
            <i class="fas fa-file-csv"></i> Export Audit Log CSV
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:16px;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- Security alerts banner -->
    <?php if (!empty($sec_alerts)): ?>
    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px; margin-bottom:24px;">
        <div style="font-weight:700; color:#991b1b; margin-bottom:8px;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo count($sec_alerts); ?> Security Alert(s) in the Last 7 Days
        </div>
        <?php foreach (array_slice($sec_alerts, 0, 5) as $al): ?>
        <div class="alert-banner">
            <i class="fas fa-circle" style="font-size:0.5rem; vertical-align:middle; margin-right:6px;"></i>
            <strong><?php echo htmlspecialchars($al['action']); ?></strong>
            <?php if ($al['user_email']): ?> &mdash; <?php echo htmlspecialchars($al['user_email']); ?><?php endif; ?>
            <?php if ($al['ip_address']): ?> &mdash; IP: <?php echo htmlspecialchars($al['ip_address']); ?><?php endif; ?>
            <span style="float:right; color:#b91c1c; font-size:0.82rem;"><?php echo htmlspecialchars(date('d M Y H:i', strtotime($al['created_at']))); ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (count($sec_alerts) > 5): ?>
            <p style="font-size:0.82rem; color:#991b1b; margin:4px 0 0;">... and <?php echo count($sec_alerts) - 5; ?> more. See the audit log below.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stat cards + Score -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:16px; margin-bottom:24px;">

        <!-- Compliance Score -->
        <div class="comp-stat-card" style="grid-column: span 1;">
            <div class="score-circle"
                 style="background: <?php echo $compliance_score >= 80 ? '#10b981' : ($compliance_score >= 60 ? '#f59e0b' : '#ef4444'); ?>;">
                <?php echo $compliance_score; ?>%
            </div>
            <div>
                <div class="comp-stat-val"><?php echo $completed_count; ?>/<?php echo $total_items; ?></div>
                <div class="comp-stat-lbl">Compliance Score</div>
                <div style="font-size:0.78rem; color: <?php echo $compliance_score >= 80 ? '#10b981' : ($compliance_score >= 60 ? '#f59e0b' : '#ef4444'); ?>; font-weight:600; margin-top:4px;">
                    <?php echo $compliance_score >= 80 ? 'Good Standing' : ($compliance_score >= 60 ? 'Needs Attention' : 'Critical'); ?>
                </div>
            </div>
        </div>

        <div class="comp-stat-card">
            <div class="comp-stat-icon" style="background:#ede9fe;">
                <i class="fas fa-list-alt" style="color:#7c3aed;"></i>
            </div>
            <div>
                <div class="comp-stat-val"><?php echo number_format((int)($total_audit['cnt'] ?? 0)); ?></div>
                <div class="comp-stat-lbl">Total Audit Events</div>
            </div>
        </div>

        <div class="comp-stat-card">
            <div class="comp-stat-icon" style="background:#dbeafe;">
                <i class="fas fa-eye" style="color:#2563eb;"></i>
            </div>
            <div>
                <div class="comp-stat-val"><?php echo number_format((int)($data_access['cnt'] ?? 0)); ?></div>
                <div class="comp-stat-lbl">Data Access Events</div>
            </div>
        </div>

        <div class="comp-stat-card">
            <div class="comp-stat-icon" style="background:#d1fae5;">
                <i class="fas fa-lock" style="color:#059669;"></i>
            </div>
            <div>
                <div class="comp-stat-val"><?php echo number_format((int)($security_ev['cnt'] ?? 0)); ?></div>
                <div class="comp-stat-lbl">Security Events</div>
            </div>
        </div>

        <div class="comp-stat-card">
            <div class="comp-stat-icon" style="background:#fee2e2;">
                <i class="fas fa-ban" style="color:#dc2626;"></i>
            </div>
            <div>
                <div class="comp-stat-val"><?php echo number_format((int)($policy_viol['cnt'] ?? 0)); ?></div>
                <div class="comp-stat-lbl">Policy Violations</div>
            </div>
        </div>
    </div>

    <!-- Main grid: checklist + audit log -->
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px; align-items:start;">

        <!-- Compliance Checklist -->
        <div class="card" style="padding:20px;">
            <div class="section-title">
                <i class="fas fa-clipboard-check" style="color:#6366f1;"></i> Compliance Checklist
            </div>
            <?php foreach ($compliance_items as $ci):
                $status_label = $ci['effective_status'] === 'completed' ? 'Completed'
                    : ($ci['effective_status'] === 'not_applicable' ? 'N/A' : 'Pending');
                $badge_cls = 'badge-' . $ci['effective_status'];
                $icon = $ci['effective_status'] === 'completed' ? 'fa-check-circle text-success' : 'fa-clock';
                $icon_color = $ci['effective_status'] === 'completed' ? '#10b981' : ($ci['effective_status'] === 'not_applicable' ? '#94a3b8' : '#f59e0b');
            ?>
            <div class="checklist-item">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas <?php echo $icon; ?>" style="color:<?php echo $icon_color; ?>; font-size:1rem;"></i>
                    <span style="font-size:0.88rem; color:#374151; font-weight:500;">
                        <?php echo htmlspecialchars($ci['item_name']); ?>
                    </span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="badge <?php echo $badge_cls; ?>" style="font-size:0.75rem; padding:3px 8px; border-radius:999px;">
                        <?php echo $status_label; ?>
                    </span>
                    <?php if (!$ci['system_confirmed']): ?>
                    <button class="btn btn-primary" style="padding:3px 8px; font-size:0.75rem;"
                            onclick="openItemModal('<?php echo htmlspecialchars($ci['db_row']['id'] ?? ''); ?>',
                                                   '<?php echo addslashes(htmlspecialchars($ci['item_name'])); ?>',
                                                   '<?php echo htmlspecialchars($ci['effective_status']); ?>',
                                                   '<?php echo addslashes(htmlspecialchars($ci['db_row']['notes'] ?? '')); ?>')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Audit Log section -->
        <div class="card" style="padding:20px;">
            <div class="section-title">
                <i class="fas fa-history" style="color:#6366f1;"></i> Recent Audit Logs
            </div>

            <!-- Date filter -->
            <form method="GET" action="" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; align-items:flex-end;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">From Date</label>
                    <input type="date" name="from_date" class="form-control" style="font-size:0.85rem;"
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">To Date</label>
                    <input type="date" name="to_date" class="form-control" style="font-size:0.85rem;"
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="font-size:0.85rem;">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </form>

            <div class="table-responsive">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left;">
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">User</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Action</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Details</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">IP Address</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($audit_logs)): ?>
                            <tr><td colspan="5" style="padding:14px 12px; color:#94a3b8; text-align:center;">No audit events in the selected date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($audit_logs as $log):
                                $is_alert = preg_match('/fail|unauthorized|error|denied|violation/i', $log['action'] ?? '');
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9; <?php echo $is_alert ? 'background:#fff7f7;' : ''; ?>">
                                <td style="padding:9px 12px;">
                                    <span style="font-size:0.82rem; color:#374151;">
                                        <?php echo htmlspecialchars($log['user_email'] ?? 'System'); ?>
                                    </span>
                                </td>
                                <td style="padding:9px 12px;">
                                    <span style="<?php echo $is_alert ? 'color:#dc2626; font-weight:600;' : 'color:#374151;'; ?>">
                                        <?php echo htmlspecialchars($log['action'] ?? ''); ?>
                                    </span>
                                </td>
                                <td style="padding:9px 12px; color:#64748b; max-width:220px; word-break:break-word;">
                                    <?php echo htmlspecialchars(mb_strimwidth($log['details'] ?? '', 0, 80, '...')); ?>
                                </td>
                                <td style="padding:9px 12px; font-family:monospace; font-size:0.8rem; color:#64748b;">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?>
                                </td>
                                <td style="padding:9px 12px; white-space:nowrap; color:#64748b; font-size:0.82rem;">
                                    <?php echo htmlspecialchars(date('d M Y H:i', strtotime($log['created_at']))); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /main grid -->

</div><!-- /padding wrapper -->

<!-- ─── Update Compliance Item Modal ─────────────────────────────────────────── -->
<div id="itemModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:14px; padding:28px 32px; width:440px; max-width:95%; box-shadow:0 20px 60px rgba(0,0,0,0.18);">
        <h3 style="margin:0 0 18px; font-size:1.1rem; color:#1e293b;">
            <i class="fas fa-edit" style="color:#6366f1;"></i> Update Compliance Item
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="update_item" value="1">
            <input type="hidden" name="item_id" id="modal_item_id">

            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600; color:#374151; font-size:0.88rem;">Item</label>
                <input type="text" name="item_name" id="modal_item_name" class="form-control" readonly
                       style="background:#f8fafc; font-size:0.88rem;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600; color:#374151; font-size:0.88rem;">Status</label>
                <select name="status" id="modal_status" class="form-control" style="font-size:0.88rem;">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="not_applicable">Not Applicable</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-weight:600; color:#374151; font-size:0.88rem;">Notes</label>
                <textarea name="notes" id="modal_notes" class="form-control" rows="3"
                          style="font-size:0.88rem;" placeholder="Optional notes..."></textarea>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeItemModal()"
                        class="btn" style="background:#f1f5f9; color:#374151;">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openItemModal(id, name, status, notes) {
    document.getElementById('modal_item_id').value   = id;
    document.getElementById('modal_item_name').value = name;
    document.getElementById('modal_notes').value     = notes;
    var sel = document.getElementById('modal_status');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === status) { sel.selectedIndex = i; break; }
    }
    var modal = document.getElementById('itemModal');
    modal.style.display = 'flex';
}
function closeItemModal() {
    document.getElementById('itemModal').style.display = 'none';
}
document.getElementById('itemModal').addEventListener('click', function(e) {
    if (e.target === this) closeItemModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
