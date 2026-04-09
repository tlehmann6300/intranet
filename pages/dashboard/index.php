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
// ── Role Label Mapping ───────────────────────────────────────────────────────
$roleLabels = [
    'vorstand_finanzen' => 'Vorstand Finanzen',
    'vorstand_intern'   => 'Vorstand Intern',
    'vorstand_extern'   => 'Vorstand Extern',
    'alumni_vorstand'   => 'Alumni Vorstand',
    'alumni_finanz'     => 'Alumni Finanzen',
    'alumni'            => 'Alumni',
    'mitglied'          => 'Mitglied',
    'ressortleiter'     => 'Ressortleiter',
    'anwaerter'         => 'Anwärter',
    'ehrenmitglied'     => 'Ehrenmitglied',
    'admin'             => 'Administrator',
];
$roleLabel = $roleLabels[$userRole] ?? ucfirst(str_replace('_', ' ', $userRole));

// ── Role Icon Mapping ────────────────────────────────────────────────────────
$roleIconMap = [
    'vorstand_finanzen' => 'fa-coins',
    'vorstand_intern'   => 'fa-building',
    'vorstand_extern'   => 'fa-globe',
    'alumni_vorstand'   => 'fa-crown',
    'alumni_finanz'     => 'fa-chart-line',
    'alumni'            => 'fa-graduation-cap',
    'mitglied'          => 'fa-user-check',
    'ressortleiter'     => 'fa-sitemap',
    'anwaerter'         => 'fa-seedling',
    'ehrenmitglied'     => 'fa-star',
    'admin'             => 'fa-shield-alt',
];
$roleIcon = $roleIconMap[$userRole] ?? 'fa-user';

// ── User Initials ────────────────────────────────────────────────────────────
$initials = 'IBC';
if (!empty($user['firstname']) && !empty($user['lastname'])) {
    $initials = mb_strtoupper(mb_substr($user['firstname'], 0, 1) . mb_substr($user['lastname'], 0, 1), 'UTF-8');
} elseif (!empty($user['firstname'])) {
    $initials = mb_strtoupper(mb_substr($user['firstname'], 0, 2), 'UTF-8');
} elseif ($displayName !== 'Benutzer') {
    $parts = explode(' ', $displayName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1), 'UTF-8');
    if (count($parts) > 1) {
        $initials .= mb_strtoupper(mb_substr($parts[1], 0, 1), 'UTF-8');
    }
}

// ── Role-based Quick Actions ─────────────────────────────────────────────────
$boardRoles = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'admin'];
$isBoard = in_array($userRole, $boardRoles);

$quickActions = [];
$quickActions[] = ['url' => '../events/index.php',    'icon' => 'fa-calendar-alt',        'label' => 'Events',    'key' => 'events'];
$quickActions[] = ['url' => '../inventory/index.php',  'icon' => 'fa-box-open',             'label' => 'Inventar',  'key' => 'inventory'];
if ($canAccessInvoices) {
    $quickActions[] = ['url' => '/pages/invoices/index.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'Rechnungen', 'key' => 'invoices'];
}
if ($isBoard || $userRole === 'ressortleiter') {
    $quickActions[] = ['url' => '/pages/members/index.php', 'icon' => 'fa-users', 'label' => 'Mitglieder', 'key' => 'members'];
}
$quickActions[] = ['url' => '../auth/profile.php', 'icon' => 'fa-user-circle', 'label' => 'Profil', 'key' => 'profile'];

// ── SVG ring for profile ─────────────────────────────────────────────────────
$circumference = 213.628;
$dashOffset = $circumference * (1 - $profileCompletenessPercent / 100);

// ── Load polls ───────────────────────────────────────────────────────────────
$userAzureRoles = isset($user['azure_roles']) ? json_decode($user['azure_roles'], true) : [];
$visiblePolls = [];
try {
    $pollStmt = $contentDb->prepare("
        SELECT p.*,
               (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
               (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
        FROM polls p
        WHERE p.is_active = 1 AND p.end_date > NOW()
        ORDER BY p.created_at DESC LIMIT 5
    ");
    $pollStmt->execute([$user['id'], $user['id']]);
    $allPolls = $pollStmt->fetchAll();
    $visiblePolls = filterPollsForUser($allPolls, $userRole, $userAzureRoles);
} catch (Exception $e) {
    error_log('dashboard: polls fetch failed: ' . $e->getMessage());
}

ob_start();
?>
<!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
<!-- ║  DASHBOARD – Corporate Premium Redesign                            ║ -->
<!-- ╚══════════════════════════════════════════════════════════════════════╝ -->

<style>
/* ════════════════════════════════════════════════════════════════════════════
   DASHBOARD  –  CSS Variables & Base
   ════════════════════════════════════════════════════════════════════════════ */
.dash-page-wrap {
    max-width: 82rem;
    margin: 0 auto;
    padding: 0 0 3rem;
}

/* ── Card System ─────────────────────────────────────────────────────────── */
.d-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1.25rem;
    box-shadow: var(--shadow-card);
    transition: transform 0.26s cubic-bezier(.22,.61,.36,1), box-shadow 0.26s ease, border-color 0.2s ease;
    position: relative;
    overflow: hidden;
}
.d-card--hover:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-card-hover);
}
.d-card--link:hover {
    border-color: var(--ibc-blue);
    transform: translateY(-4px);
    box-shadow: var(--shadow-card-hover);
    text-decoration: none !important;
    color: inherit;
}

/* ── Section Header ──────────────────────────────────────────────────────── */
.d-section-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.d-section-hdr-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.d-section-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
}
.d-section-title {
    font-size: 1.0625rem;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.01em;
    line-height: 1.2;
}
.d-section-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 1px;
}
.d-section-link {
    font-size: 0.8125rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    text-decoration: none !important;
    color: var(--ibc-blue);
    opacity: 0.85;
    transition: opacity 0.18s, gap 0.18s;
    white-space: nowrap;
}
.d-section-link:hover { opacity: 1; gap: 0.6rem; }

