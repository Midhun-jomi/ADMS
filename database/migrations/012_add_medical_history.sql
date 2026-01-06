-- Migration: 012_add_medical_history.sql
ALTER TABLE patients ADD COLUMN IF NOT EXISTS medical_history TEXT;
