<?php
// modules/ehr/book_appointment.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);

$page_title = "Book Appointment";
include '../../includes/header.php';

$error = '';
$success = '';

// Fetch doctors
$doctors = db_select("SELECT id, first_name, last_name, specialization FROM staff WHERE role = 'doctor'");

// Extract all valid specializations from the master list
require_once '../../includes/specializations.php';
$specializations = get_specializations();
sort($specializations);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $doctor_id = $_POST['doctor_id'] ?? null;
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $reason = $_POST['reason'] ?? '';
    
    if (!$doctor_id || !$date || !$time) {
        $error = "Please fill in all required fields (Doctor, Date, Time).";
    } else {
        $appointment_time = $date . ' ' . $time;
    
    // Get patient ID
    $user_id = $_SESSION['user_id'];
    $patient = db_select_one("SELECT id, first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
    
    if ($patient) {
        // Server-side validation: Prevent past dates
        if (strtotime($appointment_time) < time()) {
            $error = "Cannot book an appointment in the past. Please select a future date and time.";
        } else {
            // Check doctor has an approved leave on that date
            $appt_date = date('Y-m-d', strtotime($appointment_time));
            $av_check  = db_select_one(
                "SELECT is_available, unavailability_type FROM doctor_availability
                 WHERE doctor_id = $1 AND available_date = $2 AND approval_status = 'approved'",
                [$doctor_id, $appt_date]
            );
            if ($av_check && ($av_check['is_available'] === 'f' || $av_check['is_available'] === false)) {
                $type  = ucfirst($av_check['unavailability_type'] ?? 'leave');
                $error = "This doctor is on $type on the selected date. Please choose another date.";
            }
        }
        if (empty($error)) {
            // Check if Doctor is booked
            $existing = db_select_one("SELECT id FROM appointments WHERE doctor_id = $1 AND appointment_time = $2 AND status = 'scheduled'", [$doctor_id, $appointment_time]);
            
            // Check if Patient is already booked somewhere else at this time
            $patient_booked = db_select_one("SELECT id FROM appointments WHERE patient_id = $1 AND appointment_time = $2 AND status = 'scheduled'", [$patient['id'], $appointment_time]);
            
            if ($existing) {
                 $error = "This slot is already booked for this doctor. Please choose another time.";
            } elseif ($patient_booked) {
                 $error = "You already have an appointment scheduled at this exact time with another doctor. Please choose a different time slot.";
            } else {
                $data = [
                    'patient_id' => $patient['id'],
                    'doctor_id' => $doctor_id,
                    'appointment_time' => $appointment_time,
                    'reason' => $reason,
                    'status' => 'scheduled'
                ];
                
                try {
                    db_insert('appointments', $data);
                    
                    // Notify Doctor
                    $pat_name = $patient['first_name'] . ' ' . $patient['last_name'];
                    // Get Doctor User ID to notify
                    $doc_user = db_select_one("SELECT user_id, last_name FROM staff WHERE id = $1", [$doctor_id]);
                    $doc_name = $doc_user['last_name'] ?? 'Doctor';
                    if ($doc_user) {
                        $msg = "New appointment booked by $pat_name for " . date('M d, h:i A', strtotime($appointment_time));
                        db_insert('notifications', [
                            'user_id' => $doc_user['user_id'], 
                            'title' => 'New Appointment',
                            'message' => $msg, 
                            'type' => 'appointment'
                        ]);
                        
                        // --- FCM INTEGRATION ---
                        require_once '../../includes/fcm_service.php';
                        
                        // 1. Notify Doctor
                        $doc_token_row = db_select_one("SELECT fcm_token FROM users WHERE id = $1", [$doc_user['user_id']]);
                        if($doc_token_row && !empty($doc_token_row['fcm_token'])) {
                            FCMService::send(
                                $doc_token_row['fcm_token'], 
                                'New Patient Appointment', 
                                "{$pat_name} has booked for " . date('M d, h:i A', strtotime($appointment_time))
                            );
                        }
                        
                        // 2. Notify Patient (Confirmation)
                        $pat_token_row = db_select_one("SELECT fcm_token FROM users WHERE id = $1", [$user_id]);
                        if($pat_token_row && !empty($pat_token_row['fcm_token'])) {
                            FCMService::send(
                                $pat_token_row['fcm_token'], 
                                'Appointment Confirmed', 
                                "Your appointment with Dr. {$doc_name} is confirmed for " . date('M d, h:i A', strtotime($appointment_time))
                            );
                        }
                        // -----------------------
                    }

                    $success = "Appointment booked successfully!";
                } catch (Exception $e) {
                    $error = "Booking failed: " . $e->getMessage();
                }
            }
        }
    } else {
        $error = "Patient profile not found.";
    }
  } // End of required fields check
}
?>

