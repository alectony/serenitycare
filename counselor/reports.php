<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

/* ---- Period (default: last 6 months up to today) ---- */
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01', strtotime('first day of -5 months'));
$to   = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$fromTs = rawurlencode($from . 'T00:00:00+08:00');
$toTs   = rawurlencode($to . 'T23:59:59+08:00');

/* ---- Data: only this counselor's own appointments and records ---- */
$res = sb_request('GET', 'appointments',
    'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid)
    . '&scheduled_at=gte.' . $fromTs . '&scheduled_at=lte.' . $toTs
    . '&order=scheduled_at.desc&limit=1000', null, $token);
$failed = ($res['status'] !== 200 || !is_array($res['data']));
$appts = $failed ? [] : $res['data'];

$dx = sb_request('GET', 'diagnoses',
    'select=created_at&counselor_id=eq.' . urlencode($uid)
    . '&created_at=gte.' . $fromTs . '&created_at=lte.' . $toTs
    . '&order=created_at.desc&limit=1000', null, $token);
$records = ($dx['status'] === 200 && is_array($dx['data'])) ? $dx['data'] : [];

/* ---- CSV export (runs before any HTML is printed) ---- */
function csv_safe($v) {
    $v = (string) $v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}
if (($_GET['export'] ?? '') === 'csv' && !$failed) {
    audit_from($me, 'report_export', 'appointments', '', $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="my-appointments_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8
    fputcsv($out, ['Date', 'Time', 'Patient', 'Patient type', 'Reason', 'Status'], ',', '"', '\\');
    foreach ($appts as $a) {
        $ts = strtotime($a['scheduled_at']);
        fputcsv($out, array_map('csv_safe', [
            date('Y-m-d', $ts),
            date('g:i A', $ts),
            $a['patient']['full_name'] ?? 'Unknown',
            ucfirst($a['patient']['patient_type'] ?? '-'),
            $a['reason'] ?? '',
            ucfirst($a['status']),
        ]), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/* ---- Numbers ---- */
$statuses = ['pending', 'confirmed', 'completed', 'declined', 'cancelled'];
$byStatus = array_fill_keys($statuses, 0);
foreach ($appts as $a) {
    if (isset($byStatus[$a['status']])) { $byStatus[$a['status']]++; }
}
$total     = count($appts);
$completed = $byStatus['completed'];
$lost      = $byStatus['declined'] + $byStatus['cancelled'];
$decided   = $completed + $lost;
$completionRate = $decided > 0 ? round(100 * $completed / $decided) : 0;
$lostRate       = $total > 0 ? round(100 * $lost / $total) : 0;
$patientCount   = count(array_unique(array_column($appts, 'patient_id')));

/* ---- Month buckets for the chosen period (max 12 months) ---- */
$buckets = [];
$cur = strtotime(date('Y-m-01', strtotime($from)));
$end = strtotime(date('Y-m-01', strtotime($to)));
while ($cur <= $end) {
    $buckets[] = ['key' => date('Y-m', $cur), 'label' => date('M', $cur)];
    $cur = strtotime('+1 month', $cur);
}
$buckets = array_slice($buckets, -12);
$labels = array_column($buckets, 'label');
$apptByMonth = count_by_month($appts, 'scheduled_at', $buckets);
$recByMonth  = count_by_month($records, 'created_at', $buckets);

/* ---- Workload per patient (top 10) ---- */
$byPatient = [];
foreach ($appts as $a) {
    $pid = $a['patient_id'];
    if (!isset($byPatient[$pid])) {
        $byPatient[$pid] = [
            'name' => $a['patient']['full_name'] ?? 'Unknown',
            'type' => ucfirst($a['patient']['patient_type'] ?? '-'),
            'total' => 0, 'completed' => 0, 'lost' => 0,
        ];
    }
    $byPatient[$pid]['total']++;
    if ($a['status'] === 'completed') { $byPatient[$pid]['completed']++; }
    if (in_array($a['status'], ['declined', 'cancelled'], true)) { $byPatient[$pid]['lost']++; }
}
uasort($byPatient, fn($x, $y) => $y['total'] <=> $x['total']);
$byPatient = array_slice($byPatient, 0, 10, true);

page_start('counselor', 'Reports & Export', '/counselor/reports.php', 'Reports & Export', 'Your session statistics for a chosen period, ready to download.');
?>
            <?php if ($failed): ?><div class="alert error">Could not load your report data. Please refresh.</div><?php endif; ?>

            <div class="card">
                <form method="GET" action="/counselor/reports.php" class="filter-bar">
                    <label class="inline-label">From <input type="date" name="from" value="<?php echo e($from); ?>"></label>
                    <label class="inline-label">To <input type="date" name="to" value="<?php echo e($to); ?>"></label>
                    <button type="submit" class="btn">Update report</button>
                    <a class="btn-sm" href="/counselor/reports.php?<?php echo e(qs(['from' => $from, 'to' => $to, 'export' => 'csv'])); ?>">Export CSV</a>
                </form>
            </div>

            <div class="stats">
                <?php echo stat_card('Appointments', $total, 'In this period', 'calendar'); ?>
                <?php echo stat_card('Completion rate', $completionRate . '%', 'Completed of decided sessions', 'check'); ?>
                <?php echo stat_card('Declined / Cancelled', $lostRate . '%', 'Of all requests', 'bell'); ?>
                <?php echo stat_card('Patients', $patientCount, 'Seen in this period', 'users'); ?>
            </div>

            <div class="grid-2">
                <div class="card">
                    <div class="card-head"><h2>Appointments per month</h2></div>
                    <?php echo svg_columns($labels, $apptByMonth, 'Appointments per month'); ?>
                </div>
                <div class="card">
                    <div class="card-head"><h2>Records per month</h2></div>
                    <?php echo svg_columns($labels, $recByMonth, 'Records per month'); ?>
                </div>
            </div>

            <div class="content-grid">
                <div class="card">
                    <div class="card-head"><div><h2>By patient</h2><p class="card-sub">Your busiest patients in the period (top 10)</p></div></div>
                    <table class="data-table">
                        <thead><tr><th>Patient</th><th>Type</th><th>Sessions</th><th>Completed</th><th>Declined / Cancelled</th></tr></thead>
                        <tbody>
                        <?php if (!$byPatient): ?>
                            <tr><td colspan="5">No appointments in this period.</td></tr>
                        <?php else: foreach ($byPatient as $p): ?>
                            <tr>
                                <td><strong><?php echo e($p['name']); ?></strong></td>
                                <td><?php echo e($p['type']); ?></td>
                                <td><?php echo (int) $p['total']; ?></td>
                                <td><?php echo (int) $p['completed']; ?></td>
                                <td><?php echo (int) $p['lost']; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <div class="card-head"><h2>By status</h2></div>
                    <?php foreach ($byStatus as $label => $count) { echo bar_row(ucfirst($label), $count, $total); } ?>
                </div>
            </div>
<?php page_end(); ?>