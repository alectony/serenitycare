<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* AUTH: copy the exact first lines from patient/appointment.php (this is an example) */
$user  = require_role('patient');
$uid   = $user['id'];
$token = $user['token'];

/* ASSUMED TABLES (rename here if yours differ):
   profiles(id, full_name, role)                      -> counselors have role = 'counselor'
   availability(counselor_id, day_of_week, start_time, end_time)   day_of_week: 0 = Sunday ... 6 = Saturday
   appointments(patient_id, counselor_id, scheduled_at timestamptz, reason, status) */
const SLOT_MINUTES = 60;

function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

// Free times (H:i) for one counselor on one day: their availability minus booked slots.
function free_slots($cid, $day, $token) {
    $dow   = (int) date('w', strtotime($day));
    $hours = rows(sb_request('GET', 'availability',
        'select=start_time,end_time&counselor_id=eq.' . urlencode($cid) . '&day_of_week=eq.' . $dow, null, $token));
    $taken = array_map(fn($t) => date('H:i', strtotime($t['slot'])),
        rows(sb_request('POST', 'rpc/taken_slots', '', ['p_counselor' => $cid, 'p_day' => $day], $token)));
    $out = [];
    foreach ($hours as $h) {
        $end = strtotime("$day {$h['end_time']}");
        for ($s = strtotime("$day {$h['start_time']}"); $s + SLOT_MINUTES * 60 <= $end; $s += SLOT_MINUTES * 60) {
            if ($s > time() + 3600 && !in_array(date('H:i', $s), $taken, true)) $out[] = date('H:i', $s);
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

/* Only verified patients can book */
$status   = rows(sb_request('GET', 'patient_details', 'select=status&user_id=eq.' . urlencode($uid), null, $token))[0]['status'] ?? 'incomplete';
$verified = $status === 'verified';

$counselors = rows(sb_request('GET', 'profiles', 'select=id,full_name&role=eq.counselor&order=full_name.asc', null, $token));
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$cid = $src['counselor_id'] ?? '';
$day = $src['date'] ?? '';

$names    = array_column($counselors, 'full_name', 'id');
$okC      = is_uuid($cid) && isset($names[$cid]);
$minDay   = date('Y-m-d');
$maxDay   = date('Y-m-d', strtotime('+60 days'));
$okD      = valid_date($day) && $day >= $minDay && $day <= $maxDay;
$slots    = ($verified && $okC && $okD) ? free_slots($cid, $day, $token) : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slot   = $_POST['slot'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (!csrf_valid())                       $errors[] = 'Your session expired. Please try again.';
    elseif (!$verified)                      $errors[] = 'Your profile must be verified before you can book.';
    elseif (!$okC || !$okD)                  $errors[] = 'Choose a counselor and a date.';
    elseif (!in_array($slot, $slots, true))  $errors[] = 'That time is no longer available. Please pick another.';
    elseif (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) $errors[] = 'Please tell us the reason (3 to 500 characters).';
    else {
        $r = sb_request('POST', 'appointments', '', [
            'patient_id' => $uid, 'counselor_id' => $cid,
            'scheduled_at' => $day . 'T' . $slot . ':00+08:00',
            'reason' => $reason, 'status' => 'pending',
        ], $token);
        if ($r['status'] >= 200 && $r['status'] < 300) { header('Location: /patient/appointment.php?booked=1'); exit; }
        $errors[] = $r['status'] === 409 ? 'Someone just took that time. Please pick another.' : 'Could not book your appointment. Please try again.';
        $slots = free_slots($cid, $day, $token);
    }
}

page_start('patient', 'Book appointment', '/patient/book.php', 'Book appointment', 'Pick a counselor, a date and a time that works for you.');
?>
<?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

<?php if (!$verified): ?>
  <div class="search-alert">
    <?= $status === 'incomplete' ? 'Please complete your profile first.' : 'Your profile is still being verified by the guidance office.' ?>
    You can book once it is approved. <a href="/patient/profile.php">Go to my profile</a>
  </div>
<?php else: ?>
<div class="content-grid">
  <div class="stack">

    <form method="get" class="card">
      <h2>1. Choose a counselor and date</h2>
      <div class="form-row">
        <div class="field grow"><label for="counselor_id">Counselor</label>
          <select id="counselor_id" name="counselor_id" required>
            <option value="">Select a counselor</option>
            <?php foreach ($counselors as $c): ?>
              <option value="<?= e($c['id']) ?>" <?= $cid === $c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field grow"><label for="date">Date</label>
          <input type="date" id="date" name="date" min="<?= $minDay ?>" max="<?= $maxDay ?>" value="<?= e($day) ?>" required></div>
        <button type="submit" class="btn">Show available times</button>
      </div>
      <?php if (!$counselors): ?><p class="empty">No counselors are available yet.</p><?php endif; ?>
    </form>

    <?php if ($okC && $okD): ?>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="counselor_id" value="<?= e($cid) ?>">
      <input type="hidden" name="date" value="<?= e($day) ?>">
      <h2>2. Pick a time and tell us why</h2>
      <p class="card-sub"><?= e($names[$cid]) ?> &middot; <?= e(date('l, M j, Y', strtotime($day))) ?></p>

      <?php if (!$slots): ?>
        <p class="empty">No available times on this day. Try another date.</p>
      <?php else: ?>
        <fieldset class="day-picker">
          <legend>Available times</legend>
          <?php foreach ($slots as $i => $s): ?>
            <label class="check-chip">
              <input type="radio" name="slot" value="<?= e($s) ?>" <?= $i === 0 ? 'required' : '' ?> <?= ($_POST['slot'] ?? '') === $s ? 'checked' : '' ?>>
              <?= e(date('g:i A', strtotime("$day $s"))) ?>
            </label>
          <?php endforeach; ?>
        </fieldset>

        <div class="field"><label for="reason">Reason for the session</label>
          <textarea id="reason" name="reason" rows="3" maxlength="500" required><?= e($_POST['reason'] ?? '') ?></textarea></div>
        <button type="submit" class="btn">Request appointment</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>

  </div>

  <div class="note-box">
    <strong>How it works</strong>
    <small>Your request stays pending until the counselor confirms it. You will get a notification when its status changes.</small>
  </div>
</div>
<?php endif; ?>
<?php page_end(); ?>