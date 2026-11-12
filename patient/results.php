<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me    = require_role('patient');
$uid   = $me['user']['id'];
$token = $me['token'];

/* Table: diagnoses(id, patient_id, counselor_id, diagnosis, recommendations, shared_with_patient, created_at)
   Patients only ever see rows the counselor chose to share. */
function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$mine    = 'patient_id=eq.' . urlencode($uid) . '&shared_with_patient=eq.true';
$cols    = 'id,diagnosis,recommendations,created_at,counselor:profiles!counselor_id(full_name)';

$q = sb_request('GET', 'diagnoses',
    'select=' . $cols . '&' . $mine . '&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
    null, $token);
$failed  = ($q['status'] !== 200 || !is_array($q['data']));
$list    = rows($q);
$hasNext = count($list) > $perPage;
$list    = array_slice($list, 0, $perPage);

// The diagnosis currently open on the right (?id=...)
$open = null;
$id   = $_GET['id'] ?? '';
if (is_string($id) && (is_uuid($id) || ctype_digit($id))) {
    $open = rows(sb_request('GET', 'diagnoses', 'select=' . $cols . '&id=eq.' . urlencode($id) . '&' . $mine, null, $token))[0] ?? null;
}

page_start('patient', 'Diagnosis & Results', '/patient/results.php', 'Diagnosis and results',
    'Results your counselor has shared with you.', patient_header($uid, $token));
echo patient_search_modal($token);
?>
<?php if ($failed): ?><div class="alert error">Could not load your results. Please refresh.</div><?php endif; ?>

<div class="content-grid">

  <section class="card">
    <div class="card-head">
      <div><h2>Shared results</h2><p class="card-sub">Newest first</p></div>
    </div>

    <?php if (!$list): ?>
      <p class="empty">Nothing has been shared with you yet. When your counselor shares a result, it will show up here.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Date</th><th>Diagnosis</th><th>Counselor</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): $t = strtotime($r['created_at']); ?>
          <tr>
            <td>
              <div class="when">
                <span class="date-chip"><small><?= strtoupper(date('M', $t)) ?></small><strong><?= date('j', $t) ?></strong></span>
                <small><?= date('Y', $t) ?></small>
              </div>
            </td>
            <td><strong><?= e($r['diagnosis']) ?></strong></td>
            <td><?= e($r['counselor']['full_name'] ?? '-') ?></td>
            <td class="right"><a class="btn-sm" href="?<?= e(qs(['id' => $r['id']])) ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?= pager($page, $hasNext) ?>
    <?php endif; ?>
  </section>

  <div class="stack">
    <?php if ($open): ?>
      <section class="card details-panel">
        <div class="card-head">
          <h2>Result details</h2>
          <a class="link" href="?<?= e(qs(['id' => ''])) ?>">Close</a>
        </div>
        <div class="detail-row"><span>Date</span><strong><?= e(fmt_dt($open['created_at'])) ?></strong></div>
        <div class="detail-row"><span>Counselor</span><strong><?= e($open['counselor']['full_name'] ?? '-') ?></strong></div>
        <div class="detail-row"><span>Diagnosis</span><strong><?= e($open['diagnosis']) ?></strong></div>
        <p class="card-sub">Recommendation</p>
        <p><?= !empty($open['recommendations']) ? nl2br(e($open['recommendations'])) : 'No recommendation was added.' ?></p>
        <div class="details-actions">
          <a class="btn" href="/patient/appointment.php">Book a follow-up</a>
        </div>
      </section>
    <?php elseif ($list): ?>
      <div class="note-box">
        <strong>Select a result</strong>
        <small>Press View on any row to read the full diagnosis and recommendation.</small>
      </div>
    <?php endif; ?>

    <div class="note-box">
      <strong>Your privacy</strong>
      <small>Only you and your counselor can see these results. Questions about a result? Book a session and ask.</small>
    </div>
  </div>

</div>
<?php page_end(); ?>