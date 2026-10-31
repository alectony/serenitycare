<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$search = trim($_GET['search'] ?? '');

$patients = get_counselor_patients($me['user']['id'], $me['token']);
if ($search !== '') {
    $patients = array_values(array_filter($patients, fn($p) =>
        stripos($p['full_name'] ?? '', $search) !== false || stripos($p['email'] ?? '', $search) !== false));
}

page_start('counselor', 'Patient List', '/counselor/patients.php', 'Your Patients');
?>
            <div class="card">
                <form method="GET" action="/counselor/patients.php" style="margin-bottom:20px;">
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search by name or email..." style="padding:8px; width:300px;">
                    <button type="submit" class="btn">Search</button>
                </form>
                <table class="data-table">
                    <thead><tr><th>Full Name</th><th>Type</th><th>Grade / Department</th><th>Email</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$patients): ?>
                        <tr><td colspan="5">No patients found.</td></tr>
                    <?php else: foreach ($patients as $p): ?>
                        <tr>
                            <td><?php echo e($p['full_name']); ?></td>
                            <td><?php echo e(ucfirst($p['patient_type'] ?? '-')); ?></td>
                            <td><?php echo e($p['grade_or_dept'] ?? 'N/A'); ?></td>
                            <td><?php echo e($p['email'] ?? ''); ?></td>
                            <td><a href="/counselor/record.php?patient_id=<?php echo e($p['id']); ?>" class="btn-sm">View Record</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>