<div class="card" style="max-width: 800px; margin: 0 auto; padding: 30px;">
    <h2 style="margin-bottom: 25px; font-weight: 600;">Book an Appointment</h2>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="border-left: 5px solid #28a745;">
            <h4 class="alert-heading"><i class="fas fa-check-circle"></i> Booking Confirmed!</h4>
            <p>Your appointment has been successfully scheduled. Here are the details:</p>
            <hr>
            <div style="background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #eee; margin-bottom: 15px;">
                <p><strong><i class="fas fa-user-md"></i> Doctor:</strong> Dr. <?php 
                    $doc_name = '';
                    foreach($doctors as $d) { if($d['id'] == $doctor_id) { $doc_name = $d['first_name'].' '.$d['last_name']; break; } }
                    echo htmlspecialchars($doc_name); 
                ?></p>
                <p><strong><i class="far fa-calendar-alt"></i> Date:</strong> <?php echo date('l, F j, Y', strtotime($date)); ?></p>
                <p><strong><i class="far fa-clock"></i> Time:</strong> <?php echo date('g:i A', strtotime($time)); ?></p>
                <p><strong><i class="fas fa-map-marker-alt"></i> Location:</strong> ADMS Hospital, Main Branch</p>
            </div>
            <p class="mb-0">
                <a href="appointments.php" class="btn btn-primary btn-sm">View All Appointments</a>
                <a href="book_appointment.php" class="btn btn-outline-secondary btn-sm">Book Another</a>
            </p>
        </div>
    <?php endif; ?>

    <form id="bookingForm" method="POST" action="">
        <!-- Specialization -->
        <div class="form-group" style="position:relative;">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Specialization</label>
            <input type="text" id="spec-search" placeholder="Type to filter by specialization..." autocomplete="off"
                   oninput="showSpecSuggestions(this.value)" onfocus="showSpecSuggestions(this.value)"
                   style="width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:0.95em; box-sizing:border-box;">
            <div id="spec-suggestions" class="ac-dropdown" style="display:none;"></div>
            <!-- hidden select keeps filterDoctors() + AI integration working -->
            <select id="specialization" style="display:none;">
                <option value="">All Specializations</option>
                <?php foreach ($specializations as $spec): ?>
                    <option value="<?php echo htmlspecialchars($spec); ?>"><?php echo htmlspecialchars($spec); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Doctor -->
        <div class="form-group" style="position:relative;">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Doctor</label>
            <input type="text" id="doc-search" placeholder="Type to search doctor..." autocomplete="off"
                   oninput="showDocSuggestions(this.value)" onfocus="showDocSuggestions(this.value)"
                   style="width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:0.95em; box-sizing:border-box;">
            <div id="doc-suggestions" class="ac-dropdown" style="display:none;"></div>
            <!-- hidden select carries doctor_id for form submission -->
            <select name="doctor_id" id="doctor_id" style="display:none;">
                <option value="">-- Choose Doctor --</option>
                <?php foreach ($doctors as $doc): ?>
                    <option value="<?php echo $doc['id']; ?>" data-spec="<?php echo htmlspecialchars($doc['specialization']); ?>">
                        Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?> (<?php echo htmlspecialchars($doc['specialization']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Date & Time Grid -->
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Date</label>
                
                <!-- Month/Year Selectors -->
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <select id="select-month" class="form-control" style="flex: 1; height: 40px;" onchange="updateDateGrid()">
                        <?php 
                        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                        $currentMonth = date('n');
                        foreach ($months as $index => $month) {
                            $val = $index + 1;
                            $selected = ($val == $currentMonth) ? 'selected' : '';
                            echo "<option value='$val' $selected>$month</option>";
                        }
                        ?>
                    </select>
                    <select id="select-year" class="form-control" style="flex: 1; height: 40px;" onchange="updateDateGrid()">
                        <?php 
                        $currentYear = date('Y');
                        for ($i = 0; $i < 2; $i++) { // Show current and next year
                            $year = $currentYear + $i;
                            echo "<option value='$year'>$year</option>";
                        }
                        ?>
                    </select>
                </div>

                <!-- Visual Date Grid -->
                <div id="date-container" class="date-grid" style="max-height: 385px; overflow-y: auto; padding: 5px;">
                    <!-- Dates injected by JS -->
                </div>
                <input type="hidden" name="appointment_date" id="appointment_date" required>
            </div>

            <div class="form-group">
                <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Time Slot</label>
                
                <div id="slot-container" class="time-slot-container" style="min-height: 50px; padding: 10px; border: 1px dashed #ccc; border-radius: 8px; background: #f9f9f9;">
                    <p style="color: #777; font-size: 0.9em; margin: 0;">Please select a doctor and date first.</p>
                </div>
                <input type="hidden" name="appointment_time" id="selected_time" required>
                
                <!-- Legend -->
                <div style="display: flex; gap: 15px; margin-top: 10px; font-size: 0.85em; color: #555;">
                    <div style="display: flex; align-items: center;"><span style="width: 12px; height: 12px; background: #e8f5e9; display: inline-block; margin-right: 5px; border-radius: 2px; border: 1px solid #c8e6c9;"></span> Available</div>
                    <div style="display: flex; align-items: center;"><span style="width: 12px; height: 12px; background: #ffebee; display: inline-block; margin-right: 5px; border-radius: 2px; border: 1px solid #ffcdd2;"></span> Booked</div>
                    <div style="display: flex; align-items: center;"><span style="width: 12px; height: 12px; background: #007bff; display: inline-block; margin-right: 5px; border-radius: 2px;"></span> Selected</div>
                </div>
            </div>
        </div>

        <!-- Reason -->
        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label style="font-weight: 500; color: #555; margin-bottom: 0;">Reason for Visit</label>
                <button type="button" onclick="getAIRecommendation()" class="btn-ai-recommend">
                    <i class="fas fa-robot"></i> Ask AI for Recommendation
                </button>
            </div>
            
            <!-- AI Recommendation Result -->
            <div id="ai-recommendation-result" style="display: none; background: #e3f2fd; padding: 10px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #90caf9; color: #0d47a1;">
                <strong><i class="fas fa-stethoscope"></i> AI Suggestion:</strong> <span id="ai-msg">Loading...</span>
            </div>
            <textarea name="reason" id="reason" class="form-control" rows="4" required style="border-radius: 8px; border: 1px solid #ddd; padding: 10px;" oninput="handleReasonInput()"></textarea>
            
            <!-- Quick Keywords (dynamic per specialization) -->
            <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                <span style="font-size: 0.85em; color: #777; align-self: center; margin-right: 5px;">Quick Add:</span>
                <span id="quick-add-chips" style="display:contents;"></span>
            </div>
        </div>

        <script>
        function handleReasonInput() {
            const textarea = document.getElementById('reason');
            const text = textarea.value.toLowerCase();
            
            // Keyword to Specialization Map for Dynamic Quick Add
            const keywordMap = {
                'chest pain': 'Cardiology',
                'heart': 'Cardiology',
                'fever': 'General Medicine',
                'cough': 'General Medicine',
                'skin': 'Dermatology',
                'rash': 'Dermatology',
                'child': 'Pediatrics',
                'baby': 'Pediatrics',
                'headache': 'Neurology',
                'brain': 'Neurology',
                'seizure': 'Neurology',
                'joint': 'Orthopedics',
                'bone': 'Orthopedics',
                'fracture': 'Orthopedics',
                'anxiety': 'Psychiatry',
                'depression': 'Psychiatry',
                'stomach': 'Gastroenterology',
                'digestion': 'Gastroenterology',
                'eye': 'Ophthalmology',
                'vision': 'Ophthalmology',
                'urine': 'Urology',
                'kidney': 'Nephrology',
                'pregnancy': 'Obstetrics and Gynecology',
                'period': 'Obstetrics and Gynecology',
                'breathing': 'Pulmonology',
                'lung': 'Pulmonology',
                'throat': 'Otolaryngology (ENT)',
                'ear': 'Otolaryngology (ENT)',
                'nose': 'Otolaryngology (ENT)',
                'diabetes': 'Endocrinology',
                'sugar': 'Endocrinology'
            };

            let matchedSpec = null;
            const sortedKeys = Object.keys(keywordMap).sort((a, b) => b.length - a.length);
            
            for (const keyword of sortedKeys) {
                if (text.includes(keyword)) {
                    matchedSpec = keywordMap[keyword];
                    break;
                }
            }

            // Update Quick Add chips
            if (typeof updateQuickAdd === 'function') {
                updateQuickAdd(matchedSpec);
            }

            // Proactively auto-select specialization based on reason
            const specSelect = document.getElementById('specialization');
            if (matchedSpec && specSelect) {
                for (let i = 0; i < specSelect.options.length; i++) {
                    if (specSelect.options[i].value === matchedSpec) {
                        specSelect.selectedIndex = i;
                        document.getElementById('spec-search').value = matchedSpec;
                        updateQuickAdd(matchedSpec);
                        // Do NOT call filterDoctors here as it resets the doctor_id
                        // We only filter if the user manually changes specialization
                        break;
                    }
                }
            }
        }

        function addKeyword(keyword) {
            const textarea = document.getElementById('reason');
            if (textarea.value.trim() !== "") {
                textarea.value += ", " + keyword;
            } else {
                textarea.value = keyword;
            }
            // Trigger dynamic updates
            handleReasonInput();
        }
        
        function getAIRecommendation() {
            const reason = document.getElementById('reason').value;
            const selectedDate = document.getElementById('appointment_date').value || new Date().toISOString().split('T')[0];
            const resultDiv = document.getElementById('ai-recommendation-result');
            const msgSpan = document.getElementById('ai-msg');
            const btn = document.querySelector('.btn-ai-recommend');
            
            if (!reason.trim()) {
                alert("Please enter a reason for your visit first.");
                return;
            }
            
            // UI Loading State
            resultDiv.style.display = 'block';
            msgSpan.textContent = "Analyzing symptoms and checking doctor availability...";
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
            
            fetch('get_ai_recommendation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ reason: reason, date: selectedDate })
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Ask AI for Recommendation';
                
                if (data.error) {
                    msgSpan.textContent = "Error: " + data.error;
                    return;
                }
                
                const disease = data.disease || "Unknown";
                const specialization = data.specialization || "General Medicine";
                const recDoctor = data.recommended_doctor;
                const recReason = data.recommendation_reason;
                
                let html = `Likely condition: <strong>${disease}</strong>. Recommended Specialist: <strong>${specialization}</strong>`;
                
                if (recDoctor) {
                    html += `<div style="margin-top: 8px; padding: 8px; background: #fff; border-radius: 6px; border-left: 4px solid #4caf50;">
                                <i class="fas fa-user-md" style="color:#4caf50;"></i> <strong>Doctor Suggestion:</strong> Dr. ${recDoctor.last_name}<br>
                                <small style="color:#666;">${recReason}</small>
                             </div>`;
                }
                
                msgSpan.innerHTML = html;
                
                // 1. Auto-select specialization
                const specSelect = document.getElementById('specialization');
                let foundSpec = false;
                for (let i = 0; i < specSelect.options.length; i++) {
                    if (specSelect.options[i].value === specialization) {
                        specSelect.selectedIndex = i;
                        document.getElementById('spec-search').value = specialization;
                        foundSpec = true;
                        break;
                    }
                }
                
                if (foundSpec) {
                    // 2. Filter doctors to populate the select
                    const currentSpec = document.getElementById('specialization').value;
                    
                    // 3. Auto-select the recommended doctor if provided
                    if (recDoctor) {
                        const docSelect = document.getElementById('doctor_id');
                        const docSearch = document.getElementById('doc-search');
                        
                        // Wait a bit for any UI filtering to catch up
                        setTimeout(() => {
                            let foundDoc = false;
                            for (let i = 0; i < docSelect.options.length; i++) {
                                if (docSelect.options[i].value == recDoctor.id) {
                                    docSelect.selectedIndex = i;
                                    docSearch.value = `Dr. ${recDoctor.first_name} ${recDoctor.last_name}`;
                                    foundDoc = true;
                                    break;
                                }
                            }
                            if (foundDoc) {
                                fetchSlots(); // Refresh time slots for the suggested doctor
                            }
                        }, 100);
                    } else {
                        filterDoctors();
                        updateQuickAdd();
                    }
                } else {
                    msgSpan.innerHTML += " (Specialist not found in our records)";
                }
            })
            .catch(error => {
                console.error('Error:', error);
                msgSpan.textContent = "Error connecting to AI service.";
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-robot"></i> Ask AI for Recommendation';
            });
        }
        </script>

        <!-- Submit Button -->
        <button type="button" onclick="initiatePayment()" class="btn" style="background-color: #333; color: white; padding: 12px 25px; border-radius: 6px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-credit-card"></i> Proceed to Pay & Book
        </button>
    </form>
</div>

<!-- CONFLICT WARNING MODAL -->
<div id="conflictModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1100; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; padding:36px 32px; max-width:420px; width:90%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.25); animation:slideUp 0.25s ease;">
        <div style="width:64px; height:64px; background:#fff3cd; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 18px;">
            <i class="fas fa-exclamation-triangle" style="font-size:1.8em; color:#d97706;"></i>
        </div>
        <h4 style="margin:0 0 10px; font-size:1.15em; color:#1f2937;">Appointment Conflict</h4>
        <p style="color:#6b7280; font-size:0.95em; margin:0 0 24px; line-height:1.6;">
            You already have an appointment scheduled at this exact time with another doctor.<br>
            <strong style="color:#d97706;">Please choose a different time slot.</strong>
        </p>
        <button onclick="document.getElementById('conflictModal').style.display='none'"
                style="background:#d97706; color:#fff; border:none; padding:11px 32px; border-radius:8px; font-size:0.95em; font-weight:600; cursor:pointer;">
            <i class="fas fa-arrow-left"></i> Go Back &amp; Change Time
        </button>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div id="paymentModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; width:100%; max-width:400px; border-radius:16px; overflow:hidden; box-shadow:0 25px 50px rgba(0,0,0,0.2);">
        <div style="background:linear-gradient(135deg, #10b981, #059669); padding:20px; color:white; text-align:center;">
            <i class="fas fa-shield-alt fa-2x" style="margin-bottom:10px;"></i>
            <h3 style="margin:0; font-weight:700;">Secure Checkout</h3>
        </div>
        <div style="padding:25px;">
            <div style="text-align:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px dashed #e5e7eb;">
                <p style="margin:0; color:#6b7280; font-size:0.9em;">Consultation Fee</p>
                <h2 style="margin:5px 0 0; color:#111827; font-weight:800;">₹500.00</h2>
            </div>
            
            <div style="margin-bottom:15px;">
                <label style="display:block; font-size:0.85em; font-weight:600; color:#4b5563; margin-bottom:5px;">Card Number</label>
                <div style="position:relative;">
                    <i class="far fa-credit-card" style="position:absolute; left:12px; top:14px; color:#9ca3af;"></i>
                    <input type="text" class="form-control" placeholder="**** **** **** ****" value="4111 1111 1111 1111" style="padding-left:38px; border-radius:8px;">
                </div>
            </div>
            
            <div style="display:flex; gap:15px; margin-bottom:25px;">
                <div style="flex:1;">
                    <label style="display:block; font-size:0.85em; font-weight:600; color:#4b5563; margin-bottom:5px;">Expiry Date</label>
                    <input type="text" class="form-control" placeholder="MM/YY" value="12/25" style="border-radius:8px;">
                </div>
                <div style="flex:1;">
                    <label style="display:block; font-size:0.85em; font-weight:600; color:#4b5563; margin-bottom:5px;">CVV</label>
                    <input type="text" class="form-control" placeholder="123" value="123" style="border-radius:8px;">
                </div>
            </div>
            
            <button type="button" id="payBtn" onclick="processPayment()" style="width:100%; background:#10b981; color:white; border:none; padding:12px; border-radius:8px; font-weight:700; font-size:1.05em; cursor:pointer; transition:0.2s; display:flex; justify-content:center; align-items:center; gap:8px;">
                <i class="fas fa-lock"></i> Pay Securely
            </button>
            <button type="button" onclick="document.getElementById('paymentModal').style.display='none'" style="width:100%; background:transparent; color:#6b7280; border:none; padding:12px; border-radius:8px; font-weight:600; font-size:0.95em; cursor:pointer; margin-top:8px;">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
function initiatePayment() {
    const form = document.getElementById('bookingForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const doctor = document.getElementById('doctor_id').value;
    const date   = document.getElementById('appointment_date').value;
    const time   = document.getElementById('selected_time').value;

    if (!doctor || !date || !time) {
        alert("Please ensure Doctor, Date, and Time slots are selected.");
        return;
    }

    // Check for patient time conflict before opening payment modal
    const url = `../../modules/ehr/check_slot_conflict.php?doctor_id=${encodeURIComponent(doctor)}&date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.conflict) {
                document.getElementById('conflictModal').style.display = 'flex';
            } else {
                document.getElementById('paymentModal').style.display = 'flex';
            }
        })
        .catch(() => {
            // On network error fall through to payment (server will catch it anyway)
            document.getElementById('paymentModal').style.display = 'flex';
        });
}

function processPayment() {
    const btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Payment...';
    btn.style.background = '#059669';
    
    setTimeout(() => {
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Payment Successful!';
        setTimeout(() => {
            document.getElementById('bookingForm').submit();
        }, 800);
    }, 1500);
}
</script>

<style>
    .ac-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 220px;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .ac-item {
        padding: 10px 14px;
        cursor: pointer;
        font-size: 0.95em;
        color: #333;
        border-bottom: 1px solid #f5f5f5;
        transition: background 0.15s;
    }
    .ac-item:last-child { border-bottom: none; }
    .ac-item:hover, .ac-item.ac-active { background: #f0f4ff; color: #1d4ed8; }
    .ac-item mark { background: #fef08a; color: inherit; border-radius: 2px; padding: 0 1px; font-style: normal; }
    .ac-empty { padding: 10px 14px; color: #999; font-size: 0.9em; }
    .time-slot-container {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 10px;
    }
    .time-slot {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px 10px;
        text-align: center;
        cursor: pointer;
        background: #fff;
        transition: all 0.2s;
        font-weight: 500;
        color: #555;
    }
    .time-slot:hover {
        background-color: #f8f9fa;
        border-color: #c1c1c1;
    }
    .time-slot.available {
        background-color: #e8f5e9;
        color: #1b5e20;
        border-color: #c8e6c9;
    }
    .time-slot.available:hover {
        background-color: #c8e6c9;
    }
    .time-slot.booked {
        background-color: #ffebee;
        color: #b71c1c;
        border-color: #ffcdd2;
        cursor: not-allowed;
        text-decoration: line-through;
    }
    .time-slot.selected {
        background-color: #007bff;
        color: white;
        border-color: #0056b3;
        box-shadow: 0 2px 4px rgba(0,123,255,0.2);
    }
    
    /* Date Grid Styles */
    .date-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 10px;
    }
    .date-card {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 10px;
        text-align: center;
        cursor: pointer;
        background: #fff;
        transition: all 0.2s;
    }
    .date-card:hover {
        background-color: #f8f9fa;
        border-color: #c1c1c1;
    }
    .date-card.selected {
        background-color: #007bff;
        color: white;
        border-color: #0056b3;
    }
    .date-card .day {
        font-size: 0.8em;
        text-transform: uppercase;
        color: #777;
    }
    .date-card.selected .day {
        color: #e0e0e0;
    }
    .date-card.disabled {
        background-color: #f5f5f5;
        color: #ccc;
        cursor: not-allowed;
        border-color: #eee;
    }
    .date-card.disabled .day,
    .date-card.disabled .date-num,
    .date-card.disabled .month {
        color: #ccc;
    }
    .date-card-off {
        background-color: #fff5f5;
        border-color: #ffcdd2;
        opacity: 0.6;
    }
    .date-card-off .day { color: #e57373; }
    .date-card-off .date-num { color: #c62828; }
    .date-card-off:hover { background-color: #fff5f5; border-color: #ffcdd2; }
    .date-card .date-num {
        font-size: 1.2em;
        font-weight: bold;
        margin: 5px 0;
    }
    .date-card .month {
        font-size: 0.8em;
    }
</style>

<script>
const doctors = <?php echo json_encode($doctors); ?>;

// ── Autocomplete helpers ────────────────────────────────────────────────────

function highlight(text, query) {
    if (!query) return text;
    const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return text.replace(re, '<mark>$1</mark>');
}

function closeAllDropdowns() {
    document.getElementById('spec-suggestions').style.display = 'none';
    document.getElementById('doc-suggestions').style.display = 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.form-group')) closeAllDropdowns();
});

// ── Specialization autocomplete ──────────────────────────────────────────────

const allSpecs = <?php echo json_encode(array_values($specializations)); ?>;

function showSpecSuggestions(query) {
    const box = document.getElementById('spec-suggestions');
    const q = query.trim().toLowerCase();
    const matches = q === '' ? allSpecs : allSpecs.filter(s => s.toLowerCase().includes(q));
    box.innerHTML = '';

    if (matches.length === 0) {
        box.innerHTML = '<div class="ac-empty">No specializations found</div>';
    } else {
        if (q !== '') {
            const allItem = document.createElement('div');
            allItem.className = 'ac-item';
            allItem.textContent = 'All Specializations';
            allItem.style.cssText = 'color:#888;font-style:italic;';
            allItem.addEventListener('mousedown', function(e) { e.preventDefault(); selectSpec('', ''); });
            box.appendChild(allItem);
        }
        matches.forEach(s => {
            const item = document.createElement('div');
            item.className = 'ac-item';
            item.innerHTML = highlight(s, query.trim());
            item.addEventListener('mousedown', function(e) { e.preventDefault(); selectSpec(s, s); });
            box.appendChild(item);
        });
    }
    box.style.display = 'block';
}

// ── Quick Add keywords per specialization ────────────────────────────────────
const quickAddMap = {
    '': ['Fever', 'Cough', 'Headache', 'Stomach Pain', 'General Checkup', 'Follow-up', 'Cold/Flu', 'Body Pain', 'Nausea', 'Dizziness', 'Fatigue', 'Skin Rash', 'Sore Throat', 'Chest Pain', 'Breathing Issue'],
    'General Medicine':            ['General Checkup', 'Follow-up', 'Fever', 'Cough', 'Fatigue', 'Body Pain', 'Cold/Flu', 'Headache', 'Nausea', 'Dizziness'],
    'Cardiology':                  ['Chest Pain', 'Palpitations', 'Shortness of Breath', 'High Blood Pressure', 'Irregular Heartbeat', 'Swollen Legs', 'Dizziness', 'Heart Follow-up'],
    'Dermatology':                 ['Skin Rash', 'Acne', 'Eczema', 'Psoriasis', 'Hair Loss', 'Itching', 'Skin Lesion', 'Allergic Reaction', 'Nail Disorder'],
    'Pediatrics':                  ['Child Fever', 'Vaccination', 'Growth Check', 'Child Cough', 'Ear Infection', 'Child Rash', 'Feeding Issues', 'Developmental Assessment'],
    'Neurology':                   ['Headache', 'Migraine', 'Seizure', 'Numbness', 'Memory Loss', 'Dizziness', 'Tremors', 'Stroke Follow-up', 'Sleep Disorder'],
    'Orthopedics':                 ['Joint Pain', 'Back Pain', 'Knee Pain', 'Fracture', 'Sports Injury', 'Arthritis', 'Shoulder Pain', 'Hip Pain', 'Bone Density Check'],
    'Psychiatry':                  ['Anxiety', 'Depression', 'Insomnia', 'Stress', 'Mood Swings', 'Panic Attack', 'Follow-up', 'Behavioral Issues', 'Trauma'],
    'Oncology':                    ['Cancer Screening', 'Chemotherapy Follow-up', 'Biopsy Review', 'Tumor Check', 'Radiation Follow-up', 'Blood Work Review', 'Pain Management'],
    'Radiology':                   ['X-Ray', 'MRI Scan', 'CT Scan', 'Ultrasound', 'Mammogram', 'PET Scan', 'Bone Scan', 'Report Review'],
    'Anesthesiology':              ['Pre-Surgery Assessment', 'Pain Management', 'Post-Op Follow-up', 'Chronic Pain', 'Nerve Block'],
    'Gastroenterology':            ['Stomach Pain', 'Acid Reflux', 'Bloating', 'Constipation', 'Diarrhea', 'Nausea', 'Liver Check', 'Endoscopy Follow-up', 'IBS'],
    'Ophthalmology':               ['Eye Pain', 'Blurred Vision', 'Eye Infection', 'Glasses Review', 'Cataract Check', 'Glaucoma Follow-up', 'Dry Eyes', 'Retinal Check'],
    'Urology':                     ['Frequent Urination', 'Burning Urination', 'Kidney Stone', 'Prostate Check', 'UTI', 'Blood in Urine', 'Bladder Issue'],
    'Obstetrics and Gynecology':   ['Pregnancy Check', 'Menstrual Issue', 'Pelvic Pain', 'Prenatal Visit', 'Contraception', 'Fertility Consultation', 'Ultrasound'],
    'Emergency Medicine':          ['Chest Pain', 'Severe Injury', 'Breathing Difficulty', 'High Fever', 'Unconsciousness', 'Allergic Reaction', 'Fracture'],
    'Endocrinology':               ['Diabetes Follow-up', 'Thyroid Issue', 'Weight Gain', 'Hormonal Imbalance', 'PCOS', 'Adrenal Disorder', 'Blood Sugar Control'],
    'Pulmonology':                 ['Breathing Issue', 'Cough', 'Asthma', 'COPD', 'Chest Tightness', 'Sleep Apnea', 'Lung Function Test', 'Wheezing'],
    'Nephrology':                  ['Kidney Pain', 'Swelling', 'High Creatinine', 'Dialysis Follow-up', 'Frequent Urination', 'Blood in Urine', 'Hypertension'],
    'Rheumatology':                ['Joint Pain', 'Arthritis', 'Lupus Follow-up', 'Muscle Pain', 'Swollen Joints', 'Gout', 'Stiffness', 'Autoimmune Review'],
    'Infectious Disease':          ['Fever', 'HIV Follow-up', 'Tuberculosis', 'Malaria', 'Antibiotic Review', 'Travel Illness', 'Chronic Infection', 'Vaccination'],
    'Hematology':                  ['Anemia', 'Blood Disorder', 'Bleeding Issue', 'Platelet Count', 'Sickle Cell', 'Clotting Problem', 'Blood Cancer Follow-up'],
    'Hepatology':                  ['Liver Pain', 'Jaundice', 'Hepatitis Follow-up', 'Fatty Liver', 'Liver Function Test', 'Cirrhosis', 'Liver Biopsy Review'],
    'Neonatology':                 ['Newborn Check', 'Jaundice', 'Feeding Difficulty', 'Premature Baby Follow-up', 'Newborn Infection', 'Weight Monitoring'],
    'Geriatrics':                  ['General Checkup', 'Memory Assessment', 'Fall Prevention', 'Medication Review', 'Mobility Issue', 'Chronic Disease Management'],
    'Palliative Care':             ['Pain Management', 'Comfort Care', 'Symptom Relief', 'End-of-Life Care', 'Family Consultation', 'Medication Review'],
    'Sports Medicine':             ['Sports Injury', 'Muscle Strain', 'Ligament Tear', 'Performance Assessment', 'Concussion', 'Rehabilitation', 'Joint Pain'],
    'Plastic Surgery':             ['Wound Care', 'Scar Treatment', 'Burn Injury', 'Reconstructive Surgery Consult', 'Post-Op Follow-up'],
    'Vascular Surgery':            ['Varicose Veins', 'Leg Swelling', 'Poor Circulation', 'Arterial Pain', 'Peripheral Artery Disease', 'Aneurysm Follow-up'],
    'Cardiothoracic Surgery':      ['Chest Surgery Follow-up', 'Heart Valve Issue', 'Bypass Recovery', 'Lung Surgery Review', 'Shortness of Breath'],
    'Colorectal Surgery':          ['Rectal Bleeding', 'Hemorrhoids', 'Colon Issue', 'Colonoscopy Review', 'Bowel Problem', 'Post-Op Follow-up'],
    'Transplant Surgery':          ['Organ Rejection Check', 'Post-Transplant Follow-up', 'Immunosuppressant Review', 'Kidney Transplant', 'Liver Transplant'],
    'Allergy and Immunology':      ['Allergic Reaction', 'Asthma', 'Food Allergy', 'Skin Allergy', 'Immunodeficiency', 'Allergy Test', 'Anaphylaxis Follow-up'],
    'Nuclear Medicine':            ['Thyroid Scan', 'Bone Scan', 'PET Scan Follow-up', 'Radiation Therapy Review', 'Isotope Treatment'],
    'Pathology':                   ['Biopsy Review', 'Lab Report Review', 'Tissue Sample', 'Blood Work Analysis', 'Cancer Marker Review'],
    'Clinical Pharmacology':       ['Medication Review', 'Drug Interaction Check', 'Dosage Adjustment', 'Side Effect Assessment', 'New Prescription'],
    'Otolaryngology (ENT)':        ['Ear Pain', 'Hearing Loss', 'Sore Throat', 'Nasal Congestion', 'Sinusitis', 'Tonsil Issue', 'Voice Problem', 'Dizziness'],
    'Maxillofacial Surgery':       ['Jaw Pain', 'Facial Injury', 'Dental Surgery Consult', 'Mouth Lesion', 'Post-Op Follow-up', 'TMJ Disorder'],
    'Reproductive Medicine':       ['Fertility Consultation', 'IVF Follow-up', 'Hormonal Testing', 'Sperm Analysis', 'PCOS', 'Egg Freezing Consult'],
    'Family Medicine':             ['Routine Checkup', 'Childhood Illness', 'Vaccination', 'Chronic Condition Management', 'General Health Advice'],
    'Sleep Medicine':              ['Insomnia', 'Sleep Apnea', 'Snoring', 'Restless Legs', 'Narcolepsy', 'CPAP Review'],
    'Occupational Medicine':       ['Workplace Injury', 'Pre-Employment Check', 'Disability Assessment', 'Ergonomic Consult', 'Toxic Exposure'],
    'Forensic Medicine':           ['Legal Medical Exam', 'Injury Certification', 'Expert Opinion', 'Post-Mortem Review'],
    'Addiction Medicine':          ['Substance Abuse', 'Alcohol Detox', 'Nicotine Cessation', 'Recovery Support', 'Relapse Prevention'],
    'Physical Medicine and Rehabilitation': ['Stroke Rehab', 'Spinal Cord Injury', 'Amputee Care', 'Brain Injury Rehab', 'Mobility Improvement'],
};

function updateQuickAdd(overrideSpec = null) {
    const spec = overrideSpec !== null ? overrideSpec : document.getElementById('specialization').value;
    const keywords = quickAddMap[spec] || quickAddMap[''];
    const container = document.getElementById('quick-add-chips');
    container.innerHTML = keywords.map(k =>
        `<button type="button" onclick="addKeyword('${k.replace(/'/g, "\\'")}')"
            style="background:#e2e8f0;border:none;padding:5px 10px;border-radius:15px;font-size:0.85em;color:#4a5568;cursor:pointer;transition:background 0.2s;">
            ${k}
        </button>`
    ).join('');
}


function selectSpec(value, label) {
    document.getElementById('spec-search').value = label;
    document.getElementById('specialization').value = value;
    document.getElementById('spec-suggestions').style.display = 'none';
    filterDoctors();
    updateQuickAdd();
    document.getElementById('doc-search').value = '';
    document.getElementById('doctor_id').value = '';
}

// ── Doctor autocomplete ──────────────────────────────────────────────────────

function showDocSuggestions(query) {
    const box = document.getElementById('doc-suggestions');
    const q = query.trim().toLowerCase();
    const currentSpec = document.getElementById('specialization').value;

    const filtered = doctors.filter(d => {
        const name = ('Dr. ' + d.first_name + ' ' + d.last_name).toLowerCase();
        const matchSpec = currentSpec === '' || d.specialization === currentSpec;
        const matchQuery = q === '' || name.includes(q) || d.specialization.toLowerCase().includes(q);
        return matchSpec && matchQuery;
    });

    box.innerHTML = '';
    if (filtered.length === 0) {
        box.innerHTML = '<div class="ac-empty">No doctors found</div>';
    } else {
        filtered.forEach(d => {
            const fullName = 'Dr. ' + d.first_name + ' ' + d.last_name;
            const item = document.createElement('div');
            item.className = 'ac-item';
            item.innerHTML = highlight(fullName, query.trim()) +
                ' <span style="color:#888;font-size:0.88em;">(' + highlight(d.specialization, query.trim()) + ')</span>';
            item.addEventListener('mousedown', function(e) { e.preventDefault(); selectDoc(d.id, fullName); });
            box.appendChild(item);
        });
    }
    box.style.display = 'block';
}

function selectDoc(id, label) {
    document.getElementById('doc-search').value = label;
    document.getElementById('doctor_id').value = id;
    document.getElementById('doc-suggestions').style.display = 'none';

    // Sync Specialization with selected Doctor
    const doc = doctors.find(d => d.id == id);
    if (doc) {
        const specSelect = document.getElementById('specialization');
        const specSearch = document.getElementById('spec-search');
        if (specSelect && specSearch) {
            specSelect.value = doc.specialization;
            specSearch.value = doc.specialization;
            updateQuickAdd(doc.specialization);
        }
    }

    fetchSlots();
}

// ── filterDoctors: called by AI recommendation + URL pre-selection ───────────

function filterDoctors() {
    const spec = document.getElementById('specialization').value;
    // reset doctor search/selection when spec changes
    document.getElementById('doc-search').value = '';
    document.getElementById('doctor_id').value = '';
    document.getElementById('slot-container').innerHTML = '<p style="color: #777; font-size: 0.9em; margin: 0;">Please select a doctor and date first.</p>';
    document.getElementById('selected_time').value = '';
    // Also update spec search text to reflect current spec
    document.getElementById('spec-search').value = spec;
}

function fetchSlots() {
    const doctorId = document.getElementById('doctor_id').value;
    const date = document.getElementById('appointment_date').value;
    const container = document.getElementById('slot-container');

    if (!doctorId || !date) {
        container.innerHTML = '<p style="color: #777; font-size: 0.9em; margin: 0;">Please select a doctor and date first.</p>';
        return;
    }

    container.innerHTML = 'Loading...';

    fetch(`get_booked_slots.php?doctor_id=${doctorId}&date=${date}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            if (data.doctor_unavailable) {
                const label = data.unavailability_type
                    ? data.unavailability_type.charAt(0).toUpperCase() + data.unavailability_type.slice(1)
                    : 'Leave';
                container.innerHTML = `<p style="color:#b71c1c;font-size:0.9em;margin:0;">
                    <i class="fas fa-calendar-times"></i> Doctor is on <strong>${label}</strong> on this date. Please choose another date.</p>`;
                document.getElementById('selected_time').value = '';
                return;
            }
            renderSlots(data.booked_slots || []);
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = `<div class="alert alert-danger">Error loading slots: ${err.message}</div>`;
        });
}

