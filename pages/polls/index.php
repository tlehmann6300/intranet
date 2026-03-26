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

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? '';
// Prefer entra_roles (human-readable Graph group names); fall back to azure_roles for legacy accounts
$userAzureRoles = [];
if (!empty($user['entra_roles'])) {
    $decoded = json_decode($user['entra_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}
if (empty($userAzureRoles) && !empty($user['azure_roles'])) {
    $decoded = json_decode($user['azure_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}

// Get database connection
$db = Database::getContentDB();

// Fetch all active polls with hidden status
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

// Filter polls using shared helper function
$filteredPolls = filterPollsForUser($polls, $userRole, $userAzureRoles, $user['id']);

$title = 'Umfragen - IBC Intranet';

// Determine vote-status filter from URL
$voteFilter = $_GET['vote'] ?? 'all';
$validVoteFilters = ['all', 'open', 'voted'];
if (!in_array($voteFilter, $validVoteFilters)) {
    $voteFilter = 'all';
}

// Apply client-side vote-status filter
if ($voteFilter === 'open') {
    $filteredPolls = array_filter($filteredPolls, fn($p) => (int)$p['user_has_voted'] === 0);
} elseif ($voteFilter === 'voted') {
    $filteredPolls = array_filter($filteredPolls, fn($p) => (int)$p['user_has_voted'] > 0);
}

ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-poll mr-3 text-ibc-blue"></i>
                Umfragen
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Aktive Umfragen für Ihre Rolle</p>
        </div>
        
        <?php if (Auth::canCreatePolls()): ?>
        <a 
            href="<?php echo asset('pages/polls/create.php'); ?>"
            class="btn-primary no-underline w-full sm:w-auto"
        >
            <i class="fas fa-plus mr-2"></i>
            Neue Umfrage erstellen
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'no_permission'): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i>Keine Berechtigung für diese Umfrage.
    </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="mb-6 flex gap-2 flex-wrap">
        <a href="?vote=all" class="polls-filter-tab <?php echo $voteFilter === 'all'   ? 'polls-filter-tab--active text-white' : ''; ?>">
            <i class="fas fa-list mr-2"></i>Alle
        </a>
        <a href="?vote=open" class="polls-filter-tab <?php echo $voteFilter === 'open'  ? 'polls-filter-tab--active text-white' : ''; ?>">
            <i class="fas fa-clock mr-2"></i>Offen
        </a>
        <a href="?vote=voted" class="polls-filter-tab <?php echo $voteFilter === 'voted' ? 'polls-filter-tab--active text-white' : ''; ?>">
            <i class="fas fa-check-circle mr-2"></i>Abgestimmt
        </a>
    </div>

    <?php if (empty($filteredPolls)): ?>
    <!-- No polls message -->
    <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
        <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
             alt="Keine Umfragen"
             class="w-32 h-32 mx-auto mb-5 opacity-60">
        <?php if ($voteFilter === 'voted'): ?>
            <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Noch keine Umfragen abgestimmt</h3>
            <p class="text-sm text-gray-400 dark:text-gray-500">Stimme bei offenen Umfragen ab, um sie hier zu sehen.</p>
        <?php elseif ($voteFilter === 'open'): ?>
            <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Aktuell gibt es keine offenen Umfragen.</h3>
            <p class="text-sm text-gray-400 dark:text-gray-500">Schau später wieder vorbei!</p>
        <?php else: ?>
            <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Aktuell sind keine Umfragen aktiv.</h3>
            <p class="text-sm text-gray-400 dark:text-gray-500">Schau später wieder vorbei!</p>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- Polls Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($filteredPolls as $poll): ?>
        <?php
            $hasVoted   = (int)$poll['user_has_voted'] > 0;
            $isMSForms  = !empty($poll['microsoft_forms_url']);
            $statusClass = $hasVoted ? 'poll-card--voted' : 'poll-card--open';
        ?>
        <div class="poll-card card flex flex-col overflow-hidden <?php echo $statusClass; ?>">
            <!-- Status accent strip -->
            <div class="poll-card-accent"></div>

            <!-- Decorative header area -->
            <div class="poll-card-header relative overflow-hidden flex-shrink-0">
                <div class="w-full h-full flex flex-col items-center justify-center poll-card-placeholder">
                    <i class="fas fa-poll text-white/30 text-5xl mb-2"></i>
                    <span class="text-white/50 text-xs font-semibold tracking-widest uppercase">Umfrage</span>
                </div>

                <!-- Status badge overlay -->
                <div class="absolute top-3 left-3">
                    <?php if ($hasVoted): ?>
                    <span class="px-2.5 py-1 bg-ibc-green/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                        <i class="fas fa-check-circle mr-1"></i>Abgestimmt
                    </span>
                    <?php else: ?>
                    <span class="px-2.5 py-1 bg-yellow-500/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                        <i class="fas fa-clock mr-1"></i>Offen
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($isMSForms): ?>
                <div class="absolute top-3 right-3">
                    <span class="px-2.5 py-1 bg-ibc-blue/90 backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                        <i class="fas fa-external-link-alt mr-1"></i>Microsoft Forms
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Card Body -->
            <div class="flex flex-col flex-1 p-5">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 leading-snug line-clamp-2 break-words hyphens-auto">
                    <?php echo htmlspecialchars($poll['title']); ?>
                </h3>

                <?php if (!empty($poll['description'])): ?>
                <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 flex-1 mb-4">
                    <?php echo htmlspecialchars(substr($poll['description'], 0, 120)); ?><?php echo strlen($poll['description']) > 120 ? '…' : ''; ?>
                </p>
                <?php else: ?>
                <div class="flex-1"></div>
                <?php endif; ?>

                <!-- Meta Info -->
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="poll-meta-icon"><i class="fas fa-calendar-alt text-ibc-blue"></i></span>
                        <span>Endet am <?php echo formatDateTime($poll['end_date'], 'd.m.Y H:i'); ?> Uhr</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="poll-meta-icon"><i class="fas fa-users text-ibc-blue"></i></span>
                        <span><?php echo $poll['total_votes']; ?> Stimme(n)</span>
                    </div>
                </div>

                <!-- CTA -->
                <?php if ($isMSForms): ?>
                <div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700 gap-2">
                    <a 
                        href="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex-1 text-center px-4 py-2.5 bg-gradient-to-r from-ibc-blue to-ibc-blue-dark text-white rounded-lg font-semibold text-sm hover:opacity-90 transition-opacity no-underline"
                    >
                        <i class="fas fa-external-link-alt mr-1.5"></i>Zur Umfrage
                    </a>
                    <button 
                        onclick="hidePoll(<?php echo $poll['id']; ?>)"
                        class="px-3 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg font-semibold text-sm hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors"
                        title="Erledigt / Ausblenden"
                    >
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <?php else: ?>
                <div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700">
                    <?php if ($hasVoted): ?>
                    <span class="text-sm font-semibold text-ibc-green group-hover:text-ibc-green-dark transition-colors">
                        Ergebnisse ansehen
                    </span>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="min-w-[44px] min-h-[44px] rounded-full bg-ibc-green/10 flex items-center justify-center hover:bg-ibc-green transition-all group no-underline">
                        <i class="fas fa-chart-bar text-xs text-ibc-green group-hover:text-white transition-colors"></i>
                    </a>
                    <?php else: ?>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="text-sm font-semibold poll-cta-link hover:text-ibc-blue-dark transition-colors no-underline">
                        Jetzt abstimmen
                    </a>
                    <a href="<?php echo asset('pages/polls/view.php?id=' . $poll['id']); ?>"
                       class="min-w-[44px] min-h-[44px] rounded-full bg-ibc-blue/10 flex items-center justify-center hover:bg-ibc-blue transition-all group no-underline">
                        <i class="fas fa-vote-yea text-xs text-ibc-blue group-hover:text-white transition-colors"></i>
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

<style>
    /* ── Filter Tabs ────────────────────────────────── */
    .polls-filter-tab {
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
    }
    .polls-filter-tab:hover {
        border-color: var(--ibc-blue);
        color: var(--ibc-blue) !important;
        box-shadow: 0 2px 8px rgba(0,102,179,0.12);
    }
    .polls-filter-tab--active {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 100%) !important;
        color: #ffffff !important;
        border-color: transparent !important;
        box-shadow: 0 4px 14px rgba(0,102,179,0.35);
    }

    /* ── Poll Card ──────────────────────────────────── */
    .poll-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        border: 1.5px solid var(--border-color) !important;
    }
    .poll-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: var(--ibc-blue) !important;
    }

    /* Status accent strip */
    .poll-card-accent {
        height: 4px;
        flex-shrink: 0;
        background: #f59e0b;
    }
    .poll-card--voted .poll-card-accent { background: var(--ibc-green); }
    .poll-card--open  .poll-card-accent { background: #f59e0b; }

    /* ── Card Header / Placeholder ───────────────────── */
    .poll-card-header {
        height: 140px;
        background: #e5e7eb;
        flex-shrink: 0;
    }
    .poll-card-header::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.45) 100%);
        pointer-events: none;
    }
    .poll-card-placeholder {
        background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%);
    }
    .poll-card--voted .poll-card-placeholder {
        background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-dark) 60%, #004a24 100%);
    }

    /* ── Meta Icon ──────────────────────────────────── */
    .poll-meta-icon {
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
    .poll-cta-link {
        color: var(--ibc-blue);
    }

    /* ── Reduced Motion ─────────────────────────────── */
    @media (prefers-reduced-motion: reduce) {
        .poll-card { transition: none; }
        .poll-card:hover { transform: none; }
    }
</style>

<script>
function hidePoll(pollId) {
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
            // Reload page to update the list
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
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
