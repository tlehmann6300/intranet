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

$user     = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';
$filter   = $_GET['filter'] ?? 'current';
$filters  = [];
$now      = date('Y-m-d H:i:s');

if ($filter === 'current') {
    $filters['start_date'] = $now;
}

$events = Event::getEvents($filters, $userRole, $user['id']);

if ($filter === 'my_registrations') {
    $userSignups = Event::getUserSignups($user['id']);
    $myEventIds  = array_column($userSignups, 'event_id');
    $events = array_filter($events, fn($e) => in_array($e['id'], $myEventIds));
} else {
    $canViewPast = Auth::isBoard() || Auth::hasRole(['alumni_vorstand','alumni_finanz','manager']);
    if (!$canViewPast) {
        $events = array_filter($events, fn($e) => $e['end_time'] >= $now);
    }
}

$userSignups = Event::getUserSignups($user['id']);
$myEventIds  = array_column($userSignups, 'event_id');

$title = 'Events - IBC Intranet';
ob_start();
?>
<style>
/* ── Events Page ──────────────────────────────────────────────── */
.ev-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 0.125rem 0 0.375rem;
}
.ev-filter-bar::-webkit-scrollbar { display: none; }

.ev-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
    transition: opacity 0.18s, transform 0.18s, box-shadow 0.18s;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    flex-shrink: 0;
    min-height: 2.375rem;
    -webkit-tap-highlight-color: transparent;
}
.ev-chip:hover { opacity: 0.85; transform: translateY(-1px); }
.ev-chip--active {
    background: var(--ibc-blue);
    color: #fff;
    border-color: var(--ibc-blue);
    box-shadow: 0 2px 12px rgba(0,102,179,0.28);
}

/* ── Event Card ───────────────────────────────────────────────── */
.ev-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    text-decoration: none;
    color: inherit;
    position: relative;
    transition: transform 0.28s cubic-bezier(0.34,1.2,0.64,1),
                box-shadow 0.28s ease,
                border-color 0.22s ease;
}
.ev-card:hover {
    transform: translateY(-6px) scale(1.01);
    box-shadow: 0 18px 40px rgba(0,102,179,0.16), 0 6px 16px rgba(0,0,0,0.1);
    border-color: var(--ibc-blue);
}

