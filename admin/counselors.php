<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['counselor_id'] ?? '';
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['note'] ?? '');
    $map = ['verify' => 'verified', 'reject' => 'rejected', 'reset' => 'pending'];

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (!is_uuid($id) || !isset($map[$action])) {
        $err = 'Invalid request.';
    } else {
        $newStatus = $map[$action];
        $ok = true;

        if ($newStatus === 'verified') {   // a counselor must have submitted credentials first
            $d = sb_request('GET', 'counselor_details', 'select=license_number&counselor_id=eq.' . urlencode($id), null, $token);
            $ok = ($d['status'] === 200 && !empty($d['data'][0]['license_number']));
            if (!$ok) { $err = 'This counselor has not submitted a license number yet, so they cannot be verified.'; }
        }
        if ($ok) {
            $r = sb_request('POST', 'counselor_verifications', 'on_conflict=counselor_id', [
                'counselor_id' => $id, 'status' => $newStatus,
                'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
                'reviewed_by' => $me['user']['id'], 'reviewed_at' => date('c'),
            ], $token, 'resolution=merge-duplicates,return=representation');
            if ($r['status'] === 200 || $r['status'] === 201) {
                audit_from($me, 'counselor_' . $newStatus, 'user', $id, $note);
                $msg = 'Counselor marked as ' . $newStatus . '.';
            } else {
                $err = 'Could not save the decision.';
            }
        }
    }
}

$cr = sb_request('GET', 'profiles', 'select=id,full_name,email,is_active&role=eq.counselor&order=full_name.asc', null, $token);
$failed = ($cr['status'] !== 200 || !is_array($cr['data']));
$counselors = $failed ? [] : $cr['data'];

$details = [];
$dr = sb_request('GET', 'counselor_details', 'select=counselor_id,specialization,license_number', null, $token);
if ($dr['status'] === 200 && is_array($dr['data'])) { foreach ($dr['data'] as $d) { $details[$d['counselor_id']] = $d; } }

$verif = [];
$vr = sb_request('GET', 'counselor_verifications', 'select=counselor_id,status,note,reviewed_at', null, $token);
if ($vr['status'] === 200 && is_array($vr['data'])) { foreach ($vr['data'] as $v) { $verif[$v['counselor_id']] = $v; } }

$rows = [];
foreach ($counselors as $c) {
    $c['status'] = $verif[$c['id']]['status'] ?? 'pending';
    $c['note'] = $verif[$c['id']]['note'] ?? '';
    $c['reviewed_at'] = $verif[$c['id']]['reviewed_at'] ?? '';
    $c['details'] = $details[$c['id']] ?? null;
    
    $rows[] = $c;
}
$counts = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
foreach ($rows as $r) { $counts[$r['status']]++; }

$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['all', 'pending', 'verified', 'rejected'], true)) { $filter = 'pending'; }
$shown = $filter === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['status'] === $filter));

page_start('admin', 'Counselor Verification', '/admin/counselors.php', 'Counselor Verification', 'Only verified counselors can be booked by patients.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>
                

            <div class="stats">
                <?php echo stat_card('Pending review', $counts['pending'], 'Awaiting your decision', 'clock'); ?>
                <?php echo stat_card('Verified', $counts['verified'], 'Can receive bookings', 'shield'); ?>
                <?php echo stat_card('Rejected', $counts['rejected'], 'Cannot be booked', 'user'); ?>
            </div>

            <div class="card">
                <div class="card-head">
                    <div><h2>Counselors</h2><p class="card-sub">Check the license, then verify or reject</p></div>
                    <div class="tabs">
                        <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
                            <a class="tab<?php echo $filter === $k ? ' active' : ''; ?>" href="/admin/counselors.php?status=<?php echo e($k); ?>"><?php echo e($label); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($failed): ?><div class="alert error">Could not load counselors. Please refresh.</div><?php endif; ?>

                <table class="data-table">
                    <thead><tr><th>Counselor</th><th>Specialization</th><th>License no.</th><th>Status</th><th>Decision</th></tr></thead>
                    <tbody>
                    <?php if (!$shown): ?>
                        <tr><td colspan="5">No counselors in this list.</td></tr>
                    <?php else: foreach ($shown as $c): ?>
                        <tr>
                            <td><strong><?php echo e($c['full_name'] ?? '-'); ?></strong><br><small><?php echo e($c['email'] ?? ''); ?></small></td>
                            <td><?php echo e($c['details']['specialization'] ?? '—'); ?></td>
                            <td><?php echo $c['details'] && $c['details']['license_number'] ? e($c['details']['license_number']) : '<small>Not submitted</small>'; ?></td>
                            <td><?php echo status_badge($c['status']); ?><?php if ($c['note']): ?><br><small><?php echo e($c['note']); ?></small><?php endif; ?></td>
                            <td>
                                <form method="POST" action="/admin/counselors.php?status=<?php echo e($filter); ?>" class="decision-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="counselor_id" value="<?php echo e($c['id']); ?>">
                                    <input type="text" name="note" maxlength="300" placeholder="Optional note" aria-label="Note">
                                    <div class="row-actions">
                                        <?php if ($c['status'] !== 'verified'): ?><button type="submit" name="action" value="verify" class="btn-success-sm">Verify</button><?php endif; ?>
                                        <?php if ($c['status'] !== 'rejected'): ?><button type="submit" name="action" value="reject" class="btn-outline-danger-sm">Reject</button><?php endif; ?>
                                        <?php if ($c['status'] !== 'pending'): ?><button type="submit" name="action" value="reset" class="btn-sm">Reset</button><?php endif; ?>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
<?php page_end(); ?>