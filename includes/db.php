<?php
// includes/db.php
date_default_timezone_set('Asia/Kolkata'); // Ensure consistent timezone


$host     = 'aws-1-ap-southeast-2.pooler.supabase.com';
$port     = '5432';
$dbname   = 'postgres';
$user     = 'postgres.rmxquvqwzskridfqbdws';
$password = 'Sijomidhun1';   // same as in Supabase

// Supabase needs SSL
$connString = "host=$host port=$port dbname=$dbname user=$user password=$password sslmode=require connect_timeout=10";

$conn = pg_connect($connString);

if (!$conn) {
    $error = error_get_last();
    $msg = $error['message'] ?? 'Unknown error';
    
    // Check for common firewall timeout
    if (strpos($msg, 'timeout expired') !== false) {
        die("
            <div style='font-family: sans-serif; padding: 20px; text-align: center; color: #333;'>
                <h1 style='color: #dc3545;'>🔥 Firewall Block Detected</h1>
                <p>Your network (likely College WiFi) is blocking the database connection (Port 5432 or 6543).</p>
                <div style='background: #fff3cd; padding: 15px; display: inline-block; border-radius: 5px; text-align: left;'>
                    <strong>Solution:</strong>
                    <ol>
                        <li>Disconnect from College WiFi.</li>
                        <li>Connect your Laptop and Phone to a <strong>Mobile Hotspot</strong>.</li>
                        <li>Reload this page.</li>
                    </ol>
                </div>
                <p style='color: #666; font-size: 0.9em; margin-top: 20px;'>Technical Error: " . htmlspecialchars($msg) . "</p>
            </div>
        ");
    }
    
    die("❌ Database connection failed: " . $msg);
}

// Sync DB timezone with PHP timezone
pg_query($conn, "SET TIME ZONE 'Asia/Kolkata'");
ini_set('max_execution_time', 300);

// Simple helper
function db_query($sql, $params = []) {
    global $conn;
    if (empty($params)) {
        $result = pg_query($conn, $sql);
    } else {
        $result = pg_query_params($conn, $sql, $params);
    }
    if (!$result) {
        throw new Exception(pg_last_error($conn));
    }
    return $result;
}

// --- Helper Functions (Migrated from supabase.php) ---

// Wrapper for fetching all rows
function db_select($sql, $params = []) {
    $result = db_query($sql, $params);
    return pg_fetch_all($result) ?: [];
}

// Wrapper for fetching a single row
function db_select_one($sql, $params = []) {
    $result = db_query($sql, $params);
    return pg_fetch_assoc($result) ?: null;
}

// Wrapper for insert
function db_insert($table, $data) {
    global $conn;
    $result = pg_insert($conn, $table, $data);
    if (!$result) {
        throw new Exception("Insert failed: " . pg_last_error($conn));
    }
    return true;
}

// Wrapper for update
function db_update($table, $data, $conditions) {
    global $conn;
    $result = pg_update($conn, $table, $data, $conditions);
    if (!$result) {
        throw new Exception("Update failed: " . pg_last_error($conn));
    }
    return true;
}

// Wrapper for delete
function db_delete($table, $conditions) {
    global $conn;
    $result = pg_delete($conn, $table, $conditions);
    if (!$result) {
        throw new Exception("Delete failed: " . pg_last_error($conn));
    }
    return true;
}

// Audit Logging Helper
function log_audit($user_id, $action, $details = null) {
    global $conn;
    // If user_id is null (e.g. failed login), we can store NULL or 0 if schema allows.
    // Schema: user_id UUID REFERENCES users(id). So it must be valid or NULL.
    // Let's assume schema allows NULL for system events or failed logins if we don't have ID.
    // Actually, for failed login, we might not have ID.
    // Let's just try to insert. If $user_id is null, we pass NULL.
    
    // Ensure details is JSON
    if (!is_string($details) || (json_decode($details) === null && json_last_error() !== JSON_ERROR_NONE)) {
        $details = json_encode(['message' => $details]);
    }
    
    $sql = "INSERT INTO audit_logs (user_id, action, details) VALUES ($1, $2, $3)";
    // We use db_query directly to avoid circular deps or issues if db_insert uses logging later.
    // Also, we suppress errors here so logging failure doesn't break the app.
    try {
        db_query($sql, [$user_id, $action, $details]);
    } catch (Exception $e) {
        // Silently fail or log to file
        error_log("Audit Log Failed: " . $e->getMessage());
    }
}
?>
