<?php
// modules/ehr/schema_update_certificates.php
require_once __DIR__ . '/../../includes/db.php';

$sql = "
CREATE TABLE IF NOT EXISTS certificate_requests (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    type VARCHAR(50) NOT NULL CHECK (type IN ('Medical Certificate', 'Birth Certificate', 'Death Certificate')),
    reason TEXT,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
";

try {
    db_query($sql);
    echo "Certificate Requests table created successfully.";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
