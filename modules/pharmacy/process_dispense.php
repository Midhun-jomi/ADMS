<?php
// modules/pharmacy/process_dispense.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin']);

// --- Handle Alternative Confirmation ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirm_alternative'])) {
    $prescription_id = $_POST['prescription_id'];
    $original_med = $_POST['original_med'];
    $new_med = $_POST['new_med'];
    
    // Update the prescription JSON to reflect the AI substitution
    $rx = db_select_one("SELECT * FROM prescriptions WHERE id = $1", [$prescription_id]);
    if ($rx) {
        $meds = json_decode($rx['medication_details'], true);
        if (is_array($meds)) {
            foreach ($meds as &$m) {
                if ($m['name'] === $original_med) {
                    $m['name'] = $new_med; // Replace original with new
                    $m['dosage'] .= " [Substituted for $original_med]";
                }
            }
            db_update('prescriptions', ['medication_details' => json_encode($meds)], ['id' => $prescription_id]);
        }
        
        // Clear session so the prompt doesn't trigger repeatedly
        unset($_SESSION['dispense_shortage_data']);
    } else {
        die("Prescription not found during confirmation.");
    }
    
    // REMOVED early exit() and individual billing here.
    // The code will naturally fall through to the Normal Dispense Flow below, 
    // which will read the newly updated prescription JSON and dispense/bill 
    // ALL medications at once.
}

// --- Normal Dispense Flow ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['confirm_alternative']) && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: dispense.php?error=" . urlencode("Invalid request. Please refresh and try again."));
        exit();
    }
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
        // --- Phase 1: Check Availability ---
        foreach ($meds as $med) {
            $name = $med['name'];
            $qty_needed = $med['quantity'];
            
            $inventory = db_select_one("SELECT * FROM pharmacy_inventory WHERE medication_name = $1", [$name]);
            $available = $inventory ? $inventory['quantity'] : 0;
            
            if ($available < $qty_needed) {
                // Shortage detected! Trigger AI for this medication.
                
                // Collect actual available inventory names to guide the AI
                $inventory_db = db_select("SELECT medication_name FROM pharmacy_inventory WHERE quantity > 0");
                $available_meds = array_column($inventory_db, 'medication_name');
                
                // Call AI Service
                $url = 'http://127.0.0.1:5001/suggest_alternative';
                $data = json_encode([
                    'medicine' => $name,
                    'inventory' => $available_meds
                ]);
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                
                $response = curl_exec($ch);
                
                if (curl_errno($ch)) {
                    $suggestion = [
                        'suggested_alternative' => "Generic $name", 
                        'reason' => "AI Service Unavailable - Default Generic",
                        'dosage' => "Standard"
                    ];
                } else {
                    $suggestion = json_decode($response, true);
                }
                curl_close($ch);
                
                if (session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['dispense_shortage_data'] = [
                    'prescription_id' => $prescription_id,
                    'med_name' => $name,
                    'qty_needed' => $qty_needed,
                    'ai_suggestion' => $suggestion
                ];
                
                header("Location: approve_alternative.php");
                exit(); // Stop before any dispensing happens
            }
        }
        
        // --- Phase 2: specific Dispense (All items confirmed available) ---
        $messages = [];
        foreach ($meds as $med) {
            $name = $med['name'];
            $qty_needed = $med['quantity'];
            
            $inventory = db_select_one("SELECT * FROM pharmacy_inventory WHERE medication_name = $1", [$name]);
            // We know it exists and has stock from Phase 1
            
            if ($inventory) {
                $dispense_qty = $qty_needed;
                $price = $inventory['unit_price'];
                $cost = $price * $dispense_qty;
                $total_cost += $cost;
                
                $billed_items[] = "$name (x$dispense_qty)";
                
                // Deduct stock
                $new_qty = $inventory['quantity'] - $dispense_qty;
                db_update('pharmacy_inventory', ['quantity' => $new_qty], ['id' => $inventory['id']]);
            }
        }
        
        // Generate Bill
        if ($total_cost > 0) {
            // Prevent DB numeric overflow for NUMERIC(10,2)
            $total_cost = min((float)$total_cost, 99999999.99);
            
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
        
        // Mark the actual prescription as completed! (This was previously missing)
        db_update('prescriptions', ['status' => 'completed'], ['id' => $prescription_id]);
        
        $success_msg = "Dispensed and Billed ₹$total_cost.";
        header("Location: dispense.php?success=" . urlencode($success_msg));
        exit();
        
    } catch (Exception $e) {
        die("Error processing dispense: " . $e->getMessage());
    }
}
?>
