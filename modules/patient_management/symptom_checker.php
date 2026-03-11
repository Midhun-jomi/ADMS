<?php
// modules/patient_management/symptom_checker.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']); // Only patients

$page_title = "AI Symptom Checker";
include '../../includes/header.php';
?>

<style>
    .chat-container {
        max-width: 800px;
        margin: 20px auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        height: 70vh;
        border: 1px solid #e1e8f0;
    }
    
    .chat-header {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: white;
        padding: 20px;
        border-radius: 12px 12px 0 0;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .chat-header img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid rgba(255,255,255,0.2);
    }
    
    .chat-messages {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        background: #f8fafc;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .msg-bubble {
        max-width: 75%;
        padding: 12px 18px;
        border-radius: 18px;
        font-size: 0.95em;
        line-height: 1.5;
        position: relative;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    
    .msg-ai {
        align-self: flex-start;
        background: white;
        color: #334155;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    
    .msg-user {
        align-self: flex-end;
        background: #21a9af;
        color: white;
        border-bottom-right-radius: 4px;
        box-shadow: 0 2px 4px rgba(33, 169, 175, 0.2);
    }
    
    .chat-input-area {
        padding: 15px 20px;
        background: white;
        border-top: 1px solid #e2e8f0;
        border-radius: 0 0 12px 12px;
        display: flex;
        gap: 10px;
    }
    
    .chat-input {
        flex: 1;
        padding: 12px 20px;
        border: 1px solid #cbd5e1;
        border-radius: 25px;
        outline: none;
        transition: 0.2s;
        font-family: inherit;
    }
    
    .chat-input:focus {
        border-color: #21a9af;
        box-shadow: 0 0 0 3px rgba(33, 169, 175, 0.1);
    }
    
    .send-btn {
        background: #21a9af;
        color: white;
        border: none;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        cursor: pointer;
        transition: 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1em;
    }
    
    .send-btn:hover {
        background: #148f94;
        transform: scale(1.05);
    }
    
    .diagnosis-card {
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        padding: 15px;
        margin-top: 10px;
        color: #166534;
    }
    
    .typing-indicator {
        display: none;
        align-items: center;
        gap: 5px;
        padding: 12px 18px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        border-bottom-left-radius: 4px;
        align-self: flex-start;
        width: max-content;
    }
    
    .dot { width: 6px; height: 6px; background: #94a3b8; border-radius: 50%; animation: typing 1.4s infinite ease-in-out both; }
    .dot:nth-child(1) { animation-delay: -0.32s; }
    .dot:nth-child(2) { animation-delay: -0.16s; }
    
    @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
</style>

<div class="chat-container">
    <div class="chat-header">
        <div style="background: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #21a9af; font-size: 1.2em;">
            <i class="fas fa-robot"></i>
        </div>
        <div>
            <h3 style="margin: 0; font-size: 1.2em; font-weight: 600;">MediBot Assistant</h3>
            <span style="font-size: 0.8em; color: #94a3b8;"><i class="fas fa-circle" style="color: #10b981; font-size: 0.8em;"></i> Online - AI Symptom Checker</span>
        </div>
        <button onclick="resetChat()" class="btn btn-sm btn-outline-light" style="margin-left: auto; border: 1px solid rgba(255,255,255,0.3); color: white;">
            <i class="fas fa-sync-alt"></i> Restart
        </button>
    </div>
    
    <div class="chat-messages" id="chatBox">
        <div class="msg-bubble msg-ai">
            Hello! I am MediBot. I can help predict possible conditions based on your symptoms. <br><br>
            Please note that I am an AI and this is <strong>not a medical diagnosis</strong>. <br><br>
            What brings you here today? (e.g., "I have a severe headache", "My stomach hurts")
        </div>
        
        <div class="typing-indicator" id="typingIndicator">
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
        </div>
    </div>
    
    <div class="chat-input-area">
        <input type="text" id="userInput" class="chat-input" placeholder="Type your symptoms here..." onkeypress="handleKeyPress(event)">
        <button class="send-btn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<script>
    let chatHistory = [];
    let isFinished = false;

    // Helper to format text with mild markdown (bold text)
    function formatMessage(text) {
        return text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    }

    function appendMessage(role, text, diagnosis = null, urgency = null, spec = null) {
        const chatBox = document.getElementById('chatBox');
        const indicator = document.getElementById('typingIndicator');

        const div = document.createElement('div');
        div.className = `msg-bubble msg-${role === 'user' ? 'user' : 'ai'}`;

        let html = formatMessage(text);

        if (diagnosis) {
            let urgencyColor = urgency === 'Critical' ? '#ef4444' : (urgency === 'High' ? '#f59e0b' : '#3b82f6');
            let bgClass = urgency === 'Critical' ? 'background: #fef2f2; border-color: #fecaca;' : '';

            html += `
                <div class="diagnosis-card" style="${bgClass}">
                    <div style="font-size: 0.85em; text-transform:uppercase; font-weight:700; color: #64748b; margin-bottom:5px;">AI Preliminary Assessment</div>
                    <div style="font-size: 1.1em; font-weight: 600; margin-bottom: 5px;">${diagnosis}</div>
                    ${spec ? `<div style="font-size: 0.9em; margin-bottom: 8px; color: #475569;"><i class="fas fa-hospital-user"></i> Recommended Dept: <strong>${spec}</strong></div>` : ''}
                    <span style="font-size: 0.8em; font-weight: 600; padding: 3px 8px; border-radius: 12px; background: white; border: 1px solid ${urgencyColor}; color: ${urgencyColor};">
                        Priority: ${urgency || 'Normal'}
                    </span>
                    <div id="doctorSuggestions" style="margin-top: 12px;"></div>
                    <div style="margin-top: 10px;">
                        <a href="/modules/ehr/book_appointment.php" class="btn btn-sm btn-primary" style="background: #21a9af; border: none; padding: 5px 15px; font-size: 0.85em;">Book Doctor Appointment</a>
                    </div>
                </div>
            `;

            // Fetch suggested doctors after rendering
            if (spec) {
                setTimeout(() => loadSuggestedDoctors(spec), 100);
            }
        }

        div.innerHTML = html;

        // Insert before typing indicator
        chatBox.insertBefore(div, indicator);
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    function loadSuggestedDoctors(spec) {
        fetch(`api_suggest_doctors.php?specialization=${encodeURIComponent(spec)}`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('doctorSuggestions');
            if (!container) return;
            if (!data.doctors || data.doctors.length === 0) return;

            let html = `<div style="font-size: 0.85em; font-weight: 700; color: #166534; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-user-md"></i> Available Doctors
                        </div>`;
            data.doctors.forEach(doc => {
                const load = parseInt(doc.appt_count);
                const loadColor = load <= 3 ? '#16a34a' : load <= 7 ? '#d97706' : '#dc2626';
                const loadLabel = load <= 3 ? 'Low Load' : load <= 7 ? 'Moderate' : 'Busy';
                const bookUrl = `/modules/ehr/book_appointment.php?doctor_id=${encodeURIComponent(doc.id)}&spec=${encodeURIComponent(doc.specialization)}`;
                html += `
                    <div style="display: flex; align-items: center; justify-content: space-between; background: white; border-radius: 8px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid #d1fae5;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1; font-size: 1em;">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: #1e293b; font-size: 0.9em;">Dr. ${doc.first_name} ${doc.last_name}</div>
                                <div style="font-size: 0.78em; color: #64748b;">${doc.specialization}</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.75em; font-weight: 600; color: ${loadColor}; background: ${loadColor}18; padding: 2px 8px; border-radius: 99px;">${loadLabel}</div>
                                <div style="font-size: 0.72em; color: #94a3b8; margin-top: 2px;">${load} today</div>
                            </div>
                            <a href="${bookUrl}" style="background: #21a9af; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 0.78em; font-weight: 600; text-decoration: none; white-space: nowrap;">
                                Book
                            </a>
                        </div>
                    </div>`;
            });

            container.innerHTML = html;
        })
        .catch(() => {});
    }

    function sendMessage() {
        if (isFinished) return;
        
        const input = document.getElementById('userInput');
        const text = input.value.trim();
        if (!text) return;
        
        // 1. Add User Message
        appendMessage('user', text);
        chatHistory.push({ role: 'user', content: text });
        
        input.value = '';
        input.disabled = true;
        
        // 2. Show Typing
        const indicator = document.getElementById('typingIndicator');
        const chatBox = document.getElementById('chatBox');
        indicator.style.display = 'flex';
        chatBox.scrollTop = chatBox.scrollHeight;
        
        // 3. Send to Python Backend
        fetch('api_symptom_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages: chatHistory })
        })
        .then(res => res.json())
        .then(data => {
            indicator.style.display = 'none';
            input.disabled = false;
            input.focus();
            
            if (data.error) {
                appendMessage('ai', "Sorry, I'm having trouble connecting to the brain center right now. Please try again later.");
                return;
            }
            
            appendMessage('ai', data.reply, data.diagnosis, data.urgency, data.specialization);
            chatHistory.push({ role: 'assistant', content: data.reply });
            
            if (data.finished) {
                isFinished = true;
                input.placeholder = "Consultation finished. Please restart to try again.";
                input.disabled = true;
            }
        })
        .catch(err => {
            console.error(err);
            indicator.style.display = 'none';
            input.disabled = false;
            appendMessage('ai', "I'm offline. Make sure the AI server is running.");
        });
    }
    
    function handleKeyPress(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    }
    
    function resetChat() {
        chatHistory = [];
        isFinished = false;
        
        const chatBox = document.getElementById('chatBox');
        const indicator = document.getElementById('typingIndicator');
        
        // Clear all bubbles except the first one and the typing indicator
        const bubbles = chatBox.querySelectorAll('.msg-bubble');
        for (let i = 1; i < bubbles.length; i++) {
            bubbles[i].remove();
        }
        
        const input = document.getElementById('userInput');
        input.value = '';
        input.disabled = false;
        input.placeholder = "Type your symptoms here...";
        input.focus();
    }
</script>

<?php include '../../includes/footer.php'; ?>
