<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* ---- AUTH: copy the exact first lines from patient/appointment.php ----
   (how $uid and $token of the logged-in patient are obtained).
   The lines below are an example; change them to match includes/auth.php. */
$user  = require_role('patient');
$uid   = $user['id'];
$token = $user['token'];
/* ----------------------------------------------------------------------- */

/* Turn an uploaded image into a small JPEG data URL (stored in the database,
   because Vercel has no permanent disk). Returns [error, dataUrl]. */
function img_data_url($field, $maxSide) {
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return ['', null];             // nothing uploaded
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2 * 1024 * 1024) return ['Each image must be 2 MB or smaller.', null];
    $bin  = file_get_contents($f['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return ['Only JPG, PNG or WEBP images are allowed.', null];

    if (function_exists('imagecreatefromstring')) {                               // shrink with GD
        $im = @imagecreatefromstring($bin);
        if (!$im) return ['That image could not be read.', null];
        $w = imagesx($im); $h = imagesy($im);
        $s = min(1, $maxSide / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) round($w * $s)), max(1, (int) round($h * $s)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start(); imagejpeg($out, null, 78); $bin = ob_get_clean();
        $mime = 'image/jpeg';
    } elseif (strlen($bin) > 300 * 1024) {
        return ['Image must be under 300 KB.', null];
    }
    return ['', 'data:' . $mime . ';base64,' . base64_encode($bin)];
}
function saved_ok($r) { return $r['status'] >= 200 && $r['status'] < 300; }

/* ---- Load current data ---- */
$r = sb_request('GET', 'profiles', 'select=id,full_name,email,patient_type,grade_or_dept&id=eq.' . urlencode($uid), null, $token);
$profile = $r['data'][0] ?? [];
$r = sb_request('GET', 'patient_details', 'select=*&user_id=eq.' . urlencode($uid), null, $token);
$details = $r['data'][0] ?? null;

/* ---- Save ---- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $name     = trim($_POST['full_name'] ?? '');
        $type     = $_POST['patient_type'] ?? '';
        $grade    = trim($_POST['grade_or_dept'] ?? '');
        $section  = trim($_POST['section'] ?? '');
        $adviser  = trim($_POST['adviser'] ?? '');
        $birth    = $_POST['birthdate'] ?? '';
        $contact  = trim($_POST['contact'] ?? '');
        $idType   = $_POST['id_type'] ?? '';
        $idNumber = trim($_POST['id_number'] ?? '');
        $gName    = trim($_POST['guardian_name'] ?? '');
        $gContact = trim($_POST['guardian_contact'] ?? '');

        $isStudent = $type === 'student';
        $age = valid_date($birth) && $birth <= date('Y-m-d') ? (new DateTime($birth))->diff(new DateTime())->y : null;
        $isMinor = $age !== null && $age < 18;

        if ($name === '')                                        $errors[] = 'Full name is required.';
        if (!in_array($type, ['student', 'teacher'], true))      $errors[] = 'Choose student or teacher.';
        if ($grade === '')                                       $errors[] = 'Grade, year level or department is required.';
        if ($isStudent && ($section === '' || $adviser === ''))  $errors[] = 'Students must enter a section and an adviser.';
        if ($age === null || $age > 100)                         $errors[] = 'Enter a valid birthdate.';
        if ($contact === '')                                     $errors[] = 'Contact number is required.';
        if (!in_array($idType, ['School ID', 'Employee ID', 'Other valid ID'], true) || $idNumber === '') {
            $errors[] = 'ID type and ID number are required.';
        }
        if ($isMinor && ($gName === '' || $gContact === ''))     $errors[] = 'A parent or guardian name and contact are required if you are under 18.';
        if (!$details && empty($_POST['consent']))               $errors[] = 'Please agree to the privacy consent.';

        [$e1, $avatar]  = img_data_url('avatar', 400);
        [$e2, $idPhoto] = img_data_url('id_photo', 1000);
        if ($e1) $errors[] = 'Profile picture: ' . $e1;
        if ($e2) $errors[] = 'ID photo: ' . $e2;
        if (!$avatar && empty($details['avatar']))     $errors[] = 'A profile picture is required.';
        if (!$idPhoto && empty($details['id_photo'])) $errors[] = 'A photo of your ID is required.';

        if (!$errors) {
            $r1 = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($uid),
                ['full_name' => $name, 'patient_type' => $type, 'grade_or_dept' => $grade], $token);

            $row = [
                'birthdate' => $birth, 'contact' => $contact, 'id_type' => $idType, 'id_number' => $idNumber,
                'section' => $isStudent ? $section : null, 'adviser' => $isStudent ? $adviser : null,
                'guardian_name' => $isMinor ? $gName : null, 'guardian_contact' => $isMinor ? $gContact : null,
            ];
            if ($avatar)  $row['avatar']   = $avatar;
            if ($idPhoto) $row['id_photo'] = $idPhoto;

            if ($details) {
                $r2 = sb_request('PATCH', 'patient_details', 'user_id=eq.' . urlencode($uid), $row, $token);
            } else {
                $row['user_id'] = $uid;
                $row['consented_at'] = gmdate('c');
                $r2 = sb_request('POST', 'patient_details', '', $row, $token);
            }

            if (saved_ok($r1) && saved_ok($r2)) {
                header('Location: /patient/profile.php?saved=1');
                exit;
            }
            $errors[] = 'Could not save your profile. Please try again.';
        }
    }
}

/* ---- View helpers ---- */
$v   = fn($k) => e($_POST[$k] ?? ($details[$k] ?? ($profile[$k] ?? '')));
$sel = fn($k, $val) => ($_POST[$k] ?? ($details[$k] ?? ($profile[$k] ?? ''))) === $val ? 'selected' : '';
$status = $details['status'] ?? 'incomplete';
$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($profile['full_name'] ?? 'P')), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}

