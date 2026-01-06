-- Migration: Add UHID to Patients
-- Using SERIAL to auto-increment for new patients.
-- Existing rows will be backfilled automatically with sequential numbers.

ALTER TABLE patients ADD COLUMN IF NOT EXISTS uhid SERIAL;

-- Optional: Create an index for fast lookup by UHID
CREATE INDEX IF NOT EXISTS idx_patients_uhid ON patients(uhid);
