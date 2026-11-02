<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

$q = pg_term($_GET['q'] ?? '');
$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? ''));
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$query = 'select=id,created_at,actor_name,action,target_type,target_id,detail&order=created_at.desc';
if ($action !== '') { $query .= '&action=eq.' . $action; }
if ($q !== '') { $query .= '&or=' . rawurlencode('(actor_name.ilike.*' . $q . '*,detail.ilike.*' . $q . '*)'); }
if ($from !== '') { $query .= '&created_at=gte.' . rawurlencode($from . 'T00:00:00+08:00'); }
if ($to !== '') { $query .= '&created_at=lte.' . rawurlencode($to . 'T23:59:59+08:00'); }
$query .= '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage);

$res = sb_request('GET', 'audit_logs', $query, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$logs = $failed ? [] : $res['data'];
$hasNext = count($logs) > $perPage;
$logs = array_slice($logs, 0, $perPage);

$known = ['login', 'user_created', 'user_updated', 'user_deactivated', 'user_reactivated', 'role_changed', 'counselor_verified', 'counselor_rejected', 'counselor_pending',
          'settings_updated', 'export', 'appointment_booked', 'appointment_cancelled', 'appointment_confirmed', 'appointment_declined', 'record_saved'];

page_start('admin', 'Audit Logs', '/admin/audit.php', 'Audit Logs', 'A tamper-proof trail of who did what. Entries cannot be edited or deleted.');
?>
            <div class="card">
                <div class="card-head">
                    <div><h2>Activity</h2><p class="card-sub">Newest first</p></div>
                    <a class="btn-sm" href="/admin/export.php?<?php echo e(qs(['type' => 'audit', 'page' => '', 'q' => '', 'action' => ''])); ?>">Export CSV</a>
                </div>
                <?php if ($failed): ?><div class="alert error">Could not load the audit log. Have you run the admin SQL upgrade?</div><?php endif; ?>

                <form method="GET" action="/admin/audit.php" class="filter-bar">
                    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search person or detail...">
                    <select name="action" aria-label="Action">
                        <option value="">All actions</option>
                        <?php foreach ($known as $k): ?><option value="<?php echo e($k); ?>"<?php echo $action === $k ? ' selected' : ''; ?>><?php echo e(str_replace('_', ' ', $k)); ?></option><?php endforeach; ?>
                    </select>
                    <label class="inline-label">From <input type="date" name="from" value="<?php echo e($from); ?>"></label>
                    <label class="inline-label">To <input type="date" name="to" value="<?php echo e($to); ?>"></label>
                    <button type="submit" class="btn">Filter</button>
                </form>

                <table class="data-table">
                    <thead><tr><th>Time</th><th>Who</th><th>Action</th><th>Target</th><th>Detail</th></tr></thead>
                    <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="5">No activity found.</td></tr>
                    <?php else: foreach ($logs as $l): ?>
                        <tr>
                            <td class="nowrap"><?php echo e(fmt_dt($l['created_at'], 'M d, Y')); ?><br><small><?php echo e(fmt_dt($l['created_at'], 'g:i:s A')); ?></small></td>
                            <td><strong><?php echo e($l['actor_name'] ?: 'Unknown'); ?></strong></td>
                            <td><span class="status"><?php echo e(str_replace('_', ' ', $l['action'])); ?></span></td>
                            <td><?php echo e($l['target_type'] ?? '-'); ?></td>
                            <td><?php echo e($l['detail'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php echo pager($page, $hasNext); ?>
            </div>
<?php page_end(); ?>