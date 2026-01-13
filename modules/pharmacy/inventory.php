<?php
// modules/pharmacy/inventory.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin']);

$page_title = "Pharmacy Inventory";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle Add/Update Stock
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $medication_name = $_POST['medication_name'];
    $quantity = $_POST['quantity'];
    $unit_price = $_POST['unit_price'];
    $expiry_date = $_POST['expiry_date'];
    
    // Check if exists
    $existing = db_select_one("SELECT id, quantity FROM pharmacy_inventory WHERE medication_name = $1", [$medication_name]);
    
    if ($existing) {
        // Update quantity
        $new_qty = $existing['quantity'] + $quantity;
        db_update('pharmacy_inventory', 
                  ['quantity' => $new_qty, 'unit_price' => $unit_price, 'expiry_date' => $expiry_date], 
                  ['id' => $existing['id']]);
        $success = "Stock updated successfully.";
    } else {
        // Insert new
        $data = [
            'medication_name' => $medication_name,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'expiry_date' => $expiry_date
        ];
        db_insert('pharmacy_inventory', $data);
        $success = "New medication added successfully.";
    }
}

// Fetch Inventory
$inventory = db_select("SELECT * FROM pharmacy_inventory ORDER BY medication_name ASC");
?>

<div class="card">
    <div class="card-header">Inventory Management</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
        <h5>Add / Update Stock</h5>
        <form method="POST" action="" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
            <div style="flex: 2; min-width: 200px;">
                <label>Medication Name</label>
                <input type="text" name="medication_name" class="form-control" required>
            </div>
            <div style="flex: 1; min-width: 100px;">
                <label>Quantity</label>
                <input type="number" name="quantity" class="form-control" required>
            </div>
            <div style="flex: 1; min-width: 100px;">
                <label>Unit Price (₹)</label>
                <input type="number" step="0.01" name="unit_price" class="form-control" required>
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label>Expiry Date</label>
                <input type="date" name="expiry_date" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Add Stock</button>
        </form>
    </div>

    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Medication</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Stock</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Price</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Expiry</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $item): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px;"><?php echo htmlspecialchars($item['medication_name']); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($item['quantity']); ?></td>
                    <td style="padding: 10px;">₹<?php echo htmlspecialchars($item['unit_price']); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($item['expiry_date']); ?></td>
                    <td style="padding: 10px;">
                        <?php if ($item['quantity'] < 10): ?>
                            <span style="color: red; font-weight: bold;">Low Stock</span>
                        <?php else: ?>
                            <span style="color: green;">In Stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
