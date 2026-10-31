<?php

require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_role('counselor');
$db = db();
$search = $_GET['search'] ?? '';

$query = "SELECT DISTINCT u.* FROM users u JOIN assignments a ON u.id = a.patient_id WHERE a.counselor_id = :cid";
if ($search) {
    $query .= " AND (u.full_name ILIKE :search OR u.email ILIKE :search)";
}
$stmt = $db->prepare($query);
$params = [':cid' => $currentUser['id']];
if ($search) { $params[':search'] = "%$search%"; }
$stmt->execute($params);
$patients = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient List - SerenityCare</title>
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
                <a href="/api/counselor_dashboard/patients.php" class="active">Patient List</a>
                <a href="/api/counselor_dashboard/record.php">Patient Records</a>
                <a href="/api/counselor_dashboard/availability.php">Availability</a>
                <a href="/api/counselor_dashboard/profile.php">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1>Assigned Patient List</h1></header>
            <div class="card">
                <form method="GET" style="margin-bottom:20px;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email..." style="padding:8px; width:300px;">
                    <button type="submit" class="btn">Search</button>
                </form>
                <table class="data-table">
                    <thead><tr><th>Full Name</th><th>Type</th><th>Grade / Department</th><th>Email</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                            <tr><td colspan="5">No assigned patients found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($patients as $pat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($pat['full_name']) ?></td>
                                    <td><?= ucfirst($pat['patient_type']) ?></td>
                                    <td><?= htmlspecialchars($pat['grade_or_dept'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($pat['email']) ?></td>
                                    <td><a href="record.php?patient_id=<?= $pat['id'] ?>" class="btn-sm">View Record</a></td>
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