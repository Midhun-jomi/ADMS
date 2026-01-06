<?php
// modules/admin/edit_department.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Edit Department";
include '../../includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>Invalid Department ID.</div>";
    include '../../includes/footer.php';
    exit;
}

$dept = db_select_one("SELECT * FROM departments WHERE id = $1", [$id]);
if (!$dept) {
    echo "<div class='alert alert-danger'>Department not found.</div>";
    include '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $head = trim($_POST['head_of_dept']);
    $desc = trim($_POST['description']);
    $status = $_POST['status'];

    if (empty($name)) {
        $error = "Department name is required.";
    } else {
        try {
            db_update('departments', [
                'name' => $name,
                'head_of_dept' => $head,
                'description' => $desc,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);
            
            $success = "Department updated successfully.";
            $dept = db_select_one("SELECT * FROM departments WHERE id = $1", [$id]); // Refresh
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                Edit Department: <strong><?php echo htmlspecialchars($dept['name']); ?></strong>
                <a href="departments.php" class="btn btn-sm btn-secondary float-right">Back</a>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group mb-3">
                        <label>Department Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($dept['name']); ?>" required>
                    </div>

                    <div class="form-group mb-3">
                        <label>Head of Department</label>
                        <input type="text" name="head_of_dept" class="form-control" value="<?php echo htmlspecialchars($dept['head_of_dept']); ?>">
                    </div>

                    <div class="form-group mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($dept['description']); ?></textarea>
                    </div>

                    <div class="form-group mb-4">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?php echo $dept['status']=='active'?'selected':''; ?>>Active</option>
                            <option value="inactive" <?php echo $dept['status']=='inactive'?'selected':''; ?>>Inactive</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Update Department</button>
                    <a href="departments.php" class="btn btn-link text-muted">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