page_start('patient', 'My Profile', '/patient/profile.php', 'My profile',
    'Your personal and school details. Only you and the guidance office can see them.',
    status_badge($status));
?>

<?php if (isset($_GET['saved'])): ?><div class="alert success">Profile saved.</div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

<?php if (!$details): ?>
  <div class="search-alert">Complete this form so the guidance office can verify you. You can book sessions once you are approved.</div>
<?php elseif ($status === 'pending'): ?>
  <div class="search-alert">Your details are waiting for verification by the guidance office.</div>
<?php elseif ($status === 'rejected'): ?>
  <div class="alert error">
    Your details were not approved<?= !empty($details['rejection_note']) ? ': ' . e($details['rejection_note']) : '.' ?>
    Please update them and save again.
  </div>
<?php endif; ?>

<div class="content-grid">

  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>

    <div class="form-section">
      <h2>Personal details</h2>
      <div class="form-row">
        <div class="field grow"><label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" value="<?= $v('full_name') ?>"></div>
        <div class="field grow"><label for="email">Email</label>
          <input type="email" id="email" value="<?= e($profile['email'] ?? '') ?>" disabled></div>
      </div>
      <div class="form-row">
        <div class="field grow"><label for="birthdate">Birthdate</label>
          <input type="date" id="birthdate" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= $v('birthdate') ?>"></div>
        <div class="field grow"><label for="contact">Contact number</label>
          <input type="tel" id="contact" name="contact" value="<?= $v('contact') ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <h2>School details</h2>
      <div class="form-row">
        <div class="field grow"><label for="patient_type">I am a</label>
          <select id="patient_type" name="patient_type">
            <option value="">Select</option>
            <option value="student" <?= $sel('patient_type', 'student') ?>>Student</option>
            <option value="teacher" <?= $sel('patient_type', 'teacher') ?>>Teacher</option>
          </select></div>
        <div class="field grow"><label for="grade_or_dept">Grade or year level (students) / department (teachers)</label>
          <input type="text" id="grade_or_dept" name="grade_or_dept" value="<?= $v('grade_or_dept') ?>"></div>
      </div>
      <div class="form-row">
        <div class="field grow"><label for="section">Section (students)</label>
          <input type="text" id="section" name="section" value="<?= $v('section') ?>"></div>
        <div class="field grow"><label for="adviser">Adviser (students)</label>
          <input type="text" id="adviser" name="adviser" value="<?= $v('adviser') ?>"></div>
      </div>
      <div class="form-row">
        <div class="field grow"><label for="id_type">ID type</label>
          <select id="id_type" name="id_type">
            <?php foreach (['School ID', 'Employee ID', 'Other valid ID'] as $o): ?>
              <option <?= $sel('id_type', $o) ?>><?= e($o) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field grow"><label for="id_number">ID number</label>
          <input type="text" id="id_number" name="id_number" value="<?= $v('id_number') ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <h2>Parent or guardian</h2>
      <p class="card-sub">Required if you are under 18.</p>
      <div class="form-row">
        <div class="field grow"><label for="guardian_name">Name</label>
          <input type="text" id="guardian_name" name="guardian_name" value="<?= $v('guardian_name') ?>"></div>
        <div class="field grow"><label for="guardian_contact">Contact number</label>
          <input type="tel" id="guardian_contact" name="guardian_contact" value="<?= $v('guardian_contact') ?>"></div>
      </div>
    </div>

    <div class="form-section">
      <h2>Photos</h2>
      <div class="form-row">
        <div class="field grow"><label for="avatar">Profile picture<?= !empty($details['avatar']) ? ' (leave empty to keep current)' : '' ?></label>
          <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp"></div>
        <div class="field grow"><label for="id_photo">Photo of your ID<?= !empty($details['id_photo']) ? ' (leave empty to keep current)' : '' ?></label>
          <input type="file" id="id_photo" name="id_photo" accept="image/jpeg,image/png,image/webp"></div>
      </div>
      <p class="form-hint">JPG, PNG or WEBP, up to 2 MB each. Changing your ID number, ID type or ID photo sends your profile for verification again.</p>
    </div>

    <?php if (!$details): ?>
      <div class="checkbox-row">
        <input type="checkbox" id="consent" name="consent" value="1">
        <label for="consent">I agree that SerenityCare may store my information for counseling and verification, in line with the Data Privacy Act.</label>
      </div>
    <?php endif; ?>

    <button type="submit" class="btn">Save profile</button>
  </form>

  <div class="stack">
    <section class="card">
      <?php if (!empty($details['avatar'])): ?>
        <img class="avatar-lg" src="<?= e($details['avatar']) ?>" alt="Your profile picture">
      <?php else: ?>
        <div class="avatar-lg avatar-fallback"><?= e($initials) ?></div>
      <?php endif; ?>
      <div class="profile-id">
        <strong><?= e($profile['full_name'] ?? '') ?></strong>
        <small><?= e($profile['email'] ?? '') ?></small>
      </div>

      <div class="detail-row"><span>Status</span><?= status_badge($status) ?></div>
      <div class="detail-row"><span>Type</span><strong><?= e(ucfirst($profile['patient_type'] ?? '-')) ?></strong></div>
      <div class="detail-row"><span>Grade / department</span><strong><?= e($profile['grade_or_dept'] ?? '-') ?></strong></div>
      <?php if (!empty($details['section'])): ?>
        <div class="detail-row"><span>Section</span><strong><?= e($details['section']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($details['adviser'])): ?>
        <div class="detail-row"><span>Adviser</span><strong><?= e($details['adviser']) ?></strong></div>
      <?php endif; ?>
      <div class="detail-row"><span>ID number</span><strong><?= e($details['id_number'] ?? '-') ?></strong></div>
      <?php if (!empty($details['guardian_name'])): ?>
        <div class="detail-row"><span>Guardian</span><strong><?= e($details['guardian_name']) ?></strong></div>
      <?php endif; ?>
    </section>

    <div class="note-box">
      <strong>Your privacy</strong>
      <small>Your ID photo is never shown back on this page and is only used for verification.</small>
    </div>
  </div>
</div>

<?php page_end(); ?>