<?php
require_once 'includes/db.php';
$non_opd = [
    'Radiology', 'Anesthesiology', 'Emergency Medicine', 
    'Nuclear Medicine', 'Pathology', 'Clinical Pharmacology', 
    'Forensic Medicine'
];

foreach($non_opd as $spec) {
    $docs = db_select("SELECT user_id FROM staff WHERE specialization = $1", [$spec]);
    foreach($docs as $d) {
        $uid = $d['user_id'];
        db_query("DELETE FROM staff WHERE user_id = $1", [$uid]);
        db_query("DELETE FROM users WHERE id = $1", [$uid]);
        echo "Deleted non-OPD doctor ($spec): $uid\n";
    }
}
echo "Cleanup of non-OPD doctors done.\n";
?>
