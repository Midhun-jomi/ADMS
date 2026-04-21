<?php
// modules/ehr/health_analytics.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Health Analytics";
include '../../includes/header.php';

// Resolve patient_id
$patient_id = null;
$patient_name = '';

if ($role === 'patient') {
    $pat = db_select_one("SELECT id, first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
    if ($pat) {
        $patient_id = $pat['id'];
        $patient_name = $pat['first_name'] . ' ' . $pat['last_name'];
    }
} else {
    // Admin/Doctor: allow selecting patient via GET param
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if ($pid) {
        $pat = db_select_one("SELECT id, first_name, last_name FROM patients WHERE id = $1", [$pid]);
        if ($pat) {
            $patient_id = $pat['id'];
            $patient_name = $pat['first_name'] . ' ' . $pat['last_name'];
        }
    }
    $all_patients = db_select(
        "SELECT p.id, p.first_name, p.last_name
         FROM patients p
         JOIN (SELECT patient_id, COUNT(*) as appt_count FROM appointments GROUP BY patient_id HAVING COUNT(*) > 1) a
           ON a.patient_id = p.id
         ORDER BY p.first_name"
    );
}

// --- Fetch chart data ---
$appt_monthly = [];
$rx_monthly   = [];
$bill_monthly = [];

if ($patient_id) {
    // Appointments per month (last 12 months)
    $appt_rows = db_select(
        "SELECT TO_CHAR(appointment_time, 'YYYY-MM') as mo, COUNT(*) as cnt
         FROM appointments
         WHERE patient_id = $1 AND appointment_time >= NOW() - INTERVAL '12 months'
         GROUP BY mo ORDER BY mo",
        [$patient_id]
    );
    foreach ($appt_rows as $r) $appt_monthly[$r['mo']] = (int)$r['cnt'];

    // Prescriptions per month (last 12 months)
    $rx_rows = db_select(
        "SELECT TO_CHAR(created_at, 'YYYY-MM') as mo, COUNT(*) as cnt
         FROM prescriptions
         WHERE patient_id = $1 AND created_at >= NOW() - INTERVAL '12 months'
         GROUP BY mo ORDER BY mo",
        [$patient_id]
    );
    foreach ($rx_rows as $r) $rx_monthly[$r['mo']] = (int)$r['cnt'];

    // Billing spend per month (last 12 months, paid only)
    $bill_rows = db_select(
        "SELECT TO_CHAR(created_at, 'YYYY-MM') as mo, SUM(total_amount) as total
         FROM billing
         WHERE patient_id = $1 AND created_at >= NOW() - INTERVAL '12 months'
         GROUP BY mo ORDER BY mo",
        [$patient_id]
    );
    foreach ($bill_rows as $r) $bill_monthly[$r['mo']] = round((float)$r['total'], 2);

    // Build 12-month label array
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $months[] = date('Y-m', strtotime("-$i months"));
    }

    $appt_data = array_map(fn($m) => $appt_monthly[$m] ?? 0, $months);
    $rx_data   = array_map(fn($m) => $rx_monthly[$m] ?? 0, $months);
    $bill_data = array_map(fn($m) => $bill_monthly[$m] ?? 0, $months);

    $labels_display = array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months);

    // Summary stats
    $total_appts = array_sum($appt_data);
    $total_rx    = array_sum($rx_data);
    $total_spend = array_sum($bill_data);

    // Appointment type breakdown
    $appt_types = db_select(
        "SELECT status, COUNT(*) as cnt FROM appointments WHERE patient_id = $1 GROUP BY status",
        [$patient_id]
    );

    // Top prescribed medications
    $rx_all = db_select(
        "SELECT medication_details FROM prescriptions WHERE patient_id = $1",
        [$patient_id]
    );
    $med_count = [];
    foreach ($rx_all as $rx) {
        $meds = json_decode($rx['medication_details'] ?? '[]', true) ?: [];
        foreach ($meds as $med) {
            $name = strtolower(trim($med['name'] ?? ''));
            if ($name) $med_count[$name] = ($med_count[$name] ?? 0) + 1;
        }
    }
    arsort($med_count);
    $top_meds = array_slice($med_count, 0, 6, true);
}
?>

