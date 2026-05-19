<?php
require_once 'includes/db.php';

$sql = "
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

-- Seed some vendors
INSERT INTO vendors (vendor_name, contact_person, category, phone, email) VALUES
    ('MedTech Supplies', 'John Smith', 'Medical Equipment', '555-0101', 'sales@medtech.com'),
    ('Global Pharma Co', 'Sarah Johnson', 'Pharmaceuticals', '555-0102', 'info@globalpharma.com'),
    ('CleanCare Solutions', 'Mike Brown', 'Sanitary', '555-0103', 'support@cleancare.com'),
    ('TechLogix Hospital Systems', 'Emily Davis', 'IT/Software', '555-0104', 'emily@techlogix.com')
ON CONFLICT DO NOTHING;
";

try {
    db_query($sql);
    echo "Procurement tables (vendors, purchase_orders) created and seeded successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
