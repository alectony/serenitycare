<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$myId = $me['user']['id'];
$msg = '';
$err = '';

$accountTypes = ['student' => 'Student', 'teacher' => 'Teacher', 'counselor' => 'Counselor', 'admin' => 'Administrator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';

    } elseif ($action === 'create') {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $type = $_POST['account_type'] ?? '';
        $dept = trim($_POST['grade_or_dept'] ?? '');
        $privileged = in_array($type, ['counselor', 'admin'], true);

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a name and a valid email address.';
        } elseif (strlen($password) < 8) {
            $err = 'Password must be at least 8 characters.';
        } elseif (!isset($accountTypes[$type])) {
            $err = 'Please choose an account type.';
        } else {
            $meta = $privileged ? [] : ['patient_type' => $type, 'grade_or_dept' => $dept];
            $u = sb_service_create_user($email, $password, $name, $meta);
            if (isset($u['error'])) {
                $err = $u['error'];
            } else {
                $profile = ['email' => $email, 'full_name' => $name, 'role' => $privileged ? $type : 'patient', 'is_active' => true];
                if (!$privileged) {
                    $profile['patient_type'] = $type;
                    $profile['grade_or_dept'] = $dept !== '' ? mb_substr($dept, 0, 100) : null;
                }
                if (sb_service_upsert_profile($u['id'], $profile)) {
                    audit_from($me, 'user_created', 'user', $u['id'], $email . ' as ' . $profile['role']);
                    $msg = 'Account created for ' . $email . '. They can sign in right away.';
                } else {
                    $err = 'The login was created but the profile could not be saved. Check the user list.';
                }
            }
        }

    } elseif ($action === 'update') {
        $id = $_POST['id'] ?? '';
        $name = trim($_POST['full_name'] ?? '');
        $ptype = $_POST['patient_type'] ?? '';
        $dept = trim($_POST['grade_or_dept'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!is_uuid($id) || $name === '') {
            $err = 'Please enter a name.';
        } elseif (!in_array($ptype, ['', 'student', 'teacher'], true)) {
            $err = 'Invalid patient type.';
        } else {
            $r = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($id), [
                'full_name' => mb_substr($name, 0, 120),
                'patient_type' => $ptype !== '' ? $ptype : null,
                'grade_or_dept' => $dept !== '' ? mb_substr($dept, 0, 100) : null,
                'phone' => $phone !== '' ? mb_substr($phone, 0, 30) : null,
            ], $token);
            if ($r['status'] === 200 && !empty($r['data'])) {
                audit_from($me, 'user_updated', 'user', $id, $name);
                $msg = 'User updated.';
            } else {
                $err = 'Could not update that user.';
            }
        }

    } elseif ($action === 'toggle') {
        $id = $_POST['id'] ?? '';
        $activate = ($_POST['to'] ?? '') === '1';

        if (!is_uuid($id)) {
            $err = 'Invalid user.';
        } elseif ($id === $myId && !$activate) {
            $err = 'You cannot deactivate your own account.';
        } else {
            $r = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($id), ['is_active' => $activate], $token);
            if ($r['status'] === 200 && !empty($r['data'])) {
                sb_service_set_ban($id, !$activate);   // also block new sign-ins at the auth level (best effort)
                audit_from($me, $activate ? 'user_reactivated' : 'user_deactivated', 'user', $id, $r['data'][0]['email'] ?? '');
                $msg = $activate ? 'Account reactivated.' : 'Account deactivated. They can no longer sign in.';
            } else {
                $err = 'Could not change that account.';
            }
        }
    }
}

