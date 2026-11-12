<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me    = require_role('patient');
$uid   = $me['user']['id'];
$token = $me['token'];

/* Table: session_summaries(id, patient_id, counselor_id, summary, next_steps, shared_with_patient, created_at)
   If your table or column names are different, change them here (only these two lines). */
const SUMMARY_TABLE  = 'session_summaries';
const SHARED_COLUMN  = 'shared_with_patient';

function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$mine    = 'patient_id=eq.' . urlencode($uid) . '&' . SHARED_COLUMN . '=eq.true';
$cols    = 'id,summary,next_steps,created_at,counselor:profiles!counselor_id(full_name)';

$q = sb_request('GET', SUMMARY_TABLE,
    'select=' . $cols . '&' . $mine . '&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
    null, $token);
$failed  = ($q['status'] !== 200 || !is_array($q['data']));
$list    = rows($q);
$hasNext = count($list) > $perPage;
$list    = array_slice($list, 0, $perPage);

// The summary currently open on the right (?id=...)
$open = null;
$id   = $_GET['id'] ?? '';
if (is_string($id) && (is_uuid($id) || ctype_digit($id))) {
    $open = rows(sb_request('GET', SUMMARY_TABLE, 'select=' . $cols . '&id=eq.' . urlencode($id) . '&' . $mine, null, $token))[0] ?? null;
}

page_start('patient', 'Session Summaries', '/patient/session.php', 'Session summaries',
    'Notes your counselor chose to share after your sessions.', patient_header($uid, $token));
echo patient_search_modal($token);
?>
<?php if ($failed): ?><div class="alert error">Could not load your session summaries. Please refresh.</div><?php endif; ?>

<div class="content-grid">

  <section class="card">
    <div class="card-head">
      <div><h2>Your sessions</h2><p class="card-sub">Newest first</p></div>
    </div>

    <?php if (!$list): ?>
      <p class="empty">No summaries yet. After a session, your counselor may share a short summary here.</p>
    <?php else: ?>
      <div class="request-list">
        <?php foreach ($list as $s): $t = strtotime($s['created_at']); ?>
          <article class="request-item">
            <div class="when">
              <span class="date-chip"><small><?= strtoupper(date('M', $t)) ?></small><strong><?= date('j', $t) ?></strong></span>
              <div>
                <strong><?= e($s['counselor']['full_name'] ?? 'Counselor') ?></strong>
                <small><?= e(mb_strimwidth((string) $s['summary'], 0, 110, '...')) ?></small>
              </div>
            </div>
            <div class="request-actions">
              <a class="btn-sm" href="?<?= e(qs(['id' => $s['id']])) ?>">Read</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?= pager($page, $hasNext) ?>
    <?php endif; ?>
  </section>

  <div class="stack">
    <?php if ($open): ?>
      <section class="card details-panel">
        <div class="card-head">
          <h2>Session summary</h2>
          <a class="link" href="?<?= e(qs(['id' => ''])) ?>">Close</a>
        </div>
        <div class="detail-row"><span>Date</span><strong><?= e(fmt_dt($open['created_at'])) ?></strong></div>
        <div class="detail-row"><span>Counselor</span><strong><?= e($open['counselor']['full_name'] ?? '-') ?></strong></div>

        <p class="card-sub">Summary</p>
        <p><?= nl2br(e($open['summary'])) ?></p>

        <p class="card-sub">Next steps</p>
        <p><?= !empty($open['next_steps']) ? nl2br(e($open['next_steps'])) : 'No next steps were added.' ?></p>

        <div class="details-actions">
          <a class="btn" href="/patient/appointment.php">Book another session</a>
        </div>
      </section>
    <?php elseif ($list): ?>
      <div class="note-box">
        <strong>Select a session</strong>
        <small>Press Read to see the full summary and your next steps.</small>
      </div>
    <?php endif; ?>

    <div class="note-box">
      <strong>Your privacy</strong>
      <small>Only summaries your counselor chose to share appear here. They are visible only to you and your counselor.</small>
    </div>
  </div>

</div>
<?php page_end(); ?>