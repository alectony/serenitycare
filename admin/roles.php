<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$myId = $me['user']['id'];
$msg = '';
$err = '';
$roles = ['patient' => 'Patient', 'counselor' => 'Counselor', 'admin' => 'Administrator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $newRole = $_POST['role'] ?? '';

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (!is_uuid($id) || !isset($roles[$newRole])) {
        $err = 'Invalid request.';
    } elseif ($id === $myId) {
        $err = 'You cannot change your own role. Ask another administrator.';
    } else {
        $cur = sb_request('GET', 'profiles', 'select=id,role,email,full_name&id=eq.' . urlencode($id), null, $token);
        $target = ($cur['status'] === 200 && !empty($cur['data'][0])) ? $cur['data'][0] : null;

        if (!$target) {
            $err = 'User not found.';
        } elseif ($target['role'] === $newRole) {
            $msg = 'No change needed.';
        } else {
            $r = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($id), ['role' => $newRole], $token);
            if ($r['status'] === 200 && !empty($r['data'])) {
                if ($newRole === 'counselor') {
                    // New counselors start as "pending"; an existing review is never overwritten.
                    sb_request('POST', 'counselor_verifications', 'on_conflict=counselor_id',
                        ['counselor_id' => $id, 'status' => 'pending'], $token, 'resolution=ignore-duplicates,return=minimal');
                }
                audit_from($me, 'role_changed', 'user', $id, ($target['email'] ?? '') . ': ' . $target['role'] . ' → ' . $newRole);
                $msg = ($target['full_name'] ?: $target['email']) . ' is now a ' . strtolower($roles[$newRole]) . '.';
            } else {
                $err = 'Could not change that role.';
            }
        }
    }
}

$q = pg_term($_GET['q'] ?? '');
$filter = $_GET['role'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$query = 'select=id,full_name,email,role,is_active&order=full_name.asc';
if (isset($roles[$filter])) { $query .= '&role=eq.' . $filter; }
if ($q !== '') { $query .= '&or=' . rawurlencode('(full_name.ilike.*' . $q . '*,email.ilike.*' . $q . '*)'); }
$query .= '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage);

$res = sb_request('GET', 'profiles', $query, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$users = $failed ? [] : $res['data'];
$hasNext = count($users) > $perPage;
$users = array_slice($users, 0, $perPage);

page_start('admin', 'Role Assignment', '/admin/roles.php', 'Role Assignment', 'Decide who is a patient, counselor or administrator.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-head"><div><h2>Change a role</h2><p class="card-sub">New counselors start as pending until you verify them. Your own role is locked.</p></div></div>
                <?php if ($failed): ?><div class="alert error">Could not load users. Please refresh.</div><?php endif; ?>

                <form method="GET" action="/admin/roles.php" class="filter-bar">
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search name or email...">
                    <select name="role">
                        <option value="">All roles</option>
                        <?php foreach ($roles as $k => $label): ?><option value="<?php echo e($k); ?>"<?php echo $filter === $k ? ' selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Filter</button>
                </form>

                <table class="data-table">
                    <thead><tr><th>User</th><th>Current role</th><th>Assign new role</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="3">No users found.</td></tr>
                    <?php else: foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?php echo e($u['full_name'] ?? '-'); ?></strong><br><small><?php echo e($u['email'] ?? ''); ?></small></td>
                            <td><?php echo status_badge($u['role']); ?></td>
                            <td>
                                <?php if ($u['id'] === $myId): ?>
                                    <small>This is you</small>
                                <?php else: ?>
                                    <form method="POST" action="/admin/roles.php?<?php echo e(qs()); ?>" class="role-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                                        <select name="role" aria-label="New role for <?php echo e($u['full_name'] ?? ''); ?>">
                                            <?php foreach ($roles as $k => $label): ?><option value="<?php echo e($k); ?>"<?php echo $u['role'] === $k ? ' selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-sm">Update</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php echo pager($page, $hasNext); ?>
            </div>
<?php page_end(); ?>