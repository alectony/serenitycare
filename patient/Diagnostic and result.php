<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* AUTH: copy the exact first lines from patient/appointment.php (this is an example) */
$user  = require_role('patient');
$uid   = $user['id'];
$token = $user['token'];

/* ASSUMED TABLE: results(id, patient_id, counselor_id, diagnosis, recommendation, shared, created_at)
   Patients only ever see rows where shared = true. */
function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$mine    = 'patient_id=eq.' . urlencode($uid) . '&shared=eq.true';
$cols    = 'id,diagnosis,recommendation,created_at,counselor:profiles!counselor_id(full_name)';

$list = rows(sb_request('GET', 'results',
    'select=' . $cols . '&' . $mine . '&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
    null, $token));
$hasNext = count($list) > $perPage;
$list    = array_slice($list, 0, $perPage);

// The result currently open on the right (?id=...)
$open = null;
$id   = $_GET['id'] ?? '';
if (is_uuid($id)) {
    $open = rows(sb_request('GET', 'results', 'select=' . $cols . '&id=eq.' . urlencode($id) . '&' . $mine, null, $token))[0] ?? null;
}

page_start('patient', 'Diagnosis & Results', '/patient/results.php', 'Diagnosis and results', 'Results your counselor has shared with you.');
?>
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

  <section class="card">
  <div class="card-head">
    <div><h2>Latest results</h2></div>
    <a href="/patient/results.php" class="link">View all</a>
  </div>
  <?php if (!$latestResults): ?>
    <p class="empty">Nothing has been shared with you yet.</p>
  <?php else: foreach ($latestResults as $r): ?>
    <a href="/patient/results.php?id=<?= e($r['id']) ?>" class="result-item" style="text-decoration:none;color:inherit">
      <strong><?= e($r['diagnosis']) ?></strong>
      <small><?= e(fmt_dt($r['created_at'], 'M d, Y')) ?></small>
    </a>
  <?php endforeach; endif; ?>
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
        <p><?= !empty($open['recommendation']) ? nl2br(e($open['recommendation'])) : 'No recommendation was added.' ?></p>
        <div class="details-actions">
          <a class="btn" href="/patient/book.php">Book a follow-up</a>
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