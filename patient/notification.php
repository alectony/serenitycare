<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

/* ---- AUTH: kopyahin dito ang eksaktong unang lines ng patient/appointment.php ----
   (kung paano nakukuha ang $uid at $token ng logged-in na patient).
   Halimbawa lang ang nasa baba; palitan kung iba ang function name sa auth.php. */
$user  = require_role('patient');
$uid   = $user['id'];
$token = $user['token'];
/* ------------------------------------------------------------------------------ */

$filter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';

/* ---- Actions (POST, tapos redirect) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    $action = $_POST['action'] ?? '';
    $id     = $_POST['id'] ?? '';
    $mine   = 'user_id=eq.' . urlencode($uid);

    if ($action === 'read' && is_uuid($id)) {
        sb_request('PATCH', 'notifications', 'id=eq.' . urlencode($id) . '&' . $mine, ['is_read' => true], $token);
    } elseif ($action === 'read_all') {
        sb_request('PATCH', 'notifications', $mine . '&is_read=eq.false', ['is_read' => true], $token);
    } elseif ($action === 'delete' && is_uuid($id)) {
        sb_request('DELETE', 'notifications', 'id=eq.' . urlencode($id) . '&' . $mine, null, $token);
    }
    header('Location: /patient/notification.php?filter=' . $filter);
    exit;
}

/* ---- Data ---- */
$items  = get_notifications($uid, $token, 50, $filter === 'unread');
$unread = count(get_notifications($uid, $token, 100, true));

page_start('patient', 'Notifications', '/patient/notification.php', 'Notifications', $unread . ' unread');
?>
<section class="card">
  <div class="card-head">
    <div class="tabs">
      <a class="tab <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All</a>
      <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">Unread</a>
    </div>
    <?php if ($unread): ?>
      <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="read_all">
        <button class="btn-sm">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <p class="empty">No notifications to show.</p>
  <?php else: ?>
    <div class="request-list">
      <?php foreach ($items as $n): ?>
        <article class="request-item <?= $n['is_read'] ? '' : 'unread' ?>">
          <div>
            <strong><?= e($n['title']) ?></strong>
            <small><?= e($n['message']) ?></small>
            <small><?= e(fmt_dt($n['created_at'])) ?></small>
          </div>
          <div class="request-actions">
            <?php if (!$n['is_read']): ?>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= e($n['id']) ?>">
                <button class="btn-sm">Mark read</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this notification?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($n['id']) ?>">
              <button class="btn-outline-danger-sm">Delete</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php page_end(); ?>