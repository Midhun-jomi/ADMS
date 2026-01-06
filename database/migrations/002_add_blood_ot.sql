-- Migration: Add Blood Bank and Operation Theatre

-- 1. Blood Donors
CREATE TABLE IF NOT EXISTS blood_donors (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL,
    blood_group VARCHAR(5) NOT NULL, -- A+, B+, etc.
    age INTEGER,
    gender VARCHAR(20),
    contact_number VARCHAR(20),
    email VARCHAR(100),
    last_donation_date DATE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Blood Inventory
CREATE TABLE IF NOT EXISTS blood_inventory (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    blood_group VARCHAR(5) NOT NULL,
    quantity INTEGER DEFAULT 0, -- Number of bags
    expiry_date DATE, -- For a specific batch (simplified) or generic tracking
    status VARCHAR(20) DEFAULT 'available',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Blood Requests
CREATE TABLE IF NOT EXISTS blood_requests (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    blood_group VARCHAR(5) NOT NULL,
    units_required INTEGER DEFAULT 1,
    urgency VARCHAR(20) DEFAULT 'normal' CHECK (urgency IN ('normal', 'critical')),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'fulfilled', 'rejected')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. Operation Theatres
CREATE TABLE IF NOT EXISTS theatres (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL, -- OT 1, OT 2
    type VARCHAR(50), -- General, Cardiac, Neuro
    status VARCHAR(20) DEFAULT 'available' CHECK (status IN ('available', 'occupied', 'cleaning', 'maintenance')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 5. Surgeries (Schedule)
CREATE TABLE IF NOT EXISTS surgeries (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL, -- Lead Surgeon
    theatre_id UUID REFERENCES theatres(id) ON DELETE SET NULL,
    surgery_name VARCHAR(150) NOT NULL,
    scheduled_start TIMESTAMP WITH TIME ZONE NOT NULL,
    scheduled_end TIMESTAMP WITH TIME ZONE NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'in_progress', 'completed', 'cancelled')),
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_blood_inventory_group ON blood_inventory(blood_group);
CREATE INDEX IF NOT EXISTS idx_surgeries_date ON surgeries(scheduled_start);
