<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

$pr = sb_request('GET', 'profiles', 'select=id,full_name,email,role,is_active,created_at&order=created_at.desc', null, $token);
$profiles = ($pr['status'] === 200 && is_array($pr['data'])) ? $pr['data'] : [];

$ar = sb_request('GET', 'appointments', 'select=id,status', null, $token);
$appointments = ($ar['status'] === 200 && is_array($ar['data'])) ? $ar['data'] : [];

$counts = ['patient' => 0, 'counselor' => 0, 'admin' => 0];
foreach ($profiles as $p) { if (isset($counts[$p['role']])) { $counts[$p['role']]++; } }
$pending = count(array_filter($appointments, fn($a) => $a['status'] === 'pending'));

page_start('admin', 'Dashboard', '/admin/', 'Admin Overview');
?>
            <div style="display:flex; gap:16px; margin-bottom:16px;">
                <div class="card" style="flex:1;"><small>Total users</small><h2><?php echo count($profiles); ?></h2></div>
                <div class="card" style="flex:1;"><small>Counselors</small><h2><?php echo $counts['counselor']; ?></h2></div>
                <div class="card" style="flex:1;"><small>Appointments</small><h2><?php echo count($appointments); ?></h2></div>
                <div class="card" style="flex:1;"><small>Pending</small><h2><?php echo $pending; ?></h2></div>
            </div>

            <div class="card">
                <h2>Recent Users</h2>
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                    <tbody>
                    <?php if (!$profiles): ?>
                        <tr><td colspan="5">No users found.</td></tr>
                    <?php else: foreach (array_slice($profiles, 0, 10) as $p): ?>
                        <tr>
                            <td><?php echo e($p['full_name'] ?? '-'); ?></td>
                            <td><?php echo e($p['email'] ?? '-'); ?></td>
                            <td><?php echo e(ucfirst($p['role'])); ?></td>
                            <td><?php echo !empty($p['is_active']) ? 'Active' : 'Deactivated'; ?></td>
                            <td><?php echo e(fmt_dt($p['created_at'], 'M d, Y')); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end();