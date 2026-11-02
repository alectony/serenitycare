<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];

$defaultFrom = date('Y-m-01', strtotime('-5 months'));
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : $defaultFrom;
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }

$range = '&scheduled_at=gte.' . rawurlencode($from . 'T00:00:00+08:00') . '&scheduled_at=lte.' . rawurlencode($to . 'T23:59:59+08:00');
$ar = sb_request('GET', 'appointments',
    'select=id,status,scheduled_at,counselor_id,counselor:profiles!counselor_id(full_name)&order=scheduled_at.asc&limit=5000' . $range, null, $token);
$failed = ($ar['status'] !== 200 || !is_array($ar['data']));
$appts = $failed ? [] : $ar['data'];

$ur = sb_request('GET', 'profiles',
    'select=id,role,created_at&order=created_at.asc&limit=5000&created_at=gte.' . rawurlencode($from . 'T00:00:00+08:00')
    . '&created_at=lte.' . rawurlencode($to . 'T23:59:59+08:00'), null, $token);
$newUsers = ($ur['status'] === 200 && is_array($ur['data'])) ? $ur['data'] : [];

// ---- numbers ----
$total = count($appts);
$byStatus = array_fill_keys(['pending', 'confirmed', 'completed', 'declined', 'cancelled'], 0);
$byCounselor = [];
foreach ($appts as $a) {
    if (isset($byStatus[$a['status']])) { $byStatus[$a['status']]++; }
    $cid = $a['counselor_id'] ?? '';
    $byCounselor[$cid] = $byCounselor[$cid] ?? ['name' => $a['counselor']['full_name'] ?? 'Unknown', 'total' => 0, 'completed' => 0, 'confirmed' => 0, 'pending' => 0, 'closed' => 0];
    $byCounselor[$cid]['total']++;
    if ($a['status'] === 'completed') { $byCounselor[$cid]['completed']++; }
    elseif ($a['status'] === 'confirmed') { $byCounselor[$cid]['confirmed']++; }
    elseif ($a['status'] === 'pending') { $byCounselor[$cid]['pending']++; }
    else { $byCounselor[$cid]['closed']++; }
}
uasort($byCounselor, fn($x, $y) => $y['total'] <=> $x['total']);

$decided = $total - $byStatus['pending'];
$completionRate = $decided > 0 ? round(100 * $byStatus['completed'] / $decided) : 0;
$dropRate = $total > 0 ? round(100 * ($byStatus['declined'] + $byStatus['cancelled']) / $total) : 0;

// monthly chart over the chosen range (max 12 months shown)
$months = [];
$cursor = strtotime(date('Y-m-01', strtotime($from)));
$endM = strtotime(date('Y-m-01', strtotime($to)));
while ($cursor <= $endM && count($months) < 12) { $months[] = ['key' => date('Y-m', $cursor), 'label' => date('M', $cursor)]; $cursor = strtotime('+1 month', $cursor); }
$apptByMonth = count_by_month($appts, 'scheduled_at', $months);
$usersByMonth = count_by_month($newUsers, 'created_at', $months);

$exportQs = fn($type) => e(qs(['type' => $type, 'from' => $from, 'to' => $to]));

page_start('admin', 'Reports & Export', '/admin/reports.php', 'Reports & Export', 'Usage statistics for a chosen period, ready to download.');
?>
            <?php if ($failed): ?><div class="alert error">Could not load report data. Please refresh.</div><?php endif; ?>

            <div class="card">
                <form method="GET" action="/admin/reports.php" class="filter-bar">
                    <label class="inline-label">From <input type="date" name="from" value="<?php echo e($from); ?>"></label>
                    <label class="inline-label">To <input type="date" name="to" value="<?php echo e($to); ?>"></label>
                    <button type="submit" class="btn">Update report</button>
                </form>
            </div>

            <div class="stats spaced">
                <?php echo stat_card('Appointments', $total, 'In this period', 'calendar'); ?>
                <?php echo stat_card('Completion rate', $completionRate . '%', 'Completed of decided sessions', 'check'); ?>
                <?php echo stat_card('Declined / cancelled', $dropRate . '%', 'Of all requests', 'bell'); ?>
                <?php echo stat_card('New accounts', count($newUsers), 'Registered in this period', 'users'); ?>
            </div>

            <div class="grid-2">
                <div class="card"><div class="card-head"><h2>Appointments per month</h2></div><?php echo svg_columns(array_column($months, 'label'), $apptByMonth, 'Appointments per month'); ?></div>
                <div class="card"><div class="card-head"><h2>New accounts per month</h2></div><?php echo svg_columns(array_column($months, 'label'), $usersByMonth, 'New accounts per month'); ?></div>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-head"><div><h2>By counselor</h2><p class="card-sub">Workload and outcomes in the period</p></div></div>
                    <table class="data-table">
                        <thead><tr><th>Counselor</th><th>Total</th><th>Completed</th><th>Confirmed</th><th>Pending</th><th>Declined / cancelled</th></tr></thead>
                        <tbody>
                        <?php if (!$byCounselor): ?>
                            <tr><td colspan="6">No appointments in this period.</td></tr>
                        <?php else: foreach ($byCounselor as $c): ?>
                            <tr><td><strong><?php echo e($c['name']); ?></strong></td><td><?php echo (int) $c['total']; ?></td><td><?php echo (int) $c['completed']; ?></td><td><?php echo (int) $c['confirmed']; ?></td><td><?php echo (int) $c['pending']; ?></td><td><?php echo (int) $c['closed']; ?></td></tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="stack">
                    <div class="card">
                        <div class="card-head"><h2>By status</h2></div>
                        <?php foreach ($byStatus as $k => $v): echo bar_row(ucfirst($k), $v, $total); endforeach; ?>
                    </div>
                    <div class="card">
                        <div class="card-head"><div><h2>Download CSV</h2><p class="card-sub">Uses the dates above</p></div></div>
                        <div class="download-list">
                            <a class="btn-sm" href="/admin/export.php?<?php echo $exportQs('appointments'); ?>">Appointments</a>
                            <a class="btn-sm" href="/admin/export.php?<?php echo $exportQs('users'); ?>">Users</a>
                            <a class="btn-sm" href="/admin/export.php?<?php echo $exportQs('audit'); ?>">Audit log</a>
                        </div>
                        <div class="note-box"><strong>Privacy</strong><small>Exports contain no session notes or diagnoses.</small></div>
                    </div>
                </div>
            </div>
<?php page_end(); ?>