<?php
// tools/force_create_allocations.php
// Directly create the table to fix "relation does not exist" error
require_once __DIR__ . '/../includes/db.php';

echo "Attempting to create 'nurse_allocations' table...\n";

$sql = "
-- 1. Update Users Check Constraint (Safe retry)
-- We wrap in DO block for safety only if PLPGSQL available, but simple SQL commands are better here.
-- We'll ignore errors on constraint drops.

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'doctor', 'nurse', 'head_nurse', 'receptionist', 'pharmacist', 'lab_tech', 'radiologist', 'patient'));

-- 2. Create Table
CREATE TABLE IF NOT EXISTS nurse_allocations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nurse_id UUID REFERENCES staff(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,     
    department_id UUID REFERENCES departments(id) ON DELETE SET NULL, 
    shift VARCHAR(20) DEFAULT 'Morning' CHECK (shift IN ('Morning', 'Evening', 'Night')),
    assigned_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT unique_nurse_shift UNIQUE (nurse_id, shift)
);

-- 3. Indexes
CREATE INDEX IF NOT EXISTS idx_alloc_nurse ON nurse_allocations(nurse_id);
CREATE INDEX IF NOT EXISTS idx_alloc_doctor ON nurse_allocations(doctor_id);
CREATE INDEX IF NOT EXISTS idx_alloc_dept ON nurse_allocations(department_id);
";

try {
    $result = pg_query($conn, $sql);
    if ($result) {
        echo "✅ SUCCESS: Table 'nurse_allocations' created (or already exists).\n";
        echo "You can now refresh the Nurse Allocation page.\n";
    } else {
        echo "❌ FAILURE: " . pg_last_error($conn) . "\n";
    }
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
}
?>
