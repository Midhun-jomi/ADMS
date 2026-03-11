<?php
// check_vitals.php — Admin-only debug tool
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_role(['admin']);

$res = pg_query($conn, "SELECT id, recorded_at FROM patient_health_metrics ORDER BY recorded_at ASC LIMIT 20");
while ($row = pg_fetch_assoc($res)) {
    echo $row['id'] . " | " . $row['recorded_at'] . "\n";
}
?>
