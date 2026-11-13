<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

$status = $_GET['status'] ?? '';
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$statuses = ['pending', 'confirmed', 'completed', 'declined', 'cancelled'];

// Only the appointments that belong to this counselor.
$query = 'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.desc';
if (is_string($status) && in_array($status, $statuses, true)) { $query .= '&status=eq.' . $status; }
if ($from !== '') { $query .= '&scheduled_at=gte.' . rawurlencode($from . 'T00:00:00+08:00'); }
if ($to !== '') { $query .= '&scheduled_at=lte.' . rawurlencode($to . 'T23:59:59+08:00'); }
$query .= '&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage);

$res = sb_request('GET', 'appointments', $query, null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$rows = $failed ? [] : $res['data'];
$hasNext = count($rows) > $perPage;
$rows = array_slice($rows, 0, $perPage);

page_start('counselor', 'All Appointments', '/counselor/appointments.php', 'All Appointments', 'Overview of every session booked with you.');
?>
            <div class="card">
                <div class="card-head">
                    <div><h2>Appointments</h2><p class="card-sub">Filter by status or date</p></div>
                </div>
                <?php if ($failed): ?><div class="alert error">Could not load appointments. Please refresh.</div><?php endif; ?>

                <form method="GET" action="/counselor/appointments.php" class="filter-bar">
                    <select name="status" aria-label="Status">
                        <option value="">Any status</option>
                        <?php foreach ($statuses as $s): ?><option value="<?php echo e($s); ?>"<?php echo $status === $s ? ' selected' : ''; ?>><?php echo e(ucfirst($s)); ?></option><?php endforeach; ?>
                    </select>
                    <label class="inline-label">From <input type="date" name="from" value="<?php echo e($from); ?>"></label>
                    <label class="inline-label">To <input type="date" name="to" value="<?php echo e($to); ?>"></label>
                    <button type="submit" class="btn">Filter</button>
                </form>

                <table class="data-table">
                    <thead><tr><th>Date / Time</th><th>Patient</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="5">No appointments match your filters.</td></tr>
                    <?php else: foreach ($rows as $a): $ts = strtotime($a['scheduled_at']); ?>
                        <tr>
                            <td><div class="when"><span class="date-chip"><small><?php echo e(strtoupper(date('M', $ts))); ?></small><strong><?php echo e(date('d', $ts)); ?></strong></span><span><?php echo e(date('g:i A', $ts)); ?></span></div></td>
                            <td><strong><?php echo e($a['patient']['full_name'] ?? 'Unknown'); ?></strong><br><small><?php echo e(ucfirst($a['patient']['patient_type'] ?? '-')); ?></small></td>
                            <td><?php echo e($a['reason'] ?? '-'); ?></td>
                            <td><?php echo status_badge($a['status']); ?></td>
                            <td><a class="btn-sm" href="/counselor/?view=<?php echo e($a['id']); ?>#details">Manage</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php echo pager($page, $hasNext); ?>
            </div>
<?php page_end(); ?>