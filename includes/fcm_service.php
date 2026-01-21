<?php
// includes/fcm_service.php
require_once __DIR__ . '/../config_fcm.php';

class FCMService {
    
    // Cache the access token temporarily
    private static $accessToken = null;
    private static $tokenExpiry = 0;

    /**
     * Send a Push Notification (HTTP v1 API)
     */
    public static function send($token, $title, $body, $data = []) {
        if (empty($token)) {
            return ['status' => 'error', 'message' => 'No token provided'];
        }

        // 1. Get Access Token from Google
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
             // Fallback or Error
             if (defined('FCM_SERVER_KEY') && strpos(FCM_SERVER_KEY, 'YOUR_') === false) {
                 // Try legacy if configured (backup)
                 return self::sendLegacy($token, $title, $body, $data);
             }
             return ['status' => 'error', 'message' => 'Could not generate Access Token. Check service_account.json.'];
        }

        // 2. Prepare Payload (HTTP v1 format is different)
        $projectId = self::getProjectId();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $message = [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
            // 'data' => $data // Data is supported but must be all strings
        ];

        if (!empty($data)) {
            // Ensure all data values are strings
            $stringData = array_map('strval', $data);
            $message['data'] = $stringData;
        }

        $payload = ['message' => $message];

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        // 3. Send Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode($result, true);

        if ($http_code === 200) {
            return ['status' => 'success', 'response' => $response];
        } else {
            error_log("FCM v1 Error: " . $result);
            return ['status' => 'error', 'message' => 'FCM Send Failed', 'debug' => $result];
        }
    }

    /**
     * Get OAuth2 Access Token manually using Service Account JSON
     */
    private static function getAccessToken() {
        if (self::$accessToken && time() < self::$tokenExpiry) {
            return self::$accessToken;
        }

        // Check if file exists
        if (!file_exists(FCM_SERVICE_ACCOUNT_JSON)) {
            error_log("FCM Error: service_account.json not found at " . FCM_SERVICE_ACCOUNT_JSON);
            return false;
        }

        $authConfig = json_decode(file_get_contents(FCM_SERVICE_ACCOUNT_JSON), true);
        
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $authConfig['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ];

        // Base64Url Encode
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));

        // Sign
        $signature = '';
        $success = openssl_sign(
            $base64UrlHeader . "." . $base64UrlPayload,
            $signature,
            $authConfig['private_key'],
            'SHA256'
        );

        if (!$success) {
            error_log("FCM Error: OpenSSL Signing Failed");
            return false;
        }

        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Exchange JWT for Access Token
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        $response = json_decode($result, true);

        if (isset($response['access_token'])) {
            self::$accessToken = $response['access_token'];
            self::$tokenExpiry = $now + 3500; // Cache for slightly less than 1 hour
            return self::$accessToken;
        }
        
        error_log("FCM Token Exchange Failed: " . $result);
        return false;
    }

    private static function getProjectId() {
        if (!file_exists(FCM_SERVICE_ACCOUNT_JSON)) return '';
        $json = json_decode(file_get_contents(FCM_SERVICE_ACCOUNT_JSON), true);
        return $json['project_id'] ?? '';
    }

    // Keep Legacy as backup just in case
    private static function sendLegacy($token, $title, $body, $data) {
        // ... (Previous implementation moved here if needed, or simplified)
        return ['status' => 'simulated', 'message' => 'Legacy Key fallback simulated'];
    }
}
?>
