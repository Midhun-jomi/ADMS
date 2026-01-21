<?php
require_once __DIR__ . '/../config_sms.php';

class SMSService {
    
    public static function send($to_number, $message) {
        if (!defined('SMS_ENABLED') || SMS_ENABLED !== true) {
            // Log that we would have sent an SMS
            error_log("SMS Simulation: Sending '$message' to '$to_number'");
            return ['status' => 'simulated', 'message' => 'SMS Disabled. Check logs.'];
        }

        if (empty($to_number)) {
            return ['status' => 'error', 'message' => 'No phone number provided'];
        }

        // Basic Twilio Implementation
        if (defined('SMS_PROVIDER') && SMS_PROVIDER === 'twilio') {
            return self::sendTwilio($to_number, $message);
        }

        return ['status' => 'error', 'message' => 'No valid SMS provider configured'];
    }

    private static function sendTwilio($to, $body) {
        $sid = TWILIO_SID;
        $token = TWILIO_TOKEN;
        $from = TWILIO_FROM;

        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        
        $data = [
            'From' => $from,
            'To' => $to,
            'Body' => $body
        ];

        $post = http_build_query($data);
        $x = curl_init($url);
        curl_setopt($x, CURLOPT_POST, true);
        curl_setopt($x, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($x, CURLOPT_USERPWD, "$sid:$token");
        curl_setopt($x, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($x, CURLOPT_POSTFIELDS, $post);
        
        $result = curl_exec($x);
        $http_code = curl_getinfo($x, CURLINFO_HTTP_CODE);
        curl_close($x);

        $response = json_decode($result, true);

        if ($http_code >= 200 && $http_code < 300) {
            return ['status' => 'success', 'sid' => $response['sid'] ?? 'unknown'];
        } else {
            error_log("Twilio Error: " . print_r($response, true));
            return ['status' => 'error', 'message' => $response['message'] ?? 'Unknown API Error'];
        }
    }
}
?>