/* Status top accent */
.ev-accent {
    height: 4px;
    width: 100%;
    flex-shrink: 0;
    background: var(--ibc-blue);
}
.ev-card--open    .ev-accent { background: var(--ibc-green); }
.ev-card--running .ev-accent { background: var(--ibc-blue); }
.ev-card--closed  .ev-accent { background: #f59e0b; }
.ev-card--past    .ev-accent { background: #9ca3af; }
.ev-card--draft   .ev-accent { background: #9ca3af; }

/* ── Image Area ──────────────────────────────────────────────── */
.ev-img-wrap {
    position: relative;
    overflow: hidden;
    height: 13rem;
    flex-shrink: 0;
    background: #e5e7eb;
}
.ev-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}
.ev-card:hover .ev-img-wrap img { transform: scale(1.06); }
.ev-img-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom,
        rgba(0,0,0,0.26) 0%,
        rgba(0,0,0,0.1)  40%,
        rgba(0,0,0,0.52) 100%);
    pointer-events: none;
}
.ev-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--ibc-blue), #0044aa 60%, #001f3a);
}
.ev-card--open    .ev-placeholder { background: linear-gradient(135deg,var(--ibc-green),#15803d 60%,#004a24); }
.ev-card--past    .ev-placeholder { background: linear-gradient(135deg,#374151,#1f2937); }
.ev-card--draft   .ev-placeholder { background: linear-gradient(135deg,#6b7280,#374151); }

/* ── Overlay badge ───────────────────────────────────────────── */
.ev-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    white-space: nowrap;
}
.ev-badge--open    { background: rgba(0,166,81,0.88);  color: #fff; }
.ev-badge--running { background: rgba(0,102,179,0.88); color: #fff; }
.ev-badge--closed  { background: rgba(245,158,11,0.88);color: #fff; }
.ev-badge--past    { background: rgba(75,85,99,0.82);  color: #fff; }
.ev-badge--draft   { background: rgba(75,85,99,0.82);  color: #fff; }
.ev-badge--extern  { background: rgba(99,102,241,0.88);color: #fff; }
.ev-badge--registered { background: rgba(0,166,81,0.88); color: #fff; }
.ev-badge--countdown  { background: rgba(0,0,0,0.6);   color: #fff; }

/* ── Date Chip ───────────────────────────────────────────────── */
.ev-date-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    background: rgba(255,255,255,0.93);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border-radius: 0.75rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.22);
    padding: 0.3rem 0.6rem;
    min-width: 44px;
    text-align: center;
    line-height: 1;
}
.dark-mode .ev-date-chip { background: rgba(26,31,46,0.93); }
.ev-date-chip-month {
    font-size: 0.625rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--ibc-blue);
}
.ev-date-chip-day {
    font-size: 1.375rem;
    font-weight: 800;
    color: #111827;
    line-height: 1.1;
}
.dark-mode .ev-date-chip-day { color: #f1f5f9; }

/* ── Card Body ────────────────────────────────────────────────── */
.ev-meta-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.ev-meta-icon {
    width: 1.125rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ev-arrow-circle {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,102,179,0.1);
    transition: background 0.22s, transform 0.22s cubic-bezier(0.34,1.56,0.64,1);
    flex-shrink: 0;
}
.ev-card:hover .ev-arrow-circle {
    background: var(--ibc-blue);
    transform: scale(1.15);
}
.ev-arrow-icon {
    font-size: 0.7rem;
    color: var(--ibc-blue);
    transition: color 0.22s;
}
.ev-card:hover .ev-arrow-icon { color: #fff; }

/* ── Stagger Animations ──────────────────────────────────────── */
@keyframes evCardIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: none; }
}
.ev-card { animation: evCardIn 0.32s ease both; }
.ev-card:nth-child(2) { animation-delay: 0.06s; }
.ev-card:nth-child(3) { animation-delay: 0.12s; }
.ev-card:nth-child(4) { animation-delay: 0.18s; }
.ev-card:nth-child(5) { animation-delay: 0.22s; }
.ev-card:nth-child(6) { animation-delay: 0.26s; }
.ev-card:nth-child(n+7) { animation-delay: 0.28s; }

/* ── Empty State ─────────────────────────────────────────────── */
.ev-empty {
    background: var(--bg-card);
    border: 2px dashed var(--border-color);
    border-radius: 1rem;
    padding: 4rem 2rem;
    text-align: center;
}

@media (prefers-reduced-motion: reduce) {
    .ev-card, .ev-card:nth-child(n) { animation: none; }
    .ev-card:hover { transform: none; }
}
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,var(--ibc-blue),#0044aa);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,102,179,0.3);flex-shrink:0;">
            <i class="fas fa-calendar-alt" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div style="min-width:0;">
            <h1 style="font-size:clamp(1.25rem,4vw,1.625rem);font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Events</h1>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0.125rem 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Entdecke kommende Events und melde Dich an</p>
        </div>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;flex-shrink:0;">
        <?php if (Auth::isBoard() || Auth::hasRole(['alumni_vorstand'])): ?>
        <a href="statistics.php"
           style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.1rem;background:var(--bg-card);border:1.5px solid var(--border-color);color:var(--text-muted);border-radius:0.75rem;font-size:0.875rem;font-weight:600;text-decoration:none;white-space:nowrap;transition:border-color 0.18s,color 0.18s;"
           onmouseover="this.style.borderColor='var(--ibc-blue)';this.style.color='var(--ibc-blue)'"
           onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
            <i class="fas fa-chart-bar" aria-hidden="true"></i>
            Statistiken
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter','alumni_vorstand'])): ?>
        <a href="edit.php?new=1"
           style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.1rem;background:linear-gradient(135deg,var(--ibc-blue),#0044aa);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 3px 12px rgba(0,102,179,0.3);transition:opacity 0.18s,transform 0.18s;"
           onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
           onmouseout="this.style.opacity='1';this.style.transform='none'">
            <i class="fas fa-plus" aria-hidden="true"></i>
            Neues Event
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filter Bar ─────────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:0.875rem;padding:0.875rem 1rem;margin-bottom:1.75rem;">
    <div class="ev-filter-bar" role="navigation" aria-label="Events filtern">
        <a href="?filter=current"
           class="ev-chip <?php echo $filter === 'current' ? 'ev-chip--active' : ''; ?>">
            <i class="fas fa-calendar-day" style="font-size:0.7rem;" aria-hidden="true"></i>
            Aktuell
        </a>
        <a href="?filter=my_registrations"
           class="ev-chip <?php echo $filter === 'my_registrations' ? 'ev-chip--active' : ''; ?>">
            <i class="fas fa-user-check" style="font-size:0.7rem;" aria-hidden="true"></i>
            Meine Anmeldungen
        </a>
    </div>
</div>

<?php if (empty($events)): ?>
<!-- ── Empty State ────────────────────────────────────────────── -->
<div class="ev-empty">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(0,102,179,0.07);border:1.5px solid rgba(0,102,179,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-calendar-alt" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">
        <?php echo $filter === 'my_registrations' ? 'Keine Anmeldungen gefunden' : 'Aktuell stehen keine Events an.'; ?>
    </p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">
        <?php echo $filter === 'my_registrations' ? 'Du hast Dich noch für keine Events angemeldet.' : 'Schau später wieder vorbei!'; ?>
    </p>
</div>

<?php else: ?>
<!-- ── Events Grid ────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,20rem),1fr));gap:1.125rem;">
    <?php foreach ($events as $event):
        $startTs   = strtotime($event['start_time']);
        $nowTs     = time();
        $isUpcoming = $startTs > $nowTs;
        $isRegistered = in_array($event['id'], $myEventIds);

        // Validate image
        $hasImage = false;
        if (!empty($event['image_path'])) {
            $fp = realpath(__DIR__ . '/../../' . $event['image_path']);
            $bd = realpath(__DIR__ . '/../../');
            $hasImage = $fp && $bd && str_starts_with($fp, $bd) && file_exists($fp);
        }

        // Countdown
        $countdown = '';
        if ($isUpcoming) {
            $diff  = $startTs - $nowTs;
            $days  = floor($diff / 86400);
            $hours = floor(($diff % 86400) / 3600);
            $countdown = $days > 0 ? "Noch {$days} Tag" . ($days !== 1 ? 'e' : '') . ", {$hours} Std" : "Noch {$hours} Std";
        }

        $germanMonths = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
        $monthAbbr = $germanMonths[date('n', $startTs) - 1];
        $status = $event['status'];
    ?>
    <a href="view.php?id=<?php echo (int)$event['id']; ?>"
       class="ev-card ev-card--<?php echo htmlspecialchars($status); ?>">

        <!-- Accent bar -->
        <div class="ev-accent"></div>

        <!-- Image / Placeholder -->
        <div class="ev-img-wrap">
            <?php if ($hasImage): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $event['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($event['title']); ?>"
                     loading="lazy">
            <?php else: ?>
                <div class="ev-placeholder">
                    <i class="fas fa-calendar-alt" style="font-size:3rem;color:rgba(255,255,255,0.25);margin-bottom:0.5rem;" aria-hidden="true"></i>
                    <span style="font-size:0.6875rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.45);">Event</span>
                </div>
            <?php endif; ?>

            <!-- Top-left badges -->
            <div style="position:absolute;top:0.75rem;left:0.75rem;display:flex;flex-direction:column;gap:0.3rem;z-index:2;">
                <?php if ($status === 'draft'): ?>
                    <span class="ev-badge ev-badge--draft"><i class="fas fa-pencil-alt" style="font-size:0.55rem;"></i>Entwurf</span>
                <?php elseif ($status === 'open'): ?>
                    <span class="ev-badge ev-badge--open"><i class="fas fa-door-open" style="font-size:0.55rem;"></i>Anmeldung offen</span>
                <?php elseif ($status === 'running'): ?>
                    <span class="ev-badge ev-badge--running"><i class="fas fa-play" style="font-size:0.55rem;"></i>Läuft gerade</span>
                <?php elseif ($status === 'past'): ?>
                    <span class="ev-badge ev-badge--past"><i class="fas fa-flag-checkered" style="font-size:0.55rem;"></i>Beendet</span>
                <?php elseif ($status === 'closed'): ?>
                    <span class="ev-badge ev-badge--closed"><i class="fas fa-lock" style="font-size:0.55rem;"></i>Geschlossen</span>
                <?php endif; ?>
                <?php if (!empty($event['is_external'])): ?>
                    <span class="ev-badge ev-badge--extern"><i class="fas fa-external-link-alt" style="font-size:0.55rem;"></i>Extern</span>
                <?php endif; ?>
            </div>

            <!-- Top-right: registered badge -->
            <?php if ($isRegistered): ?>
            <div style="position:absolute;top:0.75rem;right:0.75rem;z-index:2;">
                <span class="ev-badge ev-badge--registered"><i class="fas fa-check" style="font-size:0.55rem;"></i>Angemeldet</span>
            </div>
            <?php endif; ?>

            <!-- Bottom-left: countdown -->
            <?php if ($countdown): ?>
            <div style="position:absolute;bottom:0.75rem;left:0.75rem;z-index:2;">
                <span class="ev-badge ev-badge--countdown"><i class="fas fa-hourglass-half" style="font-size:0.55rem;"></i><?php echo $countdown; ?></span>
            </div>
            <?php endif; ?>

            <!-- Bottom-right: date chip -->
            <div style="position:absolute;bottom:0.75rem;right:0.75rem;z-index:2;">
                <div class="ev-date-chip">
                    <span class="ev-date-chip-month"><?php echo $monthAbbr; ?></span>
                    <span class="ev-date-chip-day"><?php echo date('d', $startTs); ?></span>
                </div>
            </div>
        </div>

        <!-- Card Body -->
        <div style="display:flex;flex-direction:column;flex:1;padding:1.125rem 1.125rem 1rem;">
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.35;margin:0 0 0.75rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                <?php echo htmlspecialchars($event['title']); ?>
            </h3>

            <!-- Meta rows -->
            <div style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:0.875rem;">
                <div class="ev-meta-row">
                    <span class="ev-meta-icon"><i class="fas fa-calendar" style="color:var(--ibc-blue);font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span><?php
                        $sd = new DateTime($event['start_time']);
                        $ed = new DateTime($event['end_time']);
                        if ($sd->format('d.m.Y') === $ed->format('d.m.Y')) {
                            echo $sd->format('d.m.Y, H:i') . ' – ' . $ed->format('H:i') . ' Uhr';
                        } else {
                            echo $sd->format('d.m.Y, H:i') . ' – ' . $ed->format('d.m.Y, H:i') . ' Uhr';
                        }
                    ?></span>
                </div>
                <?php if (!empty($event['location'])): ?>
                <div class="ev-meta-row">
                    <span class="ev-meta-icon"><i class="fas fa-map-marker-alt" style="color:var(--ibc-blue);font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($event['location']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($event['needs_helpers']) && $userRole !== 'alumni'): ?>
                <div class="ev-meta-row">
                    <span class="ev-meta-icon"><i class="fas fa-hands-helping" style="color:var(--ibc-green);font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span style="color:var(--ibc-green);font-weight:600;">Helfer benötigt</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Description excerpt -->
            <?php if (!empty($event['description'])): ?>
            <p style="font-size:0.8125rem;color:var(--text-muted);line-height:1.55;margin:0 0 0.875rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;flex:1;">
                <?php echo htmlspecialchars(mb_substr($event['description'], 0, 120)) . (mb_strlen($event['description']) > 120 ? '…' : ''); ?>
            </p>
            <?php else: ?>
            <div style="flex:1;"></div>
            <?php endif; ?>

            <!-- Footer CTA -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding-top:0.75rem;border-top:1px solid var(--border-color);margin-top:auto;">
                <span style="font-size:0.8125rem;font-weight:700;color:var(--ibc-blue);">Details ansehen</span>
                <div class="ev-arrow-circle">
                    <i class="fas fa-arrow-right ev-arrow-icon" aria-hidden="true"></i>
                </div>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
