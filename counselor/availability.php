<?php
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_role('counselor');
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day = $_POST['day_of_week'];
    $start = $_POST['start_time'];
    $end = $_POST['end_time'];

    $stmt = $db->prepare("INSERT INTO availability (counselor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
    $stmt->execute([$currentUser['id'], $day, $start, $end]);
    $msg = "Availability slot added.";
}

$stmt = $db->prepare("SELECT * FROM availability WHERE counselor_id = ? ORDER BY day_of_week");
$stmt->execute([$currentUser['id']]);
$slots = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Availability - SerenityCare</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body class="dashboard-body">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand"><h2>SerenityCare</h2><span class="badge-role">COUNSELOR PORTAL</span></div>
            <nav class="nav-links">
                <a href="/api/counselor_dashboard/index.php">Dashboard</a>
                <a href="/api/counselor_dashboard/schedule.php">My Schedule</a>
                <a href="/api/counselor_dashboard/requests.php">Appointment Requests</a>
                <a href="/api/counselor_dashboard/patients.php">Patient List</a>
                <a href="/api/counselor_dashboard/record.php">Patient Records</a>
                <a href="/api/counselor_dashboard/availability.php" class="active">Availability</a>
                <a href="/api/counselor_dashboard/profile.php">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1>Manage Availability Slots</h1></header>
            <div class="card">
                <?php if ($msg): ?><p style="color:green;"><?= $msg ?></p><?php endif; ?>
                <form method="POST" style="margin-bottom:20px;">
                    <select name="day_of_week" required style="padding:8px;">
                        <option value="Monday">Monday</option><option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option><option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                    </select>
                    <input type="time" name="start_time" required style="padding:8px;">
                    <input type="time" name="end_time" required style="padding:8px;">
                    <button type="submit" class="btn">Add Slot</button>
                </form>
                <ul>
                    <?php foreach ($slots as $s): ?>
                        <li><?= htmlspecialchars($s['day_of_week']) ?>: <?= $s['start_time'] ?> - <?= $s['end_time'] ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </main>
    </div>
</body>
</html>