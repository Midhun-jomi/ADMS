<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Doctors, Nurses
$allowed_roles = ['doctor', 'nurse', 'admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "AI Diagnosis Assistant";
require_once '../../includes/header.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-robot"></i> AI Diagnostic Support</h1>
        <p class="text-muted">Powered by AI: Symptom-based triage and diagnosis suggestions.</p>
    </div>

    <div class="card">
        <div class="form-group">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Enter Patient Symptoms</label>
            <textarea id="symptoms" class="form-control" rows="3" placeholder="e.g. fever, cough, chest pain..."></textarea>
        </div>

        <div class="form-group" style="margin-top: 15px;">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Medical History (optional)</label>
            <textarea id="medical-history" class="form-control" rows="2" placeholder="e.g. Diabetes, Asthma, High BP..."></textarea>
        </div>

        <!-- Quick Keywords -->
        <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">
            <span style="font-size: 0.85em; color: #777; align-self: center; margin-right: 5px;">Quick Add:</span>
            <?php
            $keywords = [
                "Fever", "Cough", "Headache", "Stomach Pain",
                "Chest Pain", "Wheezing", "Nausea", "Dizziness",
                "Fatigue", "Sore Throat", "Breathing Issue",
                "Blurred Vision", "Night Sweats", "Body Pain",
                "Cut/Bleeding", "Frequent Urination"
            ];
            foreach ($keywords as $k):
            ?>
                <button type="button" onclick="addKeyword('<?php echo $k; ?>')"
                        class="keyword-btn">
                    <?php echo $k; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <button type="button" onclick="analyzeSymptoms()" class="btn-analyze" id="analyze-btn" style="margin-top: 20px;">
            <i class="fas fa-brain"></i> Analyze Symptoms
        </button>
    </div>

    <!-- AI Result Card -->
    <div id="ai-result-card" class="card mt-3" style="display: none;">
        <h3><i class="fas fa-stethoscope"></i> Analysis Result</h3>
        <div class="ai-result">
            <div class="res-item">
                <div class="res-label">Suggested Condition</div>
                <div class="res-value" id="result-disease">—</div>
            </div>
            <div class="res-item">
                <div class="res-label">Recommended Specialist</div>
                <div class="res-value" id="result-specialization">—</div>
            </div>
            <div class="res-item">
                <div class="res-label">Triage Urgency</div>
                <div id="result-urgency-container">
                    <span class="badge" id="result-urgency">—</span>
                </div>
            </div>
        </div>
        <p class="text-small text-muted mt-2">
            <i>Disclaimer: AI suggestions are for assistance only and do not replace professional medical advice.</i>
        </p>
    </div>

    <!-- Loading Spinner -->
    <div id="ai-loading" class="card mt-3" style="display: none; text-align: center; padding: 40px;">
        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #007bff;"></i>
        <p style="margin-top: 10px; color: #555;">Analyzing symptoms with AI...</p>
    </div>

    <!-- Error Card -->
    <div id="ai-error" class="card mt-3" style="display: none; border-left: 5px solid #e74c3c;">
        <p style="color: #e74c3c; margin: 0;"><i class="fas fa-exclamation-triangle"></i> <span id="error-msg"></span></p>
    </div>
</div>

<style>
    .ai-result {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1.5rem;
        margin-top: 1.5rem;
    }
    @media(max-width: 700px) {
        .ai-result { grid-template-columns: 1fr; }
    }
    .res-item {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
    }
    .res-label {
        font-size: 0.85em;
        color: #777;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
    }
    .res-value {
        font-size: 1.3em;
        font-weight: 600;
        color: #333;
    }
    .mt-3 { margin-top: 1.5rem; }
    .text-small { font-size: 0.85rem; }
    .keyword-btn {
        background: #e2e8f0;
        border: none;
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.85em;
        color: #4a5568;
        cursor: pointer;
        transition: background 0.2s;
    }
    .keyword-btn:hover {
        background: #cbd5e0;
    }
    .btn-analyze {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 1em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-analyze:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    .btn-analyze:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    .badge-critical { background: #e74c3c; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 1em; font-weight: 600; }
    .badge-high { background: #f39c12; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 1em; font-weight: 600; }
    .badge-medium { background: #3498db; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 1em; font-weight: 600; }
    .badge-low { background: #2ecc71; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 1em; font-weight: 600; }

    #ai-result-card {
        border-left: 5px solid #667eea;
        animation: fadeIn 0.4s ease;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<script>
function addKeyword(keyword) {
    const textarea = document.getElementById('symptoms');
    if (textarea.value.trim() !== "") {
        textarea.value += ", " + keyword;
    } else {
        textarea.value = keyword;
    }
}

function analyzeSymptoms() {
    const symptoms = document.getElementById('symptoms').value.trim();
    const history = document.getElementById('medical-history').value.trim();
    const resultCard = document.getElementById('ai-result-card');
    const loadingCard = document.getElementById('ai-loading');
    const errorCard = document.getElementById('ai-error');
    const btn = document.getElementById('analyze-btn');

    if (!symptoms) {
        alert("Please enter patient symptoms first.");
        return;
    }

    // Reset UI
    resultCard.style.display = 'none';
    errorCard.style.display = 'none';
    loadingCard.style.display = 'block';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';

    fetch('<?php echo rtrim(BASE_URL, '/'); ?>/modules/ehr/get_ai_recommendation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: symptoms, history: history })
    })
    .then(response => response.json())
    .then(data => {
        loadingCard.style.display = 'none';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-brain"></i> Analyze Symptoms';

        if (data.error) {
            errorCard.style.display = 'block';
            document.getElementById('error-msg').textContent = data.error;
            return;
        }

        const disease = data.disease || "Unknown";
        const specialization = data.specialization || "General Medicine";
        const urgency = data.urgency || "Low";

        document.getElementById('result-disease').textContent = disease;
        document.getElementById('result-specialization').textContent = specialization;

        const urgencyEl = document.getElementById('result-urgency');
        urgencyEl.textContent = urgency;
        urgencyEl.className = 'badge badge-' + urgency.toLowerCase();

        // Color the result card border based on urgency
        const colors = { 'Critical': '#e74c3c', 'High': '#f39c12', 'Medium': '#3498db', 'Low': '#2ecc71' };
        resultCard.style.borderLeftColor = colors[urgency] || '#667eea';

        resultCard.style.display = 'block';
    })
    .catch(error => {
        console.error('Error:', error);
        loadingCard.style.display = 'none';
        errorCard.style.display = 'block';
        document.getElementById('error-msg').textContent = "Error connecting to AI service. Please ensure the AI server is running.";
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-brain"></i> Analyze Symptoms';
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>
