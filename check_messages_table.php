<?php
require_once 'includes/db.php';

$tables = db_select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
$found = false;
foreach ($tables as $t) {
    if ($t['table_name'] == 'messages') {
        $found = true;
        break;
    }
}

if ($found) {
    echo "Table 'messages' EXISTS.\n";
    $cols = db_select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'messages'");
    print_r($cols);
} else {
    echo "Table 'messages' DOES NOT EXIST.\n";
}
?>
