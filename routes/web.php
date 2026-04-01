<?php
/**
 * Web Routes
 *
 * All clean-URL routes are registered here via the FastRoute RouteCollector ($r).
 * Each handler is a callable that includes the appropriate page file.
 *
 * Auth-protected routes call Auth::check() before including the page so that the
 * central front-controller is the authoritative gatekeeper.  Individual pages still
 * contain their own auth guards for backward-compatibility when accessed directly.
 *
 * Migration note: Pages that redirect internally (e.g. header('Location: ../auth/…'))
 * should be updated to use BASE_URL-based absolute URLs as they are migrated.
 */

declare(strict_types=1);

use function FastRoute\simpleDispatcher;

$pages = __DIR__ . '/../pages';

// ---------------------------------------------------------------------------
// Helper: redirect to a clean URL, absorbing any output buffer
// ---------------------------------------------------------------------------
$redirect = static function (string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    }
    exit;
};

// ---------------------------------------------------------------------------
// Helper: require authentication, redirect to /login otherwise
// ---------------------------------------------------------------------------
$requireAuth = static function () use ($redirect): void {
    if (!Auth::check()) {
        $redirect(BASE_URL . '/login');
    }
};

// ===========================================================================
// PUBLIC ROUTES  (no authentication required)
// ===========================================================================

// Root – redirect to dashboard when authenticated, login otherwise
$r->addRoute('GET', '/', static function () use ($redirect): void {
    $redirect(Auth::check() ? BASE_URL . '/dashboard' : BASE_URL . '/login');
});

// Login
$r->addRoute(['GET', 'POST'], '/login', static function () use ($pages): void {
    include $pages . '/auth/login.php';
});

// Logout
$r->addRoute(['GET', 'POST'], '/logout', static function () use ($pages): void {
    include $pages . '/auth/logout.php';
});

// 2FA verification
$r->addRoute(['GET', 'POST'], '/verify-2fa', static function () use ($pages): void {
    include $pages . '/auth/verify_2fa.php';
});

// Onboarding (public, completes first-time profile setup)
$r->addRoute(['GET', 'POST'], '/onboarding', static function () use ($pages): void {
    include $pages . '/auth/onboarding.php';
});

// Public alumni pages (no login required)
$r->addRoute(['GET', 'POST'], '/alumni-recovery', static function () use ($pages): void {
    include $pages . '/public/alumni_recovery.php';
});

$r->addRoute(['GET', 'POST'], '/neue-alumni', static function () use ($pages): void {
    include $pages . '/public/neue_alumni.php';
});

// Impressum / legal notice
$r->addRoute('GET', '/impressum', static function () use ($pages): void {
    include $pages . '/impressum.php';
});

// ===========================================================================
// AUTHENTICATED ROUTES
// ===========================================================================

// Dashboard
$r->addRoute('GET', '/dashboard', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/dashboard/index.php';
});

// Profile
$r->addRoute(['GET', 'POST'], '/profile', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/auth/profile.php';
});

// Profile settings
$r->addRoute(['GET', 'POST'], '/profile/settings', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/auth/settings.php';
});

// ---------------------------------------------------------------------------
// Members
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/members', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/members/index.php';
});

$r->addRoute('GET', '/members/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/members/view.php';
});

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/events', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/events/index.php';
});

$r->addRoute('GET', '/events/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/events/view.php';
});

$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/edit', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/events/edit.php';
});

$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/manage', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/events/manage.php';
});

$r->addRoute('GET', '/events/{id:\d+}/statistics', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/events/statistics.php';
});

// ---------------------------------------------------------------------------
// Blog
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/blog', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/blog/index.php';
});

$r->addRoute('GET', '/blog/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/blog/view.php';
});

$r->addRoute(['GET', 'POST'], '/blog/{id:\d+}/edit', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/blog/edit.php';
});

// ---------------------------------------------------------------------------
// Projects
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/projects', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/projects/index.php';
});

$r->addRoute('GET', '/projects/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/projects/view.php';
});

$r->addRoute(['GET', 'POST'], '/projects/{id:\d+}/manage', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/projects/manage.php';
});

$r->addRoute('GET', '/projects/{id:\d+}/applications', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/projects/applications.php';
});

// ---------------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/inventory', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/inventory/index.php';
});

$r->addRoute('GET', '/inventory/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/inventory/view.php';
});

$r->addRoute(['GET', 'POST'], '/inventory/add', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/inventory/add.php';
});

$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/edit', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/inventory/edit.php';
});

$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkout', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/inventory/checkout.php';
});

$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkin', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/inventory/checkin.php';
});

$r->addRoute('GET', '/inventory/my-checkouts', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/inventory/my_checkouts.php';
});

$r->addRoute('GET', '/inventory/my-rentals', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/inventory/my_rentals.php';
});

// ---------------------------------------------------------------------------
// Invoices
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/invoices', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/invoices/index.php';
});

// ---------------------------------------------------------------------------
// Alumni
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/alumni', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/alumni/index.php';
});

$r->addRoute('GET', '/alumni/{id:\d+}', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/alumni/view.php';
});

$r->addRoute(['GET', 'POST'], '/alumni/{id:\d+}/edit', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/alumni/edit.php';
});

$r->addRoute('GET', '/alumni/requests', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/alumni/requests.php';
});

// ---------------------------------------------------------------------------
// Jobs
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/jobs', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/jobs/index.php';
});

$r->addRoute(['GET', 'POST'], '/jobs/create', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/jobs/create.php';
});

$r->addRoute(['GET', 'POST'], '/jobs/{id:\d+}/edit', static function (array $vars) use ($pages, $requireAuth): void {
    $requireAuth();
    $_GET['id'] = $vars['id'];
    include $pages . '/jobs/edit.php';
});

// ---------------------------------------------------------------------------
// Newsletter
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/newsletter', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/newsletter/index.php';
});

// ---------------------------------------------------------------------------
// Polls
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/polls', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/polls/index.php';
});

// ---------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/links', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/links/index.php';
});

// ---------------------------------------------------------------------------
// Ideas
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/ideas', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/ideas/index.php';
});

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------
$r->addRoute('GET', '/admin', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/index.php';
});

$r->addRoute('GET', '/admin/users', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/users.php';
});

$r->addRoute(['GET', 'POST'], '/admin/settings', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/settings.php';
});

$r->addRoute('GET', '/admin/stats', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/stats.php';
});

$r->addRoute('GET', '/admin/audit', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/audit.php';
});

$r->addRoute(['GET', 'POST'], '/admin/inventory', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/inventory_dashboard.php';
});

$r->addRoute(['GET', 'POST'], '/admin/alumni-requests', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/alumni_requests.php';
});

$r->addRoute(['GET', 'POST'], '/admin/neue-alumni-requests', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/neue_alumni_requests.php';
});

$r->addRoute(['GET', 'POST'], '/admin/vcards', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/vcards.php';
});

$r->addRoute('GET', '/admin/project-applications', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/project_applications.php';
});

$r->addRoute(['GET', 'POST'], '/admin/db-maintenance', static function () use ($pages, $requireAuth): void {
    $requireAuth();
    include $pages . '/admin/db_maintenance.php';
});