// ---- list with filters + paging ----
$q = pg_term($_GET['q'] ?? '');
$role = $_GET['role'] ?? '';
$state = $_GET['state'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$query = 'select=id,full_name,email,role,patient_type,grade_or_dept,phone,is_active,created_at&order=created_at.desc';
if (in_array($role, ['patient', 'counselor', 'admin'], true)) { $query .= '&role=eq.' . $role; }
if ($state === 'active') { $query .= '&is_active=eq.true'; }
if ($state === 'inactive') { $query .= '&is_active=eq.false'; }
if ($q !== '') { $query .= '&or=' . rawurlencode('(full_name.ilike.*' . $q . '*,email.ilike.*' . $q . '*)'); }
$query .= '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage);

$res = sb_request('GET', 'profiles', $query, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$users = $failed ? [] : $res['data'];
$hasNext = count($users) > $perPage;
$users = array_slice($users, 0, $perPage);

$edit = null;
$editId = $_GET['edit'] ?? '';
if (is_uuid($editId)) {
    $er = sb_request('GET', 'profiles', 'id=eq.' . urlencode($editId), null, $token);
    $edit = ($er['status'] === 200 && !empty($er['data'][0])) ? $er['data'][0] : null;
}

page_start('admin', 'User Management', '/admin/users.php', 'User Management', 'Add, edit and deactivate accounts.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <?php if ($edit): ?>
                <div class="card details-panel" id="edit">
                    <div class="card-head"><div><h2>Edit user</h2><p class="card-sub"><?php echo e($edit['email'] ?? ''); ?> · <?php echo e(ucfirst($edit['role'])); ?></p></div><a class="link" href="/admin/users.php?<?php echo e(qs(['edit' => ''])); ?>">Close</a></div>
                    <form method="POST" action="/admin/users.php?edit=<?php echo e($edit['id']); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo e($edit['id']); ?>">
                        <div class="form-row">
                            <div class="field grow"><label for="e_name">Full name</label><input type="text" id="e_name" name="full_name" value="<?php echo e($edit['full_name'] ?? ''); ?>" required></div>
                            <div class="field"><label for="e_type">Patient type</label>
                                <select id="e_type" name="patient_type">
                                    <option value="">&mdash;</option>
                                    <option value="student"<?php echo ($edit['patient_type'] ?? '') === 'student' ? ' selected' : ''; ?>>Student</option>
                                    <option value="teacher"<?php echo ($edit['patient_type'] ?? '') === 'teacher' ? ' selected' : ''; ?>>Teacher</option>
                                </select>
                            </div>
                            <div class="field grow"><label for="e_dept">Grade / Department</label><input type="text" id="e_dept" name="grade_or_dept" maxlength="100" value="<?php echo e($edit['grade_or_dept'] ?? ''); ?>"></div>
                            <div class="field grow"><label for="e_phone">Phone</label><input type="text" id="e_phone" name="phone" maxlength="30" value="<?php echo e($edit['phone'] ?? ''); ?>"></div>
                            <button type="submit" class="btn">Save changes</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head"><div><h2>Add a user</h2><p class="card-sub">Creates a confirmed account they can sign in with immediately</p></div></div>
                <form method="POST" action="/admin/users.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="form-row">
                        <div class="field grow"><label for="c_name">Full name</label><input type="text" id="c_name" name="full_name" required></div>
                        <div class="field grow"><label for="c_email">Email</label><input type="email" id="c_email" name="email" required></div>
                        <div class="field grow"><label for="c_pass">Temporary password</label><input type="password" id="c_pass" name="password" minlength="8" required autocomplete="new-password"></div>
                        <div class="field"><label for="c_type">Account type</label>
                            <select id="c_type" name="account_type" required>
                                <?php foreach ($accountTypes as $k => $label): ?><option value="<?php echo e($k); ?>"><?php echo e($label); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field grow"><label for="c_dept">Grade / Department</label><input type="text" id="c_dept" name="grade_or_dept" maxlength="100"></div>
                        <button type="submit" class="btn">Add user</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><div><h2>All users</h2><p class="card-sub">Search and filter every account</p></div></div>
                <?php if ($failed): ?><div class="alert error">Could not load users. Please refresh.</div><?php endif; ?>

                <form method="GET" action="/admin/users.php" class="filter-bar">
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search name or email...">
                    <select name="role">
                        <option value="">All roles</option>
                        <?php foreach (['patient' => 'Patients', 'counselor' => 'Counselors', 'admin' => 'Admins'] as $k => $label): ?>
                            <option value="<?php echo e($k); ?>"<?php echo $role === $k ? ' selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="state">
                        <option value="">Any status</option>
                        <option value="active"<?php echo $state === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?php echo $state === 'inactive' ? ' selected' : ''; ?>>Deactivated</option>
                    </select>
                    <button type="submit" class="btn">Filter</button>
                </form>

                <table class="data-table">
                    <thead><tr><th>User</th><th>Role</th><th>Type / Dept</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="6">No users match your filters.</td></tr>
                    <?php else: foreach ($users as $u): $active = ($u['is_active'] ?? true) !== false; ?>
                        <tr>
                            <td><strong><?php echo e($u['full_name'] ?? '-'); ?></strong><br><small><?php echo e($u['email'] ?? ''); ?></small></td>
                            <td><?php echo status_badge($u['role']); ?></td>
                            <td><?php echo e(ucfirst($u['patient_type'] ?? '-')); ?><br><small><?php echo e($u['grade_or_dept'] ?? ''); ?></small></td>
                            <td><span class="status <?php echo $active ? 'active' : 'deactivated'; ?>"><?php echo $active ? 'Active' : 'Deactivated'; ?></span></td>
                            <td class="nowrap"><?php echo e(fmt_dt($u['created_at'], 'M d, Y')); ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn-sm" href="/admin/users.php?<?php echo e(qs(['edit' => $u['id']])); ?>#edit">Edit</a>
                                    <?php if ($u['id'] !== $myId): ?>
                                        <form method="POST" action="/admin/users.php?<?php echo e(qs()); ?>" class="inline-form">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                                            <input type="hidden" name="to" value="<?php echo $active ? '0' : '1'; ?>">
                                            <button type="submit" class="<?php echo $active ? 'btn-outline-danger-sm' : 'btn-success-sm'; ?>"><?php echo $active ? 'Deactivate' : 'Reactivate'; ?></button>
                                        </form>
                                    <?php else: ?>
                                        <small>(you)</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php echo pager($page, $hasNext); ?>
            </div>
<?php page_end();   ?>