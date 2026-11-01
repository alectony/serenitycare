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
                <div class="card-head"><div><h2>Credentials</h2><p class="card-sub">Shown to patients when they choose a counselor</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <div class="profile-id">
                    <strong><?php echo e($me['profile']['full_name'] ?? ''); ?></strong>
                    <small><?php echo e($me['user']['email'] ?? ''); ?></small>
                </div>

                <form method="POST" action="/counselor/profile.php">
                    <?php echo csrf_field(); ?>
                    <div class="field">
                        <label for="specialization">Specialization</label>
                        <input type="text" id="specialization" name="specialization" value="<?php echo e($details['specialization'] ?? ''); ?>" required>
                    </div>
                    <div class="field">
                        <label for="license_number">License number</label>
                        <input type="text" id="license_number" name="license_number" value="<?php echo e($details['license_number'] ?? ''); ?>" required>
                    </div>
                    <button type="submit" class="btn">Save credentials</button>
                </form>
            </div>
<?php page_end(); ?>