<?php
// scratch/check_schema.php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Listing Tables:\n";
    $tables = db_select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    foreach ($tables as $t) {
        echo "- " . $t['table_name'] . "\n";
        
        $cols = db_select("SELECT column_name FROM information_schema.columns WHERE table_name = $1", [$t['table_name']]);
        foreach ($cols as $c) {
            echo "  -- " . $c['column_name'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
