<?php
// modules/queue/api_queue_board.php
require_once '../../includes/db.php';

header('Content-Type: application/json');

/*
  Data Structure:
  {
      "active": [ { "doc_name", "room", "patient_name", "token", "called_at_ts" } ],
      "next":   [ { "patient_name", "token", "room" } ],
      "last_announcement": { "id", "token", "patient_name", "room", "doc_last_name" }
  }
*/

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// 1. Get Active Consultations (Now Serving)
// Sort by most recently started (consultation_start)
$active_sql = "
    SELECT 
        a.id,
        s.first_name as doc_first,
        s.last_name as doc_last,
        r.room_number,
        p.first_name as p_first,
        p.last_name as p_last,
        a.token_number,
        a.consultation_start
    FROM appointments a
    JOIN staff s ON a.doctor_id = s.id
    LEFT JOIN rooms r ON s.primary_room_id = r.id
    JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_time::date = $1
    AND a.status = 'consulting'
    ORDER BY a.consultation_start DESC
";
$active_rows = db_select($active_sql, [$today]);

$active_data = [];
foreach($active_rows as $row) {
    // Generate Token if null
    $token = $row['token_number'] ? $row['token_number'] : ('T' . substr($row['id'], -4));
    
    $active_data[] = [
        'id' => $row['id'],
        'doc_name' => $row['doc_last'],
        'room' => $row['room_number'] ?? 'G01',
        'patient_name' => $row['p_first'] . ' ' . substr($row['p_last'], 0, 1) . '.', // Shield full name privacy
        'token' => $token,
        'called_at_ts' => strtotime($row['consultation_start'])
    ];
}

// 2. Get Waiting List (Next Up)
$next_sql = "
    SELECT 
        a.id,
        r.room_number,
        p.first_name as p_first,
        p.last_name as p_last,
        a.token_number
    FROM appointments a
    JOIN staff s ON a.doctor_id = s.id
    LEFT JOIN rooms r ON s.primary_room_id = r.id
    JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_time::date = $1
    AND a.status IN ('waiting', 'ready')
    ORDER BY a.appointment_time ASC
    LIMIT 10
";
$next_rows = db_select($next_sql, [$today]);

$next_data = [];
foreach($next_rows as $row) {
    $token = $row['token_number'] ? $row['token_number'] : ('T' . substr($row['id'], -4));
    $next_data[] = [
        'patient_name' => $row['p_first'] . ' ' . substr($row['p_last'], 0, 1) . '.',
        'token' => $token,
        'room' => $row['room_number'] ?? 'G01'
    ];
}

// 3. Last Announcement
// Return the most recently started consultation (within last 30 seconds) to trigger voice
$last_announcement = null;
if (!empty($active_data)) {
    $most_recent = $active_data[0]; // First one is most recent due to DESC sort
    // Check if started in last 20 seconds
    if ((time() - $most_recent['called_at_ts']) < 20) {
        $last_announcement = [
            'id' => $most_recent['id'],
            'token' => $most_recent['token'],
            'patient_name' => $most_recent['patient_name'],
            'room' => $most_recent['room'],
            'doc_last_name' => $most_recent['doc_name']
        ];
    }
}

echo json_encode([
    'active' => $active_data,
    'next' => $next_data,
    'last_announcement' => $last_announcement
]);
?>
