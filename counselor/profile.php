<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spec = trim($_POST['specialization'] ?? '');
    $license = trim($_POST['license_number'] ?? '');

    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } elseif ($spec === '' || $license === '') {
        $err = 'Please fill in both fields.';
    } else {
        // Insert, or update if this counselor already has a row
        $r = sb_request('POST', 'counselor_details', 'on_conflict=counselor_id', [
            'counselor_id' => $uid, 'specialization' => $spec, 'license_number' => $license,
        ], $token, 'resolution=merge-duplicates,return=representation');
        if ($r['status'] === 200 || $r['status'] === 201) { $msg = 'Profile updated successfully.'; }
        else { $err = 'Could not save your profile. Please try again.'; }
    }
}

$res = sb_request('GET', 'counselor_details', 'counselor_id=eq.' . urlencode($uid), null, $token);
$details = ($res['status'] === 200 && !empty($res['data'][0])) ? $res['data'][0] : [];

page_start('counselor', 'My Profile', '/counselor/profile.php', 'Counselor Profile & Credentials');
?>
            <div class="card">
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <p><strong><?php echo e($me['profile']['full_name'] ?? ''); ?></strong><br><small><?php echo e($me['user']['email'] ?? ''); ?></small></p>

                <form method="POST" action="/counselor/profile.php">
                    <?php echo csrf_field(); ?>
                    <label>Specialization:</label>
                    <input type="text" name="specialization" value="<?php echo e($details['specialization'] ?? ''); ?>" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <label>License Number:</label>
                    <input type="text" name="license_number" value="<?php echo e($details['license_number'] ?? ''); ?>" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <button type="submit" class="btn">Save Credentials</button>
                </form>
            </div>
<?php page_end(); ?>