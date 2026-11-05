<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* AUTH: copy the exact first lines from patient/appointment.php (this is an example) */
$user  = require_role('patient');
$uid   = $user['id'];
$token = $user['token'];

// Uploaded image -> small JPEG data URL. Returns [error, dataUrl].
function img($field, $max) {
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return ['', null];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2097152) return ['must be 2 MB or smaller', null];
    $bin = file_get_contents($f['tmp_name']);
    $im  = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bin) : false;
    if (!$im) return ['must be a valid JPG, PNG or WEBP image', null];
    $w = imagesx($im); $h = imagesy($im); $s = min(1, $max / max($w, $h));
    $out = imagecreatetruecolor(max(1, (int) ($w * $s)), max(1, (int) ($h * $s)));
    imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    ob_start(); imagejpeg($out, null, 78);
    return ['', 'data:image/jpeg;base64,' . base64_encode(ob_get_clean())];
}
function ok($r) { return $r['status'] >= 200 && $r['status'] < 300; }

$p = sb_request('GET', 'profiles', 'select=full_name,email,patient_type,grade_or_dept&id=eq.' . urlencode($uid), null, $token)['data'][0] ?? [];
$d = sb_request('GET', 'patient_details', 'select=*&user_id=eq.' . urlencode($uid), null, $token)['data'][0] ?? null;
$status = $d['status'] ?? 'incomplete';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = array_map('trim', array_map('strval', array_intersect_key($_POST, array_flip([
        'full_name', 'patient_type', 'grade_or_dept', 'section', 'adviser', 'birthdate',
        'contact', 'id_type', 'id_number', 'guardian_name', 'guardian_contact']))));
    $in += array_fill_keys(['full_name', 'patient_type', 'grade_or_dept', 'section', 'adviser', 'birthdate',
        'contact', 'id_type', 'id_number', 'guardian_name', 'guardian_contact'], '');

    $student = $in['patient_type'] === 'student';
    $age     = valid_date($in['birthdate']) && $in['birthdate'] <= date('Y-m-d') ? (new DateTime($in['birthdate']))->diff(new DateTime())->y : null;
    $minor   = $age !== null && $age < 18;
    [$e1, $avatar]  = img('avatar', 400);
    [$e2, $idPhoto] = img('id_photo', 1000);

    if (!csrf_valid()) $errors[] = 'Your session expired. Please try again.';
    if (!$in['full_name'] || !$in['contact'] || !$in['id_number'] || !$in['grade_or_dept']) $errors[] = 'Name, contact, ID number and grade/department are required.';
    if (!in_array($in['patient_type'], ['student', 'teacher'], true)) $errors[] = 'Choose student or teacher.';
    if ($student && (!$in['section'] || !$in['adviser'])) $errors[] = 'Students must enter section and adviser.';
    if ($age === null || $age > 100) $errors[] = 'Enter a valid birthdate.';
    if ($minor && (!$in['guardian_name'] || !$in['guardian_contact'])) $errors[] = 'Parent or guardian details are required if you are under 18.';
    if (!$d && empty($_POST['consent'])) $errors[] = 'Please agree to the privacy consent.';
    if ($e1) $errors[] = "Profile picture $e1.";
    if ($e2) $errors[] = "ID photo $e2.";
    if (!$avatar && empty($d['avatar']))     $errors[] = 'A profile picture is required.';
    if (!$idPhoto && empty($d['id_photo'])) $errors[] = 'A photo of your ID is required.';

    if (!$errors) {
        $r1 = sb_request('PATCH', 'profiles', 'id=eq.' . urlencode($uid),
            ['full_name' => $in['full_name'], 'patient_type' => $in['patient_type'], 'grade_or_dept' => $in['grade_or_dept']], $token);
        $row = [
            'birthdate' => $in['birthdate'], 'contact' => $in['contact'], 'id_type' => $in['id_type'], 'id_number' => $in['id_number'],
            'section' => $student ? $in['section'] : null, 'adviser' => $student ? $in['adviser'] : null,
            'guardian_name' => $minor ? $in['guardian_name'] : null, 'guardian_contact' => $minor ? $in['guardian_contact'] : null,
        ];
        if ($avatar)  $row['avatar'] = $avatar;
        if ($idPhoto) $row['id_photo'] = $idPhoto;
        $r2 = $d ? sb_request('PATCH', 'patient_details', 'user_id=eq.' . urlencode($uid), $row, $token)
                 : sb_request('POST', 'patient_details', '', $row + ['user_id' => $uid, 'consented_at' => gmdate('c')], $token);
        if (ok($r1) && ok($r2)) { header('Location: /patient/profile.php?saved=1'); exit; }
        $errors[] = 'Could not save your profile. Please try again.';
    }
}

