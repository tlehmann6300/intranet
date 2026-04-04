<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/models/Invoice.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/BlogPost.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/Member.php';

// Update event statuses (pseudo-cron)
require_once __DIR__ . '/../../includes/pseudo_cron.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$currentUser = Auth::user();
if (!$currentUser) {
    Auth::logout();
    header('Location: ../auth/login.php');
    exit;
}

// Check if profile is complete - if not, redirect to profile edit page
// Only enforce for roles that need profiles (not for test/system accounts)
$rolesRequiringProfile = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz', 'alumni', 'mitglied', 'ressortleiter', 'anwaerter', 'ehrenmitglied'];
if (in_array($currentUser['role'], $rolesRequiringProfile) && isset($currentUser['profile_complete']) && $currentUser['profile_complete'] == 0) {
    $_SESSION['profile_incomplete_message'] = 'Bitte vervollständige dein Profil (Vorname und Nachname) um fortzufahren.';
    header('Location: ../alumni/edit.php');
    exit;
}

$user = $currentUser;
$userRole = $user['role'] ?? '';

// Get user's name for personalized greeting
$displayName = 'Benutzer'; // Default fallback
if (!empty($user['firstname']) && !empty($user['lastname'])) {
    $displayName = $user['firstname'] . ' ' . $user['lastname'];
} elseif (!empty($user['firstname'])) {
    $displayName = $user['firstname'];
} elseif (!empty($user['email']) && strpos($user['email'], '@') !== false) {
    $emailParts = explode('@', $user['email']);
    $displayName = $emailParts[0];
}
// Format name: remove dots and capitalize first letters
if ($displayName !== 'Benutzer') {
    $displayName = ucwords(str_replace('.', ' ', $displayName));
}

// Determine greeting based on time of day (German time)
$timezone = new DateTimeZone('Europe/Berlin');
$now = new DateTime('now', $timezone);
$hour = (int)$now->format('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Guten Morgen';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Guten Tag';
} else {
    $greeting = 'Guten Abend';
}

// Get upcoming events from database that the current user has registered for
$nextEvents = [];
$events = [];
$currentUserId = (int)Auth::getUserId();
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT DISTINCT e.id, e.title, e.start_time, e.end_time, e.location, e.status, e.image_path, e.is_external
         FROM events e
         INNER JOIN event_signups es ON es.event_id = e.id
         WHERE e.status IN ('planned', 'open', 'closed') AND DATE(e.start_time) >= CURDATE()
           AND es.user_id = ? AND es.status = 'confirmed'
         ORDER BY e.start_time ASC LIMIT 5"
    );
    $stmt->execute([$currentUserId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nextEvents = array_slice($events, 0, 3);
} catch (Exception $e) {
    error_log('dashboard: upcoming events query failed: ' . $e->getMessage());
}

// Get user's open tasks from inventory_requests and inventory_rentals tables
$openTasksCount = 0;
$userId = (int)Auth::getUserId();
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT COUNT(*) FROM inventory_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')"
    );
    $stmt->execute([$userId]);
    $openTasksCount += (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log('dashboard: open tasks count (requests) failed: ' . $e->getMessage());
}
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->prepare(
        "SELECT COUNT(*) FROM inventory_rentals WHERE user_id = ? AND status IN ('active', 'pending_return')"
    );
    $stmt->execute([$userId]);
    $openTasksCount += (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // Legacy table may not exist in deployments running the new inventory_requests schema – silently ignore
}

