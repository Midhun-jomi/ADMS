<?php
// dashboards/admin_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['admin']);

$page_title = "Dashboard";
include '../includes/header.php';

// Fetch Real Statistics (Variables are already fetched in stats_widgets.php if we wanted to reuse, but that scope is local to include. 
// However, since stats_widgets.php is included in header, it runs first. 
// But wait, header is included... so stats_widgets is output.
// We don't need to fetch them again for display here unless we use them elsewhere.
// We can just show the rest of the dashboard content.
?>

<div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="card">
        <div class="card-header">
            <span>Patient Health</span>
            <i class="fas fa-ellipsis-h"></i>
        </div>
        <div style="text-align: center; padding: 20px;">
            <img src="https://via.placeholder.com/300x200?text=Lungs+Visualization" alt="Lungs" style="max-width: 100%; border-radius: 10px;">
            <div style="display: flex; justify-content: space-around; margin-top: 20px;">
                <div class="card" style="padding: 10px; width: 45%;">
                    <strong>45.06° C</strong><br><small>Temperature</small>
                </div>
                <div class="card" style="padding: 10px; width: 45%;">
                    <strong>108 bpm</strong><br><small>Heart Rate</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Total Revenue</span>
            <select style="border: none; background: #f4f7fe; padding: 5px; border-radius: 5px;">
                <option>Monthly</option>
            </select>
        </div>
        <div style="height: 250px; display: flex; align-items: center; justify-content: center; background: #f9f9f9; border-radius: 10px;">
            <p style="color: #888;">[Chart Placeholder]</p>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 20px;">
            <div>
                <small>Hospital Total Income</small><br>
                <strong>$ 7,12,3264</strong>
            </div>
            <div>
                <small>Hospital Total Expense</small><br>
                <strong>$ 14,965,5476</strong>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

