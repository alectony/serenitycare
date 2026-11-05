<?php
session_start();
require_once __DIR__ . '/../config/db.php'; // defines $pdo

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    header('Location: ../login.php'); exit;
}
$uid = (int) $_SESSION['user_id'];
function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/* Actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'read':     $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, $uid]); break;
        case 'read_all': $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$uid]); break;
        case 'delete':   $pdo->prepare('DELETE FROM notifications WHERE id=? AND user_id=?')->execute([$id, $uid]); break;
    }
    header('Location: notifications.php?filter=' . urlencode($_GET['filter'] ?? 'all')); exit;
}

/* Data */
$filter = $_GET['filter'] ?? 'all';
$where  = $filter === 'unread' ? ' AND is_read=0' : '';
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=?$where ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$uid]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
$stmt->execute([$uid]);
$unread = (int) $stmt->fetchColumn();

$nav = [
    'dashboard.php' => 'Dashboard', 'appointments.php' => 'My appointments', 'book.php' => 'Book appointment',
    'results.php' => 'Diagnosis and results', 'summaries.php' => 'Shared session summaries',
    'notifications.php' => 'Notifications', 'profile.php' => 'Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications | SerenityCare</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-layout">

  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">S</span>
      <div><h2>SerenityCare</h2><span class="badge-role">PATIENT PORTAL</span></div>
    </div>
    <nav class="nav-links">
      <?php foreach ($nav as $file => $label): ?>
        <a href="<?= $file ?>" class="<?= $file === 'notifications.php' ? 'active' : '' ?>">
          <?= $label ?><?= ($file === 'notifications.php' && $unread) ? " ($unread)" : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><a href="../logout.php" class="logout-btn">Log out</a></div>
  </aside>

  <main class="main-content">
    <header class="top-bar">
      <h1>Notifications</h1>
      <p class="subtitle"><?= $unread ?> unread</p>
    </header>

    <section class="card">
      <div class="card-head">
        <div class="tabs">
          <a class="tab <?= $filter !== 'unread' ? 'active' : '' ?>" href="?filter=all">All</a>
          <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">Unread</a>
        </div>
        <?php if ($unread): ?>
          <form method="post" class="inline-form">
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
            <article class="request-item" style="<?= $n['is_read'] ? '' : 'background:#F7FAF7' ?>">
              <div>
                <strong><?= e($n['title']) ?></strong>
                <small><?= e($n['message']) ?></small>
                <small><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></small>
              </div>
              <div class="request-actions">
                <?php if (!$n['is_read']): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <button class="btn-sm">Mark read</button>
                  </form>
                <?php endif; ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this notification?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                  <button class="btn-outline-danger-sm">Delete</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>
</body>
</html>