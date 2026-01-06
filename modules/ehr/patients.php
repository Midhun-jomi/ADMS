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

// Base query
$sql = "SELECT * FROM patients WHERE 1=1";
$params = [];

// Search filter
if ($search) {
    $sql .= " AND (first_name ILIKE $1 OR last_name ILIKE $1 OR phone ILIKE $1)";
    $params[] = "%$search%";
}

$sql .= " ORDER BY last_name ASC LIMIT 50";

$patients = db_select($sql, $params);
?>

<div class="card">
    <div class="card-header">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span>Patient List</span>
            <form method="GET" action="" style="display: flex; gap: 10px;">
                <input type="text" name="search" class="form-control" placeholder="Search name or phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary">Search</button>
            </form>
        </div>
    </div>
    
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Patient ID</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Name</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Age/Gender</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Blood Group</th>
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
                            <a href="book_appointment.php?patient_id=<?php echo $p['id']; ?>" class="btn btn-sm" style="background: #007bff; color: white;">Book Appt</a>
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

<?php include '../../includes/footer.php'; ?>
