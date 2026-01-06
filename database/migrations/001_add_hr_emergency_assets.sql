-- Migration: Add HR, Emergency, and Asset Management tables

-- 1. Payroll Table
CREATE TABLE IF NOT EXISTS payroll (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    staff_id UUID REFERENCES staff(id) ON DELETE CASCADE,
    salary_month DATE NOT NULL, -- First day of the month
    basic_salary DECIMAL(10, 2) NOT NULL,
    allowances DECIMAL(10, 2) DEFAULT 0,
    deductions DECIMAL(10, 2) DEFAULT 0,
    net_salary DECIMAL(10, 2) GENERATED ALWAYS AS (basic_salary + allowances - deductions) STORED,
    status VARCHAR(20) DEFAULT 'unpaid' CHECK (status IN ('unpaid', 'paid')),
    payment_date TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Leaves Table
CREATE TABLE IF NOT EXISTS leaves (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    staff_id UUID REFERENCES staff(id) ON DELETE CASCADE,
    leave_type VARCHAR(50) NOT NULL, -- e.g., Sick, Casual, Annual
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    approved_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Emergency Calls Table
CREATE TABLE IF NOT EXISTS emergency_calls (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    caller_name VARCHAR(100),
    caller_phone VARCHAR(20),
    location TEXT NOT NULL,
    description TEXT,
    ambulance_id UUID REFERENCES ambulance(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'received' CHECK (status IN ('received', 'dispatched', 'resolved', 'cancelled')),
    dispatched_at TIMESTAMP WITH TIME ZONE,
    resolved_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. General Assets Table (Non-Medical/Infrastructure)
CREATE TABLE IF NOT EXISTS assets (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50), -- e.g., Furniture, IT, Medical Equipment
    purchase_date DATE,
    cost DECIMAL(10, 2),
    location VARCHAR(100), -- e.g., Room 101, Ward A
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'maintenance', 'retired')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Create Indexes
CREATE INDEX IF NOT EXISTS idx_payroll_staff_id ON payroll(staff_id);
CREATE INDEX IF NOT EXISTS idx_leaves_staff_id ON leaves(staff_id);
CREATE INDEX IF NOT EXISTS idx_emergency_calls_status ON emergency_calls(status);
