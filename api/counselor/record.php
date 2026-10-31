<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/supabase.php';

$currentUser = require_role('counselor');
$db = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = $_POST['patient_id'];
    $privateNotes = $_POST['private_notes'] ?? '';
    $diagnosis = $_POST['diagnosis'] ?? '';
    $recommendations = $_POST['recommendations'] ?? '';
    $shared = isset($_POST['shared_with_patient']) ? true : false;

    $stmt = $db->prepare("INSERT INTO records (patient_id, counselor_id, private_notes, diagnosis, recommendations, shared_with_patient) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$patientId, $currentUser['id'], $privateNotes, $diagnosis, $recommendations, $shared]);
    $msg = "Patient record saved successfully.";
}

// Fetch assigned patients for dropdown selection
$patStmt = $db->prepare("SELECT u.id, u.full_name FROM users u JOIN assignments a ON u.id = a.patient_id WHERE a.counselor_id = ?");
$patStmt->execute([$currentUser['id']]);
$assignedPatients = $patStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patient Records - SerenityCare</title>
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
                <a href="/api/counselor_dashboard/record.php" class="active">Patient Records</a>
                <a href="/api/counselor_dashboard/availability.php">Availability</a>
                <a href="/api/counselor_dashboard/profile.php">My Profile</a>
            </nav>
            <div class="sidebar-footer"><a href="/logout.php" class="logout-btn">Log out</a></div>
        </aside>
        <main class="main-content">
            <header class="top-bar"><h1>Session Notes & Diagnosis Editor</h1></header>
            <div class="card">
                <?php if ($msg): ?><p style="color:green;"><?= $msg ?></p><?php endif; ?>
                <form method="POST">
                    <label>Select Patient:</label>
                    <select name="patient_id" required style="display:block; margin-bottom:15px; padding:8px; width:100%;">
                        <option value="">-- Choose Patient --</option>
                        <?php foreach ($assignedPatients as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (($_GET['patient_id'] ?? '') === $p['id']) ? 'selected' : '' ?>><= htmlspecialchars($p['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Session Notes (Private to Counselor):</label>
                    <textarea name="private_notes" rows="4" style="width:100%; margin-bottom:15px; padding:8px;" required></textarea>

                    <label>Diagnosis:</label>
                    <input type="text" name="diagnosis" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <label>Recommendations / Results:</label>
                    <input type="text" name="recommendations" style="width:100%; margin-bottom:15px; padding:8px;" required>

                    <div style="margin-bottom:15px;">
                        <label><input type="checkbox" name="shared_with_patient" value="1"> Share with patient</label>
                    </div>

                    <button type="submit" class="btn" style="background:#2A9D8F; color:white; padding:10px 20px;">Save record</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>