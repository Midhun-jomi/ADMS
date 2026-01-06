<?php
// tools/repair_pharmacy.php
require_once __DIR__ . '/../includes/db.php';

echo "Checking Pharmacy Tables...\n";

// 1. Pharmacy Inventory
try {
    $check = db_select("SELECT * FROM information_schema.tables WHERE table_name = 'pharmacy_inventory'");
    if (empty($check)) {
        echo "Creating pharmacy_inventory table...\n";
        $sql = "CREATE TABLE pharmacy_inventory (
            id SERIAL PRIMARY KEY,
            medication_name VARCHAR(255) NOT NULL,
            description TEXT,
            quantity INTEGER DEFAULT 0,
            unit_price DECIMAL(10,2),
            expiry_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        db_query($sql);
        
        // Seed some data
        echo "Seeding inventory...\n";
        db_query("INSERT INTO pharmacy_inventory (medication_name, quantity, unit_price) VALUES 
            ('Paracetamol 500mg', 1000, 0.50),
            ('Amoxicillin 500mg', 500, 1.20),
            ('Ibuprofen 400mg', 800, 0.80),
            ('Metformin 500mg', 600, 0.40),
            ('Atorvastatin 20mg', 300, 2.50),
            ('Omeprazole 20mg', 400, 1.00),
            ('Cetirizine 10mg', 1000, 0.30)
        ");
    } else {
        echo "pharmacy_inventory exists.\n";
    }
} catch (Exception $e) {
    echo "Error processing inventory: " . $e->getMessage() . "\n";
}

// 2. Prescriptions
try {
    $check_rx = db_select("SELECT * FROM information_schema.tables WHERE table_name = 'prescriptions'");
    if (empty($check_rx)) {
        echo "Creating prescriptions table...\n";
        $sql_rx = "CREATE TABLE prescriptions (
            id SERIAL PRIMARY KEY,
            patient_id INTEGER REFERENCES patients(id),
            doctor_id INTEGER REFERENCES staff(id),
            appointment_id INTEGER REFERENCES appointments(id),
            medication_details JSONB,
            status VARCHAR(50) DEFAULT 'pending', 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        db_query($sql_rx);
    } else {
        echo "prescriptions table exists.\n";
        // Check columns
        // $cols = db_select("SELECT column_name FROM information_schema.columns WHERE table_name = 'prescriptions'");
        // ... (Optional column check)
    }
} catch (Exception $e) {
    echo "Error processing prescriptions: " . $e->getMessage() . "\n";
}

echo "Done.\n";