<style>
.analytics-wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 30px; }
.stat-card { background: white; border-radius: 14px; padding: 22px 24px; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
.stat-card .label { font-size: 0.8em; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-card .value { font-size: 2rem; font-weight: 800; margin: 6px 0 2px; }
.chart-card { background: white; border-radius: 14px; padding: 26px; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
.chart-title { font-size: 0.88em; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 18px; }
.chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width: 768px) { .chart-grid { grid-template-columns: 1fr; } }
.patient-selector { background: white; border-radius: 14px; padding: 22px 24px; border: 1px solid #e5e7eb; margin-bottom: 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.empty-state { text-align: center; padding: 60px 20px; color: #9ca3af; }
</style>

<div class="analytics-wrap">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 26px;">
        <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.3em;">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 1.4em; font-weight: 800; color: #111827;">Health Analytics</h2>
            <p style="margin: 0; color: #6b7280; font-size: 0.88em;">Visual trends for appointments, prescriptions &amp; spending</p>
        </div>
    </div>

    <?php if ($role !== 'patient'): ?>
    <div class="patient-selector">
        <form method="GET" action="" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; width: 100%;">
            <label style="font-weight: 600; color: #374151; font-size: 0.9em;">Select Patient:</label>
            <select name="patient_id" class="form-control" style="border-radius: 8px; height: 40px; max-width: 300px;" onchange="this.form.submit()">
                <option value="">-- Choose Patient --</option>
                <?php foreach ($all_patients as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo ($patient_id == $p['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($patient_id): ?>
                <span style="color: #6366f1; font-weight: 600; font-size: 0.9em;"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($patient_name); ?></span>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$patient_id): ?>
        <div class="empty-state">
            <i class="fas fa-chart-bar" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
            <h4 style="color: #374151;">No Patient Selected</h4>
            <p>Select a patient above to view their health analytics.</p>
        </div>
    <?php else: ?>

    <!-- Summary Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="label"><i class="fas fa-calendar-check" style="color: #6366f1;"></i> Appointments (12M)</div>
            <div class="value" style="color: #6366f1;"><?php echo $total_appts; ?></div>
            <div style="font-size: 0.8em; color: #9ca3af;">Total visits recorded</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-prescription-bottle-alt" style="color: #10b981;"></i> Prescriptions (12M)</div>
            <div class="value" style="color: #10b981;"><?php echo $total_rx; ?></div>
            <div style="font-size: 0.8em; color: #9ca3af;">Medication courses</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-rupee-sign" style="color: #f59e0b;"></i> Total Spend (12M)</div>
            <div class="value" style="color: #f59e0b;">₹<?php echo number_format($total_spend, 0); ?></div>
            <div style="font-size: 0.8em; color: #9ca3af;">Across all invoices</div>
        </div>
        <div class="stat-card">
            <div class="label"><i class="fas fa-pills" style="color: #ef4444;"></i> Unique Medications</div>
            <div class="value" style="color: #ef4444;"><?php echo count($med_count); ?></div>
            <div style="font-size: 0.8em; color: #9ca3af;">Different drugs prescribed</div>
        </div>
    </div>

    <!-- Line Charts -->
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-chart-line" style="color: #6366f1; margin-right: 6px;"></i> Appointments &amp; Prescriptions Over Time</div>
        <canvas id="trendChart" height="90"></canvas>
    </div>

    <div class="chart-grid">
        <!-- Billing Spend Chart -->
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-rupee-sign" style="color: #f59e0b; margin-right: 6px;"></i> Monthly Billing Spend</div>
            <canvas id="billChart" height="200"></canvas>
        </div>

        <!-- Appointment Status Donut -->
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-circle-notch" style="color: #10b981; margin-right: 6px;"></i> Appointment Status Breakdown</div>
            <?php if (empty($appt_types)): ?>
                <div style="text-align: center; padding: 40px; color: #9ca3af;">No appointment data</div>
            <?php else: ?>
                <canvas id="statusChart" height="200"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Medications Bar -->
    <?php if (!empty($top_meds)): ?>
    <div class="chart-card">
        <div class="chart-title"><i class="fas fa-pills" style="color: #ef4444; margin-right: 6px;"></i> Most Frequently Prescribed Medications</div>
        <canvas id="medChart" height="90"></canvas>
    </div>
    <?php endif; ?>

    <?php endif; // end patient_id check ?>
</div>

<?php if ($patient_id): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const labels = <?php echo json_encode($labels_display); ?>;
const apptData = <?php echo json_encode($appt_data); ?>;
const rxData   = <?php echo json_encode($rx_data); ?>;
const billData = <?php echo json_encode($bill_data); ?>;

// Common chart defaults
Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.color = '#6b7280';

// Trend chart: Appointments + Prescriptions
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [
            {
                label: 'Appointments',
                data: apptData,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99,102,241,0.08)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointBackgroundColor: '#6366f1'
            },
            {
                label: 'Prescriptions',
                data: rxData,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,0.08)',
                tension: 0.4,
                fill: true,
                pointRadius: 4,
                pointBackgroundColor: '#10b981'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f3f4f6' } },
            x: { grid: { display: false } }
        }
    }
});

// Billing bar chart
new Chart(document.getElementById('billChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [{
            label: 'Spend (₹)',
            data: billData,
            backgroundColor: 'rgba(245,158,11,0.7)',
            borderColor: '#f59e0b',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f3f4f6' } },
            x: { grid: { display: false } }
        }
    }
});

// Appointment status donut
<?php if (!empty($appt_types)): ?>
const statusLabels = <?php echo json_encode(array_column($appt_types, 'status')); ?>;
const statusCounts = <?php echo json_encode(array_map(fn($r) => (int)$r['cnt'], $appt_types)); ?>;
const statusColors = statusLabels.map(s => ({
    scheduled: '#6366f1', completed: '#10b981', cancelled: '#ef4444',
    pending: '#f59e0b', confirmed: '#3b82f6'
}[s] || '#9ca3af'));

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels.map(s => s.charAt(0).toUpperCase() + s.slice(1)),
        datasets: [{ data: statusCounts, backgroundColor: statusColors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.raw} visits`
        }}}
    }
});
<?php endif; ?>

// Top medications bar
<?php if (!empty($top_meds)): ?>
const medLabels = <?php echo json_encode(array_map('ucfirst', array_keys($top_meds))); ?>;
const medCounts = <?php echo json_encode(array_values($top_meds)); ?>;
const medColors = ['#6366f1','#10b981','#f59e0b','#ef4444','#3b82f6','#8b5cf6'];

new Chart(document.getElementById('medChart'), {
    type: 'bar',
    data: {
        labels: medLabels,
        datasets: [{
            label: 'Times Prescribed',
            data: medCounts,
            backgroundColor: medColors.slice(0, medLabels.length),
            borderRadius: 6,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f3f4f6' } },
            y: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
