<?php
require_once 'includes/db.php';

// Fetch vendor IDs
$vendors = db_select("SELECT id, vendor_name FROM vendors");
if (empty($vendors)) {
    die("No vendors found. Please run the table fix script first.");
}

$v_map = [];
foreach ($vendors as $v) { $v_map[$v['vendor_name']] = $v['id']; }

// Get an admin user for created_by
$admin = db_select_one("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_id = $admin['id'] ?? null;

$data = [
    [
        'vendor_id' => $v_map['MedTech Supplies'] ?? null,
        'item_name' => 'Advanced MRI Coil',
        'category' => 'Radiology',
        'quantity' => 2,
        'unit_price' => 450000.00,
        'status' => 'approved',
        'priority' => 'urgent'
    ],
    [
        'vendor_id' => $v_map['Global Pharma Co'] ?? null,
        'item_name' => 'Remdesivir Vials (Box of 50)',
        'category' => 'Pharmaceuticals',
        'quantity' => 10,
        'unit_price' => 25000.00,
        'status' => 'delivered',
        'priority' => 'normal'
    ],
    [
        'vendor_id' => $v_map['CleanCare Solutions'] ?? null,
        'item_name' => 'Surgical Grade Disinfectant (20L)',
        'category' => 'Sanitary',
        'quantity' => 25,
        'unit_price' => 1200.00,
        'status' => 'ordered',
        'priority' => 'normal'
    ],
    [
        'vendor_id' => $v_map['TechLogix Hospital Systems'] ?? null,
        'item_name' => 'High-Performance Server for EHR',
        'category' => 'IT Infrastructure',
        'quantity' => 1,
        'unit_price' => 125000.00,
        'status' => 'pending_approval',
        'priority' => 'urgent'
    ],
    [
        'vendor_id' => $v_map['MedTech Supplies'] ?? null,
        'item_name' => 'Standard Hospital Beds (Adjustable)',
        'category' => 'Furniture',
        'quantity' => 15,
        'unit_price' => 35000.00,
        'status' => 'approved',
        'priority' => 'normal'
    ],
    [
        'vendor_id' => $v_map['Global Pharma Co'] ?? null,
        'item_name' => 'Insulin Glargine (100 Units/mL)',
        'category' => 'Pharmaceuticals',
        'quantity' => 100,
        'unit_price' => 450.00,
        'status' => 'delivered',
        'priority' => 'normal'
    ]
];

foreach ($data as $row) {
    try {
        db_insert('purchase_orders', [
            'vendor_id' => $row['vendor_id'],
            'item_name' => $row['item_name'],
            'category' => $row['category'],
            'quantity' => $row['quantity'],
            'unit_price' => $row['unit_price'],
            'status' => $row['status'],
            'priority' => $row['priority'],
            'created_by' => $admin_id,
            'expected_delivery' => date('Y-m-d', strtotime('+7 days'))
        ]);
        echo "Inserted PO for " . $row['item_name'] . "\n";
    } catch (Exception $e) {
        echo "Error inserting " . $row['item_name'] . ": " . $e->getMessage() . "\n";
    }
}

echo "Seeding completed.\n";
?>
