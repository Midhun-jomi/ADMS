<?php
// modules/patient_management/schema.php
require_once __DIR__ . '/../../includes/db.php';

$sql = "
-- Admissions Table
CREATE TABLE IF NOT EXISTS admissions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    room_id UUID REFERENCES rooms(id) ON DELETE SET NULL,
    admission_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    discharge_date TIMESTAMP WITH TIME ZONE,
    status VARCHAR(20) DEFAULT 'admitted' CHECK (status IN ('admitted', 'discharged')),
    diagnosis TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Discharge Summaries Table
CREATE TABLE IF NOT EXISTS discharge_summaries (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    admission_id UUID REFERENCES admissions(id) ON DELETE CASCADE,
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    summary_text TEXT,
    medications_prescribed JSONB,
    follow_up_instructions TEXT,
    generated_by UUID REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Ensure rooms have status column if not already (it was in original schema, but good to check)
-- ALTER TABLE rooms ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'available';
";

try {
    db_query($sql);
    echo "Patient Management Schema created successfully.";
} catch (Exception $e) {
    echo "Error creating schema: " . $e->getMessage();
}
?>
