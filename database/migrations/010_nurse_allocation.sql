-- database/migrations/010_nurse_allocation.sql

-- 1. Update Users Table Constraint to allow 'head_nurse'
-- Note: 'role' is a check constraint. We need to drop and re-add it or just allow it.
-- Postgres syntax:
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'doctor', 'nurse', 'head_nurse', 'receptionist', 'pharmacist', 'lab_tech', 'radiologist', 'patient'));

-- 2. Update Staff Table Role Constraint ? 
-- Staff table just says role VARCHAR(50). If there is no check, we are good.
-- BUT, typically apps enforce it.
-- Let's assume staff role is flexible or we should standardise it. 
-- Schema says: role VARCHAR(50) NOT NULL. No explicit CHECK on staff.role shown in schema.sql provided.

-- 3. Nurse Allocations Table
CREATE TABLE IF NOT EXISTS nurse_allocations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    nurse_id UUID REFERENCES staff(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,     -- Allocated to specific doctor
    department_id UUID REFERENCES departments(id) ON DELETE SET NULL, -- allocated to ward/dept
    shift VARCHAR(20) DEFAULT 'Morning' CHECK (shift IN ('Morning', 'Evening', 'Night')),
    assigned_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Ensure a nurse is not double booked for same shift? 
    -- Let's add a unique constraint just in case, or allow multiple?
    -- "Allocate nurse with doctors AND in each position ward". Might imply multiple duties.
    -- Let's keep it flexible for now.
    CONSTRAINT unique_nurse_shift UNIQUE (nurse_id, shift)
);

-- Index
CREATE INDEX idx_alloc_nurse ON nurse_allocations(nurse_id);
CREATE INDEX idx_alloc_doctor ON nurse_allocations(doctor_id);
CREATE INDEX idx_alloc_dept ON nurse_allocations(department_id);
