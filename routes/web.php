<?php
/**
 * Web Routes
 *
 * Routes use 'ControllerClass@method' string notation.
 * Legacy closures remain for backward compatibility during migration.
 */

declare(strict_types=1);

// ===========================================================================
// PUBLIC ROUTES
// ===========================================================================

$r->addRoute('GET', '/', static function () use ($redirect): void {
    $redirect(\Auth::check() ? \BASE_URL . '/dashboard' : \BASE_URL . '/login');
});

$r->addRoute(['GET', 'POST'], '/login',      'App\Controllers\AuthController@login');
$r->addRoute(['GET', 'POST'], '/logout',     'App\Controllers\AuthController@logout');
$r->addRoute(['GET', 'POST'], '/verify-2fa', 'App\Controllers\AuthController@verify2fa');
$r->addRoute(['GET', 'POST'], '/onboarding', 'App\Controllers\AuthController@onboarding');

$r->addRoute(['GET', 'POST'], '/alumni-recovery', 'App\Controllers\PublicController@alumniRecovery');
$r->addRoute(['GET', 'POST'], '/neue-alumni',      'App\Controllers\PublicController@neueAlumni');
$r->addRoute('GET',           '/impressum',        'App\Controllers\PublicController@impressum');

// Public API
$r->addRoute('GET',  '/api/public/confirm-email',          'App\Controllers\PublicController@confirmEmail');
$r->addRoute('POST', '/api/public/submit-alumni-recovery', 'App\Controllers\PublicController@submitAlumniRecovery');
$r->addRoute('POST', '/api/public/submit-neue-alumni',     'App\Controllers\PublicController@submitNeueAlumni');

// ===========================================================================
// AUTHENTICATED ROUTES
// ===========================================================================

$r->addRoute('GET',           '/dashboard',        'App\Controllers\DashboardController@index');
$r->addRoute(['GET', 'POST'], '/profile',           'App\Controllers\AuthController@profile');
$r->addRoute(['GET', 'POST'], '/profile/settings',  'App\Controllers\AuthController@settings');

// Members
$r->addRoute('GET', '/members',        'App\Controllers\MemberController@index');
$r->addRoute('GET', '/members/{id:\d+}', 'App\Controllers\MemberController@view');

// Events
$r->addRoute('GET',           '/events',                    'App\Controllers\EventController@index');
$r->addRoute('GET',           '/events/{id:\d+}',           'App\Controllers\EventController@view');
$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/edit',       'App\Controllers\EventController@edit');
$r->addRoute(['GET', 'POST'], '/events/{id:\d+}/manage',     'App\Controllers\EventController@manage');
$r->addRoute('GET',           '/events/{id:\d+}/statistics', 'App\Controllers\EventController@statistics');

// Blog
$r->addRoute('GET',           '/blog',            'App\Controllers\BlogController@index');
$r->addRoute('GET',           '/blog/{id:\d+}',   'App\Controllers\BlogController@view');
$r->addRoute(['GET', 'POST'], '/blog/{id:\d+}/edit', 'App\Controllers\BlogController@edit');

// Projects
$r->addRoute('GET',           '/projects',                      'App\Controllers\ProjectController@index');
$r->addRoute('GET',           '/projects/{id:\d+}',             'App\Controllers\ProjectController@view');
$r->addRoute(['GET', 'POST'], '/projects/{id:\d+}/manage',       'App\Controllers\ProjectController@manage');
$r->addRoute('GET',           '/projects/{id:\d+}/applications', 'App\Controllers\ProjectController@applications');

// Inventory
$r->addRoute('GET',           '/inventory',                    'App\Controllers\InventoryController@index');
$r->addRoute('GET',           '/inventory/{id:\d+}',           'App\Controllers\InventoryController@view');
$r->addRoute(['GET', 'POST'], '/inventory/add',                 'App\Controllers\InventoryController@add');
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/edit',       'App\Controllers\InventoryController@edit');
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkout',   'App\Controllers\InventoryController@checkout');
$r->addRoute(['GET', 'POST'], '/inventory/{id:\d+}/checkin',    'App\Controllers\InventoryController@checkin');
$r->addRoute('GET',           '/inventory/my-checkouts',        'App\Controllers\InventoryController@myCheckouts');
$r->addRoute('GET',           '/inventory/my-rentals',          'App\Controllers\InventoryController@myRentals');

// Invoices
$r->addRoute('GET', '/invoices', 'App\Controllers\InvoiceController@index');

// Alumni
$r->addRoute('GET',           '/alumni',                'App\Controllers\AlumniController@index');
$r->addRoute('GET',           '/alumni/{id:\d+}',       'App\Controllers\AlumniController@view');
$r->addRoute(['GET', 'POST'], '/alumni/{id:\d+}/edit',  'App\Controllers\AlumniController@edit');
$r->addRoute('GET',           '/alumni/requests',       'App\Controllers\AlumniController@requests');

// Jobs
$r->addRoute('GET',           '/jobs',              'App\Controllers\JobController@index');
$r->addRoute(['GET', 'POST'], '/jobs/create',        'App\Controllers\JobController@create');
$r->addRoute(['GET', 'POST'], '/jobs/{id:\d+}/edit', 'App\Controllers\JobController@edit');

// Newsletter
$r->addRoute('GET', '/newsletter', 'App\Controllers\NewsletterController@index');

// Polls
$r->addRoute('GET', '/polls', 'App\Controllers\PollController@index');

// Links
$r->addRoute('GET', '/links', 'App\Controllers\LinkController@index');

// Ideas
$r->addRoute('GET', '/ideas', 'App\Controllers\IdeaController@index');

