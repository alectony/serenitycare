<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];
$token = $me['token'];

$filter = ($_GET['filter'] ?? 'all') === 'unread' ? 'unread' : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? '';
    $mine = 'user_id=eq.' . urlencode($uid);

    if ($action === 'read' && is_uuid($id)) {
        sb_request('PATCH', 'notifications', 'id=eq.' . urlencode($id) . '&' . $mine, ['is_read' => true], $token);
    } elseif ($action === 'read_all') {
        sb_request('PATCH', 'notifications', $mine . '&is_read=eq.false', ['is_read' => true], $token);
    } elseif ($action === 'delete' && is_uuid($id)) {
        sb_request('DELETE', 'notifications', 'id=eq.' . urlencode($id) . '&' . $mine, null, $token);
    }
    header('Location: /counselor/notification.php?filter=' . $filter);
    exit;
}

$items = get_notifications($uid, $token, 50, $filter === 'unread');
$unread = count(get_notifications($uid, $token, 100, true));

$fullName = $me['profile']['full_name'] ?? 'Counselor';
$ini = '';
foreach (array_slice(explode(' ', $fullName), 0, 2) as $w) { $ini .= strtoupper(substr($w, 0, 1)); }

$rightContent = '
    <div class="profile-interactable">
        <span class="profile-avatar">' . e($ini) . '</span>
        <div class="profile-info">
            <strong>' . e($fullName) . '</strong>
            <small>Guidance Counselor</small>
        </div>
    </div>
';

page_start('counselor', 'Notifications', '/counselor/notification.php', 'Notifications', $unread . ' unread alerts &bull; Session and student updates', $rightContent);
?>
<section class="card">
  <div class="card-head">
    <div class="tabs">
      <a class="tab <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">All</a>
      <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">Unread (<?= $unread ?>)</a>
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
    <p class="empty" style="text-align:center;padding:32px 0;">No notifications in this view.</p>
  <?php else: ?>
    <ul class="request-list">
      <?php foreach ($items as $n): ?>
        <li class="request-item" style="padding:16px 8px;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <strong style="font-size:15px;"><?= e($n['title']) ?></strong>
              <?php if (empty($n['is_read'])): ?>
                <span class="status pending" style="font-size:10.5px;padding:2px 8px;">New</span>
              <?php endif; ?>
            </div>
            <p style="margin:0 0 4px;font-size:13.5px;color:var(--ink-secondary);"><?= e($n['message']) ?></p>
            <small><?= fmt_dt($n['created_at']) ?></small>
          </div>
          <div class="request-actions">
            <?php if (empty($n['is_read'])): ?>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= e($n['id']) ?>">
                <button class="btn-sm">Mark read</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($n['id']) ?>">
              <button class="btn-outline-danger-sm" title="Delete notification">&times;</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php page_end(); ?>