function renderSlots(bookedSlots) {
    const container = document.getElementById('slot-container');
    container.innerHTML = '';
    
    const startHour = 9;
    const endHour = 16; // Doctors available 09:00 – 16:00

    // Get selected date and current time for comparison
    const selectedDateVal = document.getElementById('appointment_date').value;
    const now = new Date();

    // Check if selected date is today
    const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    const isToday = (selectedDateVal === todayStr);

    // Block Sundays entirely
    if (selectedDateVal) {
        const [yr, mo, dy] = selectedDateVal.split('-').map(Number);
        const selectedDay = new Date(yr, mo - 1, dy).getDay(); // 0 = Sunday
        if (selectedDay === 0) {
            container.innerHTML = '<p style="color:#b71c1c; font-size:0.9em; margin:0;"><i class="fas fa-calendar-times"></i> Doctors are off on Sundays. Please choose another day.</p>';
            return;
        }
    }

    const currentHour = now.getHours();
    const currentMin = now.getMinutes();

    for (let h = startHour; h < endHour; h++) {
        for (let m = 0; m < 60; m += 15) {
            const timeString = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
            // DB returns HH:MM, so we compare directly
            const isBooked = bookedSlots && bookedSlots.includes(timeString); 
            
            // Check past time
            let isPast = false;
            if (isToday) {
                if (h < currentHour || (h === currentHour && m < currentMin)) {
                    isPast = true;
                }
            }
            
            const slot = document.createElement('div');
            let className = 'time-slot';
            
            if (isBooked) {
                className += ' booked';
            } else if (isPast) {
                className += ' booked'; // Reuse booked style (red/disabled)
                slot.title = "Time passed";
            } else {
                className += ' available';
            }
            
            slot.className = className;
            slot.textContent = timeString;
            
            if (!isBooked && !isPast) {
                slot.onclick = () => selectSlot(slot, timeString);
            }
            
            container.appendChild(slot);
        }
    }
}

