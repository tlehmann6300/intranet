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
<html lang="de" class="dark:bg-gray-900">
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

            /* Prevent horizontal overflow on non-card tables */
            table:not(.card-table):not(.table-responsive):not(.table-stack-mobile) {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 8px;
            }
            th, td { white-space: normal; }

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

        /* ── SIDEBAR: ALWAYS VISIBLE, FIXED LEFT ────────────────── */
        .sidebar {
            visibility: visible;
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
           --mobile-menu-height from the actual measured height of #top-header;
           the calc() below is the pure-CSS fallback.
           The transition uses the same duration/easing as the sidebar so the
           push-down animation of #main-content stays perfectly in sync. */
        @media (max-width: 767px) {
            #main-content {
                padding-top: var(--mobile-menu-height, calc(var(--topbar-height, 60px) + env(safe-area-inset-top, 0px) + 0.75rem)) !important;
                padding-bottom: calc(5rem + env(safe-area-inset-bottom, 0px)) !important;
                margin-left: 0 !important;
                transition: padding-top 0.3s cubic-bezier(0.32, 0.72, 0, 1);
            }
        }

        /* ── PAGE ENTRANCE ANIMATION ─────────────────────────────── */
        @keyframes pageEntranceFade {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: none; }
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
        /* Ensure topbar correctly accommodates Dynamic Island and notch.
           Also resets left offset to 0 so the bar is full-width on mobile
           (on desktop it is offset by the sidebar width via the base rule). */
        @media (max-width: 767px) {
            #top-header {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                min-height: calc(var(--topbar-height, 60px) + env(safe-area-inset-top, 0px));
                padding-top: env(safe-area-inset-top, 0px);
                z-index: var(--z-topbar, 1060) !important;
            }
        }

        /* ── SMOOTH SIDEBAR OVERLAY BLUR ─────────────────────────── */
        @supports (backdrop-filter: blur(4px)) {
            .sidebar-overlay.active {
                backdrop-filter: blur(4px) saturate(1.2);
                -webkit-backdrop-filter: blur(4px) saturate(1.2);
                background: rgba(0, 0, 0, 0.45) !important;
            }
        }

        /* ── MOBILE SLIDE-DOWN NAVIGATION MENU ───────────────────── */
        .mobile-menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            padding: 0.875rem 1.25rem;
            font-size: 0.9375rem;
            font-weight: 500;
            text-decoration: none;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease-in-out;
            color: var(--text-main);
        }
        .mobile-menu-link:hover {
            background-color: var(--bg-body);
            color: var(--text-main);
        }
        .mobile-menu-link--active {
            background-color: var(--ibc-blue);
            color: #fff !important;
            border-left: 4px solid var(--ibc-blue-dark);
        }
        .mobile-menu-link--active i {
            color: #fff !important;
        }
        .mobile-menu-section-label {
            padding: 0.5rem 1.25rem 0.25rem;
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            background-color: var(--bg-body);
        }

        /* ── ROLE BADGE ──────────────────────────────────────────── */
        .role-badge {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            line-height: 1;
            white-space: nowrap;
        }
        .role-badge i {
            font-size: 9px;
        }

        /* ══════════════════════════════════════════════════════════
           ENTERPRISE-GRADE DESIGN ENHANCEMENTS
           Glassmorphism sidebar/topbar/bottom-nav: see .glass-sidebar,
           .glass-topbar, .glass-bottom-nav in tailwind.css.
           Skeleton loading: see .skeleton, .skeleton-enterprise in tailwind.css.
           ══════════════════════════════════════════════════════════ */

        /* ── GLASSMORPHISM MOBILE SLIDE-DOWN MENU ─────────────── */
        #mobile-menu {
            backdrop-filter: blur(20px) saturate(1.6) !important;
            -webkit-backdrop-filter: blur(20px) saturate(1.6) !important;
        }

        /* ── ENTERPRISE DARK-MODE BODY/LAYOUT ────────────────── */
        body.dark-mode {
            background-color: #111827 !important; /* gray-900 */
            color: #ffffff !important;
        }
        body.dark-mode #main-content {
            background-color: transparent !important;
        }

        /* ── LINK & BUTTON GLOBAL TRANSITION (300 ms ease-in-out) ─
           Applies to all links and buttons as a base; more-specific
           selectors in theme.css override where custom timing is needed. */
        a, button {
            transition: all 0.3s ease-in-out;
        }

        /* ── MAIN CONTENT DARK-MODE FOOTER ───────────────────── */
        body.dark-mode footer {
            border-color: rgba(255, 255, 255, 0.08) !important;
        }
        body.dark-mode footer p,
        body.dark-mode footer a {
            color: rgba(255, 255, 255, 0.45) !important;
        }
        body.dark-mode footer a:hover {
            color: rgba(255, 255, 255, 0.8) !important;
        }

        /* ══════════════════════════════════════════════════════════
           BUSINESS CONSULTING REDESIGN
           Unified top header · User dropdown · Lucide icon support
           ══════════════════════════════════════════════════════════ */

        /* ── UNIFIED TOP HEADER ──────────────────────────────── */
        #top-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width-md, 17rem); /* desktop: matches sidebar width; defined by --sidebar-width-md CSS variable */
            right: 0;
            height: var(--topbar-height, 60px);
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(12px) saturate(1.8);
            -webkit-backdrop-filter: blur(12px) saturate(1.8);
            z-index: var(--z-topbar, 1060);
        }

        /* Push main content below the fixed topbar on desktop */
        @media (min-width: 768px) {
            #main-content {
                padding-top: calc(var(--topbar-height, 60px) + 1rem) !important;
            }
        }

        /* hamburger button inside top header */
        #mobile-menu-btn {
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.3s ease-in-out;
        }
        /* Show hamburger on mobile despite Tailwind .hidden class */
        @media (max-width: 767px) {
            #mobile-menu-btn {
                display: flex !important;
            }
        }
        #mobile-menu-btn:hover { background: rgba(0,0,0,0.05); color: #334155; }
        #mobile-menu-btn:focus-visible { outline: 2px solid var(--ibc-blue, #0066b3); outline-offset: 2px; }
        body.dark-mode #mobile-menu-btn { color: #94a3b8; }
        body.dark-mode #mobile-menu-btn:hover { background: rgba(255,255,255,0.07); color: #e2e8f0; }

        /* theme-toggle button inside top header */
        #theme-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.75rem;
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.3s ease-in-out;
        }
        #theme-toggle:hover { background: rgba(0,0,0,0.05); color: #334155; }
        body.dark-mode #theme-toggle { color: #94a3b8; }
        body.dark-mode #theme-toggle:hover { background: rgba(255,255,255,0.07); color: #e2e8f0; }

        /* user dropdown trigger button */
        #user-dropdown-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.5rem 0.25rem 0.25rem;
            border-radius: 0.75rem;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.3s ease-in-out;
        }
        #user-dropdown-btn:hover { background: rgba(0,0,0,0.05); }
        body.dark-mode #user-dropdown-btn:hover { background: rgba(255,255,255,0.07); }
        #user-dropdown-btn .header-user-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.dark-mode #user-dropdown-btn .header-user-name { color: #e2e8f0; }

        /* Lucide chevron in dropdown button */
        #user-dropdown-btn svg.lucide-chevron-down {
            width: 0.875rem;
            height: 0.875rem;
            color: #94a3b8;
            flex-shrink: 0;
        }

        /* ── USER DROPDOWN PANEL ─────────────────────────────── */
        #user-dropdown {
            position: fixed;
            right: 1rem;
            top: calc(var(--topbar-height, 60px) + 0.375rem);
            width: 288px;
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(0, 0, 0, 0.05);
            z-index: calc(var(--z-topbar, 1060) + 5);
            overflow: hidden;
            transform-origin: top right;
            transform: scale(0.95) translateY(-6px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        #user-dropdown.open {
            transform: scale(1) translateY(0);
            opacity: 1;
            pointer-events: auto;
        }
        body.dark-mode #user-dropdown {
            background: #1e293b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.06);
        }
        .user-dropdown-divider {
            border: none;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            margin: 0;
        }
        body.dark-mode .user-dropdown-divider { border-top-color: rgba(255,255,255,0.06); }

        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            width: 100%;
            padding: 0.625rem 0.75rem;
            border-radius: 0.625rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            text-decoration: none !important;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease-in-out;
            text-align: left;
            box-sizing: border-box;
        }
        .user-dropdown-item:hover {
            background: #f1f5f9;
            color: #111827;
            text-decoration: none !important;
        }
        .user-dropdown-item--active {
            background: #eff6ff;
            color: var(--ibc-blue, #0066b3);
            font-weight: 600;
        }
        .user-dropdown-item--danger { color: #dc2626; }
        .user-dropdown-item--danger:hover { background: #fef2f2; color: #b91c1c; }

        body.dark-mode .user-dropdown-item { color: #cbd5e1; }
        body.dark-mode .user-dropdown-item:hover { background: rgba(255,255,255,0.07); color: #f1f5f9; }
        body.dark-mode .user-dropdown-item--active { background: rgba(0,102,179,0.2); color: #93c5fd; }
        body.dark-mode .user-dropdown-item--danger { color: #f87171; }
        body.dark-mode .user-dropdown-item--danger:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }

        /* Icon sizing inside dropdown items */
        .user-dropdown-item svg {
            width: 1rem !important;
            height: 1rem !important;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
        }

        /* ── LUCIDE SVG SIZING IN MOBILE BOTTOM NAV ─────────── */
        .mobile-bottom-nav-item svg {
            width: 1.25rem;
            height: 1.25rem;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
        }

        /* ── MAIN CONTENT PADDING FOR NEW UNIFIED HEADER ────── */
        /* Uses !important to override theme.css generic padding rules that
           don't account for the fixed topbar height (60px). The mobile-specific
           rule above already uses !important; this base rule ensures desktop/tablet
           also push content correctly below the fixed navbar. */
        #main-content {
            padding-top: calc(var(--topbar-height, 60px) + 1.5rem) !important;
        }

        /* ── SIDEBAR: NARROWER CLEAN FOOTER ─────────────────── */
        .sidebar-clock-area {
            padding: 0.75rem 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            text-align: center;
        }

        /* ── HEADER USER AVATAR ──────────────────────────────── */
        .header-avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
        .header-avatar-initials {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: #fff;
            user-select: none;
        }
        .header-avatar-img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .dropdown-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 dark:bg-gray-900 dark:text-white overflow-x-hidden" data-user-theme="<?php echo htmlspecialchars($currentUser['theme_preference'] ?? 'auto'); ?>">
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
        // Avatar skeleton: removes the loading shimmer once an avatar image loads or fails
        function avatarLoaded(img) { img.parentElement.classList.remove('avatar-img-loading'); }
    </script>
    <!-- Sidebar Overlay (mobile) -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <!-- Offline Banner -->
    <div id="offline-banner" role="status" aria-live="polite" aria-atomic="true" hidden
         style="position:fixed;top:0;left:0;right:0;z-index:9999;
                display:flex;align-items:center;justify-content:center;gap:0.625rem;
                background:#ef4444;color:#fff;padding:0.625rem 1rem;
                font-size:0.9375rem;font-weight:600;letter-spacing:0.01em;
                box-shadow:0 2px 8px rgba(0,0,0,0.25);
                transform:translateY(-100%);transition:transform 0.3s ease;">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false">
            <line x1="1" y1="1" x2="23" y2="23"/>
            <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>
            <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
            <path d="M10.71 5.05A16 16 0 0 1 22.56 9"/>
            <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/>
            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
            <line x1="12" y1="20" x2="12.01" y2="20"/>
        </svg>
        Du arbeitest gerade offline
    </div>
    <script>
    (function () {
        var banner = document.getElementById('offline-banner');
        function showBanner() {
            if (!banner) return;
            banner.removeAttribute('hidden');
            requestAnimationFrame(function () {
                banner.style.transform = 'translateY(0)';
            });
        }
        function hideBanner() {
            if (!banner) return;
            banner.style.transform = 'translateY(-100%)';
            banner.addEventListener('transitionend', function () {
                banner.setAttribute('hidden', '');
            }, { once: true });
        }
        if (!navigator.onLine) { showBanner(); }
        window.addEventListener('offline', showBanner);
        window.addEventListener('online',  hideBanner);
    }());
    </script>

    <?php
    // ── Pre-compute user data for header dropdown & sidebar ─────────────
    $currentUser  = Auth::user();
    $userRole     = $currentUser['role'] ?? '';
    $firstname    = '';
    $lastname     = '';
    $email        = '';
    $role         = 'User';
    $displayRoles = [];

    if ($currentUser && isset($currentUser['id'])) {
        require_once __DIR__ . '/../models/Alumni.php';
        require_once __DIR__ . '/../models/User.php';
        $profile = Alumni::getProfileByUserId($currentUser['id']);

        if ($profile && !empty($profile['first_name'])) {
            $firstname = $profile['first_name'];
            $lastname  = $profile['last_name'] ?? '';
        } elseif (!empty($currentUser['first_name'])) {
            $firstname = $currentUser['first_name'];
            $lastname  = $currentUser['last_name'] ?? '';
        }

        $email = $currentUser['email'] ?? '';
        $role  = $currentUser['role'] ?? 'User';

        // Entra / session / internal roles
        if (!empty($currentUser['entra_roles'])) {
            $rolesArray = json_decode($currentUser['entra_roles'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($rolesArray)) {
                foreach ($rolesArray as $r) {
                    $label = is_array($r) && isset($r['displayName'])
                        ? $r['displayName']
                        : translateAzureRole($r);
                    if (!empty($label)) { $displayRoles[] = $label; }
                }
            }
        } elseif (!empty($_SESSION['entra_roles'])) {
            if (is_array($_SESSION['entra_roles'])) {
                $displayRoles = extractGroupDisplayNames($_SESSION['entra_roles']);
            }
        } elseif (!empty($_SESSION['azure_roles'])) {
            if (is_array($_SESSION['azure_roles'])) {
                $displayRoles = array_filter(array_map('translateAzureRole', $_SESSION['azure_roles']));
            } else {
                $sessionRoles = json_decode($_SESSION['azure_roles'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($sessionRoles)) {
                    $displayRoles = array_filter(array_map('translateAzureRole', $sessionRoles));
                }
            }
        }

        if (empty($displayRoles)) {
            $displayRoles = [translateRole($role)];
        }
    }

    // Initials
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

    // Avatar sources (Entra ID photo via Graph API or locally stored avatar)
    $sidebarAvatarColor = getAvatarColor($firstname . ' ' . $lastname);
    if (!empty($email)) {
        $headerImgSrc  = asset('fetch-profile-photo.php') . '?email=' . urlencode($email);
        $sidebarImgSrc = $headerImgSrc;
    } else {
        $headerImgSrc  = asset(User::getProfilePictureUrl((int)($currentUser['id'] ?? 0), $currentUser ?? []));
        $sidebarImgSrc = $headerImgSrc;
    }
    ?>

    <!-- ════════════════════════════════════════════════════════════
         UNIFIED TOP HEADER BAR
         Desktop: offset left by sidebar width (md:left-64)
         Mobile:  full-width with hamburger
         Uses backdrop-blur-md for the frosted glass effect
         ════════════════════════════════════════════════════════════ -->
    <header id="top-header" class="bg-white/80 backdrop-blur-md border-b border-gray-100 shadow-sm transition-colors duration-300 dark:bg-slate-900/80 dark:border-slate-700/50" aria-label="Hauptnavigation oben">
        <!-- Mobile hamburger: hidden – sidebar access on mobile is via the bottom nav "Mehr" button -->
        <button id="mobile-menu-btn"
                class="hidden"
                aria-label="Menü öffnen"
                aria-expanded="false"
                aria-controls="sidebar">
            <svg id="menu-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path id="menu-icon-top"    stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16"  class="transition-all duration-300"></path>
                <path id="menu-icon-middle" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 12h16" class="transition-all duration-300"></path>
                <path id="menu-icon-bottom" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 18h16" class="transition-all duration-300"></path>
            </svg>
        </button>

        <!-- Page title -->
        <div class="flex-1 min-w-0 px-2">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-100 truncate leading-snug">
                <?php echo htmlspecialchars($title ?? 'Dashboard'); ?>
            </p>
            <p class="text-[11px] text-slate-400 dark:text-slate-500 hidden sm:block leading-snug">IBC Business Consulting</p>
        </div>

        <!-- Right-side actions -->
        <div class="flex items-center gap-1 shrink-0">
            <!-- Theme toggle -->
            <button id="theme-toggle"
                    aria-label="Zwischen hellem und dunklem Modus wechseln">
                <i id="theme-icon" data-lucide="moon" class="w-4 h-4" aria-hidden="true"></i>
            </button>

            <!-- User dropdown trigger: shows Entra ID avatar (via Graph API) -->
            <button id="user-dropdown-btn"
                    aria-label="Benutzerprofil öffnen"
                    aria-expanded="false"
                    aria-haspopup="true">
                <div class="header-avatar avatar-img-loading" style="background-color:<?php echo htmlspecialchars($sidebarAvatarColor); ?>">
                    <span class="header-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <img src="<?php echo htmlspecialchars($headerImgSrc); ?>"
                         alt=""
                         class="header-avatar-img"
                         onload="avatarLoaded(this)"
                         onerror="this.onerror=null; this.style.display='none'; avatarLoaded(this);">
                </div>
                <span class="header-user-name hidden sm:block">
                    <?php echo htmlspecialchars(!empty($firstname) ? $firstname : ($email ?? '')); ?>
                </span>
                <i data-lucide="chevron-down" class="w-3.5 h-3.5 hidden sm:block" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <!-- User Dropdown Panel (position: fixed, toggles open/closed via JS) -->
    <div id="user-dropdown" role="menu" aria-labelledby="user-dropdown-btn" aria-hidden="true">
        <!-- User info -->
        <div class="p-4 user-dropdown-divider">
            <div class="flex items-center gap-3">
                <div class="dropdown-avatar avatar-img-loading" style="background-color:<?php echo htmlspecialchars($sidebarAvatarColor); ?>">
                    <span class="header-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($initials); ?></span>
                    <img src="<?php echo htmlspecialchars($headerImgSrc); ?>"
                         alt=""
                         class="header-avatar-img"
                         onload="avatarLoaded(this)"
                         onerror="this.onerror=null; this.style.display='none'; avatarLoaded(this);">
                </div>
                <div class="min-w-0 flex-1">
                    <?php if (!empty($firstname) || !empty($lastname)): ?>
                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">
                        <?php echo htmlspecialchars(trim($firstname . ' ' . $lastname)); ?>
                    </p>
                    <?php endif; ?>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate"><?php echo htmlspecialchars($email); ?></p>
                    <?php if (!empty($displayRoles)): ?>
                    <span class="role-badge inline-flex items-center gap-1 mt-1 max-w-full bg-blue-50 text-blue-700 rounded-full px-3 py-1 dark:bg-blue-900/30 dark:text-blue-300"
                          title="<?php echo htmlspecialchars(implode(', ', $displayRoles)); ?>"
                          aria-label="Rolle: <?php echo htmlspecialchars($displayRoles[0]); ?>">
                        <i class="fas <?php echo getRoleIcon($role); ?> flex-shrink-0" aria-hidden="true"></i>
                        <span class="truncate"><?php echo htmlspecialchars($displayRoles[0]); ?></span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Navigation links -->
        <div class="p-2">
            <a href="<?php echo asset('pages/auth/profile.php'); ?>"
               class="user-dropdown-item <?php echo is_nav_active('/auth/profile.php') ? 'user-dropdown-item--active' : ''; ?>"
               role="menuitem"
               <?php echo is_nav_active('/auth/profile.php') ? 'aria-current="page"' : ''; ?>>
                <i data-lucide="user" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
                <span>Mein Profil</span>
            </a>
            <a href="<?php echo asset('pages/auth/settings.php'); ?>"
               class="user-dropdown-item <?php echo is_nav_active('/auth/settings.php') ? 'user-dropdown-item--active' : ''; ?>"
               role="menuitem"
               <?php echo is_nav_active('/auth/settings.php') ? 'aria-current="page"' : ''; ?>>
                <i data-lucide="settings" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
                <span>Einstellungen</span>
            </a>
            <button id="theme-toggle-dropdown" class="user-dropdown-item" role="menuitem" type="button">
                <i id="theme-icon-dropdown" data-lucide="moon" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
                <span id="theme-text-dropdown">Darkmode</span>
            </button>
        </div>
        <hr class="user-dropdown-divider">
        <div class="p-2">
            <a href="<?php echo asset('pages/auth/logout.php'); ?>"
               class="user-dropdown-item user-dropdown-item--danger"
               role="menuitem">
                <i data-lucide="log-out" class="w-4 h-4 shrink-0" aria-hidden="true"></i>
                <span>Abmelden</span>
            </a>
        </div>
    </div>

    <!-- Sidebar (Business Consulting – dark, fixed left, Lucide icons) -->
    <aside id="sidebar" class="sidebar glass-sidebar fixed left-0 top-0 h-screen w-64 z-40 text-white shadow-2xl flex flex-col backdrop-blur-2xl" aria-label="Seitenleiste">
        <?php
        // $currentUser, $userRole already set by the early PHP block above
        ?>
        <div class="px-5 pt-5 pb-8 flex-1 overflow-y-auto sidebar-scroll">
            <!-- IBC Logo in Navbar -->
            <div class="mb-6 px-3 pt-2 flex justify-center">
                <img src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>" alt="IBC Logo" class="w-4/5 h-auto drop-shadow-lg" decoding="async">
            </div>
            
            <nav aria-label="Hauptnavigation">
                <!-- Dashboard (All) -->
                <a href="<?php echo asset('pages/dashboard/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/dashboard/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/dashboard/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="layout-dashboard" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Dashboard</span>
                </a>

                <!-- Kommunikation Section -->
                <div class="sidebar-section-label">Kommunikation</div>

                <!-- Newsletter (All authenticated users) -->
                <a href="<?php echo asset('pages/newsletter/index.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/newsletter/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/newsletter/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="mail" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Newsletter</span>
                </a>

                <!-- Blog (All) -->
                <a href="<?php echo asset('pages/blog/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/blog/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/blog/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="newspaper" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Blog</span>
                </a>

                <!-- Job- & Praktikumsbörse (All) -->
                <a href="<?php echo asset('pages/jobs/index.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/jobs/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/jobs/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="briefcase" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Job- &amp; Praktikumsbörse</span>
                </a>

                <!-- Community Section -->
                <div class="sidebar-section-label">Community</div>

                <!-- Events (All) -->
                <a href="<?php echo asset('pages/events/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/events/') && !is_nav_active('/events/helpers.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/events/') && !is_nav_active('/events/helpers.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="calendar" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Events</span>
                </a>

                <!-- Helfersystem (All) -->
                <a href="<?php echo asset('pages/events/helpers.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/events/helpers.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/events/helpers.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="heart-handshake" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Helfersystem</span>
                </a>

                <!-- Projekte (All) -->
                <a href="<?php echo asset('pages/projects/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/projects/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/projects/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="git-branch" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Projekte</span>
                </a>

                <!-- Ideenbox (Members, Candidates, Head, Board) -->
                <?php if (Auth::canAccessPage('ideas')): ?>
                <a href="<?php echo asset('pages/ideas/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/ideas/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/ideas/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="lightbulb" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Ideenbox</span>
                </a>
                <?php endif; ?>

                <!-- Daten &amp; Datenbanken Section -->
                <div class="sidebar-section-label">Daten</div>

                <!-- Alumni (All) -->
                <a href="<?php echo asset('pages/alumni/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/alumni/') && !is_nav_active('/alumni/requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="graduation-cap" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Alumni-Datenbank</span>
                </a>

                <!-- Mitglieder (Board, Head, Member, Candidate) -->
                <?php if (Auth::canAccessPage('members')): ?>
                <a href="<?php echo asset('pages/members/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/members/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/members/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="users" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Mitglieder-Datenbank</span>
                </a>
                <?php endif; ?>

                <!-- Tools &amp; Services Section -->
                <div class="sidebar-section-label">Tools &amp; Services</div>

                <!-- Inventar (All) -->
                <a href="<?php echo asset('pages/inventory/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/inventory/') && !is_nav_active('/my_rentals.php') && !is_nav_active('/checkout.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/inventory/') && !is_nav_active('/my_rentals.php') && !is_nav_active('/checkout.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="package" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Inventar</span>
                </a>

                <!-- Ausleihe (All) -->
                <a href="<?php echo asset('pages/inventory/my_rentals.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/my_rentals.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/my_rentals.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="clipboard-list" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Ausleihe</span>
                </a>

                <!-- Rechnungen (All roles) -->
                <?php if (Auth::canAccessPage('invoices')): ?>
                <a href="<?php echo asset('pages/invoices/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/invoices/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/invoices/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="receipt" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Rechnungen</span>
                </a>
                <?php endif; ?>

                <!-- Schulungsanfrage (Alumni, Alumni-Board) -->
                <?php if (Auth::canAccessPage('training_requests')): ?>
                <a href="<?php echo asset('pages/alumni/requests.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/alumni/requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/alumni/requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="book-open" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Schulungsanfrage</span>
                </a>
                <?php endif; ?>

                <!-- Nützliche Links (Board + Alumni Vorstand + Alumni Finanzprüfer) -->
                <?php if (in_array($userRole, ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz'])): ?>
                <a href="<?php echo asset('pages/links/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/links/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/links/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="link" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Nützliche Links</span>
                </a>
                <?php endif; ?>

                <!-- Shop (All authenticated users) -->
                <a href="<?php echo htmlspecialchars(_env('SHOPLINK', '#')); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="sidebar-nav-item">
                    <i data-lucide="shopping-cart" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Shop</span>
                </a>

                <!-- Umfragen (Polls - All authenticated users) -->
                <?php if (Auth::canAccessPage('polls')): ?>
                <a href="<?php echo asset('pages/polls/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/polls/') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/polls/') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="bar-chart-2" class="sidebar-nav-icon" aria-hidden="true"></i>
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
                    <i data-lucide="user-cog" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Benutzerverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Admin Dashboard -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/index.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/index.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/index.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="gauge" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Dashboard</span>
                </a>
                <?php endif; ?>

                <!-- Inventarverwaltung -->
                <?php if (Auth::hasRole(['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter']) || Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/rental_returns.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/rental_returns.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/rental_returns.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="clipboard-check" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Inventarverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Bewerbungsverwaltung (Board only) -->
                <?php if (Auth::isBoard()): ?>
                <a href="<?php echo asset('pages/admin/project_applications.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/project_applications.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/project_applications.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="file-text" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Bewerbungsverwaltung</span>
                </a>
                <?php endif; ?>

                <!-- Alumni-Anfragen (Alumni-Führung + Vorstand) -->
                <?php if (Auth::hasRole(['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'])): ?>
                <a href="<?php echo asset('pages/admin/alumni_requests.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/alumni_requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/alumni_requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="user-check" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Alumni-Anfragen</span>
                </a>
                <?php endif; ?>

                <!-- Neue Alumni-Anfragen (Alumni-Führung + Vorstand) -->
                <?php if (Auth::hasRole(['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'])): ?>
                <a href="<?php echo asset('pages/admin/neue_alumni_requests.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/neue_alumni_requests.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/neue_alumni_requests.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="user-plus" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Neue Alumni</span>
                </a>
                <?php endif; ?>

                <!-- Digitale Visitenkarten (Vorstand + Ressortleiter) -->
                <?php if (Auth::canCreateBasicContent()): ?>
                <a href="<?php echo asset('pages/admin/vcards.php'); ?>"
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/vcards.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/vcards.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="credit-card" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Digitale Visitenkarten</span>
                </a>
                <?php endif; ?>

                <!-- Systemeinstellungen (Board roles + alumni_vorstand + alumni_finanz) -->
                <?php if (Auth::canAccessSystemSettings()): ?>
                <a href="<?php echo asset('pages/admin/settings.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/settings.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/settings.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="settings" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Systemeinstellungen</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken Section Divider -->
                <?php if (Auth::isAdmin()): ?>
                <div class="sidebar-section-label">Statistiken</div>
                <?php endif; ?>

                <!-- Event-Statistiken (Admin roles only) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/event_stats.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/event_stats.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/event_stats.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="bar-chart" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Event-Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Statistiken (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/stats.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/stats.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/stats.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="pie-chart" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Statistiken</span>
                </a>
                <?php endif; ?>

                <!-- Audit Logs (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/audit.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/audit.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/audit.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="scroll" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>Audit Logs</span>
                </a>
                <?php endif; ?>

                <!-- System Health (All board members) -->
                <?php if (Auth::isAdmin()): ?>
                <a href="<?php echo asset('pages/admin/db_maintenance.php'); ?>" 
                   class="sidebar-nav-item <?php echo is_nav_active('/admin/db_maintenance.php') ? 'sidebar-nav-item--active' : ''; ?>"
                   <?php echo is_nav_active('/admin/db_maintenance.php') ? 'aria-current="page"' : ''; ?>>
                    <i data-lucide="database" class="sidebar-nav-icon" aria-hidden="true"></i>
                    <span>System Health</span>
                </a>
                <?php endif; ?>

            </nav>
        </div>

        <!-- Sidebar Footer: Live Clock only (user profile/actions are in the header dropdown) -->
        <div class="sidebar-clock-area">
            <div id="live-clock" class='text-xs text-white/70 font-mono'>
                <!-- JavaScript will update this -->
            </div>
        </div>
    </aside>


    <!-- Main Content -->
    <main id="main-content" role="main" class="ml-64 min-h-screen px-4 sm:px-6 lg:px-8 bg-slate-50 dark:bg-gray-900 dark:text-white transition-all duration-300 ease-in-out" style="padding-bottom: max(1rem, env(safe-area-inset-bottom, 0))">
        <!-- Page content; AJAX-loaded sections inside should use .skeleton-enterprise or .skeleton
             on placeholder elements until data arrives, then swap them out via JS. -->
        <div class="max-w-7xl mx-auto">
            <?php echo $content ?? ''; ?>
        </div>
        <footer class="max-w-7xl mx-auto mt-8 py-4 border-t border-gray-200 dark:border-slate-700">
            <div class="flex flex-col items-center md:flex-row md:justify-between gap-2 text-sm text-gray-500 dark:text-gray-400">
                <p>&copy; <?php echo date('Y'); ?> Institut für Business Consulting Furtwangen. Alle Rechte vorbehalten.</p>
                <div class="flex gap-4">
                    <a href="https://business-consulting.de/impressum.html" target="_blank" rel="noopener noreferrer" class="hover:text-ibc-green transition-all duration-300 ease-in-out" aria-label="Impressum – Rechtliche Hinweise">Impressum</a>
                </div>
            </div>
        </footer>
    </main>

    <!-- Back-to-top button with scroll progress ring (shown after 300 px scroll) -->
    <button id="back-to-top" aria-label="Zurück nach oben" title="Zurück nach oben">
        <!-- SVG progress ring drawn around the button -->
        <svg class="btt-ring" aria-hidden="true" focusable="false">
        <circle id="btt-progress-circle" cx="50%" cy="50%" r="12"/>
        </svg>
        <i data-lucide="chevron-up" class="w-5 h-5" aria-hidden="true"></i>
    </button>

    <!-- Swipe-to-open hint strip (left edge, touch devices only, styled in theme.css) -->
    <div id="swipe-hint" aria-hidden="true"></div>

    <!-- Mobile Bottom Navigation Bar (visible on small screens only, sidebar becomes bottom nav) -->
    <nav id="mobile-bottom-nav" class="mobile-bottom-nav glass-bottom-nav backdrop-blur-md md:hidden" role="navigation" aria-label="Schnellnavigation">
        <a href="<?php echo asset('pages/dashboard/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo is_nav_active('/dashboard/') ? 'active' : ''; ?>"
           aria-label="Dashboard"
           <?php echo is_nav_active('/dashboard/') ? 'aria-current="page"' : ''; ?>>
            <i data-lucide="layout-dashboard" aria-hidden="true"></i>
            <span>Home</span>
        </a>
        <a href="<?php echo asset('pages/events/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'active' : ''; ?>"
           aria-label="Events"
           <?php echo (is_nav_active('/events/') && !is_nav_active('/events/helpers.php')) ? 'aria-current="page"' : ''; ?>>
            <i data-lucide="calendar" aria-hidden="true"></i>
            <span>Events</span>
        </a>
        <a href="<?php echo asset('pages/alumni/index.php'); ?>"
           class="mobile-bottom-nav-item <?php echo is_nav_active('/alumni/') ? 'active' : ''; ?>"
           aria-label="Alumni-Datenbank"
           <?php echo is_nav_active('/alumni/') ? 'aria-current="page"' : ''; ?>>
            <i data-lucide="graduation-cap" aria-hidden="true"></i>
            <span>Alumni</span>
        </a>
        <button id="bottom-nav-more-btn"
                class="mobile-bottom-nav-item"
                aria-label="Menü öffnen"
                aria-expanded="false"
                aria-controls="sidebar">
            <i data-lucide="layout-grid" aria-hidden="true"></i>
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
            const mobileMenu = document.getElementById('mobile-menu');

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
                // Close slide-down menu before opening sidebar (via navbar-scroll.js utility)
                window.navbarScrollUtils?.closeMobileMenu?.();
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

            // Expose sidebar helpers for mobile-animations.js swipe gestures
            window.__sidebarOpen  = openSidebar;
            window.__sidebarClose = closeSidebar;

            // Note: hamburger button in top header now toggles the sidebar on mobile
            if (btn && sidebar) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleSidebar();
                });
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

            // Escape key closes sidebar or mobile nav menu (accessibility)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (sidebar?.classList.contains('open')) {
                        closeSidebar();
                        syncBottomNavMoreBtn();
                    } else if (mobileMenu && !mobileMenu.classList.contains('hidden')) {
                        window.navbarScrollUtils?.closeMobileMenu?.();
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
        });
        
        // Sidebar scroll position: restore on load, save on scroll and before unload
        (function() {
            const sidebarScroll = document.querySelector('.sidebar-scroll');
            if (!sidebarScroll) return;
            var savedPos = localStorage.getItem('sidebarScrollPos');
            requestAnimationFrame(function() {
                if (savedPos !== null) {
                    sidebarScroll.scrollTop = parseInt(savedPos, 10);
                }
                // Always ensure the active nav item is visible within the sidebar.
                // If the saved scroll position hides the active item (or there is
                // no saved position and the item is below the fold), scroll it
                // into view without disturbing the overall position more than needed.
                var activeItem = sidebarScroll.querySelector('.sidebar-nav-item--active');
                if (activeItem) {
                    var itemTop    = activeItem.offsetTop;
                    var itemBottom = itemTop + activeItem.offsetHeight;
                    var scrollTop    = sidebarScroll.scrollTop;
                    var scrollBottom = scrollTop + sidebarScroll.clientHeight;
                    if (itemBottom > scrollBottom || itemTop < scrollTop) {
                        activeItem.scrollIntoView({ block: 'nearest', behavior: 'auto' });
                    }
                }
            });
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
        const themeToggleDropdown = document.getElementById('theme-toggle-dropdown');
        const themeIconDropdown = document.getElementById('theme-icon-dropdown');
        const themeTextDropdown = document.getElementById('theme-text-dropdown');
        
        // Get user's saved theme preference from database (via data attribute)
        const userThemePreference = document.body.getAttribute('data-user-theme') || 'auto';
        
        // Load theme preference (localStorage overrides database preference)
        let currentTheme = localStorage.getItem('theme') || userThemePreference;

        // Helper: swap a Lucide icon by replacing the data-lucide attribute and re-rendering
        function setLucideIcon(el, iconName) {
            if (!el) return;
            el.setAttribute('data-lucide', iconName);
            if (typeof lucide !== 'undefined') lucide.createIcons({ nodes: [el] });
        }
        
        // Apply theme based on preference
        function applyTheme(theme) {
            const isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (isDark) {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.classList.add('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                setLucideIcon(themeIcon, 'sun');
                setLucideIcon(themeIconDropdown, 'sun');
                if (themeTextDropdown) themeTextDropdown.textContent = 'Lightmode';
            } else {
                document.body.classList.remove('dark-mode', 'dark');
                document.documentElement.classList.remove('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'light');
                document.documentElement.style.colorScheme = 'light';
                setLucideIcon(themeIcon, 'moon');
                setLucideIcon(themeIconDropdown, 'moon');
                if (themeTextDropdown) themeTextDropdown.textContent = 'Darkmode';
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
                setLucideIcon(themeIcon, 'moon');
                setLucideIcon(themeIconDropdown, 'moon');
                if (themeTextDropdown) themeTextDropdown.textContent = 'Darkmode';
            } else {
                document.body.classList.add('dark-mode', 'dark');
                document.documentElement.classList.add('dark-mode', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.style.colorScheme = 'dark';
                localStorage.setItem('theme', 'dark');
                setLucideIcon(themeIcon, 'sun');
                setLucideIcon(themeIconDropdown, 'sun');
                if (themeTextDropdown) themeTextDropdown.textContent = 'Lightmode';
            }
        }

        themeToggle?.addEventListener('click', toggleTheme);
        themeToggleDropdown?.addEventListener('click', toggleTheme);

        // ── User Dropdown Toggle ──────────────────────────────────────────
        const userDropdownBtn = document.getElementById('user-dropdown-btn');
        const userDropdown    = document.getElementById('user-dropdown');

        function openUserDropdown() {
            if (!userDropdown) return;
            userDropdown.classList.add('open');
            userDropdown.setAttribute('aria-hidden', 'false');
            userDropdownBtn?.setAttribute('aria-expanded', 'true');
        }
        function closeUserDropdown() {
            if (!userDropdown) return;
            userDropdown.classList.remove('open');
            userDropdown.setAttribute('aria-hidden', 'true');
            userDropdownBtn?.setAttribute('aria-expanded', 'false');
        }

        userDropdownBtn?.addEventListener('click', function(e) {
            e.stopPropagation();
            if (userDropdown?.classList.contains('open')) {
                closeUserDropdown();
            } else {
                openUserDropdown();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (userDropdown && !userDropdown.contains(e.target) && !userDropdownBtn?.contains(e.target)) {
                closeUserDropdown();
            }
        });
        // Close dropdown on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && userDropdown?.classList.contains('open')) {
                closeUserDropdown();
                userDropdownBtn?.focus();
            }
        });
        
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

        // ── PJAX: auto-close sidebar and user dropdown on navigation ─────────
        document.addEventListener('pjax:complete', function () {
            // Close sidebar on mobile if still open after pjax navigation
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
                syncBottomNavMoreBtn();
            }
            // Close user dropdown if open
            closeUserDropdown();
            // Ensure body scroll lock is cleared
            window.navbarScrollUtils?.ensureScrollUnlocked?.();
            // Re-render Lucide icons in the new page content
            if (typeof lucide !== 'undefined') { lucide.createIcons(); }
        });

    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="<?php echo asset('js/navbar-scroll.js'); ?>" defer></script>
    <script src="<?php echo asset('js/pjax-navigation.js'); ?>" defer></script>
    <script src="<?php echo asset('js/mobile-animations.js'); ?>" defer></script>
    <!-- PWA: Service Worker registration & Install-App modal -->
    <script>
    (function () {
        // ── Service Worker ──────────────────────────────────────────────────
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('<?php echo asset('sw.js'); ?>', { scope: '/' })
                    .catch(function (err) { console.error('[SW] Registration failed:', err); });
            });
        }

        // ── Install-App Modal (A2HS) ────────────────────────────────────────
        // Shown once per user per browser (localStorage), on first visit after
        // login where the browser fires beforeinstallprompt.
        var deferredPrompt = null;
        var userId         = <?php echo json_encode((int)($_SESSION['user_id'] ?? 0)); ?>;
        var SHOWN_KEY      = 'pwa_install_shown_' + userId;

        // Skip if already installed, already shown, or no valid user session
        if (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            localStorage.getItem(SHOWN_KEY) ||
            userId === 0
        ) {
            return;
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferredPrompt = e;
            // Small delay so the page renders first
            setTimeout(showInstallModal, 1500);
        });

        function showInstallModal() {
            var modal = document.getElementById('pwa-install-modal');
            if (!modal) return;
            localStorage.setItem(SHOWN_KEY, '1');
            modal.removeAttribute('hidden');
            requestAnimationFrame(function () {
                modal.classList.add('pwa-modal--visible');
            });
        }

        function hideInstallModal() {
            var modal = document.getElementById('pwa-install-modal');
            if (!modal) return;
            modal.classList.remove('pwa-modal--visible');
            modal.addEventListener('transitionend', function () {
                modal.setAttribute('hidden', '');
            }, { once: true });
        }

        window.__pwaInstall = function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function () {
                deferredPrompt = null;
                hideInstallModal();
            });
        };

        window.__pwaDismiss = function () {
            hideInstallModal();
        };
    }());
    </script>

    <!-- Install-App Toast (A2HS, hidden until beforeinstallprompt fires) -->
    <div id="pwa-install-modal" hidden role="dialog" aria-modal="false"
         aria-labelledby="pwa-toast-title" aria-describedby="pwa-toast-desc">
        <div class="pwa-toast-inner dark:bg-slate-800">
            <!-- Icon + text -->
            <div style="display:flex;align-items:center;gap:0.875rem;flex:1;min-width:0;">
                <div style="width:2.5rem;height:2.5rem;background:linear-gradient(135deg,#0066b3,#0099e6);
                            border-radius:0.625rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-mobile-alt" style="color:#fff;font-size:1.125rem;" aria-hidden="true"></i>
                </div>
                <div style="min-width:0;">
                    <p id="pwa-toast-title" style="font-weight:700;font-size:0.9375rem;margin:0 0 0.125rem;
                                                   color:#1e293b;" class="dark:text-slate-100">
                        App installieren
                    </p>
                    <p id="pwa-toast-desc" style="font-size:0.8125rem;margin:0;color:#64748b;" class="dark:text-slate-400">
                        Schneller Zugriff &middot; Offline-Nutzung
                    </p>
                </div>
            </div>
            <!-- Action buttons -->
            <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                <button onclick="__pwaInstall()" type="button"
                        style="padding:0.5rem 1rem;background:linear-gradient(135deg,#0066b3,#0099e6);
                               color:#fff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.875rem;
                               cursor:pointer;white-space:nowrap;transition:opacity 0.15s;min-height:40px;"
                        onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                    <i class="fas fa-download" style="margin-right:0.375rem;" aria-hidden="true"></i>Installieren
                </button>
                <button onclick="__pwaDismiss()" type="button" aria-label="Schließen"
                        style="width:2rem;height:2rem;background:transparent;border:none;border-radius:0.375rem;
                               cursor:pointer;display:flex;align-items:center;justify-content:center;
                               color:#94a3b8;font-size:1rem;transition:color 0.15s;flex-shrink:0;"
                        onmouseover="this.style.color='#475569'" onmouseout="this.style.color='#94a3b8'">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    <style>
        #pwa-install-modal {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 1075;
            max-width: 26rem;
            width: calc(100vw - 2rem);
        }
        .pwa-toast-inner {
            background: #fff;
            border-radius: 0.875rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08);
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            pointer-events: auto;
            transform: translateY(1.5rem);
            opacity: 0;
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1), opacity 0.3s ease;
        }
        #pwa-install-modal.pwa-modal--visible .pwa-toast-inner {
            transform: translateY(0);
            opacity: 1;
        }
        @media (max-width: 767px) {
            #pwa-install-modal {
                /* 60px = bottom nav visible height + ~12px gap; safe-area for home indicator */
                bottom: calc(4.5rem + env(safe-area-inset-bottom, 0px));
                right: 1rem;
                left: 1rem;
                width: auto;
                max-width: none;
            }
        }
    </style>

    <?php if (isset($_SESSION['show_2fa_nudge']) && $_SESSION['show_2fa_nudge']): ?>
    <style>
    /* Only z-index is kept here as an !important override because Tailwind's
       compiled CSS may not include the arbitrary z-[1075] value.
       All layout/positioning is handled by the Tailwind utility classes on
       the element itself (fixed inset-0 flex items-center justify-center p-4),
       matching the pattern used by #role-notice-modal. */
    #tfa-nudge-modal { z-index: 1075 !important; }
    </style>
    <div id="tfa-nudge-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="tfa-nudge-title">
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
    <!-- Lucide Icons: replaces data-lucide="..." elements with inline SVGs (self-hosted) -->
    <script src="<?php echo asset('assets/js/lucide.min.js') . '?v=1'; ?>"></script>
    <script>
        if (typeof lucide !== 'undefined') { lucide.createIcons(); }
    </script>

</body>
</html>
<!-- ✅ Sidebar visibility: Invoices restricted to vorstand_finanzen only via canManageInvoices() -->
