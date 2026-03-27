<?php
/**
 * Ideenbox - Idea Board with Voting
 * Access: member, candidate, head, board
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Idea.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

if (!Auth::canAccessPage('ideas')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$csrfToken = CSRFHandler::getToken();
$ideas     = Idea::getAll((int) $user['id']);

// Fetch submitter names from user DB
$userDb      = Database::getUserDB();
$userInfoMap = [];
if (!empty($ideas)) {
    $uids = array_unique(array_column($ideas, 'user_id'));
    $ph   = str_repeat('?,', count($uids) - 1) . '?';
    $stmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
    $stmt->execute($uids);
    foreach ($stmt->fetchAll() as $u) {
        $userInfoMap[$u['id']] = $u['email'];
    }
}

$statusConfig = [
    'new'         => ['label' => 'Neu',          'dot' => 'bg-sky-400',     'badge' => 'bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 ring-1 ring-sky-400/30',    'accent' => '#38bdf8'],
    'in_review'   => ['label' => 'In Prüfung',   'dot' => 'bg-amber-400',   'badge' => 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 ring-1 ring-amber-400/30', 'accent' => '#fbbf24'],
    'accepted'    => ['label' => 'Angenommen',   'dot' => 'bg-green-500',   'badge' => 'bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 ring-1 ring-green-500/30',  'accent' => '#22c55e'],
    'rejected'    => ['label' => 'Abgelehnt',    'dot' => 'bg-red-500',     'badge' => 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 ring-1 ring-red-500/30',    'accent' => '#ef4444'],
    'implemented' => ['label' => 'Umgesetzt',    'dot' => 'bg-purple-500',  'badge' => 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 ring-1 ring-purple-500/30', 'accent' => '#a855f7'],
];

$title = 'Ideenbox - IBC Intranet';
ob_start();
?>

<style>
.vote-btn {
    transition: all 0.15s ease;
}
.vote-btn:active {
    transform: scale(0.88);
}
#ideaModal.show {
    animation: fadeIn 0.18s ease;
}
#ideaModal.show > div {
    animation: slideUp 0.22s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: none; } }
</style>

<div class="max-w-7xl mx-auto">

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-lightbulb mr-3 text-yellow-500"></i>
                Ideenbox
            </h1>
            <p class="text-gray-600 dark:text-gray-300 leading-relaxed">Teile Deine Ideen – stimme ab, was umgesetzt werden soll.</p>
        </div>
        <button
            id="openIdeaModal"
            class="btn-primary w-full sm:w-auto justify-center"
        >
            <i class="fas fa-plus mr-2"></i>
            Neue Idee
        </button>
    </div>

    <!-- Idea Cards -->
    <?php if (empty($ideas)): ?>
    <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
        <i class="fas fa-lightbulb text-5xl text-yellow-400 dark:text-yellow-500 mb-4"></i>
        <p class="text-base sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Noch keine Ideen vorhanden</p>
        <p class="text-gray-500 dark:text-gray-400 mb-7 text-sm max-w-xs mx-auto">Sei der Erste und teile Deine Idee mit dem Team!</p>
        <button onclick="document.getElementById('openIdeaModal').click()"
            class="btn-primary">
            <i class="fas fa-lightbulb mr-2"></i>Erste Idee einreichen
        </button>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6" id="ideas-list">
        <?php foreach ($ideas as $idea):
            $submitterEmail = $userInfoMap[$idea['user_id']] ?? 'unknown@example.com';
            $submitterName  = formatEntraName(explode('@', $submitterEmail)[0]);
            $initials       = strtoupper(substr($submitterName, 0, 2));
            $avatarColor    = getAvatarColor($submitterName);
            $sc             = $statusConfig[$idea['status']] ?? $statusConfig['new'];
            $userVote       = $idea['user_vote'] ?? null;
            $upvotes        = (int) ($idea['upvotes'] ?? 0);
            $downvotes      = (int) ($idea['downvotes'] ?? 0);
            $score          = $upvotes - $downvotes;
            $accentColor    = $sc['accent'];
        ?>
        <div class="card group overflow-hidden flex flex-col"
             style="border-top: 3px solid <?php echo $accentColor; ?>;"
             data-idea-id="<?php echo $idea['id']; ?>">

            <!-- Card Top: Vote + Content -->
            <div class="flex gap-0 flex-1">

                <!-- Vote Column -->
                <div class="flex flex-col items-center justify-center gap-2 px-3 py-5 bg-gray-50 dark:bg-gray-800/50 border-r border-gray-100 dark:border-gray-700 min-w-[64px]">
                    <button
                        onclick="castVote(<?php echo $idea['id']; ?>, 'up')"
                        title="Upvote"
                        class="vote-btn upvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center <?php echo $userVote === 'up' ? 'bg-green-500 text-white shadow-md ring-2 ring-green-300 dark:ring-green-700' : 'bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-green-50 dark:hover:bg-green-900/30 hover:text-green-500 dark:hover:text-green-400 border border-gray-200 dark:border-gray-600'; ?>"
                    >
                        <i class="fas fa-chevron-up text-xs"></i>
                    </button>
                    <span class="vote-score text-lg font-extrabold leading-none tabular-nums <?php echo $score > 0 ? 'text-green-600 dark:text-green-400' : ($score < 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'); ?>">
                        <?php echo $score; ?>
                    </span>
                    <button
                        onclick="castVote(<?php echo $idea['id']; ?>, 'down')"
                        title="Downvote"
                        class="vote-btn downvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center <?php echo $userVote === 'down' ? 'bg-red-500 text-white shadow-md ring-2 ring-red-300 dark:ring-red-700' : 'bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-500 dark:hover:text-red-400 border border-gray-200 dark:border-gray-600'; ?>"
                    >
                        <i class="fas fa-chevron-down text-xs"></i>
                    </button>
                </div>

                <!-- Content -->
                <div class="flex-1 p-4 min-w-0 flex flex-col">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <h3 class="font-bold text-gray-900 dark:text-gray-50 text-[15px] leading-snug break-words hyphens-auto"><?php echo htmlspecialchars($idea['title']); ?></h3>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $sc['badge']; ?>">
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 <?php echo $sc['dot']; ?>"></span>
                                <?php echo $sc['label']; ?>
                            </span>
                            <?php if (Auth::isBoard()): ?>
                            <div class="relative">
                                <button
                                    onclick="toggleStatusMenu(this)"
                                    title="Status ändern"
                                    class="min-w-[44px] min-h-[44px] rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                >
                                    <i class="fas fa-ellipsis-v text-xs"></i>
                                </button>
                                <div class="status-menu hidden absolute right-0 top-8 bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-xl shadow-xl z-20 w-44 py-1.5">
                                    <?php foreach ($statusConfig as $sKey => $sCfg): ?>
                                    <button
                                        onclick="changeIdeaStatus(<?php echo $idea['id']; ?>, '<?php echo $sKey; ?>')"
                                        class="w-full text-left px-3.5 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/60 flex items-center gap-2.5 transition-colors"
                                    >
                                        <span class="w-2 h-2 rounded-full flex-shrink-0 <?php echo $sCfg['dot']; ?>"></span>
                                        <?php echo $sCfg['label']; ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="text-sm text-gray-700 dark:text-gray-400 leading-relaxed line-clamp-3 break-words hyphens-auto flex-1"><?php echo nl2br(htmlspecialchars($idea['description'])); ?></p>
                </div>
            </div>

            <!-- Card Footer / Meta -->
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-gray-600 dark:text-gray-500 px-4 py-2.5 border-t border-gray-100 dark:border-gray-700 bg-gray-50/70 dark:bg-gray-800/30">
                <div class="flex items-center gap-1.5">
                    <span class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                          style="background-color: <?php echo htmlspecialchars($avatarColor); ?>">
                        <?php echo htmlspecialchars($initials); ?>
                    </span>
                    <span class="truncate max-w-[100px] sm:max-w-[160px] font-medium text-gray-700 dark:text-gray-400"><?php echo htmlspecialchars($submitterName); ?></span>
                </div>
                <span>·</span>
                <span><?php echo date('d.m.Y', strtotime($idea['created_at'])); ?></span>
                <span class="ml-auto flex items-center gap-2.5">
                    <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400 font-medium"><i class="fas fa-arrow-up text-[10px]"></i><?php echo $upvotes; ?></span>
                    <span class="inline-flex items-center gap-1 text-red-400 dark:text-red-400 font-medium"><i class="fas fa-arrow-down text-[10px]"></i><?php echo $downvotes; ?></span>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- New Idea Modal -->
<div id="ideaModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4" style="display:none">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-5 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                    <i class="fas fa-lightbulb text-yellow-500 dark:text-yellow-400 text-base"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-50 leading-tight">Neue Idee einreichen</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Dein Vorschlag zählt!</p>
                </div>
            </div>
            <button id="closeIdeaModal" class="min-w-[44px] min-h-[44px] rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-4">
            <div id="ideaFormError" class="hidden p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-300 flex items-start gap-2">
                <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                <span id="ideaFormErrorText"></span>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="ideaTitle" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Titel <span class="text-red-500">*</span>
                    </label>
                    <span id="titleCount" class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">0 / 200</span>
                </div>
                <input
                    type="text"
                    id="ideaTitle"
                    maxlength="200"
                    required
                    placeholder="Kurzer, aussagekräftiger Titel…"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-yellow-400/50 focus:border-yellow-400 dark:focus:border-yellow-500 transition-colors text-sm outline-none"
                >
            </div>

            <div>
                <label for="ideaDescription" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Beschreibung <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="ideaDescription"
                    rows="5"
                    required
                    placeholder="Beschreibe Deine Idee so detailliert wie möglich…"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-yellow-400/50 focus:border-yellow-400 dark:focus:border-yellow-500 transition-colors text-sm resize-none outline-none"
                ></textarea>
            </div>

            <!-- Info note -->
            <div class="flex gap-2.5 p-3.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-xl">
                <i class="fas fa-info-circle text-blue-500 mt-0.5 flex-shrink-0 text-sm"></i>
                <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
                    Deine Idee wird an den IBC-Vorstand und ERW weitergeleitet und sorgfältig geprüft.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 pb-6 flex gap-3">
            <button
                id="submitIdeaBtn"
                type="button"
                class="flex-1 flex items-center justify-center gap-2 px-5 py-2.5 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold rounded-xl shadow-sm hover:shadow-md transition-all text-sm"
            >
                <i class="fas fa-paper-plane"></i>
                Einreichen
            </button>
            <button
                id="cancelIdeaBtn"
                type="button"
                class="px-5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all text-sm"
            >
                Abbrechen
            </button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const CREATE_URL = <?php echo json_encode(asset('api/create_idea.php')); ?>;
const VOTE_URL   = <?php echo json_encode(asset('api/vote_idea.php')); ?>;
const STATUS_URL = <?php echo json_encode(asset('api/update_idea_status.php')); ?>;

// ── Modal ──────────────────────────────────────────────────────────────────
const ideaModal   = document.getElementById('ideaModal');
const openBtn     = document.getElementById('openIdeaModal');
const closeBtn    = document.getElementById('closeIdeaModal');
const cancelBtn   = document.getElementById('cancelIdeaBtn');
const submitBtn   = document.getElementById('submitIdeaBtn');
const titleInput  = document.getElementById('ideaTitle');
const descInput   = document.getElementById('ideaDescription');
const formError   = document.getElementById('ideaFormError');
const formErrText = document.getElementById('ideaFormErrorText');
const titleCount  = document.getElementById('titleCount');

titleInput.addEventListener('input', () => {
    titleCount.textContent = titleInput.value.length + ' / 200';
});

function openModal() {
    ideaModal.style.display = 'flex';
    ideaModal.classList.add('show');
    titleInput.focus();
}
function closeModal() {
    ideaModal.style.display = 'none';
    ideaModal.classList.remove('show');
    titleInput.value = '';
    descInput.value  = '';
    titleCount.textContent = '0 / 200';
    formError.classList.add('hidden');
}

openBtn.addEventListener('click',   openModal);
closeBtn.addEventListener('click',  closeModal);
cancelBtn.addEventListener('click', closeModal);
ideaModal.addEventListener('click', e => { if (e.target === ideaModal) closeModal(); });

submitBtn.addEventListener('click', async () => {
    const title = titleInput.value.trim();
    const desc  = descInput.value.trim();

    formError.classList.add('hidden');

    if (!title || !desc) {
        formErrText.textContent = 'Bitte fülle alle Pflichtfelder aus.';
        formError.classList.remove('hidden');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Wird eingereicht…';

    try {
        const fd = new FormData();
        fd.append('csrf_token',  CSRF_TOKEN);
        fd.append('title',       title);
        fd.append('description', desc);

        const res  = await fetch(CREATE_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            closeModal();
            window.location.reload();
        } else {
            formErrText.textContent = data.error || 'Unbekannter Fehler.';
            formError.classList.remove('hidden');
        }
    } catch (err) {
        formErrText.textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
        formError.classList.remove('hidden');
    } finally {
        submitBtn.disabled = false;
        submitBtn.setAttribute('aria-busy', 'false');
        submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Einreichen';
    }
});

// ── Voting ─────────────────────────────────────────────────────────────────
async function castVote(ideaId, direction) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('idea_id',    ideaId);
    fd.append('vote',       direction);

    try {
        const res  = await fetch(VOTE_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { console.error(data.error); return; }

        const card    = document.querySelector(`[data-idea-id="${ideaId}"]`);
        if (!card) return;
        const upBtn   = card.querySelector('.upvote');
        const downBtn = card.querySelector('.downvote');
        const scoreEl = card.querySelector('.vote-score');
        const score   = data.upvotes - data.downvotes;

        const inactiveUp   = 'vote-btn upvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-green-50 dark:hover:bg-green-900/30 hover:text-green-500 dark:hover:text-green-400 border border-gray-200 dark:border-gray-600';
        const activeUp     = 'vote-btn upvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center bg-green-500 text-white shadow-md ring-2 ring-green-300 dark:ring-green-700';
        const inactiveDown = 'vote-btn downvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center bg-white dark:bg-gray-700 text-gray-400 dark:text-gray-500 hover:bg-red-50 dark:hover:bg-red-900/30 hover:text-red-500 dark:hover:text-red-400 border border-gray-200 dark:border-gray-600';
        const activeDown   = 'vote-btn downvote min-w-[44px] min-h-[44px] rounded-full flex items-center justify-center bg-red-500 text-white shadow-md ring-2 ring-red-300 dark:ring-red-700';

        upBtn.className   = data.user_vote === 'up'   ? activeUp   : inactiveUp;
        downBtn.className = data.user_vote === 'down' ? activeDown : inactiveDown;

        scoreEl.textContent = score;
        scoreEl.className   = 'vote-score text-lg font-extrabold leading-none tabular-nums ' + (score > 0 ? 'text-green-600 dark:text-green-400' : score < 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500');
    } catch (err) {
        console.error('Vote error:', err);
    }
}

// ── Status Menu (board only) ────────────────────────────────────────────────
function toggleStatusMenu(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.status-menu').forEach(m => {
        if (m !== menu) m.classList.add('hidden');
    });
    menu.classList.toggle('hidden');
}

document.addEventListener('click', e => {
    if (!e.target.closest('[onclick^="toggleStatusMenu"]')) {
        document.querySelectorAll('.status-menu').forEach(m => m.classList.add('hidden'));
    }
});

async function changeIdeaStatus(ideaId, status) {
    document.querySelectorAll('.status-menu').forEach(m => m.classList.add('hidden'));
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('idea_id',    ideaId);
    fd.append('status',     status);
    try {
        const res  = await fetch(STATUS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) window.location.reload();
        else alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
    } catch (err) {
        alert('Netzwerkfehler.');
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
