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

// Extract unique specializations
$specializations = array_unique(array_column($doctors, 'specialization'));
sort($specializations);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $doctor_id = $_POST['doctor_id'];
    $date = $_POST['appointment_date'];
    $time = $_POST['appointment_time'];
    $reason = $_POST['reason'];
    
    $appointment_time = $date . ' ' . $time;
    
    // Get patient ID
    $user_id = $_SESSION['user_id'];
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    
    if ($patient) {
        // Server-side validation: Prevent past dates
        if (strtotime($appointment_time) < time()) {
            $error = "Cannot book an appointment in the past. Please select a future date and time.";
        } else {
            // Check availability (double check)
            $existing = db_select_one("SELECT id FROM appointments WHERE doctor_id = $1 AND appointment_time = $2 AND status = 'scheduled'", [$doctor_id, $appointment_time]);
            
            if ($existing) {
                 $error = "This slot is already booked. Please choose another.";
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
                    $success = "Appointment booked successfully!";
                } catch (Exception $e) {
                    $error = "Booking failed: " . $e->getMessage();
                }
            }
        }
    } else {
        $error = "Patient profile not found.";
    }
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

    <form method="POST" action="">
        <!-- Specialization -->
        <div class="form-group">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Specialization</label>
            <select id="specialization" class="form-control" onchange="filterDoctors()" style="height: 45px; border-radius: 8px; border: 1px solid #ddd;">
                <option value="">All Specializations</option>
                <?php foreach ($specializations as $spec): ?>
                    <option value="<?php echo htmlspecialchars($spec); ?>"><?php echo htmlspecialchars($spec); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Doctor -->
        <div class="form-group">
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Select Doctor</label>
            <select name="doctor_id" id="doctor_id" class="form-control" required onchange="fetchSlots()" style="height: 45px; border-radius: 8px; border: 1px solid #ddd;">
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
                <div id="date-container" class="date-grid" style="max-height: 300px; overflow-y: auto; padding: 5px;">
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
            <label style="font-weight: 500; color: #555; margin-bottom: 8px; display: block;">Reason for Visit</label>
            <textarea name="reason" class="form-control" rows="4" required style="border-radius: 8px; border: 1px solid #ddd; padding: 10px;"></textarea>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn" style="background-color: #333; color: white; padding: 12px 25px; border-radius: 6px; font-weight: 600; border: none; cursor: pointer;">
            Confirm Booking
        </button>
    </form>
</div>

<style>
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

function filterDoctors() {
    const spec = document.getElementById('specialization').value;
    const doctorSelect = document.getElementById('doctor_id');
    const options = doctorSelect.options;

    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        if (option.value === "") continue;
        
        const docSpec = option.getAttribute('data-spec');
        if (spec === "" || docSpec === spec) {
            option.style.display = "";
        } else {
            option.style.display = "none";
        }
    }
    doctorSelect.value = "";
    document.getElementById('slot-container').innerHTML = '<p style="color: #777; font-size: 0.9em; margin: 0;">Please select a doctor and date first.</p>';
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
    const endHour = 17;
    
    for (let h = startHour; h < endHour; h++) {
        for (let m = 0; m < 60; m += 30) {
            const timeString = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
            // DB returns HH:MM, so we compare directly
            const isBooked = bookedSlots.includes(timeString); 
            
            const slot = document.createElement('div');
            slot.className = `time-slot ${isBooked ? 'booked' : 'available'}`;
            slot.textContent = timeString;
            
            if (!isBooked) {
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
        
        const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
        const dayNum = date.getDate();
        const monthName = date.toLocaleDateString('en-US', { month: 'short' });
        
        const card = document.createElement('div');
        card.className = 'date-card';
        if (isPast) {
            card.classList.add('disabled');
        }
        
        // Auto-select if it matches current hidden input value (and not past)
        if (!isPast && document.getElementById('appointment_date').value === fullDate) {
            card.classList.add('selected');
        }
        
        card.innerHTML = `
            <div class="day">${dayName}</div>
            <div class="date-num">${dayNum}</div>
            <div class="month">${monthName}</div>
        `;
        
        if (!isPast) {
            card.onclick = () => selectDate(card, fullDate);
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
