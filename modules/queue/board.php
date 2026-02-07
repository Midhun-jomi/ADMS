<?php
// modules/queue/board.php
require_once '../../includes/db.php';
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
            --danger: #ef4444;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            margin: 0;
            padding: 30px;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            padding-bottom: 20px;
        }
        .app-logo { font-size: 3rem; font-weight: 800; color: var(--accent); display: flex; align-items: center; gap: 20px; letter-spacing: 2px; }
        .live-clock { font-size: 3rem; font-weight: 300; color: white; display: flex; align-items: center; gap: 15px;}
        .date-box { font-size: 1.2rem; color: var(--text-secondary); text-align: right; margin-right: 20px; border-right: 1px solid rgba(255,255,255,0.2); padding-right: 20px; }

        /* Main Grid */
        .main-content {
            display: grid;
            grid-template-columns: 2fr 1fr; /* Now Serving (Left) vs Up Next (Right) */
            gap: 40px;
            flex-grow: 1;
        }

        /* LEFT: Now Serving Cards */
        .now-serving-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            align-content: start;
        }

        .doctor-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 0;
            border: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            position: relative;
            overflow: hidden;
            min-height: 280px;
            transition: transform 0.4s ease, border-color 0.4s ease;
        }
        .doctor-card.just-called {
            border-color: var(--success);
            animation: pulse-border 2s infinite;
        }
        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        .card-header {
            background: rgba(0,0,0,0.2);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-badge {
            background: var(--accent);
            color: #000;
            padding: 5px 15px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 1.2rem;
            text-transform: uppercase;
        }

        .card-body {
            padding: 25px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        
        .status-label {
            font-size: 1rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }
        
        .token-display {
            font-size: 4rem;
            font-weight: 800;
            color: white;
            line-height: 1;
            margin-bottom: 10px;
            text-shadow: 0 5px 15px rgba(0,0,0,0.5);
        }
        
        .patient-name {
            font-size: 1.8rem;
            color: var(--success);
            font-weight: 600;
        }

        .card-footer {
            padding: 15px;
            background: rgba(255,255,255,0.02);
            color: var(--text-secondary);
            text-align: center;
            font-size: 1.1rem;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        /* RIGHT: Up Next List */
        .up-next-panel {
            background: rgba(255,255,255,0.03);
            border-radius: 20px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.05);
            display: flex;
            flex-direction: column;
        }
        .panel-title {
            font-size: 1.5rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 2px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .next-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 1.3rem;
        }
        .next-token { font-weight: 700; color: white; width: 60px; }
        .next-name { color: var(--text-secondary); flex-grow: 1; }
        .next-room { color: var(--accent); font-weight: 600; }

        /* Footer Ticker */
        .footer {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 20px;
            background: rgba(0,0,0,0.3);
            padding: 15px;
            border-radius: 10px;
        }
        marquee { font-size: 1.5rem; color: var(--warning); font-weight: 600; }

    </style>
</head>
<body onload="initBoard()">

    <div class="header">
        <div class="app-logo"><i class="fas fa-plane-departure"></i> OPD QUEUE</div>
        <div class="live-clock">
            <div class="date-box" id="date-display">Mon, 01 Jan</div>
            <div id="clock">12:00:00</div>
        </div>
    </div>

    <div class="main-content">
        <!-- Left: Active Doctors -->
        <div class="now-serving-container" id="now-serving">
            <!-- Injected via JS -->
            <div style="grid-column: 1/-1; text-align: center; color: #555; font-size: 2rem;">Loading Data...</div>
        </div>

        <!-- Right: Up Next -->
        <div class="up-next-panel">
            <div class="panel-title">Coming Up Next</div>
            <div id="up-next-list">
                <!-- Injected via JS -->
            </div>
        </div>
    </div>

    <div class="footer">
        <marquee>
            Please keep your Token Number ready. Emergency cases will be prioritized. Please wear a mask if you have flu-like symptoms.
        </marquee>
    </div>

    <!-- Backend API Shim included directly for simplicity or fetch proper endpoint -->
    <script>
        let lastSpeakId = null;

        function initBoard() {
            startTime();
            fetchData();
            setInterval(fetchData, 4000); // Poll every 4 seconds
        }

        function startTime() {
            const today = new Date();
            let h = today.getHours();
            let m = today.getMinutes();
            // let s = today.getSeconds();
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12; // the hour '0' should be '12'
            m = checkTime(m);
            // s = checkTime(s);
            document.getElementById('clock').innerHTML =  h + ":" + m + " <span style='font-size:0.5em; vertical-align:top;'>" + ampm + "</span>";
            
            const options = { weekday: 'short', month: 'short', day: 'numeric' };
            document.getElementById('date-display').innerText = today.toLocaleDateString('en-US', options);
            
            setTimeout(startTime, 1000);
        }

        function checkTime(i) {
            return (i < 10) ? "0" + i : i;
        }

        async function fetchData() {
            try {
                // We need an endpoint for this. Creating a simple one inline via a PHP file would be best
                // For now, let's assume we hit a new endpoint 'api_queue_board.php'
                const response = await fetch('api_queue_board.php');
                const data = await response.json();

                renderBoard(data);
                checkVoiceAnnouncement(data);

            } catch (error) {
                console.error('Error fetching board data:', error);
            }
        }

        function renderBoard(data) {
            const servingContainer = document.getElementById('now-serving');
            const nextContainer = document.getElementById('up-next-list');

            // Render Active
            let servingHtml = '';
            if(data.active && data.active.length > 0) {
                data.active.forEach(doc => {
                    const isJustCalled = (Date.now() / 1000) - doc.called_at_ts < 20; // Highlight for 20s
                    const activeClass = isJustCalled ? 'just-called' : '';
                    
                    servingHtml += `
                    <div class="doctor-card ${activeClass}">
                        <div class="card-header">
                            <i class="fas fa-user-md" style="font-size: 1.5rem; color: #94a3b8;"></i>
                            <div class="room-badge">${doc.room}</div>
                        </div>
                        <div class="card-body">
                            <div class="status-label">Now Serving</div>
                            <div class="token-display">#${doc.token}</div>
                            <div class="patient-name">${doc.patient_name}</div>
                        </div>
                        <div class="card-footer">
                            Dr. ${doc.doc_name}
                        </div>
                    </div>
                    `;
                });
            } else {
                servingHtml = '<div style="grid-column: 1/-1; text-align: center; font-size: 1.5rem; color: #555; margin-top:50px;">Waiting for Operations...</div>';
            }
            servingContainer.innerHTML = servingHtml;

            // Render Next
            let nextHtml = '';
            if(data.next && data.next.length > 0) {
                data.next.slice(0, 6).forEach(p => {
                    nextHtml += `
                    <div class="next-item">
                        <div class="next-token">#${p.token}</div>
                        <div class="next-name">${p.patient_name}</div>
                        <div class="next-room">Rm ${p.room}</div>
                    </div>
                    `;
                });
            } else {
                nextHtml = '<div style="text-align:center; padding:20px; color:#555;">Queue is empty</div>';
            }
            nextContainer.innerHTML = nextHtml;
        }

        function checkVoiceAnnouncement(data) {
            // Find the most recent 'just called' patient
            // Logic: Server returns 'last_called_id'
            if (data.last_announcement && data.last_announcement.id !== lastSpeakId) {
                const ann = data.last_announcement;
                lastSpeakId = ann.id;
                
                // Construct Speech
                const text = `Attention please. Token Number ${ann.token}, ${ann.patient_name}, please proceed to Room ${ann.room} for Doctor ${ann.doc_last_name}.`;
                speak(text);
            }
        }

        function speak(text) {
            if ('speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(text);
                utterance.rate = 0.9;
                utterance.pitch = 1;
                utterance.volume = 1;
                window.speechSynthesis.speak(utterance);
            }
        }
    </script>
</body>
</html>
