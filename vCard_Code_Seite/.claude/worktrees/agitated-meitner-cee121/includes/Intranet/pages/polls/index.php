<?php
/**
 * Polls - List all active polls
 * Access: All authenticated users (filtered by target_groups)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user     = Auth::user();
$userRole = $user['role'] ?? '';

// Prefer entra_roles (human-readable Graph group names); fall back to azure_roles for legacy accounts
$userAzureRoles = [];
if (!empty($user['entra_roles'])) {
    $decoded = json_decode($user['entra_roles'], true);
    if (is_array($decoded)) $userAzureRoles = $decoded;
}
if (empty($userAzureRoles) && !empty($user['azure_roles'])) {
    $decoded = json_decode($user['azure_roles'], true);
    if (is_array($decoded)) $userAzureRoles = $decoded;
}

$db   = Database::getContentDB();
$stmt = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id AND user_id = ?) as user_has_voted,
           (SELECT COUNT(*) FROM poll_votes WHERE poll_id = p.id) as total_votes,
           (SELECT COUNT(*) FROM poll_hidden_by_user WHERE poll_id = p.id AND user_id = ?) as user_has_hidden
      FROM polls p
     WHERE p.is_active = 1 AND p.end_date > NOW()
     ORDER BY p.created_at DESC
");
$stmt->execute([$user['id'], $user['id']]);
$polls = $stmt->fetchAll();

$filteredPolls = filterPollsForUser($polls, $userRole, $userAzureRoles, $user['id']);

$title = 'Umfragen - IBC Intranet';

$voteFilter = $_GET['vote'] ?? 'all';
if (!in_array($voteFilter, ['all', 'open', 'voted'])) $voteFilter = 'all';

if ($voteFilter === 'open')  $filteredPolls = array_filter($filteredPolls, fn($p) => (int)$p['user_has_voted'] === 0);
if ($voteFilter === 'voted') $filteredPolls = array_filter($filteredPolls, fn($p) => (int)$p['user_has_voted'] > 0);

ob_start();
?>

<style>
/* ── Umfragen Module ── */
.plls-header-icon {
    width: 3rem; height: 3rem;
    border-radius: 0.875rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-blue-dark));
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(0,102,179,0.3);
    flex-shrink: 0;
}

/* ── Filter Tabs ── */
.polls-filter-bar {
    display: flex; gap: 0.5rem; flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.polls-filter-tab {
    display: inline-flex; align-items: center;
    padding: 0.5rem 1.375rem;
    border-radius: 9999px; font-weight: 600; font-size: 0.875rem;
    transition: all .25s ease;
    background: var(--bg-card); color: var(--text-muted);
    border: 1.5px solid var(--border-color);
    text-decoration: none !important; cursor: pointer;
}
.polls-filter-tab:hover {
    border-color: var(--ibc-blue);
    color: var(--ibc-blue) !important;
    box-shadow: 0 2px 8px rgba(0,102,179,0.12);
}
.polls-filter-tab--active {
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-blue-dark)) !important;
    color: #fff !important;
    border-color: transparent !important;
    box-shadow: 0 4px 14px rgba(0,102,179,0.35);
}

/* Create poll button */
.plls-create-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1.25rem; border-radius: 0.75rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-blue-dark));
    color: #fff; font-weight: 600; font-size: 0.9375rem;
    text-decoration: none;
    box-shadow: 0 3px 12px rgba(0,102,179,0.3);
    transition: opacity .2s, transform .15s;
    white-space: nowrap;
}
.plls-create-btn:hover { opacity: .9; transform: scale(1.03); color: #fff; }

/* ── Poll Card ── */
.poll-card {
    background-color: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1.125rem;
    display: flex; flex-direction: column;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    transition: transform .25s ease, box-shadow .25s ease, border-color .2s;
    animation: pollCardIn .45s ease both;
}
.poll-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,102,179,0.14);
    border-color: var(--ibc-blue);
}
@keyframes pollCardIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.poll-card:nth-child(1)  { animation-delay: .05s }
.poll-card:nth-child(2)  { animation-delay: .10s }
.poll-card:nth-child(3)  { animation-delay: .15s }
.poll-card:nth-child(4)  { animation-delay: .20s }
.poll-card:nth-child(5)  { animation-delay: .25s }
.poll-card:nth-child(6)  { animation-delay: .30s }
.poll-card:nth-child(n+7){ animation-delay: .35s }

