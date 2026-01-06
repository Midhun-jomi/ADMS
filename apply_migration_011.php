<?php
require_once __DIR__ . '/includes/db.php';

echo "Applying migration 011_health_metrics...\n";

$sql = file_get_contents(__DIR__ . '/database/migrations/011_health_metrics.sql');

if ($sql) {
    $res = pg_query($base_connection, $sql);
    if ($res) {
        echo "Migration applied successfully.\n";
    } else {
        echo "Error applying migration: " . pg_last_error($base_connection) . "\n";
    }
} else {
    echo "Could not read migration file.\n";
}
?>
