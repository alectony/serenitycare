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

$res = sb_request('GET', 'availability', 'counselor_id=eq.' . urlencode($uid), null, $token);
$slots = ($res['status'] === 200 && is_array($res['data'])) ? $res['data'] : [];
usort($slots, fn($a, $b) =>
    [array_search($a['day_of_week'], $days), $a['start_time']] <=> [array_search($b['day_of_week'], $days), $b['start_time']]);

page_start('counselor', 'Availability', '/counselor/availability.php', 'Manage Availability Slots');
?>
            <div class="card">
                <div class="card-head"><div><h2>Add a time block</h2><p class="card-sub">Patients can request sessions inside the hours you set</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/counselor/availability.php" class="form-row">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="field">
                        <label for="day_of_week">Day</label>
                        <select id="day_of_week" name="day_of_week" required>
                            <?php foreach ($days as $d): ?><option value="<?php echo e($d); ?>"><?php echo e($d); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="start_time">From</label>
                        <input type="time" id="start_time" name="start_time" required>
                    </div>
                    <div class="field">
                        <label for="end_time">To</label>
                        <input type="time" id="end_time" name="end_time" required>
                    </div>
                    <button type="submit" class="btn">Add slot</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h2>Your availability</h2></div>
                <?php if (!$slots): ?>
                    <p class="empty">No availability slots yet.</p>
                <?php else: ?>
                    <ul class="slot-list">
                        <?php foreach ($slots as $s): ?>
                            <li>
                                <span><strong><?php echo e($s['day_of_week']); ?></strong> · <?php echo e(substr($s['start_time'], 0, 5)); ?> – <?php echo e(substr($s['end_time'], 0, 5)); ?></span>
                                <form method="POST" action="/counselor/availability.php" class="inline-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="slot_id" value="<?php echo e($s['id']); ?>">
                                    <button type="submit" class="btn-sm">Remove</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
<?php page_end(); ?>