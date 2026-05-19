<?php
// modules/ehr/patients.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['doctor', 'admin', 'receptionist']);

$page_title = "My Patients";
include '../../includes/header.php';

$role = get_user_role();
$user_id = get_user_id();

$search = $_GET['search'] ?? '';
$admitted_only = isset($_GET['admitted_only']) && $_GET['admitted_only'] == '1';

// Base query
$sql = "SELECT p.* FROM patients p";
$params = [];
$where = ["1=1"];

if ($admitted_only) {
    $sql .= " JOIN admissions a ON p.id = a.patient_id AND a.status = 'admitted'";
}

// Search filter
if ($search) {
    $where[] = "(p.first_name ILIKE $" . (count($params) + 1) . " OR p.last_name ILIKE $" . (count($params) + 1) . " OR p.phone ILIKE $" . (count($params) + 1) . ")";
    $params[] = "%$search%";
}

$sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY p.last_name ASC LIMIT 100";

$patients = db_select($sql, $params);
?>

<div class="card">
    <div class="card-header">
        Patient List
    </div>
    
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0;">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; width: 100%;">
            <div style="position: relative; flex: 1;">
                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, phone or UHID..." style="padding: 10px 10px 10px 35px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100%; outline: none;">
            </div>
            
            <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; user-select: none; font-weight: 500; color: #475569;">
                <input type="checkbox" name="admitted_only" value="1" <?php echo $admitted_only ? 'checked' : ''; ?> onchange="this.form.submit()" style="width: 18px; height: 18px;">
                Currently Admitted
            </label>

            <button type="submit" class="btn btn-primary" style="background: #4f46e5; border: none; padding: 10px 25px; border-radius: 8px; font-weight: 600;">
                Apply Filters
            </button>
            <?php if ($search || $admitted_only): ?>
                <a href="patients.php" class="btn btn-light" style="border: 1px solid #cbd5e1; padding: 10px 15px; border-radius: 8px; color: #64748b; text-decoration: none;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <table id="tbl-patients" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; cursor:pointer; user-select:none;" onclick="sortTable(0)" title="Sort by Patient ID">Patient ID <span id="sort-icon-0">↕</span></th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; cursor:pointer; user-select:none;" onclick="sortTable(1)" title="Sort by Name">Name <span id="sort-icon-1">↕</span></th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; cursor:pointer; user-select:none;" onclick="sortTable(2)" title="Sort by Age">Age/Gender <span id="sort-icon-2">↕</span></th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; cursor:pointer; user-select:none;" onclick="sortTable(3)" title="Sort by Blood Group">Blood Group <span id="sort-icon-3">↕</span></th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Phone</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($patients)): ?>
                <tr><td colspan="5" style="padding: 10px;">No patients found.</td></tr>
            <?php else: ?>
                <?php foreach ($patients as $p): 
                    $age = date_diff(date_create($p['date_of_birth']), date_create('today'))->y;
                ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td style="padding: 10px;">
                            <span style="font-family: monospace; background: #eee; padding: 2px 5px; border-radius: 4px;">
                                P-<?php echo str_pad($p['uhid'], 4, '0', STR_PAD_LEFT); ?>
                            </span>
                        </td>
                        <td style="padding: 10px;">
                            <strong><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></strong>
                        </td>
                        <td style="padding: 10px;"><?php echo $age . ' yrs / ' . htmlspecialchars($p['gender'] ?? 'Unknown'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($p['blood_group'] ?? '-'); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($p['phone'] ?? 'N/A'); ?></td>
                        <td style="padding: 10px;">
                            <a href="history.php?patient_id=<?php echo $p['id']; ?>" class="btn btn-sm" style="background: #17a2b8; color: white;">History</a>
                            <?php if ($role !== 'doctor'): ?>
                                <a href="book_appointment.php?patient_id=<?php echo $p['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white;">Book Appt</a>
                            <?php endif; ?>
                            <?php if ($role === 'admin'): ?>
                                <a href="edit_profile.php?id=<?php echo $p['id']; ?>" class="btn btn-sm" style="background: #ffc107; color: black;">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
let sortState = { col: -1, asc: true };

function sortTable(col) {
    const table = document.getElementById('tbl-patients');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    // Toggle direction if same column
    if (sortState.col === col) {
        sortState.asc = !sortState.asc;
    } else {
        sortState.col = col;
        sortState.asc = true;
    }

    // Update icons
    for (let i = 0; i <= 3; i++) {
        const icon = document.getElementById('sort-icon-' + i);
        if (icon) icon.textContent = '↕';
    }
    const activeIcon = document.getElementById('sort-icon-' + col);
    if (activeIcon) activeIcon.textContent = sortState.asc ? '↑' : '↓';

    rows.sort((a, b) => {
        const aText = (a.cells[col]?.textContent || '').trim();
        const bText = (b.cells[col]?.textContent || '').trim();

        // Numeric sort for Patient ID (col 0) and Age (col 2)
        if (col === 0) {
            const aNum = parseInt(aText.replace(/\D/g, '')) || 0;
            const bNum = parseInt(bText.replace(/\D/g, '')) || 0;
            return sortState.asc ? aNum - bNum : bNum - aNum;
        }
        if (col === 2) {
            const aNum = parseInt(aText) || 0;
            const bNum = parseInt(bText) || 0;
            return sortState.asc ? aNum - bNum : bNum - aNum;
        }

        return sortState.asc
            ? aText.localeCompare(bText)
            : bText.localeCompare(aText);
    });

    rows.forEach(row => tbody.appendChild(row));
}
</script>

<?php include '../../includes/footer.php'; ?>
