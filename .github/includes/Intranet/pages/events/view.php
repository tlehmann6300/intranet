<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../src/CalendarService.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Get event ID
$eventId = $_GET['id'] ?? null;
if (!$eventId) {
    header('Location: index.php');
    exit;
}

// Get event details
$event = Event::getById($eventId, true);
if (!$event) {
    header('Location: index.php');
    exit;
}

// Check if user has permission to view this event
$allowedRoles = $event['allowed_roles'] ?? [];
if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
    header('Location: index.php');
    exit;
}

// Get user's signups
$userSignups = Event::getUserSignups($user['id']);
$isRegistered = false;
$userSignupId = null;
$userSlotId = null;
foreach ($userSignups as $signup) {
    if ($signup['event_id'] == $eventId) {
        $isRegistered = true;
        $userSignupId = $signup['id'];
        $userSlotId = $signup['slot_id'];
        break;
    }
}

// Get registration count
$registrationCount = Event::getRegistrationCount($eventId);

// Get participants list (visible to all logged-in users)
$participants = Event::getEventAttendees($eventId);

// Get helper types and slots if needed
$helperTypes = [];
if ($event['needs_helpers'] && $userRole !== 'alumni') {
    $helperTypes = Event::getHelperTypes($eventId);

    // For each helper type, get slots with signup counts
    foreach ($helperTypes as &$helperType) {
        $slots = Event::getSlots($helperType['id']);

        // Add signup counts to each slot
        foreach ($slots as &$slot) {
            $signups = Event::getSignups($eventId);
            $confirmedCount = 0;
            $userInSlot = false;

            foreach ($signups as $signup) {
                if ($signup['slot_id'] == $slot['id'] && $signup['status'] == 'confirmed') {
                    $confirmedCount++;
                    if ($signup['user_id'] == $user['id']) {
                        $userInSlot = true;
                    }
                }
            }

            $slot['signups_count'] = $confirmedCount;
            $slot['user_in_slot'] = $userInSlot;
            $slot['is_full'] = $confirmedCount >= $slot['quantity_needed'];
        }

        $helperType['slots'] = $slots;
    }
}

// Check if event signup has a deadline
$signupDeadline = $event['start_time']; // Default to event start time
$canCancel = strtotime($signupDeadline) > time();

// Check if user has permission to add financial statistics
$canAddStats = in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']));

// Load feedback contact info
$feedbackContact = Event::getFeedbackContact((int)$eventId);
$feedbackContactRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
$canBecomeFeedbackContact = in_array($userRole, $feedbackContactRoles);
$isFeedbackContact = $feedbackContact && (int)($feedbackContact['user_id'] ?? 0) === (int)$user['id'];

$title = htmlspecialchars($event['title']) . ' - Events';

// Open Graph meta tags for link preview
$og_title       = $event['title'];
$og_type        = 'website';
$og_url         = url('pages/events/view.php?id=' . (int)$event['id']);
$og_description = !empty($event['description'])
    ? mb_strimwidth(strip_tags($event['description']), 0, 200, '...')
    : '';
$og_image       = asset($event['image_path'] ?? Event::DEFAULT_IMAGE);

ob_start();
?>

<style>
/* ═══════════════════════════════════════════════════════════════════════════════
   SCOPED EVENT VIEW STYLES
   Prefix: .evv-*
   Design system: CSS variables for dark mode compatibility
═══════════════════════════════════════════════════════════════════════════════ */

/* ── Animation Keyframes ──────────────────────────────────────────────────────── */
@keyframes evvFadeIn {
    from {
        opacity: 0;
        transform: translateY(16px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes evvSlideInLeft {
    from {
        opacity: 0;
        transform: translateX(-12px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes evvScaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* ── CSS Variables (Fallback & Light Mode) ────────────────────────────────────── */
:root {
    --evv-stat-teal: #0891b2;
    --evv-stat-amber: var(--ibc-accent);
}

/* ── Hero Section ──────────────────────────────────────────────────────────────── */
.evv-hero {
    position: relative;
    background: var(--bg-card);
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: var(--shadow-card);
}

.evv-hero-image {
    width: 100%;
    height: 480px;
    position: relative;
    overflow: hidden;
}

@media (max-width: 640px) {
    .evv-hero-image {
        height: 280px;
    }
}

.evv-hero-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.evv-hero-placeholder {
    background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 55%, #001f3a 100%);
}

.evv-hero-placeholder-icon {
    font-size: 7rem;
    color: rgba(255, 255, 255, 0.1);
}

@media (max-width: 640px) {
    .evv-hero-placeholder-icon {
        font-size: 4rem;
    }
}

.evv-hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.4) 50%, rgba(0, 0, 0, 0.1) 100%);
}

.dark-mode .evv-hero-overlay {
    background: linear-gradient(to top, rgba(0, 0, 0, 0.9) 0%, rgba(0, 0, 0, 0.5) 50%, rgba(0, 0, 0, 0.15) 100%);
}

.evv-hero-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 1.5rem 2rem;
    z-index: 2;
}

@media (max-width: 640px) {
    .evv-hero-content {
        padding: 1rem 1.25rem;
    }
}

.evv-hero-title {
    font-size: 2rem;
    font-weight: 700;
    color: white;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
    line-height: 1.2;
    animation: evvFadeIn 0.6s cubic-bezier(0.22, 0.68, 0, 1.2) forwards;
}

@media (min-width: 640px) {
    .evv-hero-title {
        font-size: 2.25rem;
    }
}

@media (min-width: 768px) {
    .evv-hero-title {
        font-size: 2.25rem;
    }
}

/* ── Status & Badge Styles ────────────────────────────────────────────────────── */
.evv-badge-container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    animation: evvFadeIn 0.6s 0.1s cubic-bezier(0.22, 0.68, 0, 1.2) forwards;
}

.evv-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 600;
    border: 1px solid;
    backdrop-filter: blur(8px);
    min-height: 44px;
}

.evv-badge-status {
    border-color: rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.2);
    color: white;
}

.evv-badge-external {
    border-color: rgba(255, 255, 255, 0.3);
    background: rgba(var(--ibc-accent-rgb), 0.8);
    color: white;
}

.evv-badge-registered {
    border-color: rgba(0, 166, 81, 0.5);
    background: rgba(0, 166, 81, 0.7);
    color: white;
}

.evv-badge-icon {
    font-size: 0.75rem;
}

/* ── Quick Stats Row ───────────────────────────────────────────────────────────── */
.evv-quickstats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.5rem;
    animation: evvFadeIn 0.6s 0.2s cubic-bezier(0.22, 0.68, 0, 1.2) forwards;
}

@media (max-width: 640px) {
    .evv-quickstats {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 0.5rem;
    }
}

.evv-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: var(--shadow-soft);
    border-left-width: 4px;
    min-height: 44px;
}

.dark-mode .evv-stat-card {
    background: var(--bg-body);
    border-color: var(--border-color);
}

