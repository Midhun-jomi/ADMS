<?php
// modules/queue/board.php
require_once '../../includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Public Queue Board - ADMS Smart Hospital</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            font-family: 'Outfit', sans-serif;
            margin: 0;
            overflow: hidden;
            height: 100vh;
        }
        .header {
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(10px);
            padding: 30px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #334155;
        }
        .logo { font-size: 2.5rem; font-weight: 800; color: #38bdf8; }
        .clock { font-size: 2.5rem; font-weight: 600; color: #94a3b8; }
        
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            height: calc(100vh - 120px);
            gap: 20px;
            padding: 20px;
        }
        
        .panel {
            background: #1e293b;
            border-radius: 30px;
            padding: 30px;
            overflow: hidden;
            border: 1px solid #334155;
        }
        
        .now-calling {
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(14, 165, 233, 0.4); }
            70% { box-shadow: 0 0 0 40px rgba(14, 165, 233, 0); }
            100% { box-shadow: 0 0 0 0 rgba(14, 165, 233, 0); }
        }
        
        .calling-title { font-size: 2rem; opacity: 0.9; text-transform: uppercase; letter-spacing: 5px; margin-bottom: 20px; }
        .calling-name { font-size: 5rem; font-weight: 800; margin-bottom: 10px; }
        .calling-room { font-size: 3rem; background: rgba(0,0,0,0.2); padding: 10px 40px; border-radius: 100px; }
        
        .queue-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .q-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px;
            background: rgba(255,255,255,0.03);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .q-name { font-size: 2rem; font-weight: 600; }
        .q-room { font-size: 1.8rem; color: #38bdf8; font-weight: 700; }
        
        .footer-scroll {
            position: fixed;
            bottom: 0;
            width: 100%;
            background: #000;
            padding: 10px 0;
            color: #38bdf8;
            font-weight: 600;
            white-space: nowrap;
        }
        
        marquee { font-size: 1.2rem; }
    </style>
    <script>
        function updateTime() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
        }
        setInterval(updateTime, 1000);
        
        // Auto-refresh to get new calling status
        setInterval(() => {
            location.reload();
        }, 5000);
    </script>
</head>
<body onload="updateTime()">
    <?php
    $calling = db_select_one("SELECT * FROM public_queue WHERE status = 'calling' ORDER BY created_at DESC LIMIT 1");
    $history = db_select("SELECT * FROM public_queue ORDER BY created_at DESC OFFSET 1 LIMIT 5");
    ?>
    
    <div class="header">
        <div class="logo"><i class="fas fa-hospital-alt"></i> ADMS SMART OPD</div>
        <div class="clock" id="clock">--:-- --</div>
    </div>
    
    <div class="main-grid">
        <div class="panel now-calling">
            <?php if ($calling): ?>
                <div class="calling-title">Now Calling</div>
                <div class="calling-name"><?php echo htmlspecialchars($calling['patient_name']); ?></div>
                <div class="calling-room">Proceed to Room: <?php echo htmlspecialchars($calling['room_number']); ?></div>
                <audio autoplay>
                    <source src="https://www.soundjay.com/buttons/beep-07a.mp3" type="audio/mpeg">
                </audio>
            <?php else: ?>
                <div class="calling-title">System Ready</div>
                <div class="calling-name">Waiting...</div>
                <div class="calling-room">Please watch this board</div>
            <?php endif; ?>
        </div>
        
        <div class="panel">
            <h2 style="margin-top: 0; font-size: 2.5rem; border-bottom: 2px solid #334155; padding-bottom: 15px;">Recent Calls</h2>
            <div class="queue-list">
                <?php foreach ($history as $h): ?>
                    <div class="q-item">
                        <div class="q-name"><?php echo htmlspecialchars($h['patient_name']); ?></div>
                        <div class="q-room">Room <?php echo htmlspecialchars($h['room_number']); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($history) && !$calling): ?>
                    <p style="text-align: center; font-size: 1.5rem; opacity: 0.5; margin-top: 50px;">No patient records found today.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="footer-scroll">
        <marquee behavior="scroll" direction="left">
            Welcome to ADMS Smart Hospital. Please keep your UHID Card ready. &bull; Masks are mandatory in the waiting area. &bull; AI-Triage is active for all patients. &bull; Next free consultation slot available at 3:00 PM.
        </marquee>
    </div>
</body>
</html>