function selectSlot(element, time) {
    // Deselect others
    document.querySelectorAll('.time-slot.selected').forEach(el => el.classList.remove('selected'));
    
    // Select this one
    element.classList.add('selected');
    document.getElementById('selected_time').value = time;
}

// Initialize with disabled slots
document.addEventListener('DOMContentLoaded', function() {
    updateDateGrid(); // Initial render
    renderSlots(null);
    updateQuickAdd(); // Render default quick-add chips

    // Pre-select specialization + doctor if passed from symptom checker
    const params = new URLSearchParams(window.location.search);
    const preDocId = params.get('doctor_id');
    const preSpec = params.get('spec');

    if (preDocId) {
        const docSelect = document.getElementById('doctor_id');
        const specSelect = document.getElementById('specialization');

        // Step 1: Find the doctor option and get its exact data-spec
        let targetIndex = -1;
        let targetSpec = preSpec || '';
        for (let i = 0; i < docSelect.options.length; i++) {
            if (docSelect.options[i].value === preDocId) {
                targetIndex = i;
                targetSpec = docSelect.options[i].getAttribute('data-spec') || targetSpec;
                break;
            }
        }

        // Step 2: Set specialization dropdown to exact matched value
        for (let i = 0; i < specSelect.options.length; i++) {
            if (specSelect.options[i].value === targetSpec) {
                specSelect.selectedIndex = i;
                break;
            }
        }

        // Step 3: Filter doctors (this resets doctor select but shows correct options)
        filterDoctors();

        // Step 4: Re-select the doctor (now the option is visible and enabled)
        if (targetIndex !== -1) {
            docSelect.value = preDocId;
            const opt = docSelect.options[targetIndex];
            const docLabel = opt.text.split('(')[0].trim();
            document.getElementById('doc-search').value = docLabel;
        }
        document.getElementById('spec-search').value = targetSpec;
    } else if (preSpec) {
        // Only spec provided — just filter by specialization
        const specSelect = document.getElementById('specialization');
        for (let i = 0; i < specSelect.options.length; i++) {
            if (specSelect.options[i].value === preSpec) {
                specSelect.selectedIndex = i;
                break;
            }
        }
        document.getElementById('spec-search').value = preSpec;
        filterDoctors();
    }
});

