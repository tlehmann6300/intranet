<?php
/**
 * Web Routes
 *
 * Routes use 'ControllerClass@method' string notation.
 * Protected routes use the middleware tuple format:
 *   ['ControllerClass@method', [MiddlewareClass::class, ...]]
 *
 * Available middleware:
 *   \App\Middleware\AuthMiddleware  – requires authenticated session
 *   \App\Middleware\AdminMiddleware – requires board-level role (implies auth)
 */

declare(strict_types=1);

use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;

// ===========================================================================
// PUBLIC ROUTES  (no middleware)
// ===========================================================================

$r->addRoute('GET', '/', static function () use ($redirect): void {
    $redirect(\Auth::check() ? \BASE_URL . '/dashboard' : \BASE_URL . '/login');
});

$r->addRoute(['GET', 'POST'], '/login',          ['App\Controllers\AuthController@login',        [new RateLimitMiddleware('login', 10, 600)]]);
$r->addRoute(['GET', 'POST'], '/logout',         'App\Controllers\AuthController@logout');
$r->addRoute(['GET', 'POST'], '/verify-2fa',     'App\Controllers\AuthController@verify2fa');
$r->addRoute(['GET', 'POST'], '/onboarding',     'App\Controllers\AuthController@onboarding');

// Microsoft OAuth flow (replaces auth/login_start.php and auth/callback.php)
$r->addRoute('GET', '/auth/login-start', ['App\Controllers\AuthController@loginStart', [new RateLimitMiddleware('oauth_initiate', 20, 600)]]);
$r->addRoute('GET', '/auth/callback',    'App\Controllers\AuthController@oauthCallback');

$r->addRoute(['GET', 'POST'], '/alumni-recovery', 'App\Controllers\PublicController@alumniRecovery');
$r->addRoute(['GET', 'POST'], '/neue-alumni',      'App\Controllers\PublicController@neueAlumni');
$r->addRoute('GET',           '/impressum',        'App\Controllers\PublicController@impressum');

// Public API
$r->addRoute('GET',  '/api/public/confirm-email',          'App\Controllers\PublicController@confirmEmail');
$r->addRoute('POST', '/api/public/submit-alumni-recovery', 'App\Controllers\PublicController@submitAlumniRecovery');
$r->addRoute('POST', '/api/public/submit-neue-alumni',     'App\Controllers\PublicController@submitNeueAlumni');

// Profile photo proxy (caches Entra ID photos, replaces fetch-profile-photo.php)
$r->addRoute('GET', '/profile-photo', 'App\Controllers\ProfileController@fetchProfilePhoto');

// ===========================================================================
// AUTHENTICATED ROUTES  (AuthMiddleware)
// ===========================================================================

