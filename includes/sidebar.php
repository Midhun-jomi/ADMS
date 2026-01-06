<?php
// includes/sidebar.php
$role = get_user_role();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-hospital-alt fa-2x" style="color: var(--primary-color);"></i>
        <h3>ADMS Hospital</h3>
    </div>
    
    <ul class="sidebar-menu">
        <div class="menu-category">Main Menu</div>
        <?php if ($role === 'nurse' || $role === 'head_nurse'): ?>
        <li><a href="/modules/patient_management/nursing_station.php" class="<?php echo strpos($current_page, 'nursing_station') !== false ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a></li>
        <?php else: ?>
        <li><a href="/dashboards/<?php echo $role; ?>_dashboard.php" class="<?php echo strpos($current_page, 'dashboard') !== false ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'patient'): ?>
        <div class="menu-category">Quick Actions</div>
        <li><a href="/modules/ehr/appointments.php" class="<?php echo $current_page == 'appointments.php' ? 'active' : ''; ?>">
            <i class="far fa-calendar-check"></i> Book Appointment
        </a></li>
        <li><a href="/modules/lab/results.php" class="<?php echo $current_page == 'results.php' ? 'active' : ''; ?>">
            <i class="fas fa-vial"></i> Lab Results
        </a></li>
        <li><a href="/modules/ehr/request_certificate.php" class="<?php echo $current_page == 'request_certificate.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-medical"></i> Request Certificate
        </a></li>
        <?php endif; ?>
        
        <?php if ($role === 'doctor'): ?>
        <li><a href="/modules/ehr/appointments.php" class="<?php echo $current_page == 'appointments.php' ? 'active' : ''; ?>">
            <i class="far fa-calendar-check"></i> Appointments
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'doctor' || $role === 'admin'): ?>
        <li><a href="/modules/ehr/patients.php" class="<?php echo $current_page == 'patients.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-injured"></i> Patients
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Management</div>
        
        <?php if ($role === 'admin'): ?>
        <li><a href="/modules/admin/staff_management.php" class="<?php echo $current_page == 'staff_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-md"></i> Doctors & Staff
        </a></li>
        <li><a href="/modules/admin/departments.php">
            <i class="fas fa-building"></i> Departments
        </a></li>
        <?php endif; ?>

        <!-- Patient Management Module -->
        <?php if ($role === 'admin' || $role === 'nurse' || $role === 'head_nurse'): ?>
        <li><a href="/modules/patient_management/manage_beds.php" class="<?php echo $current_page == 'manage_beds.php' ? 'active' : ''; ?>">
            <i class="fas fa-procedures"></i> Bed Management
        </a></li>
        
        <?php if ($role === 'admin'): // Nurses see this as Dashboard ?>
        <li><a href="/modules/patient_management/nursing_station.php" class="<?php echo $current_page == 'nursing_station.php' ? 'active' : ''; ?>">
            <i class="fas fa-notes-medical"></i> Nursing Station
        </a></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'head_nurse'): ?>
        <li><a href="/modules/admin/nurse_allocation.php" class="<?php echo $current_page == 'nurse_allocation.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i> Nurse Allocation
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
        <div class="menu-category">Human Resources</div>
        <li><a href="/modules/hr/dashboard.php" class="<?php echo strpos($current_page, '/hr/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-users-cog"></i> HR & Payroll
        </a></li>
        
        <div class="menu-category">Emergency Services</div>
        <li><a href="/modules/emergency/dashboard.php" class="<?php echo strpos($current_page, '/emergency/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-ambulance"></i> Ambulance/Emergency
        </a></li>

        <div class="menu-category">Infrastructure</div>
        <li><a href="/modules/inventory/assets.php" class="<?php echo strpos($current_page, '/inventory/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-cubes"></i> Asset Management
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'doctor' || $role === 'nurse'): ?>
        <div class="menu-category">Medical Services</div>
        <li><a href="/modules/blood_bank/dashboard.php" class="<?php echo strpos($current_page, 'blood_bank') !== false ? 'active' : ''; ?>">
            <i class="fas fa-burn"></i> Blood Bank
        </a></li>
        <li><a href="/modules/ot/schedule.php" class="<?php echo strpos($current_page, '/ot/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-procedures"></i> Operation Theatre
        </a></li>
        <?php endif; ?>



        <?php if ($role === 'admin' || $role === 'doctor' || $role === 'patient'): ?>
        <div class="menu-category">Virtual Care</div>
        <li><a href="/modules/telemedicine/dashboard.php" class="<?php echo strpos($current_page, 'telemedicine') !== false ? 'active' : ''; ?>">
            <i class="fas fa-video"></i> Telemedicine
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'nurse'): ?>
        <div class="menu-category">Support Services</div>
        <li><a href="/modules/dietary/planner.php" class="<?php echo strpos($current_page, 'dietary') !== false ? 'active' : ''; ?>">
            <i class="fas fa-utensils"></i> Dietary/Meals
        </a></li>
        <li><a href="/modules/housekeeping/dashboard.php" class="<?php echo strpos($current_page, 'housekeeping') !== false ? 'active' : ''; ?>">
            <i class="fas fa-broom"></i> Housekeeping
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Finance & Others</div>
        
        <?php if ($role === 'admin' || $role === 'receptionist' || $role === 'patient'): ?>
        <li><a href="/modules/billing/invoices.php">
            <i class="fas fa-file-invoice-dollar"></i> Payments
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'pharmacist'): ?>
        <li><a href="/modules/pharmacy/inventory.php">
            <i class="fas fa-pills"></i> Inventory
        </a></li>
        <?php endif; ?>



        <div class="menu-category">Enterprise & AI</div>
        <li><a href="/modules/ai/diagnosis_assist.php">
            <i class="fas fa-robot"></i> AI Assist
        </a></li>
        <li><a href="/modules/queue/display.php" target="_blank">
            <i class="fas fa-tv"></i> Queue Board
        </a></li>
        <li><a href="/modules/feedback/survey.php">
            <i class="fas fa-poll"></i> Feedback
        </a></li>

        <div class="menu-category">Help</div>
        <li><a href="/help_center.php">
            <i class="far fa-question-circle"></i> Help Center
        </a></li>
    </ul>
</aside>
