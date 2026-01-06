<?php
// search_results.php
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_auth();

$page_title = "Search Results";
include 'includes/header.php';

$query = $_GET['q'] ?? '';
$role = get_user_role();
$results = [];

if (strlen($query) > 2) {
    // Escape for LIKE
    $search_term = "%" . $query . "%";
    
    // 1. Search Patients (Doctors/Admins only)
    if ($role === 'doctor' || $role === 'admin' || $role === 'receptionist') {
        $pats = db_select("SELECT id, first_name, last_name, 'Patient' as type, '/modules/ehr/history.php?patient_id=' || id as link 
                           FROM patients 
                           WHERE first_name ILIKE $1 OR last_name ILIKE $1", [$search_term]);
        $results = array_merge($results, $pats);
    }
    
    // 2. Search Staff/Doctors
    $staff = db_select("SELECT id, first_name, last_name, role as type, '#' as link 
                        FROM staff 
                        WHERE first_name ILIKE $1 OR last_name ILIKE $1", [$search_term]);
    foreach ($staff as &$s) {
        $s['link'] = ($role === 'admin') ? "/modules/admin/edit_staff.php?id=" . $s['id'] : '#';
        $s['type'] = ucfirst($s['type']);
    }
    $results = array_merge($results, $staff);
    
    // 3. Search Modules/Pages (Navigation)
    $pages = [
        ['name' => 'Book Appointment', 'url' => '/modules/ehr/appointments.php', 'roles' => ['patient']],
        ['name' => 'My Appointments', 'url' => '/modules/ehr/appointments.php', 'roles' => ['patient', 'doctor']],
        ['name' => 'Lab Results', 'url' => '/modules/lab/results.php', 'roles' => ['patient', 'doctor', 'lab_tech']],
        ['name' => 'Medical History', 'url' => '/modules/ehr/history.php', 'roles' => ['doctor', 'patient']],
        ['name' => 'Invoices / Payments', 'url' => '/modules/billing/invoices.php', 'roles' => ['patient', 'admin', 'receptionist']],
        ['name' => 'Settings', 'url' => '/settings.php', 'roles' => ['all']]
    ];
    
    foreach ($pages as $p) {
        if (stripos($p['name'], $query) !== false) {
            if (in_array('all', $p['roles']) || in_array($role, $p['roles'])) {
                $results[] = [
                    'first_name' => $p['name'],
                    'last_name' => '',
                    'type' => 'Page',
                    'link' => $p['url']
                ];
            }
        }
    }
}
?>

<div class="card">
    <div class="card-header">
        Search Results for "<?php echo htmlspecialchars($query); ?>"
    </div>
    
    <?php if (empty($results) && strlen($query) > 2): ?>
        <p style="padding: 20px; color: #666;">No results found.</p>
    <?php elseif (strlen($query) <= 2): ?>
        <p style="padding: 20px; color: #666;">Please enter at least 3 characters to search.</p>
    <?php else: ?>
        <ul style="list-style: none; padding: 0;">
            <?php foreach ($results as $r): ?>
                <li style="border-bottom: 1px solid #eee; padding: 15px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <strong><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></strong>
                        <span class="badge badge-secondary" style="margin-left: 10px; font-size: 0.8em; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px;">
                            <?php echo htmlspecialchars($r['type']); ?>
                        </span>
                    </div>
                    <?php if ($r['link'] !== '#'): ?>
                        <a href="<?php echo htmlspecialchars($r['link']); ?>" class="btn btn-sm btn-outline-primary">Go</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
