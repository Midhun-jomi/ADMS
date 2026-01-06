<?php
// modules/admin/reports.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "System Reports";
include '../../includes/header.php';
?>

<div class="card">
    <div class="card-header">System Reports</div>
    <div class="card-body">
        <p>Select a report to view:</p>
        
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 200px; padding: 20px; background: #e3f2fd; border-radius: 8px;">
                <h5>Patient Demographics</h5>
                <p>View patient distribution by age, gender, etc.</p>
                <button class="btn btn-sm btn-primary" disabled>View Report (Coming Soon)</button>
            </div>
            
            <div style="flex: 1; min-width: 200px; padding: 20px; background: #e8f5e9; border-radius: 8px;">
                <h5>Financial Overview</h5>
                <p>Revenue, outstanding invoices, and expenses.</p>
                <button class="btn btn-sm btn-primary" disabled>View Report (Coming Soon)</button>
            </div>
            
            <div style="flex: 1; min-width: 200px; padding: 20px; background: #fff3e0; border-radius: 8px;">
                <h5>Appointment Stats</h5>
                <p>Visit volume, cancellations, and doctor load.</p>
                <button class="btn btn-sm btn-primary" disabled>View Report (Coming Soon)</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
