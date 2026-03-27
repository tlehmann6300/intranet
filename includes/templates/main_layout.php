<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../handlers/AuthHandler.php';
require_once __DIR__ . '/../handlers/CSRFHandler.php';
require_once __DIR__ . '/../models/User.php';

// DEBUG: Uncomment to force role for testing
// $_SESSION['user_role'] = 'vorstand_finanzen';

// Enforce onboarding: redirect non-onboarded users to the onboarding page (first login only)
if (Auth::check() && isset($_SESSION['is_onboarded']) && $_SESSION['is_onboarded'] === false) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    if ($currentPage !== 'onboarding.php' && $currentPage !== 'logout.php') {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $baseUrl . '/pages/auth/onboarding.php');
        exit;
    }
}

// Check if profile is incomplete and redirect to profile page (unless already on profile page)
if (Auth::check() && isset($_SESSION['profile_incomplete']) && $_SESSION['profile_incomplete'] === true) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    // Allow access only to profile.php and logout
    if ($currentPage !== 'profile.php' && $currentPage !== 'logout.php') {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $baseUrl . '/pages/auth/profile.php');
        exit;
    }
}

$_themeCssVersion = filemtime(__DIR__ . '/../../assets/css/theme.css');
$_tailwindCssVersion = filemtime(__DIR__ . '/../../assets/css/tailwind.css');

/**
 * Check if the given navigation path matches the current request URI.
 *
 * Only the path component of REQUEST_URI is used (query strings and fragments
 * are intentionally ignored to prevent false positives).
 *
 * Dashboard-like paths (/ and /index.php) must not be considered active
 * when the user is inside the admin area (i.e. the URI contains /admin).
 *
 * @param string $path  The path fragment to check against REQUEST_URI.
 * @return bool         True when the current page matches the given path.
 */