// Get events that need helpers (for all users)
$helperEvents = [];
try {
    $contentDb = Database::getContentDB();
    $stmt = $contentDb->query("
        SELECT e.id, e.title, e.description, e.start_time, e.end_time, e.location
        FROM events e
        WHERE e.needs_helpers = 1
        AND e.status IN ('open', 'planned')
        AND e.end_time >= NOW()
        ORDER BY e.start_time ASC
        LIMIT 5
    ");
    $helperEvents = $stmt->fetchAll();
} catch (PDOException $e) {
    // If needs_helpers column doesn't exist yet, gracefully skip this section
    // This can happen if update_database_schema.php hasn't been run yet
    $errorMessage = $e->getMessage();
    
    // Check for column-not-found error using SQLSTATE code (42S22) for reliability
    // Also check error message as fallback for different database systems
    $isColumnError = (isset($e->errorInfo[0]) && $e->errorInfo[0] === '42S22') ||
                     stripos($errorMessage, 'Unknown column') !== false ||
                     stripos($errorMessage, 'Column not found') !== false;
    
    if ($isColumnError) {
        // Column not found - continue with empty $helperEvents array
        error_log("Dashboard: needs_helpers column not found in events table. Run update_database_schema.php to add it.");
    } else {
        error_log("Dashboard: Unexpected database error when fetching helper events: " . $errorMessage);
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching helper events: " . $e->getMessage());
}

// Get open invoices count for eligible users
$openInvoicesCount = 0;
$canAccessInvoices = Auth::canAccessPage('invoices');
if ($canAccessInvoices) {
    try {
        $rechDb = Database::getRechDB();
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']))) {
            $iStmt = $rechDb->prepare("SELECT COUNT(*) FROM invoices WHERE status IN ('pending', 'approved')");
            $iStmt->execute();
        } else {
            $iStmt = $rechDb->prepare("SELECT COUNT(*) FROM invoices WHERE user_id = ? AND status IN ('pending', 'approved')");
            $iStmt->execute([$userId]);
        }
        $openInvoicesCount = (int)$iStmt->fetchColumn();
    } catch (Exception $e) {
        error_log('dashboard: open invoices count failed: ' . $e->getMessage());
    }
}

// Get recent open invoices for status-badge display
$recentOpenInvoices = [];
if ($canAccessInvoices) {
    try {
        $rechDb = Database::getRechDB();
        if (in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']))) {
            $iStmt = $rechDb->prepare("SELECT id, description, amount, status, created_at FROM invoices WHERE status IN ('pending', 'approved') ORDER BY created_at DESC LIMIT 5");
            $iStmt->execute();
        } else {
            $iStmt = $rechDb->prepare("SELECT id, description, amount, status, created_at FROM invoices WHERE user_id = ? AND status IN ('pending', 'approved') ORDER BY created_at DESC LIMIT 5");
            $iStmt->execute([$userId]);
        }
        $recentOpenInvoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('dashboard: recent open invoices fetch failed: ' . $e->getMessage());
    }
}

// Get recent blog posts
$recentBlogPosts = [];
try {
    $recentBlogPosts = BlogPost::getAll(3, 0);
} catch (Exception $e) {
    error_log('dashboard: recent blog posts query failed: ' . $e->getMessage());
}

// Calculate profile completeness for the gamification widget
// Required fields (8 total): Vorname, Nachname, E-Mail, Telefon, Geschlecht, Geburtstag,
// Fähigkeiten (mindestens ein Eintrag), Über mich
$profileCompletenessPercent = 0;
if (in_array($userRole, $rolesRequiringProfile)) {
    try {
        // Fetch the profile record depending on the user's role
        $profileRecord = null;
        if (isMemberRole($userRole)) {
            $profileRecord = Member::getProfileByUserId($userId);
        } else {
            $profileRecord = Alumni::getProfileByUserId($userId);
        }

        // Fields from the users table (already available in $user)
        $filledCount = 0;
        $totalFields = 8;

        if (!empty($user['first_name']))  $filledCount++;
        if (!empty($user['last_name']))   $filledCount++;
        if (!empty($user['email']))       $filledCount++;
        if (!empty($user['about_me']))    $filledCount++;
        if (!empty($user['gender']))      $filledCount++;
        if (!empty($user['birthday']))    $filledCount++;

        // Fields from the profile record
        if ($profileRecord) {
            if (!empty($profileRecord['mobile_phone'])) $filledCount++;
            if (!empty($profileRecord['skills']))       $filledCount++;
        }

        $profileCompletenessPercent = (int)round(($filledCount / $totalFields) * 100);
    } catch (Exception $e) {
        error_log('dashboard: profile completeness check failed: ' . $e->getMessage());
    }
}

$title = 'Dashboard - IBC Intranet';
ob_start();
?>

<style>
    /* ── Dashboard Wrapper ──────────────────────────────── */
    .dash-wrap {
        max-width: 80rem;
        margin-left: auto;
        margin-right: auto;
    }

    /* ── Section Headers ────────────────────────────────── */
    .dash-section-hdr {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .dash-section-hdr-left {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .dash-section-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.9rem;
        box-shadow: var(--shadow-soft);
    }
    .dash-section-title {
        font-size: clamp(1rem, 2.5vw, 1.375rem);
        font-weight: 800;
        color: var(--text-main);
        letter-spacing: -0.01em;
    }
    .dash-section-link {
        font-size: 0.875rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        transition: gap 0.2s ease, opacity 0.2s ease;
        text-decoration: none !important;
    }
    .dash-section-link:hover {
        gap: 0.625rem;
        opacity: 0.85;
        text-decoration: none !important;
    }

    /* ── Hero Quick Actions ─────────────────────────────── */
    .hero-quick-action {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 1.1rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 700;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1.5px solid rgba(255, 255, 255, 0.32);
        color: #fff !important;
        text-decoration: none !important;
        letter-spacing: 0.02em;
        transition: background 0.22s ease, transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    }
    .hero-quick-action i {
        font-size: 0.8rem;
        opacity: 0.95;
    }
    .hero-quick-action:hover {
        background: rgba(255, 255, 255, 0.28);
        border-color: rgba(255, 255, 255, 0.55);
        transform: translateY(-3px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.2);
        text-decoration: none !important;
        color: #fff !important;
    }
    .hero-quick-action--events   { background: rgba(59,130,246,0.35);  border-color: rgba(147,197,253,0.5); }
    .hero-quick-action--events:hover   { background: rgba(59,130,246,0.52); }
    .hero-quick-action--inventory { background: rgba(249,115,22,0.32); border-color: rgba(253,186,116,0.5); }
    .hero-quick-action--inventory:hover { background: rgba(249,115,22,0.48); }
    .hero-quick-action--invoices  { background: rgba(16,185,129,0.32);  border-color: rgba(110,231,183,0.5); }
    .hero-quick-action--invoices:hover  { background: rgba(16,185,129,0.48); }
    .hero-quick-action--profile   { background: rgba(168,85,247,0.32);  border-color: rgba(216,180,254,0.5); }
    .hero-quick-action--profile:hover   { background: rgba(168,85,247,0.48); }

    /* ── Stat Cards ─────────────────────────────────────── */
    /* Structural/visual properties (rounded, padding, border, shadow, transition)
       are now handled by Tailwind utility classes on the HTML element.
       Only pseudo-element theming and anchor resets remain here. */
    .dash-stat-card {
        position: relative;
        overflow: hidden;
        background: var(--bg-card);
        text-decoration: none !important;
        display: block;
        color: inherit;
    }
    .dash-stat-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        border-radius: 1rem 1rem 0 0; /* matches rounded-2xl top corners */
        background: var(--dash-stat-color, var(--ibc-blue));
        opacity: 1;
    }
    .dash-stat-card::after {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(ellipse at 80% 0%, var(--dash-stat-bg, transparent) 0%, transparent 65%);
        pointer-events: none;
        border-radius: inherit;
    }
    .dash-stat-card:hover {
        /* !important is required to override Tailwind's static border-slate-100
           utility with the dynamic per-card accent color on hover. */
        border-color: var(--dash-stat-color, var(--ibc-blue)) !important;
        text-decoration: none !important;
        color: inherit;
    }
    .dash-stat-icon {
        width: 3rem;
        height: 3rem;
        border-radius: 0.875rem;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--dash-stat-bg, rgba(0,102,179,0.08));
        color: var(--dash-stat-color, var(--ibc-blue));
        font-size: 1.15rem;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    }

    /* ── Profile Completeness Ring ──────────────────────── */
    .profile-ring {
        position: relative;
        width: 5rem;
        height: 5rem;
        flex-shrink: 0;
    }
    .profile-ring svg {
        transform: rotate(-90deg);
        width: 100%;
        height: 100%;
    }
    .profile-ring-pct {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        font-weight: 800;
        background: linear-gradient(135deg, #a855f7, #ec4899);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    /* ── Dashboard Event Cards ──────────────────────────── */
    .dash-event-card {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-radius: 1rem;
        border: 1.5px solid var(--border-color);
        background-color: var(--bg-card);
        box-shadow: var(--shadow-card);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        text-decoration: none !important;
        color: inherit;
    }
    .dash-event-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: var(--ibc-blue) !important;
        text-decoration: none !important;
    }
    .dash-event-card-accent {
        height: 4px;
        flex-shrink: 0;
        background: var(--ibc-blue);
    }
    .dash-event-card--open    .dash-event-card-accent { background: var(--ibc-green); }
    .dash-event-card--closed  .dash-event-card-accent { background: var(--ibc-warning); }
    .dash-event-card--planned .dash-event-card-accent { background: var(--ibc-blue); }

    .dash-event-date-chip {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: rgba(0, 102, 179, 0.06);
        border: 1.5px solid rgba(0, 102, 179, 0.15);
        border-radius: 0.75rem;
        padding: 0.4rem 0.7rem;
        min-width: 52px;
        text-align: center;
        line-height: 1;
        flex-shrink: 0;
    }
    .dash-event-date-month {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--ibc-blue);
        line-height: 1;
    }
    .dash-event-date-day {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-main);
        line-height: 1.1;
    }

    /* ── Invoice Status Badges ──────────────────────────── */
    .invoice-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.875rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .invoice-badge-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .invoice-badge--pending  { background: rgba(245,158,11,0.12); color: #92400e; }
    .invoice-badge--approved { background: rgba(234,179,8,0.12);  color: #78350f; }
    .invoice-badge--pending .invoice-badge-dot  { background: #f59e0b; }
    .invoice-badge--approved .invoice-badge-dot { background: #eab308; }
    .dark-mode .invoice-badge--pending  { background: rgba(245,158,11,0.18); color: #fde68a; }
    .dark-mode .invoice-badge--approved { background: rgba(234,179,8,0.18);  color: #fef08a; }

    /* ── Dashboard Hover Cards (generic) ───────────────── */
    .dash-hover-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .dash-hover-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-card-hover);
    }

    /* ── Blog Cards ─────────────────────────────────────── */
    .dash-blog-card {
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border-radius: 1rem;
        border: 1.5px solid var(--border-color);
        background-color: var(--bg-card);
        box-shadow: var(--shadow-card);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        text-decoration: none !important;
        color: inherit;
    }
    .dash-blog-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: var(--ibc-blue) !important;
        text-decoration: none !important;
    }
    .dash-blog-img {
        height: 160px;
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%);
        overflow: hidden;
        flex-shrink: 0;
        position: relative;
    }
    .dash-blog-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s ease;
    }
    .dash-blog-card:hover .dash-blog-img img {
        transform: scale(1.05);
    }

    /* ── Helper Event Cards ─────────────────────────────── */
    .dash-helper-card {
        border-radius: 1rem;
        padding: 1.25rem 1.5rem;
        background: var(--bg-card);
        border: 1.5px solid var(--border-color);
        border-top: 4px solid var(--ibc-green);
        box-shadow: var(--shadow-card);
        transition: all 0.25s ease;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .dash-helper-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-card-hover);
    }

    /* ── Poll Cards ─────────────────────────────────────── */
    .dash-poll-card {
        border-radius: 1rem;
        background: var(--bg-card);
        border: 1.5px solid var(--border-color);
        box-shadow: var(--shadow-card);
        overflow: hidden;
        transition: all 0.25s ease;
    }
    .dash-poll-card:hover {
        box-shadow: var(--shadow-card-hover);
        transform: translateY(-2px);
    }
    .dash-poll-accent {
        height: 3px;
        background: linear-gradient(90deg, #f97316, #ef4444);
    }

    /* ── Line Clamp ─────────────────────────────────────── */
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .line-clamp-3 {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* ── Empty State ────────────────────────────────────── */
    .dash-empty {
        border-radius: 1rem;
        padding: 2.5rem;
        background: var(--bg-card);
        border: 1.5px dashed var(--border-color);
        text-align: center;
    }

    /* ── Hero Typography & Decorative ───────────────────── */
    .hero-date-text {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8125rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        opacity: 1;
        margin-bottom: 0.75rem;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.28);
        padding: 0.3rem 0.8rem;
        border-radius: 9999px;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        width: fit-content;
    }
    .hero-subtitle-text {
        font-size: 1rem;
        opacity: 0.82;
        margin-bottom: 1.5rem;
        line-height: 1.6;
        max-width: 38rem;
    }
    .hero-badge-icon {
        width: 3.25rem;
        height: 3.25rem;
        opacity: 0.95;
    }
    /* Animated shimmer on hero orbs */
    @keyframes hero-orb-pulse {
        0%, 100% { opacity: 0.55; transform: scale(1); }
        50% { opacity: 0.85; transform: scale(1.08); }
    }
    /* Fade-in-up for hero content */
    @keyframes hero-fadein {
        from { opacity: 0; transform: translateY(16px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: no-preference) {
        .hero-orb-1 { animation: hero-orb-pulse 6s ease-in-out infinite; }
        .hero-orb-2 { animation: hero-orb-pulse 8s ease-in-out 2s infinite; }
        .hero-content-animate {
            animation: hero-fadein 0.55s cubic-bezier(.22,.61,.36,1) both;
        }
    }
</style>

<?php if (!empty($user['prompt_profile_review']) && $user['prompt_profile_review'] == 1): ?>
<!-- Profile Review Prompt Modal -->
<div id="profile-review-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
    <div class="rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden transform transition-all" style="background-color: var(--bg-card)">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-purple-600 to-blue-600 px-6 py-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-user-edit text-white text-2xl"></i>
                </div>
                <h3 class="text-lg sm:text-xl font-bold text-white">Deine Rolle wurde geändert!</h3>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div class="px-6 py-6 overflow-y-auto flex-1">
            <p class="text-lg mb-6" style="color: var(--text-main)">
                Bitte überprüfe deine Daten (besonders E-Mail und Job-Daten), damit wir in Kontakt bleiben können.
            </p>
            
            <div class="rounded-lg p-4" style="background-color: var(--bg-body); border: 1px solid var(--border-color)">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-purple-600 mt-1 mr-3"></i>
                    <p class="text-sm" style="color: var(--text-main)">
                        Es ist wichtig, dass deine Kontaktdaten aktuell sind, damit du alle wichtigen Informationen erhältst.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div class="px-6 py-4 flex flex-col sm:flex-row gap-3" style="background-color: var(--bg-body); border-top: 1px solid var(--border-color)">
            <a href="../auth/profile.php" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg font-semibold hover:from-purple-700 hover:to-blue-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                <i class="fas fa-user-circle mr-2"></i>
                Zum Profil
            </a>
            <button onclick="dismissProfileReviewPrompt()" class="flex-1 px-6 py-3 rounded-lg font-semibold transition-all duration-300" style="background-color: var(--border-color); color: var(--text-main)">
                Später
            </button>
        </div>
    </div>
</div>

<script>
// Dismiss profile review prompt and update database
function dismissProfileReviewPrompt() {
    // Construct API path relative to web root
    const baseUrl = window.location.origin;
    const apiPath = baseUrl + '/api/dismiss_profile_review.php';
    
    // Make AJAX call to update database
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Hide modal
            document.getElementById('profile-review-modal').style.display = 'none';
        } else {
            console.error('Failed to dismiss prompt:', data.message);
            // Hide modal anyway to prevent blocking user
            document.getElementById('profile-review-modal').style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Hide modal anyway to prevent blocking user
        document.getElementById('profile-review-modal').style.display = 'none';
    });
}
</script>
<?php endif; ?>

<?php if (empty($user['has_seen_onboarding'])): ?>
<!-- Onboarding Welcome Modal -->
<div id="onboarding-modal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 p-4">
    <div class="rounded-2xl shadow-2xl w-full max-w-lg flex flex-col overflow-hidden transform transition-all" style="background-color: var(--bg-card)">
        <!-- Slide indicators -->
        <div class="bg-gradient-to-r from-blue-600 via-blue-700 to-emerald-600 px-6 pt-6 pb-4">
            <div class="flex justify-center gap-2 mb-4">
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white transition-all duration-300" data-slide="0"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white bg-opacity-40 transition-all duration-300" data-slide="1"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white bg-opacity-40 transition-all duration-300" data-slide="2"></span>
            </div>
            <div class="flex items-center justify-center">
                <div id="onboarding-icon" class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <i id="onboarding-icon-el" class="fas fa-calendar-alt text-white text-3xl"></i>
                </div>
            </div>
            <h3 id="onboarding-title" class="text-lg sm:text-xl font-bold text-white text-center mt-3">Events &amp; Projekte</h3>
        </div>

        <!-- Slide content -->
        <div class="px-6 py-6 flex-1" style="min-height: 160px">
            <!-- Slide 0 -->
            <div class="onboarding-slide" id="slide-0">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Entdecke kommende <strong>Events</strong> und laufende <strong>Projekte</strong> im IBC-Intranet.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Melde dich für Events an oder trag dich als Helfer ein</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Verfolge den Fortschritt laufender Projekte</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Bleib mit deinem Kalender immer auf dem neuesten Stand</li>
                </ul>
            </div>
            <!-- Slide 1 -->
            <div class="onboarding-slide hidden" id="slide-1">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Leih dir Equipment direkt über das <strong>Inventar</strong>-Modul aus – schnell und unkompliziert.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Durchsuche verfügbare Geräte und Materialien</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Stelle eine Ausleih-Anfrage in wenigen Klicks</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Behalte deine aktiven Ausleihen im Blick</li>
                </ul>
            </div>
            <!-- Slide 2 -->
            <div class="onboarding-slide hidden" id="slide-2">
                <p class="text-base mb-4" style="color: var(--text-main)">
                    Teile deine Ideen in der <strong>Ideenbox</strong> und stöbere im <strong>Shop</strong>.
                </p>
                <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Reiche Ideen ein und stimme über Vorschläge ab</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Bestelle Merchandise im externen Shop</li>
                    <li><i class="fas fa-check-circle text-emerald-500 mr-2"></i>Gestalte den IBC aktiv mit!</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 flex justify-between items-center" style="background-color: var(--bg-body); border-top: 1px solid var(--border-color)">
            <span id="onboarding-step-label" class="text-xs font-medium" style="color: var(--text-muted)">Schritt 1 von 3</span>
            <button id="onboarding-next-btn" onclick="onboardingNext()" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-blue-600 to-emerald-600 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-emerald-700 transition-all duration-300 shadow-md">
                Weiter <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var currentSlide = 0;
    var slides = [
        { icon: 'fa-calendar-alt', title: 'Events &amp; Projekte' },
        { icon: 'fa-box-open',     title: 'Inventar-Ausleihe' },
        { icon: 'fa-lightbulb',    title: 'Ideenbox &amp; Shop' }
    ];

    function updateSlide() {
        // Update slides visibility
        document.querySelectorAll('.onboarding-slide').forEach(function (el, i) {
            el.classList.toggle('hidden', i !== currentSlide);
        });
        // Update dots
        document.querySelectorAll('.onboarding-dot').forEach(function (el, i) {
            if (i === currentSlide) {
                el.classList.remove('bg-opacity-40');
            } else {
                el.classList.add('bg-opacity-40');
            }
        });
        // Update header icon & title
        document.getElementById('onboarding-icon-el').className = 'fas ' + slides[currentSlide].icon + ' text-white text-3xl';
        document.getElementById('onboarding-title').innerHTML = slides[currentSlide].title;
        // Update step label
        document.getElementById('onboarding-step-label').textContent = 'Schritt ' + (currentSlide + 1) + ' von 3';
        // Update button
        var btn = document.getElementById('onboarding-next-btn');
        if (currentSlide === slides.length - 1) {
            btn.innerHTML = 'Loslegen <i class="fas fa-rocket ml-2"></i>';
        } else {
            btn.innerHTML = 'Weiter <i class="fas fa-arrow-right ml-2"></i>';
        }
    }

    window.onboardingNext = function () {
        if (currentSlide < slides.length - 1) {
            currentSlide++;
            updateSlide();
        } else {
            // Last slide – save to DB and close
            var modal = document.getElementById('onboarding-modal');
            fetch(window.location.origin + '/api/complete_onboarding.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
            })
            .then(function (r) { return r.json(); })
            .catch(function (e) { console.error('Onboarding save error:', e); return {}; })
            .finally(function () {
                modal.style.display = 'none';
            });
        }
    };
})();
</script>
<?php endif; ?>

