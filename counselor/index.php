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
$firstName = explode(' ', trim($counselorName))[0];

page_start('counselor', 'Dashboard', '/counselor/', 'Good morning, ' . $firstName);
?>

<div class="space-y-6 max-w-7xl w-full mx-auto">
    
    <!-- Error Alert if Data Fetch Failed -->
    <?php if ($failed): ?>
    <div class="alert error">Could not load your data. Please refresh.</div>
    <?php endif; ?>

    <!-- Action Alert Banner -->
    <div id="alert-box" class="hidden bg-[#e3eae4] border border-[#b8cac0] text-[#283b2c] px-4 py-3 rounded-2xl flex items-center justify-between text-xs shadow-sm transition">
        <div class="flex items-center gap-2">
            <i data-lucide="check-circle-2" class="w-4 h-4 text-[#3d5240]"></i>
            <span id="alert-text">Action completed successfully.</span>
        </div>
        <button onclick="document.getElementById('alert-box').classList.add('hidden')" class="text-[#3d5240] hover:text-black">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <!-- Stat 1 -->
        <div class="card">
            <div class="flex items-center justify-between">
                <small class="text-[11px] font-semibold text-[#716b60] uppercase tracking-wider">Today's Sessions</small>
                <i data-lucide="calendar-check" class="w-4 h-4 text-[#3d5240]"></i>
            </div>
            <div class="flex items-baseline justify-between mt-3">
                <h2 class="text-3xl font-bold text-[#2c2925]" id="stat-todays"><?php echo count($todays); ?></h2>
                <span class="text-[11px] text-[#3d5240] font-semibold bg-[#e3eae4] px-2 py-0.5 rounded-full">Active</span>
            </div>
        </div>

        <!-- Stat 2 -->
        <div class="card">
            <div class="flex items-center justify-between">
                <small class="text-[11px] font-semibold text-[#716b60] uppercase tracking-wider">Pending Requests</small>
                <i data-lucide="clock" class="w-4 h-4 text-[#785e13]"></i>
            </div>
            <div class="flex items-baseline justify-between mt-3">
                <h2 class="text-3xl font-bold text-[#2c2925]" id="stat-pending"><?php echo $pendingCount; ?></h2>
                <span class="text-[11px] text-[#785e13] font-semibold bg-[#f5eed8] px-2 py-0.5 rounded-full">Awaiting</span>
            </div>
        </div>

        <!-- Stat 3 -->
        <div class="card">
            <div class="flex items-center justify-between">
                <small class="text-[11px] font-semibold text-[#716b60] uppercase tracking-wider">Active Patients</small>
                <i data-lucide="users" class="w-4 h-4 text-[#3d5240]"></i>
            </div>
            <div class="flex items-baseline justify-between mt-3">
                <h2 class="text-3xl font-bold text-[#2c2925]"><?php echo $patientCount; ?></h2>
                <span class="text-[11px] text-[#716b60] font-medium">Students & Staff</span>
            </div>
        </div>
    </div>

    <!-- Two Column Main Content Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Appointments Table Card (2 Columns) -->
        <div class="lg:col-span-2 card flex flex-col justify-between">
            <div>
                <!-- Card Title & Filters -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-[#e8dfce] gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-[#2c2925]">All Appointments & Schedule</h2>
                        <p class="text-xs text-[#716b60]">Manage counseling sessions and review statuses</p>
                    </div>
                    
                    <!-- Filter Pills -->
                    <div class="flex gap-1 bg-[#ede6d5] p-1 rounded-xl text-xs font-semibold text-[#5c5549]">
                        <button onclick="filterStatus('all')" class="filter-tab active px-3 py-1 rounded-lg bg-[#faf7f0] text-stone-900 shadow-sm transition">All</button>
                        <button onclick="filterStatus('confirmed')" class="filter-tab px-3 py-1 rounded-lg hover:text-stone-900 transition">Confirmed</button>
                        <button onclick="filterStatus('pending')" class="filter-tab px-3 py-1 rounded-lg hover:text-stone-900 transition">Pending</button>
                    </div>
                </div>

                <!-- Schedule Table -->
                <div class="overflow-x-auto mt-4">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Patient / Type</th>
                                <th>Topic / Reason</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="appointment-rows">
                            <?php if (empty($all)): ?>
                                <tr><td colspan="5" class="py-6 text-center text-stone-500 italic">No appointments found.</td></tr>
                            <?php else: foreach ($all as $a): 
                                $timestamp = strtotime($a['scheduled_at']);
                                $month = strtoupper(date('M', $timestamp));
                                $day = date('d', $timestamp);
                                $timeStr = date('g:i A', $timestamp);
                                $patientName = $a['patient']['full_name'] ?? 'Unknown';
                                $patientType = ucfirst($a['patient']['patient_type'] ?? '-');
                                $reason = $a['reason'] ?? '-';
                                $status = strtolower($a['status']);
                            ?>
                                <tr class="appt-row transition hover:bg-white/60" data-status="<?php echo e($status); ?>" data-patient="<?php echo e(strtolower($patientName)); ?>">
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center gap-3">
                                            <div class="bg-[#e2dac8] text-[#3d3832] font-bold rounded-xl px-3 py-1.5 text-center leading-tight">
                                                <div class="text-[9px] uppercase tracking-wider text-stone-500"><?php echo e($month); ?></div>
                                                <div class="text-sm"><?php echo e($day); ?></div>
                                            </div>
                                            <span class="font-semibold text-stone-800"><?php echo e($timeStr); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-bold text-stone-900"><?php echo e($patientName); ?></div>
                                        <div class="text-stone-500 text-[11px] mt-0.5"><?php echo e($patientType); ?></div>
                                    </td>
                                    <td class="text-stone-700">
                                        <?php echo e($reason); ?>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="status <?php echo e($status); ?>">
                                            <?php echo e(ucfirst($status)); ?>
                                        </span>
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <button onclick="showDetails('<?php echo e($patientName); ?>', '<?php echo e("$month $day, $timeStr"); ?>', '<?php echo e($reason); ?>', '<?php echo e(ucfirst($status)); ?>')" class="btn-sm">
                                            Manage
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer prompt card embedded inside schedule -->
            <div class="mt-6 bg-[#3d5240] text-cream-50 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h3 class="font-bold text-sm">Need to schedule an extra session?</h3>
                    <p class="text-xs text-emerald-100/80 mt-0.5">Book a priority consultation or update time blocks.</p>
                </div>
                <button onclick="triggerAlert('Booking request form opened.')" class="bg-white hover:bg-cream-100 text-[#2c3b31] font-semibold px-4 py-2 rounded-xl text-xs whitespace-nowrap transition shadow-sm">
                    Book appointment
                </button>
            </div>
        </div>

        <!-- Right Side Widgets (1 Column) -->
        <div class="space-y-6">
            
            <!-- Latest Results Widget -->
            <div class="card space-y-4">
                <div class="flex items-center justify-between border-b border-[#e8dfce] pb-3">
                    <h2 class="font-bold text-stone-900 text-base">Latest Results</h2>
                    <a href="#" class="text-xs text-[#3d5240] font-bold hover:underline">View all</a>
                </div>

                <div class="space-y-3">
                    <!-- Result Item 1 -->
                    <div class="p-3.5 bg-white/70 rounded-xl border border-[#e8dfce] space-y-1">
                        <div class="font-bold text-xs text-stone-900">Anxiety Screening (GAD-7)</div>
                        <div class="text-[11px] text-stone-500">Mild · Updated Record</div>
                    </div>

                    <!-- Result Item 2 -->
                    <div class="p-3.5 bg-white/70 rounded-xl border border-[#e8dfce] space-y-1">
                        <div class="font-bold text-xs text-stone-900">Career Interest Assessment</div>
                        <div class="text-[11px] text-stone-500">Completed · Student Profile</div>
                    </div>

                    <!-- Recommendation Box -->
                    <div class="p-4 bg-[#e8efea] rounded-xl border border-[#cbd8ce] text-xs text-[#2c4230] space-y-1">
                        <div class="font-bold">Counselor Note</div>
                        <p class="text-[11px] leading-relaxed text-[#3a543f]">Maintain regular check-ins for students with pending follow-ups.</p>
                    </div>
                </div>

                <p class="text-[10px] text-stone-400 text-center italic">Encrypted counselor records & logs.</p>
            </div>

        </div>

    </div>
</div>

<!-- Details Modal -->
<div id="details-modal" class="fixed inset-0 bg-stone-900/40 backdrop-blur-xs z-50 hidden flex items-center justify-center p-4">
    <div class="bg-[#faf7f0] border border-[#e6dec9] rounded-2xl shadow-xl max-w-sm w-full p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-[#e8dfce] pb-3">
            <h3 class="font-bold text-stone-900 text-base">Appointment Details</h3>
            <button onclick="toggleModal('details-modal')" class="text-stone-400 hover:text-stone-700">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        
        <div class="space-y-2 text-xs">
            <div class="flex justify-between py-1 border-b border-[#eee6d5]">
                <span class="text-stone-500">Patient:</span>
                <span id="modal-patient" class="font-bold text-stone-800"></span>
            </div>
            <div class="flex justify-between py-1 border-b border-[#eee6d5]">
                <span class="text-stone-500">Scheduled:</span>
                <span id="modal-time" class="font-bold text-stone-800"></span>
            </div>
            <div class="flex justify-between py-1 border-b border-[#eee6d5]">
                <span class="text-stone-500">Session Topic:</span>
                <span id="modal-type" class="font-bold text-stone-800"></span>
            </div>
            <div class="flex justify-between py-1 border-b border-[#eee6d5]">
                <span class="text-stone-500">Status:</span>
                <span id="modal-status" class="font-bold text-[#3d5240]"></span>
            </div>
        </div>

        <div class="pt-2">
            <button onclick="toggleModal('details-modal'); triggerAlert('Session updated successfully.');" class="w-full bg-[#3d5240] hover:bg-[#2b3b2f] text-white font-semibold py-2.5 rounded-xl text-xs transition">
                Close / Save Changes
            </button>
        </div>
    </div>
</div>

<script>
    function toggleModal(id) {
        document.getElementById(id).classList.toggle('hidden');
    }

    function showDetails(patient, time, type, status) {
        document.getElementById('modal-patient').innerText = patient;
        document.getElementById('modal-time').innerText = time;
        document.getElementById('modal-type').innerText = type;
        document.getElementById('modal-status').innerText = status;
        toggleModal('details-modal');
    }

    function filterStatus(status) {
        const tabs = document.querySelectorAll('.filter-tab');
        tabs.forEach(tab => {
            tab.classList.remove('active', 'bg-[#faf7f0]', 'text-stone-900', 'shadow-sm');
            tab.classList.add('hover:text-stone-900');
        });
        event.target.classList.add('active', 'bg-[#faf7f0]', 'text-stone-900', 'shadow-sm');

        const rows = document.querySelectorAll('.appt-row');
        rows.forEach(row => {
            const rowStatus = row.getAttribute('data-status');
            if (status === 'all' || rowStatus === status || (status === 'confirmed' && (rowStatus === 'approved' || rowStatus === 'confirmed'))) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function triggerAlert(msg) {
        const box = document.getElementById('alert-box');
        document.getElementById('alert-text').innerText = msg;
        box.classList.remove('hidden');
    }
</script>

<?php 
page_end(); 
?>