<?php
// modules/admin/departments.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Department Management";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle Add Department
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_department'])) {
    $name = trim($_POST['name']);
    $head = trim($_POST['head_of_dept']);
    $desc = trim($_POST['description']);
    $status = $_POST['status'];

    if (empty($name)) {
        $error = "Department name is required.";
    } else {
        // Check duplicate
        $exists = db_select_one("SELECT id FROM departments WHERE name = $1", [$name]);
        if ($exists) {
            $error = "Department '$name' already exists.";
        } else {
            try {
                db_insert('departments', [
                    'name' => $name,
                    'head_of_dept' => $head,
                    'description' => $desc,
                    'status' => $status
                ]);
                $success = "Department added successfully.";
            } catch (Exception $e) {
                $error = "Failed to add department: " . $e->getMessage();
            }
        }
    }
}

// Fetch Departments
$departments = db_select("SELECT * FROM departments ORDER BY name ASC");
?>

<div class="row">
    <!-- Add Department Form -->
    <div class="col-md-5 mb-4">
        <div class="card-header-styled">
            <i class="fas fa-building"></i>
            <h2>Departments</h2>
            <p>Manage hospital departments and units.</p>
        </div>

        <div class="staff-form-container">
            <h4 class="mb-4"><i class="fas fa-plus-circle"></i> Add New Department</h4>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="staff-grid-form" style="display: block;">
                <input type="hidden" name="add_department" value="1">
                
                <div class="form-group mb-3">
                    <label>Department Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Cardiology" required>
                </div>

                <div class="form-group mb-3">
                    <label>Head of Department</label>
                    <input type="text" name="head_of_dept" class="form-control" placeholder="Name">
                </div>

                <div class="form-group mb-3">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Brief description..."></textarea>
                </div>

                <div class="form-group mb-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="width: 100%;">
                    <i class="fas fa-save"></i> Save Department
                </button>
            </form>
        </div>
    </div>

    <!-- Department List -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h3>Department Directory</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr style="background-color: #f8f9fa;">
                            <th style="padding: 15px;">Department Name</th>
                            <th style="padding: 15px;">Head</th>
                            <th style="padding: 15px;">Status</th>
                            <th style="padding: 15px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departments)): ?>
                            <tr><td colspan="4" class="text-center p-4">No departments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($departments as $d): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 15px;">
                                        <strong><?php echo htmlspecialchars($d['name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($d['description'] ?? '', 0, 50)); ?>...</small>
                                    </td>
                                    <td style="padding: 15px;"><?php echo htmlspecialchars($d['head_of_dept'] ?: '-'); ?></td>
                                    <td style="padding: 15px;">
                                        <?php if ($d['status'] === 'active'): ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 15px;">
                                        <a href="edit_department.php?id=<?php echo $d['id']; ?>" class="btn btn-sm btn-outline-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    /* Reuse premium styles */
    .card-header-styled {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); /* Greenish gradient for depts */
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(56, 239, 125, 0.2);
    }
    .card-header-styled i { font-size: 40px; margin-bottom: 10px; opacity: 0.8; }
    .card-header-styled h2 { margin: 0; font-weight: 700; }
    
    .staff-form-container {
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #344767;
    }
    .form-control {
        width: 100%;
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        transition: all 0.2s;
        box-sizing: border-box; /* Fix overlay overlap */
    }
    .form-control:focus {
        border-color: #38ef7d;
        outline: none;
    }
     
    /* Force full width for this page */
    .content-wrapper {
        max-width: 100% !important;
        width: 100% !important;
        padding-right: 30px;
    }
    .row {
        margin-right: 0;
        margin-left: 0;
    }
</style>

<?php include '../../includes/footer.php'; ?>
