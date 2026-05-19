<?php
require_once 'includes/db.php';

// 1. Ensure table exists
try {
    db_query("CREATE TABLE IF NOT EXISTS assets (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        asset_name VARCHAR(200) NOT NULL,
        asset_tag VARCHAR(50) UNIQUE NOT NULL,
        category VARCHAR(50),
        location VARCHAR(100),
        purchase_date DATE,
        purchase_cost DECIMAL(12,2),
        warranty_expiry DATE,
        status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'maintenance', 'retired', 'lost')),
        notes TEXT,
        created_at TIMESTAMPTZ DEFAULT NOW()
    )");
} catch (Exception $e) { echo "Table check error: " . $e->getMessage() . "\n"; }

$assets = [
    [
        'asset_name' => 'Philips Ingenia MRI 1.5T',
        'asset_tag' => 'ASSET-MRI-001',
        'category' => 'Medical Equipment',
        'location' => 'Radiology Dept - Room 4',
        'purchase_date' => '2023-05-15',
        'purchase_cost' => 12500000.00,
        'warranty_expiry' => '2028-05-15',
        'status' => 'active'
    ],
    [
        'asset_name' => 'GE Revolution CT Scanner',
        'asset_tag' => 'ASSET-CT-002',
        'category' => 'Medical Equipment',
        'location' => 'Radiology Dept - Room 2',
        'purchase_date' => '2024-01-10',
        'purchase_cost' => 8500000.00,
        'warranty_expiry' => '2029-01-10',
        'status' => 'maintenance'
    ],
    [
        'asset_name' => 'Dell PowerEdge R750 Server',
        'asset_tag' => 'ASSET-IT-001',
        'category' => 'IT Infrastructure',
        'location' => 'Data Center - Rack A1',
        'purchase_date' => '2024-02-20',
        'purchase_cost' => 450000.00,
        'warranty_expiry' => '2027-02-20',
        'status' => 'active'
    ],
    [
        'asset_name' => 'Draeger Perseus A500 Anesthesia Machine',
        'asset_tag' => 'ASSET-OT-001',
        'category' => 'Surgical Equipment',
        'location' => 'OT Complex - Theater 1',
        'purchase_date' => '2023-11-05',
        'purchase_cost' => 3200000.00,
        'warranty_expiry' => '2026-11-05',
        'status' => 'active'
    ],
    [
        'asset_name' => 'Stryker SV2 Hospital Bed',
        'asset_tag' => 'ASSET-BED-101',
        'category' => 'Furniture',
        'location' => 'ICU - Bed 1',
        'purchase_date' => '2024-03-01',
        'purchase_cost' => 125000.00,
        'warranty_expiry' => '2029-03-01',
        'status' => 'active'
    ],
    [
        'asset_name' => 'HP EliteBook 840 G10 (Staff Laptop)',
        'asset_tag' => 'ASSET-IT-105',
        'category' => 'IT Infrastructure',
        'location' => 'Nursing Station - 2nd Floor',
        'purchase_date' => '2024-04-12',
        'purchase_cost' => 95000.00,
        'warranty_expiry' => '2027-04-12',
        'status' => 'active'
    ]
];

echo "Seeding assets...\n";
$count = 0;
foreach ($assets as $a) {
    try {
        db_query(
            "INSERT INTO assets (asset_name, asset_tag, category, location, purchase_date, purchase_cost, warranty_expiry, status) 
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
             ON CONFLICT (asset_tag) DO UPDATE 
             SET asset_name = EXCLUDED.asset_name, location = EXCLUDED.location, status = EXCLUDED.status",
            [$a['asset_name'], $a['asset_tag'], $a['category'], $a['location'], $a['purchase_date'], $a['purchase_cost'], $a['warranty_expiry'], $a['status']]
        );
        $count++;
    } catch (Exception $e) {
        // echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "Successfully seeded $count assets.\n";
?>