// Admin
$r->addRoute('GET',           '/admin',                      'App\Controllers\AdminController@index');
$r->addRoute('GET',           '/admin/users',                'App\Controllers\AdminController@users');
$r->addRoute(['GET', 'POST'], '/admin/settings',             'App\Controllers\AdminController@settings');
$r->addRoute('GET',           '/admin/stats',                'App\Controllers\AdminController@stats');
$r->addRoute('GET',           '/admin/audit',                'App\Controllers\AdminController@audit');
$r->addRoute(['GET', 'POST'], '/admin/inventory',            'App\Controllers\AdminController@inventory');
$r->addRoute(['GET', 'POST'], '/admin/alumni-requests',      'App\Controllers\AdminController@alumniRequests');
$r->addRoute(['GET', 'POST'], '/admin/neue-alumni-requests', 'App\Controllers\AdminController@neueAlumniRequests');
$r->addRoute(['GET', 'POST'], '/admin/vcards',               'App\Controllers\AdminController@vcards');
$r->addRoute('GET',           '/admin/project-applications', 'App\Controllers\AdminController@projectApplications');
$r->addRoute(['GET', 'POST'], '/admin/db-maintenance',       'App\Controllers\AdminController@dbMaintenance');

// ===========================================================================
// API ROUTES
// ===========================================================================

// Invoice API
$r->addRoute('POST', '/api/invoices/submit',               'App\Controllers\InvoiceController@submit');
$r->addRoute('POST', '/api/invoices/{id:\d+}/mark-paid',   'App\Controllers\InvoiceController@markPaid');
$r->addRoute('POST', '/api/invoices/{id:\d+}/status',      'App\Controllers\InvoiceController@updateStatus');
$r->addRoute('GET',  '/api/invoices/export',               'App\Controllers\InvoiceController@exportInvoices');
$r->addRoute('GET',  '/api/invoices/{id:\d+}/download',    'App\Controllers\InvoiceController@downloadFile');

// Profile API
$r->addRoute('POST', '/api/profile/upload-avatar',        'App\Controllers\ProfileController@uploadAvatar');
$r->addRoute('POST', '/api/profile/delete-avatar',        'App\Controllers\ProfileController@deleteAvatar');
$r->addRoute('GET',  '/api/profile/export-data',          'App\Controllers\ProfileController@exportUserData');
$r->addRoute('POST', '/api/profile/dismiss-review',       'App\Controllers\ProfileController@dismissProfileReview');
$r->addRoute('POST', '/api/profile/complete-onboarding',  'App\Controllers\ProfileController@completeOnboarding');
$r->addRoute('POST', '/api/profile/submit-support',       'App\Controllers\ProfileController@submitSupport');
$r->addRoute('POST', '/api/profile/submit-2fa-support',   'App\Controllers\ProfileController@submit2faSupport');

// Event API
$r->addRoute('POST', '/api/events/signup',               'App\Controllers\EventApiController@eventSignup');
$r->addRoute('POST', '/api/events/signup-simple',        'App\Controllers\EventApiController@eventSignupSimple');
$r->addRoute('GET',  '/api/events/{id:\d+}/download-ics','App\Controllers\EventApiController@downloadIcs');
$r->addRoute('POST', '/api/events/{id:\d+}/documentation','App\Controllers\EventApiController@saveEventDocumentation');
$r->addRoute('POST', '/api/events/{id:\d+}/financial-stats','App\Controllers\EventApiController@saveFinancialStats');
$r->addRoute('GET',  '/api/events/mail-template',        'App\Controllers\EventApiController@getMailTemplate');

// Inventory API
$r->addRoute('POST', '/api/inventory/cart-toggle',         'App\Controllers\InventoryApiController@cartToggle');
$r->addRoute('POST', '/api/inventory/request',             'App\Controllers\InventoryApiController@inventoryRequest');
$r->addRoute('POST', '/api/inventory/rental-request-action','App\Controllers\InventoryApiController@rentalRequestAction');

// Project API
$r->addRoute('POST', '/api/projects/join', 'App\Controllers\ProjectApiController@projectJoin');

// Job API
$r->addRoute('POST', '/api/jobs/contact-listing', 'App\Controllers\JobController@contactListing');

// Newsletter API
$r->addRoute('GET',  '/api/newsletter/{id:\d+}/download',           'App\Controllers\NewsletterController@download');
$r->addRoute('GET',  '/api/newsletter/{id:\d+}/download-attachment', 'App\Controllers\NewsletterController@downloadAttachment');

// Poll API
$r->addRoute('POST', '/api/polls/hide', 'App\Controllers\PollController@hide');

// Idea API
$r->addRoute('POST', '/api/ideas/create',        'App\Controllers\IdeaController@create');
$r->addRoute('POST', '/api/ideas/vote',          'App\Controllers\IdeaController@vote');
$r->addRoute('POST', '/api/ideas/update-status', 'App\Controllers\IdeaController@updateStatus');

// Admin API
$r->addRoute('POST', '/api/admin/create-vcard',               'App\Controllers\AdminController@createVcard');
$r->addRoute('POST', '/api/admin/delete-vcard',               'App\Controllers\AdminController@deleteVcard');
$r->addRoute('POST', '/api/admin/update-vcard',               'App\Controllers\AdminController@updateVcard');
$r->addRoute('POST', '/api/admin/process-alumni-request',     'App\Controllers\AdminController@processAlumniRequest');
$r->addRoute('POST', '/api/admin/process-neue-alumni-request','App\Controllers\AdminController@processNeueAlumniRequest');
$r->addRoute('POST', '/api/admin/update-user-role',           'App\Controllers\AdminController@updateUserRole');
$r->addRoute('GET',  '/api/admin/search-entra-users',         'App\Controllers\AdminController@searchEntraUsers');
