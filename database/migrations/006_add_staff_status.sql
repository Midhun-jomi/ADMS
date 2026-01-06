-- Migration: Add status to Staff table
-- Default to 'active' for existing staff.

ALTER TABLE staff ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active';

-- Ensure status is one of the expected values
ALTER TABLE staff DROP CONSTRAINT IF EXISTS check_staff_status;
ALTER TABLE staff ADD CONSTRAINT check_staff_status CHECK (status IN ('active', 'inactive', 'on_leave'));
