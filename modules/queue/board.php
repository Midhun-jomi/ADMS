<?php
// modules/queue/board.php
require_once '../../includes/db.php';

// Fetch stats for all doctors with appointments TODAY
$today = date('Y-m-d');
$doctors_data = db_select("
    SELECT 
        s.id as doctor_id, 
        s.first_name, 
        s.last_name, 
        s.room_number,
        COUNT(CASE WHEN a.status IN ('scheduled', 'confirmed') THEN 1 END) as waiting_count,
        MAX(CASE WHEN a.status = 'in_progress' THEN COALESCE(p.first_name, '') || ' ' || COALESCE(p.last_name, '') END) as current_patient
    FROM staff s
    JOIN appointments a ON s.id = a.doctor_id
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_time::date = '$today' AND s.role = 'doctor' AND s.status = 'active'
    GROUP BY s.id, s.first_name, s.last_name, s.room_number
    HAVING COUNT(a.id) > 0
");

// Determine the active call (same logic as before or derived from update)
// For simplicity, we just use the first 'in_progress' one found as the "Just Called" if we wanted to animate it.
// But the user asked for a "Board" with doctor/patient count.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Queue Board - ADMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #38bdf8;
            --success: #22c55e;
            --warning: #f59e0b;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            margin: 0;
            padding: 20px;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 0 10px;
        }
        .app-logo { font-size: 2.5rem; font-weight: 800; color: var(--accent); display: flex; align-items: center; gap: 15px; }
        .live-clock { font-size: 2.5rem; font-weight: 300; color: var(--text-secondary); }
        
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            flex-grow: 1;
            align-content: start;
        }
        
        .doctor-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
            gap: 15px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .doctor-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 15px;
        }
        .dr-name { font-size: 1.5rem; font-weight: 700; color: white; }
        .dr-room { 
            background: rgba(56, 189, 248, 0.1); 
            color: var(--accent); 
            padding: 5px 12px; 
            border-radius: 8px; 
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        
        .current-patient-box {
            flex: 2;
        }
        .label { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 5px; }
        .patient-name { 
            font-size: 1.8rem; 
            font-weight: 600; 
            color: var(--success); 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            max-width: 220px;
        }
        .empty-state { color: var(--text-secondary); font-style: italic; font-size: 1.4rem; }
        
        .waiting-box {
            flex: 1;
            text-align: right;
            background: rgba(255,255,255,0.03);
            padding: 10px;
            border-radius: 12px;
        }
        .count-big { font-size: 2.5rem; font-weight: 800; color: var(--warning); line-height: 1; }
        
        /* Auto-scroll marquee for bottom info */
        .footer {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 20px;
        }
    </style>
</head>
<body onload="startTime()">

    <div class="header">
        <div class="app-logo"><i class="fas fa-heartbeat"></i> OPD STATUS BOARD</div>
        <div class="live-clock" id="clock">--:--</div>
    </div>

    <div class="grid-container">
        <?php if (empty($doctors_data)): ?>
            <div style="grid-column: 1/-1; text-align: center; margin-top: 50px;">
                <h2 style="color: var(--text-secondary);">No active OPD sessions right now.</h2>
            </div>
        <?php else: ?>
            <?php foreach ($doctors_data as $dr): ?>
                <div class="doctor-card">
                    <div class="doctor-header">
                        <div>
                            <div class="dr-name">Dr. <?php echo htmlspecialchars($dr['first_name'] . ' ' . $dr['last_name']); ?></div>
                            <div style="color: var(--text-secondary); font-size: 0.9rem;">General Medicine</div>
                        </div>
                        <div class="dr-room">Room <?php echo htmlspecialchars($dr['room_number']); ?></div>
                    </div>
                    
                    <div class="status-row">
                        <div class="current-patient-box">
                            <div class="label">Now Serving</div>
                            <?php if ($dr['current_patient']): ?>
                                <div class="patient-name"><?php echo htmlspecialchars($dr['current_patient']); ?></div>
                            <?php else: ?>
                                <div class="patient-name empty-state">Waiting...</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="waiting-box">
                            <div class="count-big"><?php echo $dr['waiting_count']; ?></div>
                            <div class="label" style="font-size: 0.7rem;">In Queue</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="footer">
        <marquee style="font-size: 1.2rem; color: var(--accent);">
            Please keep silence in the waiting area. • Have your insurance documents ready. • Use the sanitizers provided. • Emergency Ward is on the Ground Floor.
        </marquee>
    </div>

    <script>
        function startTime() {
            const today = new Date();
            let h = today.getHours();
            let m = today.getMinutes();
            let s = today.getSeconds();
            m = checkTime(m);
            s = checkTime(s);
            document.getElementById('clock').innerHTML =  h + ":" + m + ":" + s;
            setTimeout(startTime, 1000);
        }

        function checkTime(i) {
            if (i < 10) {i = "0" + i};  // add zero in front of numbers < 10
            return i;
        }

        // Auto Refresh
        setTimeout(() => {
            window.location.reload();
        }, 5000);
    </script>
</body>
</html>