function is_nav_active(string $path): bool {
    // Use only the path component to avoid query-string interference
    $uri = (string)(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
    // Dashboard paths must not match when the admin area is active
    if ($path === '/' || $path === '/index.php') {
        return strpos($uri, $path) !== false && strpos($uri, '/admin') === false;
    }
    return strpos($uri, $path) !== false;
}

// Ensure $currentUser is defined for the body data-user-theme attribute,
// even on pages that don't set it before including this layout.
if (!isset($currentUser)) {
    $currentUser = Auth::user();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#0066b3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?php echo $title ?? 'IBC Intranet'; ?></title>
    <?php if (!empty($og_title)): ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($og_title); ?>">
    <meta property="og:type" content="<?php echo htmlspecialchars($og_type ?? 'website'); ?>">
    <?php if (!empty($og_url)): ?>
    <meta property="og:url" content="<?php echo htmlspecialchars($og_url); ?>">
    <?php endif; ?>
    <?php if (!empty($og_description)): ?>
    <meta property="og:description" content="<?php echo htmlspecialchars($og_description); ?>">
    <?php endif; ?>
    <?php if (!empty($og_image)): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($og_image); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo !empty($og_image) ? 'summary_large_image' : 'summary'; ?>">
    <?php endif; ?>
    <link rel="icon" type="image/webp" href="<?php echo asset('assets/img/cropped_maskottchen_32x32.webp'); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset('assets/img/cropped_maskottchen_180x180.webp'); ?>">
    <link rel="manifest" href="<?php echo asset('manifest.json'); ?>">
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="//cdn-uicons.flaticon.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <link rel="preload" href="<?php echo asset('assets/css/theme.css') . '?v=' . $_themeCssVersion; ?>" as="style">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css') . '?v=' . $_themeCssVersion; ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/tailwind.css') . '?v=' . $_tailwindCssVersion; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-rounded/css/uicons-regular-rounded.css">
    <style>
        /* Mobile menu button accessibility */
        #mobile-menu-btn {
            border: none;
            outline: none;
        }
        #mobile-menu-btn:focus-visible {
            outline: 2px solid rgba(255,255,255,0.8);
            outline-offset: 2px;
        }

        /* Mobile view improvements */
        @media (max-width: 767px) {
            .sidebar .sidebar-scroll {
                padding-bottom: 2rem !important;
            }

            /* Better logo sizing on mobile */
            .sidebar img[alt="IBC Logo"] {
                max-width: 90% !important;
                margin: 0 auto !important;
            }

            /* Fix text overflow in cards */
            .card p, .card div, .card span {
                word-wrap: break-word;
                overflow-wrap: break-word;
                hyphens: auto;
            }

            /* Prevent horizontal overflow on tables */
            table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 8px;
            }
            table thead { display: table-header-group; }
            table tbody { display: table-row-group; }
            th, td { white-space: nowrap; }

            /* Better form inputs on mobile (prevents iOS zoom-in) */
            form input, form select, form textarea {
                font-size: 16px;
                padding: 0.875rem !important;
                border-radius: 10px !important;
            }

            /* Better image scaling on mobile */
            img:not([class*="w-"]) { max-width: 100%; height: auto; }

            /* Improved stat cards on mobile */
            .stat-icon { width: 48px !important; height: 48px !important; font-size: 1.25rem !important; }

            /* Better badge sizing (exclude sidebar-nav-badge which has its own sizing) */
            .badge:not(.sidebar-nav-badge) { padding: 0.375rem 0.75rem !important; font-size: 0.875rem !important; }
        }

        /* Very narrow screens (under 480px): force single column for ALL grids including 2-column */
        @media (max-width: 479px) {
            .grid:not(.grid-no-stack):not(.grid-cols-1) {
                grid-template-columns: 1fr !important;
                gap: 1.5rem !important;
            }
        }

        /* Extra small screens (below Tailwind sm: breakpoint): stack 3+ column grids to single column */
        @media (max-width: 639px) {
            .grid:not(.grid-no-stack):not(.grid-cols-1):not(.grid-cols-2) {
                grid-template-columns: 1fr !important;
                gap: 1.5rem !important;
            }
        }

        /* Tablet view improvements */
        @media (min-width: 768px) and (max-width: 1024px) {
            /* 2-column grid on tablets */
            .grid:not(.grid-no-stack):not(.grid-cols-1) {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }

        /* Desktop and larger */
        @media (min-width: 1025px) {
            .container { max-width: 1400px; margin: 0 auto; }
        }

        /* Extra large screens */
        @media (min-width: 1536px) {
            .container { max-width: 1600px; }
        }

        /* Landscape mobile optimization */
        @media (max-height: 500px) and (orientation: landscape) and (max-width: 767px) {
            .sidebar { width: 14rem !important; }
            .sidebar nav a { padding: 0.5rem 1rem !important; font-size: 0.875rem !important; }
        }

        /* High DPI displays */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
            .card { border-width: 0.5px; }
        }

        /* Touch device optimizations: larger targets, better tap feedback */
        @media (hover: none) and (pointer: coarse) {
            a, button, input[type="submit"], input[type="button"] { min-height: 44px; min-width: 44px; }
            a:active, button:active { opacity: 0.7; transform: scale(0.98); transition: all 0.1s ease; }
        }

        /* Ensure long text doesn't overflow */
        p, span, li, h1, h2, h3 { overflow-wrap: break-word; word-break: break-word; }

        /* ── DVH SUPPORT ────────────────────────────────────────── */
        @supports (height: 100dvh) {
            .sidebar {
                height: 100dvh;
            }
        }

        /* ── SIDEBAR CLOSING ANIMATION ───────────────────────────── */
        .sidebar {
            transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1), visibility 0s 0.3s;
            visibility: hidden;
        }
        .sidebar.open {
            visibility: visible;
            transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1), visibility 0s 0s;
        }
        @media (min-width: 768px) {
            .sidebar {
                visibility: visible;
                transition: none;
            }
        }

        /* ── SIDEBAR OVERLAY Z-INDEX ─────────────────────────────── */
        /* Overlay sits above page content but below the sidebar so that
           clicks on the dimmed backdrop reliably reach the overlay itself. */
        @media (max-width: 767px) {
            .sidebar-overlay {
                z-index: 1040; /* above content, below sidebar (1050) */
                pointer-events: none; /* inactive state: pass clicks through */
            }
            .sidebar-overlay.active {
                pointer-events: auto; /* active state: capture clicks to close sidebar */
            }
            .sidebar.open {
                z-index: 1050; /* above overlay (1040) */
            }
        }

        /* ── MOBILE: BODY SCROLL LOCK FIX ───────────────────────── */
        body.sidebar-open {
            overflow: hidden !important;
            position: fixed;
            width: 100%;
        }

        /* ── IMPROVED SELECTION HIGHLIGHT ───────────────────────── */
        ::selection {
            background-color: rgba(0, 102, 179, 0.2);
            color: inherit;
        }
        .dark-mode ::selection {
            background-color: rgba(51, 133, 196, 0.3);
        }

        /* ── IMPROVE MAIN CONTENT AREA PADDING ──────────────────── */
        /* On mobile, padding-top accounts for the fixed topbar height.
           Uses env(safe-area-inset-top) so content is never hidden behind the
           Dynamic Island / notch. The JS in navbar-scroll.js updates
           --mobile-menu-height from the actual measured height of #mobile-header;
           the calc() below is the pure-CSS fallback.
           The transition uses the same duration/easing as the sidebar so the
           push-down animation of #main-content stays perfectly in sync. */
        @media (max-width: 767px) {
            #main-content {
                padding-top: var(--mobile-menu-height, calc(var(--topbar-height) + env(safe-area-inset-top, 0px))) !important;
                transition: padding-top 0.3s cubic-bezier(0.32, 0.72, 0, 1);
            }
        }

        /* ── PAGE ENTRANCE ANIMATION ─────────────────────────────── */
        @keyframes pageEntranceFade {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: no-preference) {
            #main-content > *:not(.fixed):not([style*="position: fixed"]):not([style*="position:fixed"]) {
                animation: pageEntranceFade 0.35s cubic-bezier(0.22, 0.61, 0.36, 1) both;
            }
            #main-content > *:nth-child(2) { animation-delay: 0.04s; }
            #main-content > *:nth-child(3) { animation-delay: 0.08s; }
            #main-content > *:nth-child(4) { animation-delay: 0.12s; }
            #main-content > *:nth-child(5) { animation-delay: 0.16s; }
        }

        /* ── SPRING-LIKE CARD TAP ANIMATION (MOBILE) ─────────────── */
        @media (hover: none) and (pointer: coarse) {
            .card, .dash-stat-card, .dash-event-card, .dash-blog-card,
            .dash-helper-card, .dash-poll-card {
                transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1),
                            box-shadow 0.15s ease,
                            border-color 0.15s ease !important;
            }
            .card:active, .dash-stat-card:active, .dash-event-card:active,
            .dash-blog-card:active, .dash-helper-card:active {
                transform: scale(0.975) !important;
                transition: transform 0.08s ease !important;
            }
        }

        /* ── IMPROVED MOBILE TOPBAR SAFE AREA ───────────────────── */
        /* Ensure topbar correctly accommodates Dynamic Island and notch */
        #mobile-header {
            min-height: calc(var(--topbar-height) + env(safe-area-inset-top, 0px));
            padding-top: env(safe-area-inset-top, 0px);
        }

        /* ── SMOOTH SIDEBAR OVERLAY BLUR ─────────────────────────── */
        @supports (backdrop-filter: blur(4px)) {
            .sidebar-overlay.active {
                backdrop-filter: blur(4px) saturate(1.2);
                -webkit-backdrop-filter: blur(4px) saturate(1.2);
                background: rgba(0, 0, 0, 0.45) !important;
            }
        }

        /* ── ROLE BADGE ──────────────────────────────────────────── */
        .role-badge {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            line-height: 1;
            white-space: nowrap;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.30);
        }
        .role-badge i {
            font-size: 9px;
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-800 dark:bg-slate-900 dark:text-slate-200 overflow-x-hidden" data-user-theme="<?php echo htmlspecialchars($currentUser['theme_preference'] ?? 'auto'); ?>">
    <script>
        // Apply theme immediately to prevent flash of unstyled content (FOUC)
        (function() {
            const userTheme = document.body.getAttribute('data-user-theme') || 'auto';
            const savedTheme = localStorage.getItem('theme') || userTheme;
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.classList.add('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.colorScheme = 'dark';
            } else if (savedTheme === 'light') {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.classList.remove('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.style.colorScheme = 'light';
            } else { // auto
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.body.classList.add('dark-mode', 'dark');
                    document.documentElement.classList.add('dark-mode', 'dark');
                    document.documentElement.setAttribute('data-theme', 'dark');
                    document.documentElement.style.colorScheme = 'dark';
                }
            }
        })();
    </script>
    <!-- Mobile Menu Overlay -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <!-- Mobile Header Bar (visible on small screens only) -->
    <header id="mobile-header" class="mobile-topbar md:hidden flex items-center px-3" aria-label="Mobile-Navigation">
        <button id="mobile-menu-btn" class="mobile-topbar-btn block md:hidden shrink-0" aria-label="Menü öffnen" aria-expanded="false" aria-controls="sidebar">
            <svg class="w-5 h-5 text-white" id="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path id="menu-icon-top" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16" class="transition-all duration-300"></path>
                <path id="menu-icon-middle" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 12h16" class="transition-all duration-300"></path>
                <path id="menu-icon-bottom" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 18h16" class="transition-all duration-300"></path>
            </svg>
        </button>
        <div class="flex-1 flex flex-col items-center justify-center select-none">
            <span class="text-white font-bold text-sm tracking-wide leading-tight">IBC Intranet</span>
            <span class="mobile-topbar-page-title text-white/60 text-[11px] font-medium leading-tight"><?php echo htmlspecialchars($title ?? 'Dashboard'); ?></span>
        </div>
        <div class="flex items-center gap-1 shrink-0">
            <button id="mobile-theme-toggle" class="mobile-topbar-btn" aria-label="Zwischen hellem und dunklem Modus wechseln">
                <i id="mobile-theme-icon" class="fas fa-moon text-white text-base" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed left-0 top-0 h-screen w-64 md:w-72 transform -translate-x-full md:translate-x-0 transition-transform duration-300 z-40 text-white shadow-2xl flex flex-col" aria-label="Seitenleiste">
        <?php 
        $currentUser = Auth::user();
        $userRole = $currentUser['role'] ?? '';
        ?>
        <div class="p-5 flex-1 overflow-y-auto sidebar-scroll">
            <!-- IBC Logo in Navbar -->
            <div class="mb-6 px-3 pt-2">
                <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>" alt="IBC Logo" class="w-full h-auto drop-shadow-lg" decoding="async">
            </div>
            
            <nav aria-label="Hauptnavigation">
                <!-- Dashboard (All) -->
                <a href="<?php echo asset('pages/dashboard/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/dashboard/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/dashboard/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-home sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Kommunikation Section -->
                <div class="sidebar-section-label">Kommunikation</div>

                <!-- Newsletter (All authenticated users) -->
                <a href="<?php echo asset('pages/newsletter/index.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/newsletter/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/newsletter/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-envelope-open-text sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Newsletter</span>
                </a>

                <!-- Blog (All) -->
                <a href="<?php echo asset('pages/blog/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/blog/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/blog/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-newspaper sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Blog</span>
                </a>

                <!-- Job- & Praktikumsbörse (All) -->
                <a href="<?php echo asset('pages/jobs/index.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/jobs/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/jobs/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-briefcase sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Job- &amp; Praktikumsbörse</span>
                </a>

                <!-- Community Section -->
                <div class="sidebar-section-label">Community</div>

                <!-- Events (All) -->
                <a href="<?php echo asset('pages/events/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/events/') && !is_nav_active('/events/helpers.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/events/') && !is_nav_active('/events/helpers.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-calendar sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Events</span>
                </a>

                <!-- Helfersystem (All) -->
                <a href="<?php echo asset('pages/events/helpers.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/events/helpers.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/events/helpers.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-hands-helping sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Helfersystem</span>
                </a>

                <!-- Projekte (All) -->
                <a href="<?php echo asset('pages/projects/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/projects/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/projects/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-project-diagram sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Projekte</span>
                </a>

                <!-- Ideenbox (Members, Candidates, Head, Board) -->
                <?php if (Auth::canAccessPage('ideas')): ?>
                <a href="<?php echo asset('pages/ideas/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/ideas/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/ideas/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-lightbulb sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Ideenbox</span>
                </a>
                <?php endif; ?>

                <!-- Daten &amp; Datenbanken Section -->
                <div class="sidebar-section-label">Daten</div>

                <!-- Alumni (All) -->
                <a href="<?php echo asset('pages/alumni/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-user-graduate sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Alumni-Datenbank</span>
                </a>

                <!-- Mitglieder (Board, Head, Member, Candidate) -->
                <?php if (Auth::canAccessPage('members')): ?>
                <a href="<?php echo asset('pages/members/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/members/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/members/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-users sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Mitglieder-Datenbank</span>
                </a>
                <?php endif; ?>

                <!-- Tools &amp; Services Section -->
                <div class="sidebar-section-label">Tools &amp; Services</div>

                <!-- Inventar (All) -->
                <a href="<?php echo asset('pages/inventory/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/inventory/') && !is_nav_active('/my_rentals.php') && !is_nav_active('/checkout.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/inventory/') && !is_nav_active('/my_rentals.php') && !is_nav_active('/checkout.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-box sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Inventar</span>
                </a>

                <!-- Ausleihe (All) -->
                <a href="<?php echo asset('pages/inventory/my_rentals.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/my_rentals.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/my_rentals.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-clipboard-list sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Ausleihe</span>
                </a>

                <!-- Rechnungen (All roles) -->
                <?php if (Auth::canAccessPage('invoices')): ?>
                <a href="<?php echo asset('pages/invoices/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/invoices/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/invoices/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-file-invoice-dollar sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Rechnungen</span>
                </a>
                <?php endif; ?>

                <!-- Schulungsanfrage (Alumni, Alumni-Board) -->
                <?php if (Auth::canAccessPage('training_requests')): ?>
                <a href="<?php echo asset('pages/alumni/requests.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/alumni/requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/alumni/requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-chalkboard-teacher sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Schulungsanfrage</span>
                </a>
                <?php endif; ?>

                <!-- Nützliche Links (Board + Alumni Vorstand + Alumni Finanzprüfer) -->
                <?php if (in_array($userRole, ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz'])): ?>
                <a href="<?php echo asset('pages/links/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/links/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/links/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-link sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Nützliche Links</span>
                </a>
                <?php endif; ?>

                <!-- Shop (All authenticated users) -->
                <a href="<?php echo htmlspecialchars(_env('SHOPLINK', '#')); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="sidebar-nav-item">
                    <i class="fas fa-shopping-cart sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Shop</span>
                </a>

                <!-- Umfragen (Polls - All authenticated users) -->
                <?php if (Auth::canAccessPage('polls')): ?>
                <a href="<?php echo asset('pages/polls/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/polls/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/polls/') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-poll sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Umfragen</span>
                </a>
                <?php endif; ?>

                <!-- Admin Section Divider -->
                <?php if (Auth::canManageUsers() || Auth::isAdmin() || Auth::canApproveReturns()): ?>
                <div class="sidebar-section-label">Administration</div>
                <?php endif; ?>

                <!-- Benutzerverwaltung (All board members who can manage users) -->
                <?php if (Auth::canManageUsers()): ?>
                <a href="<?php echo asset('pages/admin/users.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/users.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/users.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-users-cog sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Benutzerverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Admin Dashboard -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/index.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/index.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-tachometer-alt sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Dashboard</span>
                </a>
                <?php endif; ?>

                <!-- Inventarverwaltung -->
                <?php if (Auth::hasRole(['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter']) || Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/rental_returns.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/rental_returns.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/rental_returns.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-clipboard-check sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Inventarverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Bewerbungsverwaltung (Board only) -->
                <?php if (Auth::isBoard()): ?>
                <a href="<?php echo asset('pages/admin/project_applications.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/project_applications.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/project_applications.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-file-alt sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Bewerbungsverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Alumni-Anfragen (Alumni-Führung + Vorstand) -->
                <?php if (Auth::hasRole(['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'])): ?>
                <a href="<?php echo asset('pages/admin/alumni_requests.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/alumni_requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/alumni_requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-user-graduate sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Alumni-Anfragen</span>
                </a>
                <?php endif; ?>

                <!-- Neue Alumni-Anfragen (Alumni-Führung + Vorstand) -->
                <?php if (Auth::hasRole(['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'])): ?>
                <a href="<?php echo asset('pages/admin/neue_alumni_requests.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/neue_alumni_requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/neue_alumni_requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-user-plus sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Neue Alumni</span>
                </a>
                <?php endif; ?>


                <!-- Systemeinstellungen (Board roles + alumni_vorstand + alumni_finanz) -->
                <?php if (Auth::canAccessSystemSettings()): ?>
                <a href="<?php echo asset('pages/admin/settings.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/settings.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/settings.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-cogs sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Systemeinstellungen</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken Section Divider -->
                <?php if (Auth::isAdmin() || Auth::hasRole(['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter'])): ?>
                <div class="sidebar-section-label">Statistiken</div>
                <?php endif; ?>

                <!-- Event-Statistiken (Admin roles only) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/event_stats.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/event_stats.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/event_stats.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-chart-bar sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Event-Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/stats.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/stats.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/stats.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-chart-pie sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Audit Logs (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/audit.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/audit.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/audit.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-clipboard-list sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Audit Logs</span>
                </a>
                <?php endif; ?>

                <!-- System Health (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/db_maintenance.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/db_maintenance.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/db_maintenance.php') ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-database sidebar-nav-icon" aria-hidden="true"></i>
                    <span>System Health</span>
                </a>
                <?php endif; ?>

            </nav>
        </div>

        <!-- User Profile Section -->
        <div class='sidebar-footer mt-auto pt-2 pb-2 px-3'>
            <?php 
            $currentUser = Auth::user();
            
            // Initialize default values
            $firstname = '';
            $lastname = '';
            $email = '';
            $role = 'User';
            $displayRoles = [];
            
            // Only try to get profile if user is logged in
            if ($currentUser && isset($currentUser['id'])) {
                // Try to get name from alumni_profiles table first
                require_once __DIR__ . '/../models/Alumni.php';
                $profile = Alumni::getProfileByUserId($currentUser['id']);
                
                // Entra photo path and custom avatar info are available directly from $currentUser
                // (Auth::user() fetches these columns via SELECT *).
                // No extra DB query needed here.
                
                // Profile data may be user-edited, so don't transform it
                if ($profile && !empty($profile['first_name'])) {
                    $firstname = $profile['first_name'];
                    $lastname = $profile['last_name'] ?? '';
                } elseif (!empty($currentUser['first_name'])) {
                    $firstname = $currentUser['first_name'];
                    $lastname = $currentUser['last_name'] ?? '';
                }
                
                $email = $currentUser['email'] ?? '';
                $role = $currentUser['role'] ?? 'User';
                
                // Check for Entra roles - priority: entra_roles from user table, then session azure_roles, then fallback to internal role
                $displayRoles = [];
                
                // Debug logging for role determination
                if (!empty($currentUser['entra_roles'])) {
                    error_log("main_layout.php: User " . intval($currentUser['id']) . " has entra_roles in database: " . $currentUser['entra_roles']);
                }
                if (!empty($_SESSION['azure_roles'])) {
                    error_log("main_layout.php: Session azure_roles for user " . intval($currentUser['id']) . ": " . (is_array($_SESSION['azure_roles']) ? json_encode($_SESSION['azure_roles']) : $_SESSION['azure_roles']));
                }
                if (!empty($_SESSION['entra_roles'])) {
                    error_log("main_layout.php: Session entra_roles for user " . intval($currentUser['id']) . ": " . (is_array($_SESSION['entra_roles']) ? json_encode($_SESSION['entra_roles']) : $_SESSION['entra_roles']));
                }
                
                if (!empty($currentUser['entra_roles'])) {
                    // Parse JSON array from database.
                    // entra_roles stores App Role value strings (e.g. ["mitglied"]).
                    // Apply translateAzureRole so they are rendered as human-readable German names.
                    $rolesArray = json_decode($currentUser['entra_roles'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($rolesArray)) {
                        foreach ($rolesArray as $r) {
                            $label = is_array($r) && isset($r['displayName'])
                                ? $r['displayName']
                                : translateAzureRole($r);
                            if (!empty($label)) {
                                $displayRoles[] = $label;
                            }
                        }
                    } else {
                        error_log("Failed to decode entra_roles in main_layout for user ID " . intval($currentUser['id']) . ": " . json_last_error_msg());
                    }
                } elseif (!empty($_SESSION['entra_roles'])) {
                    // Prefer entra_roles from session (groups from Microsoft Graph)
                    if (is_array($_SESSION['entra_roles'])) {
                        // Extract displayName from each group object (groups now contain both id and displayName)
                        $displayRoles = extractGroupDisplayNames($_SESSION['entra_roles']);
                    }
                } elseif (!empty($_SESSION['azure_roles'])) {
                    // Check session variable as alternative (App Roles from JWT)
                    if (is_array($_SESSION['azure_roles'])) {
                        $displayRoles = array_filter(array_map('translateAzureRole', $_SESSION['azure_roles']));
                    } else {
                        // Try to decode if it's JSON string
                        $sessionRoles = json_decode($_SESSION['azure_roles'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($sessionRoles)) {
                            $displayRoles = array_filter(array_map('translateAzureRole', $sessionRoles));
                        }
                    }
                }
                
                // If no Entra roles found, use internal role as fallback
                if (empty($displayRoles)) {
                    $displayRoles = [translateRole($role)];
                }
            }
            
            // Generate initials with proper fallbacks
            if (!empty($firstname) && !empty($lastname)) {
                $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
            } elseif (!empty($firstname)) {
                $initials = strtoupper(substr($firstname, 0, 1));
            } elseif (!empty($lastname)) {
                $initials = strtoupper(substr($lastname, 0, 1));
            } elseif (!empty($email)) {
                $initials = strtoupper(substr($email, 0, 1));
            } else {
                $initials = 'U';
            }

            // Role badge: color-coded by role type for clear visual separation
            $roleBadgeConfig = [
                'admin'             => ['bg' => '#dc2626', 'text' => '#fff'],
                'vorstand_intern'   => ['bg' => '#d97706', 'text' => '#fff'],
                'vorstand_extern'   => ['bg' => '#d97706', 'text' => '#fff'],
                'vorstand_finanzen' => ['bg' => '#d97706', 'text' => '#fff'],
                'alumni_vorstand'   => ['bg' => '#2563eb', 'text' => '#fff'],
                'alumni_finanz'     => ['bg' => '#2563eb', 'text' => '#fff'],
                'alumni'            => ['bg' => '#0891b2', 'text' => '#fff'],
                'ressortleiter'     => ['bg' => '#7c3aed', 'text' => '#fff'],
                'mitglied'          => ['bg' => '#4f46e5', 'text' => '#fff'],
                'anwaerter'         => ['bg' => '#ea580c', 'text' => '#fff'],
                'ehrenmitglied'     => ['bg' => '#be185d', 'text' => '#fff'],
            ];
            $badgeCfg = $roleBadgeConfig[$role] ?? ['bg' => '#374151', 'text' => '#fff'];
            $badgeStyle = 'background:' . htmlspecialchars($badgeCfg['bg']) . '; color:' . htmlspecialchars($badgeCfg['text']) . ';';
            ?>

            <!-- User Info -->
            <div class='flex items-start gap-2 mb-2'>
                <?php
                // Use fetch-profile-photo.php when email is available to serve the live Entra ID
                // photo (cached 24 h). Fall back to the locally stored avatar when no email is set.
                if (!empty($email)) {
                    $sidebarImgSrc = asset('fetch-profile-photo.php') . '?email=' . urlencode($email);
                } else {
                    $sidebarImgSrc = asset(User::getProfilePictureUrl((int)$currentUser['id'], $currentUser));
                }
                $sidebarAvatarColor = getAvatarColor($firstname . ' ' . $lastname);
                ?>
                <div class="w-9 h-9 shrink-0 rounded-full overflow-hidden relative flex-shrink-0" style="background-color:<?php echo htmlspecialchars($sidebarAvatarColor); ?>">
                    <span class="absolute inset-0 flex items-center justify-center text-xs font-bold text-white select-none" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <img src="<?php echo htmlspecialchars($sidebarImgSrc); ?>" alt="Profilbild" class="absolute inset-0 w-full h-full object-cover" onerror="this.onerror=null; this.style.display='none';">
                </div>
                <div class='flex-1 min-w-0'>
                    <?php if (!empty($firstname) || !empty($lastname)): ?>
                    <p class='text-xs font-semibold text-white truncate leading-snug mb-0.5' title='<?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>'>
                        <?php echo htmlspecialchars($firstname . ' ' . $lastname); ?>
                    </p>
                    <?php endif; ?>
                    <p class='text-[11px] text-white/70 truncate leading-snug' title='<?php echo htmlspecialchars($email); ?>'>
                        <?php echo htmlspecialchars($email); ?>
                    </p>
                    <?php if (!empty($displayRoles)): ?>
                    <span class='role-badge inline-flex items-center gap-1 mt-1 max-w-full'
                          style='<?php echo $badgeStyle; ?>'
                          title='<?php echo htmlspecialchars(implode(', ', $displayRoles)); ?>'
                          aria-label='Rolle: <?php echo htmlspecialchars($displayRoles[0]); ?>'>
                        <i class='fas <?php echo getRoleIcon($role); ?> flex-shrink-0' aria-hidden='true'></i>
                        <span class='truncate'><?php echo htmlspecialchars($displayRoles[0]); ?></span>
                    </span>
                    <?php elseif (!empty($role) && $role !== 'User'): ?>
                    <span class='role-badge inline-flex items-center gap-1 mt-1 max-w-full'
                          style='<?php echo $badgeStyle; ?>'
                          title='<?php echo htmlspecialchars(getFormattedRoleName($role)); ?>'
                          aria-label='Rolle: <?php echo htmlspecialchars(getFormattedRoleName($role)); ?>'>
                        <i class='fas <?php echo getRoleIcon($role); ?> flex-shrink-0' aria-hidden='true'></i>
                        <span class='truncate'><?php echo htmlspecialchars(getFormattedRoleName($role)); ?></span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Profile Navigation -->
            <a href='<?php echo asset('pages/auth/profile.php'); ?>' 
               class='sidebar-footer-btn <?php echo is_nav_active('/auth/profile.php') ? 'active-btn' : ''; ?>'
               <?php echo is_nav_active('/auth/profile.php') ? 'aria-current="page"' : ''; ?>>
                <i class='fas fa-user' aria-hidden="true"></i>
                <span>Mein Profil</span>
            </a>

            <a href='<?php echo asset('pages/auth/settings.php'); ?>' 
               class='sidebar-footer-btn <?php echo is_nav_active('/auth/settings.php') ? 'active-btn' : ''; ?>'
               <?php echo is_nav_active('/auth/settings.php') ? 'aria-current="page"' : ''; ?>>
                <i class='fas fa-cog' aria-hidden="true"></i>
                <span>Einstellungen</span>
            </a>

            <!-- Dark/Light Mode Toggle -->
            <button id="theme-toggle" class='sidebar-footer-btn' aria-label="Zwischen hellem und dunklem Modus wechseln">
                <i id="theme-icon" class='fas fa-moon' aria-hidden="true"></i>
                <span id="theme-text">Darkmode</span>
            </button>

            <!-- Logout -->
            <a href='<?php echo asset('pages/auth/logout.php'); ?>' 
               class='sidebar-footer-btn sidebar-logout-btn'>
                <i class='fas fa-sign-out-alt' aria-hidden="true"></i>
                <span>Abmelden</span>
            </a>

            
            <!-- Live Clock -->
            <div class='mt-2 pt-2 border-t border-white/20 text-center'>
                <div id="live-clock" class='text-xs text-white/80 font-mono'>
                    <!-- JavaScript will update this -->
                </div>
            </div>
        </div>
    </aside>


    <!-- Main Content -->
    <main id="main-content" role="main" class="md:ml-64 lg:ml-72 min-h-screen px-4 sm:px-6 lg:px-8 pb-4 pt-[var(--topbar-height)] md:pt-6 lg:pt-8 2xl:pt-10" style="padding-bottom: max(1rem, env(safe-area-inset-bottom, 0))">
        <div class="max-w-7xl mx-auto">
            <?php echo $content ?? ''; ?>
        </div>
    </main>

    <!-- Mobile Bottom Navigation Bar (visible on small screens only) -->
    <nav id="mobile-bottom-nav" class="mobile-bottom-nav md:hidden" role="navigation" aria-label="Schnellnavigation">
        <a href="<?php echo asset('pages/dashboard/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo is_nav_active('/dashboard/') ? 'active' : ''; ?>"
           aria-label="Dashboard"
           <?php echo is_nav_active('/dashboard/') ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-home" aria-hidden="true"></i>
            <span>Home</span>
        </a>
        <a href="<?php echo asset('pages/events/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'active' : ''; ?>"
           aria-label="Events"
           <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-calendar" aria-hidden="true"></i>
            <span>Events</span>
        </a>
        <a href="<?php echo asset('pages/alumni/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo is_nav_active('/alumni/') ? 'active' : ''; ?>"
           aria-label="Alumni-Datenbank"
           <?php echo is_nav_active('/alumni/') ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-user-graduate" aria-hidden="true"></i>
            <span>Alumni</span>
        </a>
        <button id="bottom-nav-more-btn"
                class="mobile-bottom-nav-item"
                aria-label="Menü öffnen"
                aria-expanded="false"
                aria-controls="sidebar">
            <i class="fas fa-th" aria-hidden="true"></i>
            <span>Mehr</span>
        </button>
    </nav>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mobile-menu-btn');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const menuIconTop = document.getElementById('menu-icon-top');
            const menuIconMiddle = document.getElementById('menu-icon-middle');
            const menuIconBottom = document.getElementById('menu-icon-bottom');

            // Tailwind md breakpoint (must match tailwind.config.js screens.md = 768px)
            var MD_BREAKPOINT = 768;

            // Body scroll lock helpers (iOS Safari fix)
            var _savedScrollY = 0;
            function lockBodyScroll() {
                _savedScrollY = window.scrollY;
                document.body.style.top = '-' + _savedScrollY + 'px';
                document.body.style.overflow = 'hidden';
                document.body.classList.add('sidebar-open');
            }
            function unlockBodyScroll() {
                document.body.classList.remove('sidebar-open');
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.width = '';
                document.documentElement.style.overflow = '';
                window.scrollTo(0, _savedScrollY);
            }

            // Reusable open/close helpers
            function openSidebar() {
                if (!sidebar) return;
                // Toggle Tailwind translate classes for the slide-over effect
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0', 'open');
                // Show overlay and lock body scroll on all viewport sizes
                if (overlay) overlay.classList.add('active');
                lockBodyScroll();
                btn?.setAttribute('aria-expanded', 'true');
                btn?.setAttribute('aria-label', 'Menü schließen');
                menuIconTop?.setAttribute('d', 'M6 18L18 6');
                menuIconMiddle?.setAttribute('d', 'M12 12h0');
                menuIconMiddle?.setAttribute('opacity', '0');
                menuIconBottom?.setAttribute('d', 'M6 6L18 18');
            }
            function closeSidebar() {
                if (!sidebar) return;
                // Toggle Tailwind translate classes for the slide-out effect
                sidebar.classList.remove('translate-x-0', 'open');
                sidebar.classList.add('-translate-x-full');
                // Hide overlay and restore scroll on all viewport sizes
                if (overlay) overlay.classList.remove('active');
                unlockBodyScroll();
                btn?.setAttribute('aria-expanded', 'false');
                btn?.setAttribute('aria-label', 'Menü öffnen');
                menuIconTop?.setAttribute('d', 'M4 6h16');
                menuIconMiddle?.setAttribute('d', 'M4 12h16');
                menuIconMiddle?.setAttribute('opacity', '1');
                menuIconBottom?.setAttribute('d', 'M4 18h16');
            }

            function toggleSidebar() {
                if (sidebar && sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            if (btn && sidebar) {
                btn.addEventListener('click', toggleSidebar);
            }

            // Bottom nav "More" button toggles sidebar
            const bottomNavMoreBtn = document.getElementById('bottom-nav-more-btn');
            if (bottomNavMoreBtn && sidebar) {
                bottomNavMoreBtn.addEventListener('click', function() {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                        bottomNavMoreBtn.setAttribute('aria-expanded', 'false');
                        bottomNavMoreBtn.classList.remove('open');
                    } else {
                        openSidebar();
                        bottomNavMoreBtn.setAttribute('aria-expanded', 'true');
                        bottomNavMoreBtn.classList.add('open');
                    }
                });
            }

            // Sync bottom nav "More" button when sidebar closes via overlay/swipe
            function syncBottomNavMoreBtn() {
                if (bottomNavMoreBtn) {
                    bottomNavMoreBtn.classList.remove('open');
                    bottomNavMoreBtn.setAttribute('aria-expanded', 'false');
                }
            }

            if (overlay && sidebar) {
                overlay.addEventListener('click', function() {
                    closeSidebar();
                    syncBottomNavMoreBtn();
                });
            }

            // Close sidebar when clicking on main content (mobile)
            const mainContent = document.getElementById('main-content');
            if (mainContent && sidebar) {
                mainContent.addEventListener('click', function() {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                        syncBottomNavMoreBtn();
                    }
                });
            }

            // Escape key closes sidebar (accessibility)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar?.classList.contains('open')) {
                    closeSidebar();
                    syncBottomNavMoreBtn();
                    btn?.focus();
                }
            });

            // Auto-close sidebar on mobile when a nav link is clicked
            if (sidebar) {
                sidebar.querySelectorAll('nav a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        if (window.innerWidth < MD_BREAKPOINT && sidebar.classList.contains('open')) {
                            closeSidebar();
                            syncBottomNavMoreBtn();
                        }
                    });
                });
            }

            // Touch swipe detection for sidebar (mobile only)
            var _touchStartX = 0;
            var _touchStartY = 0;
            var SWIPE_THRESHOLD = 50; // min px for swipe

            document.addEventListener('touchstart', function(e) {
                _touchStartX = e.changedTouches[0].clientX;
                _touchStartY = e.changedTouches[0].clientY;
            }, { passive: true });

            document.addEventListener('touchend', function(e) {
                if (window.innerWidth >= MD_BREAKPOINT) return; // mobile only
                var touchEndX = e.changedTouches[0].clientX;
                var touchEndY = e.changedTouches[0].clientY;
                var deltaX = touchEndX - _touchStartX;
                var deltaY = touchEndY - _touchStartY;
                // Left swipe when sidebar is open: close it
                if (deltaX < -SWIPE_THRESHOLD && Math.abs(deltaY) < Math.abs(deltaX) * 1.5 && sidebar?.classList.contains('open')) {
                    closeSidebar();
                    syncBottomNavMoreBtn();
                }
                // Right swipe from near left edge when sidebar is closed: open it
                if (deltaX > SWIPE_THRESHOLD && Math.abs(deltaY) < Math.abs(deltaX) * 1.5 && _touchStartX < 60 && !sidebar?.classList.contains('open')) {
                    openSidebar();
                }
            }, { passive: true });
        });
        
        // Sidebar scroll position: restore on load, save on scroll and before unload
        (function() {
            const sidebarScroll = document.querySelector('.sidebar-scroll');
            if (!sidebarScroll) return;
            var savedPos = localStorage.getItem('sidebarScrollPos');
            if (savedPos !== null) {
                requestAnimationFrame(function() {
                    sidebarScroll.scrollTop = parseInt(savedPos, 10);
                });
            }
            var _scrollSaveTimer = null;
            sidebarScroll.addEventListener('scroll', function() {
                clearTimeout(_scrollSaveTimer);
                _scrollSaveTimer = setTimeout(function() {
                    localStorage.setItem('sidebarScrollPos', sidebarScroll.scrollTop);
                }, 100);
            }, { passive: true });
            window.addEventListener('beforeunload', function() {
                localStorage.setItem('sidebarScrollPos', sidebarScroll.scrollTop);
            });
        })();
        
        // Dark/Light Mode Toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');
        const themeText = document.getElementById('theme-text');
        const mobileThemeToggle = document.getElementById('mobile-theme-toggle');
        const mobileThemeIcon = document.getElementById('mobile-theme-icon');
        
        // Get user's saved theme preference from database (via data attribute)
        const userThemePreference = document.body.getAttribute('data-user-theme') || 'auto';
        
        // Load theme preference (localStorage overrides database preference)
        let currentTheme = localStorage.getItem('theme') || userThemePreference;
        
        // Apply theme based on preference
        function applyTheme(theme) {
            const isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.classList.add('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                if (themeIcon) { themeIcon.classList.remove('fa-moon'); themeIcon.classList.add('fa-sun'); }
                if (themeText) themeText.textContent = 'Lightmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-moon'); mobileThemeIcon.classList.add('fa-sun'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            } else {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.classList.remove('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.style.colorScheme = 'light';
                if (themeIcon) { themeIcon.classList.remove('fa-sun'); themeIcon.classList.add('fa-moon'); }
                if (themeText) themeText.textContent = 'Darkmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-sun'); mobileThemeIcon.classList.add('fa-moon'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
            }
        }
        
        // Apply initial theme
        applyTheme(currentTheme);
        
        // Toggle theme on button click
        function toggleTheme() {
            const isDarkMode = document.body.classList.contains('dark-mode');
            if (isDarkMode) {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.classList.remove('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.style.colorScheme = 'light';
                localStorage.setItem('theme', 'light');
                if (themeIcon) { themeIcon.classList.remove('fa-sun'); themeIcon.classList.add('fa-moon'); }
                if (themeText) themeText.textContent = 'Darkmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-sun'); mobileThemeIcon.classList.add('fa-moon'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
            } else {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.classList.add('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                localStorage.setItem('theme', 'dark');
                if (themeIcon) { themeIcon.classList.remove('fa-moon'); themeIcon.classList.add('fa-sun'); }
                if (themeText) themeText.textContent = 'Lightmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-moon'); mobileThemeIcon.classList.add('fa-sun'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            }
        }

        themeToggle?.addEventListener('click', toggleTheme);

        // Mobile theme toggle (synced with sidebar toggle)
        mobileThemeToggle?.addEventListener('click', toggleTheme);
        
        // Live Clock - Updates every second
        function updateLiveClock() {
            const now = new Date();
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            const dateTimeString = `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
            const clockElement = document.getElementById('live-clock');
            if (clockElement) {
                clockElement.textContent = dateTimeString;
            }
        }
        
        // Update immediately and then every second
        updateLiveClock();
        setInterval(updateLiveClock, 1000);

        // Lazy image fade-in
        document.querySelectorAll('img[loading="lazy"]').forEach(img => {
            if (img.complete) {
                img.classList.add('loaded');
            } else {
                img.addEventListener('load', () => img.classList.add('loaded'));
            }
        });

        // Table overflow indicator for responsive tables + scroll-end detection
        document.querySelectorAll('.table-scroll-wrapper, .table-responsive').forEach(wrapper => {
            const checkOverflow = () => {
                if (wrapper.scrollWidth > wrapper.clientWidth) {
                    wrapper.classList.add('has-overflow');
                } else {
                    wrapper.classList.remove('has-overflow');
                }
                // Detect if scrolled to the end (hide right fade); 4px tolerance for sub-pixel rounding
                const SCROLL_END_TOLERANCE = 4;
                const atEnd = wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - SCROLL_END_TOLERANCE;
                wrapper.classList.toggle('scrolled-end', atEnd);
                // Detect scroll-start for left fade indicator
                wrapper.classList.toggle('scrolled-start', wrapper.scrollLeft > SCROLL_END_TOLERANCE);
            };
            checkOverflow();
            wrapper.addEventListener('scroll', checkOverflow, { passive: true });
            window.addEventListener('resize', checkOverflow, { passive: true });
        });

        // Stacked layout: add .table-stack-mobile to .table/.card-table tables with more than 3 columns.
        // The CSS for .table-stack-mobile converts these tables into a stacked-card layout on screens < 768 px
        // (Apple/Google UX standard) instead of horizontal scrolling.
        document.querySelectorAll('table.table, table.card-table').forEach(function(table) {
            var headerRow = table.querySelector('thead tr');
            if (headerRow && headerRow.querySelectorAll('th').length > 3) {
                table.classList.add('table-stack-mobile');
            }
        });

        // iOS viewport height fix
        function setAppHeight() {
            document.documentElement.style.setProperty('--app-height', `${window.innerHeight}px`);
        }
        setAppHeight();
        window.addEventListener('resize', setAppHeight);
        window.addEventListener('orientationchange', () => {
            setTimeout(setAppHeight, 200);
        });

    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?php echo asset('js/navbar-scroll.js'); ?>" defer></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo asset('sw.js'); ?>');
            });
        }
    </script>

    <?php if (isset($_SESSION['show_2fa_nudge']) && $_SESSION['show_2fa_nudge']): ?>
    <div id="tfa-nudge-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1070] p-4" role="dialog" aria-modal="true" aria-labelledby="tfa-nudge-title">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden transform transition-all">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-blue-600 to-green-600 px-6 py-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-shield-alt text-white text-2xl" aria-hidden="true"></i>
                    </div>
                    <h3 id="tfa-nudge-title" class="text-xl font-bold text-white">Sicherheitshinweis</h3>
                </div>
            </div>
            
            <!-- Modal Body -->
            <div class="px-6 py-6 overflow-y-auto flex-1">
                <p class="text-slate-800 dark:text-slate-200 text-lg mb-2 font-semibold">
                    Erhöhe deine Sicherheit!
                </p>
                <p class="text-slate-800 dark:text-slate-200 mb-6">
                    Aktiviere jetzt die 2-Faktor-Authentifizierung für zusätzlichen Schutz deines Kontos.
                </p>
                
                <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3" aria-hidden="true"></i>
                        <p class="text-sm text-slate-800 dark:text-slate-200">
                            Die 2-Faktor-Authentifizierung macht dein Konto deutlich sicherer, indem bei der Anmeldung ein zusätzlicher Code erforderlich ist.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700 flex flex-col items-center md:flex-row md:justify-between gap-3">
                <a href="<?php echo asset('pages/auth/profile.php'); ?>" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-blue-600 to-green-600 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-green-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                    <i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>
                    Jetzt einrichten
                </a>
                <button onclick="dismissTfaNudge()" class="flex-1 px-6 py-3 bg-gray-300 dark:bg-slate-600 text-slate-800 dark:text-slate-200 rounded-lg font-semibold hover:bg-gray-400 dark:hover:bg-slate-500 transition-all duration-300">
                    Später
                </button>
            </div>
        </div>
    </div>

    <script>
    // Dismiss modal
    function dismissTfaNudge() {
        document.getElementById('tfa-nudge-modal').style.display = 'none';
    }
    </script>
    <?php 
        unset($_SESSION['show_2fa_nudge']);
    endif; 
    ?>

    <?php if (isset($_SESSION['show_role_notice']) && $_SESSION['show_role_notice']): ?>
    <!-- Role Notice Modal -->
    <div id="role-notice-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1060] p-4" role="dialog" aria-modal="true" aria-labelledby="role-notice-title" aria-describedby="role-notice-description">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden transform transition-all">
            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-orange-500 to-yellow-500 px-6 py-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-user-tag text-white text-2xl" aria-hidden="true"></i>
                    </div>
                    <h3 id="role-notice-title" class="text-xl font-bold text-white">Rollenhinweis</h3>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="px-6 py-6 overflow-y-auto flex-1">
                <p class="text-slate-800 dark:text-slate-200 text-lg mb-2 font-semibold">
                    Stimmt deine Rolle?
                </p>
                <p id="role-notice-description" class="text-slate-800 dark:text-slate-200 mb-6">
                    Dir wurde automatisch die Rolle <strong>Mitglied</strong> zugewiesen, da in Microsoft keine Rolle hinterlegt ist. Falls deine Rolle nicht korrekt ist, kannst du einen Änderungsantrag stellen.
                </p>

                <div class="bg-orange-50 dark:bg-orange-900/30 border border-orange-200 dark:border-orange-700 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-orange-600 dark:text-orange-400 mt-1 mr-3" aria-hidden="true"></i>
                        <p class="text-sm text-slate-800 dark:text-slate-200">
                            Bitte wende dich an den Vorstand oder stelle einen Änderungsantrag, wenn deine Rolle angepasst werden muss.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700 flex flex-col items-center md:flex-row md:justify-between gap-3">
                <a href="<?php echo asset('pages/auth/settings.php'); ?>#aenderungsantrag" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-orange-500 to-yellow-500 text-white rounded-lg font-semibold hover:from-orange-600 hover:to-yellow-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                    <i class="fas fa-file-alt mr-2" aria-hidden="true"></i>
                    Zum Änderungsantrag
                </a>
                <button onclick="dismissRoleNotice()" class="flex-1 px-6 py-3 bg-gray-300 dark:bg-slate-600 text-slate-800 dark:text-slate-200 rounded-lg font-semibold hover:bg-gray-400 dark:hover:bg-slate-500 transition-all duration-300">
                    Später
                </button>
            </div>
        </div>
    </div>

    <script>
    function dismissRoleNotice() {
        document.getElementById('role-notice-modal').style.display = 'none';
    }
    </script>
    <?php
        unset($_SESSION['show_role_notice']);
    endif;
    ?>

</body>
</html>
<!-- ✅ Sidebar visibility: Invoices restricted to vorstand_finanzen only via canManageInvoices() -->
