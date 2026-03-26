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
    'planned' => ['label' => 'Geplant',                'icon' => 'fa-clock',          'color' => 'bg-white/20 border-white/30 text-white'],
    'open'    => ['label' => 'Anmeldung offen',         'icon' => 'fa-door-open',      'color' => 'bg-ibc-green/30 border-ibc-green/50 text-white'],
    'closed'  => ['label' => 'Anmeldung geschlossen',   'icon' => 'fa-door-closed',    'color' => 'bg-yellow-500/30 border-yellow-400/50 text-white'],
    'running' => ['label' => 'Läuft gerade',            'icon' => 'fa-play-circle',    'color' => 'bg-white/30 border-white/50 text-white'],
    'past'    => ['label' => 'Beendet',                 'icon' => 'fa-flag-checkered', 'color' => 'bg-white/10 border-white/20 text-white/70'],
];
$currentStatus = $event['status'] ?? 'planned';
$statusInfo = $statusLabels[$currentStatus] ?? ['label' => $currentStatus, 'icon' => 'fa-circle', 'color' => 'bg-white/20 border-white/30 text-white'];
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
    <div class="event-hero rounded-2xl overflow-hidden shadow-premium mb-6">
        <!-- Image / Fallback gradient -->
        <div class="event-hero-image">
            <?php if ($imageExists): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $imagePath); ?>"
                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                     class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full event-hero-placeholder flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-white/15 event-hero-placeholder-icon"></i>
                </div>
            <?php endif; ?>
            <!-- Dark gradient overlay for legibility -->
            <div class="event-hero-overlay"></div>
        </div>

        <!-- Title + badges on top of image -->
        <div class="event-hero-content">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border backdrop-blur-sm <?php echo $statusInfo['color']; ?>">
                    <i class="fas <?php echo $statusInfo['icon']; ?> mr-1.5 text-xs"></i>
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($event['is_external']): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border border-white/30 bg-ibc-accent/80 backdrop-blur-sm text-white">
                        <i class="fas fa-external-link-alt mr-1.5 text-xs"></i>Extern
                    </span>
                <?php endif; ?>
                <?php if ($isRegistered): ?>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-semibold border border-ibc-green/50 bg-ibc-green/70 backdrop-blur-sm text-white">
                        <i class="fas fa-check-circle mr-1.5 text-xs"></i>Angemeldet
                    </span>
                <?php endif; ?>
            </div>

            <h1 id="eventHeroTitle" class="text-2xl sm:text-3xl md:text-4xl font-bold text-white drop-shadow-lg leading-tight">
                <?php echo htmlspecialchars($event['title']); ?>
            </h1>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════
         QUICK STATS ROW  (date, location, participants)
    ════════════════════════════════════════════════ -->
    <div class="event-quickstats mb-6">
        <!-- Start -->
        <div class="event-stat-card event-stat-card--blue">
            <span class="event-stat-icon">
                <i class="fas fa-calendar-day"></i>
            </span>
            <div class="min-w-0">
                <div class="event-stat-label">Beginn</div>
                <div class="event-stat-value"><?php echo date('d.m.Y', $startTimestamp); ?></div>
                <div class="event-stat-sub"><?php echo date('H:i', $startTimestamp); ?> Uhr</div>
            </div>
        </div>
        <!-- End -->
        <div class="event-stat-card event-stat-card--purple">
            <span class="event-stat-icon">
                <i class="fas fa-clock"></i>
            </span>
            <div class="min-w-0">
                <div class="event-stat-label">Ende</div>
                <div class="event-stat-value"><?php echo date('d.m.Y', $endTimestamp); ?></div>
                <div class="event-stat-sub"><?php echo date('H:i', $endTimestamp); ?> Uhr</div>
            </div>
        </div>
        <?php if (!empty($event['location'])): ?>
        <!-- Location -->
        <div class="event-stat-card event-stat-card--green">
            <span class="event-stat-icon">
                <i class="fas fa-map-marker-alt"></i>
            </span>
            <div class="min-w-0">
                <div class="event-stat-label">Ort</div>
                <div class="event-stat-value truncate"><?php echo htmlspecialchars($event['location']); ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!$event['is_external']): ?>
        <!-- Participants -->
        <div class="event-stat-card event-stat-card--orange">
            <span class="event-stat-icon">
                <i class="fas fa-users"></i>
            </span>
            <div class="min-w-0">
                <div class="event-stat-label">Teilnehmer</div>
                <div class="event-stat-value"><?php echo $registrationCount; ?></div>
                <?php if ($isRegistered): ?>
                <div class="event-stat-sub" style="color:var(--ibc-green);"><i class="fas fa-check-circle mr-1"></i>Angemeldet</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════
         MAIN CONTENT  (two-column on md+)
    ════════════════════════════════════════════════ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        <!-- LEFT: Description + Participants -->
        <div class="lg:col-span-2 space-y-6">

            <?php if (!empty($event['description'])): ?>
            <!-- Description Card -->
            <div class="glass-card shadow-soft rounded-2xl p-6">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-align-left text-ibc-blue text-sm"></i>
                    </span>
                    Beschreibung
                </h2>
                <p class="text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed break-words hyphens-auto event-description"><?php echo htmlspecialchars($event['description']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!$event['is_external']): ?>
            <!-- Participants Card -->
            <div class="glass-card shadow-soft rounded-2xl p-6">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-ibc-green/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-users text-ibc-green text-sm"></i>
                    </span>
                    Teilnehmer
                    <span class="ml-auto inline-flex items-center justify-center min-w-[2rem] h-8 px-2.5 rounded-full bg-ibc-blue text-white text-sm font-bold">
                        <?php echo $registrationCount; ?>
                    </span>
                </h2>
                <?php if (!empty($participants)): ?>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($participants as $participant): ?>
                            <li class="py-2.5 flex items-center gap-3 text-gray-700 dark:text-gray-300">
                                <span class="w-7 h-7 rounded-full bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-user text-ibc-blue text-xs"></i>
                                </span>
                                <?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Noch keine Anmeldungen.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Info Sidebar -->
        <div class="space-y-4 event-sidebar">

            <!-- Date & Time Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Datum & Uhrzeit</h3>
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-calendar-day text-ibc-blue"></i>
                        </span>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Beginn</div>
                            <div class="font-semibold text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y', strtotime($event['start_time'])); ?></div>
                            <div class="text-sm text-gray-600 dark:text-gray-300"><?php echo date('H:i', strtotime($event['start_time'])); ?> Uhr</div>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fas fa-clock text-ibc-blue"></i>
                        </span>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Ende</div>
                            <div class="font-semibold text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y', strtotime($event['end_time'])); ?></div>
                            <div class="text-sm text-gray-600 dark:text-gray-300"><?php echo date('H:i', strtotime($event['end_time'])); ?> Uhr</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($event['location'])): ?>
            <!-- Location Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Veranstaltungsort</h3>
                <div class="flex items-start gap-3">
                    <span class="w-9 h-9 rounded-xl bg-ibc-green/10 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <i class="fas fa-map-marker-alt text-ibc-green"></i>
                    </span>
                    <div class="flex-1">
                        <div class="font-semibold text-gray-800 dark:text-gray-100 break-words hyphens-auto"><?php echo htmlspecialchars($event['location']); ?></div>
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
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Ansprechpartner</h3>
                <div class="flex items-center gap-3">
                    <span class="w-9 h-9 rounded-xl bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-ibc-blue"></i>
                    </span>
                    <span class="font-semibold text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($event['contact_person']); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Registration / CTA Card -->
            <div class="event-cta-card rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-white/80 uppercase tracking-wider mb-3">
                    <i class="fas fa-ticket-alt mr-1.5"></i>Anmeldung
                </h3>
                <div class="flex flex-col gap-3">
                    <?php if (!empty($event['registration_link'])): ?>
                        <a href="<?php echo htmlspecialchars($event['registration_link']); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center justify-center px-5 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium w-full">
                            <i class="fas fa-external-link-alt mr-2"></i>
                            Jetzt anmelden
                        </a>
                    <?php elseif ($event['is_external']): ?>
                        <?php if (!empty($event['external_link'])): ?>
                            <a href="<?php echo htmlspecialchars($event['external_link']); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-flex items-center justify-center px-5 py-3 bg-ibc-blue text-white rounded-xl font-semibold hover:bg-ibc-blue-dark ease-premium shadow-soft w-full">
                                <i class="fas fa-external-link-alt mr-2"></i>
                                Zur Anmeldung (extern)
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$isRegistered && !$userSlotId): ?>
                            <button onclick="signupForEvent(<?php echo intval($eventId); ?>)"
                                    class="inline-flex items-center justify-center px-5 py-3 bg-ibc-green text-white rounded-xl font-semibold hover:shadow-glow-green ease-premium w-full">
                                <i class="fas fa-user-plus mr-2"></i>
                                Jetzt anmelden
                            </button>
                        <?php elseif ($canCancel && $userSignupId && !$userSlotId): ?>
                            <button onclick="cancelSignup(<?php echo $userSignupId; ?>)"
                                    class="inline-flex items-center justify-center px-5 py-3 bg-red-600 text-white rounded-xl font-semibold hover:bg-red-700 ease-premium w-full">
                                <i class="fas fa-user-times mr-2"></i>
                                Abmelden
                            </button>
                        <?php elseif ($isRegistered): ?>
                            <div class="flex items-center justify-center gap-2 py-3 rounded-xl bg-ibc-green/10 text-ibc-green font-semibold border border-ibc-green/20">
                                <i class="fas fa-check-circle"></i>
                                Du bist angemeldet
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Calendar Export -->
                    <div class="pt-2 border-t border-white/20">
                        <p class="text-xs text-white/60 mb-2 font-medium">In Kalender eintragen</p>
                        <div class="flex gap-4">
                            <a href="<?php echo htmlspecialchars(CalendarService::getGoogleLink($event)); ?>"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 min-h-[44px] bg-white/15 border border-white/25 text-white rounded-lg text-sm font-semibold hover:bg-white/25 ease-premium">
                                <i class="fab fa-google mr-1.5"></i>Google
                            </a>
                            <a href="../../api/download_ics.php?event_id=<?php echo htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8'); ?>"
                               class="flex-1 inline-flex items-center justify-center px-3 py-2 min-h-[44px] bg-white/15 border border-white/25 text-white rounded-lg text-sm font-semibold hover:bg-white/25 ease-premium">
                                <i class="fas fa-download mr-1.5"></i>iCal
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($canAddStats && in_array($currentStatus, ['closed', 'past'])): ?>
            <!-- Add Financial Stats Card -->
            <div class="glass-card shadow-soft rounded-2xl p-5">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Verwaltung</h3>
                <button onclick="openAddStatsModal()"
                        class="w-full inline-flex items-center justify-center px-5 py-3 bg-ibc-blue text-white rounded-xl font-semibold hover:bg-ibc-blue-dark ease-premium">
                    <i class="fas fa-chart-bar mr-2"></i>
                    Statistiken nachtragen
                </button>
            </div>
            <?php endif; ?>

        </div><!-- /sidebar -->
    </div><!-- /grid -->

    <!-- Helper Slots Section (Only for non-alumni and if event needs helpers) -->
    <?php if ($event['needs_helpers'] && $userRole !== 'alumni' && !empty($helperTypes)): ?>
        <div class="card rounded-2xl p-6 border border-gray-200 dark:border-gray-700 shadow-soft">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-1 flex items-center gap-2">
                <span class="w-9 h-9 rounded-xl bg-ibc-green/10 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-hands-helping text-ibc-green"></i>
                </span>
                Helfer-Bereich
            </h2>
            <p class="text-gray-500 dark:text-gray-400 text-sm mb-5 ml-11">Unterstütze uns als Helfer! Wähle einen freien Slot aus.</p>
            
            <?php foreach ($helperTypes as $helperType): ?>
                <div class="mb-6 last:mb-0">
                    <h3 class="text-base font-bold text-gray-800 dark:text-gray-100 mb-1 break-words hyphens-auto">
                        <?php echo htmlspecialchars($helperType['title']); ?>
                    </h3>
                    
                    <?php if (!empty($helperType['description'])): ?>
                        <p class="text-gray-500 dark:text-gray-400 text-sm mb-3"><?php echo htmlspecialchars($helperType['description']); ?></p>
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
                            
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 rounded-xl border <?php echo $slot['user_in_slot'] ? 'border-ibc-green/40 bg-ibc-green/5' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50'; ?>">
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2 flex-wrap">
                                        <i class="fas fa-clock text-ibc-blue text-sm"></i>
                                        <?php echo htmlspecialchars($slotTimeDisplay); ?>
                                        <?php if ($isAufbau): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-ibc-blue/10 text-ibc-blue border border-ibc-blue/20">
                                                <i class="fas fa-tools mr-1 text-xs"></i>Aufbau
                                            </span>
                                        <?php elseif ($isAbbau): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-ibc-accent/10 text-ibc-accent border border-ibc-accent/20">
                                                <i class="fas fa-box mr-1 text-xs"></i>Abbau
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Capacity bar -->
                                    <div class="mt-2 flex items-center gap-2">
                                        <div class="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-600 overflow-hidden">
                                            <div class="h-full rounded-full <?php echo $slot['is_full'] ? 'bg-red-500' : 'bg-ibc-green'; ?>" style="width:<?php echo $fillPct; ?>%"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap"><?php echo $occupancy; ?> belegt</span>
                                    </div>
                                </div>
                                
                                <div class="flex-shrink-0">
                                    <?php if ($slot['user_in_slot']): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="px-3 py-1.5 bg-ibc-green/10 text-ibc-green border border-ibc-green/20 rounded-xl font-semibold text-sm">
                                                <i class="fas fa-check mr-1"></i>Eingetragen
                                            </span>
                                            <?php if ($canCancel): ?>
                                                <button onclick="cancelHelperSlot(<?php echo $userSignupId; ?>)" 
                                                        class="px-3 py-1.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-xl font-semibold text-sm hover:bg-red-200 ease-premium">
                                                    <i class="fas fa-times mr-1"></i>Austragen
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($canSignup): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>" 
                                                class="rounded-xl bg-ibc-green text-white px-4 py-2 text-sm font-semibold hover:shadow-glow ease-premium">
                                            <i class="fas fa-user-plus mr-1.5"></i>Als Helfer eintragen
                                        </button>
                                    <?php elseif ($onWaitlist): ?>
                                        <button onclick="<?php echo $slotSignupHandler; ?>" 
                                                class="px-4 py-2 bg-amber-500 text-white rounded-xl font-semibold text-sm hover:bg-amber-600 ease-premium">
                                            <i class="fas fa-list mr-1.5"></i>Warteliste
                                        </button>
                                    <?php else: ?>
                                        <span class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-xl font-semibold text-sm">
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
        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 rounded-2xl p-6 border border-purple-100 dark:border-purple-800 shadow-soft">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-purple-600/10 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-comment-dots text-purple-600 text-sm"></i>
                </span>
                Feedback Ansprechpartner
            </h2>
            <div class="flex items-center gap-4">
                <?php if (!empty($feedbackContact['image_path'])): ?>
                <img src="/<?php echo htmlspecialchars($feedbackContact['image_path']); ?>"
                     alt="<?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>"
                     class="w-16 h-16 rounded-full object-cover border-2 border-purple-300 shadow-md flex-shrink-0">
                <?php else: ?>
                <div class="w-16 h-16 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center flex-shrink-0 border-2 border-purple-300">
                    <i class="fas fa-user text-purple-600 text-xl"></i>
                </div>
                <?php endif; ?>
                <div>
                    <div class="font-bold text-gray-900 dark:text-white text-base">
                        <?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>
                    </div>
                    <?php if (!empty($feedbackContact['position']) || !empty($feedbackContact['company'])): ?>
                    <div class="text-sm text-gray-600 dark:text-gray-300 mt-0.5">
                        <?php
                        $parts = array_filter([$feedbackContact['position'] ?? '', $feedbackContact['company'] ?? '']);
                        echo htmlspecialchars(implode(' · ', $parts));
                        ?>
                    </div>
                    <?php endif; ?>
                    <div class="text-xs text-purple-600 dark:text-purple-400 mt-1 font-medium">
                        <i class="fas fa-star mr-1"></i>Stellt sich für Feedback zur Verfügung
                    </div>
                </div>
                <?php if ($isFeedbackContact): ?>
                <button id="removeFeedbackContactBtn"
                        class="ml-auto px-4 py-2 bg-red-100 text-red-700 rounded-lg font-semibold hover:bg-red-200 transition text-sm">
                    <i class="fas fa-times mr-1"></i>Zurückziehen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($canBecomeFeedbackContact): ?>
    <div class="mt-6">
        <button id="becomeFeedbackContactBtn"
                class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-semibold hover:from-purple-700 hover:to-indigo-700 transition shadow-md text-sm">
            <i class="fas fa-comment-dots mr-2"></i>
            Feedback Ansprechpartner werden
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($canAddStats && in_array($currentStatus, ['closed', 'past'])): ?>
<div id="addStatsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-chart-bar text-purple-600 mr-2"></i>
                Statistiken nachtragen
            </h3>

            <div class="space-y-4">
                <!-- Category -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Kategorie</label>
                    <select id="statsCategory" onchange="onStatsCategoryChange()"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="Verkauf">Verkauf</option>
                        <option value="Kalkulation">Kalkulation</option>
                        <option value="Spenden">Spenden</option>
                    </select>
                </div>

                <!-- Item-based fields (Verkauf / Kalkulation) -->
                <div id="statsItemFields" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Artikelname</label>
                        <input type="text" id="statsItemName" maxlength="255"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                               placeholder="z.B. Bratwurst">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Menge</label>
                            <input type="number" id="statsQuantity" min="0" step="1" value="0"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Umsatz (€)</label>
                            <input type="number" id="statsRevenue" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                   placeholder="Optional">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Jahr</label>
                        <input type="number" id="statsYear" min="2000" max="<?php echo date('Y') + 10; ?>" value="<?php echo date('Y', strtotime($event['start_time'])); ?>"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    </div>
                </div>

                <!-- Donations field (Spenden) -->
                <div id="statsDonationsField" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Spendenbetrag (€)</label>
                    <input type="number" id="statsDonationsTotal" min="0" step="0.01" value="0"
                           class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>

                <div id="statsError" class="hidden p-3 bg-red-100 border border-red-300 text-red-700 rounded-lg text-sm"></div>
            </div>
        </div>

        <div class="px-6 pb-6 flex space-x-4">
            <button type="button" id="closeAddStatsModalBtn"
                    class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                Abbrechen
            </button>
            <button type="button" onclick="submitAddStats()"
                    class="flex-1 px-6 py-3 bg-ibc-blue text-white rounded-lg hover:bg-ibc-blue-dark transition">
                <i class="fas fa-save mr-2"></i>Speichern
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Hero & Card Styles -->
<style>
    /* Local accent colors for stat cards – use IBC brand palette */
    :root {
        --stat-teal:   #0891b2;
        --stat-amber:  var(--ibc-accent);
    }

    /* ── Quick Stats Row ────────────────────────────── */
    .event-quickstats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
    }

    /* Stat cards with colored left border accents */
    .event-stat-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 1rem;
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: var(--shadow-soft);
        border-left-width: 4px;
    }
    .event-stat-card--blue   { border-left-color: var(--ibc-blue); }
    .event-stat-card--purple { border-left-color: var(--stat-teal); }
    .event-stat-card--green  { border-left-color: var(--ibc-green); }
    .event-stat-card--orange { border-left-color: var(--stat-amber); }

    .event-stat-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .event-stat-card--blue   .event-stat-icon { background: rgba(0,102,179,0.12);   color: var(--ibc-blue); }
    .event-stat-card--purple .event-stat-icon { background: rgba(8,145,178,0.12);   color: var(--stat-teal); }
    .event-stat-card--green  .event-stat-icon { background: rgba(0,166,81,0.12);    color: var(--ibc-green); }
    .event-stat-card--orange .event-stat-icon { background: rgba(255,107,53,0.12);  color: var(--stat-amber); }

    .event-stat-label {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
    }
    .event-stat-value {
        font-weight: 700;
        color: var(--text-main);
        font-size: 0.95rem;
        line-height: 1.3;
    }
    .event-stat-sub {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-top: 0.1rem;
    }

    /* ── Hero Section ───────────────────────────────── */
    .event-hero {
        position: relative;
        background: #1f2937;
    }
    .event-hero-image {
        width: 100%;
        height: 480px;
        position: relative;
        overflow: hidden;
    }
    @media (max-width: 640px) {
        .event-hero-image { height: 280px; }
    }
    .event-hero-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .event-hero-placeholder {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 55%, #001f3a 100%);
    }
    .event-hero-placeholder-icon {
        font-size: 7rem;
    }
    @media (max-width: 640px) {
        .event-hero-placeholder-icon { font-size: 4rem; }
    }
    .event-hero-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.80) 0%, rgba(0,0,0,0.40) 50%, rgba(0,0,0,0.10) 100%);
    }
    .event-hero-content {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 1.5rem 2rem;
    }
    @media (max-width: 640px) {
        .event-hero-content { padding: 1rem 1.25rem; }
    }

    /* ── Registration CTA Card ──────────────────────── */
    .event-cta-card {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 100%);
        box-shadow: 0 8px 24px rgba(0,102,179,0.35);
        border: none;
    }
    .event-cta-card h3,
    .event-cta-card p,
    .event-cta-card .event-stat-label {
        color: rgba(255,255,255,0.75) !important;
    }

    /* ── Sidebar Sticky on Desktop ──────────────────── */
    @media (min-width: 1024px) {
        .event-sidebar {
            position: sticky;
            top: 1.5rem;
            align-self: flex-start;
        }
    }
</style>
<div id="message-container" class="fixed top-4 right-4 z-50 hidden">
    <div id="message-content" class="card px-6 py-4 shadow-2xl"></div>
</div>

<script>
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

// Show message helper
function showMessage(message, type = 'success') {
    const container = document.getElementById('message-container');
    const content = document.getElementById('message-content');
    
    const bgColor = type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    
    content.className = `card px-6 py-4 shadow-2xl ${bgColor}`;
    content.innerHTML = `<i class="fas ${icon} mr-2"></i>`;
    content.appendChild(document.createTextNode(message));
    
    container.classList.remove('hidden');
    
    setTimeout(() => {
        container.classList.add('hidden');
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
    const heroImg   = document.querySelector('.event-hero-image img');
    const heroTitle = document.getElementById('eventHeroTitle');
    const overlay   = document.querySelector('.event-hero-overlay');

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
                heroTitle.classList.remove('text-white', 'drop-shadow-lg');
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
