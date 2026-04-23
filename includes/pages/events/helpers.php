<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Event.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user     = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';
$events   = [];
$mySlotIds = [];

if ($userRole !== 'alumni') {
    $events = Event::getEvents(['needs_helpers' => true, 'include_helpers' => true], $userRole);
    $events = array_filter($events, fn($e) => in_array($e['status'], ['open','planned','running','closed']));

    $userSignups = Event::getUserSignups($user['id']);
    $mySlotIds   = array_column($userSignups, 'slot_id');
}

$title = 'Helfersystem - IBC Intranet';
ob_start();
?>
<style>
/* ── Helfersystem Page ────────────────────────────────────────── */
.hs-event-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    transition: box-shadow 0.22s ease, border-color 0.22s ease;
    margin-bottom: 1.25rem;
}
.hs-event-card:hover {
    box-shadow: 0 8px 30px rgba(0,166,81,0.1);
    border-color: var(--ibc-green);
}
.hs-event-header {
    padding: 1.25rem 1.25rem 1rem;
    border-bottom: 1px solid var(--border-color);
}
.hs-slots-badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 0.75rem;
    padding: 0.625rem 1rem;
    text-align: center;
    flex-shrink: 0;
    min-width: 6rem;
}
.hs-slots-badge--open {
    background: rgba(0,166,81,0.08);
    border: 1.5px solid rgba(0,166,81,0.2);
}
.hs-slots-badge--full {
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
}
.hs-progress-bar {
    height: 0.5rem;
    background: var(--bg-body);
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 0.875rem;
}
.hs-progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.5s ease;
}
.hs-role-block {
    border: 1.5px solid var(--border-color);
    border-radius: 0.75rem;
    overflow: hidden;
}
.hs-role-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: var(--bg-body);
    border-bottom: 1px solid var(--border-color);
}
.hs-slot-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-card);
}
.hs-slot-row:last-child { border-bottom: none; }
.hs-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.65rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid transparent;
}
.hs-pill--registered {
    background: rgba(0,102,179,0.08);
    color: var(--ibc-blue);
    border-color: rgba(0,102,179,0.2);
}
.hs-pill--full {
    background: rgba(239,68,68,0.08);
    color: #ef4444;
    border-color: rgba(239,68,68,0.2);
}
.hs-pill--open {
    background: rgba(0,166,81,0.08);
    color: var(--ibc-green);
    border-color: rgba(0,166,81,0.2);
}
.hs-pill--aufbau {
    background: rgba(0,102,179,0.08);
    color: var(--ibc-blue);
    border-color: rgba(0,102,179,0.15);
}
.hs-pill--abbau {
    background: rgba(99,102,241,0.08);
    color: #6366f1;
    border-color: rgba(99,102,241,0.15);
}
.hs-meta-row {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.hs-info-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    padding: 4rem 2rem;
    text-align: center;
}
@keyframes hsCardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: none; }
}
.hs-event-card { animation: hsCardIn 0.3s ease both; }
.hs-event-card:nth-child(2) { animation-delay: 0.08s; }
.hs-event-card:nth-child(3) { animation-delay: 0.14s; }
.hs-event-card:nth-child(n+4) { animation-delay: 0.18s; }
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,var(--ibc-green),#15803d);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,166,81,0.3);flex-shrink:0;">
            <i class="fas fa-hands-helping" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div>
            <h1 style="font-size:1.625rem;font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Helfersystem</h1>
            <p style="font-size:0.875rem;color:var(--text-muted);margin:0.125rem 0 0;">Unterstütze unser Team bei bevorstehenden Events</p>
        </div>
    </div>
</div>

