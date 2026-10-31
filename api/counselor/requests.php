<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/supabase.php';

$currentUser = require_role('counselor');
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['appointment_id'])) {
    $status = $_POST['action'] === 'approve' ? 'confirmed' : 'declined';
    $stmt = $db->prepare("UPDATE appointments SET status = :status WHERE id = :id AND counselor_id = :cid");
    $stmt->execute([':status' => $status, ':id' => $_POST['appointment_id'], ':cid' => $currentUser['id']]);
    header('Location: requests.php');
    exit;
}

$stmt = $db->prepare("SELECT a.*, p.full_name as patient_name, p.patient_type FROM appointments a JOIN users p ON a.patient_id = p.id WHERE a.counselor_id = :cid AND a.status = 'pending' ORDER BY a.created_at ASC");
$stmt->execute([':cid' => $currentUser['id']]);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appointment Requests - SerenityCare</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body class="dashboard-body">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand"><h2>SerenityCare</h2><span class="badge-role">COUNSELOR PORTAL</span></div>
            <nav class="nav-links">
                <a href="/api/counselor_dashboard/index.php">Dashboard</a>
                <a href="/api/counselor_dashboard/schedule.php">My Schedule</a>
                <a href="/api/counselor_dashboard/requests.php" class="active">Appointment Requests</a>
                <a href="/api/counselor_dashboard/patients.php">Patient List</a>
                <a href="/api/counselor_dashboard/record.php">Patient Records</a>
                <a href="/api/counselor_dashboard/availability.php">Availability</a>
                <a href="/api/counselor_dashboard/profile.php">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1>Pending Appointment Requests</h1></header>
            <div class="card">
                <?php if (empty($requests)): ?>
                    <p>No pending appointment requests at this time.</p>
                <?php else: ?>
                    <div class="requests-list">
                        <?php foreach ($requests as $req): ?>
                            <div class="request-item" style="display:flex; justify-content:space-between; padding:15px; border-bottom:1px solid #eee;">
                                <div>
                                    <strong><?= htmlspecialchars($req['patient_name']) ?></strong> (<?= ucfirst($req['patient_type']) ?>)<br>
                                    <small><= date('F d, Y - g:i A', strtotime($req['scheduled_at'])) ?></small>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="appointment_id" value="<?= $req['id'] ?>">
                                    <button type="submit" name="action" value="approve" class="btn btn-success-sm">Approve</button>
                                    <button type="submit" name="action" value="decline" class="btn btn-outline-danger-sm">Decline</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>