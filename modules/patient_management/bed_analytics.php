<?php
// modules/patient_management/bed_analytics.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse']);

// ── Overall Stats ─────────────────────────────────────────────────────────────
$overall = db_select_one("
    SELECT
        COUNT(*) AS total,
        COUNT(CASE WHEN status='occupied' THEN 1 END)  AS occupied,
        COUNT(CASE WHEN status='available' THEN 1 END) AS available,
        COUNT(CASE WHEN status='maintenance' THEN 1 END) AS maintenance
    FROM beds");

$total      = (int)($overall['total'] ?? 0);
$occupied   = (int)($overall['occupied'] ?? 0);
$available  = (int)($overall['available'] ?? 0);
$maintenance= (int)($overall['maintenance'] ?? 0);
$occ_rate   = $total > 0 ? round(($occupied / $total) * 100, 1) : 0;

// Average Length of Stay (days)
$alos = db_select_one("
    SELECT ROUND(AVG(EXTRACT(EPOCH FROM (COALESCE(discharge_date, NOW()) - admission_date))/86400), 1) AS avg_days
    FROM admissions WHERE status = 'admitted'");
$avg_los = $alos['avg_days'] ?? 0;

// ── Occupancy by Ward ──────────────────────────────────────────────────────────
$by_ward = db_select("
    SELECT
        COALESCE(b.ward, 'General') AS ward,
        COUNT(*) AS total_beds,
        COUNT(CASE WHEN b.status='occupied' THEN 1 END) AS occupied_beds,
        COUNT(CASE WHEN b.status='available' THEN 1 END) AS available_beds
    FROM beds b
    GROUP BY COALESCE(b.ward, 'General')
    ORDER BY occupied_beds DESC");

// ── Current Patients in Beds ───────────────────────────────────────────────────
$filter_ward = $_GET['ward'] ?? '';
$ward_sql    = $filter_ward ? "AND b.ward = '$1'" : "";
$ward_params = $filter_ward ? [$filter_ward] : [];

$patients_in_beds = db_select("
    SELECT b.bed_number, b.ward, b.room_number,
           p.first_name || ' ' || p.last_name AS patient_name,
           a.admission_date,
           EXTRACT(DAY FROM NOW() - a.admission_date)::INT AS days_admitted,
           s.first_name || ' ' || s.last_name AS doctor_name,
           a.status AS admission_status
    FROM beds b
    LEFT JOIN admissions a ON b.id = a.bed_id AND a.status = 'admitted'
    LEFT JOIN patients p   ON a.patient_id = p.id
    LEFT JOIN staff s      ON a.doctor_id = s.id
    WHERE b.status = 'occupied'
    " . ($filter_ward ? "AND b.ward = \$1" : "") . "
    ORDER BY a.admission_date ASC", $ward_params);

// ── Trend data: last 7 days (mock/derived from admissions) ────────────────────
$trend = db_select("
    SELECT DATE(admission_date) AS d, COUNT(*) AS new_admissions
    FROM admissions
    WHERE admission_date >= NOW() - INTERVAL '7 days'
    GROUP BY DATE(admission_date)
    ORDER BY d ASC");

$trend_labels = [];
$trend_data   = [];
for ($i = 6; $i >= 0; $i--) {
    $day   = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime($day));
    $count = 0;
    foreach ($trend as $t) {
        if (substr($t['d'], 0, 10) === $day) { $count = (int)$t['new_admissions']; break; }
    }
    $trend_labels[] = $label;
    $trend_data[]   = $count;
}

// Ward occupancy chart data
$ward_labels   = array_column($by_ward, 'ward');
$ward_occupied = array_column($by_ward, 'occupied_beds');
$ward_total    = array_column($by_ward, 'total_beds');

$page_title = "Bed Occupancy Analytics";
include '../../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.ba-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:18px; margin-bottom:28px; }
.ba-card  { background:#fff; border-radius:14px; padding:22px; box-shadow:0 4px 15px rgba(0,0,0,.05); text-align:center; }
.ba-num   { font-size:2.4rem; font-weight:700; }
.ba-lbl   { font-size:.82rem; color:#888; margin-top:4px; }
.ba-charts{ display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:24px; }
@media(max-width:800px){ .ba-charts{ grid-template-columns:1fr; } }
.chart-box{ background:#fff; border-radius:14px; padding:22px; box-shadow:0 4px 15px rgba(0,0,0,.05); }
.occ-bar  { height:10px; border-radius:99px; background:#e5e7eb; overflow:hidden; margin-top:6px; }
.occ-fill { height:100%; border-radius:99px; }
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
    <div>
        <h1 style="margin:0;">Bed Occupancy Analytics</h1>
        <p style="color:#888;margin:4px 0 0;">Real-time bed utilisation, ward breakdowns, and patient stay analysis.</p>
    </div>
</div>

<!-- Stats -->
<div class="ba-stats">
    <div class="ba-card"><div class="ba-num" style="color:#3b82f6;"><?= $total ?></div><div class="ba-lbl">Total Beds</div></div>
    <div class="ba-card"><div class="ba-num" style="color:#ef4444;"><?= $occupied ?></div><div class="ba-lbl">Occupied</div></div>
    <div class="ba-card"><div class="ba-num" style="color:#22c55e;"><?= $available ?></div><div class="ba-lbl">Available</div></div>
    <div class="ba-card"><div class="ba-num" style="color:#f59e0b;"><?= $maintenance ?></div><div class="ba-lbl">Maintenance</div></div>
    <div class="ba-card">
        <div class="ba-num" style="color:<?= $occ_rate > 90 ? '#ef4444' : ($occ_rate > 70 ? '#f97316' : '#22c55e') ?>;">
            <?= $occ_rate ?>%
        </div>
        <div class="ba-lbl">Occupancy Rate</div>
    </div>
    <div class="ba-card"><div class="ba-num" style="color:#8b5cf6;"><?= $avg_los ?></div><div class="ba-lbl">Avg. Stay (days)</div></div>
</div>

<!-- Charts -->
<div class="ba-charts">
    <div class="chart-box">
        <h3 style="margin:0 0 16px;font-size:1rem;color:#444;">Occupancy by Ward</h3>
        <canvas id="wardChart" height="120"></canvas>
    </div>
    <div class="chart-box">
        <h3 style="margin:0 0 16px;font-size:1rem;color:#444;">Admissions — Last 7 Days</h3>
        <canvas id="trendChart" height="120"></canvas>
    </div>
</div>

<!-- Ward Breakdown Table -->
<div class="card" style="margin-bottom:24px;">
    <div style="padding:18px 20px;border-bottom:1px solid #f3f4f6;">
        <h3 style="margin:0;">Ward-wise Breakdown</h3>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Ward</th>
                    <th style="padding:12px 16px;text-align:right;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Total Beds</th>
                    <th style="padding:12px 16px;text-align:right;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Occupied</th>
                    <th style="padding:12px 16px;text-align:right;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Available</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Occupancy</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($by_ward)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">No bed data available.</td></tr>
                <?php else: ?>
                    <?php foreach ($by_ward as $w):
                        $rate = $w['total_beds'] > 0 ? round(($w['occupied_beds'] / $w['total_beds']) * 100) : 0;
                        $color = $rate > 90 ? '#ef4444' : ($rate > 70 ? '#f97316' : '#22c55e');
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:14px 16px;font-weight:600;"><?= htmlspecialchars($w['ward']) ?></td>
                        <td style="padding:14px 16px;text-align:right;"><?= $w['total_beds'] ?></td>
                        <td style="padding:14px 16px;text-align:right;color:#ef4444;font-weight:600;"><?= $w['occupied_beds'] ?></td>
                        <td style="padding:14px 16px;text-align:right;color:#22c55e;font-weight:600;"><?= $w['available_beds'] ?></td>
                        <td style="padding:14px 16px;min-width:180px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div class="occ-bar" style="flex:1;">
                                    <div class="occ-fill" style="width:<?= $rate ?>%;background:<?= $color ?>;"></div>
                                </div>
                                <span style="font-size:.85rem;font-weight:700;color:<?= $color ?>;min-width:40px;"><?= $rate ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Current Patients in Beds -->
<div class="card">
    <div style="padding:18px 20px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3 style="margin:0;">Current Patients in Beds (<?= count($patients_in_beds) ?>)</h3>
        <div style="display:flex;gap:10px;align-items:center;">
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <select name="ward" onchange="this.form.submit()" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;font-size:.88em;">
                    <option value="">All Wards</option>
                    <?php foreach ($by_ward as $w): ?>
                        <option value="<?= htmlspecialchars($w['ward']) ?>" <?= $filter_ward===$w['ward']?'selected':'' ?>>
                            <?= htmlspecialchars($w['ward']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <input type="text" id="searchBed" onkeyup="filterTable('searchBed','tbl-beds')" placeholder="Search patient..." style="padding:7px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:.88em;width:200px;outline:none;">
        </div>
    </div>
    <div class="table-responsive">
        <table id="tbl-beds" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Bed #</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Ward / Room</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Patient</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Admitted On</th>
                    <th style="padding:12px 16px;text-align:right;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Days</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Doctor</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($patients_in_beds)): ?>
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#888;"><i class="fas fa-bed" style="font-size:2rem;opacity:.3;margin-bottom:10px;display:block;"></i>No patients currently in beds.</td></tr>
                <?php else: ?>
                    <?php foreach ($patients_in_beds as $p):
                        $days = (int)($p['days_admitted'] ?? 0);
                        $dayColor = $days > 14 ? '#ef4444' : ($days > 7 ? '#f97316' : '#374151');
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:13px 16px;">
                            <span style="font-family:monospace;background:#f3f4f6;padding:3px 8px;border-radius:6px;font-weight:600;">
                                <?= htmlspecialchars($p['bed_number'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td style="padding:13px 16px;color:#555;">
                            <?= htmlspecialchars($p['ward'] ?? 'General') ?>
                            <?php if ($p['room_number']): ?>
                                <span style="color:#888;font-size:.82rem;"> · Rm <?= htmlspecialchars($p['room_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:13px 16px;font-weight:600;"><?= htmlspecialchars($p['patient_name'] ?? '—') ?></td>
                        <td style="padding:13px 16px;color:#555;"><?= $p['admission_date'] ? date('d M Y', strtotime($p['admission_date'])) : '—' ?></td>
                        <td style="padding:13px 16px;text-align:right;">
                            <strong style="color:<?= $dayColor ?>;"><?= $days ?></strong>
                        </td>
                        <td style="padding:13px 16px;color:#555;"><?= htmlspecialchars($p['doctor_name'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Ward Occupancy Chart
new Chart(document.getElementById('wardChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map('htmlspecialchars_decode', $ward_labels)) ?>,
        datasets: [
            {
                label: 'Occupied',
                data: <?= json_encode(array_map('intval', $ward_occupied)) ?>,
                backgroundColor: '#ef4444',
                borderRadius: 6,
            },
            {
                label: 'Total',
                data: <?= json_encode(array_map('intval', $ward_total)) ?>,
                backgroundColor: '#e5e7eb',
                borderRadius: 6,
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'top' } },
        scales: {
            x: { grid: { display: false }, stacked: false },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' } }
        }
    }
});

// Trend Chart
new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trend_labels) ?>,
        datasets: [{
            label: 'New Admissions',
            data: <?= json_encode($trend_data) ?>,
            borderColor: '#8b5cf6',
            backgroundColor: 'rgba(139,92,246,.1)',
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#8b5cf6',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { precision: 0 } }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