.evv-stat-card--blue {
    border-left-color: var(--ibc-blue);
}

.evv-stat-card--purple {
    border-left-color: var(--evv-stat-teal);
}

.evv-stat-card--green {
    border-left-color: var(--ibc-green);
}

.evv-stat-card--orange {
    border-left-color: var(--evv-stat-amber);
}

.evv-stat-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.evv-stat-card--blue .evv-stat-icon {
    background: rgba(0, 102, 179, 0.12);
    color: var(--ibc-blue);
}

.dark-mode .evv-stat-card--blue .evv-stat-icon {
    background: rgba(0, 102, 179, 0.2);
    color: var(--ibc-blue);
}

.evv-stat-card--purple .evv-stat-icon {
    background: rgba(8, 145, 178, 0.12);
    color: var(--evv-stat-teal);
}

.dark-mode .evv-stat-card--purple .evv-stat-icon {
    background: rgba(8, 145, 178, 0.2);
    color: var(--evv-stat-teal);
}

.evv-stat-card--green .evv-stat-icon {
    background: rgba(0, 166, 81, 0.12);
    color: var(--ibc-green);
}

.dark-mode .evv-stat-card--green .evv-stat-icon {
    background: rgba(0, 166, 81, 0.2);
    color: var(--ibc-green);
}

.evv-stat-card--orange .evv-stat-icon {
    background: rgba(255, 107, 53, 0.12);
    color: var(--evv-stat-amber);
}

.dark-mode .evv-stat-card--orange .evv-stat-icon {
    background: rgba(255, 107, 53, 0.2);
    color: var(--evv-stat-amber);
}

.evv-stat-label {
    font-size: 0.875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
}

.dark-mode .evv-stat-label {
    color: var(--text-muted);
}

.evv-stat-value {
    font-weight: 700;
    color: var(--text-main);
    font-size: 0.95rem;
    line-height: 1.3;
}

.dark-mode .evv-stat-value {
    color: var(--text-main);
}

.evv-stat-sub {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.1rem;
}

.dark-mode .evv-stat-sub {
    color: var(--text-muted);
}

/* ── Description Card ──────────────────────────────────────────────────────────── */
.evv-description-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-soft);
}

.dark-mode .evv-description-card {
    background: var(--bg-body);
    border-color: var(--border-color);
}

.evv-card-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.dark-mode .evv-card-title {
    color: var(--text-main);
}

.evv-card-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.evv-card-icon--blue {
    background: rgba(0, 102, 179, 0.1);
    color: var(--ibc-blue);
}

.dark-mode .evv-card-icon--blue {
    background: rgba(0, 102, 179, 0.15);
}

.evv-card-icon--green {
    background: rgba(0, 166, 81, 0.1);
    color: var(--ibc-green);
}

.dark-mode .evv-card-icon--green {
    background: rgba(0, 166, 81, 0.15);
}

.evv-card-icon--purple {
    background: rgba(147, 51, 234, 0.1);
    color: #9333ea;
}

.dark-mode .evv-card-icon--purple {
    background: rgba(147, 51, 234, 0.15);
}

.evv-description-text {
    color: var(--text-main);
    font-size: 1rem;
    line-height: 1.6;
    white-space: pre-line;
    word-break: break-word;
    word-wrap: break-word;
}

.dark-mode .evv-description-text {
    color: var(--text-main);
}

/* ── Participants Card ────────────────────────────────────────────────────────── */
.evv-participants-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-soft);
}

.dark-mode .evv-participants-card {
    background: var(--bg-body);
    border-color: var(--border-color);
}

.evv-participant-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.625rem;
    border-radius: 9999px;
    background: var(--ibc-blue);
    color: white;
    font-size: 0.875rem;
    font-weight: 700;
    margin-left: auto;
}

.evv-participants-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.evv-participant-item {
    padding: 0.625rem 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-color);
    min-height: 44px;
}

.dark-mode .evv-participant-item {
    color: var(--text-main);
    border-color: var(--border-color);
}

.evv-participant-item:last-child {
    border-bottom: none;
}

.evv-participant-avatar {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 9999px;
    background: rgba(0, 102, 179, 0.1);
    color: var(--ibc-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.75rem;
}

.dark-mode .evv-participant-avatar {
    background: rgba(0, 102, 179, 0.15);
}

.evv-empty-state {
    color: var(--text-muted);
    font-size: 0.875rem;
}

.dark-mode .evv-empty-state {
    color: var(--text-muted);
}

/* ── Sidebar Info Cards ────────────────────────────────────────────────────────── */
.evv-sidebar-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.25rem;
    box-shadow: var(--shadow-soft);
}

.dark-mode .evv-sidebar-card {
    background: var(--bg-body);
    border-color: var(--border-color);
}

.evv-sidebar-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
    display: block;
}

.dark-mode .evv-sidebar-label {
    color: var(--text-muted);
}

.evv-sidebar-info-row {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.evv-sidebar-info-row:last-child {
    margin-bottom: 0;
}

.evv-sidebar-info-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.75rem;
    background: rgba(0, 102, 179, 0.1);
    color: var(--ibc-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dark-mode .evv-sidebar-info-icon {
    background: rgba(0, 102, 179, 0.15);
}

.evv-sidebar-info-text {
    flex: 1;
    min-width: 0;
}

.evv-sidebar-info-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
}

.dark-mode .evv-sidebar-info-label {
    color: var(--text-muted);
}

.evv-sidebar-info-value {
    font-weight: 600;
    color: var(--text-main);
    font-size: 0.95rem;
    line-height: 1.4;
}

.dark-mode .evv-sidebar-info-value {
    color: var(--text-main);
}

.evv-sidebar-info-sub {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.dark-mode .evv-sidebar-info-sub {
    color: var(--text-muted);
}

/* ── CTA Card (Registration) ───────────────────────────────────────────────────── */
.evv-cta-card {
    background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 100%);
    border: none;
    border-radius: 1rem;
    padding: 1.25rem;
    box-shadow: 0 8px 24px rgba(0, 102, 179, 0.35);
    color: rgba(255, 255, 255, 0.75);
}

.evv-cta-card .evv-sidebar-label,
.evv-cta-card .evv-card-title {
    color: rgba(255, 255, 255, 0.8) !important;
}

.evv-cta-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 44px;
    padding: 0.75rem 1.25rem;
    border-radius: 0.75rem;
    font-weight: 600;
    font-size: 0.95rem;
    width: 100%;
    border: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.evv-cta-button-primary {
    background: var(--ibc-green);
    color: white;
}

.evv-cta-button-primary:hover {
    box-shadow: 0 8px 16px rgba(0, 166, 81, 0.3);
    transform: translateY(-2px);
}

.evv-cta-button-secondary {
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: white;
}

.evv-cta-button-secondary:hover {
    background: rgba(255, 255, 255, 0.25);
}

.evv-cta-button-danger {
    background: #dc2626;
    color: white;
}

.evv-cta-button-danger:hover {
    background: #b91c1c;
}

.evv-cta-button-success {
    background: var(--ibc-green);
    color: white;
}

.evv-cta-button-success:hover {
    box-shadow: 0 8px 16px rgba(0, 166, 81, 0.3);
}

.evv-cta-button-disabled {
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.5);
    cursor: not-allowed;
}

