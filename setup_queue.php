<?php
require_once 'includes/db.php';

$sql = "
CREATE TABLE IF NOT EXISTS public_queue (
    id SERIAL PRIMARY KEY,
    patient_name VARCHAR(100) NOT NULL,
    room_number VARCHAR(20) NOT NULL,
    status VARCHAR(20) DEFAULT 'queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
";

try {
    db_query($sql);
    echo "Table 'public_queue' created successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
