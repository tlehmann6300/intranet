<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Initialize empty arrays for alumni
$events = [];
$mySlotIds = [];

// Only fetch events if user is not alumni (they can't access helper system)
if ($userRole !== 'alumni') {
    // Get all events that need helpers
    $filters = [
        'needs_helpers' => true,
        'include_helpers' => true
    ];
    
    // Get events where helpers are needed
    $events = Event::getEvents($filters, $userRole);
    
    // Filter to only show events that have not yet ended (not past)
    $events = array_filter($events, function($event) {
        return in_array($event['status'], ['open', 'planned', 'running', 'closed']);
    });
    
    // Get user's signups to show which slots they're already signed up for
    $userSignups = Event::getUserSignups($user['id']);
    $mySlotIds = array_column($userSignups, 'slot_id');
}

$title = 'Helfersystem - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <div class="w-11 h-11 rounded-2xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center shadow-sm">
                    <i class="fas fa-hands-helping text-ibc-green text-xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Helfersystem</h1>
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Wir suchen Helfer für folgende Events – Unterstütze uns!</p>
        </div>
    </div>

    <?php if ($userRole === 'alumni'): ?>
        <!-- Alumni message -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-10 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center">
                <i class="fas fa-info-circle text-3xl text-ibc-blue"></i>
            </div>
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Information für Alumni</h2>
            <p class="text-gray-600 dark:text-gray-300">Als Alumni-Mitglied hast Du keinen Zugriff auf das Helfersystem.</p>
        </div>
    <?php elseif (empty($events)): ?>
        <!-- No events message -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-10 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-50 dark:bg-green-900/20 flex items-center justify-center">
                <i class="fas fa-check-circle text-3xl text-ibc-green"></i>
            </div>
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Keine Helfer benötigt</h2>
            <p class="text-gray-600 dark:text-gray-300">Aktuell werden für keine Events Helfer gesucht.</p>
        </div>
    <?php else: ?>
        <!-- Banner -->
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-ibc-green via-emerald-600 to-ibc-blue p-7 mb-8 text-white shadow-xl">
            <div class="relative z-10">
                <div class="flex items-center gap-3 mb-3">
                    <span class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center">
                        <i class="fas fa-bullhorn text-white text-lg"></i>
                    </span>
                    <h2 class="text-lg sm:text-2xl font-bold">Wir suchen Helfer!</h2>
                </div>
                <p class="text-white/90 text-sm leading-relaxed mb-5 max-w-2xl">
                    Deine Mithilfe ist gefragt! Für die folgenden Events suchen wir noch Unterstützung.
                    Melde Dich für einen Slot an und hilf uns dabei, großartige Events zu organisieren.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 backdrop-blur-sm rounded-xl text-sm font-semibold">
                        <i class="fas fa-calendar-check"></i>
                        <strong><?php echo count($events); ?></strong> <?php echo count($events) === 1 ? 'Event' : 'Events'; ?>
                    </span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-white/20 backdrop-blur-sm rounded-xl text-sm font-semibold">
                        <i class="fas fa-users"></i>
                        Verschiedene Rollen verfügbar
                    </span>
                </div>
            </div>
            <!-- Decorative circles -->
            <div class="absolute -top-8 -right-8 w-40 h-40 rounded-full bg-white/5 pointer-events-none"></div>
            <div class="absolute -bottom-12 right-16 w-56 h-56 rounded-full bg-white/5 pointer-events-none"></div>
        </div>

        <!-- Events List -->
        <div class="space-y-6">
            <?php foreach ($events as $event): ?>
                <?php 
                // Get helper types for this event
                $helperTypes = $event['helper_types'] ?? [];
                
                // Calculate total slots needed and filled
                $totalSlotsNeeded = 0;
                $totalSlotsFilled = 0;
                
                foreach ($helperTypes as $helperType) {
                    foreach ($helperType['slots'] as $slot) {
                        $totalSlotsNeeded += $slot['quantity_needed'];
                        $totalSlotsFilled += $slot['signups_count'];
                    }
                }
                
                $slotsAvailable = $totalSlotsNeeded - $totalSlotsFilled;
                $fillPercent = $totalSlotsNeeded > 0 ? round((float)$totalSlotsFilled / $totalSlotsNeeded * 100) : 0;
                
                // Parse event date
                $startDate = new DateTime($event['start_time']);
                $endDate   = new DateTime($event['end_time']);
                $formattedDate = $startDate->format('d.m.Y');
                $formattedTime = $startDate->format('H:i');
                ?>
                
                <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">
                    <!-- Event Header -->
                    <div class="p-6 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <h2 class="text-base sm:text-xl font-bold text-gray-900 dark:text-gray-50 mb-2 leading-snug break-words hyphens-auto">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </h2>
                                <div class="flex flex-wrap gap-3 text-sm text-gray-500 dark:text-gray-400">
                                    <span class="inline-flex items-center gap-1.5">
                                        <i class="fas fa-calendar text-ibc-blue"></i>
                                        <?php echo $formattedDate; ?>, <?php echo $formattedTime; ?> Uhr
                                    </span>
                                    <?php if (!empty($event['location'])): ?>
                                        <span class="inline-flex items-center gap-1.5">
                                            <i class="fas fa-map-marker-alt text-ibc-green"></i>
                                            <?php echo htmlspecialchars($event['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Slots Summary Badge -->
                            <div class="flex-shrink-0 text-center px-5 py-3 rounded-xl <?php echo $slotsAvailable > 0 ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700'; ?>">
                                <div class="text-2xl font-bold <?php echo $slotsAvailable > 0 ? 'text-ibc-green' : 'text-gray-400'; ?>">
                                    <?php echo $slotsAvailable; ?>
                                </div>
                                <div class="text-xs font-medium <?php echo $slotsAvailable > 0 ? 'text-green-700 dark:text-green-400' : 'text-gray-500'; ?>">
                                    <?php echo $slotsAvailable === 1 ? 'Platz frei' : 'Plätze frei'; ?>
                                </div>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">von <?php echo $totalSlotsNeeded; ?> gesamt</div>
                            </div>
                        </div>

                        <!-- Fill progress bar -->
                        <?php if ($totalSlotsNeeded > 0): ?>
                        <div class="mt-4">
                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                                <span><?php echo $totalSlotsFilled; ?> von <?php echo $totalSlotsNeeded; ?> Plätzen besetzt</span>
                                <span class="font-semibold"><?php echo $fillPercent; ?>%</span>
                            </div>
                            <div class="h-2 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all <?php echo $fillPercent >= 100 ? 'bg-red-400' : ($fillPercent >= 70 ? 'bg-amber-400' : 'bg-ibc-green'); ?>"
                                     style="width: <?php echo min($fillPercent, 100); ?>%"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Event Description -->
                    <?php if (!empty($event['description'])): ?>
                        <div class="px-6 pt-4 pb-2 text-sm text-gray-600 dark:text-gray-400 leading-relaxed long-text">
                            <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Helper Types & Slots -->
                    <?php if (!empty($helperTypes)): ?>
                        <div class="p-6 space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-clipboard-list text-ibc-blue"></i>
                                Verfügbare Helfer-Rollen
                            </h3>
                            
                            <?php foreach ($helperTypes as $helperType): ?>
                                <div class="rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden">
                                    <!-- Role Header -->
                                    <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/60 flex items-center gap-3">
                                        <div class="w-7 h-7 rounded-lg bg-ibc-blue/10 flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-user-tag text-ibc-blue text-xs"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-gray-800 dark:text-gray-100 text-sm">
                                                <?php echo htmlspecialchars($helperType['title']); ?>
                                            </div>
                                            <?php if (!empty($helperType['description'])): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 leading-snug">
                                                    <?php echo htmlspecialchars($helperType['description']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Slots as cards -->
                                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <?php foreach ($helperType['slots'] as $slot): ?>
                                            <?php
                                            $slotStart = new DateTime($slot['start_time']);
                                            $slotEnd = new DateTime($slot['end_time']);
                                            $eventDate    = $startDate->format('Y-m-d');
                                            $eventEndDate = $endDate->format('Y-m-d');
                                            
                                            $isAufbau = $slotStart->format('Y-m-d') < $eventDate;
                                            $isAbbau  = $slotEnd->format('Y-m-d')   > $eventEndDate;
                                            $showSlotDate = $slotStart->format('Y-m-d') !== $eventDate || $slotEnd->format('Y-m-d') !== $eventDate;
                                            
                                            if ($showSlotDate) {
                                                $slotTimeRange = $slotStart->format('d.m. H:i') . ' – ' . $slotEnd->format('d.m. H:i');
                                            } else {
                                                $slotTimeRange = $slotStart->format('H:i') . ' – ' . $slotEnd->format('H:i');
                                            }
                                            
                                            $isFull = $slot['signups_count'] >= $slot['quantity_needed'];
                                            $isUserSignedUp = in_array($slot['id'], $mySlotIds);
                                            ?>
                                            <div class="px-4 py-3 flex items-center justify-between gap-4 flex-wrap bg-white dark:bg-gray-900">
                                                <!-- Time + type -->
                                                <div class="flex items-center gap-2 flex-wrap min-w-0">
                                                    <i class="fas fa-clock text-ibc-blue text-sm flex-shrink-0"></i>
                                                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($slotTimeRange); ?></span>
                                                    <?php if ($isAufbau): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold bg-ibc-blue/10 text-ibc-blue">
                                                            <i class="fas fa-tools text-[10px]"></i>Aufbau
                                                        </span>
                                                    <?php elseif ($isAbbau): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-semibold bg-ibc-accent/10 text-ibc-accent">
                                                            <i class="fas fa-box text-[10px]"></i>Abbau
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <!-- Capacity + Status -->
                                                <div class="flex items-center gap-3 flex-shrink-0">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                                        <?php echo $slot['signups_count']; ?>/<?php echo $slot['quantity_needed']; ?>
                                                    </span>
                                                    <?php if ($isUserSignedUp): ?>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-ibc-blue/10 text-ibc-blue border border-ibc-blue/20">
                                                            <i class="fas fa-check text-[10px]"></i>Angemeldet
                                                        </span>
                                                    <?php elseif ($isFull): ?>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800">
                                                            <i class="fas fa-times text-[10px]"></i>Voll
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 dark:bg-green-900/30 text-ibc-green border border-green-200 dark:border-green-800">
                                                            <i class="fas fa-check-circle text-[10px]"></i>Verfügbar
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
                    
                    <!-- Action Button -->
                    <div class="px-6 pb-6 flex justify-end">
                        <a href="view.php?id=<?php echo $event['id']; ?>" 
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-ibc-green hover:bg-ibc-green-dark text-white rounded-xl font-semibold text-sm transition-all shadow-sm hover:shadow-md">
                            <i class="fas fa-eye"></i>
                            Event ansehen & anmelden
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
