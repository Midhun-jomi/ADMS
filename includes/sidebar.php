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
        <li><a href="<?php echo BASE_URL; ?>/modules/patient_management/nursing_station.php" class="<?php echo strpos($current_page, 'nursing_station') !== false ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a></li>
        <?php else: ?>
        <li><a href="<?php echo BASE_URL; ?>/dashboards/<?php echo $role; ?>_dashboard.php" class="<?php echo strpos($current_page, 'dashboard') !== false ? 'active' : ''; ?>">
            <i class="fas fa-th-large"></i> Dashboard
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'patient'): ?>
        <div class="menu-category">Quick Actions</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/appointments.php" class="<?php echo $current_page == 'appointments.php' ? 'active' : ''; ?>">
            <i class="far fa-calendar-check"></i> Book Appointment
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/lab/results.php" class="<?php echo $current_page == 'results.php' ? 'active' : ''; ?>">
            <i class="fas fa-vial"></i> Lab Results
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/request_certificate.php" class="<?php echo $current_page == 'request_certificate.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-medical"></i> Request Certificate
        </a></li>
        <?php endif; ?>
        
        <?php if ($role === 'doctor'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/appointments.php" class="<?php echo $current_page == 'appointments.php' ? 'active' : ''; ?>">
            <i class="far fa-calendar-check"></i> Appointments
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'doctor' || $role === 'admin'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/patients.php" class="<?php echo $current_page == 'patients.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-injured"></i> Patients
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'doctor'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/lab/orders.php" class="<?php echo $current_page == 'orders.php' ? 'active' : ''; ?>">
            <i class="fas fa-flask"></i> Lab Orders
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/radiology/orders.php" class="<?php echo strpos($current_page, 'radiology') !== false ? 'active' : ''; ?>">
            <i class="fas fa-x-ray"></i> Radiology Orders
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/discharge_summary.php" class="<?php echo $current_page == 'discharge_summary.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-medical-alt"></i> Discharge Summary
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Management</div>
        
        <?php if ($role === 'admin'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/staff_management.php" class="<?php echo $current_page == 'staff_management.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-md"></i> Doctors & Staff
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/departments.php">
            <i class="fas fa-building"></i> Departments
        </a></li>
        <?php endif; ?>

        <!-- Patient Management Module -->
        <?php if ($role === 'admin' || $role === 'nurse' || $role === 'head_nurse' || $role === 'doctor'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/patient_management/manage_beds.php" class="<?php echo $current_page == 'manage_beds.php' ? 'active' : ''; ?>">
            <i class="fas fa-procedures"></i> Bed Management
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/patient_management/in_patients.php" class="<?php echo $current_page == 'in_patients.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i> In Patients
        </a></li>
        
        <?php if ($role === 'admin'): // Nurses see this as Dashboard ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/patient_management/nursing_station.php" class="<?php echo $current_page == 'nursing_station.php' ? 'active' : ''; ?>">
            <i class="fas fa-notes-medical"></i> Nursing Station
        </a></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'head_nurse'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/admin/nurse_allocation.php" class="<?php echo $current_page == 'nurse_allocation.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i> Nurse Allocation
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
        <div class="menu-category">Human Resources</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/doctor_availability.php" class="<?php echo strpos($current_page, 'doctor_availability') !== false ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> Leave Management
        </a></li>
        


        <div class="menu-category">Infrastructure</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/inventory/assets.php" class="<?php echo strpos($current_page, '/inventory/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-cubes"></i> Asset Management
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'nurse', 'head_nurse'])): ?>
        <div class="menu-category">Medical Services</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/blood_bank/dashboard.php" class="<?php echo strpos($current_page, 'blood_bank') !== false ? 'active' : ''; ?>">
            <i class="fas fa-burn"></i> Blood Bank
        </a></li>
        <?php endif; ?>
        <?php if (in_array($role, ['admin', 'nurse', 'head_nurse'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ot/schedule.php" class="<?php echo strpos($current_page, '/ot/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-procedures"></i> Operation Theatre
        </a></li>
        <?php endif; ?>



        <?php if ($role === 'admin' || $role === 'doctor' || $role === 'patient'): ?>
        <div class="menu-category">Virtual Care</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/telemedicine/dashboard.php" class="<?php echo strpos($current_page, 'telemedicine') !== false ? 'active' : ''; ?>">
            <i class="fas fa-video"></i> Telemedicine
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'nurse', 'head_nurse'])): ?>
        <div class="menu-category">Support Services</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/dietary/planner.php" class="<?php echo strpos($current_page, 'dietary') !== false ? 'active' : ''; ?>">
            <i class="fas fa-utensils"></i> Dietary/Meals
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Finance & Others</div>
        
        <?php if ($role === 'admin' || $role === 'receptionist' || $role === 'patient'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/billing/invoices.php">
            <i class="fas fa-file-invoice-dollar"></i> Payments
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'pharmacist'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/pharmacy/inventory.php">
            <i class="fas fa-pills"></i> Inventory
        </a></li>
        <?php endif; ?>



        <?php if ($role === 'admin' || $role === 'receptionist' || $role === 'patient'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/billing/cost_estimator.php" class="<?php echo $current_page == 'cost_estimator.php' ? 'active' : ''; ?>">
            <i class="fas fa-calculator"></i> Cost Estimator
        </a></li>
        <?php endif; ?>

        <?php if ($role === 'admin' || $role === 'pharmacist'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/pharmacy/low_stock_alerts.php" class="<?php echo $current_page == 'low_stock_alerts.php' ? 'active' : ''; ?>">
            <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Enterprise & AI</div>
        <?php if (in_array($_SESSION['role'], ['admin', 'doctor', 'nurse'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ai/diagnosis_assist.php">
            <i class="fas fa-robot"></i> AI Assist
        </a></li>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'doctor', 'nurse', 'receptionist'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/drug_interactions.php" class="<?php echo $current_page == 'drug_interactions.php' ? 'active' : ''; ?>">
            <i class="fas fa-capsules"></i> Drug Interactions
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/health_analytics.php" class="<?php echo $current_page == 'health_analytics.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> Health Analytics
        </a></li>
        <?php if ($role !== 'doctor'): // Already shown in Clinical Workflow for doctor ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/discharge_summary.php" class="<?php echo $current_page == 'discharge_summary.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-medical"></i> Discharge Summary
        </a></li>
        <?php endif; ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/timeline.php" class="<?php echo $current_page == 'timeline.php' ? 'active' : ''; ?>">
            <i class="fas fa-stream"></i> Health Timeline
        </a></li>
        <?php if (in_array($role, ['admin', 'nurse', 'head_nurse', 'receptionist'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/patient_qr.php" class="<?php echo $current_page == 'patient_qr.php' ? 'active' : ''; ?>">
            <i class="fas fa-id-card"></i> Patient Card
        </a></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($role === 'patient'): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/health_analytics.php" class="<?php echo $current_page == 'health_analytics.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> My Analytics
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/timeline.php" class="<?php echo $current_page == 'timeline.php' ? 'active' : ''; ?>">
            <i class="fas fa-stream"></i> My Timeline
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/patient_qr.php" class="<?php echo $current_page == 'patient_qr.php' ? 'active' : ''; ?>">
            <i class="fas fa-id-card"></i> My Card
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/drug_interactions.php" class="<?php echo $current_page == 'drug_interactions.php' ? 'active' : ''; ?>">
            <i class="fas fa-capsules"></i> Drug Interactions
        </a></li>
        <?php endif; ?>


        <!-- Emergency Module -->
        <?php if (in_array($role, ['admin','doctor','nurse','head_nurse'])): ?>
        <div class="menu-category">Emergency & Clinical</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/emergency/dashboard.php" class="<?php echo strpos($current_page, 'emergency') !== false ? 'active' : ''; ?>">
            <i class="fas fa-ambulance"></i> Emergency / Casualty
        </a></li>
        <?php if (in_array($role, ['admin','nurse','head_nurse'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/infection_control/dashboard.php" class="<?php echo strpos($current_page, 'infection_control') !== false ? 'active' : ''; ?>">
            <i class="fas fa-biohazard"></i> Infection Control
        </a></li>
        <?php endif; ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/outcomes/clinical_outcomes.php" class="<?php echo strpos($current_page, 'clinical_outcomes') !== false ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i> Clinical Outcomes
        </a></li>
        <?php endif; ?>

        <!-- Referrals (Doctor & Admin) -->
        <?php if (in_array($role, ['admin','doctor'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/referrals/referrals.php" class="<?php echo strpos($current_page, 'referrals') !== false ? 'active' : ''; ?>">
            <i class="fas fa-exchange-alt"></i> Patient Referrals
        </a></li>
        <?php endif; ?>

        <!-- Consent Forms -->
        <?php if (in_array($role, ['admin','doctor','nurse','receptionist','patient'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/consent/consent_forms.php" class="<?php echo strpos($current_page, 'consent') !== false ? 'active' : ''; ?>">
            <i class="fas fa-file-signature"></i> Consent Forms
        </a></li>
        <?php endif; ?>

        <!-- Incident Reports -->
        <?php if (in_array($role, ['admin','doctor','nurse','head_nurse','pharmacist','lab_tech','radiologist','receptionist'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/incidents/incident_reports.php" class="<?php echo strpos($current_page, 'incident') !== false ? 'active' : ''; ?>">
            <i class="fas fa-exclamation-circle"></i> Incident Reports
        </a></li>
        <?php endif; ?>

        <!-- Shift Handover -->
        <?php if (in_array($role, ['admin','nurse','head_nurse'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/handover/shift_handover.php" class="<?php echo strpos($current_page, 'shift_handover') !== false ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i> Shift Handover
        </a></li>
        <?php endif; ?>

        <!-- Doctor/Staff Leave -->
        <?php if (in_array($role, ['doctor','receptionist','nurse','head_nurse'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/doctor_availability.php<?php echo in_array($role, ['nurse','head_nurse','receptionist']) ? '?tab=staff' : ''; ?>" class="<?php echo strpos($current_page, 'doctor_availability') !== false ? 'active' : ''; ?>">
            <i class="fas fa-calendar-check"></i> My Leaves
        </a></li>
        <?php endif; ?>

        <!-- Equipment & Scheduling -->
        <?php if (in_array($role, ['admin','nurse','head_nurse'])): ?>
        <?php endif; ?>

        <!-- Appointment Reminders -->
        <?php if (in_array($role, ['admin','receptionist'])): ?>
        <li><a href="<?php echo BASE_URL; ?>/modules/ehr/appointment_reminders.php" class="<?php echo strpos($current_page, 'appointment_reminders') !== false ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i> Appointment Reminders
        </a></li>
        <?php endif; ?>

        <!-- Admin-only sections -->
        <?php if ($role === 'admin'): ?>
        <div class="menu-category">Procurement</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/procurement/purchase_orders.php" class="<?php echo strpos($current_page, 'purchase_orders') !== false ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i> Purchase Orders
        </a></li>

        <div class="menu-category">HR & Finance</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/hr/roster.php" class="<?php echo strpos($current_page, 'roster') !== false ? 'active' : ''; ?>">
            <i class="fas fa-calendar-week"></i> Duty Roster
        </a></li>
        <li><a href="<?php echo BASE_URL; ?>/modules/hr/payroll.php" class="<?php echo strpos($current_page, 'payroll') !== false ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice-dollar"></i> Payroll
        </a></li>

        <div class="menu-category">Compliance</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/compliance/dashboard.php" class="<?php echo strpos($current_page, '/compliance/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-shield-alt"></i> Compliance & Security
        </a></li>
        <?php endif; ?>

        <!-- HR Tools for Head Nurse too -->
        <?php if ($role === 'head_nurse'): ?>
        <div class="menu-category">HR Tools</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/hr/roster.php" class="<?php echo strpos($current_page, 'roster') !== false ? 'active' : ''; ?>">
            <i class="fas fa-calendar-week"></i> Duty Roster
        </a></li>
        <?php endif; ?>

        <!-- Patient Portal -->
        <?php if ($role === 'patient'): ?>
        <div class="menu-category">My Health</div>
        <li><a href="<?php echo BASE_URL; ?>/modules/patient_management/patient_portal.php" class="<?php echo strpos($current_page, 'patient_portal') !== false ? 'active' : ''; ?>">
            <i class="fas fa-heartbeat"></i> My Health Portal
        </a></li>
        <?php endif; ?>

        <div class="menu-category">Help</div>
        <li><a href="<?php echo BASE_URL; ?>/help_center.php">
            <i class="far fa-question-circle"></i> Help Center
        </a></li>
    </ul>
</aside>
