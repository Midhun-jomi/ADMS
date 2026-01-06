<?php
// modules/insurance/providers.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Insurance Providers";
include '../../includes/header.php';

$success = '';
$error = '';

// Handle Add Provider
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_provider'])) {
    $name = $_POST['name'];
    $type = $_POST['coverage_type'];
    $contact = $_POST['contact_info'];
    
    if ($name) {
        db_insert('insurance_providers', [
            'name' => $name,
            'coverage_type' => $type,
            'contact_info' => $contact
        ]);
        $success = "Provider added successfully.";
    } else {
        $error = "Provider name is required.";
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    db_delete('insurance_providers', ['id' => $_GET['delete']]);
    header("Location: providers.php");
    exit();
}

$providers = db_select("SELECT * FROM insurance_providers ORDER BY name");
?>

<div class="card">
    <div class="card-header">Manage Insurance Providers</div>
    <div class="card-body">
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST" action="" style="display: flex; gap: 10px; align-items: flex-end; margin-bottom: 20px;">
            <div style="flex: 2;">
                <label>Provider Name</label>
                <input type="text" name="name" class="form-control" required placeholder="e.g. Blue Cross">
            </div>
            <div style="flex: 1;">
                <label>Coverage Type</label>
                <select name="coverage_type" class="form-control">
                    <option value="Private">Private</option>
                    <option value="Public">Public</option>
                    <option value="Corporate">Corporate</option>
                </select>
            </div>
            <div style="flex: 2;">
                <label>Contact Info</label>
                <input type="text" name="contact_info" class="form-control" placeholder="Phone or Email">
            </div>
            <button type="submit" name="add_provider" class="btn btn-primary">Add Provider</button>
        </form>

        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f9fa; text-align: left;">
                    <th style="padding: 10px;">Name</th>
                    <th style="padding: 10px;">Type</th>
                    <th style="padding: 10px;">Contact</th>
                    <th style="padding: 10px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px;"><?php echo htmlspecialchars($p['name']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($p['coverage_type']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($p['contact_info']); ?></td>
                        <td style="padding: 10px;">
                            <a href="?delete=<?php echo $p['id']; ?>" class="btn btn-sm" style="background: #dc3545; color: white;" onclick="return confirm('Delete this provider?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
