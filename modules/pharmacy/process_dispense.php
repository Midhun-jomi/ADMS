<?php
// modules/pharmacy/process_dispense.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $prescription_id = $_POST['prescription_id'];
    
    // Fetch prescription details
    $rx = db_select_one("SELECT * FROM prescriptions WHERE id = $1", [$prescription_id]);
    
    if (!$rx) {
        die("Prescription not found.");
    }
    
    $meds = json_decode($rx['medication_details'], true);
    $total_cost = 0;
    $billed_items = [];
    
    try {
        // Process each med
        $messages = [];

        foreach ($meds as $med) {
            $name = $med['name'];
            $qty_needed = $med['quantity'];
            
            $inventory = db_select_one("SELECT * FROM pharmacy_inventory WHERE medication_name = $1", [$name]);
            
            if ($inventory) {
                $available = $inventory['quantity'];
                
                // Calculate what we can give
                $dispense_qty = min($qty_needed, $available);
                $shortage = $qty_needed - $dispense_qty;
                
                if ($dispense_qty > 0) {
                    $price = $inventory['unit_price'];
                    $cost = $price * $dispense_qty;
                    $total_cost += $cost;
                    
                    $item_note = "$name (x$dispense_qty)";
                    if ($shortage > 0) {
                        $item_note .= " [Partial - Short: $shortage]";
                    }
                    $billed_items[] = $item_note;
                    
                    // Deduct stock
                    $new_qty = $available - $dispense_qty;
                    db_update('pharmacy_inventory', ['quantity' => $new_qty], ['id' => $inventory['id']]);
                }
                
                if ($shortage > 0) {
                    $msg = "Shortage for $name. Requested: $qty_needed, Dispensed: $dispense_qty.";
                    $messages[] = $msg;
                    
                    // Notify Admins
                    $admins = db_select("SELECT id FROM users WHERE role = 'admin'");
                    foreach ($admins as $admin) {
                        db_insert('notifications', [
                            'user_id' => $admin['id'],
                            'title' => 'Low Stock / Partial Dispense',
                            'message' => $msg
                        ]);
                    }
                }
            } else {
                $messages[] = "Medication '$name' not found in inventory.";
            }
        }
        
        // Generate Bill
        if ($total_cost > 0) {
            $desc = "Pharmacy: " . implode(", ", $billed_items);
            $bill_data = [
                'patient_id' => $rx['patient_id'],
                'appointment_id' => $rx['appointment_id'],
                'total_amount' => $total_cost,
                'status' => 'pending',
                'service_description' => substr($desc, 0, 255)
            ];
            db_insert('billing', $bill_data);
        }
        
        $success_msg = "Dispensed and Billed $$total_cost.";
        if (!empty($messages)) {
            $success_msg .= " Notes: " . implode(" ", $messages);
        }
        
        header("Location: dispense.php?success=" . urlencode($success_msg));
        exit();
        
    } catch (Exception $e) {
        die("Error processing dispense: " . $e->getMessage());
    }
}
?>