<!-- Hero Section with Personalized Greeting -->
<div class="dash-wrap mb-6 md:mb-10">
    <div class="hero-gradient relative overflow-hidden rounded-2xl text-white shadow-xl p-6 md:p-9 lg:p-11"
         style="background: linear-gradient(135deg, #003d6e 0%, #0066b3 30%, #00845f 65%, #00a651 100%); min-height: 13rem;">
        <!-- Decorative grid pattern -->
        <svg class="absolute inset-0 w-full h-full pointer-events-none" xmlns="http://www.w3.org/2000/svg" style="opacity:0.05">
            <defs>
                <pattern id="dash-hero-grid" width="36" height="36" patternUnits="userSpaceOnUse">
                    <path d="M 36 0 L 0 0 0 36" fill="none" stroke="white" stroke-width="1"/>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#dash-hero-grid)"/>
        </svg>
        <!-- Glowing orbs (animated) -->
        <div class="absolute -top-12 -right-12 w-72 h-72 rounded-full pointer-events-none hero-orb-1"
             style="background: radial-gradient(circle, rgba(255,255,255,0.13) 0%, transparent 70%)"></div>
        <div class="absolute -bottom-12 -left-12 w-60 h-60 rounded-full pointer-events-none hero-orb-2"
             style="background: radial-gradient(circle, rgba(0,210,130,0.22) 0%, transparent 70%)"></div>
        <div class="absolute top-1/2 right-1/4 w-40 h-40 rounded-full pointer-events-none"
             style="background: radial-gradient(circle, rgba(99,179,237,0.12) 0%, transparent 70%); transform: translateY(-50%);"></div>

        <!-- Content -->
        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 hero-content-animate">
            <div class="flex-1 min-w-0">
                <!-- Date pill -->
                <p class="hero-date-text hero-date">
                    <i class="fas fa-calendar-day" style="opacity:0.85"></i><?php
                        $germanMonths = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
                        $monthNum = (int)date('n');
                        echo date('d') . '. ' . ($germanMonths[$monthNum] ?? '') . ' ' . date('Y');
                    ?>
                </p>
                <!-- Greeting -->
                <h1 class="font-extrabold tracking-tight hero-title mb-2" style="font-size: clamp(1.85rem, 4vw, 2.85rem); line-height: 1.12; text-shadow: 0 2px 8px rgba(0,0,0,0.18);">
                    <?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($displayName); ?>! 👋
                </h1>
                <p class="hero-subtitle hero-subtitle-text">
                    Willkommen zurück im IBC&nbsp;Intranet – hier ist deine Übersicht für heute.
                </p>
                <!-- Quick action pill buttons -->
                <div class="flex flex-wrap gap-2 mt-1">
                    <a href="../events/index.php" class="hero-quick-action hero-quick-action--events">
                        <i class="fas fa-calendar-alt"></i> Events
                    </a>
                    <a href="../inventory/index.php" class="hero-quick-action hero-quick-action--inventory">
                        <i class="fas fa-box-open"></i> Inventar
                    </a>
                    <?php if ($canAccessInvoices): ?>
                    <a href="/pages/invoices/index.php" class="hero-quick-action hero-quick-action--invoices">
                        <i class="fas fa-file-invoice-dollar"></i> Rechnungen
                    </a>
                    <?php endif; ?>
                    <a href="../auth/profile.php" class="hero-quick-action hero-quick-action--profile">
                        <i class="fas fa-user-circle"></i> Profil
                    </a>
                </div>
            </div>
            <!-- Decorative icon badge (desktop only) -->
            <div class="hidden lg:flex items-center justify-center flex-shrink-0"
                 style="width: 7.5rem; height: 7.5rem; background: rgba(255,255,255,0.12); border-radius: 1.75rem; border: 1.5px solid rgba(255,255,255,0.28); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 8px 32px rgba(0,0,0,0.15);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="hero-badge-icon" aria-hidden="true">
                    <path fill-rule="evenodd" d="M3 6a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3v2.25a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3V6ZM3 15.75a3 3 0 0 1 3-3h2.25a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-2.25Zm9.75 0a3 3 0 0 1 3-3H18a3 3 0 0 1 3 3V18a3 3 0 0 1-3 3h-2.25a3 3 0 0 1-3-3v-2.25Z" clip-rule="evenodd" />
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Quick Stats Widgets -->
<div class="dash-wrap mb-6 md:mb-10">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #7c3aed, #a855f7); color: #fff; box-shadow: 0 4px 14px rgba(124,58,237,0.35);">
                <i class="fas fa-tachometer-alt"></i>
            </div>
            <div>
                <h2 class="dash-section-title">Schnellübersicht</h2>
                <p class="text-xs font-medium mt-0.5" style="color: var(--text-muted)">Deine wichtigsten Kennzahlen auf einen Blick</p>
            </div>
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 sm:gap-4 md:gap-6">

        <!-- Rentals Stat Card -->
        <a href="/pages/inventory/my_rentals.php"
           class="dash-stat-card w-full rounded-2xl p-6 border border-slate-100 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
           style="--dash-stat-color: #f97316; --dash-stat-bg: rgba(249,115,22,0.09);">
            <div class="flex items-center justify-between mb-4">
                <div class="dash-stat-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <span class="text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-full" style="color: #f97316; background: rgba(249,115,22,0.1);">Ausleihen</span>
            </div>
            <div class="text-2xl sm:text-3xl md:text-4xl font-extrabold mb-1" style="color: var(--text-main)"><?php echo $openTasksCount; ?></div>
            <div class="text-sm font-semibold mb-2" style="color: var(--text-main)">Meine Ausleihen</div>
            <p class="text-xs" style="color: var(--text-muted)">
                <?php if ($openTasksCount > 0): ?>
                    <i class="fas fa-exclamation-circle mr-1" style="color: #f97316"></i><?php echo $openTasksCount; ?> offene <?php echo $openTasksCount == 1 ? 'Ausleihe' : 'Ausleihen'; ?>
                <?php else: ?>
                    <i class="fas fa-check-circle mr-1" style="color: var(--ibc-green)"></i>Keine offenen Ausleihen
                <?php endif; ?>
            </p>
        </a>

        <!-- Next Event Stat Card -->
        <div class="dash-stat-card w-full rounded-2xl p-6 border border-slate-100 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
             style="--dash-stat-color: var(--ibc-blue); --dash-stat-bg: rgba(0,102,179,0.08);">
            <div class="flex items-center justify-between mb-4">
                <div class="dash-stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <span class="text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-full" style="color: var(--ibc-blue); background: rgba(0,102,179,0.1);">Events</span>
            </div>
            <?php if (!empty($nextEvents)):
                $nextEvent = $nextEvents[0];
                $ts = strtotime($nextEvent['start_time']);
                $monthAbbrs = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
                $monthAbbr = $monthAbbrs[(int)date('n', $ts) - 1];
            ?>
            <div class="text-2xl sm:text-3xl md:text-4xl font-extrabold mb-1" style="color: var(--text-main)"><?php echo count($nextEvents); ?></div>
            <div class="text-sm font-semibold mb-2 truncate" style="color: var(--text-main)">Nächstes: <?php echo htmlspecialchars($nextEvent['title']); ?></div>
            <p class="text-xs" style="color: var(--text-muted)">
                <i class="fas fa-clock mr-1" style="color: var(--ibc-blue)"></i><?php echo date('d.m.Y', $ts); ?> &middot; <?php echo date('H:i', $ts); ?> Uhr
            </p>
            <?php else: ?>
            <div class="text-2xl sm:text-3xl md:text-4xl font-extrabold mb-1" style="color: var(--text-main)">0</div>
            <div class="text-sm font-semibold mb-2" style="color: var(--text-main)">Nächstes Event</div>
            <p class="text-xs" style="color: var(--text-muted)">
                <i class="fas fa-info-circle mr-1"></i>Keine anstehenden Events
            </p>
            <?php endif; ?>
        </div>

        <?php if ($canAccessInvoices): ?>
        <!-- Invoices Stat Card -->
        <a href="/pages/invoices/index.php"
           class="dash-stat-card w-full rounded-2xl p-6 border border-slate-100 shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
           style="--dash-stat-color: var(--ibc-green); --dash-stat-bg: rgba(0,166,81,0.09);">
            <div class="flex items-center justify-between mb-4">
                <div class="dash-stat-icon">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <span class="text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-full" style="color: var(--ibc-green); background: rgba(0,166,81,0.1);">Rechnungen</span>
            </div>
            <div class="text-2xl sm:text-3xl md:text-4xl font-extrabold mb-1" style="color: var(--text-main)"><?php echo $openInvoicesCount; ?></div>
            <div class="text-sm font-semibold mb-2" style="color: var(--text-main)">Offene Rechnungen</div>
            <p class="text-xs" style="color: var(--text-muted)">
                <?php if ($openInvoicesCount > 0): ?>
                    <i class="fas fa-hourglass-half mr-1" style="color: #f59e0b"></i><?php echo $openInvoicesCount; ?> <?php echo $openInvoicesCount == 1 ? 'Rechnung ausstehend' : 'Rechnungen ausstehend'; ?>
                <?php else: ?>
                    <i class="fas fa-check-circle mr-1" style="color: var(--ibc-green)"></i>Alle Rechnungen bearbeitet
                <?php endif; ?>
            </p>
        </a>
        <?php endif; ?>

    </div>
