<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* AUTH: copy the exact first lines from patient/appointment.php (this is an example) */
$user  = require_role('patient');
$uid   = $user['user']['id'] ?? ($user['id'] ?? null);
$token = $user['token'];

/* ASSUMED TABLE: session_summaries(id, patient_id, counselor_id, appointment_id, summary, next_steps, shared, created_at)
   Patients only ever see rows where shared = true. */
function rows($r) { return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : []; }

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$mine    = 'patient_id=eq.' . urlencode($uid) . '&shared=eq.true';
$cols    = 'id,summary,next_steps,created_at,counselor:profiles!counselor_id(full_name),appointment:appointments(scheduled_at)';

$list = rows(sb_request('GET', 'session_summaries',
    'select=' . $cols . '&' . $mine . '&order=created_at.desc&limit=' . ($perPage + 1) . '&offset=' . (($page - 1) * $perPage),
    null, $token));
$hasNext = count($list) > $perPage;
$list    = array_slice($list, 0, $perPage);

// The summary currently open on the right (?id=...)
$open = null;
$id   = $_GET['id'] ?? '';
if (is_uuid($id)) {
    $open = rows(sb_request('GET', 'session_summaries', 'select=' . $cols . '&id=eq.' . urlencode($id) . '&' . $mine, null, $token))[0] ?? null;
}

// Session date: the appointment time if linked, otherwise the day the summary was written
function session_date($s) { return $s['appointment']['scheduled_at'] ?? $s['created_at']; }

page_start('patient', 'Session Summaries', '/patient/summaries.php', 'Session summaries', 'Notes your counselor chose to share after your sessions.');
?>
<div class="content-grid">

  <section class="card">
    <div class="card-head">
      <div><h2>Your sessions</h2><p class="card-sub">Newest first</p></div>
    </div>

    <?php if (!$list): ?>
      <p class="empty">No summaries yet. After a session, your counselor may share a short summary here.</p>
    <?php else: ?>
      <div class="request-list">
        <?php foreach ($list as $s): $t = strtotime(session_date($s)); ?>
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
                    <div class="card">
                        <div class="card-head"><h2>Latest Results</h2><a class="link" href="/patient/results.php">View all</a></div>
                        <?php if (!$results): ?>
                            <p class="card-sub">No results have been shared with you yet.</p>
                        <?php else: foreach ($results as $r): ?>
                            <div class="result-item">
                                <strong><?php echo e($r['diagnosis']); ?></strong>
                                <small><?php echo e($r['counselor']['full_name'] ?? 'Counselor'); ?> · <?php echo e(fmt_dt($r['created_at'], 'M d, Y')); ?></small>
                                <?php if (!empty($r['recommendations'])): ?><small>Recommendation: <?php echo e($r['recommendations']); ?></small><?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                        <div class="note-box"><strong>Your privacy</strong><small>Only you and your counselor can see these records.</small></div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div><h2>Notifications</h2><p class="card-sub"><?php echo count($unread); ?> unread</p></div>
                            <a class="link" href="/patient/notification.php">See all</a>
                        </div>
                        <?php if (!$unread): ?>
                            <p class="card-sub">You are all caught up.</p>
                        <?php else: foreach (array_slice($unread, 0, 3) as $n): ?>
                            <div class="result-item">
                                <strong><?php echo e($n['title']); ?></strong>
                                <small><?php echo e($n['message']); ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
<?php page_end(); ?>