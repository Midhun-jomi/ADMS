<?php
// modules/procurement/purchase_orders.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Purchase Orders & Vendors";
include '../../includes/header.php';

$error   = '';
$success = '';

// ─── POST HANDLERS ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {

        $action = $_POST['action'] ?? '';

        // ── Add Vendor ──────────────────────────────────────────────────────
        if ($action === 'add_vendor') {
            $vendor_name    = trim($_POST['vendor_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $address        = trim($_POST['address'] ?? '');
            $category       = $_POST['category'] ?? '';
            $notes          = trim($_POST['notes'] ?? '');

            if ($vendor_name === '' || $category === '') {
                $error = "Vendor name and category are required.";
            } else {
                try {
                    db_query(
                        "INSERT INTO vendors (vendor_name, contact_person, phone, email, address, category, notes, status)
                         VALUES ($1,$2,$3,$4,$5,$6,$7,'active')",
                        [$vendor_name, $contact_person, $phone, $email, $address, $category, $notes]
                    );
                    $success = "Vendor \"" . htmlspecialchars($vendor_name) . "\" added successfully.";
                } catch (Exception $e) {
                    $error = "Failed to add vendor: " . $e->getMessage();
                }
            }
        }

        // ── Edit Vendor ─────────────────────────────────────────────────────
        elseif ($action === 'edit_vendor') {
            $vendor_id      = trim($_POST['vendor_id'] ?? '');
            $vendor_name    = trim($_POST['vendor_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone          = trim($_POST['phone'] ?? '');
            $email          = trim($_POST['email'] ?? '');
            $address        = trim($_POST['address'] ?? '');
            $category       = $_POST['category'] ?? '';
            $notes          = trim($_POST['notes'] ?? '');

            if ($vendor_id === '' || $vendor_name === '') {
                $error = "Vendor ID and name are required.";
            } else {
                try {
                    db_query(
                        "UPDATE vendors SET vendor_name=$1, contact_person=$2, phone=$3, email=$4,
                         address=$5, category=$6, notes=$7 WHERE id=$8",
                        [$vendor_name, $contact_person, $phone, $email, $address, $category, $notes, $vendor_id]
                    );
                    $success = "Vendor updated successfully.";
                } catch (Exception $e) {
                    $error = "Failed to update vendor: " . $e->getMessage();
                }
            }
        }

        // ── Toggle Vendor Status ────────────────────────────────────────────
        elseif ($action === 'toggle_vendor_status') {
            $vendor_id = trim($_POST['vendor_id'] ?? '');
            if ($vendor_id !== '') {
                try {
                    db_query(
                        "UPDATE vendors SET status = CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=$1",
                        [$vendor_id]
                    );
                    $success = "Vendor status updated.";
                } catch (Exception $e) {
                    $error = "Failed to toggle status: " . $e->getMessage();
                }
            }
        }

        // ── Delete Vendor ───────────────────────────────────────────────────
        elseif ($action === 'delete_vendor') {
            $vendor_id = trim($_POST['vendor_id'] ?? '');
            if ($vendor_id !== '') {
                try {
                    // Prevent delete if POs reference this vendor
                    $ref = db_select_one("SELECT id FROM purchase_orders WHERE vendor_id=$1 LIMIT 1", [$vendor_id]);
                    if ($ref) {
                        $error = "Cannot delete vendor — purchase orders are linked to them.";
                    } else {
                        db_query("DELETE FROM vendors WHERE id=$1", [$vendor_id]);
                        $success = "Vendor deleted successfully.";
                    }
                } catch (Exception $e) {
                    $error = "Failed to delete vendor: " . $e->getMessage();
                }
            }
        }

        // ── Create Purchase Order ───────────────────────────────────────────
        elseif ($action === 'create_po') {
            $vendor_id         = trim($_POST['vendor_id'] ?? '');
            $item_name         = trim($_POST['item_name'] ?? '');
            $category          = $_POST['po_category'] ?? '';
            $quantity          = (int)($_POST['quantity'] ?? 0);
            $unit_price        = (float)($_POST['unit_price'] ?? 0);
            $expected_delivery = trim($_POST['expected_delivery'] ?? '');
            $priority          = $_POST['priority'] ?? 'normal';
            $notes             = trim($_POST['notes'] ?? '');
            $total_amount      = $quantity * $unit_price;
            $user_id           = get_user_id();

            if ($vendor_id === '' || $item_name === '' || $quantity <= 0 || $unit_price <= 0 || $expected_delivery === '') {
                $error = "All required fields must be filled correctly.";
            } else {
                try {
                    db_query(
                        "INSERT INTO purchase_orders
                         (vendor_id, item_name, category, quantity, unit_price, total_amount, expected_delivery, priority, notes, status, created_by)
                         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,'draft',$10)",
                        [$vendor_id, $item_name, $category, $quantity, $unit_price, $total_amount,
                         $expected_delivery, $priority, $notes, $user_id]
                    );
                    $success = "Purchase Order created successfully (status: Draft).";
                } catch (Exception $e) {
                    $error = "Failed to create PO: " . $e->getMessage();
                }
            }
        }

        // ── Update PO Status ────────────────────────────────────────────────
        elseif ($action === 'update_po_status') {
            $po_id      = trim($_POST['po_id'] ?? '');
            $new_status = $_POST['new_status'] ?? '';
            $user_id    = get_user_id();

            $valid_transitions = [
                'draft'            => 'pending_approval',
                'pending_approval' => 'approved',
                'approved'         => 'ordered',
                'ordered'          => 'delivered',
            ];
            $cancel_allowed = ['draft', 'pending_approval', 'approved', 'ordered'];

            if ($po_id === '' || $new_status === '') {
                $error = "Invalid request.";
            } else {
                try {
                    $po = db_select_one("SELECT status FROM purchase_orders WHERE id=$1", [$po_id]);
                    if (!$po) {
                        $error = "Purchase order not found.";
                    } else {
                        $current = $po['status'];
                        $ok = ($new_status === 'cancelled' && in_array($current, $cancel_allowed))
                           || (isset($valid_transitions[$current]) && $valid_transitions[$current] === $new_status);
                        if (!$ok) {
                            $error = "Invalid status transition from \"$current\" to \"$new_status\".";
                        } else {
                            if ($new_status === 'approved') {
                                db_query(
                                    "UPDATE purchase_orders SET status=$1, approved_by=$2, approved_at=NOW() WHERE id=$3",
                                    [$new_status, $user_id, $po_id]
                                );
                            } else {
                                db_query("UPDATE purchase_orders SET status=$1 WHERE id=$2", [$new_status, $po_id]);
                            }
                            $success = "Purchase Order status updated to \"" . htmlspecialchars(ucfirst(str_replace('_', ' ', $new_status))) . "\".";
                        }
                    }
                } catch (Exception $e) {
                    $error = "Failed to update PO: " . $e->getMessage();
                }
            }
        }

    } // end CSRF block
}

// ─── FETCH DATA ───────────────────────────────────────────────────────────────

$vendors = db_select("SELECT * FROM vendors ORDER BY vendor_name ASC");

$purchase_orders = db_select("
    SELECT po.*,
           v.vendor_name,
           u.email AS created_by_email,
           a.email AS approved_by_email
    FROM purchase_orders po
    LEFT JOIN vendors v       ON po.vendor_id    = v.id
    LEFT JOIN users   u       ON po.created_by   = u.id
    LEFT JOIN users   a       ON po.approved_by  = a.id
    ORDER BY po.created_at DESC
");

// Stats
$stat_total     = count($purchase_orders);
$stat_pending   = count(array_filter($purchase_orders, fn($p) => $p['status'] === 'pending_approval'));
$stat_approved  = count(array_filter($purchase_orders, fn($p) => $p['status'] === 'approved'));
$stat_delivered = count(array_filter($purchase_orders, fn($p) => $p['status'] === 'delivered'));
$stat_value     = array_sum(array_column($purchase_orders, 'total_amount'));

// Active vendors for PO dropdown
$active_vendors = db_select("SELECT id, vendor_name FROM vendors WHERE status='active' ORDER BY vendor_name ASC");
?>

<style>
/* ── Layout ─────────────────────────────────────────── */
.po-main { padding: 24px; max-width: 1400px; margin: 0 auto; }
.po-page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.po-page-header h1 { margin: 0; font-size: 1.6rem; color: #111827; }
.po-page-header p  { margin: 4px 0 0; color: #6b7280; font-size: 0.9em; }
.po-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Stats ──────────────────────────────────────────── */
.po-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 16px; margin-bottom: 24px; }
.po-stat-card {
    background: #fff; border-radius: 12px; padding: 18px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07); border: 1px solid #f3f4f6;
    display: flex; align-items: center; gap: 14px;
}
.po-stat-icon {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
}
.po-stat-card h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #111827; }
.po-stat-card p  { margin: 2px 0 0; font-size: 0.78rem; color: #6b7280; }

/* ── Tabs ───────────────────────────────────────────── */
.po-tab-bar { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; }
.po-tab-btn {
    padding: 10px 20px; background: none; border: none; cursor: pointer;
    font-size: 0.92rem; font-weight: 500; color: #6b7280; border-bottom: 2px solid transparent;
    margin-bottom: -2px; transition: color .2s, border-color .2s; border-radius: 4px 4px 0 0;
}
.po-tab-btn:hover  { color: #374151; }
.po-tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; background: #eff6ff; }
.po-tab-content    { display: none; }
.po-tab-content.active { display: block; }

/* ── Card ───────────────────────────────────────────── */
.po-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 20px;
}
.po-card-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; gap: 10px;
}
.po-card-header h4 { margin: 0; font-size: 1rem; color: #111827; }
.po-card-body { padding: 20px; }

/* ── Table ──────────────────────────────────────────── */
.po-table-wrap { overflow-x: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.po-table thead th {
    background: #f9fafb; padding: 10px 12px; text-align: left;
    font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
}
.po-table tbody tr:hover { background: #f9fafb; }
.po-table tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

/* ── Badges ─────────────────────────────────────────── */
.po-badge {
    display: inline-block; padding: 3px 9px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 600; text-transform: capitalize; white-space: nowrap;
}
.badge-draft             { background: #f3f4f6; color: #374151; }
.badge-pending_approval  { background: #fef3c7; color: #92400e; }
.badge-approved          { background: #d1fae5; color: #065f46; }
.badge-ordered           { background: #dbeafe; color: #1e40af; }
.badge-delivered         { background: #dcfce7; color: #166534; }
.badge-cancelled         { background: #fee2e2; color: #991b1b; }
.badge-active            { background: #d1fae5; color: #065f46; }
.badge-inactive          { background: #fee2e2; color: #991b1b; }
.badge-normal            { background: #e5e7eb; color: #374151; }
.badge-urgent            { background: #fef3c7; color: #92400e; }

/* ── Alerts ─────────────────────────────────────────── */
.po-alert {
    padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px; font-size: 0.9rem;
}
.po-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.po-alert-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Search ─────────────────────────────────────────── */
.po-search {
    padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 0.875rem; outline: none; width: 260px; max-width: 100%;
}
.po-search:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.15); }

/* ── Modal ──────────────────────────────────────────── */
.po-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1050; overflow-y: auto;
    padding: 40px 16px;
}
.po-modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; }
.po-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 580px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: poSlideIn .2s ease;
}
@keyframes poSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.po-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 22px; border-bottom: 1px solid #e5e7eb;
}
.po-modal-header h5 { margin: 0; font-size: 1.1rem; font-weight: 600; color: #111827; }
.po-modal-close {
    background: none; border: none; cursor: pointer; font-size: 1.2rem;
    color: #6b7280; line-height: 1; padding: 4px; border-radius: 6px;
}
.po-modal-close:hover { background: #f3f4f6; color: #111827; }
.po-modal-body { padding: 22px; }
.po-modal-footer {
    padding: 16px 22px; border-top: 1px solid #e5e7eb;
    display: flex; justify-content: flex-end; gap: 10px;
}
.po-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.po-form-row.single { grid-template-columns: 1fr; }
.po-form-group { display: flex; flex-direction: column; gap: 5px; }
.po-form-group label { font-size: 0.82rem; font-weight: 600; color: #374151; }
.po-form-group input,
.po-form-group select,
.po-form-group textarea {
    padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 0.875rem; outline: none; font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
}
.po-form-group input:focus,
.po-form-group select:focus,
.po-form-group textarea:focus {
    border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.15);
}
.po-form-group textarea { resize: vertical; min-height: 80px; }

/* ── View Modal ─────────────────────────────────────── */
.po-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.po-detail-item label { font-size: 0.78rem; color: #6b7280; display: block; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.po-detail-item span  { font-size: 0.9rem; color: #111827; display: block; margin-top: 2px; }

/* ── Buttons ─────────────────────────────────────────── */
.po-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;
    font-size: 0.875rem; font-weight: 500; transition: opacity .2s, transform .1s; text-decoration: none;
}
.po-btn:hover { opacity: .88; transform: translateY(-1px); }
.po-btn-primary   { background: #2563eb; color: #fff; }
.po-btn-success   { background: #16a34a; color: #fff; }
.po-btn-warning   { background: #d97706; color: #fff; }
.po-btn-danger    { background: #dc2626; color: #fff; }
.po-btn-secondary { background: #6b7280; color: #fff; }
.po-btn-ghost     { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.po-btn-sm { padding: 5px 10px; font-size: 0.78rem; }

/* ── Empty State ─────────────────────────────────────── */
.po-empty { text-align: center; padding: 48px 20px; color: #9ca3af; }
.po-empty i { font-size: 2.5rem; margin-bottom: 10px; display: block; }
.po-empty p { margin: 0; font-size: 0.9rem; }

@media (max-width: 640px) {
    .po-form-row { grid-template-columns: 1fr; }
    .po-detail-grid { grid-template-columns: 1fr; }
    .po-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="po-main">

    <!-- Page Header -->
    <div class="po-page-header">
        <div>
            <h1><i class="fas fa-file-invoice" style="color:#2563eb;margin-right:8px;"></i>Purchase Orders &amp; Vendors</h1>
            <p>Manage procurement, purchase orders and vendor relationships</p>
        </div>
        <div class="po-header-actions">
            <button class="po-btn po-btn-primary" onclick="poOpenModal('addVendorModal')">
                <i class="fas fa-building"></i> Add Vendor
            </button>
            <button class="po-btn po-btn-success" onclick="poOpenModal('createPOModal')">
                <i class="fas fa-plus"></i> Create PO
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="po-alert po-alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="po-alert po-alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Stats (Purchase Orders section) -->
    <div class="po-stats">
        <div class="po-stat-card">
            <div class="po-stat-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-file-alt"></i></div>
            <div><h3><?php echo $stat_total; ?></h3><p>Total POs</p></div>
        </div>
        <div class="po-stat-card">
            <div class="po-stat-icon" style="background:#fef3c7;color:#92400e;"><i class="fas fa-hourglass-half"></i></div>
            <div><h3><?php echo $stat_pending; ?></h3><p>Pending Approval</p></div>
        </div>
        <div class="po-stat-card">
            <div class="po-stat-icon" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i></div>
            <div><h3><?php echo $stat_approved; ?></h3><p>Approved</p></div>
        </div>
        <div class="po-stat-card">
            <div class="po-stat-icon" style="background:#dcfce7;color:#166534;"><i class="fas fa-truck"></i></div>
            <div><h3><?php echo $stat_delivered; ?></h3><p>Delivered</p></div>
        </div>
        <div class="po-stat-card">
            <div class="po-stat-icon" style="background:#f3e8ff;color:#6b21a8;"><i class="fas fa-rupee-sign"></i></div>
            <div><h3>₹<?php echo number_format($stat_value, 2); ?></h3><p>Total Value</p></div>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="po-tab-bar">
        <button class="po-tab-btn active" data-tab="tab-po">
            <i class="fas fa-file-invoice" style="margin-right:6px;"></i>Purchase Orders
        </button>
        <button class="po-tab-btn" data-tab="tab-vendors">
            <i class="fas fa-building" style="margin-right:6px;"></i>Vendors
        </button>
    </div>

    <!-- ── Tab: Purchase Orders ─────────────────────────────────────────────── -->
    <div id="tab-po" class="po-tab-content active">
        <div class="po-card">
            <div class="po-card-header">
                <h4><i class="fas fa-list" style="margin-right:6px;color:#2563eb;"></i>All Purchase Orders</h4>
                <input type="text" class="po-search" id="filter-po" placeholder="Search POs..." onkeyup="filterTable('filter-po','tbl-po')">
            </div>
            <?php if (empty($purchase_orders)): ?>
                <div class="po-empty">
                    <i class="fas fa-file-alt"></i>
                    <p>No purchase orders found. Click "Create PO" to get started.</p>
                </div>
            <?php else: ?>
            <div class="po-table-wrap">
                <table id="tbl-po" class="po-table">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Vendor</th>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Total (₹)</th>
                            <th>Priority</th>
                            <th>Exp. Delivery</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($purchase_orders as $po):
                        $s = $po['status'];
                    ?>
                        <tr>
                            <td><strong>#<?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($po['vendor_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($po['item_name']); ?></td>
                            <td><?php echo (int)$po['quantity']; ?></td>
                            <td>₹<?php echo number_format((float)$po['unit_price'], 2); ?></td>
                            <td><strong>₹<?php echo number_format((float)$po['total_amount'], 2); ?></strong></td>
                            <td><span class="po-badge badge-<?php echo htmlspecialchars($po['priority']); ?>"><?php echo htmlspecialchars(ucfirst($po['priority'])); ?></span></td>
                            <td><?php echo htmlspecialchars($po['expected_delivery'] ?? '—'); ?></td>
                            <td><span class="po-badge badge-<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $s))); ?></span></td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                    <!-- View -->
                                    <button class="po-btn po-btn-ghost po-btn-sm"
                                        onclick="poViewPO(<?php echo htmlspecialchars(json_encode($po)); ?>)"
                                        title="View"><i class="fas fa-eye"></i></button>

                                    <!-- Submit for approval (draft → pending) -->
                                    <?php if ($s === 'draft'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Submit this PO for approval?');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_po_status">
                                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($po['id']); ?>">
                                        <input type="hidden" name="new_status" value="pending_approval">
                                        <button type="submit" class="po-btn po-btn-warning po-btn-sm" title="Submit for Approval"><i class="fas fa-paper-plane"></i></button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Approve (pending_approval → approved) -->
                                    <?php if ($s === 'pending_approval'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this purchase order?');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_po_status">
                                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($po['id']); ?>">
                                        <input type="hidden" name="new_status" value="approved">
                                        <button type="submit" class="po-btn po-btn-success po-btn-sm" title="Approve"><i class="fas fa-check"></i></button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Mark Ordered (approved → ordered) -->
                                    <?php if ($s === 'approved'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this PO as ordered?');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_po_status">
                                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($po['id']); ?>">
                                        <input type="hidden" name="new_status" value="ordered">
                                        <button type="submit" class="po-btn po-btn-primary po-btn-sm" title="Mark Ordered"><i class="fas fa-shopping-cart"></i></button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Mark Delivered (ordered → delivered) -->
                                    <?php if ($s === 'ordered'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this PO as delivered?');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_po_status">
                                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($po['id']); ?>">
                                        <input type="hidden" name="new_status" value="delivered">
                                        <button type="submit" class="po-btn po-btn-success po-btn-sm" title="Mark Delivered"><i class="fas fa-truck"></i></button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Cancel -->
                                    <?php if (in_array($s, ['draft','pending_approval','approved','ordered'])): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this purchase order?');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="update_po_status">
                                        <input type="hidden" name="po_id" value="<?php echo htmlspecialchars($po['id']); ?>">
                                        <input type="hidden" name="new_status" value="cancelled">
                                        <button type="submit" class="po-btn po-btn-danger po-btn-sm" title="Cancel"><i class="fas fa-times"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Tab: Vendors ─────────────────────────────────────────────────────── -->
    <div id="tab-vendors" class="po-tab-content">
        <div class="po-card">
            <div class="po-card-header">
                <h4><i class="fas fa-building" style="margin-right:6px;color:#2563eb;"></i>Vendor List</h4>
                <input type="text" class="po-search" id="filter-vendors" placeholder="Search vendors..." onkeyup="filterTable('filter-vendors','tbl-vendors')">
            </div>
            <?php if (empty($vendors)): ?>
                <div class="po-empty">
                    <i class="fas fa-building"></i>
                    <p>No vendors added yet. Click "Add Vendor" to register one.</p>
                </div>
            <?php else: ?>
            <div class="po-table-wrap">
                <table id="tbl-vendors" class="po-table">
                    <thead>
                        <tr>
                            <th>Vendor Name</th>
                            <th>Category</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendors as $v): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($v['vendor_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($v['category']); ?></td>
                            <td><?php echo htmlspecialchars($v['contact_person'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($v['phone'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($v['email'] ?: '—'); ?></td>
                            <td>
                                <span class="po-badge badge-<?php echo htmlspecialchars($v['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($v['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                    <!-- Edit -->
                                    <button class="po-btn po-btn-ghost po-btn-sm"
                                        onclick="poEditVendor(<?php echo htmlspecialchars(json_encode($v)); ?>)"
                                        title="Edit"><i class="fas fa-edit"></i></button>

                                    <!-- Toggle Status -->
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="toggle_vendor_status">
                                        <input type="hidden" name="vendor_id" value="<?php echo htmlspecialchars($v['id']); ?>">
                                        <button type="submit" class="po-btn po-btn-sm <?php echo $v['status']==='active' ? 'po-btn-warning' : 'po-btn-success'; ?>"
                                            title="<?php echo $v['status']==='active' ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas fa-<?php echo $v['status']==='active' ? 'ban' : 'check'; ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Delete -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this vendor? This cannot be undone.');">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="delete_vendor">
                                        <input type="hidden" name="vendor_id" value="<?php echo htmlspecialchars($v['id']); ?>">
                                        <button type="submit" class="po-btn po-btn-danger po-btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /po-main -->

<!-- ═══════════════════════════════ MODALS ═══════════════════════════════════ -->

<!-- Add Vendor Modal -->
<div id="addVendorModal" class="po-modal-overlay">
    <div class="po-modal">
        <div class="po-modal-header">
            <h5><i class="fas fa-building" style="margin-right:8px;color:#2563eb;"></i>Add New Vendor</h5>
            <button class="po-modal-close" onclick="poCloseModal('addVendorModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="add_vendor">
            <div class="po-modal-body">
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Vendor Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="vendor_name" placeholder="e.g. MedSupply Co." required>
                    </div>
                    <div class="po-form-group">
                        <label>Category <span style="color:#dc2626;">*</span></label>
                        <select name="category" required>
                            <option value="">-- Select Category --</option>
                            <option value="Medical Supplies">Medical Supplies</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Furniture">Furniture</option>
                            <option value="IT">IT</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" placeholder="Full name">
                    </div>
                    <div class="po-form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" placeholder="+91 98765 43210">
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="vendor@example.com">
                    </div>
                    <div class="po-form-group">
                        <label>Address</label>
                        <input type="text" name="address" placeholder="City, State">
                    </div>
                </div>
                <div class="po-form-row single">
                    <div class="po-form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Additional information..."></textarea>
                    </div>
                </div>
            </div>
            <div class="po-modal-footer">
                <button type="button" class="po-btn po-btn-ghost" onclick="poCloseModal('addVendorModal')">Cancel</button>
                <button type="submit" class="po-btn po-btn-primary"><i class="fas fa-save"></i> Save Vendor</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Vendor Modal -->
<div id="editVendorModal" class="po-modal-overlay">
    <div class="po-modal">
        <div class="po-modal-header">
            <h5><i class="fas fa-edit" style="margin-right:8px;color:#2563eb;"></i>Edit Vendor</h5>
            <button class="po-modal-close" onclick="poCloseModal('editVendorModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="edit_vendor">
            <input type="hidden" name="vendor_id" id="edit_vendor_id">
            <div class="po-modal-body">
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Vendor Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="vendor_name" id="edit_vendor_name" required>
                    </div>
                    <div class="po-form-group">
                        <label>Category <span style="color:#dc2626;">*</span></label>
                        <select name="category" id="edit_vendor_category" required>
                            <option value="Medical Supplies">Medical Supplies</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Furniture">Furniture</option>
                            <option value="IT">IT</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="edit_vendor_contact">
                    </div>
                    <div class="po-form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="edit_vendor_phone">
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="edit_vendor_email">
                    </div>
                    <div class="po-form-group">
                        <label>Address</label>
                        <input type="text" name="address" id="edit_vendor_address">
                    </div>
                </div>
                <div class="po-form-row single">
                    <div class="po-form-group">
                        <label>Notes</label>
                        <textarea name="notes" id="edit_vendor_notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="po-modal-footer">
                <button type="button" class="po-btn po-btn-ghost" onclick="poCloseModal('editVendorModal')">Cancel</button>
                <button type="submit" class="po-btn po-btn-primary"><i class="fas fa-save"></i> Update Vendor</button>
            </div>
        </form>
    </div>
</div>

<!-- Create PO Modal -->
<div id="createPOModal" class="po-modal-overlay">
    <div class="po-modal">
        <div class="po-modal-header">
            <h5><i class="fas fa-file-invoice" style="margin-right:8px;color:#16a34a;"></i>Create Purchase Order</h5>
            <button class="po-modal-close" onclick="poCloseModal('createPOModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="create_po">
            <div class="po-modal-body">
                <div class="po-form-row single">
                    <div class="po-form-group">
                        <label>Vendor <span style="color:#dc2626;">*</span></label>
                        <select name="vendor_id" required>
                            <option value="">-- Select Vendor --</option>
                            <?php foreach ($active_vendors as $av): ?>
                                <option value="<?php echo htmlspecialchars($av['id']); ?>">
                                    <?php echo htmlspecialchars($av['vendor_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Item Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="item_name" placeholder="e.g. Surgical Gloves (Box of 100)" required>
                    </div>
                    <div class="po-form-group">
                        <label>Category</label>
                        <select name="po_category">
                            <option value="">-- Select --</option>
                            <option value="Medical Supplies">Medical Supplies</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Furniture">Furniture</option>
                            <option value="IT">IT</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Quantity <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="quantity" id="po_quantity" min="1" placeholder="0" required oninput="poCalcTotal()">
                    </div>
                    <div class="po-form-group">
                        <label>Unit Price (₹) <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="unit_price" id="po_unit_price" step="0.01" min="0" placeholder="0.00" required oninput="poCalcTotal()">
                    </div>
                </div>
                <div class="po-form-row single">
                    <div class="po-form-group">
                        <label>Total Amount (₹)</label>
                        <input type="text" id="po_total_display" readonly placeholder="Auto-calculated" style="background:#f9fafb;color:#374151;font-weight:600;">
                    </div>
                </div>
                <div class="po-form-row">
                    <div class="po-form-group">
                        <label>Expected Delivery Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="expected_delivery" required>
                    </div>
                    <div class="po-form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="po-form-row single">
                    <div class="po-form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Special instructions or requirements..."></textarea>
                    </div>
                </div>
            </div>
            <div class="po-modal-footer">
                <button type="button" class="po-btn po-btn-ghost" onclick="poCloseModal('createPOModal')">Cancel</button>
                <button type="submit" class="po-btn po-btn-success"><i class="fas fa-file-invoice"></i> Create PO</button>
            </div>
        </form>
    </div>
</div>

<!-- View PO Modal -->
<div id="viewPOModal" class="po-modal-overlay">
    <div class="po-modal">
        <div class="po-modal-header">
            <h5 id="viewPO_title"><i class="fas fa-file-invoice" style="margin-right:8px;color:#2563eb;"></i>Purchase Order Details</h5>
            <button class="po-modal-close" onclick="poCloseModal('viewPOModal')">&times;</button>
        </div>
        <div class="po-modal-body">
            <div class="po-detail-grid" id="viewPO_body"></div>
        </div>
        <div class="po-modal-footer">
            <button class="po-btn po-btn-ghost" onclick="poCloseModal('viewPOModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.po-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.po-tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.po-tab-content').forEach(function(c) { c.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function poOpenModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function poCloseModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }

// Close on overlay click
document.querySelectorAll('.po-modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) poCloseModal(overlay.id);
    });
});

// ── PO Total Calculator ───────────────────────────────────────────────────────
function poCalcTotal() {
    var qty   = parseFloat(document.getElementById('po_quantity').value) || 0;
    var price = parseFloat(document.getElementById('po_unit_price').value) || 0;
    var total = qty * price;
    document.getElementById('po_total_display').value = total > 0 ? '₹' + total.toFixed(2) : '';
}

// ── Edit Vendor ───────────────────────────────────────────────────────────────
function poEditVendor(v) {
    document.getElementById('edit_vendor_id').value       = v.id;
    document.getElementById('edit_vendor_name').value     = v.vendor_name || '';
    document.getElementById('edit_vendor_contact').value  = v.contact_person || '';
    document.getElementById('edit_vendor_phone').value    = v.phone || '';
    document.getElementById('edit_vendor_email').value    = v.email || '';
    document.getElementById('edit_vendor_address').value  = v.address || '';
    document.getElementById('edit_vendor_notes').value    = v.notes || '';
    // Set category select
    var catSel = document.getElementById('edit_vendor_category');
    for (var i = 0; i < catSel.options.length; i++) {
        if (catSel.options[i].value === v.category) { catSel.selectedIndex = i; break; }
    }
    poOpenModal('editVendorModal');
}

// ── View PO ───────────────────────────────────────────────────────────────────
function poViewPO(po) {
    document.getElementById('viewPO_title').innerHTML =
        '<i class="fas fa-file-invoice" style="margin-right:8px;color:#2563eb;"></i>PO #' + escHtml(po.po_number);

    var statusClass = 'badge-' + (po.status || 'draft');
    var priorityClass = 'badge-' + (po.priority || 'normal');

    document.getElementById('viewPO_body').innerHTML =
        poDetailItem('PO Number', '#' + escHtml(po.po_number)) +
        poDetailItem('Vendor', escHtml(po.vendor_name || '—')) +
        poDetailItem('Item', escHtml(po.item_name)) +
        poDetailItem('Category', escHtml(po.category || '—')) +
        poDetailItem('Quantity', escHtml(po.quantity)) +
        poDetailItem('Unit Price', '₹' + parseFloat(po.unit_price).toFixed(2)) +
        poDetailItem('Total Amount', '<strong>₹' + parseFloat(po.total_amount).toFixed(2) + '</strong>') +
        poDetailItem('Priority', '<span class="po-badge ' + priorityClass + '">' + escHtml(po.priority) + '</span>') +
        poDetailItem('Expected Delivery', escHtml(po.expected_delivery || '—')) +
        poDetailItem('Status', '<span class="po-badge ' + statusClass + '">' + escHtml((po.status||'').replace(/_/g,' ')) + '</span>') +
        poDetailItem('Created By', escHtml(po.created_by_email || '—')) +
        poDetailItem('Approved By', escHtml(po.approved_by_email || '—')) +
        poDetailItem('Approved At', escHtml(po.approved_at || '—')) +
        '<div class="po-detail-item" style="grid-column:1/-1;"><label>Notes</label><span>' + escHtml(po.notes || '—') + '</span></div>';

    poOpenModal('viewPOModal');
}

function poDetailItem(label, val) {
    return '<div class="po-detail-item"><label>' + label + '</label><span>' + val + '</span></div>';
}

function escHtml(str) {
    if (str === null || str === undefined) return '—';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>

<?php include '../../includes/footer.php'; ?>
