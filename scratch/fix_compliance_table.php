<?php
require_once 'includes/db.php';

$sql = "
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
";

try {
    db_query($sql);
    echo "Table 'compliance_items' created and seeded successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