<?php if ($userRole === 'alumni'): ?>
<!-- ── Alumni Info ─────────────────────────────────────────────── -->
<div class="hs-info-card">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(0,102,179,0.07);border:1.5px solid rgba(0,102,179,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-info-circle" style="font-size:1.75rem;color:var(--ibc-blue);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Information für Alumni</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">Als Alumni-Mitglied hast Du keinen Zugriff auf das Helfersystem.</p>
</div>

<?php elseif (empty($events)): ?>
<!-- ── No Events ───────────────────────────────────────────────── -->
<div class="hs-info-card">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(0,166,81,0.07);border:1.5px solid rgba(0,166,81,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-check-circle" style="font-size:1.75rem;color:var(--ibc-green);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Keine Helfer benötigt</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">Aktuell werden für keine Events Helfer gesucht.</p>
</div>

<?php else: ?>
<!-- ── Recruitment Banner ─────────────────────────────────────── -->
<div style="position:relative;overflow:hidden;border-radius:1rem;background:linear-gradient(135deg,var(--ibc-green),#15803d 50%,#0e4d2b);padding:1.75rem 1.5rem;margin-bottom:1.75rem;box-shadow:0 6px 24px rgba(0,166,81,0.22);">
    <div style="position:relative;z-index:1;">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
            <div style="width:2.5rem;height:2.5rem;border-radius:0.75rem;background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fas fa-bullhorn" style="color:#fff;font-size:1rem;" aria-hidden="true"></i>
            </div>
            <h2 style="font-size:1.25rem;font-weight:800;color:#fff;margin:0;">Wir suchen Helfer!</h2>
        </div>
        <p style="color:rgba(255,255,255,0.88);font-size:0.875rem;line-height:1.6;margin:0 0 1rem;max-width:36rem;">
            Deine Mithilfe ist gefragt! Für die folgenden Events suchen wir noch tatkräftige Unterstützung.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.625rem;">
            <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.875rem;background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:9999px;font-size:0.8125rem;font-weight:600;color:#fff;">
                <i class="fas fa-calendar-check" aria-hidden="true"></i>
                <strong><?php echo count($events); ?></strong>&nbsp;<?php echo count($events) === 1 ? 'Event' : 'Events'; ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.35rem 0.875rem;background:rgba(255,255,255,0.18);backdrop-filter:blur(4px);border-radius:9999px;font-size:0.8125rem;font-weight:600;color:#fff;">
                <i class="fas fa-users" aria-hidden="true"></i>
                Verschiedene Rollen verfügbar
            </span>
        </div>
    </div>
    <!-- Decorative circles -->
    <div style="position:absolute;top:-2rem;right:-2rem;width:9rem;height:9rem;border-radius:50%;background:rgba(255,255,255,0.06);pointer-events:none;"></div>
    <div style="position:absolute;bottom:-3rem;right:4rem;width:12rem;height:12rem;border-radius:50%;background:rgba(255,255,255,0.05);pointer-events:none;"></div>
</div>

<!-- ── Events List ────────────────────────────────────────────── -->
<?php foreach ($events as $event):
    $helperTypes     = $event['helper_types'] ?? [];
    $totalNeeded     = 0;
    $totalFilled     = 0;
    foreach ($helperTypes as $ht) {
        foreach ($ht['slots'] as $sl) {
            $totalNeeded += (int)$sl['quantity_needed'];
            $totalFilled += (int)$sl['signups_count'];
        }
    }
    $slotsAvail  = $totalNeeded - $totalFilled;
    $fillPct     = $totalNeeded > 0 ? round($totalFilled / $totalNeeded * 100) : 0;
    $startDate   = new DateTime($event['start_time']);
    $endDate     = new DateTime($event['end_time']);
?>
<div class="hs-event-card">
    <!-- Event Header -->
    <div class="hs-event-header">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:0.875rem;">
            <div style="flex:1;min-width:0;">
                <h2 style="font-size:1.0625rem;font-weight:800;color:var(--text-main);line-height:1.3;margin:0 0 0.625rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                    <?php echo htmlspecialchars($event['title']); ?>
                </h2>
                <div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
                    <span class="hs-meta-row">
                        <i class="fas fa-calendar" style="color:var(--ibc-blue);font-size:0.7rem;" aria-hidden="true"></i>
                        <?php echo $startDate->format('d.m.Y, H:i'); ?> Uhr
                    </span>
                    <?php if (!empty($event['location'])): ?>
                    <span class="hs-meta-row">
                        <i class="fas fa-map-marker-alt" style="color:var(--ibc-green);font-size:0.7rem;" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($event['location']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Slots Summary -->
            <div class="hs-slots-badge <?php echo $slotsAvail > 0 ? 'hs-slots-badge--open' : 'hs-slots-badge--full'; ?>">
                <span style="font-size:1.5rem;font-weight:800;line-height:1;color:<?php echo $slotsAvail > 0 ? 'var(--ibc-green)' : 'var(--text-muted)'; ?>;">
                    <?php echo $slotsAvail; ?>
                </span>
                <span style="font-size:0.6875rem;font-weight:600;color:<?php echo $slotsAvail > 0 ? 'var(--ibc-green)' : 'var(--text-muted)'; ?>;margin-top:0.1rem;">
                    <?php echo $slotsAvail === 1 ? 'Platz frei' : 'Plätze frei'; ?>
                </span>
                <span style="font-size:0.625rem;color:var(--text-muted);margin-top:0.1rem;">
                    von <?php echo $totalNeeded; ?> gesamt
                </span>
            </div>
        </div>

        <!-- Progress bar -->
        <?php if ($totalNeeded > 0): ?>
        <div style="margin-top:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.75rem;color:var(--text-muted);margin-bottom:0.375rem;">
                <span><?php echo $totalFilled; ?> von <?php echo $totalNeeded; ?> Plätzen besetzt</span>
                <span style="font-weight:700;"><?php echo $fillPct; ?>%</span>
            </div>
            <div class="hs-progress-bar">
                <div class="hs-progress-fill"
                     style="width:<?php echo min($fillPct, 100); ?>%;background:<?php echo $fillPct >= 100 ? '#ef4444' : ($fillPct >= 70 ? '#f59e0b' : 'var(--ibc-green)'); ?>;">
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Description -->
    <?php if (!empty($event['description'])): ?>
    <div style="padding:1rem 1.25rem 0.5rem;font-size:0.8125rem;color:var(--text-muted);line-height:1.6;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
        <?php echo htmlspecialchars($event['description']); ?>
    </div>
    <?php endif; ?>

    <!-- Helper Roles -->
    <?php if (!empty($helperTypes)): ?>
    <div style="padding:1rem 1.25rem;">
        <p style="font-size:0.6875rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-muted);margin:0 0 0.75rem;display:flex;align-items:center;gap:0.4rem;">
            <i class="fas fa-clipboard-list" style="color:var(--ibc-blue);" aria-hidden="true"></i>
            Verfügbare Helfer-Rollen
        </p>
        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <?php foreach ($helperTypes as $helperType): ?>
            <div class="hs-role-block">
                <!-- Role header -->
                <div class="hs-role-header">
                    <div style="width:1.75rem;height:1.75rem;border-radius:0.5rem;background:rgba(0,102,179,0.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-user-tag" style="font-size:0.625rem;color:var(--ibc-blue);" aria-hidden="true"></i>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:0.875rem;font-weight:700;color:var(--text-main);">
                            <?php echo htmlspecialchars($helperType['title']); ?>
                        </div>
                        <?php if (!empty($helperType['description'])): ?>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo htmlspecialchars($helperType['description']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Slots -->
                <?php foreach ($helperType['slots'] as $slot):
                    $slotStart  = new DateTime($slot['start_time']);
                    $slotEnd    = new DateTime($slot['end_time']);
                    $evDay      = $startDate->format('Y-m-d');
                    $isAufbau   = $slotStart->format('Y-m-d') < $evDay;
                    $isAbbau    = $slotEnd->format('Y-m-d') > $endDate->format('Y-m-d');
                    $multiDay   = $slotStart->format('Y-m-d') !== $slotEnd->format('Y-m-d')
                               || $slotStart->format('Y-m-d') !== $evDay;
                    $timeRange  = $multiDay
                        ? $slotStart->format('d.m. H:i') . ' – ' . $slotEnd->format('d.m. H:i')
                        : $slotStart->format('H:i') . ' – ' . $slotEnd->format('H:i');
                    $isFull      = (int)$slot['signups_count'] >= (int)$slot['quantity_needed'];
                    $isSignedUp  = in_array($slot['id'], $mySlotIds);
                ?>
                <div class="hs-slot-row">
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;min-width:0;">
                        <i class="fas fa-clock" style="color:var(--ibc-blue);font-size:0.7rem;flex-shrink:0;" aria-hidden="true"></i>
                        <span style="font-size:0.875rem;font-weight:600;color:var(--text-main);"><?php echo $timeRange; ?></span>
                        <?php if ($isAufbau): ?>
                            <span class="hs-pill hs-pill--aufbau"><i class="fas fa-tools" style="font-size:0.55rem;" aria-hidden="true"></i>Aufbau</span>
                        <?php elseif ($isAbbau): ?>
                            <span class="hs-pill hs-pill--abbau"><i class="fas fa-box" style="font-size:0.55rem;" aria-hidden="true"></i>Abbau</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.625rem;flex-shrink:0;">
                        <span style="font-size:0.75rem;color:var(--text-muted);"><?php echo (int)$slot['signups_count']; ?>/<?php echo (int)$slot['quantity_needed']; ?></span>
                        <?php if ($isSignedUp): ?>
                            <span class="hs-pill hs-pill--registered"><i class="fas fa-check" style="font-size:0.55rem;" aria-hidden="true"></i>Angemeldet</span>
                        <?php elseif ($isFull): ?>
                            <span class="hs-pill hs-pill--full"><i class="fas fa-times" style="font-size:0.55rem;" aria-hidden="true"></i>Voll</span>
                        <?php else: ?>
                            <span class="hs-pill hs-pill--open"><i class="fas fa-check-circle" style="font-size:0.55rem;" aria-hidden="true"></i>Verfügbar</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Footer -->
    <div style="padding:0.875rem 1.25rem;border-top:1px solid var(--border-color);display:flex;justify-content:flex-end;">
        <a href="view.php?id=<?php echo (int)$event['id']; ?>"
           style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.25rem;background:linear-gradient(135deg,var(--ibc-green),#15803d);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;box-shadow:0 3px 12px rgba(0,166,81,0.25);transition:opacity 0.18s,transform 0.18s;"
           onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
           onmouseout="this.style.opacity='1';this.style.transform='none'">
            <i class="fas fa-eye" aria-hidden="true"></i>
            Event ansehen &amp; anmelden
        </a>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