$r->addRoute('GET',           '/dashboard',        ['App\Controllers\DashboardController@index',   [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/profile',           ['App\Controllers\AuthController@profile',      [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/profile/settings',  ['App\Controllers\AuthController@settings',     [AuthMiddleware::class]]);

// Members
$r->addRoute('GET', '/members',          ['App\Controllers\MemberController@index', [AuthMiddleware::class]]);
$r->addRoute('GET', '/members/{id:\d+}', ['App\Controllers\MemberController@view',  [AuthMiddleware::class]]);

// Events
$r->addRoute('GET',           '/events',                    ['App\Controllers\EventController@index',      [AuthMiddleware::class]]);
$r->addRoute('GET',           '/events/{id:\d+}',           ['App\Controllers\EventController@view',       [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/edit',       ['App\Controllers\EventController@edit',       [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/manage',     ['App\Controllers\EventController@manage',     [AuthMiddleware::class]]);
$r->addRoute('GET',           '/events/{id:\d+}/statistics', ['App\Controllers\EventController@statistics', [AuthMiddleware::class]]);

// Blog
$r->addRoute('GET',           '/blog',               ['App\Controllers\BlogController@index', [AuthMiddleware::class]]);
$r->addRoute('GET',           '/blog/{id:\d+}',      ['App\Controllers\BlogController@view',  [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/blog/{id:\d+}/edit', ['App\Controllers\BlogController@edit',  [AuthMiddleware::class]]);

// Projects
$r->addRoute('GET',           '/projects',                       ['App\Controllers\ProjectController@index',        [AuthMiddleware::class]]);
$r->addRoute('GET',           '/projects/{id:\d+}',              ['App\Controllers\ProjectController@view',         [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/projects/{id:\d+}/manage',        ['App\Controllers\ProjectController@manage',       [AuthMiddleware::class]]);
$r->addRoute('GET',           '/projects/{id:\d+}/applications',  ['App\Controllers\ProjectController@applications', [AuthMiddleware::class]]);

// Inventory
$r->addRoute('GET',           '/inventory',                   ['App\Controllers\InventoryController@index',       [AuthMiddleware::class]]);
$r->addRoute('GET',           '/inventory/{id:\d+}',          ['App\Controllers\InventoryController@view',        [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/inventory/add',                ['App\Controllers\InventoryController@add',         [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/edit',      ['App\Controllers\InventoryController@edit',        [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkout',  ['App\Controllers\InventoryController@checkout',    [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkin',   ['App\Controllers\InventoryController@checkin',     [AuthMiddleware::class]]);
$r->addRoute('GET',           '/inventory/my-checkouts',       ['App\Controllers\InventoryController@myCheckouts', [AuthMiddleware::class]]);
$r->addRoute('GET',           '/inventory/my-rentals',         ['App\Controllers\InventoryController@myRentals',   [AuthMiddleware::class]]);

// Invoices
$r->addRoute('GET', '/invoices', ['App\Controllers\InvoiceController@index', [AuthMiddleware::class]]);

// Alumni
$r->addRoute('GET',           '/alumni',               ['App\Controllers\AlumniController@index',    [AuthMiddleware::class]]);
$r->addRoute('GET',           '/alumni/{id:\d+}',      ['App\Controllers\AlumniController@view',     [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/alumni/{id:\d+}/edit', ['App\Controllers\AlumniController@edit',     [AuthMiddleware::class]]);
$r->addRoute('GET',           '/alumni/requests',      ['App\Controllers\AlumniController@requests', [AuthMiddleware::class]]);

// Jobs
$r->addRoute('GET',           '/jobs',              ['App\Controllers\JobController@index',  [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/jobs/create',        ['App\Controllers\JobController@create', [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/jobs/{id:\d+}/edit', ['App\Controllers\JobController@edit',   [AuthMiddleware::class]]);

// Newsletter, Polls, Links, Ideas
$r->addRoute('GET',           '/newsletter',                    ['App\Controllers\NewsletterController@index',  [AuthMiddleware::class]]);
$r->addRoute('GET',           '/newsletter/{id:\d+}',          ['App\Controllers\NewsletterController@view',   [AuthMiddleware::class]]);
$r->addRoute('GET',           '/newsletter/{id:\d+}/render',   ['App\Controllers\NewsletterController@render', [AuthMiddleware::class]]);
$r->addRoute('GET',           '/polls',                        ['App\Controllers\PollController@index',        [AuthMiddleware::class]]);
$r->addRoute('GET',           '/polls/{id:\d+}',               ['App\Controllers\PollController@view',        [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/polls/create',                  ['App\Controllers\PollController@create',       [AuthMiddleware::class]]);
$r->addRoute('GET',           '/links',                        ['App\Controllers\LinkController@index',        [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/links/create',                  ['App\Controllers\LinkController@create',       [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/links/{id:\d+}/edit',           ['App\Controllers\LinkController@edit',         [AuthMiddleware::class]]);
$r->addRoute('GET',           '/ideas',                        ['App\Controllers\IdeaController@index',        [AuthMiddleware::class]]);

// ===========================================================================
// ADMIN ROUTES  (AdminMiddleware – implies board role + auth)
// ===========================================================================

$r->addRoute('GET',           '/admin',                       ['App\Controllers\AdminController@index',              [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/users',                 ['App\Controllers\AdminController@users',              [AdminMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/settings',              ['App\Controllers\AdminController@settings',           [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/stats',                 ['App\Controllers\AdminController@stats',              [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/audit',                 ['App\Controllers\AdminController@audit',              [AdminMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/inventory',             ['App\Controllers\AdminController@inventory',          [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/alumni-requests',       ['App\Controllers\AdminController@alumniRequests',     [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/neue-alumni-requests',  ['App\Controllers\AdminController@neueAlumniRequests', [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/vcards',                ['App\Controllers\AdminController@vcards',             [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/project-applications',  ['App\Controllers\AdminController@projectApplications',[AdminMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/db-maintenance',        ['App\Controllers\AdminController@dbMaintenance',      [AdminMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/locations',             ['App\Controllers\AdminController@locations',          [AdminMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/admin/categories',            ['App\Controllers\AdminController@categories',         [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/rental-returns',        ['App\Controllers\AdminController@rentalReturns',      [AdminMiddleware::class]]);
$r->addRoute('GET',           '/admin/event-stats',           ['App\Controllers\AdminController@eventStats',         [AdminMiddleware::class]]);

// ===========================================================================
// API ROUTES – AUTHENTICATED
// ===========================================================================

// Invoice API
$r->addRoute('POST', '/api/invoices/submit',               ['App\Controllers\InvoiceController@submit',        [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/invoices/{id:\d+}/mark-paid',   ['App\Controllers\InvoiceController@markPaid',      [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/invoices/{id:\d+}/status',      ['App\Controllers\InvoiceController@updateStatus',  [AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/invoices/export',               ['App\Controllers\InvoiceController@exportInvoices',[AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/invoices/{id:\d+}/download',    ['App\Controllers\InvoiceController@downloadFile',  [AuthMiddleware::class]]);

// Profile API
$r->addRoute('POST', '/api/profile/upload-avatar',        ['App\Controllers\ProfileController@uploadAvatar',        [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/profile/delete-avatar',        ['App\Controllers\ProfileController@deleteAvatar',        [AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/profile/export-data',          ['App\Controllers\ProfileController@exportUserData',      [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/profile/dismiss-review',       ['App\Controllers\ProfileController@dismissProfileReview',[AuthMiddleware::class]]);
$r->addRoute('POST', '/api/profile/complete-onboarding',  ['App\Controllers\ProfileController@completeOnboarding',  [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/profile/submit-support',       ['App\Controllers\ProfileController@submitSupport',       [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/profile/submit-2fa-support',   ['App\Controllers\ProfileController@submit2faSupport',    [AuthMiddleware::class]]);

// Event API
$r->addRoute('POST', '/api/events/signup',                 ['App\Controllers\EventApiController@eventSignup',           [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/events/signup-simple',          ['App\Controllers\EventApiController@eventSignupSimple',      [AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/events/{id:\d+}/download-ics',  ['App\Controllers\EventApiController@downloadIcs',            [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/events/{id:\d+}/documentation', ['App\Controllers\EventApiController@saveEventDocumentation', [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/events/{id:\d+}/financial-stats',['App\Controllers\EventApiController@saveFinancialStats',    [AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/events/mail-template',          ['App\Controllers\EventApiController@getMailTemplate',        [AuthMiddleware::class]]);

// Inventory API
$r->addRoute('POST', '/api/inventory/cart-toggle',          ['App\Controllers\InventoryApiController@cartToggle',          [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/inventory/request',              ['App\Controllers\InventoryApiController@inventoryRequest',    [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/inventory/rental-request-action',['App\Controllers\InventoryApiController@rentalRequestAction', [AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/inventory/easyverein-image',     ['App\Controllers\InventoryApiController@easyvereinImage',     [AuthMiddleware::class]]);

// Project API
$r->addRoute('POST', '/api/projects/join',            ['App\Controllers\ProjectApiController@projectJoin', [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/projects/feedback-contact', ['App\Controllers\ProjectApiController@setFeedbackContact', [AuthMiddleware::class]]);

// Event feedback contact
$r->addRoute('POST', '/api/events/feedback-contact', ['App\Controllers\EventApiController@setFeedbackContact', [AuthMiddleware::class]]);

// Job API
$r->addRoute('POST', '/api/jobs/contact-listing', ['App\Controllers\JobController@contactListing', [AuthMiddleware::class]]);

// Newsletter API
$r->addRoute('GET', '/api/newsletter/{id:\d+}/download',            ['App\Controllers\NewsletterController@download',           [AuthMiddleware::class]]);
$r->addRoute('GET', '/api/newsletter/{id:\d+}/download-attachment',  ['App\Controllers\NewsletterController@downloadAttachment', [AuthMiddleware::class]]);

// Poll API
$r->addRoute('POST', '/api/polls/hide', ['App\Controllers\PollController@hide', [AuthMiddleware::class]]);

// Idea API
$r->addRoute('POST', '/api/ideas/create',        ['App\Controllers\IdeaController@create',       [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/ideas/vote',          ['App\Controllers\IdeaController@vote',         [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/ideas/update-status', ['App\Controllers\IdeaController@updateStatus', [AuthMiddleware::class]]);

// ===========================================================================
// API ROUTES – ADMIN
// ===========================================================================

$r->addRoute('POST', '/api/admin/create-vcard',                ['App\Controllers\AdminController@createVcard',             [AdminMiddleware::class]]);
$r->addRoute('POST', '/api/admin/delete-vcard',                ['App\Controllers\AdminController@deleteVcard',             [AdminMiddleware::class]]);
$r->addRoute('POST', '/api/admin/update-vcard',                ['App\Controllers\AdminController@updateVcard',             [AdminMiddleware::class]]);
$r->addRoute('POST', '/api/admin/process-alumni-request',      ['App\Controllers\AdminController@processAlumniRequest',    [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/admin/process-neue-alumni-request', ['App\Controllers\AdminController@processNeueAlumniRequest',[AuthMiddleware::class]]);
$r->addRoute('POST', '/api/admin/update-user-role',            ['App\Controllers\AdminController@updateUserRole',          [AdminMiddleware::class]]);
$r->addRoute('GET',  '/api/admin/search-entra-users',          ['App\Controllers\AdminController@searchEntraUsers',        [AdminMiddleware::class]]);

// ===========================================================================
// SEARCH
// ===========================================================================
$r->addRoute('GET', '/search', ['App\Controllers\SearchController@index', [AuthMiddleware::class]]);

// ===========================================================================
// DOCUMENTS
// ===========================================================================
$r->addRoute('GET',           '/documents',                       ['App\Controllers\DocumentController@index',    [AuthMiddleware::class]]);
$r->addRoute('GET',           '/documents/{id:\d+}',              ['App\Controllers\DocumentController@view',     [AuthMiddleware::class]]);
$r->addRoute(['GET', 'POST'], '/documents/create',                 ['App\Controllers\DocumentController@create',   [AuthMiddleware::class]]);
$r->addRoute('GET',           '/documents/{id:\d+}/download',      ['App\Controllers\DocumentController@download', [AuthMiddleware::class]]);
$r->addRoute('POST',          '/documents/{id:\d+}/delete',        ['App\Controllers\DocumentController@delete',   [AuthMiddleware::class]]);

// ===========================================================================
// NOTIFICATIONS (polling API + SSE stream)
// ===========================================================================
$r->addRoute('GET',  '/api/notifications',          ['App\Controllers\NotificationController@list',       [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/notifications/read',     ['App\Controllers\NotificationController@markRead',   [AuthMiddleware::class]]);
$r->addRoute('POST', '/api/notifications/read-all', ['App\Controllers\NotificationController@markAllRead',[AuthMiddleware::class]]);
$r->addRoute('GET',  '/api/notifications/stream',   ['App\Controllers\NotificationController@stream',     [AuthMiddleware::class]]);
