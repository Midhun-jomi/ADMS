-- Migration: Add Enterprise Features (Feedback, etc.)

-- 1. Patient Feedback / Surveys
CREATE TABLE IF NOT EXISTS patient_feedback (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    patient_id UUID REFERENCES patients(id) ON DELETE SET NULL,
    visit_id UUID REFERENCES appointments(id) ON DELETE SET NULL,
    rating INTEGER CHECK (rating >= 1 AND rating <= 5),
    comments TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Notification Logs (for "Smart Notifications" simulation)
CREATE TABLE IF NOT EXISTS notification_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    type VARCHAR(50), -- SMS, Email, WhatsApp
    message TEXT,
    status VARCHAR(20) DEFAULT 'sent',
    sent_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_feedback_rating ON patient_feedback(rating);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notification_logs(user_id);
