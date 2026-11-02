<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('admin');
$token = $me['token'];
$msg = '';
$err = '';
$allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$settings = get_settings($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $open = $_POST['office_open'] ?? '';
    $close = $_POST['office_close'] ?? '';
    $days = array_values(array_intersect($allDays, (array) ($_POST['working_days'] ?? [])));
    $session = (int) ($_POST['session_minutes'] ?? 0);
    $window = (int) ($_POST['booking_window_days'] ?? 0);

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
        $err = 'Please enter valid opening and closing times.';
    } elseif (strcmp($close, $open) <= 0) {
        $err = 'Closing time must be after opening time.';
    } elseif (!$days) {
        $err = 'Choose at least one working day.';
    } elseif ($session < 15 || $session > 180) {
        $err = 'Session length must be between 15 and 180 minutes.';
    } elseif ($window < 1 || $window > 365) {
        $err = 'Booking window must be between 1 and 365 days.';
    } else {
        $new = ['office_open' => $open, 'office_close' => $close, 'working_days' => implode(',', $days),
                'session_minutes' => (string) $session, 'booking_window_days' => (string) $window];
        $rows = [];
        foreach ($new as $k => $v) {
            $rows[] = ['key' => $k, 'value' => $v, 'updated_by' => $me['user']['id'], 'updated_at' => date('c')];
        }
        $r = sb_request('POST', 'system_settings', 'on_conflict=key', $rows, $token, 'resolution=merge-duplicates,return=minimal');
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $changed = [];
            foreach ($new as $k => $v) { if (($settings[$k] ?? '') !== $v) { $changed[] = $k; } }
            audit_from($me, 'settings_updated', 'settings', '', $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved without changes');
            $settings = $new;
            $msg = 'Settings saved. The booking page now uses these rules.';
        } else {
            $err = 'Could not save the settings. Have you run the admin SQL upgrade?';
        }
    }
}
$activeDays = settings_days($settings);

page_start('admin', 'System Settings', '/admin/settings.php', 'System Settings', 'Office hours and session rules that patients see when booking.');
?>
            <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="card">
                <div class="card-head"><div><h2>Booking rules</h2><p class="card-sub">Patients can only book inside these limits</p></div></div>
                <form method="POST" action="/admin/settings.php">
                    <?php echo csrf_field(); ?>

                    <div class="form-row">
                        <div class="field"><label for="office_open">Office opens</label><input type="time" id="office_open" name="office_open" value="<?php echo e($settings['office_open']); ?>" required></div>
                        <div class="field"><label for="office_close">Office closes</label><input type="time" id="office_close" name="office_close" value="<?php echo e($settings['office_close']); ?>" required></div>
                        <div class="field"><label for="session_minutes">Session length (minutes)</label><input type="number" id="session_minutes" name="session_minutes" min="15" max="180" step="5" value="<?php echo e($settings['session_minutes']); ?>" required></div>
                        <div class="field"><label for="booking_window_days">Book up to (days ahead)</label><input type="number" id="booking_window_days" name="booking_window_days" min="1" max="365" value="<?php echo e($settings['booking_window_days']); ?>" required></div>
                    </div>

                    <fieldset class="day-picker">
                        <legend>Working days</legend>
                        <?php foreach ($allDays as $d): ?>
                            <label class="check-chip"><input type="checkbox" name="working_days[]" value="<?php echo e($d); ?>"<?php echo in_array($d, $activeDays, true) ? ' checked' : ''; ?>> <?php echo e($d); ?></label>
                        <?php endforeach; ?>
                    </fieldset>

                    <button type="submit" class="btn">Save settings</button>
                </form>
            </div>
<?php page_end(); ?>    