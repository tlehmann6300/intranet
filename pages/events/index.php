<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

// Update event statuses (pseudo-cron)
require_once __DIR__ . '/../../includes/pseudo_cron.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Get filter from query parameters
$filter = $_GET['filter'] ?? 'current';

$filters = [];
$now = date('Y-m-d H:i:s');

// Filter logic
if ($filter === 'current') {
    // Show only future and current events
    $filters['start_date'] = $now;
} elseif ($filter === 'my_registrations') {
    // We'll filter this separately after getting events
}

// Get all events visible to user
$events = Event::getEvents($filters, $userRole, $user['id']);

// Get user's registrations if needed
if ($filter === 'my_registrations') {
    $userSignups = Event::getUserSignups($user['id']);
    $myEventIds = array_column($userSignups, 'event_id');
    $events = array_filter($events, function($event) use ($myEventIds) {
        return in_array($event['id'], $myEventIds);
    });
} else {
    // Hide past events for normal users (non-board, non-manager)
    // Board members, alumni_vorstand, alumni_finanz, and managers can see past events
    $canViewPastEvents = Auth::isBoard() || Auth::hasRole(['alumni_vorstand', 'alumni_finanz', 'manager']);
    if (!$canViewPastEvents) {
        $events = array_filter($events, function($event) use ($now) {
            return $event['end_time'] >= $now;
        });
    }
}

// Get user's signups for display
$userSignups = Event::getUserSignups($user['id']);
$myEventIds = array_column($userSignups, 'event_id');

