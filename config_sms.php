<?php
// config_sms.php
// Configuration for SMS Service (e.g., Twilio)

define('SMS_ENABLED', false); // Set to true to enable real sending
define('SMS_PROVIDER', 'twilio');

// Twilio Credentials
define('TWILIO_SID', 'your_account_sid_here');
define('TWILIO_TOKEN', 'your_auth_token_here');
define('TWILIO_FROM', 'your_twilio_phone_number');

// Optional: Fallback or other providers can be added here
?>
