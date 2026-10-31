<?php

require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_role('counselor');
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spec = $_POST['specialization'];
    $license = $_POST['license_number'];

    $stmt = $db->prepare("INSERT INTO counselor_details (counselor_id, specialization, license_number) VALUES (?, ?, ?) ON CONFLICT (counselor_id) DO UPDATE SET specialization = EXCLUDED.specialization, license_number = EXCLUDED.license_number");
    $stmt->execute([$currentUser['id'], $spec, $license]);
    $msg = "Profile updated successfully.";
}

$stmt = $db->prepare("SELECT * FROM counselor_details WHERE counselor_id = ?");
$stmt->execute([$currentUser['id']]);
$details = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - SerenityCare</title>
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
                <a href="/api/counselor_dashboard/availability.php">Availability</a>
                <a href="/api/counselor_dashboard/profile.php" class="active">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1>Counselor Profile & Credentials</h1></header>
            <div class="card">
                <?php if ($msg): ?><p style="color:green;"><?= $msg ?></p><?php endif; ?>
                <form method="POST">
                    <label>Specialization:</label>
                    <input type="text" name="specialization" value="<?= htmlspecialchars($details['specialization'] ?? '') ?>" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <label>License Number:</label>
                    <input type="text" name="license_number" value="<?= htmlspecialchars($details['license_number'] ?? '') ?>" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <button type="submit" class="btn">Save Credentials</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>