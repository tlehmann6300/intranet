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
$_uiFixesPath = __DIR__ . '/../../assets/css/ui-fixes.css';
$_uiFixesCssVersion = file_exists($_uiFixesPath) ? filemtime($_uiFixesPath) : '1';

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

// Pre-compute user display data for both the desktop navbar and sidebar footer
// so both components share the same values without a second DB query.
require_once __DIR__ . '/../models/Alumni.php';
require_once __DIR__ . '/../models/User.php';
$_navbarFirstname = '';
$_navbarLastname  = '';
$_navbarEmail     = '';
$_navbarRole      = 'User';
$_navbarImgSrc    = '';
$_navbarAvatarColor = '#374151';
$_navbarInitials  = 'U';

if ($currentUser && isset($currentUser['id'])) {
    $_navbarProfile = Alumni::getProfileByUserId($currentUser['id']);
    if ($_navbarProfile && !empty($_navbarProfile['first_name'])) {
        $_navbarFirstname = $_navbarProfile['first_name'];
        $_navbarLastname  = $_navbarProfile['last_name'] ?? '';
    } elseif (!empty($currentUser['first_name'])) {
        $_navbarFirstname = $currentUser['first_name'];
        $_navbarLastname  = $currentUser['last_name'] ?? '';
    }
    $_navbarEmail = $currentUser['email'] ?? '';
    $_navbarRole  = $currentUser['role'] ?? 'User';

    if (!empty($_navbarFirstname) && !empty($_navbarLastname)) {
        $_navbarInitials = strtoupper(substr($_navbarFirstname, 0, 1) . substr($_navbarLastname, 0, 1));
    } elseif (!empty($_navbarFirstname)) {
        $_navbarInitials = strtoupper(substr($_navbarFirstname, 0, 1));
    } elseif (!empty($_navbarEmail)) {
        $_navbarInitials = strtoupper(substr($_navbarEmail, 0, 1));
    }

    $_navbarAvatarColor = getAvatarColor($_navbarFirstname . ' ' . $_navbarLastname);

    if (!empty($_navbarEmail)) {
        $_navbarImgSrc = asset('fetch-profile-photo.php') . '?email=' . urlencode($_navbarEmail);
    } else {
        $_navbarImgSrc = asset(User::getProfilePictureUrl((int)$currentUser['id'], $currentUser));
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="overflow-x-hidden">
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
    <link rel="stylesheet" href="<?php echo asset('assets/css/ui-fixes.css') . '?v=' . $_uiFixesCssVersion; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn-uicons.flaticon.com/2.6.0/uicons-regular-rounded/css/uicons-regular-rounded.css" crossorigin="anonymous">
    <style>
        /* ════════════════════════════════════════════════════════════════
           GLOBAL BASE
           ════════════════════════════════════════════════════════════════ */
        p, span, li, h1, h2, h3 { overflow-wrap: break-word; word-break: break-word; hyphens: manual; }

        ::selection { background-color: rgba(0,102,179,0.2); color: inherit; }
        .dark-mode ::selection { background-color: rgba(51,133,196,0.3); }

        @supports (height: 100dvh) { .sidebar { height: 100dvh; } }

        /* ── PAGE ENTRANCE ANIMATION ──────────────────────────────── */
        /* WICHTIG: Nur opacity animieren – KEIN transform, da transform mit
           animation-fill-mode:both einen containing block erzeugt und
           fixed-positionierte Modals (z.B. Popups) dadurch nicht mehr
           relativ zum Viewport, sondern relativ zu diesem Container
           ausgerichtet würden (= "Popup zu weit oben"-Problem). */
        @keyframes pageEntranceFade {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @media (prefers-reduced-motion: no-preference) {
            #main-content > *:not(.fixed):not([style*="position: fixed"]):not([style*="position:fixed"]):not([class*="modal-overlay"]) {
                animation: pageEntranceFade 0.35s cubic-bezier(0.22, 0.61, 0.36, 1) both;
            }
            #main-content > *:nth-child(2) { animation-delay: 0.04s; }
            #main-content > *:nth-child(3) { animation-delay: 0.08s; }
            #main-content > *:nth-child(4) { animation-delay: 0.12s; }
            #main-content > *:nth-child(5) { animation-delay: 0.16s; }
            /* Sicherheitsnetz: Modals dürfen NIE einen transform erben */
            [class*="modal-overlay"],
            [class$="-modal-overlay"] {
                animation: none !important;
                transform: none !important;
            }
        }

        /* ── SPRING CARD TAP (MOBILE) ────────────────────────────── */
        @media (hover: none) and (pointer: coarse) {
            .card, .dash-stat-card, .dash-event-card, .dash-blog-card,
            .dash-helper-card, .dash-poll-card, .d-card, .d-stat-card,
            .d-event-item, .d-invoice-item, .d-blog-card {
                transition: transform 0.15s cubic-bezier(0.34,1.56,0.64,1),
                            box-shadow 0.15s ease, border-color 0.15s ease !important;
            }
            .card:active, .dash-stat-card:active, .d-card:active,
            .d-stat-card:active, .d-blog-card:active { transform: scale(0.975) !important; }
        }

        /* ── MOBILE BODY SCROLL LOCK ─────────────────────────────── */
        body.sidebar-open { overflow: hidden !important; position: fixed; width: 100%; }

        /* ── SIDEBAR ANIMATION ───────────────────────────────────── */
        .sidebar {
            transition: transform 0.3s cubic-bezier(0.32,0.72,0,1), visibility 0s 0.3s;
            visibility: hidden;
        }
        .sidebar.open {
            visibility: visible;
            transition: transform 0.3s cubic-bezier(0.32,0.72,0,1), visibility 0s 0s;
        }
        @media (min-width: 768px) {
            .sidebar { visibility: visible; transition: none; }
        }

        /* ── SIDEBAR OVERLAY ─────────────────────────────────────── */
        @media (max-width: 767px) {
            .sidebar-overlay { z-index: 1040; pointer-events: none; }
            .sidebar-overlay.active { pointer-events: auto; }
            .sidebar.open { z-index: 1050; }
        }
        @supports (backdrop-filter: blur(4px)) {
            .sidebar-overlay.active {
                backdrop-filter: blur(5px) saturate(1.2);
                -webkit-backdrop-filter: blur(5px) saturate(1.2);
                background: rgba(0,0,0,0.5) !important;
            }
        }

        /* ── MOBILE: MAIN CONTENT TOP PADDING ───────────────────── */
        @media (max-width: 767px) {
            #main-content {
                padding-top: var(--mobile-menu-height, calc(var(--topbar-height) + env(safe-area-inset-top,0px))) !important;
                padding-bottom: calc(4.5rem + env(safe-area-inset-bottom,0px)) !important;
                transition: padding-top 0.3s cubic-bezier(0.32,0.72,0,1);
            }
        }

        /* ── MOBILE TOPBAR ───────────────────────────────────────── */
        #mobile-header {
            min-height: calc(var(--topbar-height) + env(safe-area-inset-top,0px));
            padding-top: env(safe-area-inset-top,0px);
        }

        /* ── MOBILE TOPBAR BUTTON BASE ───────────────────────────── */
        .mob-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.375rem;
            height: 2.375rem;
            border-radius: 0.625rem;
            border: none;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.18s;
            -webkit-tap-highlight-color: transparent;
            flex-shrink: 0;
        }
        .mob-btn:hover, .mob-btn:focus-visible {
            background: rgba(255,255,255,0.2);
            outline: none;
        }
        .mob-btn:focus-visible { outline: 2px solid rgba(255,255,255,0.7); outline-offset: 2px; }

        /* ── MOBILE PROFILE DROPDOWN ─────────────────────────────── */
        .mob-profile-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.5rem 0.25rem 0.25rem;
            border-radius: 2rem;
            border: none;
            background: rgba(255,255,255,0.12);
            color: #fff;
            cursor: pointer;
            transition: background 0.18s;
            -webkit-tap-highlight-color: transparent;
            max-width: 11rem;
        }
        .mob-profile-btn:hover { background: rgba(255,255,255,0.22); }
        .mob-profile-btn .mob-avatar {
            width: 1.875rem;
            height: 1.875rem;
            border-radius: 50%;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
        .mob-profile-btn .mob-name {
            font-size: 0.8125rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mob-profile-btn .mob-chevron {
            font-size: 0.55rem;
            opacity: 0.7;
            margin-left: auto;
            transition: transform 0.2s;
            flex-shrink: 0;
        }
        .mob-profile-btn[aria-expanded="true"] .mob-chevron {
            transform: rotate(180deg);
        }

        /* ── MOBILE PROFILE DROPDOWN MENU ───────────────────────── */
        #mob-profile-dropdown {
            position: fixed;
            top: calc(var(--topbar-height) + env(safe-area-inset-top,0px) + 0.375rem);
            right: 0.75rem;
            min-width: 14rem;
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 1rem;
            box-shadow: 0 16px 48px rgba(0,0,0,0.18), 0 4px 16px rgba(0,0,0,0.1);
            z-index: 1100;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-8px) scale(0.97);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.34,1.56,0.64,1);
        }
        #mob-profile-dropdown.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .mob-dd-header {
            padding: 0.875rem 1rem 0.625rem;
            border-bottom: 1px solid var(--border-color);
        }
        .mob-dd-user-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }
        .mob-dd-role {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            background: rgba(0,102,179,0.1);
            color: var(--ibc-blue);
            margin-top: 0.25rem;
        }
        .mob-dd-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
            transition: background 0.15s;
        }
        .mob-dd-item:hover { background: var(--bg-body); }
        .mob-dd-item i { width: 1rem; text-align: center; color: var(--text-muted); font-size: 0.875rem; }
        .mob-dd-divider { height: 1px; background: var(--border-color); margin: 0.125rem 0; }
        .mob-dd-item--logout { color: #ef4444; }
        .mob-dd-item--logout i { color: #ef4444; }
        .mob-dd-theme-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--border-color);
            background: var(--bg-body);
        }
        .mob-dd-theme-row span {
            flex: 1;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
        }
        .mob-dd-theme-toggle {
            position: relative;
            width: 2.5rem;
            height: 1.375rem;
            border-radius: 9999px;
            background: var(--border-color);
            border: none;
            cursor: pointer;
            transition: background 0.25s;
            flex-shrink: 0;
        }
        .dark-mode .mob-dd-theme-toggle { background: var(--ibc-blue); }
        .mob-dd-theme-toggle::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.25s cubic-bezier(0.34,1.56,0.64,1);
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        .dark-mode .mob-dd-theme-toggle::after { transform: translateX(1.125rem); }

        /* ── MOBILE BOTTOM NAV ───────────────────────────────────── */
        #mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 900;
            display: flex;
            align-items: stretch;
            background: var(--bg-card);
            border-top: 1.5px solid var(--border-color);
            box-shadow: 0 -4px 24px rgba(0,0,0,0.1);
            padding-bottom: env(safe-area-inset-bottom, 0px);
            height: calc(3.75rem + env(safe-area-inset-bottom, 0px));
        }
        @media (min-width: 768px) { #mobile-bottom-nav { display: none !important; } }

        .mob-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            padding: 0.5rem 0.25rem;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.625rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            border: none;
            background: none;
            cursor: pointer;
            transition: color 0.15s;
            -webkit-tap-highlight-color: transparent;
            position: relative;
        }
        .mob-nav-item i { font-size: 1.2rem; transition: transform 0.18s cubic-bezier(0.34,1.56,0.64,1); }
        .mob-nav-item:hover i, .mob-nav-item:active i { transform: scale(1.18); }
        .mob-nav-item.active { color: var(--ibc-blue); }
        .mob-nav-item.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 25%;
            right: 25%;
            height: 2.5px;
            border-radius: 0 0 4px 4px;
            background: var(--ibc-blue);
        }
        .mob-nav-item.open { color: var(--ibc-blue); }

        /* ── MOBILE FORM FIXES ───────────────────────────────────── */
        @media (max-width: 767px) {
            table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            table thead { display: table-header-group; }
            table tbody { display: table-row-group; }
            th, td { white-space: nowrap; }
            form input, form select, form textarea {
                font-size: 16px !important; /* prevents iOS auto-zoom */
            }
            img:not([class*="w-"]) { max-width: 100%; height: auto; }
        }

        /* ── ROLE BADGE ──────────────────────────────────────────── */
        .role-badge {
            font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
            padding: 0.25rem 0.6rem; border-radius: 9999px; line-height: 1;
            white-space: nowrap; box-shadow: 0 1px 4px rgba(0,0,0,0.3);
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
    <header id="mobile-header" class="mobile-topbar md:hidden flex items-center px-3 gap-2" aria-label="Mobile-Navigation">
        <!-- Hamburger: opens sidebar overlay -->
        <button id="mobile-menu-btn" class="mob-btn" aria-label="Menü öffnen" aria-expanded="false">
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
                <path id="mob-icon-top" stroke="#fff" stroke-width="2" stroke-linecap="round" d="M2 4.5h14"/>
                <path id="mob-icon-mid" stroke="#fff" stroke-width="2" stroke-linecap="round" d="M2 9h14"/>
                <path id="mob-icon-bot" stroke="#fff" stroke-width="2" stroke-linecap="round" d="M2 13.5h14"/>
            </svg>
        </button>

        <!-- Center: IBC Logo -->
        <div class="flex-1 flex justify-center">
            <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>"
                 alt="IBC Intranet"
                 style="height:1.75rem;width:auto;object-fit:contain;filter:brightness(0) invert(1);"
                 decoding="async">
        </div>

        <!-- Right: Theme toggle + Profile dropdown button -->
        <div class="flex items-center gap-1.5 shrink-0">
            <button id="mobile-theme-toggle" class="mob-btn" aria-label="Darkmode umschalten">
                <i id="mobile-theme-icon" class="fas fa-moon" aria-hidden="true"></i>
            </button>
            <button id="mob-profile-btn" class="mob-profile-btn" aria-label="Profil-Menü öffnen" aria-expanded="false" aria-controls="mob-profile-dropdown">
                <div class="mob-avatar" style="background-color:<?php echo htmlspecialchars($_navbarAvatarColor); ?>">
                    <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:0.625rem;font-weight:700;color:#fff;" aria-hidden="true"><?php echo htmlspecialchars($_navbarInitials); ?></span>
                    <img src="<?php echo htmlspecialchars($_navbarImgSrc); ?>" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" onerror="this.onerror=null;this.style.display='none';">
                </div>
                <?php if (!empty($_navbarFirstname)): ?>
                <span class="mob-name"><?php echo htmlspecialchars($_navbarFirstname); ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-down mob-chevron" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <!-- Mobile Profile Dropdown -->
    <div id="mob-profile-dropdown" role="menu" aria-labelledby="mob-profile-btn" aria-hidden="true">
        <div class="mob-dd-header">
            <?php if (!empty($_navbarFirstname) || !empty($_navbarLastname)): ?>
            <p class="mob-dd-user-name"><?php echo htmlspecialchars(trim($_navbarFirstname . ' ' . $_navbarLastname)); ?></p>
            <?php endif; ?>
            <?php if (!empty($_navbarRole) && $_navbarRole !== 'User'): ?>
            <span class="mob-dd-role">
                <i class="fas <?php echo getRoleIcon($_navbarRole); ?>" aria-hidden="true"></i>
                <?php echo htmlspecialchars(getFormattedRoleName($_navbarRole)); ?>
            </span>
            <?php endif; ?>
        </div>
        <a href="<?php echo asset('pages/auth/profile.php'); ?>" class="mob-dd-item" role="menuitem">
            <i class="fas fa-user" aria-hidden="true"></i>
            <span>Mein Profil</span>
        </a>
        <a href="<?php echo asset('pages/auth/settings.php'); ?>" class="mob-dd-item" role="menuitem">
            <i class="fas fa-cog" aria-hidden="true"></i>
            <span>Einstellungen</span>
        </a>
        <div class="mob-dd-divider"></div>
        <a href="<?php echo asset('pages/auth/logout.php'); ?>" class="mob-dd-item mob-dd-item--logout" role="menuitem">
            <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
            <span>Abmelden</span>
        </a>
        <div class="mob-dd-theme-row">
            <i class="fas fa-moon" style="color:var(--text-muted);width:1rem;text-align:center;font-size:0.875rem;" aria-hidden="true"></i>
            <span>Darkmode</span>
            <button class="mob-dd-theme-toggle" id="mob-dd-theme-btn" aria-label="Darkmode umschalten"></button>
        </div>
    </div>

    <!-- Desktop Fixed Top Navbar (hidden on mobile) -->
    <header class="desktop-navbar" id="desktop-navbar" aria-label="Desktop-Navigation">
        <!-- Light/Dark Mode Toggle -->
        <button id="navbar-theme-toggle" class="navbar-theme-btn" aria-label="Zwischen hellem und dunklem Modus wechseln">
            <i id="navbar-theme-icon" class="fas fa-moon" aria-hidden="true"></i>
        </button>

        <!-- Profile Dropdown Trigger -->
        <div class="relative" id="navbar-profile-wrapper">
            <button id="navbar-profile-btn" class="navbar-profile-btn" aria-haspopup="true" aria-expanded="false" aria-controls="navbar-profile-dropdown">
                <!-- Avatar -->
                <div class="w-8 h-8 rounded-full overflow-hidden relative flex-shrink-0" style="background-color:<?php echo htmlspecialchars($_navbarAvatarColor); ?>">
                    <span class="absolute inset-0 flex items-center justify-center text-xs font-bold text-white select-none" aria-hidden="true"><?php echo htmlspecialchars($_navbarInitials); ?></span>
                    <img src="<?php echo htmlspecialchars($_navbarImgSrc); ?>" alt="Profilbild" class="absolute inset-0 w-full h-full object-cover" onerror="this.onerror=null;this.style.display='none';">
                </div>
                <!-- Name -->
                <?php if (!empty($_navbarFirstname) || !empty($_navbarLastname)): ?>
                <span class="navbar-profile-name"><?php echo htmlspecialchars(trim($_navbarFirstname . ' ' . $_navbarLastname)); ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-down navbar-profile-chevron" aria-hidden="true"></i>
            </button>

            <!-- Dropdown Menu -->
            <div id="navbar-profile-dropdown" class="navbar-profile-dropdown" role="menu" aria-labelledby="navbar-profile-btn">
                <?php if (!empty($_navbarRole) && $_navbarRole !== 'User'): ?>
                <div class="navbar-dropdown-role">
                    <i class="fas <?php echo getRoleIcon($_navbarRole); ?>" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars(getFormattedRoleName($_navbarRole)); ?></span>
                </div>
                <div class="navbar-dropdown-divider"></div>
                <?php endif; ?>
                <a href="<?php echo asset('pages/auth/profile.php'); ?>" class="navbar-dropdown-item <?php echo is_nav_active('/auth/profile.php') ? 'font-semibold' : ''; ?>" role="menuitem">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <span>Mein Profil</span>
                </a>
                <a href="<?php echo asset('pages/auth/settings.php'); ?>" class="navbar-dropdown-item <?php echo is_nav_active('/auth/settings.php') ? 'font-semibold' : ''; ?>" role="menuitem">
                    <i class="fas fa-cog" aria-hidden="true"></i>
                    <span>Einstellungen</span>
                </a>
                <div class="navbar-dropdown-divider"></div>
                <a href="<?php echo asset('pages/auth/logout.php'); ?>" class="navbar-dropdown-item navbar-dropdown-logout" role="menuitem">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    <span>Abmelden</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar fixed left-0 top-0 h-screen w-64 md:w-72 transform -translate-x-full md:translate-x-0 transition-transform duration-300 z-40 text-white shadow-2xl flex flex-col" aria-label="Seitenleiste">
        <?php 
        $currentUser = Auth::user();
        $userRole = $currentUser['role'] ?? '';
        ?>
        <div class="p-5 flex-1 overflow-y-auto sidebar-scroll">
            <!-- IBC Logo + mobile close button -->
            <div class="mb-6 px-3 pt-2 relative">
                <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>" alt="IBC Logo" class="w-full h-auto drop-shadow-lg" decoding="async">
                <!-- Close button: only visible on mobile, hidden on md+ -->
                <button id="sidebar-close-btn"
                        class="md:hidden absolute top-0 right-0 flex items-center justify-center w-8 h-8 rounded-lg text-white/70 hover:text-white hover:bg-white/15 transition-colors"
                        aria-label="Seitenleiste schließen"
                        style="-webkit-tap-highlight-color: transparent;">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
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


                <!-- Digitale Visitenkarten (Vorstand + Ressortleiter) -->
                <?php if (Auth::canCreateBasicContent()): ?>
                <a href="<?php echo asset('pages/admin/vcards.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/vcards.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/vcards.php') ? 'aria-current="page"' : ''; ?>>
                    <svg class="sidebar-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                    <span>Digitale Visitenkarten</span>
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

        <!-- Sidebar Footer -->
        <div class='sidebar-footer mt-auto pt-2 pb-2 px-3'>
            <!-- Live Clock (centered) -->
            <div class='pt-2 border-t border-white/20 text-center'>
                <div id="live-clock" class='text-xs text-white/80 font-mono'>
                    <!-- JavaScript will update this -->
                </div>
            </div>

            <!-- Hidden elements to keep theme-toggle IDs for existing JS -->
            <button id="theme-toggle" class="hidden" aria-hidden="true" tabindex="-1">
                <i id="theme-icon" class='fas fa-moon'></i>
                <span id="theme-text">Darkmode</span>
            </button>
        </div>
    </aside>


    <!-- Main Content -->
    <main id="main-content" role="main" class="md:ml-64 lg:ml-72 min-h-screen px-4 sm:px-6 lg:px-8 pb-4 pt-[var(--topbar-height)] md:pt-6 lg:pt-8 2xl:pt-10" style="padding-bottom: max(1rem, env(safe-area-inset-bottom, 0))">
        <div class="max-w-7xl mx-auto">
            <?php echo $content ?? ''; ?>
        </div>
        <footer class="max-w-7xl mx-auto mt-8 py-4" style="border-top: 1px solid var(--border-color);">
            <div class="flex flex-col items-center md:flex-row md:justify-between gap-2 text-sm" style="color: var(--text-muted);">
                <p style="color: var(--text-muted);">&copy; <?php echo date('Y'); ?> IBC Business Consulting. Alle Rechte vorbehalten.</p>
                <div class="flex gap-4">
                    <a href="<?php echo asset('pages/impressum.php'); ?>" style="color: var(--text-muted); transition: color 0.15s;" onmouseover="this.style.color='var(--ibc-green)'" onmouseout="this.style.color='var(--text-muted)'" aria-label="Impressum – Rechtliche Hinweise">Impressum</a>
                </div>
            </div>
        </footer>
    </main>

    <!-- Mobile Bottom Navigation Bar (visible on small screens only) -->
    <nav id="mobile-bottom-nav" role="navigation" aria-label="Schnellnavigation">
        <a href="<?php echo asset('pages/dashboard/index.php'); ?>"
           class="mob-nav-item <?php echo is_nav_active('/dashboard/') ? 'active' : ''; ?>"
           aria-label="Dashboard"
           <?php echo is_nav_active('/dashboard/') ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-home" aria-hidden="true"></i>
            <span>Home</span>
        </a>
        <a href="<?php echo asset('pages/events/index.php'); ?>"
           class="mob-nav-item <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'active' : ''; ?>"
           aria-label="Events"
           <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <span>Events</span>
        </a>
        <a href="<?php echo asset('pages/alumni/index.php'); ?>"
           class="mob-nav-item <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'active' : ''; ?>"
           aria-label="Alumni"
           <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'aria-current="page"' : ''; ?>>
            <i class="fas fa-user-graduate" aria-hidden="true"></i>
            <span>Alumni</span>
        </a>
        <button id="bottom-nav-more-btn"
                class="mob-nav-item"
                aria-label="Menü öffnen"
                aria-expanded="false">
            <i class="fas fa-grip-horizontal" aria-hidden="true"></i>
            <span>Mehr</span>
        </button>
    </nav>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mobile-menu-btn');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebar-overlay');

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

            // Hamburger icon state helpers
            function setHamburgerOpen(isOpen) {
                const top = document.getElementById('mob-icon-top');
                const mid = document.getElementById('mob-icon-mid');
                const bot = document.getElementById('mob-icon-bot');
                if (isOpen) {
                    top?.setAttribute('d', 'M3 3l12 12');
                    mid?.setAttribute('opacity', '0');
                    bot?.setAttribute('d', 'M15 3L3 15');
                    btn?.setAttribute('aria-expanded', 'true');
                    btn?.setAttribute('aria-label', 'Menü schließen');
                } else {
                    top?.setAttribute('d', 'M2 4.5h14');
                    mid?.setAttribute('opacity', '1');
                    bot?.setAttribute('d', 'M2 13.5h14');
                    btn?.setAttribute('aria-expanded', 'false');
                    btn?.setAttribute('aria-label', 'Menü öffnen');
                }
            }

            // Reusable open/close helpers
            function openSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0', 'open');
                if (overlay) overlay.classList.add('active');
                lockBodyScroll();
                setHamburgerOpen(true);
            }
            function closeSidebar() {
                if (!sidebar) return;
                sidebar.classList.remove('translate-x-0', 'open');
                sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');
                unlockBodyScroll();
                setHamburgerOpen(false);
            }
            function toggleSidebar() {
                if (sidebar && sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            }

            // Hamburger button toggles sidebar
            btn?.addEventListener('click', toggleSidebar);

            // Sidebar close button (X inside sidebar)
            const sidebarCloseBtn = document.getElementById('sidebar-close-btn');
            sidebarCloseBtn?.addEventListener('click', function() {
                closeSidebar();
                syncBottomNavMoreBtn();
            });

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

            // Escape key closes sidebar or mobile profile dropdown (accessibility)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (sidebar?.classList.contains('open')) {
                        closeSidebar();
                        syncBottomNavMoreBtn();
                    }
                    const mobDrop = document.getElementById('mob-profile-dropdown');
                    if (mobDrop?.classList.contains('open')) {
                        window.closeMobProfileDropdown?.();
                    }
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

            // ── Mobile Profile Dropdown ──────────────────────────────────
            (function() {
                const profileBtn = document.getElementById('mob-profile-btn');
                const dropdown   = document.getElementById('mob-profile-dropdown');
                if (!profileBtn || !dropdown) return;

                function openMobDropdown() {
                    dropdown.classList.add('open');
                    dropdown.setAttribute('aria-hidden', 'false');
                    profileBtn.setAttribute('aria-expanded', 'true');
                }
                function closeMobDropdown() {
                    dropdown.classList.remove('open');
                    dropdown.setAttribute('aria-hidden', 'true');
                    profileBtn.setAttribute('aria-expanded', 'false');
                }
                window.closeMobProfileDropdown = closeMobDropdown;

                profileBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (dropdown.classList.contains('open')) {
                        closeMobDropdown();
                    } else {
                        openMobDropdown();
                    }
                });

                // Close when clicking outside
                document.addEventListener('click', function(e) {
                    if (!dropdown.contains(e.target) && !profileBtn.contains(e.target)) {
                        closeMobDropdown();
                    }
                });

                // Close when a dropdown link is clicked
                dropdown.querySelectorAll('a').forEach(function(link) {
                    link.addEventListener('click', function() {
                        closeMobDropdown();
                    });
                });

                // Dropdown theme toggle button
                const ddThemeBtn = document.getElementById('mob-dd-theme-btn');
                if (ddThemeBtn) {
                    ddThemeBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        toggleTheme();
                    });
                }
            })();
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
        const navbarThemeToggle = document.getElementById('navbar-theme-toggle');
        const navbarThemeIcon = document.getElementById('navbar-theme-icon');
        
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
                if (navbarThemeIcon) { navbarThemeIcon.classList.remove('fa-moon'); navbarThemeIcon.classList.add('fa-sun'); }
                if (navbarThemeToggle) navbarThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            } else {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.classList.remove('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.style.colorScheme = 'light';
                if (themeIcon) { themeIcon.classList.remove('fa-sun'); themeIcon.classList.add('fa-moon'); }
                if (themeText) themeText.textContent = 'Darkmode';
                if (mobileThemeIcon) { mobileThemeIcon.classList.remove('fa-sun'); mobileThemeIcon.classList.add('fa-moon'); }
                if (mobileThemeToggle) mobileThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
                if (navbarThemeIcon) { navbarThemeIcon.classList.remove('fa-sun'); navbarThemeIcon.classList.add('fa-moon'); }
                if (navbarThemeToggle) navbarThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
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
                if (navbarThemeIcon) { navbarThemeIcon.classList.remove('fa-sun'); navbarThemeIcon.classList.add('fa-moon'); }
                if (navbarThemeToggle) navbarThemeToggle.setAttribute('aria-label', 'Zu Darkmode wechseln');
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
                if (navbarThemeIcon) { navbarThemeIcon.classList.remove('fa-moon'); navbarThemeIcon.classList.add('fa-sun'); }
                if (navbarThemeToggle) navbarThemeToggle.setAttribute('aria-label', 'Zu Lightmode wechseln');
            }
        }

        themeToggle?.addEventListener('click', toggleTheme);

        // Mobile theme toggle (synced with sidebar toggle)
        mobileThemeToggle?.addEventListener('click', toggleTheme);

        // Navbar theme toggle
        navbarThemeToggle?.addEventListener('click', toggleTheme);

        // ── Navbar Profile Dropdown ──────────────────────────────────
        (function() {
            const profileBtn = document.getElementById('navbar-profile-btn');
            const dropdown   = document.getElementById('navbar-profile-dropdown');
            if (!profileBtn || !dropdown) return;

            function openDropdown() {
                dropdown.classList.add('open');
                profileBtn.setAttribute('aria-expanded', 'true');
            }
            function closeDropdown() {
                dropdown.classList.remove('open');
                profileBtn.setAttribute('aria-expanded', 'false');
            }
            function toggleDropdown() {
                if (dropdown.classList.contains('open')) {
                    closeDropdown();
                } else {
                    openDropdown();
                }
            }

            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });

            // Close when clicking outside
            document.addEventListener('click', function(e) {
                const wrapper = document.getElementById('navbar-profile-wrapper');
                if (wrapper && !wrapper.contains(e.target)) {
                    closeDropdown();
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && dropdown.classList.contains('open')) {
                    closeDropdown();
                    profileBtn.focus();
                }
            });

            // Close when a dropdown link is clicked
            dropdown.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    closeDropdown();
                });
            });
        })();
        
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
        // Service Worker v3 – cross-origin requests are skipped (CDN fix)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo asset('sw.js'); ?>')
                    .then(function(reg) {
                        // Force immediate update check so new SW activates fast
                        reg.update();
                    })
                    .catch(function(err) {
                        console.warn('SW registration failed:', err);
                    });
            });
        }
    </script>

    <?php if (isset($_SESSION['show_2fa_nudge']) && $_SESSION['show_2fa_nudge']): ?>
    <style>
    /* 2FA Nudge Modal – Premium Design */
    #tfa-nudge-modal { animation: ibc-modal-backdrop 0.25s ease both; }
    #tfa-nudge-modal .ibc-modal-card {
        animation: ibc-modal-slide 0.35s cubic-bezier(0.34,1.56,0.64,1) both;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
    }
    @keyframes ibc-modal-backdrop { from { opacity:0; } to { opacity:1; } }
    @keyframes ibc-modal-slide { from { opacity:0; transform:translateY(24px) scale(0.96); } to { opacity:1; transform:translateY(0) scale(1); } }

    .ibc-modal-header-icon {
        width: 3rem; height: 3rem; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: rgba(255,255,255,0.22);
        border: 1.5px solid rgba(255,255,255,0.35);
        flex-shrink: 0;
        font-size: 1.25rem; color: #fff;
    }
    .ibc-modal-info-box {
        border-radius: 12px;
        padding: 1rem 1.25rem;
        display: flex; gap: 0.875rem; align-items: flex-start;
        background: rgba(59,130,246,0.08);
        border: 1px solid rgba(59,130,246,0.2);
    }
    .dark-mode .ibc-modal-info-box {
        background: rgba(59,130,246,0.12);
        border-color: rgba(59,130,246,0.25);
    }
    .ibc-modal-btn-primary {
        flex: 1; display: inline-flex; align-items: center; justify-content: center;
        gap: 0.5rem; padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, #0066b3 0%, #00a651 100%);
        color: #fff !important; font-weight: 600; font-size: 0.9375rem;
        border-radius: 10px; border: none; cursor: pointer; text-decoration: none !important;
        transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        box-shadow: 0 4px 12px rgba(0,102,179,0.3);
    }
    .ibc-modal-btn-primary:hover {
        opacity: 0.92; transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(0,102,179,0.4);
        color: #fff !important;
    }
    .ibc-modal-btn-secondary {
        flex: 1; display: inline-flex; align-items: center; justify-content: center;
        gap: 0.5rem; padding: 0.75rem 1.5rem;
        background: var(--bg-body); color: var(--text-muted) !important;
        font-weight: 500; font-size: 0.9375rem;
        border-radius: 10px; border: 1.5px solid var(--border-color); cursor: pointer;
        transition: background 0.2s ease, border-color 0.2s ease;
    }
    .ibc-modal-btn-secondary:hover {
        background: var(--bg-card);
        border-color: var(--ibc-gray-400);
        color: var(--text-main) !important;
    }
    </style>
    <div id="tfa-nudge-modal"
         class="fixed inset-0 flex items-center justify-center z-[1070] p-4"
         style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);"
         role="dialog" aria-modal="true" aria-labelledby="tfa-nudge-title">
        <div class="ibc-modal-card rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">

            <!-- Header -->
            <div style="background:linear-gradient(135deg,#0052a3 0%,#0066b3 40%,#00845f 75%,#00a651 100%);padding:1.5rem 1.5rem 1.25rem;">
                <div class="flex items-center gap-4">
                    <div class="ibc-modal-header-icon">
                        <i class="fas fa-shield-alt" aria-hidden="true" style="color:#fff;font-size:1.2rem;"></i>
                    </div>
                    <div>
                        <p style="font-size:0.75rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:rgba(255,255,255,0.7);margin-bottom:2px;">Sicherheitshinweis</p>
                        <h3 id="tfa-nudge-title" style="font-size:1.1875rem;font-weight:700;color:#fff;margin:0;">Erhöhe deine Sicherheit</h3>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:1.5rem;">
                <p style="color:var(--text-main);margin-bottom:1.25rem;font-size:0.9375rem;line-height:1.6;">
                    Aktiviere die <strong>2-Faktor-Authentifizierung</strong> für zusätzlichen Schutz deines IBC-Kontos.
                </p>
                <div class="ibc-modal-info-box">
                    <i class="fas fa-info-circle" style="color:var(--ibc-blue);margin-top:2px;flex-shrink:0;" aria-hidden="true"></i>
                    <p style="color:var(--text-muted);font-size:0.875rem;line-height:1.55;margin:0;">
                        Bei der Anmeldung wird zusätzlich ein Code aus einer Authenticator-App abgefragt. Das schützt dein Konto auch bei gestohlenen Zugangsdaten.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:1rem 1.5rem 1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
                <a href="<?php echo asset('pages/auth/settings.php'); ?>#2fa" class="ibc-modal-btn-primary">
                    <i class="fas fa-shield-alt" aria-hidden="true"></i>
                    Jetzt einrichten
                </a>
                <button onclick="dismissTfaNudge()" class="ibc-modal-btn-secondary">
                    Später
                </button>
            </div>
        </div>
    </div>

    <script>
    function dismissTfaNudge() {
        var m = document.getElementById('tfa-nudge-modal');
        if (m) { m.style.opacity='0'; m.style.transition='opacity 0.2s ease'; setTimeout(function(){m.style.display='none';},200); }
    }
    </script>
    <?php 
        unset($_SESSION['show_2fa_nudge']);
    endif; 
    ?>

    <?php if (isset($_SESSION['show_role_notice']) && $_SESSION['show_role_notice']): ?>
    <!-- Role Notice Modal – Premium Design -->
    <div id="role-notice-modal"
         class="fixed inset-0 flex items-center justify-center z-[1060] p-4"
         style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);animation:ibc-modal-backdrop 0.25s ease both;"
         role="dialog" aria-modal="true" aria-labelledby="role-notice-title" aria-describedby="role-notice-description">
        <div class="ibc-modal-card rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">

            <!-- Header -->
            <div style="background:linear-gradient(135deg,#d97706 0%,#f59e0b 100%);padding:1.5rem 1.5rem 1.25rem;">
                <div class="flex items-center gap-4">
                    <div class="ibc-modal-header-icon">
                        <i class="fas fa-user-tag" aria-hidden="true" style="color:#fff;font-size:1.2rem;"></i>
                    </div>
                    <div>
                        <p style="font-size:0.75rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:rgba(255,255,255,0.75);margin-bottom:2px;">Rollenhinweis</p>
                        <h3 id="role-notice-title" style="font-size:1.1875rem;font-weight:700;color:#fff;margin:0;">Stimmt deine Rolle?</h3>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:1.5rem;">
                <p id="role-notice-description" style="color:var(--text-main);margin-bottom:1.25rem;font-size:0.9375rem;line-height:1.6;">
                    Dir wurde automatisch die Rolle <strong>Mitglied</strong> zugewiesen, da in Microsoft keine Rolle hinterlegt ist. Falls das nicht stimmt, stelle bitte einen Änderungsantrag.
                </p>
                <div style="border-radius:12px;padding:1rem 1.25rem;display:flex;gap:0.875rem;align-items:flex-start;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.22);">
                    <i class="fas fa-info-circle" style="color:#f59e0b;margin-top:2px;flex-shrink:0;" aria-hidden="true"></i>
                    <p style="color:var(--text-muted);font-size:0.875rem;line-height:1.55;margin:0;">
                        Wende dich an den Vorstand oder stelle einen Änderungsantrag, wenn deine Rolle angepasst werden muss.
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:1rem 1.5rem 1.5rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
                <a href="<?php echo asset('pages/auth/settings.php'); ?>#aenderungsantrag"
                   style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:0.75rem 1.5rem;background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff !important;font-weight:600;font-size:0.9375rem;border-radius:10px;border:none;cursor:pointer;text-decoration:none !important;box-shadow:0 4px 12px rgba(217,119,6,0.35);transition:opacity 0.2s ease;">
                    <i class="fas fa-file-alt" aria-hidden="true"></i>
                    Zum Änderungsantrag
                </a>
                <button onclick="dismissRoleNotice()" class="ibc-modal-btn-secondary">
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
