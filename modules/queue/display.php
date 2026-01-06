<?php
session_start();
require_once '../../includes/db.php';

// Public Access allowed (or restricted to reception IP in real scenario)
// For now, allow logged in users (Admin/Receptionist/Patient)
if (!isset($_SESSION['user_id'])) {
    // Ideally this might be public, but let's keep it behind auth for this demo
    // or just allow it. Let's allow public for "TV Mode".
}

$page_title = "Queue Status Board";
// Custom raw HTML header for full screen TV mode
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Q-Monitor | ADMS Hospital</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #2c3e50; --accent-color: #3498db; --bg-color: #f4f6f9; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-color); margin: 0; padding: 0; height: 100vh; overflow: hidden; }
        .tv-layout { display: grid; grid-template-columns: 2fr 1fr; height: 100vh; }
        
        .main-queue { background: white; padding: 2rem; display: flex; flex-direction: column; justify-content: center; align-items: center; border-right: 5px solid var(--accent-color); }
        .token-large { font-size: 8rem; font-weight: 800; color: var(--primary-color); animation: pulse 2s infinite; }
        .room-text { font-size: 3rem; color: #7f8c8d; }
        .status-badge { background: #2ecc71; color: white; padding: 0.5rem 2rem; border-radius: 50px; font-size: 1.5rem; text-transform: uppercase; margin-bottom: 2rem; }
        
        .upcoming-list { background: var(--primary-color); color: white; padding: 2rem; }
        .list-header { font-size: 2rem; border-bottom: 2px solid rgba(255,255,255,0.2); padding-bottom: 1rem; margin-bottom: 2rem; }
        .queue-item { display: flex; justify-content: space-between; font-size: 1.5rem; padding: 1rem 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .queue-item span { font-weight: 600; }
        
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
    </style>
</head>
<body>

<?php
// Mock Data (Since we don't have a robust Token system linked to appointments yet)
// In a real app, join appointments with room numbers.
$current_token = "A-104";
$current_room = "Dr. Smith - Room 102";
$upcoming = [
    ['token' => 'A-105', 'dept' => 'Gen. Medicine'],
    ['token' => 'B-202', 'dept' => 'Cardiology'],
    ['token' => 'A-106', 'dept' => 'Gen. Medicine'],
    ['token' => 'C-301', 'dept' => 'Orthopedics'],
];
?>

<div class="tv-layout">
    <div class="main-queue">
        <div class="status-badge">Now Serving</div>
        <div class="token-large"><?php echo $current_token; ?></div>
        <div class="room-text"><?php echo $current_room; ?></div>
        <div style="margin-top: 3rem; font-size: 1.2rem; color: #95a5a6;">ADMS Hospital Queue Management</div>
    </div>
    
    <div class="upcoming-list">
        <div class="list-header">Up Next</div>
        <?php foreach ($upcoming as $item): ?>
        <div class="queue-item">
            <span><?php echo $item['token']; ?></span>
            <span style="font-weight: 400; opacity: 0.8; font-size: 1.2rem;"><?php echo $item['dept']; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Auto-refresh every 30s to simulate live updates -->
<script>
    setTimeout(function(){ window.location.reload(); }, 30000);
</script>

</body>
</html>