/* ── Hero Section ────────────────────────────────────────────────────────── */
.d-hero {
    background: linear-gradient(135deg, #002d5a 0%, #00457D 28%, #005fa3 55%, #007a52 80%, #00A651 100%);
    border-radius: 1.5rem;
    padding: 2rem 2rem 1.75rem;
    position: relative;
    overflow: hidden;
    color: #fff;
    box-shadow: 0 16px 48px rgba(0,45,90,0.28), 0 4px 16px rgba(0,70,125,0.18);
}
@media (min-width: 768px) { .d-hero { padding: 2.5rem 2.75rem 2.25rem; } }
@media (min-width: 1024px) { .d-hero { padding: 2.75rem 3rem 2.5rem; } }

/* Hero orb effects */
.d-hero::before {
    content: '';
    position: absolute;
    top: -4rem; right: -4rem;
    width: 22rem; height: 22rem;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.09) 0%, transparent 70%);
    pointer-events: none;
    animation: d-orb-pulse 7s ease-in-out infinite;
}
.d-hero::after {
    content: '';
    position: absolute;
    bottom: -5rem; left: -3rem;
    width: 18rem; height: 18rem;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0,210,110,0.18) 0%, transparent 70%);
    pointer-events: none;
    animation: d-orb-pulse 9s ease-in-out 2s infinite;
}
@keyframes d-orb-pulse {
    0%, 100% { transform: scale(1);    opacity: 0.6; }
    50%       { transform: scale(1.1); opacity: 1;   }
}

/* Hero grid pattern */
.d-hero-grid {
    position: absolute;
    inset: 0;
    pointer-events: none;
    opacity: 0.04;
    background-image: linear-gradient(rgba(255,255,255,1) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,1) 1px, transparent 1px);
    background-size: 32px 32px;
}

/* User avatar */
.d-hero-avatar {
    width: 4rem;
    height: 4rem;
    border-radius: 1rem;
    background: rgba(255,255,255,0.18);
    border: 2px solid rgba(255,255,255,0.38);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    letter-spacing: -0.02em;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}
@media (min-width: 768px) {
    .d-hero-avatar {
        width: 4.75rem;
        height: 4.75rem;
        font-size: 1.4rem;
        border-radius: 1.25rem;
    }
}

/* Role badge */
.d-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.8rem;
    border-radius: 9999px;
    background: rgba(255,255,255,0.16);
    border: 1.5px solid rgba(255,255,255,0.3);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(255,255,255,0.95);
    letter-spacing: 0.03em;
    width: fit-content;
    margin-bottom: 0.625rem;
}

/* Hero title */
.d-hero-title {
    font-size: clamp(1.6rem, 4vw, 2.5rem);
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -0.02em;
    color: #fff;
    text-shadow: 0 2px 12px rgba(0,0,0,0.2);
    margin: 0 0 0.35rem;
}
.d-hero-subtitle {
    font-size: 0.9375rem;
    color: rgba(255,255,255,0.78);
    line-height: 1.55;
    margin-bottom: 1.25rem;
}

/* Quick action chips */
.d-quick-action {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 700;
    color: #fff !important;
    text-decoration: none !important;
    letter-spacing: 0.02em;
    border: 1.5px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: background 0.2s, transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    white-space: nowrap;
}
.d-quick-action:hover {
    background: rgba(255,255,255,0.26);
    border-color: rgba(255,255,255,0.5);
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0,0,0,0.18);
}
.d-quick-action--events    { background: rgba(59,130,246,0.3);  border-color: rgba(147,197,253,0.45); }
.d-quick-action--events:hover    { background: rgba(59,130,246,0.48); }
.d-quick-action--inventory { background: rgba(249,115,22,0.3);  border-color: rgba(253,186,116,0.45); }
.d-quick-action--inventory:hover { background: rgba(249,115,22,0.48); }
.d-quick-action--invoices  { background: rgba(16,185,129,0.3);  border-color: rgba(110,231,183,0.45); }
.d-quick-action--invoices:hover  { background: rgba(16,185,129,0.48); }
.d-quick-action--members   { background: rgba(139,92,246,0.3);  border-color: rgba(196,181,253,0.45); }
.d-quick-action--members:hover   { background: rgba(139,92,246,0.48); }
.d-quick-action--profile   { background: rgba(236,72,153,0.3);  border-color: rgba(249,168,212,0.45); }
.d-quick-action--profile:hover   { background: rgba(236,72,153,0.48); }

/* Hero decorative card (desktop) */
.d-hero-deco {
    width: 6.5rem;
    height: 6.5rem;
    border-radius: 1.5rem;
    background: rgba(255,255,255,0.1);
    border: 1.5px solid rgba(255,255,255,0.22);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    flex-shrink: 0;
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
}

/* ── Stat Cards ─────────────────────────────────────────────────────────── */
.d-stat-card {
    display: flex;
    flex-direction: column;
    padding: 1.375rem 1.5rem;
    border-radius: 1.25rem;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    box-shadow: var(--shadow-card);
    position: relative;
    overflow: hidden;
    transition: transform 0.26s cubic-bezier(.22,.61,.36,1), box-shadow 0.26s ease, border-color 0.2s ease;
    text-decoration: none !important;
    color: inherit;
}
.d-stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--d-stat-accent, var(--ibc-blue));
    border-radius: 1.25rem 1.25rem 0 0;
}
.d-stat-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 8rem; height: 8rem;
    border-radius: 50%;
    background: radial-gradient(circle, var(--d-stat-glow, rgba(0,102,179,0.07)) 0%, transparent 70%);
    pointer-events: none;
    transform: translate(25%, -25%);
}
.d-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-card-hover);
    border-color: var(--d-stat-accent, var(--ibc-blue));
}
.d-stat-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
    background: var(--d-stat-icon-bg, rgba(0,102,179,0.1));
    color: var(--d-stat-accent, var(--ibc-blue));
    margin-bottom: 0.875rem;
}
.d-stat-num {
    font-size: 2.25rem;
    font-weight: 900;
    color: var(--text-main);
    line-height: 1;
    letter-spacing: -0.03em;
    margin-bottom: 0.2rem;
}
.d-stat-label {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}
.d-stat-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: auto;
}

/* ── Profile Ring Card ───────────────────────────────────────────────────── */
.d-profile-ring-wrap {
    position: relative;
    width: 5rem;
    height: 5rem;
    flex-shrink: 0;
}
.d-profile-ring-wrap svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}
.d-profile-ring-pct {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.925rem;
    font-weight: 800;
    background: linear-gradient(135deg, #a855f7, #ec4899);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── Event List Items ────────────────────────────────────────────────────── */
.d-event-item {
    display: flex;
    align-items: stretch;
    gap: 1rem;
    padding: 1rem 1.25rem;
    text-decoration: none !important;
    color: inherit;
    transition: background 0.18s;
    position: relative;
}
.d-event-item:not(:last-child) {
    border-bottom: 1px solid var(--border-color);
}
.d-event-item:hover {
    background: rgba(0,70,125,0.04);
}
.dark-mode .d-event-item:hover {
    background: rgba(255,255,255,0.03);
}
.d-event-date-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 3rem;
    width: 3rem;
    padding: 0.5rem 0.35rem;
    border-radius: 0.75rem;
    background: rgba(0,102,179,0.07);
    border: 1.5px solid rgba(0,102,179,0.15);
    flex-shrink: 0;
    line-height: 1.1;
    text-align: center;
}
.d-event-date-month {
    font-size: 0.625rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--ibc-blue);
}
.d-event-date-day {
    font-size: 1.375rem;
    font-weight: 900;
    color: var(--text-main);
    letter-spacing: -0.02em;
}
.d-event-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
}

