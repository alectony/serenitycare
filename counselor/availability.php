<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif ($action === 'delete') {
        $id = $_POST['slot_id'] ?? '';
        if (is_uuid($id)) {
            sb_request('DELETE', 'availability', 'id=eq.' . urlencode($id) . '&counselor_id=eq.' . urlencode($uid), null, $token);
            $msg = 'Availability slot removed.';
        }
    } else {
        $day = $_POST['day_of_week'] ?? '';
        $start = $_POST['start_time'] ?? '';
        $end = $_POST['end_time'] ?? '';

        if (!in_array($day, $days, true) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            $err = 'Please choose a valid day and time.';
        } elseif (strcmp($end, $start) <= 0) {
            $err = 'End time must be after start time.';
        } else {
            $r = sb_request('POST', 'availability', '', [
                'counselor_id' => $uid, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end,
            ], $token);
            if ($r['status'] === 201) { $msg = 'Availability slot added.'; }
            else { $err = 'Could not add the slot. Please try again.'; }
        }
    }
}

$res = sb_request('GET', 'availability', 'counselor_id=eq.' . urlencode($uid) . '&order=day_of_week.asc,start_time.asc', null, $token);
$slots = ($res['status'] === 200 && is_array($res['data'])) ? $res['data'] : [];
usort($slots, fn($a, $b) => array_search($a['day_of_week'], $GLOBALS['days']) <=> array_search($b['day_of_week'], $GLOBALS['days']));

page_start('counselor', 'Availability', '/counselor/availability.php', 'Manage Availability Slots');
?>
            <div class="card">
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/counselor/availability.php" style="margin-bottom:20px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <select name="day_of_week" required style="padding:8px;">
                        <?php foreach ($days as $d): ?><option value="<?php echo e($d); ?>"><?php echo e($d); ?></option><?php endforeach; ?>
                    </select>
                    <input type="time" name="start_time" required style="padding:8px;">
                    <input type="time" name="end_time" required style="padding:8px;">
                    <button type="submit" class="btn">Add Slot</button>
                </form>

                <?php if (!$slots): ?><p>No availability slots yet.</p><?php endif; ?>
                <ul>
                    <?php foreach ($slots as $s): ?>
                        <li>
                            <?php echo e($s['day_of_week']); ?>: <?php echo e(substr($s['start_time'], 0, 5)); ?> - <?php echo e(substr($s['end_time'], 0, 5)); ?>
                            <form method="POST" action="/counselor/availability.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slot_id" value="<?php echo e($s['id']); ?>">
                                <button type="submit" class="btn-sm">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
<?php page_end(); ?>