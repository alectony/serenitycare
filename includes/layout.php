<?php
// Shared helpers + page shell for every dashboard page.
date_default_timezone_set('Asia/Manila');

function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function is_uuid($v) {
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
}

function fmt_dt($iso, $format = 'M d, Y g:i A') {
    $t = strtotime((string) $iso);
    return $t ? date($format, $t) : '-';
}

/* ---- CSRF (double-submit cookie; works without PHP sessions) ---- */
function csrf_token() {
    if (empty($_COOKIE['csrf'])) {
        $t = bin2hex(random_bytes(16));
        setcookie('csrf', $t, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
        $_COOKIE['csrf'] = $t;
    }
    return $_COOKIE['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_valid() {
    $c = $_COOKIE['csrf'] ?? '';
    $p = $_POST['csrf'] ?? '';
    return $c !== '' && is_string($p) && hash_equals($c, $p);
}

/* ---- Data helpers ---- */
function get_counselor_patients($uid, $token) {
    $r = sb_request('GET', 'appointments',
        'select=patient_id,patient:profiles!patient_id(id,full_name,email,patient_type,grade_or_dept)'
        . '&counselor_id=eq.' . urlencode($uid), null, $token);
    $out = [];
    if ($r['status'] === 200 && is_array($r['data'])) {
        foreach ($r['data'] as $row) {
            if (!empty($row['patient']['id'])) {
                $out[$row['patient']['id']] = $row['patient'];
            }
        }
    }
    usort($out, fn($a, $b) => strcasecmp($a['full_name'] ?? '', $b['full_name'] ?? ''));
    return $out;
}

/* ---- Page shell ---- */
function nav_for($role) {
    $nav = [
        'counselor' => [
            '/counselor/index.php' => 'Dashboard',
            '/counselor/availability.php' => 'Availability',
            '/counselor/schedule.php' => 'My Appointments',
            '/counselor/patients.php' => 'Patients',
            '/counselor/requests.php' => 'Requests',
            '/counselor/profile.php' => 'My Profile',
        ],
        'patient' => [
            '/patient/' => 'Dashboard',
            '/patient/appointment.php' => 'Appointments',
        ],
        'admin' => [
            '/admin/' => 'Dashboard',
        ],
    ];
    return $nav[$role] ?? [];
}

function page_start($role, $title, $current, $heading = null) {
    csrf_token();
    $heading = $heading ?? $title;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - SerenityCare</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Global CSS Stylesheet -->
    <link rel="stylesheet" href="/public/css/style.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        olive: { 50: '#f4f6f4', 100: '#e3e8e4', 200: '#c5d0c7', 500: '#526e58', 700: '#34493a', 800: '#2b3b2f', 900: '#1d2920' },
                        cream: { 50: '#faf8f5', 100: '#f5f1ea', 200: '#eae2d4', 300: '#ddcfba', 400: '#cdba9f' },
                        taupe: { 500: '#8c8275', 800: '#3d3832' }
                    },
                    fontFamily: { serif: ['Georgia', 'Cambria', 'serif'], sans: ['Inter', 'system-ui', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen flex flex-col md:flex-row antialiased select-none" style="background-color: #f3efe6; color: #2c2925;">

    <!-- Sidebar Navigation -->
    <aside class="w-full md:w-64 bg-gradient-to-b from-[#2e3e31] to-[#1c291f] text-[#d1cbd3] flex flex-col justify-between shrink-0 border-r border-emerald-950/20 min-h-screen sticky top-0">
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
                <?php foreach (nav_for($role) as $href => $label): 
                    $isActive = ($href === $current);
                ?>
                    <a href="<?php echo e($href); ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition <?php echo $isActive ? 'bg-white/10 text-white border-l-4 border-[#8ba98f]' : 'text-stone-300 hover:text-white hover:bg-white/5'; ?>">
                        <i data-lucide="<?php 
                            echo match($label) {
                                'Dashboard' => 'layout-dashboard',
                                'Availability' => 'calendar-plus',
                                'My Appointments' => 'calendar',
                                'Patients' => 'users',
                                'Requests' => 'bell',
                                'My Profile' => 'user',
                                default => 'circle'
                            };
                        ?>" class="w-4 h-4 <?php echo $isActive ? 'text-[#a7caa9]' : 'text-stone-400'; ?>"></i>
                        <?php echo e($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- Footer / Sign Out -->
        <div class="p-4 border-t border-white/10">
            <a href="/api/logout.php" class="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-stone-400 hover:text-rose-300 hover:bg-rose-500/10 text-xs font-medium transition">
                <i data-lucide="log-out" class="w-4 h-4"></i>
                Log out
            </a>
        </div>
    </aside>

    <!-- Main Workspace -->
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto" style="padding: 28px 32px 48px;">
        <header class="bg-[#f3efe6] border-b border-[#e5ddcb] pb-6 mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4 sticky top-0 z-10">
            <div>
                <h1 style="font-family: Georgia, serif;" class="text-2xl md:text-3xl font-normal text-[#2c2925]"><?php echo e($heading); ?></h1>
                <p class="text-xs text-[#716b60] mt-1">Counselor care management and student sessions portal.</p>
            </div>
        </header>
<?php
}

function page_end() {
    echo "    </main>\n<script>if(typeof lucide !== 'undefined') lucide.createIcons();</script>\n</body>\n</html>\n";
}