$title = 'Events - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-calendar-alt mr-3 text-ibc-blue"></i>
                Events
            </h1>
            <p class="text-gray-600 dark:text-gray-300 leading-relaxed">Entdecke kommende Events und melde Dich an</p>
        </div>
        
        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
            <!-- Statistiken Button - Board/Alumni Vorstand only -->
            <?php if (Auth::isBoard() || Auth::hasRole(['alumni_vorstand'])): ?>
            <a href="statistics.php" class="inline-flex items-center justify-center w-full sm:w-auto px-6 py-3 bg-ibc-blue text-white rounded-lg font-semibold hover:bg-ibc-blue-dark transition-all shadow-soft hover:shadow-lg">
                <i class="fas fa-chart-bar mr-2"></i>
                Statistiken
            </a>
            <?php endif; ?>
            
            <!-- Neues Event Button - Board/Resortleiter/Manager only -->
            <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand'])): ?>
            <a href="edit.php?new=1" class="btn-primary w-full sm:w-auto justify-center">
                <i class="fas fa-plus mr-2"></i>Neues Event
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Tabs – horizontal scroll on mobile -->
    <div class="mb-6 -mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto scrollbar-hide">
        <div class="flex gap-2 flex-nowrap pb-1">
            <a href="?filter=current" 
               class="events-filter-tab flex-shrink-0 <?php echo $filter === 'current' ? 'events-filter-tab--active text-white' : ''; ?>">
                <i class="fas fa-calendar-day mr-2"></i>
                Aktuell
            </a>
            <a href="?filter=my_registrations" 
               class="events-filter-tab flex-shrink-0 <?php echo $filter === 'my_registrations' ? 'events-filter-tab--active text-white' : ''; ?>">
                <i class="fas fa-user-check mr-2"></i>
                Meine Anmeldungen
            </a>
        </div>
    </div>

    <!-- Events Grid -->
    <?php if (empty($events)): ?>
        <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
                 alt="Keine Events"
                 class="w-32 h-32 mx-auto mb-5 opacity-60">
            <?php if ($filter === 'my_registrations'): ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Anmeldungen gefunden</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 leading-relaxed">Du hast Dich noch für keine Events angemeldet.</p>
            <?php else: ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Aktuell stehen keine Events an.</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 leading-relaxed">Schau später wieder vorbei!</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">
            <?php foreach ($events as $event): ?>
                <?php
                    // Calculate countdown for upcoming events
                    $startTimestamp = strtotime($event['start_time']);
                    $nowTimestamp = time();
                    $isUpcoming = $startTimestamp > $nowTimestamp;
                    $isPast = strtotime($event['end_time']) < $nowTimestamp;
                    $isRegistered = in_array($event['id'], $myEventIds);

                    // Validate image path
                    $hasImage = false;
                    if (!empty($event['image_path'])) {
                        $fullImagePath = __DIR__ . '/../../' . $event['image_path'];
                        $realPath = realpath($fullImagePath);
                        $baseDir = realpath(__DIR__ . '/../../');
                        $hasImage = $realPath && $baseDir && strpos($realPath, $baseDir) === 0 && file_exists($realPath);
                    }
                    
                    $countdown = '';
                    if ($isUpcoming) {
                        $diff = $startTimestamp - $nowTimestamp;
                        $days = floor($diff / 86400);
                        $hours = floor(($diff % 86400) / 3600);
                        
                        if ($days > 0) {
                            $countdown = "Noch {$days} Tag" . ($days != 1 ? 'e' : '') . ", {$hours} Std";
                        } else {
                            $countdown = "Noch {$hours} Std";
                        }
                    }
                ?>
                
                <a href="view.php?id=<?php echo $event['id']; ?>" class="event-card card w-full flex flex-col overflow-hidden group no-underline event-card--<?php echo htmlspecialchars($event['status']); ?>" style="text-decoration:none;">
                    <!-- Status accent strip -->
                    <div class="event-card-accent"></div>
                    <!-- Event Image -->
                    <div class="event-card-image relative overflow-hidden">
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $event['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($event['title']); ?>"
                                 loading="lazy"
                                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center event-card-placeholder">
                                <i class="fas fa-calendar-alt text-white/30 text-5xl mb-2"></i>
                                <span class="text-white/50 text-xs font-semibold tracking-widest uppercase">Event</span>
                            </div>
                        <?php endif; ?>

                        <!-- Overlay badges -->
                        <div class="absolute top-3 left-3 flex flex-col gap-1">
                            <?php if ($event['status'] === 'draft'): ?>
                                <span class="px-2.5 py-1 bg-gray-800/80 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-pencil-alt mr-1"></i>Entwurf
                                </span>
                            <?php elseif ($event['status'] === 'open'): ?>
                                <span class="px-2.5 py-1 bg-ibc-green/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-door-open mr-1"></i>Anmeldung offen
                                </span>
                            <?php elseif ($event['status'] === 'running'): ?>
                                <span class="px-2.5 py-1 bg-ibc-blue/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-play mr-1"></i>Läuft gerade
                                </span>
                            <?php elseif ($event['status'] === 'past'): ?>
                                <span class="px-2.5 py-1 bg-gray-600/80 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-flag-checkered mr-1"></i>Beendet
                                </span>
                            <?php endif; ?>

                            <?php if ($event['is_external']): ?>
                                <span class="px-2.5 py-1 bg-ibc-accent/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-external-link-alt mr-1"></i>Extern
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isRegistered): ?>
                            <div class="absolute top-3 right-3">
                                <span class="px-2.5 py-1 bg-ibc-green/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-check mr-1"></i>Angemeldet
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($countdown): ?>
                            <div class="absolute bottom-3 left-3">
                                <span class="inline-flex items-center px-3 py-1 bg-black/60 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                    <i class="fas fa-hourglass-half mr-1.5"></i>
                                    <?php echo $countdown; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <!-- Date stamp chip -->
                        <?php
                            $germanMonths = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
                            $monthAbbr = $germanMonths[date('n', $startTimestamp) - 1];
                        ?>
                        <div class="absolute bottom-3 right-3">
                            <div class="event-date-chip">
                                <span class="event-date-chip-month"><?php echo $monthAbbr; ?></span>
                                <span class="event-date-chip-day"><?php echo date('d', $startTimestamp); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="flex flex-col flex-1 p-5">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 leading-snug line-clamp-2 break-words hyphens-auto">
                            <?php echo htmlspecialchars($event['title']); ?>
                        </h3>

                        <!-- Meta Info -->
                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400 mb-4">
                            <div class="flex items-center gap-2">
                                <span class="event-meta-icon"><i class="fas fa-calendar text-ibc-blue"></i></span>
                                <span>
                                    <?php
                                        $startDate = new DateTime($event['start_time']);
                                        $endDate   = new DateTime($event['end_time']);
                                        if ($startDate->format('d.m.Y') === $endDate->format('d.m.Y')) {
                                            echo $startDate->format('d.m.Y, H:i') . ' – ' . $endDate->format('H:i') . ' Uhr';
                                        } else {
                                            echo $startDate->format('d.m.Y, H:i') . ' – ' . $endDate->format('d.m.Y, H:i') . ' Uhr';
                                        }
                                    ?>
                                </span>
                            </div>
                            <?php if (!empty($event['location'])): ?>
                                <div class="flex items-center gap-2">
                                    <span class="event-meta-icon"><i class="fas fa-map-marker-alt text-ibc-blue"></i></span>
                                    <span class="truncate"><?php echo htmlspecialchars($event['location']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($event['needs_helpers'] && $userRole !== 'alumni'): ?>
                                <div class="flex items-center gap-2">
                                    <span class="event-meta-icon"><i class="fas fa-hands-helping text-ibc-green"></i></span>
                                    <span class="text-ibc-green font-medium">Helfer benötigt</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Description Preview -->
                        <?php if (!empty($event['description'])): ?>
                            <p class="text-gray-600 dark:text-gray-400 text-sm line-clamp-2 flex-1 mb-4 break-words hyphens-auto leading-relaxed">
                                <?php echo htmlspecialchars(substr($event['description'], 0, 120)); ?><?php echo strlen($event['description']) > 120 ? '…' : ''; ?>
                            </p>
                        <?php else: ?>
                            <div class="flex-1"></div>
                        <?php endif; ?>

                        <!-- CTA -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700">
                            <span class="text-sm font-semibold event-cta-link group-hover:text-ibc-blue-dark transition-colors">
                                Details ansehen
                            </span>
                            <span class="w-8 h-8 rounded-full bg-ibc-blue/10 flex items-center justify-center group-hover:bg-ibc-blue transition-all event-arrow-btn">
                                <i class="fas fa-arrow-right text-xs event-cta-link group-hover:text-white transition-colors"></i>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* ── Filter Tabs ────────────────────────────────── */
    .scrollbar-hide {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .scrollbar-hide::-webkit-scrollbar { display: none; }

    .events-filter-tab {
        display: inline-flex;
        align-items: center;
        padding: 0.6rem 1.5rem;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.25s ease;
        background: var(--bg-card);
        color: var(--text-muted);
        border: 1.5px solid var(--border-color);
        text-decoration: none !important;
        white-space: nowrap;
    }
    .events-filter-tab:hover {
        border-color: var(--ibc-blue);
        color: var(--ibc-blue) !important;
        box-shadow: 0 2px 8px rgba(0,102,179,0.12);
    }
    .events-filter-tab--active {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 100%) !important;
        color: #ffffff !important;
        border-color: transparent !important;
        box-shadow: 0 4px 14px rgba(0,102,179,0.35);
    }

    /* ── Event Card ─────────────────────────────────── */
    .event-card {
        transition: transform 0.3s cubic-bezier(0.34, 1.2, 0.64, 1), box-shadow 0.3s ease, border-color 0.3s ease;
        color: inherit;
        border: 1.5px solid var(--border-color) !important;
    }
    .event-card:hover {
        transform: translateY(-7px) scale(1.01);
        box-shadow: 0 20px 40px rgba(0, 102, 179, 0.18), 0 8px 16px rgba(0, 0, 0, 0.12);
        border-color: var(--ibc-blue) !important;
    }

    /* Status accent strip */
    .event-card-accent {
        height: 4px;
        flex-shrink: 0;
        background: var(--ibc-blue);
    }
    .event-card--open    .event-card-accent { background: var(--ibc-green); }
    .event-card--running .event-card-accent { background: var(--ibc-blue); }
    .event-card--past    .event-card-accent { background: var(--ibc-gray-400); }
    .event-card--draft   .event-card-accent { background: var(--ibc-gray-400); }
    .event-card--closed  .event-card-accent { background: var(--ibc-warning); }

    /* ── Card Image ─────────────────────────────────── */
    .event-card-image {
        height: 220px;
        background: #e5e7eb;
        flex-shrink: 0;
    }
    @media (max-width: 480px) {
        .event-card-image { height: 180px; }
    }

    /* Image overlay so text badges stay readable */
    .event-card-image::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.28) 0%, rgba(0,0,0,0.15) 40%, rgba(0,0,0,0.55) 100%);
        pointer-events: none;
    }

    /* Placeholder gradient per status */
    .event-card-placeholder {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%);
    }
    .event-card--open    .event-card-placeholder { background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-dark) 60%, #004a24 100%); }
    .event-card--past    .event-card-placeholder { background: linear-gradient(135deg, #374151 0%, #1f2937 100%); }

    /* ── Date Chip ───────────────────────────────────── */
    .event-date-chip {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,0.92);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border-radius: 0.75rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.22);
        padding: 0.35rem 0.65rem;
        min-width: 44px;
        text-align: center;
        line-height: 1;
    }
    .dark-mode .event-date-chip {
        background: rgba(26,31,46,0.92);
    }
    .event-date-chip-month {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ibc-blue);
        line-height: 1;
    }
    .event-date-chip-day {
        font-size: 1.4rem;
        font-weight: 800;
        color: #111827;
        line-height: 1.1;
    }
    .dark-mode .event-date-chip-day {
        color: #f8fafc;
    }

    /* ── Meta Icon ──────────────────────────────────── */
    .event-meta-icon {
        width: 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* ── Text Clamp ─────────────────────────────────── */
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .event-cta-link {
        color: var(--ibc-blue);
        transition: color 0.25s ease;
    }

    /* Arrow button transition enhancement */
    .event-arrow-btn {
        transition: background-color 0.25s ease, transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s ease;
    }
    .event-card:hover .event-arrow-btn {
        transform: scale(1.15);
        box-shadow: 0 4px 12px rgba(0, 102, 179, 0.35);
    }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
