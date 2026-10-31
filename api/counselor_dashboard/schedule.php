<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/supabase.php';

$currentUser = require_role('counselor');
$db = db();

$stmt = $db->prepare("SELECT a.*, p.full_name as patient_name, p.patient_type FROM appointments a JOIN users p ON a.patient_id = p.id WHERE a.counselor_id = :cid ORDER BY a.scheduled_at DESC");
$stmt->execute([':cid' => $currentUser['id']]);
$appointments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Schedule - SerenityCare</title>
    <link rel="stylesheet" href="/public/css/style.css">
</head>
<body class="dashboard-body">
    <div class="app-layout">
        <aside class="sidebar">
            <div class="brand"><h2>SerenityCare</h2><span class="badge-role">COUNSELOR PORTAL</span></div>
            <nav class="nav-links">
                <a href="/api/counselor_dashboard/index.php">Dashboard</a>
                <a href="/api/counselor_dashboard/schedule.php" class="active">My Schedule</a>
                <a href="/api/counselor_dashboard/requests.php">Appointment Requests</a>
                <a href="/api/counselor_dashboard/patients.php">Patient List</a>
                <a href="/api/counselor_dashboard/record.php">Patient Records</a>
                <a href="/api/counselor_dashboard/availability.php">Availability</a>
                <a href="/api/counselor_dashboard/profile.php">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar">
                <h1>My Schedule & Calendar</h1>
            </header>
            <div class="card">
                <h2>All Appointments</h2>
                <table class="data-table">
                    <thead>
                        <tr><th>Date & Time</th><th>Patient Name</th><th>Type</th><th>Topic</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr><td colspan="5">No appointments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td><?= date('M d, Y g:i A', strtotime($appt['scheduled_at'])) ?></td>
                                    <td><?= htmlspecialchars($appt['patient_name']) ?></td>
                                    <td><?= ucfirst($appt['patient_type']) ?></td>
                                    <td><?= htmlspecialchars($appt['topic'] ?? 'General') ?></td>
                                    <td><span class="badge status-<?= $appt['status'] ?>"><?= ucfirst($appt['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>