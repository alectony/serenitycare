<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$me = require_role('counselor');
$uid =$me['user']['id'];

$res = sb_request('GET', 'appointments',
    'select=id,patient_id,scheduled_at,status,reason,patient:profiles!patient_id(full_name,patient_type)'
    . '&counselor_id=eq.' . urlencode($uid) . '&order=scheduled_at.asc', null,$me['token']);

$failed = ($res['status'] !== 200 || !is_array($res['data']));$all = $failed ? [] : $res['data'];

$today = date('Y-m-d');$todays = array_values(array_filter($all, fn($a) =>
    date('Y-m-d', strtotime($a['scheduled_at'])) ===$today
    && in_array($a['status'], ['pending', 'confirmed', 'completed'], true)));
$pendingCount = count(array_filter($all, fn($a) =>$a['status'] === 'pending'));
$patientCount = count(array_unique(array_column($all, 'patient_id')));

$counselorName =$me['profile']['full_name'] ?? 'Counselor';
// Extract first name for greeting
$firstName = explode(' ', trim($counselorName))[0];

page_start('counselor', 'Dashboard', '/counselor/', 'Welcome, ' . $counselorName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SerenityCare - Counselor Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        olive: {
                            50: '#f4f6f4',
                            100: '#e3e8e4',
                            200: '#c5d0c7',
                            500: '#526e58',
                            700: '#34493a',
                            800: '#2b3b2f',
                            900: '#1d2920',
                        },
                        cream: {
                            50: '#faf8f5',
                            100: '#f5f1ea',
                            200: '#eae2d4',
                            300: '#ddcfba',
                            400: '#cdba9f',
                        },
                        taupe: {
                            500: '#8c8275',
                            800: '#3d3832',
                        }
                    },
                    fontFamily: {
                        serif: ['Georgia', 'Cambria', 'serif'],
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600&family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            background-color: #f3efe6;
            color: #2c2925;
            font-family: 'Inter', sans-serif;
        }

        .serif-heading {
            font-family: 'Georgia', serif;
        }

        .custom-scroll::-webkit-scrollbar {
            width: 5px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: #f3efe6;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #d6ccba;
            border-radius: 4px;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col md:flex-row antialiased select-none">

    <!-- Sidebar Navigation -->
    <aside class="w-full md:w-64 bg-gradient-to-b from-[#2e3e31] to-[#1c291f] text-[#d1cbd3] flex flex-col justify-between shrink-0 border-r border-emerald-950/20">
        <div>
            <!-- Logo Header -->
            <div class="px-6 py-6 flex items-center gap-3 border-b border-white/10">
                <div class="w-10 h-10 rounded-xl bg-[#4a634e] text-white flex items-center justify-center font-bold text-xl shadow-inner">
                    S
                </div>
                <div>
                    <h1 class="font-bold text-white text-lg tracking-tight leading-none">SerenityCare</h1>
                    <span class="text-[10px] text-[#9bb59f] font-semibold tracking-wider uppercase">Counselor Portal</span>
                </div>
            </div>

            <!-- Nav Links -->
            <nav class="px-3 py-6 space-y-1">
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white/10 text-white font-medium text-sm transition border-l-4 border-[#8ba98f]">
                    <i data-lucide="layout-dashboard" class="w-4 h-4 text-[#a7caa9]"></i>
                    Dashboard
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition">
                    <i data-lucide="calendar-plus" class="w-4 h-4 text-stone-400"></i>
                    Book Appointment
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition">
                    <i data-lucide="calendar" class="w-4 h-4 text-stone-400"></i>
                    My Appointments
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition">
                    <i data-lucide="activity" class="w-4 h-4 text-stone-400"></i>
                    Diagnosis & Results
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition">
                    <i data-lucide="file-text" class="w-4 h-4 text-stone-400"></i>
                    Session Summaries
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition justify-between">
                    <div class="flex items-center gap-3">
                        <i data-lucide="bell" class="w-4 h-4 text-stone-400"></i>
                        Notifications
                    </div>
                    <span class="bg-[#785e13]/50 text-amber-200 text-[10px] px-2 py-0.5 rounded-full font-bold"><?php echo $pendingCount; ?></span>
                </a>
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-stone-300 hover:text-white hover:bg-white/5 font-medium text-sm transition">
                    <i data-lucide="user" class="w-4 h-4 text-stone-400"></i>
                    My Profile
                </a>
            </nav>
        </div>

        <!-- Footer / Sign Out -->
        <div class="p-4 border-t border-white/10">
            <a href="/logout.php" class="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-stone-400 hover:text-rose-300 hover:bg-rose-500/10 text-xs font-medium transition">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                Log out
            </a>
        </div>
    </aside>

    <!-- Main Workspace -->
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto custom-scroll">
        <!-- Top Bar Header -->
        <header class="bg-[#f3efe6] border-b border-[#e5ddcb] px-8 py-6 flex flex-col md:flex-row md:items-center justify-between gap-4 sticky top-0 z-10">
            <div>
                <h1 class="serif-heading text-2xl md:text-3xl font-normal text-[#2c2925]">Good morning, <?php echo e($firstName); ?></h1>
                <p class="text-xs text-[#716b60] mt-1">Here is a summary of your counseling care and upcoming student sessions.</p>
            </div>

            <!-- Header Utility Actions -->
            <div class="flex items-center gap-4">
                <!-- Search Box -->
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"></i>
                    <input type="text" id="search-input" onkeyup="handleSearch()" placeholder="Search student or appointment..." class="pl-9 pr-4 py-2 bg-[#faf7f0] text-xs text-stone-800 rounded-xl border border-[#e2d8c3] focus:outline-none focus:border-[#4a634e] w-56 md:w-64 transition shadow-sm">
                </div>

                <!-- User Profile Dropdown / Avatar -->
                <div class="flex items-center gap-3 pl-3 border-l border-[#e2d8c3]">
                    <div class="w-9 h-9 rounded-full bg-[#3d5240] text-cream-100 flex items-center justify-center font-bold text-xs shadow-sm">
                        <?php 
                        $initials = '';
                        foreach (explode(' ', $counselorName) as$part) {
                            $initials .= mb_substr($part, 0, 1);
                        }
                        echo e(mb_strtoupper(mb_substr($initials, 0, 2)));
                        ?>
                    </div>
                    <div class="hidden sm:block">
                        <p class="text-xs font-semibold text-stone-900 leading-none"><?php echo e($counselorName); ?></p>
                        <p class="text-[10px] text-stone-500 mt-0.5">Guidance Counselor</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Body Grid Container -->
        <div class="p-8 space-y-6 max-w-7xl w-full mx-auto">
            
            <!-- Error Alert if Data Fetch Failed -->
            <?php if ($failed): ?>
            <div class="bg-rose-100 border border-rose-300 text-rose-800 px-4 py-3 rounded-2xl flex items-center justify-between text-xs shadow-sm">
                <div class="flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i>
                    <span>Could not load your data. Please refresh.</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Alert Banner (JS triggered) -->
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
                <div class="bg-[#faf7f0] border border-[#e6dec9] rounded-2xl p-5 shadow-sm hover:border-[#cdba9f] transition">
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
                <div class="bg-[#faf7f0] border border-[#e6dec9] rounded-2xl p-5 shadow-sm hover:border-[#cdba9f] transition">
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
                <div class="bg-[#faf7f0] border border-[#e6dec9] rounded-2xl p-5 shadow-sm hover:border-[#cdba9f] transition">
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
                
                <!-- Appointments Table (2 Columns) -->
                <div class="lg:col-span-2 bg-[#faf7f0] border border-[#e6dec9] rounded-2xl p-6 shadow-sm flex flex-col justify-between">
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
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="text-[#716b60] uppercase text-[10px] font-bold tracking-wider border-b border-[#e8dfce]">
                                        <th class="py-3 px-2">Date / Time</th>
                                        <th class="py-3 px-2">Patient / Type</th>
                                        <th class="py-3 px-2">Topic / Reason</th>
                                        <th class="py-3 px-2">Status</th>
                                        <th class="py-3 px-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="appointment-rows" class="divide-y divide-[#eee6d5]">
                                    <?php if (empty($all)): ?>
                                        <tr><td colspan="5" class="py-6 text-center text-stone-500 italic">No appointments found.</td></tr>
                                    <?php else: foreach ($all as$a): 
                                        $timestamp = strtotime($a['scheduled_at']);
                                        $month = strtoupper(date('M',$timestamp));
                                        $day = date('d',$timestamp);
                                        $timeStr = date('g:i A',$timestamp);
                                        $patientName =$a['patient']['full_name'] ?? 'Unknown';
                                        $patientType = ucfirst($a['patient']['patient_type'] ?? '-');
                                        $reason =$a['reason'] ?? '-';
                                        $status = strtolower($a['status']);
                                        
                                        // Badge styling mapping
                                        $badgeClass = match($status) {
                                            'confirmed', 'approved' => 'bg-[#dbe3dc] text-[#2c4230]',
                                            'pending' => 'bg-[#f5eed8] text-[#785e13]',
                                            'completed' => 'bg-stone-200 text-stone-700',
                                            default => 'bg-stone-100 text-stone-600'
                                        };
                                    ?>
                                        <tr class="appt-row transition hover:bg-white/60" data-status="<?php echo e($status); ?>" data-patient="<?php echo e(strtolower($patientName)); ?>">
                                            <td class="py-4 px-2 whitespace-nowrap">
                                                <div class="flex items-center gap-3">
                                                    <div class="bg-[#e2dac8] text-[#3d3832] font-bold rounded-xl px-3 py-1.5 text-center leading-tight">
                                                        <div class="text-[9px] uppercase tracking-wider text-stone-500"><?php echo e($month); ?></div>
                                                        <div class="text-sm"><?php echo e($day); ?></div>
                                                    </div>
                                                    <span class="font-semibold text-stone-800"><?php echo e($timeStr); ?></span>
                                                </div>
                                            </td>
                                            <td class="py-4 px-2">
                                                <div class="font-bold text-stone-900"><?php echo e($patientName); ?></div>
                                                <div class="text-stone-500 text-[11px] mt-0.5"><?php echo e($patientType); ?></div>
                                            </td>
                                            <td class="py-4 px-2 text-stone-700">
                                                <?php echo e($reason); ?>
                                            </td>
                                            <td class="py-4 px-2 whitespace-nowrap">
                                                <span class="status-badge inline-block <?php echo $badgeClass; ?> font-semibold px-3 py-1 rounded-full text-[11px]">
                                                    <?php echo e(ucfirst($status)); ?>
                                                </span>
                                            </td>
                                            <td class="py-4 px-2 text-right whitespace-nowrap">
                                                <button onclick="showDetails('<?php echo e($patientName); ?>', '<?php echo e("$month $day,$timeStr"); ?>', '<?php echo e($reason); ?>', '<?php echo e(ucfirst($status)); ?>')" class="bg-white border border-[#d8ceb9] hover:bg-[#f3efe6] text-stone-700 font-semibold px-3 py-1.5 rounded-xl transition text-[11px]">
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
                    <div class="bg-[#faf7f0] border border-[#e6dec9] rounded-2xl p-6 shadow-sm space-y-4">
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
    </main>

    <!-- Details Modal -->
    <div id="details-modal" class="fixed inset-0