<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

$pr = sb_request('GET', 'profiles', 'select=id,full_name,email,role,is_active,created_at&order=created_at.desc', null, $token);
$failed = ($pr['status'] !== 200 || !is_array($pr['data']));
$profiles = $failed ? [] : $pr['data'];

$ar = sb_request('GET', 'appointments', 'select=id,status', null, $token);
$appointments = ($ar['status'] === 200 && is_array($ar['data'])) ? $ar['data'] : [];

$counts = ['patient' => 0, 'counselor' => 0, 'admin' => 0];
foreach ($profiles as $p) { if (isset($counts[$p['role']])) { $counts[$p['role']]++; } }
$pending = count(array_filter($appointments, fn($a) => $a['status'] === 'pending'));

page_start('admin', 'Dashboard', '/admin/', 'Admin Overview');
?>
            <?php if ($failed): ?><div class="alert error">Could not load users. Please refresh.</div><?php endif; ?>

            <div class="stats">
                <?php echo stat_card('Total users', count($profiles), 'All roles', 'users'); ?>
                <?php echo stat_card('Counselors', $counts['counselor'], 'Registered counselors', 'user'); ?>
                <?php echo stat_card('Appointments', count($appointments), 'All time', 'calendar'); ?>
                <?php echo stat_card('Pending', $pending, 'Awaiting counselors', 'clock'); ?>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-head"><div><h2>Recent Users</h2><p class="card-sub">Newest accounts across all roles</p></div></div>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php if (!$profiles): ?>
                            <tr><td colspan="5">No users found.</td></tr>
                        <?php else: foreach (array_slice($profiles, 0, 10) as $p): ?>
                            <tr>
                                <td><strong><?php echo e($p['full_name'] ?? '-'); ?></strong></td>
                                <td><?php echo e($p['email'] ?? '-'); ?></td>
                                <td><?php echo status_badge($p['role']); ?></td>
                                <td><?php echo !empty($p['is_active']) ? '<span class="status active">Active</span>' : '<span class="status deactivated">Deactivated</span>'; ?></td>
                                <td class="nowrap"><?php echo e(fmt_dt($p['created_at'], 'M d, Y')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="stack">
                    <div class="card">
                        <div class="card-head"><h2>Users by Role</h2></div>
                        <?php foreach (['patient' => 'Patients', 'counselor' => 'Counselors', 'admin' => 'Administrators'] as $k => $label): ?>
                            <div class="result-item row-between"><strong><?php echo e($label); ?></strong><span class="count"><?php echo (int) $counts[$k]; ?></span></div>
                        <?php endforeach; ?>
                        <div class="note-box"><strong>Coming next</strong><small>User management, counselor verification and audit logs.</small></div>
                    </div>
                </div>
            </div>
<?php page_end(); ?>