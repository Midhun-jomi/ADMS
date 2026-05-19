<?php
require_once 'includes/db.php';

$assets = [
    [
        'name' => 'Philips Ingenia MRI 1.5T',
        'category' => 'Medical Equipment',
        'location' => 'Radiology Dept - Room 4',
        'purchase_date' => '2023-05-15',
        'cost' => 12500000.00,
        'status' => 'active'
    ],
    [
        'name' => 'GE Revolution CT Scanner',
        'category' => 'Medical Equipment',
        'location' => 'Radiology Dept - Room 2',
        'purchase_date' => '2024-01-10',
        'cost' => 8500000.00,
        'status' => 'maintenance'
    ],
    [
        'name' => 'Dell PowerEdge R750 Server',
        'category' => 'IT Infrastructure',
        'location' => 'Data Center - Rack A1',
        'purchase_date' => '2024-02-20',
        'cost' => 450000.00,
        'status' => 'active'
    ],
    [
        'name' => 'Draeger Perseus A500 Anesthesia Machine',
        'category' => 'Surgical Equipment',
        'location' => 'OT Complex - Theater 1',
        'purchase_date' => '2023-11-05',
        'cost' => 3200000.00,
        'status' => 'active'
    ],
    [
        'name' => 'Stryker SV2 Hospital Bed',
        'category' => 'Furniture',
        'location' => 'ICU - Bed 1',
        'purchase_date' => '2024-03-01',
        'cost' => 125000.00,
        'status' => 'active'
    ],
    [
        'name' => 'HP EliteBook 840 G10 (Staff Laptop)',
        'category' => 'IT Infrastructure',
        'location' => 'Nursing Station - 2nd Floor',
        'purchase_date' => '2024-04-12',
        'cost' => 95000.00,
        'status' => 'active'
    ]
];

echo "Seeding assets with correct schema...\n";
$count = 0;
foreach ($assets as $a) {
    try {
        db_insert('assets', $a);
        $count++;
    } catch (Exception $e) {
        // echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "Successfully seeded $count assets.\n";
?>
