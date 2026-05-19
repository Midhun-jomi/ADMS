<?php
require_once 'includes/db.php';
$docs = db_select("SELECT user_id FROM staff WHERE specialization = 'Addiction Medicine'");
foreach($docs as $d) {
    $uid = $d['user_id'];
    db_query("DELETE FROM staff WHERE user_id = $1", [$uid]);
    db_query("DELETE FROM users WHERE id = $1", [$uid]);
    echo "Deleted Addiction Medicine doctor: $uid\n";
}
echo "Cleanup done.\n";
?>
