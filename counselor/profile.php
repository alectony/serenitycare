<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $err = 'Session expired. Please reload the page and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $spec = trim($_POST['specialization'] ?? '');
        $license = trim($_POST['license_number'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $spec === '' || $license === '') {
            $err = 'Please fill in all required profile fields.';
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $err = 'New password must be at least 6 characters long.';
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $err = 'New passwords do not match.';
        } else {
            // 1. Update Full Name in profiles table
            $profUpdate = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($uid), [
                'full_name' => $fullName
            ], $token, 'return=representation');

            // 2. Update Credentials in counselor_details table
            $credUpdate = sb_request('POST', 'counselor_details', 'on_conflict=counselor_id', [
                'counselor_id' => $uid, 'specialization' => $spec, 'license_number' => $license,
            ], $token, 'resolution=merge-duplicates,return=representation');

            // 3. Update Password via Supabase Auth API if provided
            $passSuccess = true;
            if ($newPassword !== '') {
                $authRes = sb_request('PUT', 'auth/v1/user', null, [
                    'password' => $newPassword
                ], $token);
                if ($authRes['status'] !== 200) {
                    $passSuccess = false;
                }
            }

            if (($profUpdate['status'] === 200 || $profUpdate['status'] === 204) &&
                ($credUpdate['status'] === 200 || $credUpdate['status'] === 201) && $passSuccess) {
                $msg = 'Profile, credentials, and password updated successfully.';
                // Refresh local user data object
                $me['profile']['full_name'] = $fullName;
            } else {
                $err = 'Could not fully save your changes. Please check your inputs and try again.';
            }
        }
    }
}

// Fetch current counselor details
$res = sb_request('GET', 'counselor_details', 'counselor_id=eq.' . urlencode($uid), null, $token);
$details = ($res['status'] === 200 && !empty($res['data'][0])) ? $res['data'][0] : [];

page_start('counselor', 'My Profile', '/counselor/profile.php', 'Counselor Profile & Credentials');
?>
            <div class="card">
                <div class="card-head"><div><h2>Edit Profile &amp; Security</h2><p class="card-sub">Update your personal info, professional credentials, and password</p></div></div>
                <?php if ($msg): ?><div class="alert success"><?php echo e($msg); ?></div><?php endif; ?>
                <?php if ($err): ?><div class="alert error"><?php echo e($err); ?></div><?php endif; ?>

                <form method="POST" action="/counselor/profile.php">
                    <?php echo csrf_field(); ?>
                    
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo e($me['profile']['full_name'] ?? ''); ?>" required>
                    </div>

                    <div class="field">
                        <label for="email">Email Address (Read-only)</label>
                        <input type="email" id="email" value="<?php echo e($me['user']['email'] ?? ''); ?>" disabled>
                    </div>

                    <div class="field">
                        <label for="specialization">Specialization</label>
                        <input type="text" id="specialization" name="specialization" value="<?php echo e($details['specialization'] ?? ''); ?>" required>
                    </div>

                    <div class="field">
                        <label for="license_number">License number</label>
                        <input type="text" id="license_number" name="license_number" value="<?php echo e($details['license_number'] ?? ''); ?>" required>
                    </div>

                    <div class="field">
                        <h3>Change Password</h3>
                        <p class="form-hint">Leave blank if you do not wish to change your current password.</p>
                    </div>

                    <div class="field">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="At least 6 characters">
                    </div>

                    <div class="field">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password">
                    </div>

                    <button type="submit" class="btn">Save all changes</button>
                </form>
            </div>
<?php page_end(); ?>