.evv-cta-divider {
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    margin: 0.75rem 0;
    padding-top: 0.75rem;
}

.evv-cta-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgba(255, 255, 255, 0.6);
    font-weight: 600;
    margin-bottom: 0.5rem;
    display: block;
}

.evv-cta-button-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* ── Helper Slots Section ──────────────────────────────────────────────────────── */
.evv-helpers-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-soft);
    margin-top: 1.5rem;
}

.dark-mode .evv-helpers-card {
    background: var(--bg-body);
    border-color: var(--border-color);
}

.evv-helper-type {
    margin-bottom: 1.5rem;
}

.evv-helper-type:last-child {
    margin-bottom: 0;
}

.evv-helper-type-title {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-main);
    margin-bottom: 0.25rem;
    word-wrap: break-word;
}

.dark-mode .evv-helper-type-title {
    color: var(--text-main);
}

.evv-helper-type-desc {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
}

.dark-mode .evv-helper-type-desc {
    color: var(--text-muted);
}

.evv-slot-item {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 0.75rem;
    border: 1px solid var(--border-color);
    margin-bottom: 0.5rem;
    min-height: 44px;
}

.evv-slot-item:last-child {
    margin-bottom: 0;
}

.dark-mode .evv-slot-item {
    border-color: var(--border-color);
}

.evv-slot-item--user-in {
    background: rgba(0, 166, 81, 0.05);
    border-color: rgba(0, 166, 81, 0.4);
}

.dark-mode .evv-slot-item--user-in {
    background: rgba(0, 166, 81, 0.08);
}

.evv-slot-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.evv-slot-time {
    font-weight: 600;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.dark-mode .evv-slot-time {
    color: var(--text-main);
}

.evv-slot-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.evv-slot-badge--aufbau {
    background: rgba(0, 102, 179, 0.1);
    color: var(--ibc-blue);
    border: 1px solid rgba(0, 102, 179, 0.2);
}

.dark-mode .evv-slot-badge--aufbau {
    background: rgba(0, 102, 179, 0.15);
}

.evv-slot-badge--abbau {
    background: rgba(var(--ibc-accent-rgb), 0.1);
    color: var(--ibc-accent);
    border: 1px solid rgba(var(--ibc-accent-rgb), 0.2);
}

.dark-mode .evv-slot-badge--abbau {
    background: rgba(var(--ibc-accent-rgb), 0.15);
}

.evv-slot-capacity {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.evv-slot-capacity-bar {
    flex: 1;
    height: 0.375rem;
    border-radius: 9999px;
    background: var(--border-color);
    overflow: hidden;
}

.dark-mode .evv-slot-capacity-bar {
    background: var(--border-color);
}

.evv-slot-capacity-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.3s ease;
}

.evv-slot-capacity-fill--active {
    background: var(--ibc-green);
}

.evv-slot-capacity-fill--full {
    background: #ef4444;
}

.evv-slot-capacity-text {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
}

.dark-mode .evv-slot-capacity-text {
    color: var(--text-muted);
}

.evv-slot-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.evv-slot-button {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    min-height: 44px;
    transition: all 0.3s ease;
}

.evv-slot-button--primary {
    background: var(--ibc-green);
    color: white;
}

.evv-slot-button--primary:hover {
    box-shadow: 0 4px 12px rgba(0, 166, 81, 0.3);
    transform: translateY(-1px);
}

.evv-slot-button--danger {
    background: #fee2e2;
    color: #dc2626;
}

.dark-mode .evv-slot-button--danger {
    background: #7f1d1d;
    color: #fca5a5;
}

.evv-slot-button--danger:hover {
    background: #fecaca;
}

.dark-mode .evv-slot-button--danger:hover {
    background: #991b1b;
}

.evv-slot-button--waitlist {
    background: #fbbf24;
    color: white;
}

.evv-slot-button--waitlist:hover {
    background: #f59e0b;
}

.evv-slot-status {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    background: rgba(0, 166, 81, 0.1);
    color: var(--ibc-green);
    border: 1px solid rgba(0, 166, 81, 0.2);
    min-height: 44px;
}

.dark-mode .evv-slot-status {
    background: rgba(0, 166, 81, 0.15);
}

.evv-slot-status--full {
    background: rgba(107, 114, 128, 0.1);
    color: var(--text-muted);
    border-color: var(--border-color);
}

.dark-mode .evv-slot-status--full {
    background: rgba(107, 114, 128, 0.15);
}

/* ── Feedback Contact Section ──────────────────────────────────────────────────── */
.evv-feedback-card {
    background: linear-gradient(to right, rgba(147, 51, 234, 0.05), rgba(99, 102, 241, 0.05));
    border: 1px solid rgba(147, 51, 234, 0.15);
    border-radius: 1rem;
    padding: 1.5rem;
    box-shadow: var(--shadow-soft);
    margin-top: 1.5rem;
}

.dark-mode .evv-feedback-card {
    background: linear-gradient(to right, rgba(147, 51, 234, 0.1), rgba(99, 102, 241, 0.1));
    border-color: rgba(147, 51, 234, 0.25);
}

.evv-feedback-content {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.evv-feedback-avatar {
    width: 4rem;
    height: 4rem;
    border-radius: 9999px;
    object-fit: cover;
    border: 2px solid rgba(147, 51, 234, 0.3);
    flex-shrink: 0;
}

.evv-feedback-avatar--placeholder {
    width: 4rem;
    height: 4rem;
    border-radius: 9999px;
    background: rgba(147, 51, 234, 0.1);
    border: 2px solid rgba(147, 51, 234, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #9333ea;
    font-size: 1.25rem;
}

.dark-mode .evv-feedback-avatar--placeholder {
    background: rgba(147, 51, 234, 0.15);
}

.evv-feedback-info {
    flex: 1;
}

.evv-feedback-name {
    font-weight: 700;
    font-size: 1rem;
    color: var(--text-main);
    margin-bottom: 0.25rem;
}

.dark-mode .evv-feedback-name {
    color: var(--text-main);
}

.evv-feedback-role {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.125rem;
}

.dark-mode .evv-feedback-role {
    color: var(--text-muted);
}

.evv-feedback-badge {
    font-size: 0.75rem;
    color: #9333ea;
    margin-top: 0.25rem;
    font-weight: 600;
}

.evv-feedback-button {
    padding: 0.5rem 1rem;
    background: #fee2e2;
    color: #dc2626;
    border-radius: 0.5rem;
    border: none;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    margin-left: auto;
    flex-shrink: 0;
    min-height: 44px;
    transition: all 0.3s ease;
}

.dark-mode .evv-feedback-button {
    background: #7f1d1d;
    color: #fca5a5;
}

.evv-feedback-button:hover {
    background: #fecaca;
}

.dark-mode .evv-feedback-button:hover {
    background: #991b1b;
}

/* ── Stats Modal ──────────────────────────────────────────────────────────────── */
.evv-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    backdrop-filter: blur(4px);
}

.evv-modal {
    background: var(--bg-card);
    border-radius: 0.5rem;
    width: 100%;
    max-width: 32rem;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
}

.dark-mode .evv-modal {
    background: var(--bg-body);
}

.evv-modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    overflow-y: auto;
    flex: 1;
}

.dark-mode .evv-modal-header {
    border-color: var(--border-color);
}

.evv-modal-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
}

