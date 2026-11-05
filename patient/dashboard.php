<?php
session_start();
require_once __DIR__ . '/../config/db.php'; // defines $pdo

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'patient') {
    header('Location: ../login.php'); exit;
}
$uid = (int) $_SESSION['user_id'];
function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function one($pdo, $sql, $params) {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn();
}
function all($pdo, $sql, $params) {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
}

$name = one($pdo, 'SELECT full_name FROM users WHERE id=?', [$uid]) ?: 'Patient';

/* Stats */
$upcoming = (int) one($pdo, "SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status IN ('pending','confirmed') AND appointment_date >= CURDATE()", [$uid]);
$pending  = (int) one($pdo, "SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status='pending'", [$uid]);
$done     = (int) one($pdo, "SELECT COUNT(*) FROM appointments WHERE patient_id=? AND status='completed'", [$uid]);
$unread   = (int) one($pdo, 'SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$uid]);

/* Upcoming appointments (first row = next appointment) */
$appts = all($pdo,
    "SELECT a.appointment_date, a.appointment_time, a.reason, a.status, u.full_name AS counselor
     FROM appointments a JOIN users u ON u.id = a.counselor_id
     WHERE a.patient_id=? AND a.status IN ('pending','confirmed') AND a.appointment_date >= CURDATE()
     ORDER BY a.appointment_date, a.appointment_time LIMIT 5", [$uid]);
$next = $appts[0] ?? null;

/* Latest alerts */
$alerts = all($pdo, 'SELECT title, message FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 3', [$uid]);

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
  <title>Dashboard | SerenityCare</title>
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
        <a href="<?= $file ?>" class="<?= $file === 'dashboard.php' ? 'active' : '' ?>">
          <?= $label ?><?= ($file === 'notifications.php' && $unread) ? " ($unread)" : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><a href="../logout.php" class="logout-btn">Log out</a></div>
  </aside>

  <main class="main-content">
    <header class="top-bar">
      <h1>Welcome, <?= e(explode(' ', trim($name))[0]) ?></h1>
      <p class="subtitle">Here is what is coming up for you.</p>
    </header>

    <section class="stats">
      <div class="stat-card"><span class="stat-label">Upcoming</span>
        <div class="stat-value"><?= $upcoming ?></div><div class="stat-note">Appointments ahead</div></div>
      <div class="stat-card"><span class="stat-label">Pending</span>
        <div class="stat-value"><?= $pending ?></div><div class="stat-note">Awaiting confirmation</div></div>
      <div class="stat-card"><span class="stat-label">Completed</span>
        <div class="stat-value"><?= $done ?></div><div class="stat-note">Sessions attended</div></div>
      <div class="stat-card"><span class="stat-label">Unread alerts</span>
        <div class="stat-value"><?= $unread ?></div><div class="stat-note">New notifications</div></div>
    </section>

    <div class="cta spaced">
      <div>
        <h3>Need to talk to someone?</h3>
        <p>Pick a counselor, date and time that works for you.</p>
      </div>
      <a href="book.php" class="btn btn-light">Book appointment</a>
    </div>

    <div class="content-grid">
      <div class="stack">
        <section class="card">
          <div class="card-head">
            <div><h2>Next appointment</h2></div>
            <a href="appointments.php" class="link">View all</a>
          </div>
          <?php if (!$next): ?>
            <p class="empty">No upcoming appointments. Book one to get started.</p>
          <?php else: ?>
            <table class="data-table">
              <thead><tr><th>When</th><th>Counselor</th><th>Reason</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($appts as $a): $t = strtotime($a['appointment_date']); ?>
                <tr>
                  <td>
                    <div class="when">
                      <span class="date-chip"><small><?= strtoupper(date('M', $t)) ?></small><strong><?= date('j', $t) ?></strong></span>
                      <span><?= date('g:i A', strtotime($a['appointment_time'])) ?></span>
                    </div>
                  </td>
                  <td><?= e($a['counselor']) ?></td>
                  <td><?= e($a['reason']) ?></td>
                  <td><span class="status <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>
      </div>

      <div class="stack">
        <section class="card">
          <div class="card-head">
            <div><h2>Alerts</h2></div>
            <a href="notifications.php" class="link">See all</a>
          </div>
          <?php if (!$alerts): ?>
            <p class="empty">You are all caught up.</p>
          <?php else: foreach ($alerts as $n): ?>
            <div class="result-item">
              <strong><?= e($n['title']) ?></strong>
              <small><?= e($n['message']) ?></small>
            </div>
          <?php endforeach; endif; ?>
        </section>
      </div>
    </div>
  </main>
</div>
</body>
</html>
