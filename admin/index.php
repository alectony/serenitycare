The layout appeared broken because the CSS class names used in the PHP file (`header-search-input`, `profile-interactable`, etc.) did not match the class names defined in your stylesheet (`dashboard-search-input`, `profile-interactable`, etc.), causing the browser to render them unstyled and the search icon to blow up to its full native size.

Here is the corrected **`admin/index.php`** using the exact matching stylesheet class names:

### Updated `admin/index.php`

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

// Capture search query parameter for filtering profiles from the database
$search = trim($_GET['q'] ?? '');

$profileQuery = 'select=id,full_name,email,role,is_active,created_at';
if ($search !== '') {
    $profileQuery .= '&or=(full_name.ilike.*' . urlencode($search) . '*,email.ilike.*' . urlencode($search) . '*)';
}
$profileQuery .= '&order=created_at.desc&limit=5000';

$pr = sb_request('GET', 'profiles', $profileQuery, null, $token);
$failed = ($pr['status'] !== 200 || !is_array($pr['data']));
$profiles = $failed ? [] : $pr['data'];

$ar = sb_request('GET', 'appointments', 'select=id,status,scheduled_at&limit=5000', null, $token);
$appointments = ($ar['status'] === 200 && is_array($ar['data'])) ? $ar['data'] : [];

$vr = sb_request('GET', 'counselor_verifications', 'select=counselor_id,status', null, $token);
$verif = [];
if ($vr['status'] === 200 && is_array($vr['data'])) { foreach ($vr['data'] as $v) { $verif[$v['counselor_id']] = $v['status']; } }

$lr = sb_request('GET', 'audit_logs', 'select=created_at,actor_name,action,detail&order=created_at.desc&limit=5', null, $token);
$logs = ($lr['status'] === 200 && is_array($lr['data'])) ? $lr['data'] : [];

$roleCounts = ['patient' => 0, 'counselor' => 0, 'admin' => 0];
$counselorIds = [];
foreach ($profiles as $p) {
    if (isset($roleCounts[$p['role']])) { $roleCounts[$p['role']]++; }
    if ($p['role'] === 'counselor') { $counselorIds[] = $p['id']; }
}
$pendingVerify = count(array_filter($counselorIds, fn($id) => ($verif[$id] ?? 'pending') === 'pending'));
$verifiedCount = count(array_filter($counselorIds, fn($id) => ($verif[$id] ?? '') === 'verified'));

$statusCounts = array_fill_keys(['pending', 'confirmed', 'completed', 'declined', 'cancelled'], 0);
foreach ($appointments as $a) { if (isset($statusCounts[$a['status']])) { $statusCounts[$a['status']]++; } }

$months = month_buckets(6);
$apptByMonth = count_by_month($appointments, 'scheduled_at', $months);
$usersByMonth = count_by_month($profiles, 'created_at', $months);

// Prepare admin profile initials for avatar display
$fullName = $me['profile']['full_name'] ?? 'Administrator';
$nameParts = explode(' ', $fullName);
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// Construct header search bar and profile interactable widgets using matching stylesheet classes
$rightContent = '
    <form method="GET" action="/admin/" class="dashboard-search-form">
        <div class="dashboard-search-wrapper">
            <input type="text" name="q" value="' . e($search) . '" placeholder="Search users by name or email..." class="dashboard-search-input">
            <svg class="dashboard-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </div>
        ' . ($search !== '' ? '<a href="/admin/" class="btn-sm">Clear</a>' : '') . '
    </form>
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($initials) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>System Administrator</small>
        </div>
    </div>
';

page_start('admin', 'Dashboard', '/admin/', 'Admin Overview', null, $rightContent);
?>
            <?php if ($failed): ?><div class="alert error">Could not load users. Please refresh.</div><?php endif; ?>

            <?php if ($search !== ''): ?>
                <div class="search-alert">
                    Showing database search results for user query: <strong><?php echo e($search); ?></strong> (<a href="/admin/" style="font-weight: 600;">Reset search</a>)
                </div>
            <?php endif; ?>

            <div class="stats">
                <?php echo stat_card('Total users', count($profiles), $roleCounts['patient'] . ' patients', 'users'); ?>
                <?php echo stat_card('Counselors', $roleCounts['counselor'], $verifiedCount . ' verified', 'user'); ?>
                <?php echo stat_card('Appointments', count($appointments), $statusCounts['pending'] . ' pending', 'calendar'); ?>
                <?php echo stat_card('Awaiting verification', $pendingVerify, 'Counselors to review', 'shield'); ?>
            </div>

            <?php if ($pendingVerify > 0): ?>
                <div class="cta spaced">
                    <div><h3><?php echo (int) $pendingVerify; ?> counselor<?php echo $pendingVerify === 1 ? '' : 's'; ?> waiting for verification</h3><p>Patients cannot book a counselor until you verify them.</p></div>
                    <a href="/admin/counselors.php" class="btn btn-light">Review now</a>
                </div>
            <?php endif; ?>

            <div class="grid-2">
                <div class="card"><div class="card-head"><h2>Appointments per month</h2><a class="link" href="/admin/reports.php">Reports</a></div><?php echo svg_columns(array_column($months, 'label'), $apptByMonth, 'Appointments per month'); ?></div>
                <div class="card"><div class="card-head"><h2>New accounts per month</h2><a class="link" href="/admin/users.php">Users</a></div><?php echo svg_columns(array_column($months, 'label'), $usersByMonth, 'New accounts per month'); ?></div>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-head"><div><h2>Recent Users</h2><p class="card-sub">Newest accounts across all roles</p></div><a class="link" href="/admin/users.php">Manage</a></div>
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php if (!$profiles): ?>
                            <tr><td colspan="5">No users found matching your search.</td></tr>
                        <?php else: foreach (array_slice($profiles, 0, 6) as $p): $active = ($p['is_active'] ?? true) !== false; ?>
                            <tr>
                                <td><strong><?php echo e($p['full_name'] ?? '-'); ?></strong></td>
                                <td><?php echo e($p['email'] ?? '-'); ?></td>
                                <td><?php echo status_badge($p['role']); ?></td>
                                <td><span class="status <?php echo $active ? 'active' : 'deactivated'; ?>"><?php echo $active ? 'Active' : 'Deactivated'; ?></span></td>
                                <td class="nowrap"><?php echo e(fmt_dt($p['created_at'], 'M d, Y')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="stack">
                    <div class="card">
                        <div class="card-head"><h2>Appointments by status</h2></div>
                        <?php foreach ($statusCounts as $k => $v) { echo bar_row(ucfirst($k), $v, count($appointments)); } ?>
                    </div>
                    <div class="card">
                        <div class="card-head"><h2>Users by role</h2></div>
                        <?php foreach (['patient' => 'Patients', 'counselor' => 'Counselors', 'admin' => 'Administrators'] as $k => $label) { echo bar_row($label, $roleCounts[$k], count($profiles)); } ?>
                    </div>
                    <div class="card">
                        <div class="card-head"><h2>Recent activity</h2><a class="link" href="/admin/audit.php">All logs</a></div>
                        <?php if (!$logs): ?>
                            <p class="empty">No activity recorded yet.</p>
                        <?php else: foreach ($logs as $l): ?>
                            <div class="result-item">
                                <strong><?php echo e(str_replace('_', ' ', $l['action'])); ?></strong>
                                <small><?php echo e($l['actor_name'] ?: 'Unknown'); ?> · <?php echo e(fmt_dt($l['created_at'], 'M d, g:i A')); ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
<?php page_end(); ?>

```