.dark-mode .evv-modal-title {
    color: var(--text-main);
}

.evv-modal-footer {
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    gap: 1rem;
}

.dark-mode .evv-modal-footer {
    border-color: var(--border-color);
}

.evv-form-group {
    margin-bottom: 1rem;
}

.evv-form-group:last-child {
    margin-bottom: 0;
}

.evv-form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    margin-bottom: 0.25rem;
}

.dark-mode .evv-form-label {
    color: var(--text-main);
}

.evv-form-input {
    width: 100%;
    padding: 0.5rem 1rem;
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    border-radius: 0.5rem;
    font-size: 0.95rem;
    min-height: 44px;
    transition: all 0.3s ease;
}

.dark-mode .evv-form-input {
    background: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-main);
}

.evv-form-input:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.1);
}

.dark-mode .evv-form-input:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.15);
}

.evv-error-message {
    padding: 0.75rem;
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #dc2626;
    border-radius: 0.5rem;
    font-size: 0.875rem;
}

.dark-mode .evv-error-message {
    background: rgba(220, 38, 38, 0.15);
    border-color: rgba(220, 38, 38, 0.25);
    color: #fca5a5;
}

/* ── Message Container ────────────────────────────────────────────────────────── */
.evv-message-container {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 50;
    display: none;
    animation: evvSlideInLeft 0.3s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.evv-message-content {
    padding: 1rem 1.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    min-height: 44px;
}

.evv-message-success {
    background: rgba(0, 166, 81, 0.1);
    color: var(--ibc-green);
    border: 1px solid rgba(0, 166, 81, 0.25);
}

.dark-mode .evv-message-success {
    background: rgba(0, 166, 81, 0.15);
}

.evv-message-error {
    background: rgba(220, 38, 38, 0.1);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.25);
}

.dark-mode .evv-message-error {
    background: rgba(220, 38, 38, 0.15);
    color: #fca5a5;
}

/* ── Responsive Adjustments ────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    .evv-cta-card {
        padding: 1rem;
    }

    .evv-sidebar-card {
        padding: 1rem;
    }

    .evv-description-card,
    .evv-participants-card {
        padding: 1rem;
    }

    .evv-slot-item {
        padding: 0.75rem;
    }

    .evv-slot-actions {
        flex-direction: column;
        width: 100%;
    }

    .evv-slot-button {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 900px) {
    .evv-quickstats {
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    }
}

/* ── Accessibility ────────────────────────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .evv-hero-title,
    .evv-badge-container,
    .evv-quickstats,
    .evv-slot-capacity-fill,
    .evv-cta-button,
    .evv-message-container {
        animation: none !important;
    }
}

/* ── Focus Styles for Keyboard Navigation ──────────────────────────────────────── */
.evv-cta-button:focus,
.evv-slot-button:focus,
.evv-form-input:focus {
    outline: 2px solid var(--ibc-blue);
    outline-offset: 2px;
}

/* ── Print Styles ──────────────────────────────────────────────────────────────── */
@media print {
    .evv-modal-overlay,
    .evv-message-container {
        display: none !important;
    }
}
</style>

<?php
// Validate image existence once for reuse
$imagePath = $event['image_path'] ?? '';
$imageExists = false;
if (!empty($imagePath)) {
    $fullImagePath = __DIR__ . '/../../' . $imagePath;
    $realPath = realpath($fullImagePath);
    $baseDir = realpath(__DIR__ . '/../../');
    $imageExists = $realPath && $baseDir && strpos($realPath, $baseDir) === 0 && file_exists($realPath);
}

// Precompute timestamps for reuse
$startTimestamp = strtotime($event['start_time']);
$endTimestamp   = strtotime($event['end_time']);

// Status badge config
$statusLabels = [
    'planned' => ['label' => 'Geplant',                'icon' => 'fa-clock',          'color' => 'evv-badge-status'],
    'open'    => ['label' => 'Anmeldung offen',         'icon' => 'fa-door-open',      'color' => 'evv-badge-status'],
    'closed'  => ['label' => 'Anmeldung geschlossen',   'icon' => 'fa-door-closed',    'color' => 'evv-badge-status'],
    'running' => ['label' => 'Läuft gerade',            'icon' => 'fa-play-circle',    'color' => 'evv-badge-status'],
    'past'    => ['label' => 'Beendet',                 'icon' => 'fa-flag-checkered', 'color' => 'evv-badge-status'],
];
$currentStatus = $event['status'] ?? 'planned';
$statusInfo = $statusLabels[$currentStatus] ?? ['label' => $currentStatus, 'icon' => 'fa-circle', 'color' => 'evv-badge-status'];
?>