function updateDateGrid() {
    const month = parseInt(document.getElementById('select-month').value);
    const year = parseInt(document.getElementById('select-year').value);
    renderDates(month, year);
}

function renderDates(month, year) {
    const container = document.getElementById('date-container');
    container.innerHTML = '';
    
    // Create date for 1st of selected month
    const date = new Date(year, month - 1, 1);
    const today = new Date();
    today.setHours(0,0,0,0); // Normalize today

    // Loop through all days in month
    while (date.getMonth() === month - 1) {
        // Fix: Use local date components instead of ISO (UTC) to avoid off-by-one error
        const fullDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const isPast = date < today;
        
        // Skip past dates entirely so Today is at the top
        if (isPast) {
            date.setDate(date.getDate() + 1);
            continue;
        }

        const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
        const dayNum = date.getDate();
        const monthName = date.toLocaleDateString('en-US', { month: 'short' });
        const isSunday = date.getDay() === 0;

        const card = document.createElement('div');
        card.className = 'date-card' + (isSunday ? ' date-card-off' : '');

        card.innerHTML = `
            <div class="day">${dayName}</div>
            <div class="date-num">${dayNum}</div>
            <div class="month">${isSunday ? '<span style="font-size:0.75em;color:#b71c1c;">Off</span>' : monthName}</div>
        `;

        if (!isSunday) {
            card.onclick = () => selectDate(card, fullDate);
        } else {
            card.title = 'Doctors off on Sundays';
            card.style.cursor = 'not-allowed';
        }
        
        container.appendChild(card);
        
        date.setDate(date.getDate() + 1);
    }
    
    // If no dates shown (e.g. past month), show message
    if (container.children.length === 0) {
        container.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #777;">No available dates in this month.</p>';
    }
}

function selectDate(element, dateStr) {
    // Deselect all dates
    document.querySelectorAll('.date-card').forEach(el => el.classList.remove('selected'));
    // Select clicked
    element.classList.add('selected');
    // Update hidden input
    document.getElementById('appointment_date').value = dateStr;
    // Fetch slots for new date
    fetchSlots();
}
</script>
</div>

<?php include '../../includes/footer.php'; ?>
```