/* Status accent */
.poll-card-accent {
    height: 4px; flex-shrink: 0;
    background: #f59e0b;
}
.poll-card--voted .poll-card-accent { background: var(--ibc-green); }

/* Decorative header */
.poll-card-header {
    height: 9rem; flex-shrink: 0; position: relative; overflow: hidden;
    background: #e5e7eb;
}
.poll-card-header::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0.06) 0%, rgba(0,0,0,0.42) 100%);
    pointer-events: none;
}
.poll-card-placeholder {
    width: 100%; height: 100%;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%);
}
.poll-card--voted .poll-card-placeholder {
    background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-dark) 60%, #004a24 100%);
}
.poll-card-badge {
    position: absolute; top: 0.75rem;
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.3rem 0.75rem; border-radius: 999px;
    font-size: 0.72rem; font-weight: 700; z-index: 1;
}
.poll-card-badge--left  { left: 0.75rem; }
.poll-card-badge--right { right: 0.75rem; }
.poll-card-badge--voted   { background: rgba(0,160,80,0.9);  color: #fff; }
.poll-card-badge--open    { background: rgba(245,158,11,0.9); color: #fff; }
.poll-card-badge--msforms { background: rgba(0,102,179,0.9);  color: #fff; }

/* Card body */
.poll-card-body { padding: 1.125rem; display: flex; flex-direction: column; flex: 1; }
.poll-card-title {
    font-size: 1rem; font-weight: 700; color: var(--text-main);
    margin: 0 0 0.625rem; line-height: 1.35;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    word-break: break-word;
}
.poll-card-desc {
    font-size: 0.8125rem; color: var(--text-muted);
    margin: 0 0 1rem; flex: 1; line-height: 1.5;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.poll-card-meta {
    display: flex; flex-direction: column; gap: 0.4rem;
    margin-bottom: 1rem;
}
.poll-meta-row {
    display: flex; align-items: center; gap: 0.5rem;
    font-size: 0.8125rem; color: var(--text-muted);
}
.poll-meta-icon {
    width: 1.375rem; display: inline-flex; align-items: center;
    justify-content: center; flex-shrink: 0; color: var(--ibc-blue);
}

/* Card footer / CTA */
.poll-card-footer {
    padding-top: 0.875rem;
    border-top: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between;
}
.poll-cta-text { font-size: 0.875rem; font-weight: 600; color: var(--ibc-blue); }
.poll-cta-text--voted { color: var(--ibc-green); }
.poll-arrow-btn {
    min-width: 2.75rem; min-height: 2.75rem; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; transition: background .2s;
}
.poll-arrow-btn--blue  { background: rgba(0,102,179,0.1); }
.poll-arrow-btn--green { background: rgba(0,160,80,0.1);  }
.poll-arrow-btn--blue  i { color: var(--ibc-blue);  font-size: 0.8125rem; }
.poll-arrow-btn--green i { color: var(--ibc-green); font-size: 0.8125rem; }
.poll-arrow-btn--blue:hover  { background: var(--ibc-blue);  }
.poll-arrow-btn--green:hover { background: var(--ibc-green); }
.poll-arrow-btn--blue:hover  i { color: #fff; }
.poll-arrow-btn--green:hover i { color: #fff; }

/* MS Forms go button */
.poll-forms-btn {
    flex: 1; text-align: center; text-decoration: none;
    padding: 0.5625rem 1rem; border-radius: 0.625rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-blue-dark));
    color: #fff; font-weight: 700; font-size: 0.8125rem;
    transition: opacity .2s;
}
.poll-forms-btn:hover { opacity: .9; color: #fff; }
.poll-hide-btn {
    padding: 0.5625rem 0.875rem; border-radius: 0.625rem; border: none;
    background: rgba(100,116,139,0.12); color: var(--text-muted);
    font-size: 0.8125rem; cursor: pointer; transition: background .2s;
}
.poll-hide-btn:hover { background: rgba(100,116,139,0.22); }

/* Empty state */
.polls-empty {
    background-color: var(--bg-card);
    border: 1.5px dashed var(--border-color);
    border-radius: 1.125rem; padding: 4rem 2rem; text-align: center;
}

/* Error notice */
.polls-error {
    margin-bottom: 1.5rem; padding: 0.875rem 1.125rem;
    border-radius: 0.875rem; border: 1px solid rgba(220,38,38,0.3);
    background: rgba(220,38,38,0.08); color: #b91c1c;
    display: flex; align-items: center; gap: 0.625rem; font-size: 0.9rem;
}

@media (prefers-reduced-motion: reduce) {
    .poll-card { transition: none; animation: none; }
    .poll-card:hover { transform: none; }
}
</style>

<div class="max-w-7xl mx-auto">

    <?php if (isset($_GET['error']) && $_GET['error'] === 'no_permission'): ?>
    <div class="polls-error">
        <i class="fas fa-exclamation-circle flex-shrink-0"></i>
        Keine Berechtigung für diese Umfrage.
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div style="margin-bottom:2rem; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
        <div style="display:flex; align-items:center; gap:0.875rem; flex:1; min-width:0;">
            <div class="plls-header-icon">
                <i class="fas fa-poll" style="color:#fff; font-size:1.1875rem;"></i>
            </div>
            <div style="min-width:0;">
                <h1 style="font-size:clamp(1.25rem,4vw,1.75rem); font-weight:800; color:var(--text-main); margin:0; line-height:1.2;">Umfragen</h1>
                <p style="color:var(--text-muted); margin:0.2rem 0 0; font-size:0.8125rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Aktive Umfragen für deine Rolle</p>
            </div>
        </div>

        <?php if (Auth::canCreatePolls()): ?>
        <a href="<?php echo asset('pages/polls/create.php'); ?>" class="plls-create-btn">
            <i class="fas fa-plus"></i>Neue Umfrage erstellen
        </a>
        <?php endif; ?>
    </div>

    <!-- Filter Tabs -->
    <div class="polls-filter-bar">
        <a href="?vote=all" class="polls-filter-tab <?php echo $voteFilter === 'all'   ? 'polls-filter-tab--active' : ''; ?>">
            <i class="fas fa-list" style="margin-right:0.4rem;"></i>Alle
        </a>
        <a href="?vote=open" class="polls-filter-tab <?php echo $voteFilter === 'open'  ? 'polls-filter-tab--active' : ''; ?>">
            <i class="fas fa-clock" style="margin-right:0.4rem;"></i>Offen
        </a>
        <a href="?vote=voted" class="polls-filter-tab <?php echo $voteFilter === 'voted' ? 'polls-filter-tab--active' : ''; ?>">
            <i class="fas fa-check-circle" style="margin-right:0.4rem;"></i>Abgestimmt
        </a>
    </div>

    <?php if (empty($filteredPolls)): ?>
    <!-- Empty State -->
    <div class="polls-empty">
        <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
             alt="Keine Umfragen"
             style="width:7rem; height:7rem; object-fit:contain; margin:0 auto 1.25rem; display:block; opacity:.55;">
        <?php if ($voteFilter === 'voted'): ?>
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-main); margin:0 0 0.375rem;">Noch keine Umfragen abgestimmt</h3>
            <p style="font-size:0.875rem; color:var(--text-muted); margin:0;">Stimme bei offenen Umfragen ab, um sie hier zu sehen.</p>
        <?php elseif ($voteFilter === 'open'): ?>
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-main); margin:0 0 0.375rem;">Aktuell gibt es keine offenen Umfragen.</h3>
            <p style="font-size:0.875rem; color:var(--text-muted); margin:0;">Schau später wieder vorbei!</p>
        <?php else: ?>
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-main); margin:0 0 0.375rem;">Aktuell sind keine Umfragen aktiv.</h3>
            <p style="font-size:0.875rem; color:var(--text-muted); margin:0;">Schau später wieder vorbei!</p>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- Polls Grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(17rem, 1fr)); gap:1.25rem;">
        <?php foreach ($filteredPolls as $poll):
            $hasVoted  = (int)$poll['user_has_voted'] > 0;
            $isMSForms = !empty($poll['microsoft_forms_url']);
            $cardMod   = $hasVoted ? 'poll-card--voted' : 'poll-card--open';
        ?>
        <div class="poll-card <?php echo $cardMod; ?>">
            <div class="poll-card-accent"></div>

            <!-- Decorative Header -->
            <div class="poll-card-header">
                <div class="poll-card-placeholder">
                    <i class="fas fa-poll" style="color:rgba(255,255,255,0.25); font-size:3rem; margin-bottom:0.375rem;"></i>
                    <span style="color:rgba(255,255,255,0.45); font-size:0.6875rem; font-weight:700; letter-spacing:0.1em; text-transform:uppercase;">Umfrage</span>
                </div>

                <!-- Status badge -->
                <span class="poll-card-badge poll-card-badge--left <?php echo $hasVoted ? 'poll-card-badge--voted' : 'poll-card-badge--open'; ?>">
                    <i class="fas <?php echo $hasVoted ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                    <?php echo $hasVoted ? 'Abgestimmt' : 'Offen'; ?>
                </span>

                <?php if ($isMSForms): ?>
                <span class="poll-card-badge poll-card-badge--right poll-card-badge--msforms">
                    <i class="fas fa-external-link-alt"></i>Microsoft Forms
                </span>
                <?php endif; ?>
            </div>

            <!-- Card Body -->
            <div class="poll-card-body">
                <h3 class="poll-card-title"><?php echo htmlspecialchars($poll['title']); ?></h3>

                <?php if (!empty($poll['description'])): ?>
                <p class="poll-card-desc">
                    <?php echo htmlspecialchars(substr($poll['description'], 0, 120)); ?><?php echo strlen($poll['description']) > 120 ? '…' : ''; ?>
                </p>
                <?php else: ?>
                <div style="flex:1;"></div>
                <?php endif; ?>

                <!-- Meta -->
                <div class="poll-card-meta">
                    <div class="poll-meta-row">
                        <span class="poll-meta-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span>Endet am <?php echo formatDateTime($poll['end_date'], 'd.m.Y H:i'); ?> Uhr</span>
                    </div>
                    <div class="poll-meta-row">
                        <span class="poll-meta-icon"><i class="fas fa-users"></i></span>
                        <span><?php echo $poll['total_votes']; ?> Stimme(n)</span>
                    </div>
                </div>

                <!-- CTA -->
                <?php if ($isMSForms): ?>
                <div class="poll-card-footer" style="gap:0.5rem;">
                    <a href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="poll-forms-btn">
                        <i class="fas fa-external-link-alt" style="margin-right:0.375rem;"></i>Zur Umfrage
                    </a>
                    <button onclick="hidePoll(<?php echo $poll['id']; ?>)" class="poll-hide-btn" title="Ausblenden">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <?php else: ?>
                <div class="poll-card-footer">
                    <?php if ($hasVoted): ?>
                    <span class="poll-cta-text poll-cta-text--voted">Ergebnisse ansehen</span>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="poll-arrow-btn poll-arrow-btn--green">
                        <i class="fas fa-chart-bar"></i>
                    </a>
                    <?php else: ?>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="poll-cta-text" style="text-decoration:none;">Jetzt abstimmen</a>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="poll-arrow-btn poll-arrow-btn--blue">
                        <i class="fas fa-vote-yea"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
function hidePoll(pollId) {
    if (!confirm('Möchtest du diese Umfrage wirklich ausblenden?')) return;
    fetch('<?php echo asset('api/hide_poll.php'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ poll_id: pollId, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) window.location.reload();
        else alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
    })
    .catch(() => alert('Ein Fehler ist aufgetreten. Bitte versuche es später erneut.'));
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