/* ── Invoice List Items ──────────────────────────────────────────────────── */
.d-invoice-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.875rem 1.25rem;
    text-decoration: none !important;
    color: inherit;
    transition: background 0.18s;
}
.d-invoice-item:not(:last-child) {
    border-bottom: 1px solid var(--border-color);
}
.d-invoice-item:hover {
    background: rgba(0,166,81,0.04);
}
.dark-mode .d-invoice-item:hover {
    background: rgba(255,255,255,0.03);
}
.d-invoice-icon {
    width: 2.375rem;
    height: 2.375rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.75rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #fff;
    box-shadow: 0 2px 8px rgba(16,185,129,0.25);
}

/* Invoice badge */
.d-inv-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
}
.d-inv-badge--pending  { background: rgba(245,158,11,0.12); color: #92400e; }
.d-inv-badge--approved { background: rgba(16,185,129,0.12); color: #065f46; }
.dark-mode .d-inv-badge--pending  { background: rgba(245,158,11,0.18); color: #fde68a; }
.dark-mode .d-inv-badge--approved { background: rgba(16,185,129,0.18); color: #6ee7b7; }
.d-inv-badge-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.d-inv-badge--pending .d-inv-badge-dot  { background: #f59e0b; }
.d-inv-badge--approved .d-inv-badge-dot { background: #10b981; }

/* ── Blog Cards ──────────────────────────────────────────────────────────── */
.d-blog-card {
    display: flex;
    flex-direction: column;
    border-radius: 1.125rem;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    box-shadow: var(--shadow-card);
    overflow: hidden;
    transition: transform 0.26s cubic-bezier(.22,.61,.36,1), box-shadow 0.26s ease, border-color 0.2s ease;
    text-decoration: none !important;
    color: inherit;
}
.d-blog-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-card-hover);
    border-color: var(--ibc-blue);
}
.d-blog-img {
    height: 10rem;
    overflow: hidden;
    flex-shrink: 0;
    position: relative;
    background: linear-gradient(135deg, #004d9e 0%, #001f3a 100%);
}
.d-blog-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}
.d-blog-card:hover .d-blog-img img { transform: scale(1.06); }

/* ── Poll Items ──────────────────────────────────────────────────────────── */
.d-poll-item {
    padding: 0.875rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.875rem;
    transition: background 0.18s;
}
.d-poll-item:not(:last-child) {
    border-bottom: 1px solid var(--border-color);
}
.d-poll-item:hover {
    background: rgba(249,115,22,0.04);
}
.dark-mode .d-poll-item:hover {
    background: rgba(255,255,255,0.03);
}

/* ── Helper Event Items ──────────────────────────────────────────────────── */
.d-helper-item {
    padding: 0.875rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    transition: background 0.18s;
}
.d-helper-item:not(:last-child) {
    border-bottom: 1px solid var(--border-color);
}
.d-helper-item:hover {
    background: rgba(34,197,94,0.04);
}
.dark-mode .d-helper-item:hover {
    background: rgba(255,255,255,0.03);
}

/* ── Empty State ─────────────────────────────────────────────────────────── */
.d-empty {
    padding: 2.5rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 0.5rem;
}
.d-empty-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}

/* ── Stagger animations ──────────────────────────────────────────────────── */
@keyframes d-fadein {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: no-preference) {
    .d-hero         { animation: d-fadein 0.45s cubic-bezier(.22,.61,.36,1) both; }
    .d-stats-wrap > * { animation: d-fadein 0.45s cubic-bezier(.22,.61,.36,1) both; }
    .d-stats-wrap > *:nth-child(1) { animation-delay: 0.05s; }
    .d-stats-wrap > *:nth-child(2) { animation-delay: 0.1s; }
    .d-stats-wrap > *:nth-child(3) { animation-delay: 0.15s; }
    .d-stats-wrap > *:nth-child(4) { animation-delay: 0.2s; }
    .d-main-col  { animation: d-fadein 0.45s cubic-bezier(.22,.61,.36,1) 0.15s both; }
    .d-side-col  { animation: d-fadein 0.45s cubic-bezier(.22,.61,.36,1) 0.22s both; }
    .d-blog-section { animation: d-fadein 0.45s cubic-bezier(.22,.61,.36,1) 0.25s both; }
}

/* ── Utility ─────────────────────────────────────────────────────────────── */
.d-line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.d-line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
.d-content-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}
@media (min-width: 900px) {
    .d-content-grid {
        grid-template-columns: 1fr 22rem;
    }
}
@media (min-width: 1100px) {
    .d-content-grid {
        grid-template-columns: 1fr 24rem;
    }
}

.d-stats-wrap {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}
@media (min-width: 540px) {
    .d-stats-wrap {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (min-width: 768px) {
    .d-stats-wrap {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (min-width: 1100px) {
    .d-stats-wrap {
        grid-template-columns: repeat(<?php echo $canAccessInvoices ? '4' : '3'; ?>, 1fr);
    }
}
</style>

<?php if (!empty($user['prompt_profile_review']) && $user['prompt_profile_review'] == 1): ?>
<!-- Profile Review Prompt Modal (premium variant) -->
<div id="profile-review-modal" class="fixed inset-0 flex items-center justify-center z-50 p-4"
     style="background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);">
    <div class="ibc-modal-card rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
        <div style="background:linear-gradient(135deg,#7c3aed 0%,#4f46e5 100%);padding:1.5rem 1.5rem 1.25rem;">
            <div class="flex items-center gap-4">
                <div class="ibc-modal-header-icon">
                    <i class="fas fa-user-edit" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
                </div>
                <div>
                    <p style="font-size:0.75rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:rgba(255,255,255,0.75);margin-bottom:2px;">Hinweis</p>
                    <h3 style="font-size:1.125rem;font-weight:700;color:#fff;margin:0;">Deine Rolle wurde geändert!</h3>
                </div>
            </div>
        </div>
        <div style="padding:1.5rem;">
            <p style="color:var(--text-main);margin-bottom:1.25rem;font-size:0.9375rem;line-height:1.6;">
                Bitte überprüfe deine Daten (besonders E-Mail und Job-Daten), damit wir in Kontakt bleiben können.
            </p>
            <div style="border-radius:12px;padding:1rem 1.25rem;display:flex;gap:0.875rem;align-items:flex-start;background:rgba(124,58,237,0.08);border:1px solid rgba(124,58,237,0.22);">
                <i class="fas fa-info-circle" style="color:#7c3aed;margin-top:2px;flex-shrink:0;" aria-hidden="true"></i>
                <p style="color:var(--text-muted);font-size:0.875rem;line-height:1.55;margin:0;">
                    Aktuelle Kontaktdaten helfen dir, alle wichtigen Infos zu erhalten.
                </p>
            </div>
        </div>
        <div style="padding:1rem 1.5rem 1.5rem;display:flex;gap:0.75rem;">
            <a href="../auth/profile.php"
               style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:0.75rem 1.5rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff !important;font-weight:600;font-size:0.9375rem;border-radius:10px;border:none;cursor:pointer;text-decoration:none !important;box-shadow:0 4px 12px rgba(124,58,237,0.35);transition:opacity 0.2s ease;">
                <i class="fas fa-user-circle" aria-hidden="true"></i>
                Zum Profil
            </a>
            <button onclick="dismissProfileReviewPrompt()" class="ibc-modal-btn-secondary">Später</button>
        </div>
    </div>
</div>
<script>
function dismissProfileReviewPrompt() {
    fetch(window.location.origin + '/api/dismiss_profile_review.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    }).catch(function(){}).finally(function(){
        document.getElementById('profile-review-modal').style.display = 'none';
    });
}
</script>
<?php endif; ?>

<?php if (empty($user['has_seen_onboarding'])): ?>
<!-- Onboarding Welcome Modal -->
<div id="onboarding-modal" class="fixed inset-0 flex items-center justify-center z-50 p-4"
     style="background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);">
    <div class="ibc-modal-card rounded-2xl shadow-2xl w-full max-w-lg flex flex-col overflow-hidden">
        <div style="background:linear-gradient(135deg,#0066b3 0%,#004d9e 50%,#00a651 100%);padding:1.5rem 1.5rem 1.25rem;">
            <div class="flex justify-center gap-2 mb-4">
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white transition-all duration-300" data-slide="0" style="width:10px;height:10px;border-radius:9999px;background:#fff;display:inline-block;"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white transition-all duration-300" data-slide="1" style="width:10px;height:10px;border-radius:9999px;background:rgba(255,255,255,0.4);display:inline-block;"></span>
                <span class="onboarding-dot w-2.5 h-2.5 rounded-full bg-white transition-all duration-300" data-slide="2" style="width:10px;height:10px;border-radius:9999px;background:rgba(255,255,255,0.4);display:inline-block;"></span>
            </div>
            <div class="flex items-center justify-center">
                <div class="ibc-modal-header-icon" id="onboarding-icon">
                    <i id="onboarding-icon-el" class="fas fa-calendar-alt" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
                </div>
            </div>
            <h3 id="onboarding-title" style="font-size:1.125rem;font-weight:700;color:#fff;text-align:center;margin:0.75rem 0 0;">Events &amp; Projekte</h3>
        </div>
        <div style="padding:1.5rem;min-height:140px;">
            <div class="onboarding-slide" id="slide-0">
                <p style="color:var(--text-main);font-size:0.9375rem;line-height:1.6;margin-bottom:0.875rem;">
                    Entdecke kommende <strong>Events</strong> und laufende <strong>Projekte</strong> im IBC-Intranet.
                </p>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Melde dich für Events an oder trag dich als Helfer ein
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Verfolge den Fortschritt laufender Projekte
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Bleib mit deinem Kalender immer auf dem neuesten Stand
                    </div>
                </div>
            </div>
            <div class="onboarding-slide hidden" id="slide-1">
                <p style="color:var(--text-main);font-size:0.9375rem;line-height:1.6;margin-bottom:0.875rem;">
                    Leih dir Equipment direkt über das <strong>Inventar</strong>-Modul aus – schnell und unkompliziert.
                </p>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Durchsuche verfügbare Geräte und Materialien
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Stelle eine Ausleih-Anfrage in wenigen Klicks
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Behalte deine aktiven Ausleihen im Blick
                    </div>
                </div>
            </div>
            <div class="onboarding-slide hidden" id="slide-2">
                <p style="color:var(--text-main);font-size:0.9375rem;line-height:1.6;margin-bottom:0.875rem;">
                    Teile deine Ideen in der <strong>Ideenbox</strong> und stöbere im <strong>Shop</strong>.
                </p>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Reiche Ideen ein und stimme über Vorschläge ab
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Bestelle Merchandise im externen Shop
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;font-size:0.875rem;color:var(--text-muted);">
                        <i class="fas fa-check-circle" style="color:#10b981;flex-shrink:0;"></i>Gestalte den IBC aktiv mit!
                    </div>
                </div>
            </div>
        </div>
        <div style="padding:1rem 1.5rem 1.5rem;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-color);">
            <span id="onboarding-step-label" style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Schritt 1 von 3</span>
            <button id="onboarding-next-btn" onclick="onboardingNext()"
                    class="ibc-modal-btn-primary" style="padding:0.625rem 1.25rem;font-size:0.875rem;">
                Weiter <i class="fas fa-arrow-right" style="margin-left:0.375rem;" aria-hidden="true"></i>
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
        document.querySelectorAll('.onboarding-slide').forEach(function(el, i) {
            el.classList.toggle('hidden', i !== currentSlide);
        });
        document.querySelectorAll('.onboarding-dot').forEach(function(el, i) {
            el.style.background = (i === currentSlide) ? '#fff' : 'rgba(255,255,255,0.4)';
        });
        document.getElementById('onboarding-icon-el').className = 'fas ' + slides[currentSlide].icon;
        document.getElementById('onboarding-title').innerHTML = slides[currentSlide].title;
        document.getElementById('onboarding-step-label').textContent = 'Schritt ' + (currentSlide + 1) + ' von 3';
        var btn = document.getElementById('onboarding-next-btn');
        btn.innerHTML = currentSlide === slides.length - 1
            ? 'Loslegen <i class="fas fa-rocket" style="margin-left:0.375rem;"></i>'
            : 'Weiter <i class="fas fa-arrow-right" style="margin-left:0.375rem;"></i>';
    }
    window.onboardingNext = function() {
        if (currentSlide < slides.length - 1) {
            currentSlide++;
            updateSlide();
        } else {
            var modal = document.getElementById('onboarding-modal');
            fetch(window.location.origin + '/api/complete_onboarding.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
            }).catch(function(){}).finally(function() { modal.style.display = 'none'; });
        }
    };
})();
</script>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════
     DASHBOARD PAGE
     ════════════════════════════════════════════════ -->
<div class="dash-page-wrap">

    <!-- ── HERO ──────────────────────────────────────────────────────────── -->
    <div class="d-hero mb-6">
        <div class="d-hero-grid" aria-hidden="true"></div>
        <div style="position:relative;z-index:2;display:flex;flex-direction:column;gap:1.25rem;">
            <!-- Top row: avatar + content -->
            <div style="display:flex;align-items:flex-start;gap:1.25rem;flex-wrap:wrap;">
                <!-- Avatar -->
                <div class="d-hero-avatar" aria-hidden="true">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <!-- Greeting text -->
                <div style="flex:1;min-width:0;">
                    <!-- Role badge -->
                    <div class="d-role-badge">
                        <i class="fas <?php echo $roleIcon; ?>" style="font-size:0.7rem;" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($roleLabel); ?>
                    </div>
                    <!-- Name + greeting -->
                    <h1 class="d-hero-title"><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($displayName); ?>!</h1>
                    <p class="d-hero-subtitle">
                        <?php
                        $germanMonths = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',
                                         7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
                        $monthNum = (int)date('n');
                        $germanDays = ['Monday'=>'Montag','Tuesday'=>'Dienstag','Wednesday'=>'Mittwoch',
                                       'Thursday'=>'Donnerstag','Friday'=>'Freitag','Saturday'=>'Samstag','Sunday'=>'Sonntag'];
                        $dayDe = $germanDays[date('l')] ?? date('l');
                        echo htmlspecialchars($dayDe . ', ' . date('d') . '. ' . ($germanMonths[$monthNum] ?? '') . ' ' . date('Y'));
                        ?> &mdash; Willkommen im IBC&nbsp;Intranet.
                    </p>
                </div>
            </div>
            <!-- Quick actions row -->
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                <?php foreach ($quickActions as $qa): ?>
                <a href="<?php echo htmlspecialchars($qa['url']); ?>" class="d-quick-action d-quick-action--<?php echo $qa['key']; ?>">
                    <i class="fas <?php echo $qa['icon']; ?>" style="font-size:0.75rem;" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($qa['label']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── STATS ROW ─────────────────────────────────────────────────────── -->
    <div class="d-stats-wrap mb-6">

        <!-- Upcoming Events (registered) -->
        <a href="../events/index.php" class="d-stat-card"
           style="--d-stat-accent:#3b82f6;--d-stat-icon-bg:rgba(59,130,246,0.1);--d-stat-glow:rgba(59,130,246,0.1);"
           aria-label="Meine Events">
            <div class="d-stat-icon"><i class="fas fa-calendar-alt" aria-hidden="true"></i></div>
            <div class="d-stat-num"><?php echo count($events); ?></div>
            <div class="d-stat-label">Meine Events</div>
            <div class="d-stat-meta">
                <?php if (!empty($nextEvents)): ?>
                    <?php $ts0 = strtotime($nextEvents[0]['start_time']); ?>
                    <i class="fas fa-clock" style="color:#3b82f6;" aria-hidden="true"></i>
                    Nächstes: <?php echo date('d.m.', $ts0); ?>
                <?php else: ?>
                    <i class="fas fa-info-circle" aria-hidden="true"></i>Keine angemeldeten Events
                <?php endif; ?>
            </div>
        </a>

        <!-- Open Rentals -->
        <a href="/pages/inventory/my_rentals.php" class="d-stat-card"
           style="--d-stat-accent:#f97316;--d-stat-icon-bg:rgba(249,115,22,0.1);--d-stat-glow:rgba(249,115,22,0.1);"
           aria-label="Meine Ausleihen">
            <div class="d-stat-icon"><i class="fas fa-box-open" aria-hidden="true"></i></div>
            <div class="d-stat-num"><?php echo $openTasksCount; ?></div>
            <div class="d-stat-label">Ausleihen</div>
            <div class="d-stat-meta">
                <?php if ($openTasksCount > 0): ?>
                    <i class="fas fa-exclamation-circle" style="color:#f97316;" aria-hidden="true"></i>
                    <?php echo $openTasksCount; ?> offen
                <?php else: ?>
                    <i class="fas fa-check-circle" style="color:#10b981;" aria-hidden="true"></i>Keine offenen
                <?php endif; ?>
            </div>
        </a>

        <?php if ($canAccessInvoices): ?>
        <!-- Open Invoices -->
        <a href="/pages/invoices/index.php" class="d-stat-card"
           style="--d-stat-accent:#10b981;--d-stat-icon-bg:rgba(16,185,129,0.1);--d-stat-glow:rgba(16,185,129,0.1);"
           aria-label="Offene Rechnungen">
            <div class="d-stat-icon"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i></div>
            <div class="d-stat-num"><?php echo $openInvoicesCount; ?></div>
            <div class="d-stat-label">Rechnungen</div>
            <div class="d-stat-meta">
                <?php if ($openInvoicesCount > 0): ?>
                    <i class="fas fa-hourglass-half" style="color:#f59e0b;" aria-hidden="true"></i>
                    <?php echo $openInvoicesCount; ?> ausstehend
                <?php else: ?>
                    <i class="fas fa-check-circle" style="color:#10b981;" aria-hidden="true"></i>Alle bearbeitet
                <?php endif; ?>
            </div>
        </a>
        <?php endif; ?>

        <!-- Profile Completeness (stat variant) -->
        <?php if (in_array($userRole, $rolesRequiringProfile)): ?>
        <a href="../auth/profile.php" class="d-stat-card"
           style="--d-stat-accent:#a855f7;--d-stat-icon-bg:rgba(168,85,247,0.1);--d-stat-glow:rgba(168,85,247,0.1);"
           aria-label="Profilvollständigkeit">
            <div class="d-stat-icon"><i class="fas fa-user-circle" aria-hidden="true"></i></div>
            <div class="d-stat-num"><?php echo $profileCompletenessPercent; ?><span style="font-size:1.2rem;font-weight:700;">%</span></div>
            <div class="d-stat-label">Profil</div>
            <div class="d-stat-meta">
                <?php if ($profileCompletenessPercent < 100): ?>
                    <i class="fas fa-edit" style="color:#a855f7;" aria-hidden="true"></i>Vervollständigen
                <?php else: ?>
                    <i class="fas fa-check-circle" style="color:#10b981;" aria-hidden="true"></i>Vollständig
                <?php endif; ?>
            </div>
        </a>
        <?php endif; ?>

    </div>

    <!-- ── 2-COLUMN CONTENT GRID ─────────────────────────────────────────── -->
    <div class="d-content-grid mb-6">

        <!-- ── MAIN COLUMN (left) ──────────────────────────────────────── -->
        <div class="d-main-col" style="display:flex;flex-direction:column;gap:1.5rem;">

            <!-- Meine nächsten Events -->
            <div>
                <div class="d-section-hdr">
                    <div class="d-section-hdr-left">
                        <div class="d-section-icon" style="background:linear-gradient(135deg,#3b82f6,#4f46e5);color:#fff;">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="d-section-title">Meine nächsten Events</div>
                            <div class="d-section-sub">Veranstaltungen, für die du angemeldet bist</div>
                        </div>
                    </div>
                    <a href="../events/index.php" class="d-section-link">
                        Alle Events <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div class="d-card" style="overflow:hidden;">
                    <?php if (!empty($events)):
                        $monthAbbrs = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
                        $eventStatusDotColors = [
                            'open'    => '#10b981',
                            'planned' => '#3b82f6',
                            'closed'  => '#f59e0b',
                        ];
                        $eventStatusLabels = [
                            'open'    => 'Anmeldung offen',
                            'planned' => 'Geplant',
                            'closed'  => 'Anmeldung geschlossen',
                        ];
                        foreach ($events as $event):
                            $ts = strtotime($event['start_time']);
                            $monthAbbr = $monthAbbrs[(int)date('n', $ts) - 1];
                            $eventStatus = $event['status'] ?? 'planned';
                            $dotColor = $eventStatusDotColors[$eventStatus] ?? '#3b82f6';
                            $statusLabel = $eventStatusLabels[$eventStatus] ?? 'Geplant';
                            $diffSecs = $ts - time();
                            $countdown = '';
                            if ($diffSecs > 0) {
                                $days = floor($diffSecs / 86400);
                                $hours = floor(($diffSecs % 86400) / 3600);
                                $countdown = $days > 0
                                    ? "Noch {$days} Tag" . ($days != 1 ? 'e' : '')
                                    : "Noch {$hours} Std.";
                            }
                    ?>
                    <a href="../events/view.php?id=<?php echo (int)$event['id']; ?>" class="d-event-item">
                        <!-- Date chip -->
                        <div class="d-event-date-chip">
                            <span class="d-event-date-month"><?php echo $monthAbbr; ?></span>
                            <span class="d-event-date-day"><?php echo date('d', $ts); ?></span>
                        </div>
                        <!-- Content -->
                        <div style="flex:1;min-width:0;">
                            <div class="d-line-clamp-2" style="font-weight:700;font-size:0.9375rem;color:var(--text-main);line-height:1.3;margin-bottom:0.35rem;">
                                <?php echo htmlspecialchars($event['title']); ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:0.875rem;flex-wrap:wrap;">
                                <span style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.3rem;">
                                    <i class="fas fa-clock" style="color:#3b82f6;font-size:0.65rem;" aria-hidden="true"></i>
                                    <?php echo date('H:i', $ts); ?> Uhr
                                </span>
                                <?php if (!empty($event['location'])): ?>
                                <span style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.3rem;min-width:0;">
                                    <i class="fas fa-map-marker-alt" style="color:#3b82f6;font-size:0.65rem;flex-shrink:0;" aria-hidden="true"></i>
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($event['location']); ?></span>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Status + countdown -->
                        <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:0.35rem;">
                            <div style="display:flex;align-items:center;gap:0.35rem;font-size:0.7rem;font-weight:700;color:var(--text-muted);">
                                <span class="d-event-status-dot" style="background:<?php echo $dotColor; ?>;"></span>
                                <span><?php echo $statusLabel; ?></span>
                            </div>
                            <?php if ($countdown): ?>
                            <span style="font-size:0.7rem;color:var(--text-muted);"><?php echo $countdown; ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="d-empty">
                        <div class="d-empty-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;">
                            <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                        </div>
                        <p style="font-weight:700;font-size:0.9375rem;color:var(--text-main);margin:0;">Keine anstehenden Events</p>
                        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0;">Du bist für keine Events angemeldet.</p>
                        <a href="../events/index.php" style="font-size:0.8125rem;font-weight:700;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:0.375rem;margin-top:0.25rem;">
                            Events entdecken <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Offene Rechnungen (if applicable) -->
            <?php if ($canAccessInvoices && !empty($recentOpenInvoices)): ?>
            <div>
                <div class="d-section-hdr">
                    <div class="d-section-hdr-left">
                        <div class="d-section-icon" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;">
                            <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="d-section-title">Offene Rechnungen</div>
                            <div class="d-section-sub">Ausstehende Rechnungen &amp; Bearbeitungen</div>
                        </div>
                    </div>
                    <a href="/pages/invoices/index.php" class="d-section-link" style="color:#10b981;">
                        Alle ansehen <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="d-card" style="overflow:hidden;">
                    <?php
                    $invStatusLabels = ['pending' => 'In Prüfung', 'approved' => 'Freigegeben'];
                    foreach ($recentOpenInvoices as $inv):
                        $invStatus = $inv['status'];
                        $badgeLbl  = $invStatusLabels[$invStatus] ?? ucfirst($invStatus);
                    ?>
                    <a href="/pages/invoices/index.php" class="d-invoice-item">
                        <div class="d-invoice-icon">
                            <i class="fas fa-receipt" aria-hidden="true"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-weight:600;font-size:0.875rem;color:var(--text-main);margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo htmlspecialchars($inv['description'] ?: 'Keine Beschreibung'); ?>
                            </p>
                            <p style="font-size:0.75rem;color:var(--text-muted);margin:0.15rem 0 0;display:flex;align-items:center;gap:0.3rem;">
                                <i class="fas fa-calendar-alt" style="color:#10b981;font-size:0.65rem;" aria-hidden="true"></i>
                                <?php echo date('d.m.Y', strtotime($inv['created_at'])); ?>
                            </p>
                        </div>
                        <div style="flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:0.3rem;">
                            <span style="font-weight:800;font-size:0.875rem;color:var(--ibc-green);">
                                <?php echo number_format((float)$inv['amount'], 2, ',', '.'); ?>&nbsp;€
                            </span>
                            <span class="d-inv-badge d-inv-badge--<?php echo $invStatus; ?>">
                                <span class="d-inv-badge-dot"></span>
                                <?php echo $badgeLbl; ?>
                            </span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Wir suchen Helfer -->
            <div>
                <div class="d-section-hdr">
                    <div class="d-section-hdr-left">
                        <div class="d-section-icon" style="background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;">
                            <i class="fas fa-hands-helping" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="d-section-title">Helfer gesucht</div>
                            <div class="d-section-sub">Events, bei denen Unterstützung benötigt wird</div>
                        </div>
                    </div>
                    <a href="../events/index.php" class="d-section-link" style="color:#22c55e;">
                        Alle Events <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="d-card" style="overflow:hidden;">
                    <?php if (!empty($helperEvents)):
                        foreach ($helperEvents as $hEvent):
                    ?>
                    <div class="d-helper-item">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;">
                            <span style="font-weight:700;font-size:0.9rem;color:var(--text-main);line-height:1.35;" class="d-line-clamp-2">
                                <?php echo htmlspecialchars($hEvent['title']); ?>
                            </span>
                            <a href="../events/view.php?id=<?php echo (int)$hEvent['id']; ?>"
                               style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.75rem;font-weight:700;color:var(--ibc-green);text-decoration:none;white-space:nowrap;flex-shrink:0;">
                                Details <i class="fas fa-arrow-right" style="font-size:0.65rem;" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                            <span style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.3rem;">
                                <i class="fas fa-clock" style="color:#22c55e;font-size:0.65rem;" aria-hidden="true"></i>
                                <?php echo date('d.m.Y H:i', strtotime($hEvent['start_time'])); ?> Uhr
                            </span>
                            <?php if (!empty($hEvent['location'])): ?>
                            <span style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.3rem;min-width:0;">
                                <i class="fas fa-map-marker-alt" style="color:#22c55e;font-size:0.65rem;flex-shrink:0;" aria-hidden="true"></i>
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($hEvent['location']); ?></span>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="d-empty">
                        <div class="d-empty-icon" style="background:rgba(34,197,94,0.1);color:#22c55e;">
                            <i class="fas fa-hands-helping" aria-hidden="true"></i>
                        </div>
                        <p style="font-weight:700;font-size:0.9375rem;color:var(--text-main);margin:0;">Keine Helfer benötigt</p>
                        <p style="font-size:0.8125rem;color:var(--text-muted);margin:0;">Aktuell werden keine Helfer gesucht.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.d-main-col -->

        <!-- ── SIDEBAR COLUMN (right) ──────────────────────────────────── -->
        <div class="d-side-col" style="display:flex;flex-direction:column;gap:1.5rem;">

            <!-- Profile Completeness Card -->
            <?php if (in_array($userRole, $rolesRequiringProfile) && $profileCompletenessPercent < 100): ?>
            <div>
                <div class="d-section-hdr" style="margin-bottom:1rem;">
                    <div class="d-section-hdr-left">
                        <div class="d-section-icon" style="background:linear-gradient(135deg,#a855f7,#ec4899);color:#fff;">
                            <i class="fas fa-user-edit" aria-hidden="true"></i>
                        </div>
                        <div class="d-section-title">Dein Profil</div>
                    </div>
                </div>
                <div class="d-card" style="overflow:hidden;">
                    <div style="height:3px;background:linear-gradient(90deg,#a855f7,#ec4899);"></div>
                    <div style="padding:1.25rem;">
                        <div style="display:flex;align-items:center;gap:1.25rem;">
                            <!-- SVG Ring -->
                            <div class="d-profile-ring-wrap">
                                <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="40" cy="40" r="34" fill="none" stroke="var(--border-color)" stroke-width="6"/>
                                    <circle cx="40" cy="40" r="34" fill="none"
                                            stroke="url(#dProfileGrad)"
                                            stroke-width="6"
                                            stroke-linecap="round"
                                            stroke-dasharray="<?php echo $circumference; ?>"
                                            stroke-dashoffset="<?php echo $dashOffset; ?>"
                                            style="transition:stroke-dashoffset 0.7s ease;"/>
                                    <defs>
                                        <linearGradient id="dProfileGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#a855f7"/>
                                            <stop offset="100%" stop-color="#ec4899"/>
                                        </linearGradient>
                                    </defs>
                                </svg>
                                <div class="d-profile-ring-pct"><?php echo $profileCompletenessPercent; ?>%</div>
                            </div>
                            <!-- Text -->
                            <div style="flex:1;min-width:0;">
                                <p style="font-weight:700;font-size:0.9rem;color:var(--text-main);margin:0 0 0.25rem;">Vervollständige dein Profil</p>
                                <p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 0.875rem;line-height:1.5;">
                                    Name, E-Mail, Telefon, Geschlecht, Geburtstag, Fähigkeiten &amp; Über mich
                                </p>
                                <a href="../auth/profile.php"
                                   style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;background:linear-gradient(135deg,#a855f7,#ec4899);color:#fff;font-weight:700;font-size:0.8rem;border-radius:8px;text-decoration:none;box-shadow:0 3px 10px rgba(168,85,247,0.3);transition:opacity 0.2s;">
                                    <i class="fas fa-user-edit" style="font-size:0.7rem;" aria-hidden="true"></i>Profil bearbeiten
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Active Polls -->
            <div>
                <div class="d-section-hdr" style="margin-bottom:1rem;">
                    <div class="d-section-hdr-left">
                        <div class="d-section-icon" style="background:linear-gradient(135deg,#f97316,#dc2626);color:#fff;">
                            <i class="fas fa-poll" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="d-section-title">Umfragen</div>
                        </div>
                    </div>
                    <a href="../polls/index.php" class="d-section-link" style="color:#f97316;">
                        Alle <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="d-card" style="overflow:hidden;">
                    <?php if (!empty($visiblePolls)):
                        foreach ($visiblePolls as $poll):
                    ?>
                    <div class="d-poll-item">
                        <div style="width:2.25rem;height:2.25rem;border-radius:0.75rem;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(249,115,22,0.1);color:#f97316;font-size:0.9rem;">
                            <i class="fas fa-poll-h" aria-hidden="true"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-weight:700;font-size:0.8125rem;color:var(--text-main);margin:0 0 0.25rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?php echo htmlspecialchars($poll['title']); ?>
                            </p>
                            <p style="font-size:0.7rem;color:var(--text-muted);margin:0;">
                                Endet <?php echo date('d.m.Y', strtotime($poll['end_date'])); ?>
                            </p>
                        </div>
                        <?php if (!empty($poll['microsoft_forms_url'])): ?>
                        <a href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                           target="_blank" rel="noopener noreferrer"
                           style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.3rem;padding:0.375rem 0.75rem;background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;font-weight:700;font-size:0.75rem;border-radius:8px;text-decoration:none;">
                            <i class="fas fa-external-link-alt" style="font-size:0.65rem;" aria-hidden="true"></i>Öffnen
                        </a>
                        <?php else: ?>
                        <a href="../polls/view.php?id=<?php echo $poll['id']; ?>"
                           style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.3rem;padding:0.375rem 0.75rem;background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;font-weight:700;font-size:0.75rem;border-radius:8px;text-decoration:none;">
                            <?php echo $poll['user_has_voted'] > 0 ? '<i class="fas fa-chart-bar" aria-hidden="true"></i>Ergebnis' : '<i class="fas fa-vote-yea" aria-hidden="true"></i>Abstimmen'; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="d-empty" style="padding:1.75rem 1.5rem;">
                        <div class="d-empty-icon" style="background:rgba(249,115,22,0.1);color:#f97316;">
                            <i class="fas fa-poll" aria-hidden="true"></i>
                        </div>
                        <p style="font-weight:600;font-size:0.875rem;color:var(--text-main);margin:0;">Keine aktiven Umfragen</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.d-side-col -->

    </div><!-- /.d-content-grid -->

    <!-- ── BLOG SECTION (full width) ─────────────────────────────────────── -->
    <?php if (!empty($recentBlogPosts)): ?>
    <div class="d-blog-section">
        <div class="d-section-hdr">
            <div class="d-section-hdr-left">
                <div class="d-section-icon" style="background:linear-gradient(135deg,#6366f1,#7c3aed);color:#fff;">
                    <i class="fas fa-newspaper" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="d-section-title">Neuigkeiten</div>
                    <div class="d-section-sub">Aktuelle Beiträge aus dem Blog</div>
                </div>
            </div>
            <a href="../blog/index.php" class="d-section-link" style="color:#6366f1;">
                Alle Artikel <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        <?php
        $blogCategoryColors = [
            'Allgemein'          => ['bg' => 'rgba(107,114,128,0.1)', 'color' => 'var(--text-muted)'],
            'IT'                 => ['bg' => 'rgba(59,130,246,0.1)',  'color' => '#3b82f6'],
            'Marketing'          => ['bg' => 'rgba(168,85,247,0.1)', 'color' => '#a855f7'],
            'Human Resources'    => ['bg' => 'rgba(34,197,94,0.1)',  'color' => '#22c55e'],
            'Qualitätsmanagement'=> ['bg' => 'rgba(234,179,8,0.1)',  'color' => '#eab308'],
            'Akquise'            => ['bg' => 'rgba(239,68,68,0.1)',  'color' => '#ef4444'],
            'Vorstand'           => ['bg' => 'rgba(99,102,241,0.1)', 'color' => '#6366f1'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;">
            <?php foreach ($recentBlogPosts as $post):
                $cat = $post['category'] ?? 'Allgemein';
                $catStyle = $blogCategoryColors[$cat] ?? $blogCategoryColors['Allgemein'];
                $postDate = new DateTime($post['created_at']);
                $excerpt  = strip_tags($post['content'] ?? '');
                $excerpt  = strlen($excerpt) > 110 ? substr($excerpt, 0, 110) . '…' : $excerpt;
                $authorName = explode('@', $post['author_email'])[0];
            ?>
            <a href="../blog/view.php?id=<?php echo (int)$post['id']; ?>" class="d-blog-card">
                <div class="d-blog-img">
                    <?php if (!empty($post['image_path']) && $post['image_path'] !== BlogPost::DEFAULT_IMAGE): ?>
                        <img src="/<?php echo htmlspecialchars(ltrim($post['image_path'], '/')); ?>"
                             alt="<?php echo htmlspecialchars($post['title']); ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#4f46e5,#7c3aed);">
                            <i class="fas fa-newspaper" style="color:rgba(255,255,255,0.25);font-size:2.5rem;" aria-hidden="true"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="padding:1rem;flex:1;display:flex;flex-direction:column;">
                    <div style="margin-bottom:0.5rem;">
                        <span style="padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.7rem;font-weight:700;background:<?php echo $catStyle['bg']; ?>;color:<?php echo $catStyle['color']; ?>;">
                            <?php echo htmlspecialchars($cat); ?>
                        </span>
                    </div>
                    <h3 class="d-line-clamp-2" style="font-weight:800;font-size:0.9375rem;color:var(--text-main);line-height:1.35;margin:0 0 0.35rem;">
                        <?php echo htmlspecialchars($post['title']); ?>
                    </h3>
                    <p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 0.5rem;display:flex;align-items:center;gap:0.3rem;">
                        <i class="fas fa-calendar-alt" style="color:#6366f1;font-size:0.65rem;" aria-hidden="true"></i>
                        <?php echo $postDate->format('d.m.Y'); ?>
                    </p>
                    <p class="d-line-clamp-3" style="font-size:0.8125rem;color:var(--text-muted);line-height:1.55;flex:1;margin:0 0 0.75rem;">
                        <?php echo htmlspecialchars($excerpt); ?>
                    </p>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding-top:0.625rem;border-top:1px solid var(--border-color);font-size:0.75rem;color:var(--text-muted);">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:flex;align-items:center;gap:0.3rem;">
                            <i class="fas fa-user-circle" style="color:#6366f1;font-size:0.65rem;" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($authorName); ?>
                        </span>
                        <span style="font-weight:700;color:#6366f1;display:flex;align-items:center;gap:0.3rem;flex-shrink:0;">
                            Lesen <i class="fas fa-arrow-right" style="font-size:0.65rem;" aria-hidden="true"></i>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.dash-page-wrap -->

<script>
function hidePollFromDashboard(pollId) {
    if (!confirm('Möchten Sie diese Umfrage wirklich ausblenden?')) return;
    fetch('<?php echo asset('api/hide_poll.php'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ poll_id: pollId, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.success) { window.location.reload(); }
        else { alert('Fehler: ' + (data.message || 'Unbekannter Fehler')); }
    })
    .catch(function(error){
        console.error('Error:', error);
        alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.');
    });
}
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
