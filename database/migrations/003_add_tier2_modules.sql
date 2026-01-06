-- Migration: Add Telemedicine, Dietary, and Housekeeping

-- 1. Telemedicine Sessions
CREATE TABLE IF NOT EXISTS telemedicine_sessions (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    appointment_id UUID REFERENCES appointments(id) ON DELETE CASCADE,
    meeting_link TEXT, -- Zoom/Google Meet/Jitsi link
    platform VARCHAR(50) DEFAULT 'in-house',
    duration_minutes INTEGER,
    recording_url TEXT,
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Diet Plans
CREATE TABLE IF NOT EXISTS diet_plans (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL, -- Nutritionist/Doctor
    plan_name VARCHAR(100), -- Low Salt, Diabetic, Liquid
    instructions TEXT,
    start_date DATE,
    end_date DATE,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Canteen/Kitchen Items (for order management, optionally)
CREATE TABLE IF NOT EXISTS canteen_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50), -- Breakfast, Lunch, Drink
    price DECIMAL(10, 2),
    is_available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. Housekeeping Tasks
CREATE TABLE IF NOT EXISTS housekeeping_tasks (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    room_id UUID REFERENCES rooms(id) ON DELETE SET NULL, -- Can be null if general area
    location_name VARCHAR(100), -- If not a room ID (e.g. Lobby)
    assigned_staff_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    task_type VARCHAR(50), -- Cleaning, Sanitization, Maintenance
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'in_progress', 'completed')),
    completed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_telemed_appt ON telemedicine_sessions(appointment_id);
CREATE INDEX IF NOT EXISTS idx_diet_patient ON diet_plans(patient_id);
CREATE INDEX IF NOT EXISTS idx_housekeeping_status ON housekeeping_tasks(status);
