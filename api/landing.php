<?php
require_once __DIR__ . '/../includes/auth.php';

// Check if user is already authenticated
$token = get_user_token();
$currentUser = null;
$dashboardUrl = null;

if ($token) {
    $sbUser = sb_get_user($token);
    if ($sbUser && !empty($sbUser['id'])) {
        $profile = get_user_profile($sbUser['id'], $token);
        if ($profile && ($profile['is_active'] ?? true)) {
            $currentUser = $profile;
            $dashboardUrl = home_for_role($profile['role'] ?? 'patient');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SerenityCare - Student Guidance &amp; Mental Wellness Portal</title>
    <script>
    (function(){
        var t = localStorage.getItem('serenity_theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="/css/style.css">
    <script src="/js/serenity.js" defer></script>
</head>
<body class="landing-body">

    <!-- Top Navigation -->
    <header class="landing-nav-wrapper">
        <nav class="landing-nav">
            <a href="/" class="landing-brand">
                <span class="brand-mark">S</span>
                <span>SerenityCare</span>
            </a>

            <div class="landing-links">
                <a href="#about" class="landing-link">About</a>
                <a href="#services" class="landing-link">Services</a>
                <a href="#hiring" class="landing-link">Counselor Openings</a>
                <a href="#crisis" class="landing-link">Crisis Helplines</a>
            </div>

            <div class="landing-actions">
                <button type="button" class="theme-toggle-btn" data-theme-toggle title="Toggle Dark/Light Mode" aria-label="Toggle Dark/Light Mode">
                    <svg class="icon icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    <svg class="icon icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>

                <?php if ($currentUser && $dashboardUrl): ?>
                    <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn-primary">
                        Go to My Portal &rarr;
                    </a>
                <?php else: ?>
                    <a href="/login.php" class="btn-secondary" style="padding: 9px 18px; font-size: 14px;">Sign In</a>
                    <a href="/register.php" class="btn-primary" style="padding: 9px 18px; font-size: 14px;">Student Sign Up</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main>
        <!-- Hero Section -->
        <section class="landing-hero">
            <div class="landing-container">
                <div class="hero-grid">
                    <div>
                        <div class="hero-pill">
                            <span class="hero-pill-dot"></span>
                            <span>Confidential • Compassionate • Student-First</span>
                        </div>
                        <h1 class="hero-title">
                            Your Safe Space for <span>Guidance</span>, Healing &amp; Personal Growth.
                        </h1>
                        <p class="hero-desc">
                            SerenityCare connects students with licensed guidance counselors in a private, supportive setting. Book sessions around your schedule, receive confidential care, and take control of your mental wellness.
                        </p>
                        <div class="hero-btns">
                            <?php if ($currentUser && $dashboardUrl): ?>
                                <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn-primary" style="font-size: 16px; padding: 13px 26px;">
                                    Open Student Dashboard &rarr;
                                </a>
                            <?php else: ?>
                                <a href="/register.php" class="btn-primary" style="font-size: 16px; padding: 13px 26px;">
                                    Sign Up as a Student
                                </a>
                                <a href="/login.php" class="btn-secondary" style="font-size: 16px; padding: 13px 26px;">
                                    Sign In to Portal
                                </a>
                            <?php endif; ?>
                            <a href="#services" class="btn-light" style="font-size: 15px; padding: 13px 20px;">
                                Explore Services
                            </a>
                        </div>
                        <div class="hero-stats-row">
                            <div class="hero-stat-item">
                                <strong>100%</strong>
                                <span>Confidential Records</span>
                            </div>
                            <div class="hero-stat-item">
                                <strong>Licensed</strong>
                                <span>Guidance Counselors</span>
                            </div>
                            <div class="hero-stat-item">
                                <strong>Always Free</strong>
                                <span>For Enrolled Students</span>
                            </div>
                        </div>
                    </div>

                    <!-- Hero Visual Card -->
                    <div class="hero-showcase">
                        <div class="showcase-header">
                            <div>
                                <h3 style="font-size: 18px; margin: 0; color: var(--ink);">Student Care Overview</h3>
                                <small style="color: var(--muted);">SerenityCare Digital Counseling Suite</small>
                            </div>
                            <span class="showcase-badge">Live Counseling</span>
                        </div>

                        <div class="showcase-appointment-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                <strong>Upcoming Session</strong>
                                <span class="status confirmed">Confirmed</span>
                            </div>
                            <p style="font-size: 13.5px; margin-bottom: 6px; color: var(--ink-secondary);">
                                <strong>Counselor:</strong> Ms. Elena Reyes, RGC
                            </p>
                            <p style="font-size: 13px; margin: 0; color: var(--muted);">
                                📅 Thursday &bull; 10:00 AM &bull; Private Guidance Room 204
                            </p>
                        </div>

                        <div class="showcase-features-list">
                            <div class="showcase-feature-item">
                                <span class="showcase-feature-icon">&#10003;</span>
                                <span>Encrypted notes shared only between you and your counselor</span>
                            </div>
                            <div class="showcase-feature-item">
                                <span class="showcase-feature-icon">&#10003;</span>
                                <span>Easy online scheduling matching your free periods</span>
                            </div>
                            <div class="showcase-feature-item">
                                <span class="showcase-feature-icon">&#10003;</span>
                                <span>Comprehensive academic stress and wellness support</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Services Section -->
        <section id="services" class="landing-section" style="background: var(--surface);">
            <div class="landing-container">
                <div class="section-header">
                    <h2>Everything You Need to Feel Supported</h2>
                    <p>SerenityCare was built to remove the barrier between students and mental health care, ensuring an empathetic, stigma-free experience.</p>
                </div>

                <div class="feature-cards-grid">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <h3>1-on-1 Guidance Sessions</h3>
                        <p>Book private appointments with licensed counselors who listen, advise, and guide you through personal and academic challenges.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </div>
                        <h3>Smart Schedule Booking</h3>
                        <p>View real-time availability slots, reserve consultation hours that suit your timetable, and receive automated reminders.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                        </div>
                        <h3>Strict Confidentiality</h3>
                        <p>Your session details, personal records, and notes are safeguarded under student privacy ethics and data protection laws.</p>
                    </div>

                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/></svg>
                        </div>
                        <h3>Shared Summaries &amp; Care</h3>
                        <p>Access counselor-provided recommendations, wellness action plans, and personalized resources directly in your student hub.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Counselor Hiring Section with Post-It Note -->
        <section id="hiring" class="hiring-section-wrapper">
            <div class="landing-container">
                <div class="hiring-flex">
                    <div class="hiring-info-col">
                        <div class="hero-pill" style="margin-bottom:14px;">
                            <span>Counselor Careers</span>
                        </div>
                        <h2>Make a Lasting Difference in Student Lives</h2>
                        <p>
                            Are you an empathetic, licensed mental health professional? SerenityCare empowers counselors with a seamless digital clinic, flexible scheduling, and intuitive case documentation so you can focus on what matters most: student well-being.
                        </p>
                        <p style="color: var(--muted); font-size: 14.5px;">
                            We welcome registered guidance counselors (RGC), counseling interns, and psychologists passionate about supporting adolescents and young adults.
                        </p>
                    </div>

                    <!-- Tilted Authentic Post-It Note -->
                    <div class="postit-container">
                        <div class="postit-card">
                            <div class="postit-pin" title="Pinned Notice"></div>
                            <span class="postit-badge">📌 We're Hiring!</span>
                            <h3 class="postit-title">Guidance Counselor Positions Open</h3>
                            <p class="postit-desc">
                                Join our dedicated guidance and student care team! Help nurture resilient, emotionally healthy students.
                            </p>
                            <ul class="postit-perks">
                                <li>Flexible online &amp; on-campus consultation hours</li>
                                <li>Streamlined, paperless appointment management</li>
                                <li>Supportive, multidisciplinary care environment</li>
                                <li>Direct impact on youth mental health &amp; growth</li>
                            </ul>
                            <a href="mailto:careers@serenitycare.edu?subject=Application%20for%20Guidance%20Counselor%20Position" class="postit-btn">
                                ✉️ Inquire / Send Resume
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Crisis & Immediate Support Bar -->
        <section id="crisis" class="landing-section" style="padding-top: 30px;">
            <div class="landing-container">
                <div class="crisis-strip">
                    <div class="crisis-strip-left">
                        <h3>In Need of Urgent Help? 24/7 Crisis Support is Available.</h3>
                        <p>If you or someone you know is in immediate emotional distress or crisis, please reach out now. You are never alone.</p>
                    </div>
                    <div class="crisis-numbers">
                        <a href="tel:1553" class="crisis-chip">
                            📞 NCMH Hotline: 1553
                        </a>
                        <a href="tel:09178998727" class="crisis-chip">
                            📱 0917-899-USAP (8727)
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="landing-footer">
        <div class="landing-container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="brand-mark">S</span>
                        <h3 style="font-size: 18px; margin: 0; color: var(--ink);">SerenityCare</h3>
                    </div>
                    <p>A safe, compassionate digital guidance counseling platform designed to support student mental wellness, academic focus, and personal growth.</p>
                </div>

                <div class="footer-col">
                    <h4>Student Portal</h4>
                    <ul>
                        <li><a href="/register.php">Create Student Account</a></li>
                        <li><a href="/login.php">Student Sign In</a></li>
                        <li><a href="/patient/appointment.php">Book Appointment</a></li>
                        <li><a href="/patient/settings.php">Student Privacy Settings</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Staff &amp; Counselors</h4>
                    <ul>
                        <li><a href="/login.php">Staff / Counselor Portal Login</a></li>
                        <li><a href="#hiring">Counselor Career Openings</a></li>
                        <li><a href="/admin/">Administrative Dashboard</a></li>
                        <li><a href="mailto:guidance@serenitycare.edu">Contact Guidance Office</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div>&copy; <?php echo date('Y'); ?> SerenityCare Guidance &amp; Counseling Services. All rights reserved.</div>
                <div>Strictly confidential under Student Mental Health &amp; Privacy Acts.</div>
            </div>
        </div>
    </footer>

</body>
</html>
