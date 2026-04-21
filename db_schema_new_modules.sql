-- ============================================================
-- ADMS Hospital Management System - New Modules Schema
-- Run this SQL on your Supabase/PostgreSQL database
-- Date: 2026-03-27
-- ============================================================

-- 1. Emergency / Casualty Module
CREATE TABLE IF NOT EXISTS emergency_cases (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_number SERIAL UNIQUE,
    patient_name VARCHAR(200) NOT NULL,
    age INT,
    gender VARCHAR(20),
    chief_complaint TEXT NOT NULL,
    triage_level VARCHAR(20) NOT NULL CHECK (triage_level IN ('red','orange','yellow','green')),
    assigned_doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    room_id UUID REFERENCES rooms(id) ON DELETE SET NULL,
    notes TEXT,
    status VARCHAR(30) DEFAULT 'active' CHECK (status IN ('active','under_treatment','observation','discharged')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_emergency_status ON emergency_cases(status);
CREATE INDEX IF NOT EXISTS idx_emergency_triage ON emergency_cases(triage_level);

-- 2. Patient Referrals
CREATE TABLE IF NOT EXISTS referrals (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    from_doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    to_doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    referral_type VARCHAR(20) DEFAULT 'Internal' CHECK (referral_type IN ('Internal','External')),
    priority VARCHAR(20) DEFAULT 'Routine' CHECK (priority IN ('Routine','Urgent','Emergency')),
    reason TEXT NOT NULL,
    notes TEXT,
    external_hospital VARCHAR(200),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','accepted','completed','cancelled')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_referrals_patient ON referrals(patient_id);
CREATE INDEX IF NOT EXISTS idx_referrals_status ON referrals(status);

-- 3. Consent Forms
CREATE TABLE IF NOT EXISTS consent_forms (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    consent_type VARCHAR(50) NOT NULL,
    procedure_name VARCHAR(200),
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    consent_text TEXT NOT NULL,
    witness_name VARCHAR(200),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','signed','revoked')),
    signed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_consent_patient ON consent_forms(patient_id);
CREATE INDEX IF NOT EXISTS idx_consent_status ON consent_forms(status);

-- 4. Incident Reports
CREATE TABLE IF NOT EXISTS incident_reports (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    report_number SERIAL UNIQUE,
    incident_type VARCHAR(100) NOT NULL,
    incident_datetime TIMESTAMPTZ NOT NULL,
    location VARCHAR(200),
    patient_id UUID REFERENCES patients(id) ON DELETE SET NULL,
    description TEXT NOT NULL,
    immediate_action TEXT,
    severity VARCHAR(20) NOT NULL CHECK (severity IN ('Minor','Moderate','Severe','Critical')),
    witness_names TEXT,
    status VARCHAR(30) DEFAULT 'open' CHECK (status IN ('open','under_investigation','resolved')),
    follow_up_notes TEXT,
    reported_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_incidents_status ON incident_reports(status);
CREATE INDEX IF NOT EXISTS idx_incidents_reporter ON incident_reports(reported_by);

-- 5. Vendors
CREATE TABLE IF NOT EXISTS vendors (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    vendor_name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(200),
    phone VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    category VARCHAR(50),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active','inactive')),
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 6. Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    po_number SERIAL UNIQUE,
    vendor_id UUID REFERENCES vendors(id) ON DELETE SET NULL,
    item_name VARCHAR(200) NOT NULL,
    category VARCHAR(50),
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_amount DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    expected_delivery DATE,
    priority VARCHAR(20) DEFAULT 'normal' CHECK (priority IN ('normal','urgent')),
    notes TEXT,
    status VARCHAR(30) DEFAULT 'draft' CHECK (status IN ('draft','pending_approval','approved','ordered','delivered','cancelled')),
    approved_by UUID REFERENCES users(id) ON DELETE SET NULL,
    approved_at TIMESTAMPTZ,
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_po_status ON purchase_orders(status);
CREATE INDEX IF NOT EXISTS idx_po_vendor ON purchase_orders(vendor_id);

-- 7. Schedulable Resources (Equipment & Rooms)
CREATE TABLE IF NOT EXISTS schedulable_resources (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    type VARCHAR(50) NOT NULL,
    location VARCHAR(200),
    capacity INT DEFAULT 1,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'available' CHECK (status IN ('available','maintenance','retired')),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 8. Resource Bookings
CREATE TABLE IF NOT EXISTS resource_bookings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    resource_id UUID REFERENCES schedulable_resources(id) ON DELETE CASCADE,
    booked_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    booked_for VARCHAR(300),
    booked_by UUID REFERENCES users(id) ON DELETE SET NULL,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming','in_progress','completed','cancelled')),
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_bookings_resource ON resource_bookings(resource_id);
CREATE INDEX IF NOT EXISTS idx_bookings_date ON resource_bookings(booked_date);

-- 9. Duty Roster
CREATE TABLE IF NOT EXISTS duty_roster (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    staff_id UUID REFERENCES staff(id) ON DELETE CASCADE,
    shift_date DATE NOT NULL,
    shift_type VARCHAR(20) NOT NULL CHECK (shift_type IN ('Morning','Evening','Night','Custom')),
    start_time TIME,
    end_time TIME,
    department VARCHAR(100),
    notes TEXT,
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(staff_id, shift_date, shift_type)
);
CREATE INDEX IF NOT EXISTS idx_roster_staff ON duty_roster(staff_id);
CREATE INDEX IF NOT EXISTS idx_roster_date ON duty_roster(shift_date);

-- 10. Appointment Reminders
CREATE TABLE IF NOT EXISTS appointment_reminders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    appointment_id UUID REFERENCES appointments(id) ON DELETE CASCADE,
    sent_to_email VARCHAR(200),
    sent_at TIMESTAMPTZ DEFAULT NOW(),
    status VARCHAR(20) DEFAULT 'sent' CHECK (status IN ('sent','failed','pending')),
    method VARCHAR(20) DEFAULT 'email',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 11. Reminder Settings
CREATE TABLE IF NOT EXISTS reminder_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
INSERT INTO reminder_settings (setting_key, setting_value) VALUES
    ('lead_time_hours', '24'),
    ('reminder_template', 'Dear {patient_name}, this is a reminder for your appointment with {doctor_name} on {appointment_time}. Please arrive 15 minutes early. - ADMS Hospital')
ON CONFLICT (setting_key) DO NOTHING;

-- 12. Clinical Outcomes
CREATE TABLE IF NOT EXISTS clinical_outcomes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    appointment_id UUID REFERENCES appointments(id) ON DELETE SET NULL,
    outcome_type VARCHAR(30) NOT NULL CHECK (outcome_type IN ('Recovered','Improved','Unchanged','Deteriorated','Readmitted','Deceased')),
    diagnosis_treated TEXT,
    treatment_given TEXT,
    outcome_date DATE NOT NULL,
    notes TEXT,
    follow_up_required BOOLEAN DEFAULT FALSE,
    follow_up_date DATE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    created_by UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_outcomes_patient ON clinical_outcomes(patient_id);
CREATE INDEX IF NOT EXISTS idx_outcomes_doctor ON clinical_outcomes(doctor_id);

-- 13. Compliance Items
CREATE TABLE IF NOT EXISTS compliance_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    item_name VARCHAR(300) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('compliant','pending','non_compliant')),
    notes TEXT,
    updated_by UUID REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
INSERT INTO compliance_items (item_name, status) VALUES
    ('Session timeout enforced (30 minutes)', 'compliant'),
    ('CSRF protection active on all forms', 'compliant'),
    ('Password minimum 8 characters enforced', 'compliant'),
    ('SSL/TLS encryption enabled', 'compliant'),
    ('Audit logging active', 'compliant'),
    ('Role-based access control implemented', 'compliant'),
    ('Patient data encrypted at rest', 'pending'),
    ('Regular automated backup policy', 'pending'),
    ('Staff security awareness training', 'pending'),
    ('Data breach response plan documented', 'pending'),
    ('Two-factor authentication for admin', 'pending'),
    ('Vulnerability assessment conducted', 'pending')
ON CONFLICT DO NOTHING;

-- 14. Infection Cases
CREATE TABLE IF NOT EXISTS infection_cases (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id UUID REFERENCES patients(id) ON DELETE CASCADE,
    infection_type VARCHAR(100) NOT NULL,
    source VARCHAR(30) DEFAULT 'Unknown' CHECK (source IN ('Community','Hospital-acquired','Unknown')),
    date_identified DATE NOT NULL,
    ward VARCHAR(100),
    isolation_required BOOLEAN DEFAULT FALSE,
    antibiotic_prescribed VARCHAR(200),
    resistance_pattern TEXT,
    notes TEXT,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active','resolved','transferred')),
    resolution_date DATE,
    resolution_notes TEXT,
    assigned_doctor_id UUID REFERENCES staff(id) ON DELETE SET NULL,
    reported_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_infection_patient ON infection_cases(patient_id);
CREATE INDEX IF NOT EXISTS idx_infection_status ON infection_cases(status);
CREATE INDEX IF NOT EXISTS idx_infection_type ON infection_cases(infection_type);

-- ============================================================
-- Summary of new tables created:
-- 1.  emergency_cases
-- 2.  referrals
-- 3.  consent_forms
-- 4.  incident_reports
-- 5.  vendors
-- 6.  purchase_orders
-- 7.  schedulable_resources
-- 8.  resource_bookings
-- 9.  duty_roster
-- 10. appointment_reminders
-- 11. reminder_settings
-- 12. clinical_outcomes
-- 13. compliance_items
-- 14. infection_cases
-- ============================================================
