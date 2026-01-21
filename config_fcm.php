<?php
// config_fcm.php
// Configuration for Firebase Cloud Messaging

// Path to your downloaded JSON file. 
// Make sure to rename the downloaded file to 'service_account.json' and place it in the same directory as this file (ADMS root).
define('FCM_SERVICE_ACCOUNT_JSON', __DIR__ . '/service_account.json');

// Legacy Key (Backup only, optional)
define('FCM_SERVER_KEY', 'YOUR_FIREBASE_SERVER_KEY_HERE');
?>
