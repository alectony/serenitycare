<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid = $me['user']['id'];

$res = sb_request('GET', 'appointments',
    'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.asc', null, $me['token']);

$failed = ($res['status'] !== 200 || !is_array($res['data']));
$all = $failed ? [] : $res['data'];

$today = date('Y-m-d');
$todays = array_values(array_filter($all, fn($a) =>
    date('Y-m-d', strtotime($a['scheduled_at'])) === $today
    && in_array($a['status'], ['pending', 'confirmed', 'completed'], true)));

$pendingCount = count(array_filter($all, fn($a) => $a['status'] === 'pending'));
$patientCount = count(array_unique(array_column($all, 'patient_id')));
$counselorName = $me['profile']['full_name'] ?? 'Counselor';
$firstName = explode(' ', trim($counselorName))[0] ?: 'Counselor';

$upcoming = array_values(array_filter($all, fn($a) =>
    strtotime($a['scheduled_at']) >= strtotime('today')
    && in_array($a['status'], ['pending', 'confirmed'], true)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SerenityCare - Counselor Dashboard</title>
    <!-- Fixed CSS path reference -->
    <link rel="stylesheet" href="../public/css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="role-counselor app-layout custom-scroll">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div>
            <div class="px-6 py-6 flex items-center gap-3 border-b border-white/10">
                <div class="w-10 h-10 rounded-xl bg-[#3d5240] text-white flex items-center justify-center font-bold text-xl shadow-inner">S</div>
                <div>
                    <h1 class="font-bold text-white text-lg tracking-tight leading-none">SerenityCare</h1>
                    <span class="badge-role uppercase mt-1">Counselor Portal</span>
                </div>
            </div>
            <nav class="nav-links">
                <a href="/counselor/" class="active"><i data-lucide="layout-dashboard" class="w-4 h-4"></i>Dashboard</a>
                <a href="/counselor/appointments.php"><i data-lucide="calendar-plus" class="w-4 h-4"></i>Book Appointment</a>
                <a href="/counselor/appointments.php"><i data-lucide="calendar" class="w-4 h-4"></i>My Appointments</a>
                <a href="/counselor/results.php"><i data-lucide="activity" class="w-4 h-4"></i>Diagnosis &amp; Results</a>
                <a href="/counselor/summaries.php"><i data-lucide="file-text" class="w-4 h-4"></i>Session Summaries</a>
                <a href="/counselor/notifications.php" class="justify-between">
                    <span class="flex items-center gap-3"><i data-lucide="bell" class="w-4 h-4"></i>Notifications</span>
                    <span class="bg-[#785e13]/50 text-amber-200 text-[10px] px-2 py-0.5 rounded-full font-bold"><?php echo (int)$pendingCount; ?></span>
                </a>
                <a href="/counselor/profile.php"><i data-lucide="user" class="w-4 h-4"></i>My Profile</a>
            </nav>
        </div>
        <div class="sidebar-footer">
            <a href="/logout.php" class="logout-btn"><i data-lucide="log-out" class="w-4 h-4"></i>Log out</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="top-bar">
            <div>
                <h1 class="serif-heading font-normal">Good day, <?php echo e($firstName); ?></h1>
                <p class="text-xs text-[#716b60] mt-1">Here is a summary of your counseling care and upcoming student sessions.</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"></i>
                    <input type="text" id="search-input" oninput="handleSearch()" placeholder="Search student or appointment..." class="pl-9 pr-4 py-2 text-xs w-56 md:w-64">
                </div>
                <div class="flex items-center gap-3 pl-3 border-l border-[#e2d8c3]">
                    <div class="w-9 h-9 rounded-full bg-[#3d5240] text-cream-100 flex items-center justify-center font-bold text-xs shadow-sm">
                        <?php echo e(strtoupper(substr($firstName, 0, 1) . (isset(explode(' ', trim($counselorName))[1]) ? substr(explode(' ', trim($counselorName))[1], 0, 1) : ''))); ?>
                    </div>
                    <div class="hidden sm:block">
                        <p class="text-xs font-semibold text-stone-900 leading-none"><?php echo e($counselorName); ?></p>
                        <p class="text-[10px] text-stone-500 mt-1">Guidance Counselor</p>
                    </div>
                </div>
            </div>
        </header>

        <div class="p-5 md:p-8 space-y-6 max-w-7xl w-full mx-auto">
            <?php if ($failed): ?>
                <div class="alert error">Could not load your appointment data. Please refresh the page.</div>
            <?php endif; ?>

            <div id="alert-box" class="hidden alert success flex items-center justify-between">
                <div class="flex items-center gap-2"><i data-lucide="check-circle-2" class="w-4 h-4"></i><span id="alert-text">Action completed successfully.</span></div>
                <button onclick="document.getElementById('alert-box').classList.add('hidden')" class="text-[#3d5240]"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="card">
                    <div class="flex items-center justify-between"><small class="font-semibold uppercase tracking-wider">Today's Sessions</small><i data-lucide="calendar-check" class="w-4 h-4 text-[#3d5240]"></i></div>
                    <div class="flex items-baseline justify-between mt-3"><h2 class="text-3xl font-bold"><?php echo count($todays); ?></h2><span class="status-badge status-confirmed">Today</span></div>
                </div>
                <div class="card">
                    <div class="flex items-center justify-between"><small class="font-semibold uppercase tracking-wider">Pending Requests</small><i data-lucide="clock" class="w-4 h-4 text-[#785e13]"></i></div>
                    <div class="flex items-baseline justify-between mt-3"><h2 class="text-3xl font-bold" id="stat-pending"><?php echo $pendingCount; ?></h2><span class="status-badge status-pending">Awaiting review</span></div>
                </div>
                <div class="card">
                    <div class="flex items-center justify-between"><small class="font-semibold uppercase tracking-wider">Active Patients</small><i data-lucide="users" class="w-4 h-4 text-[#3d5240]"></i></div>
                    <div class="flex items-baseline justify-between mt-3"><h2 class="text-3xl font-bold"><?php echo $patientCount; ?></h2><span class="text-[11px] text-[#716b60] font-medium">Unique patients</span></div>
                </div>
            </div>

            <!-- Dashboard Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <section class="lg:col-span-2 card">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-[#e8dfce] gap-3">
                        <div><h2 class="text-lg font-bold">Upcoming Appointments</h2><p class="text-xs text-[#716b60]">Your scheduled counseling sessions and requests</p></div>
                        <div class="flex gap-1 bg-[#ede6d5] p-1 rounded-xl text-xs font-semibold text-[#5c5549]">
                            <button type="button" onclick="filterStatus('all',this)" class="filter-tab active">All</button>
                            <button type="button" onclick="filterStatus('confirmed',this)" class="filter-tab">Confirmed</button>
                            <button type="button" onclick="filterStatus('pending',this)" class="filter-tab">Pending</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto mt-4">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date / Time</th>
                                    <th>Patient / Session</th>
                                    <th>Status</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody id="appointment-rows">
                            <?php if (empty($upcoming)): ?>
                                <tr id="empty-row"><td colspan="4" class="py-8 text-center text-stone-500">No upcoming appointments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($upcoming as $a):
                                    $status = strtolower($a['status'] ?? 'pending');
                                    $patient = $a['patient']['full_name'] ?? 'Unknown patient';
                                    $type = $a['patient']['patient_type'] ?? 'Counseling';
                                    $dateMonth = fmt_dt($a['scheduled_at'], 'M');
                                    $dateDay = fmt_dt($a['scheduled_at'], 'd');
                                    $dateLabel = fmt_dt($a['scheduled_at'], 'M d, Y');
                                    $timeLabel = fmt_dt($a['scheduled_at'], 'g:i A');
                                    $reason = $a['reason'] ?: 'Counseling session';
                                ?>
                                    <tr class="appt-row hover:bg-white/60" data-status="<?php echo e($status); ?>">
                                        <td class="whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <div class="bg-[#e2dac8] text-[#3d3832] font-bold rounded-xl px-3 py-1.5 text-center leading-tight">
                                                    <div class="text-[9px] uppercase tracking-wider text-stone-500"><?php echo e($dateMonth); ?></div>
                                                    <div class="text-sm"><?php echo e($dateDay); ?></div>
                                                </div>
                                                <span class="font-semibold text-stone-800"><?php echo e($timeLabel); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="font-bold text-stone-900"><?php echo e($reason); ?></div>
                                            <div class="text-stone-500 text-[11px] mt-0.5">with <?php echo e($patient); ?> · <?php echo e(ucfirst($type)); ?></div>
                                        </td>
                                        <td class="whitespace-nowrap">
                                            <span class="status-badge status-<?php echo e($status); ?>"><?php echo e(ucfirst($status)); ?></span>
                                        </td>
                                        <td class="text-right whitespace-nowrap">
                                            <button type="button" 
                                                    onclick="showDetails(this)" 
                                                    data-patient="<?php echo e($patient); ?>" 
                                                    data-time="<?php echo e($dateLabel . ', ' . $timeLabel); ?>" 
                                                    data-type="<?php echo e(ucfirst($type)); ?>" 
                                                    data-status="<?php echo e(ucfirst($status)); ?>" 
                                                    data-reason="<?php echo e($reason); ?>" 
                                                    class="btn-sm">
                                                View details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-6 bg-[#3d5240] text-cream-50 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <h3 class="font-bold text-sm">Need to manage a session?</h3>
                            <p class="text-xs text-emerald-100/80 mt-0.5">Review your appointment schedule and requests.</p>
                        </div>
                        <a href="/counselor/appointments.php" class="btn-sm whitespace-nowrap">Manage appointments</a>
                    </div>
                </section>

                <aside class="card h-fit">
                    <div class="flex items-center justify-between border-b border-[#e8dfce] pb-3">
                        <h2 class="font-bold text-stone-900 text-base">Appointment Overview</h2>
                        <i data-lucide="clipboard-list" class="w-4 h-4 text-[#3d5240]"></i>
                    </div>
                    <div class="space-y-3 mt-4">
                        <div class="p-3.5 bg-white/70 rounded-xl border border-[#e8dfce]">
                            <div class="font-bold text-xs">Today's sessions</div>
                            <div class="text-[11px] text-stone-500 mt-1"><?php echo count($todays); ?> appointment(s) scheduled for today</div>
                        </div>
                        <div class="p-3.5 bg-white/70 rounded-xl border border-[#e8dfce]">
                            <div class="font-bold text-xs">Pending requests</div>
                            <div class="text-[11px] text-stone-500 mt-1"><?php echo (int)$pendingCount; ?> request(s) awaiting review</div>
                        </div>
                        <div class="p-4 bg-[#e8efea] rounded-xl border border-[#cbd8ce] text-xs text-[#2c4230]">
                            <div class="font-bold">Counseling reminder</div>
                            <p class="text-[11px] leading-relaxed text-[#3a543f] mt-1">Keep appointment information and student records confidential.</p>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div id="details-modal" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-card space-y-4">
            <div class="flex items-center justify-between border-b border-[#e8dfce] pb-3">
                <h3 class="font-bold text-stone-900 text-base">Appointment Details</h3>
                <button type="button" onclick="toggleModal()" class="text-stone-400 hover:text-stone-700" aria-label="Close"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="space-y-2 text-xs">
                <div class="flex justify-between gap-4 py-1 border-b border-[#eee6d5]"><span class="text-stone-500">Patient:</span><span id="modal-patient" class="font-bold text-right"></span></div>
                <div class="flex justify-between gap-4 py-1 border-b border-[#eee6d5]"><span class="text-stone-500">Scheduled:</span><span id="modal-time" class="font-bold text-right"></span></div>
                <div class="flex justify-between gap-4 py-1 border-b border-[#eee6d5]"><span class="text-stone-500">Patient type:</span><span id="modal-type" class="font-bold text-right"></span></div>
                <div class="flex justify-between gap-4 py-1 border-b border-[#eee6d5]"><span class="text-stone-500">Status:</span><span id="modal-status" class="font-bold text-right text-[#3d5240]"></span></div>
                <div class="pt-2"><p class="text-stone-500 mb-1">Reason / Topic</p><p id="modal-reason" class="font-medium text-stone-800"></p></div>
            </div>
            <button type="button" onclick="toggleModal()" class="btn-success-sm w-full">Close</button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function toggleModal() {
            const modal = document.getElementById('details-modal');
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }

        function showDetails(button) {
            document.getElementById('modal-patient').textContent = button.dataset.patient || 'Unknown';
            document.getElementById('modal-time').textContent = button.dataset.time || '-';
            document.getElementById('modal-type').textContent = button.dataset.type || '-';
            document.getElementById('modal-status').textContent = button.dataset.status || '-';
            document.getElementById('modal-reason').textContent = button.dataset.reason || 'No additional details provided.';
            toggleModal();
        }

        function filterStatus(status, button) {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            button.classList.add('active');
            document.querySelectorAll('.appt-row').forEach(row => {
                row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
            });
        }

        function handleSearch() {
            const query = document.getElementById('search-input').value.toLowerCase().trim();
            document.querySelectorAll('.appt-row').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
            });
        }

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                const modal = document.getElementById('details-modal');
                if (!modal.classList.contains('hidden')) toggleModal();
            }
        });
    </script>
</body>
</html>