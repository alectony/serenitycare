<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['appointment_id'] ?? '';
    $action = $_POST['action'] ?? '';

    if (csrf_valid() && is_uuid($id) && in_array($action, ['approve', 'decline'], true)) {
        $status = $action === 'approve' ? 'confirmed' : 'declined';
        sb_request('PATCH', 'appointments',
            'id=eq.' . urlencode($id) . '&counselor_id=eq.' . urlencode($uid) . '&status=eq.pending',
            ['status' => $status], $me['token']);
    }
    header('Location: /counselor/requests.php');
    exit;
}

$res = sb_request('GET', 'appointments',
    'select=id,scheduled_at,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&status=eq.pending&order=created_at.asc', null, $me['token']);

$failed = ($res['status'] !== 200 || !is_array($res['data']));
$requests = $failed ? [] : $res['data'];

page_start('counselor', 'Appointment Requests', '/counselor/requests.php', 'Pending Appointment Requests');
?>
            <div class="card">
                <?php if ($failed): ?><div class="alert error">Could not load requests. Please try again.</div><?php endif; ?>
                <?php if (!$requests && !$failed): ?>
                    <p>No pending appointment requests at this time.</p>
                <?php else: foreach ($requests as $req): ?>
                    <div class="request-item" style="display:flex; justify-content:space-between; padding:15px; border-bottom:1px solid #eee;">
                        <div>
                            <strong><?php echo e($req['patient']['full_name'] ?? 'Unknown'); ?></strong>
                            (<?php echo e(ucfirst($req['patient']['patient_type'] ?? '-')); ?>)<br>
                            <small><?php echo e(fmt_dt($req['scheduled_at'], 'F d, Y - g:i A')); ?></small>
                            <?php if (!empty($req['reason'])): ?><br><small>Reason: <?php echo e($req['reason']); ?></small><?php endif; ?>
                        </div>
                        <form method="POST" action="/counselor/requests.php">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="appointment_id" value="<?php echo e($req['id']); ?>">
                            <button type="submit" name="action" value="approve" class="btn btn-success-sm">Approve</button>
                            <button type="submit" name="action" value="decline" class="btn btn-outline-danger-sm">Decline</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
<?php page_end(); ?>