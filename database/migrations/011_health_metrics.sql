-- Migration: 011_health_metrics.sql
-- Purpose: Store detailed health metrics (glucose, heart rate, etc.) for dashboard visualization

CREATE TABLE IF NOT EXISTS patient_health_metrics (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    metric_type VARCHAR(50) NOT NULL, -- 'heart_rate', 'glucose', 'cholesterol', 'ecg', 'stress_level'
    metric_value JSONB NOT NULL, -- Stores value and unit, e.g. {"value": 90, "unit": "bpm"} or {"data": [1,2,3...]}
    recorded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    recorded_by UUID REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_health_metrics_patient ON patient_health_metrics(patient_id);
CREATE INDEX idx_health_metrics_type ON patient_health_metrics(metric_type);