$val = fn($k) => e($_POST[$k] ?? ($d[$k] ?? ($p[$k] ?? '')));
$fields = [
    'full_name' => ['Full name', 'text'], 'birthdate' => ['Birthdate', 'date'], 'contact' => ['Contact number', 'tel'],
    'grade_or_dept' => ['Grade / year level (students) or department (teachers)', 'text'],
    'section' => ['Section (students)', 'text'], 'adviser' => ['Adviser (students)', 'text'],
    'id_number' => ['ID number', 'text'],
    'guardian_name' => ['Parent or guardian name (if under 18)', 'text'], 'guardian_contact' => ['Parent or guardian contact (if under 18)', 'tel'],
];

page_start('patient', 'My Profile', '/patient/profile.php', 'My profile', 'Your personal and school details.', status_badge($status));
?>
<?php if (isset($_GET['saved'])): ?><div class="alert success">Profile saved.</div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($status === 'pending'): ?><div class="search-alert">Waiting for verification by the guidance office.</div><?php endif; ?>
<?php if ($status === 'rejected'): ?><div class="alert error">Not approved<?= !empty($d['rejection_note']) ? ': ' . e($d['rejection_note']) : '.' ?> Please update and save again.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <?php if (!empty($d['avatar'])): ?><img class="avatar-lg" src="<?= e($d['avatar']) ?>" alt="Profile picture"><?php endif; ?>

  <div class="form-row">
    <div class="field grow"><label>Email</label><input type="email" value="<?= e($p['email'] ?? '') ?>" disabled></div>
    <div class="field grow"><label>I am a</label>
      <select name="patient_type">
        <option value="">Select</option>
        <?php foreach (['student', 'teacher'] as $t): ?>
          <option value="<?= $t ?>" <?= ($_POST['patient_type'] ?? $p['patient_type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field grow"><label>ID type</label>
      <select name="id_type">
        <?php foreach (['School ID', 'Employee ID', 'Other valid ID'] as $o): ?>
          <option <?= ($_POST['id_type'] ?? $d['id_type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>

  <div class="form-row">
    <?php foreach ($fields as $name => [$label, $type]): ?>
      <div class="field grow"><label><?= e($label) ?></label>
        <input type="<?= $type ?>" name="<?= $name ?>" value="<?= $val($name) ?>"></div>
    <?php endforeach; ?>
  </div>

  <div class="form-row">
    <div class="field grow"><label>Profile picture<?= !empty($d['avatar']) ? ' (empty = keep current)' : '' ?></label>
      <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></div>
    <div class="field grow"><label>Photo of your ID<?= !empty($d['id_photo']) ? ' (empty = keep current)' : '' ?></label>
      <input type="file" name="id_photo" accept="image/jpeg,image/png,image/webp"></div>
  </div>
  <p class="form-hint">Images up to 2 MB. Changing your ID details sends your profile for verification again.</p>

  <?php if (!$d): ?>
    <div class="checkbox-row">
      <input type="checkbox" id="consent" name="consent" value="1">
      <label for="consent">I agree that SerenityCare may store my information for counseling and verification.</label>
    </div>
  <?php endif; ?>

  <button type="submit" class="btn">Save profile</button>
</form>
<?php page_end(); ?>