</div>

<?php if (in_array($userRole, $rolesRequiringProfile) && $profileCompletenessPercent < 100): ?>
<!-- Profile Completeness Widget -->
<div class="dash-wrap mb-6 md:mb-10">
    <?php
        // SVG ring values: r=34 → circumference ≈ 213.6
        $circumference = 213.628;
        $dashOffset = $circumference * (1 - $profileCompletenessPercent / 100);
    ?>
    <div class="card rounded-2xl overflow-hidden" style="background-color: var(--bg-card); border: 1.5px solid var(--border-color); box-shadow: var(--shadow-card);">
        <!-- Accent top strip -->
        <div style="height: 3px; background: linear-gradient(90deg, #a855f7, #ec4899);"></div>
        <div class="p-4 md:p-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                <!-- Circular progress ring -->
                <div class="profile-ring">
                    <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                        <!-- Track -->
                        <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border-color)" stroke-width="6"/>
                        <!-- Progress -->
                        <circle cx="40" cy="40" r="34" fill="none"
                                stroke="url(#profileGrad)"
                                stroke-width="6"
                                stroke-linecap="round"
                                stroke-dasharray="<?php echo $circumference; ?>"
                                stroke-dashoffset="<?php echo $dashOffset; ?>"
                                style="transition: stroke-dashoffset 0.6s ease"/>
                        <defs>
                            <linearGradient id="profileGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#a855f7"/>
                                <stop offset="100%" stop-color="#ec4899"/>
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="profile-ring-pct"><?php echo $profileCompletenessPercent; ?>%</div>
                </div>
                <!-- Text content -->
                <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-bold mb-1" style="color: var(--text-main)">
                        🎯 Vervollständige dein Profil!
                    </h3>
                    <p class="text-sm mb-4 leading-relaxed" style="color: var(--text-muted)">
                        <span class="hidden sm:inline">Für 100% werden folgende Felder benötigt: Vorname, Nachname, E-Mail, Telefon, Geschlecht, Geburtstag, mindestens eine Fähigkeit und ein „Über mich"-Text.
                        Du bist schon zu&nbsp;<strong style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</strong>&nbsp;fertig&nbsp;– fast geschafft!</span>
                        <span class="sm:hidden">Pflichtfelder: Name, E-Mail, Telefon, Geschlecht, Geburtstag, Fähigkeit, Über mich.
                        Du bist zu&nbsp;<strong style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</strong>&nbsp;fertig!</span>
                    </p>
                    <a href="../auth/profile.php"
                       class="inline-flex items-center px-4 py-2.5 text-white rounded-xl font-semibold text-sm transition-all duration-300 shadow-md hover:opacity-90 hover:-translate-y-0.5"
                       style="background: linear-gradient(135deg, #a855f7, #ec4899)">
                        <i class="fas fa-user-edit mr-2"></i>Profil vervollständigen
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Meine nächsten Events Section -->
<?php if (!empty($events)): ?>
<div class="dash-wrap mb-6 md:mb-10">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #3b82f6, #4f46e5); color: #fff">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <h2 class="dash-section-title">Meine nächsten Events</h2>
        </div>
        <a href="../events/index.php" class="dash-section-link" style="color: var(--ibc-blue)">
            Alle Events <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php
        $monthAbbrs = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        $eventStatusLabels = [
            'open'    => ['label' => 'Anmeldung offen',    'color' => 'text-green-700 bg-green-100 dark:bg-green-900/40 dark:text-green-300'],
            'planned' => ['label' => 'Geplant',            'color' => 'text-blue-700 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'],
            'closed'  => ['label' => 'Anmeldung geschlossen', 'color' => 'text-amber-700 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'],
        ];
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 sm:gap-4 md:gap-6">
        <?php foreach ($events as $event): ?>
        <?php
            $ts = strtotime($event['start_time']);
            $monthAbbr = $monthAbbrs[(int)date('n', $ts) - 1];
            $eventStatus = $event['status'] ?? 'planned';
            $statusInfo = $eventStatusLabels[$eventStatus] ?? $eventStatusLabels['planned'];
            // Countdown
            $diffSecs = $ts - time();
            $countdown = '';
            if ($diffSecs > 0) {
                $days = floor($diffSecs / 86400);
                $hours = floor(($diffSecs % 86400) / 3600);
                $countdown = $days > 0 ? "Noch {$days} Tag" . ($days != 1 ? 'e' : '') . ", {$hours} Std" : "Noch {$hours} Std";
            }
        ?>
        <a href="../events/view.php?id=<?php echo (int)$event['id']; ?>" class="dash-event-card dash-event-card--<?php echo htmlspecialchars($eventStatus); ?> w-full">
            <!-- Status accent strip -->
            <div class="dash-event-card-accent"></div>
            <!-- Card header: gradient background with date chip -->
            <div class="relative flex items-start gap-4 p-4 md:p-5 md:pb-4">
                <!-- Date chip -->
                <div class="dash-event-date-chip">
                    <span class="dash-event-date-month"><?php echo $monthAbbr; ?></span>
                    <span class="dash-event-date-day"><?php echo date('d', $ts); ?></span>
                </div>
                <!-- Title & meta -->
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-base leading-snug line-clamp-2 mb-1.5 break-words hyphens-auto" style="color: var(--text-main)">
                        <?php echo htmlspecialchars($event['title']); ?>
                    </h3>
                    <div class="space-y-1 text-xs" style="color: var(--text-muted)">
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-clock text-blue-400 w-3 text-center"></i>
                            <span><?php echo date('H:i', $ts); ?> Uhr</span>
                        </div>
                        <?php if (!empty($event['location'])): ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-map-marker-alt text-blue-400 w-3 text-center"></i>
                            <span class="truncate"><?php echo htmlspecialchars($event['location']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Footer: status badge + countdown -->
            <div class="px-4 md:px-5 pb-4 flex items-center justify-between gap-2" style="border-top: 1px solid var(--border-color)">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $statusInfo['color']; ?>">
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($countdown): ?>
                <span class="text-xs font-medium" style="color: var(--text-muted)">
                    <i class="fas fa-hourglass-half mr-1 text-amber-400"></i><?php echo $countdown; ?>
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 text-blue-600 font-semibold text-xs">
                    Details <i class="fas fa-arrow-right"></i>
                </span>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Offene Rechnungen Section -->
<?php if ($canAccessInvoices && !empty($recentOpenInvoices)): ?>
<div class="dash-wrap mb-6 md:mb-10">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <h2 class="dash-section-title">Offene Rechnungen</h2>
        </div>
        <a href="/pages/invoices/index.php" class="dash-section-link" style="color: var(--ibc-green)">
            Alle Rechnungen <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php
        $invStatusLabels = [
            'pending'  => 'In Prüfung',
            'approved' => 'Offen',
        ];
    ?>
    <div class="card rounded-2xl overflow-hidden" style="background-color: var(--bg-card); border: 1.5px solid var(--border-color); box-shadow: var(--shadow-card);">
        <div class="divide-y" style="border-color: var(--border-color)">
            <?php foreach ($recentOpenInvoices as $inv): ?>
            <?php
                $invStatus = $inv['status'];
                $badgeLbl  = $invStatusLabels[$invStatus] ?? ucfirst($invStatus);
            ?>
            <a href="/pages/invoices/index.php" class="flex items-center gap-4 px-4 py-3 md:px-5 md:py-4 dash-hover-card" style="color: inherit; text-decoration: none;">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center flex-shrink-0 shadow-sm">
                    <i class="fas fa-receipt text-white text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-sm truncate" style="color: var(--text-main)">
                        <?php echo htmlspecialchars($inv['description'] ?: 'Keine Beschreibung'); ?>
                    </p>
                    <p class="text-xs mt-0.5" style="color: var(--text-muted)">
                        <i class="fas fa-calendar-alt mr-1 text-emerald-500"></i>
                        <?php echo date('d.m.Y', strtotime($inv['created_at'])); ?>
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="font-bold text-sm" style="color: var(--ibc-green)">
                        <?php echo number_format((float)$inv['amount'], 2, ',', '.'); ?>&nbsp;€
                    </span>
                    <span class="invoice-badge invoice-badge--<?php echo $invStatus; ?>">
                        <span class="invoice-badge-dot"></span>
                        <?php echo $badgeLbl; ?>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Wir suchen Helfer Section -->
<div class="dash-wrap mb-6 md:mb-10">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff">
                <i class="fas fa-hands-helping"></i>
            </div>
            <h2 class="dash-section-title">Wir suchen Helfer</h2>
        </div>
        <a href="../events/index.php" class="dash-section-link" style="color: var(--ibc-green)">
            Alle Events <i class="fas fa-arrow-right"></i>
        </a>
    </div>

    <?php if (!empty($helperEvents)): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 sm:gap-4 md:gap-6">
        <?php foreach ($helperEvents as $event): ?>
        <div class="dash-helper-card w-full">
            <h3 class="text-base font-bold leading-snug break-words hyphens-auto" style="color: var(--text-main)">
                <?php echo htmlspecialchars($event['title']); ?>
            </h3>
            <?php if (!empty($event['description'])): ?>
            <p class="text-sm line-clamp-2 leading-relaxed" style="color: var(--text-muted)">
                <?php echo htmlspecialchars(substr($event['description'], 0, 100)) . (strlen($event['description']) > 100 ? '…' : ''); ?>
            </p>
            <?php endif; ?>
            <div class="text-xs space-y-1" style="color: var(--text-muted)">
                <div class="flex items-center gap-2">
                    <i class="fas fa-clock w-3 text-center" style="color: var(--ibc-green)"></i>
                    <span><?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?> Uhr</span>
                </div>
                <?php if (!empty($event['location'])): ?>
                <div class="flex items-center gap-2">
                    <i class="fas fa-map-marker-alt w-3 text-center" style="color: var(--ibc-green)"></i>
                    <span class="truncate"><?php echo htmlspecialchars($event['location']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <a href="../events/view.php?id=<?php echo $event['id']; ?>"
               class="inline-flex items-center gap-2 px-4 py-2 text-white rounded-xl font-semibold text-sm transition-all hover:opacity-90 hover:-translate-y-0.5 shadow-md"
               style="background: linear-gradient(135deg, var(--ibc-green), var(--ibc-green-dark))">
                Mehr erfahren <i class="fas fa-arrow-right"></i>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="dash-empty">
        <div class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style="background: rgba(0,166,81,0.1)">
            <i class="fas fa-hands-helping text-2xl" style="color: var(--ibc-green)"></i>
        </div>
        <p class="font-semibold mb-1" style="color: var(--text-main)">Aktuell werden keine Helfer gesucht</p>
        <a href="../events/index.php" class="inline-flex items-center gap-1 text-sm font-semibold mt-2" style="color: var(--ibc-green); text-decoration: none">
            Alle Events ansehen <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Neuigkeiten aus dem Blog Section -->
<?php if (!empty($recentBlogPosts)): ?>
<div class="dash-wrap mb-6 md:mb-10">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #6366f1, #7c3aed); color: #fff">
                <i class="fas fa-newspaper"></i>
            </div>
            <h2 class="dash-section-title">Neuigkeiten aus dem Blog</h2>
        </div>
        <a href="../blog/index.php" class="dash-section-link" style="color: #6366f1">
            Alle Artikel <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php
        $blogCategoryColors = [
            'Allgemein'          => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            'IT'                 => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            'Marketing'          => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
            'Human Resources'    => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
            'Qualitätsmanagement'=> 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
            'Akquise'            => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            'Vorstand'           => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
        ];
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 sm:gap-4 md:gap-6">
        <?php foreach ($recentBlogPosts as $post): ?>
        <?php
            $catColor = $blogCategoryColors[$post['category'] ?? ''] ?? $blogCategoryColors['Allgemein'];
            $postDate = new DateTime($post['created_at']);
            $excerpt  = strip_tags($post['content'] ?? '');
            $excerpt  = strlen($excerpt) > 120 ? substr($excerpt, 0, 120) . '…' : $excerpt;
        ?>
        <a href="../blog/view.php?id=<?php echo (int)$post['id']; ?>" class="dash-blog-card w-full">
            <!-- Image / placeholder -->
            <div class="dash-blog-img">
                <?php if (!empty($post['image_path']) && $post['image_path'] !== BlogPost::DEFAULT_IMAGE): ?>
                    <img src="/<?php echo htmlspecialchars(ltrim($post['image_path'], '/')); ?>"
                         alt="<?php echo htmlspecialchars($post['title']); ?>"
                         loading="lazy">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-indigo-500 to-purple-600">
                        <i class="fas fa-newspaper text-white/30 text-4xl"></i>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Content -->
            <div class="p-4 flex-1 flex flex-col">
                <div class="mb-2">
                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full <?php echo $catColor; ?>">
                        <?php echo htmlspecialchars($post['category'] ?? 'Allgemein'); ?>
                    </span>
                </div>
                <h3 class="font-bold text-base leading-snug line-clamp-2 mb-1.5 break-words hyphens-auto" style="color: var(--text-main)">
                    <?php echo htmlspecialchars($post['title']); ?>
                </h3>
                <p class="text-xs mb-2" style="color: var(--text-muted)">
                    <i class="fas fa-calendar-alt mr-1 text-indigo-400"></i>
                    <?php echo $postDate->format('d.m.Y'); ?>
                </p>
                <p class="text-sm flex-1 line-clamp-3 leading-relaxed" style="color: var(--text-muted)">
                    <?php echo htmlspecialchars($excerpt); ?>
                </p>
                <div class="mt-3 pt-3 flex items-center justify-between text-xs" style="border-top: 1px solid var(--border-color); color: var(--text-muted)">
                    <span class="truncate"><i class="fas fa-user-circle mr-1 text-indigo-400"></i><?php echo htmlspecialchars(explode('@', $post['author_email'])[0]); ?></span>
                    <span class="inline-flex items-center gap-1 font-semibold flex-shrink-0" style="color: #6366f1">
                        Lesen <i class="fas fa-arrow-right"></i>
                    </span>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Polls Widget Section -->
<div class="dash-wrap mb-8 md:mb-12">
    <div class="dash-section-hdr">
        <div class="dash-section-hdr-left">
            <div class="dash-section-icon" style="background: linear-gradient(135deg, #f97316, #dc2626); color: #fff">
                <i class="fas fa-poll"></i>
            </div>
            <h2 class="dash-section-title">Aktuelle Umfragen</h2>
        </div>
        <a href="../polls/index.php" class="dash-section-link" style="color: #f97316">
            Alle Umfragen <i class="fas fa-arrow-right"></i>
        </a>
    </div>

    <?php
    // Fetch active polls for the user
    $userAzureRoles = isset($user['azure_roles']) ? json_decode($user['azure_roles'], true) : [];
    
    $pollStmt = $contentDb->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
               (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
        FROM polls p
        WHERE p.is_active = 1 AND p.end_date > NOW()
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $pollStmt->execute([$user['id'], $user['id']]);
    $allPolls = $pollStmt->fetchAll();
    
    // Filter polls using shared helper function
    $visiblePolls = filterPollsForUser($allPolls, $userRole, $userAzureRoles);
    
    if (!empty($visiblePolls)): 
    ?>
    <div class="grid grid-cols-1 gap-2 sm:gap-3 md:gap-4">
        <?php foreach ($visiblePolls as $poll): ?>
        <div class="dash-poll-card w-full">
            <div class="dash-poll-accent"></div>
            <div class="p-4 md:p-5 flex flex-col sm:flex-row items-start gap-4">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm"
                     style="background: rgba(249,115,22,0.12); color: #f97316; font-size: 1.1rem">
                    <i class="fas fa-poll-h"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-base mb-1.5 leading-snug break-words hyphens-auto" style="color: var(--text-main)">
                        <?php echo htmlspecialchars($poll['title']); ?>
                    </h3>
                    <?php if (!empty($poll['description'])): ?>
                    <p class="text-sm mb-2 line-clamp-2 leading-relaxed" style="color: var(--text-muted)">
                        <?php echo htmlspecialchars(substr($poll['description'], 0, 150)) . (strlen($poll['description']) > 150 ? '…' : ''); ?>
                    </p>
                    <?php endif; ?>
                    <p class="text-xs" style="color: var(--text-muted)">
                        <i class="fas fa-clock mr-1"></i>Endet am <?php echo date('d.m.Y', strtotime($poll['end_date'])); ?>
                    </p>
                </div>
                <div class="flex sm:flex-col gap-2 flex-shrink-0">
                    <?php if (!empty($poll['microsoft_forms_url'])): ?>
                    <a href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center gap-1.5 px-4 py-2 text-white rounded-xl font-semibold text-sm transition-all hover:opacity-90"
                       style="background: linear-gradient(135deg, #f97316, #ea580c)">
                        <i class="fas fa-external-link-alt"></i>Zur Umfrage
                    </a>
                    <button onclick="hidePollFromDashboard(<?php echo $poll['id']; ?>)"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl font-semibold text-xs transition-all"
                            style="background: var(--ibc-gray-200); color: var(--text-muted)">
                        <i class="fas fa-eye-slash"></i>Ausblenden
                    </button>
                    <?php else: ?>
                    <a href="../polls/view.php?id=<?php echo $poll['id']; ?>"
                       class="inline-flex items-center gap-1.5 px-4 py-2 text-white rounded-xl font-semibold text-sm transition-all hover:opacity-90"
                       style="background: linear-gradient(135deg, #f97316, #ea580c)">
                        <?php if ($poll['user_has_voted'] > 0): ?>
                            <i class="fas fa-chart-bar"></i>Ergebnisse
                        <?php else: ?>
                            <i class="fas fa-vote-yea"></i>Abstimmen
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="dash-empty">
        <div class="w-14 h-14 rounded-2xl mx-auto mb-4 flex items-center justify-center" style="background: rgba(249,115,22,0.1)">
            <i class="fas fa-poll text-2xl" style="color: #f97316"></i>
        </div>
        <p class="font-semibold mb-1" style="color: var(--text-main)">Keine aktiven Umfragen verfügbar</p>
        <a href="../polls/index.php" class="inline-flex items-center gap-1 text-sm font-semibold mt-2" style="color: #f97316; text-decoration: none">
            Alle Umfragen ansehen <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
function hidePollFromDashboard(pollId) {
    if (!confirm('Möchten Sie diese Umfrage wirklich ausblenden?')) {
        return;
    }
    
    fetch('<?php echo asset('api/hide_poll.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ poll_id: pollId, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to update the dashboard
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