<div class="max-w-5xl mx-auto">

    <!-- Back Button + Edit Button -->
    <div class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <a href="index.php" class="inline-flex items-center text-ibc-blue hover:text-ibc-blue-dark ease-premium font-medium">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zur Übersicht
        </a>
        <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand'])): ?>
        <a href="edit.php?id=<?php echo (int)$eventId; ?>" class="inline-flex items-center px-4 py-2 min-h-[44px] bg-ibc-blue text-white rounded-xl font-semibold text-sm hover:bg-ibc-blue-dark ease-premium shadow-soft">
            <i class="fas fa-edit mr-2"></i>
            Event bearbeiten
        </a>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════
         HERO SECTION  (image + title overlay)
    ════════════════════════════════════════════════ -->
    <div class="evv-hero rounded-2xl overflow-hidden shadow-premium mb-6">
        <!-- Image / Fallback gradient -->
        <div class="evv-hero-image">
            <?php if ($imageExists): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $imagePath); ?>"
                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                     class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full evv-hero-placeholder flex items-center justify-center">
                    <i class="fas fa-calendar-alt evv-hero-placeholder-icon"></i>
                </div>
            <?php endif; ?>
            <!-- Dark gradient overlay for legibility -->
            <div class="evv-hero-overlay"></div>
        </div>

        <!-- Title + badges on top of image -->
        <div class="evv-hero-content">
            <div class="evv-badge-container">
                <span class="evv-badge <?php echo $statusInfo['color']; ?>">
                    <i class="fas <?php echo $statusInfo['icon']; ?> evv-badge-icon"></i>
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($event['is_external']): ?>
                    <span class="evv-badge evv-badge-external">
                        <i class="fas fa-external-link-alt evv-badge-icon"></i>Extern
                    </span>
                <?php endif; ?>
                <?php if ($isRegistered): ?>
                    <span class="evv-badge evv-badge-registered">
                        <i class="fas fa-check-circle evv-badge-icon"></i>Angemeldet
                    </span>
                <?php endif; ?>
            </div>

            <h1 id="eventHeroTitle" class="evv-hero-title">
                <?php echo htmlspecialchars($event['title']); ?>
            </h1>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         QUICK STATS ROW  (date, location, participants)
    ════════════════════════════════════════════════ -->
    <div class="evv-quickstats">
        <!-- Start -->
        <div class="evv-stat-card evv-stat-card--blue">
            <span class="evv-stat-icon">
                <i class="fas fa-calendar-day"></i>
            </span>
            <div class="min-w-0">
                <div class="evv-stat-label">Beginn</div>
                <div class="evv-stat-value"><?php echo date('d.m.Y', $startTimestamp); ?></div>
                <div class="evv-stat-sub"><?php echo date('H:i', $startTimestamp); ?> Uhr</div>
            </div>
        </div>
        <!-- End -->
        <div class="evv-stat-card evv-stat-card--purple">
            <span class="evv-stat-icon">
                <i class="fas fa-clock"></i>
            </span>
            <div class="min-w-0">
                <div class="evv-stat-label">Ende</div>
                <div class="evv-stat-value"><?php echo date('d.m.Y', $endTimestamp); ?></div>
                <div class="evv-stat-sub"><?php echo date('H:i', $endTimestamp); ?> Uhr</div>
            </div>
        </div>
        <?php if (!empty($event['location'])): ?>
        <!-- Location -->
        <div class="evv-stat-card evv-stat-card--green">
            <span class="evv-stat-icon">
                <i class="fas fa-map-marker-alt"></i>
            </span>
            <div class="min-w-0">
                <div class="evv-stat-label">Ort</div>
                <div class="evv-stat-value truncate"><?php echo htmlspecialchars($event['location']); ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!$event['is_external']): ?>
        <!-- Participants -->
        <div class="evv-stat-card evv-stat-card--orange">
            <span class="evv-stat-icon">
                <i class="fas fa-users"></i>
            </span>
            <div class="min-w-0">
                <div class="evv-stat-label">Teilnehmer</div>
                <div class="evv-stat-value"><?php echo $registrationCount; ?></div>
                <?php if ($isRegistered): ?>
                <div class="evv-stat-sub" style="color:var(--ibc-green);"><i class="fas fa-check-circle mr-1"></i>Angemeldet</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════
         MAIN CONTENT  (two-column on md+)
    ════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6 mb-6">

        <!-- LEFT: Description + Participants -->
        <div class="lg:col-span-2 space-y-6">

            <?php if (!empty($event['description'])): ?>
            <!-- Description Card -->
            <div class="evv-description-card">
                <h2 class="evv-card-title">
                    <span class="evv-card-icon evv-card-icon--blue">
                        <i class="fas fa-align-left text-sm"></i>
                    </span>
                    Beschreibung
                </h2>
                <p class="evv-description-text"><?php echo htmlspecialchars($event['description']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!$event['is_external']): ?>
            <!-- Participants Card -->
            <div class="evv-participants-card">
                <div class="flex items-center gap-2 mb-4">
                    <span class="evv-card-icon evv-card-icon--green">
                        <i class="fas fa-users text-sm"></i>
                    </span>
                    <h2 class="evv-card-title mb-0">Teilnehmer</h2>
                    <span class="evv-participant-count">
                        <?php echo $registrationCount; ?>
                    </span>
                </div>
                <?php if (!empty($participants)): ?>
                    <ul class="evv-participants-list">
                        <?php foreach ($participants as $participant): ?>
                            <li class="evv-participant-item">
                                <span class="evv-participant-avatar">
                                    <i class="fas fa-user"></i>
                                </span>
                                <?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="evv-empty-state">Noch keine Anmeldungen.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Info Sidebar -->
        <div class="space-y-4 event-sidebar">

            <!-- Date & Time Card -->
            <div class="evv-sidebar-card">
                <span class="evv-sidebar-label">Datum & Uhrzeit</span>
                <div class="space-y-3">
                    <div class="evv-sidebar-info-row">
                        <span class="evv-sidebar-info-icon">
                            <i class="fas fa-calendar-day"></i>
                        </span>
                        <div class="evv-sidebar-info-text">
                            <div class="evv-sidebar-info-label">Beginn</div>
                            <div class="evv-sidebar-info-value"><?php echo date('d.m.Y', strtotime($event['start_time'])); ?></div>
                            <div class="evv-sidebar-info-sub"><?php echo date('H:i', strtotime($event['start_time'])); ?> Uhr</div>
                        </div>
                    </div>
                    <div class="evv-sidebar-info-row">
                        <span class="evv-sidebar-info-icon">
                            <i class="fas fa-clock"></i>
                        </span>
                        <div class="evv-sidebar-info-text">
                            <div class="evv-sidebar-info-label">Ende</div>
                            <div class="evv-sidebar-info-value"><?php echo date('d.m.Y', strtotime($event['end_time'])); ?></div>
                            <div class="evv-sidebar-info-sub"><?php echo date('H:i', strtotime($event['end_time'])); ?> Uhr</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($event['location'])): ?>
            <!-- Location Card -->
            <div class="evv-sidebar-card">
                <span class="evv-sidebar-label">Veranstaltungsort</span>
                <div class="evv-sidebar-info-row">
                    <span class="evv-sidebar-info-icon" style="background: rgba(0, 166, 81, 0.1); color: var(--ibc-green);">
                        <i class="fas fa-map-marker-alt"></i>
                    </span>
                    <div class="evv-sidebar-info-text">
                        <div class="evv-sidebar-info-value break-words"><?php echo htmlspecialchars($event['location']); ?></div>
                        <?php if (!empty($event['maps_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['maps_link']); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center mt-2 px-3 py-1.5 bg-ibc-green text-white rounded-lg font-semibold text-xs hover:shadow-glow-green ease-premium">
                                <i class="fas fa-route mr-1.5"></i>Route planen
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($event['contact_person'])): ?>
            <!-- Contact Card -->
            <div class="evv-sidebar-card">
                <span class="evv-sidebar-label">Ansprechpartner</span>
                <div class="evv-sidebar-info-row">
                    <span class="evv-sidebar-info-icon">
                        <i class="fas fa-user"></i>
                    </span>
                    <div class="evv-sidebar-info-text">
                        <div class="evv-sidebar-info-value"><?php echo htmlspecialchars($event['contact_person']); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Registration / CTA Card -->
            <div class="evv-cta-card">
                <span class="evv-cta-label">
                    <i class="fas fa-ticket-alt mr-1.5"></i>Anmeldung
                </span>
                <div class="flex flex-col gap-3">
                    <?php if (!empty($event['registration_link'])): ?>
                        <a href="<?php echo htmlspecialchars($event['registration_link']); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="evv-cta-button evv-cta-button-primary">
                            <i class="fas fa-external-link-alt"></i>
                            Jetzt anmelden
                        </a>
                    <?php elseif ($event['is_external']): ?>
                        <?php if (!empty($event['external_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['external_link']); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="evv-cta-button evv-cta-button-secondary">
                                <i class="fas fa-external-link-alt"></i>
                                Zur Anmeldung (extern)
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$isRegistered && !$userSlotId): ?>
                            <button onclick="signupForEvent(<?php echo intval($eventId); ?>)"
                                    class="evv-cta-button evv-cta-button-primary">
                                <i class="fas fa-user-plus"></i>
                                Jetzt anmelden
                            </button>
                        <?php elseif ($canCancel && $userSignupId && !$userSlotId): ?>
                            <button onclick="cancelSignup(<?php echo $userSignupId; ?>)"
                                    class="evv-cta-button evv-cta-button-danger">
                                <i class="fas fa-user-times"></i>
                                Abmelden
                            </button>
                        <?php elseif ($isRegistered): ?>
                            <div class="flex items-center justify-center gap-2 py-3 rounded-xl bg-ibc-green/10 text-ibc-green font-semibold border border-ibc-green/20 min-h-[44px]">
                                <i class="fas fa-check-circle"></i>
                                Du bist angemeldet
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Calendar Export -->
                    <div class="evv-cta-divider">
                        <p class="evv-cta-label mb-0">In Kalender eintragen</p>
                        <div class="flex gap-4 mt-2">
                            <a href="<?php echo htmlspecialchars(CalendarService::getGoogleLink($event)); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="flex-1 evv-cta-button evv-cta-button-secondary">
                                <i class="fab fa-google"></i>Google
                            </a>
                            <a href="../../api/download_ics.php?event_id=<?php echo htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8'); ?>"
                               class="flex-1 evv-cta-button evv-cta-button-secondary">
                                <i class="fas fa-download"></i>iCal
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canAddStats && in_array($currentStatus, ['closed', 'past'])): ?>
            <!-- Add Financial Stats Card -->
            <div class="evv-sidebar-card">
                <span class="evv-sidebar-label">Verwaltung</span>
                <button onclick="openAddStatsModal()"
                        class="w-full evv-cta-button evv-cta-button-primary" style="background: var(--ibc-blue); color: white;">
                    <i class="fas fa-chart-bar"></i>
                    Statistiken nachtragen
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /sidebar -->
    </div><!-- /grid -->

    <!-- Helper Slots Section (Only for non-alumni and if event needs helpers) -->
    <?php if ($event['needs_helpers'] && $userRole !== 'alumni' && !empty($helperTypes)): ?>
        <div class="evv-helpers-card">
            <h2 class="evv-card-title mb-4">
                <span class="evv-card-icon evv-card-icon--green">
                    <i class="fas fa-hands-helping text-sm"></i>
                </span>
                Helfer-Bereich
            </h2>
            <p class="text-sm mb-5 ml-11" style="color: var(--text-muted);">Unterstütze uns als Helfer! Wähle einen freien Slot aus.</p>

            <?php foreach ($helperTypes as $helperType): ?>
                <div class="evv-helper-type">
                    <h3 class="evv-helper-type-title">
                        <?php echo htmlspecialchars($helperType['title']); ?>
                    </h3>

                    <?php if (!empty($helperType['description'])): ?>
                        <p class="evv-helper-type-desc"><?php echo htmlspecialchars($helperType['description']); ?></p>
                    <?php endif; ?>

                    <!-- Slots -->
                    <div class="space-y-2">
                        <?php foreach ($helperType['slots'] as $slot): ?>
                            <?php
                                $slotStart = new DateTime($slot['start_time']);
                                $slotEnd = new DateTime($slot['end_time']);
                                $occupancy = $slot['signups_count'] . '/' . $slot['quantity_needed'];
                                $canSignup = !$slot['is_full'] && !$slot['user_in_slot'];
                                $onWaitlist = $slot['is_full'] && !$slot['user_in_slot'];
                                $fillPct = $slot['quantity_needed'] > 0
                                    ? min(100, round($slot['signups_count'] / $slot['quantity_needed'] * 100))
                                    : 0;

                                // Prepare slot parameters for onclick handlers
                                $slotStartFormatted = htmlspecialchars($slotStart->format('Y-m-d H:i:s'), ENT_QUOTES);
                                $slotEndFormatted = htmlspecialchars($slotEnd->format('Y-m-d H:i:s'), ENT_QUOTES);
                                $slotSignupHandler = "signupForSlot({$eventId}, {$slot['id']}, '{$slotStartFormatted}', '{$slotEndFormatted}')";

                                // Determine if this slot is before event start (Aufbau) or after event end (Abbau)
                                $isAufbau = $slotStart->format('Y-m-d') < date('Y-m-d', $startTimestamp);
                                $isAbbau  = $slotEnd->format('Y-m-d')   > date('Y-m-d', $endTimestamp);
                                $showDate = $slotStart->format('Y-m-d') !== date('Y-m-d', $startTimestamp)
                                         || $slotEnd->format('Y-m-d')   !== date('Y-m-d', $startTimestamp);

                                // Format time display (show date if slot is on a different day)
                                if ($showDate) {
                                    $slotTimeDisplay = $slotStart->format('d.m. H:i') . ' – ' . $slotEnd->format('d.m. H:i') . ' Uhr';
                                } else {
                                    $slotTimeDisplay = $slotStart->format('H:i') . ' – ' . $slotEnd->format('H:i') . ' Uhr';
                                }
                            ?>

                            <div class="evv-slot-item <?php echo $slot['user_in_slot'] ? 'evv-slot-item--user-in' : ''; ?>">
                                <div class="evv-slot-header">
                                    <div class="evv-slot-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo htmlspecialchars($slotTimeDisplay); ?>
                                        <?php if ($isAufbau): ?>
                                            <span class="evv-slot-badge evv-slot-badge--aufbau">
                                                <i class="fas fa-tools"></i>Aufbau
                                            </span>
                                        <?php elseif ($isAbbau): ?>
                                            <span class="evv-slot-badge evv-slot-badge--abbau">
                                                <i class="fas fa-box"></i>Abbau
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Capacity bar -->
                                <div class="evv-slot-capacity">
                                    <div class="evv-slot-capacity-bar">
                                        <div class="evv-slot-capacity-fill <?php echo $slot['is_full'] ? 'evv-slot-capacity-fill--full' : 'evv-slot-capacity-fill--active'; ?>" style="width:<?php echo $fillPct; ?>%"></div>
                                    </div>
                                    <span class="evv-slot-capacity-text"><?php echo $occupancy; ?> belegt</span>
                                </div>

                                <div class="evv-slot-actions">
                                    <?php if ($slot['user_in_slot']): ?>
                                        <div class="flex items-center gap-2 flex-1">
                                            <span class="evv-slot-status">
                                                <i class="fas fa-check"></i>Eingetragen
                                            </span>
                                            <?php if ($canCancel): ?>
                                                <button onclick="cancelHelperSlot(<?php echo $userSignupId; ?>)"
                                                        class="evv-slot-button evv-slot-button--danger">
                                                    <i class="fas fa-times"></i>Austragen
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($canSignup): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>"
                                                class="evv-slot-button evv-slot-button--primary flex-1">
                                            <i class="fas fa-user-plus"></i>Als Helfer eintragen
                                        </button>
                                    <?php elseif ($onWaitlist): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>"
                                                class="evv-slot-button evv-slot-button--waitlist flex-1">
                                            <i class="fas fa-list"></i>Warteliste
                                        </button>
                                    <?php else: ?>
                                        <span class="evv-slot-status evv-slot-status--full flex-1">
                                            Belegt
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Feedback Ansprechpartner Section -->
    <?php if ($feedbackContact): ?>
    <div class="mt-6">
        <div class="evv-feedback-card">
            <div class="evv-feedback-content">
                <?php if (!empty($feedbackContact['image_path'])): ?>
                <img src="/<?php echo htmlspecialchars($feedbackContact['image_path']); ?>"
                     alt="<?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>"
                     class="evv-feedback-avatar">
                <?php else: ?>
                <div class="evv-feedback-avatar--placeholder">
                    <i class="fas fa-user"></i>
                </div>
                <?php endif; ?>
                <div>
                    <div class="evv-feedback-name">
                        <?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>
                    </div>
                    <?php if (!empty($feedbackContact['position']) || !empty($feedbackContact['company'])): ?>
                    <div class="evv-feedback-role">
                        <?php
                        $parts = array_filter([$feedbackContact['position'] ?? '', $feedbackContact['company'] ?? '']);
                        echo htmlspecialchars(implode(' · ', $parts));
                        ?>
                    </div></
                    <?php endif; ?>
                    <div class="evv-feedback-badge">
                        <i class="fas fa-star mr-1"></i>Stellt sich für Feedback zur Verfügung
                    </div>
                </div>
                <?php if ($isFeedbackContact): ?>
                <button id="removeFeedbackContactBtn"
                        class="evv-feedback-button">
                    <i class="fas fa-times mr-1"></i>Zurückziehen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($canBecomeFeedbackContact): ?>
    <div class="mt-6">
        <button id="becomeFeedbackContactBtn"
                class="inline-flex items-center px-5 py-3 min-h-[44px] bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-semibold hover:from-purple-700 hover:to-indigo-700 transition shadow-md text-sm">
            <i class="fas fa-comment-dots mr-2"></i>
            Feedback Ansprechpartner werden
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($canAddStats && in_array($currentStatus, ['closed', 'past'])): ?>
<div id="addStatsModal" class="evv-modal-overlay hidden">
    <div class="evv-modal">
        <div class="evv-modal-header">
            <h3 class="evv-modal-title">
                <i class="fas fa-chart-bar text-purple-600 mr-2"></i>
                Statistiken nachtragen
            </h3>

            <div class="space-y-4">
                <!-- Category -->
                <div class="evv-form-group">
                    <label class="evv-form-label">Kategorie</label>
                    <select id="statsCategory" onchange="onStatsCategoryChange()"
                            class="evv-form-input">
                        <option value="Verkauf">Verkauf</option>
                        <option value="Kalkulation">Kalkulation</option>
                        <option value="Spenden">Spenden</option>
                    </select>
                </div>

                <!-- Item-based fields (Verkauf / Kalkulation) -->
                <div id="statsItemFields" class="space-y-4">
                    <div class="evv-form-group">
                        <label class="evv-form-label">Artikelname</label>
                        <input type="text" id="statsItemName" maxlength="255"
                               class="evv-form-input"
                               placeholder="z.B. Bratwurst">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                        <div class="evv-form-group">
                            <label class="evv-form-label">Menge</label>
                            <input type="number" id="statsQuantity" min="0" step="1" value="0"
                                   class="evv-form-input">
                        </div>
                        <div class="evv-form-group">
                            <label class="evv-form-label">Umsatz (€)</label>
                            <input type="number" id="statsRevenue" min="0" step="0.01"
                                   class="evv-form-input"
                                   placeholder="Optional">
                        </div>
                    </div>
                    <div class="evv-form-group">
                        <label class="evv-form-label">Jahr</label>
                        <input type="number" id="statsYear" min="2000" max="<?php echo date('Y') + 10; ?>" value="<?php echo date('Y', strtotime($event['start_time'])); ?>"
                               class="evv-form-input">
                    </div>
                </div>

                <!-- Donations field (Spenden) -->
                <div id="statsDonationsField" class="hidden">
                    <div class="evv-form-group">
                        <label class="evv-form-label">Spendenbetrag (€)</label>
                        <input type="number" id="statsDonationsTotal" min="0" step="0.01" value="0"
                               class="evv-form-input">
                    </div>
                </div>

                <div id="statsError" class="hidden evv-error-message"></div>
            </div>
        </div>

        <div class="evv-modal-footer">
            <button type="button" id="closeAddStatsModalBtn"
                    class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                Abbrechen
            </button>
            <button type="button" onclick="submitAddStats()"
                    class="flex-1 px-6 py-3 bg-ibc-blue text-white rounded-lg hover:bg-ibc-blue-dark transition font-semibold">
                <i class="fas fa-save mr-2"></i>Speichern
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="message-container" class="evv-message-container">
    <div id="message-content" class="evv-message-content"></div>
</div>

<script>
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

// Show message helper
function showMessage(message, type = 'success') {
    const container = document.getElementById('message-container');
    const content = document.getElementById('message-content');

    const messageClass = type === 'success' ? 'evv-message-success' : 'evv-message-error';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';

    content.className = `evv-message-content ${messageClass}`;
    content.innerHTML = `<i class="fas ${icon} mr-2"></i>`;
    content.appendChild(document.createTextNode(message));

    container.classList.remove('hidden');
    container.style.display = 'block';

    setTimeout(() => {
        container.classList.add('hidden');
        container.style.display = 'none';
    }, 5000);
}

// Signup for event (general participation)
function signupForEvent(eventId) {
    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'signup',
            event_id: eventId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('Erfolgreich angemeldet!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Anmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Signup for helper slot
function signupForSlot(eventId, slotId, slotStart, slotEnd) {
    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'signup',
            event_id: eventId,
            slot_id: slotId,
            slot_start: slotStart,
            slot_end: slotEnd,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.status === 'waitlist') {
                showMessage('Sie wurden auf die Warteliste gesetzt', 'success');
            } else {
                showMessage('Erfolgreich eingetragen!', 'success');
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Anmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Cancel signup (general or helper slot)
function cancelSignup(signupId, message = 'Möchtest Du Deine Anmeldung wirklich stornieren?', successMessage = 'Abmeldung erfolgreich') {
    if (!confirm(message)) {
        return;
    }

    fetch('../../api/event_signup.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'cancel',
            signup_id: signupId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(successMessage, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showMessage(data.message || 'Fehler bei der Abmeldung', 'error');
        }
    })
    .catch(error => {
        showMessage('Netzwerkfehler', 'error');
    });
}

// Cancel helper slot (wrapper for consistency)
function cancelHelperSlot(signupId) {
    cancelSignup(signupId, 'Möchtest Du Dich wirklich austragen?', 'Erfolgreich ausgetragen');
}

<?php if ($canAddStats && in_array($currentStatus, ['closed', 'past'])): ?>
// ── Add Financial Stats Modal ──────────────────────────────────────────────────

function openAddStatsModal() {
    document.getElementById('addStatsModal').classList.remove('hidden');
    document.getElementById('statsError').classList.add('hidden');
}

function closeAddStatsModal() {
    document.getElementById('addStatsModal').classList.add('hidden');
}

function onStatsCategoryChange() {
    const category = document.getElementById('statsCategory').value;
    const itemFields = document.getElementById('statsItemFields');
    const donationsField = document.getElementById('statsDonationsField');
    if (category === 'Spenden') {
        itemFields.classList.add('hidden');
        donationsField.classList.remove('hidden');
    } else {
        itemFields.classList.remove('hidden');
        donationsField.classList.add('hidden');
    }
}

function submitAddStats() {
    const category = document.getElementById('statsCategory').value;
    const errorDiv = document.getElementById('statsError');
    errorDiv.classList.add('hidden');

    let payload = {
        event_id: <?php echo (int)$eventId; ?>,
        csrf_token: csrfToken
    };

    if (category === 'Spenden') {
        const donationsTotalRaw = document.getElementById('statsDonationsTotal').value;
        const donationsTotal = parseFloat(donationsTotalRaw);
        if (donationsTotalRaw === '' || isNaN(donationsTotal) || donationsTotal < 0) {
            errorDiv.textContent = 'Bitte einen gültigen Spendenbetrag (>= 0) eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }
        payload.donations_total = donationsTotal;
    } else {
        const itemName = document.getElementById('statsItemName').value.trim();
        const quantityRaw = document.getElementById('statsQuantity').value;
        const quantity = parseInt(quantityRaw);
        const revenue = document.getElementById('statsRevenue').value;
        const year = document.getElementById('statsYear').value;

        if (!itemName) {
            errorDiv.textContent = 'Bitte einen Artikelnamen eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }
        if (quantityRaw === '' || isNaN(quantity) || quantity < 0) {
            errorDiv.textContent = 'Bitte eine gültige Menge (>= 0) eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }

        payload.category = category;
        payload.item_name = itemName;
        payload.quantity = quantity;
        payload.revenue = revenue !== '' ? parseFloat(revenue) : null;
        payload.record_year = parseInt(year);
    }

    fetch('../../api/save_financial_stats.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeAddStatsModal();
            showMessage(data.message || 'Statistik erfolgreich gespeichert', 'success');
        } else {
            errorDiv.textContent = data.message || 'Fehler beim Speichern';
            errorDiv.classList.remove('hidden');
        }
    })
    .catch(() => {
        errorDiv.textContent = 'Netzwerkfehler';
        errorDiv.classList.remove('hidden');
    });
}

document.getElementById('closeAddStatsModalBtn')?.addEventListener('click', closeAddStatsModal);

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAddStatsModal();
});

document.getElementById('addStatsModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'addStatsModal') closeAddStatsModal();
});
<?php endif; ?>

// ── Feedback Contact ──────────────────────────────────────────────────────────
function sendFeedbackContactAction(action, btn) {
    btn.disabled = true;
    fetch('/api/set_feedback_contact.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({type: 'event', id: <?php echo intval($eventId); ?>, action: action, csrf_token: csrfToken})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            showMessage(data.message || 'Ein Fehler ist aufgetreten', 'error');
            btn.disabled = false;
        }
    })
    .catch(() => {
        showMessage('Netzwerkfehler', 'error');
        btn.disabled = false;
    });
}

document.getElementById('becomeFeedbackContactBtn')?.addEventListener('click', function() {
    sendFeedbackContactAction('set', this);
});
document.getElementById('removeFeedbackContactBtn')?.addEventListener('click', function() {
    if (confirm('Möchtest du dich als Feedback-Ansprechpartner zurückziehen?')) {
        sendFeedbackContactAction('remove', this);
    }
});

// ── Dynamic title colour based on hero image brightness ───────────────────────
(function () {
    const heroImg   = document.querySelector('.evv-hero-image img');
    const heroTitle = document.getElementById('eventHeroTitle');
    const overlay   = document.querySelector('.evv-hero-overlay');

    if (!heroImg || !heroTitle) return;

    function applyTitleColor() {
        try {
            const w = heroImg.naturalWidth;
            const h = heroImg.naturalHeight;
            if (!w || !h) return;

            // Sample the bottom 35% of the image – where the title overlaps
            const sampleY = Math.floor(h * 0.65);
            const sampleH = h - sampleY;

            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = sampleH;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(heroImg, 0, sampleY, w, sampleH, 0, 0, w, sampleH);

            const data = ctx.getImageData(0, 0, w, sampleH).data;
            let sum = 0;
            for (let i = 0; i < data.length; i += 4) {
                // Perceived brightness (ITU-R BT.601)
                sum += (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000;
            }
            const avgBrightness = sum / (w * sampleH);

            if (avgBrightness > 128) {
                // Light image – switch to dark title and a light overlay
                heroTitle.classList.remove('text-white');
                heroTitle.style.color = '#111827';
                heroTitle.style.textShadow = '0 1px 4px rgba(255,255,255,0.6)';
                if (overlay) {
                    overlay.style.background =
                        'linear-gradient(to top, rgba(255,255,255,0.70) 0%, rgba(255,255,255,0.30) 50%, transparent 100%)';
                }
            }
            // Dark image: keep default white text
        } catch (e) {
            // Canvas security error or browser limitation – keep white text
        }
    }

    if (heroImg.complete && heroImg.naturalWidth) {
        applyTitleColor();
    } else {
        heroImg.addEventListener('load', applyTitleColor);
    }
})();

</script>


<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
