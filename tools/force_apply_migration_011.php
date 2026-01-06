<?php
// tools/force_apply_migration_011.php
require_once __DIR__ . '/../includes/db.php';

echo "Attempting to create patient_health_metrics table...\n";

$sql = "
CREATE TABLE IF NOT EXISTS patient_health_metrics (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    metric_type VARCHAR(50) NOT NULL, -- 'heart_rate', 'glucose', 'cholesterol', 'ecg', 'stress_level'
    metric_value JSONB NOT NULL, -- Stores value and unit, e.g. {\"value\": 90, \"unit\": \"bpm\"} or {\"data\": [1,2,3...]}
    recorded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    recorded_by UUID REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_health_metrics_patient ON patient_health_metrics(patient_id);
CREATE INDEX IF NOT EXISTS idx_health_metrics_type ON patient_health_metrics(metric_type);
";

try {
    // db_query abstracts the connection and execution
    db_query($sql);
    echo "SUCCESS: Table 'patient_health_metrics' created (or already exists).\n";
} catch (Exception $e) {
    echo "ERROR: Failed to create table. " . $e->getMessage() . "\n";
}
?>
