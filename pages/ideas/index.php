<?php
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

// Fetch submitter names
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
    'new'         => ['label'=>'Neu',         'accent'=>'#38bdf8', 'bg'=>'rgba(56,189,248,0.1)',  'border'=>'rgba(56,189,248,0.25)',  'color'=>'#0284c7'],
    'in_review'   => ['label'=>'In Prüfung',  'accent'=>'#fbbf24', 'bg'=>'rgba(251,191,36,0.1)',  'border'=>'rgba(251,191,36,0.25)',  'color'=>'#d97706'],
    'accepted'    => ['label'=>'Angenommen',  'accent'=>'#22c55e', 'bg'=>'rgba(34,197,94,0.1)',   'border'=>'rgba(34,197,94,0.25)',   'color'=>'#16a34a'],
    'rejected'    => ['label'=>'Abgelehnt',   'accent'=>'#ef4444', 'bg'=>'rgba(239,68,68,0.1)',   'border'=>'rgba(239,68,68,0.25)',   'color'=>'#dc2626'],
    'implemented' => ['label'=>'Umgesetzt',   'accent'=>'#a855f7', 'bg'=>'rgba(168,85,247,0.1)',  'border'=>'rgba(168,85,247,0.25)', 'color'=>'#9333ea'],
];

$title = 'Ideenbox - IBC Intranet';
ob_start();
?>
<style>
/* ── Ideenbox Page ───────────────────────────────────────────── */
.idea-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow 0.22s ease, border-color 0.22s ease, transform 0.22s ease;
}
.idea-card:hover {
    box-shadow: 0 8px 28px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

/* Vote column */
.idea-vote-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem 0.75rem;
    background: var(--bg-body);
    border-right: 1px solid var(--border-color);
    min-width: 3.75rem;
    flex-shrink: 0;
}
.vote-btn {
    width: 2.75rem;
    height: 2.75rem;
    min-width: 2.75rem;
    min-height: 2.75rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    font-size: 0.75rem;
    transition: background 0.15s, color 0.15s, border-color 0.15s, transform 0.15s;
    -webkit-tap-highlight-color: transparent;
    outline: none;
}
.vote-btn:active { transform: scale(0.88); }
.vote-btn--up:hover   { background: rgba(34,197,94,0.1);  color: #22c55e; border-color: rgba(34,197,94,0.35);  }
.vote-btn--down:hover { background: rgba(239,68,68,0.1);  color: #ef4444; border-color: rgba(239,68,68,0.35);  }
.vote-btn--up-active   { background: #22c55e; color: #fff; border-color: #22c55e; box-shadow: 0 2px 10px rgba(34,197,94,0.35); }
.vote-btn--down-active { background: #ef4444; color: #fff; border-color: #ef4444; box-shadow: 0 2px 10px rgba(239,68,68,0.35); }

.vote-score {
    font-size: 1.125rem;
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.vote-score--pos  { color: #22c55e; }
.vote-score--neg  { color: #ef4444; }
.vote-score--zero { color: var(--text-muted); }

/* Status badge */
.idea-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    border: 1px solid transparent;
    white-space: nowrap;
}

/* Status menu */
.idea-menu-btn {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    -webkit-tap-highlight-color: transparent;
}
.idea-menu-btn:hover { background: var(--bg-body); color: var(--text-main); }
.idea-status-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.25rem);
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 0.75rem;
    box-shadow: 0 8px 24px rgba(0,0,0,0.14);
    z-index: 50;
    width: 11rem;
    padding: 0.375rem 0;
    display: none;
}
.idea-status-menu.open { display: block; }
.idea-status-menu-item {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    width: 100%;
    padding: 0.5rem 0.875rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-main);
    background: transparent;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: background 0.12s;
}
.idea-status-menu-item:hover { background: var(--bg-body); }
.idea-status-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    flex-shrink: 0;
}

/* Card footer */
.idea-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    border-top: 1px solid var(--border-color);
    background: var(--bg-body);
    font-size: 0.75rem;
    color: var(--text-muted);
}
.idea-author-avatar {
    width: 1.375rem;
    height: 1.375rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.5625rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
}
.idea-vote-stat {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-weight: 600;
}

/* Modal overlay */
.idea-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 1060;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding: 0;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.22s ease;
}
.idea-modal-overlay.open { opacity: 1; pointer-events: auto; }
.idea-modal-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1.25rem 1.25rem 0 0;
    width: 100%;
    max-width: 34rem;
    max-height: 92dvh;
    overflow-y: auto;
    transform: translateY(32px);
    transition: transform 0.28s cubic-bezier(0.32,0.72,0,1);
    box-shadow: 0 -8px 40px rgba(0,0,0,0.18);
}
.idea-modal-overlay.open .idea-modal-card { transform: translateY(0); }
@media (min-width: 600px) {
    .idea-modal-overlay { align-items: center; padding: 1.5rem; }
    .idea-modal-card { border-radius: 1.25rem; }
}

/* Modal form elements */
.idea-form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.375rem;
}
.idea-form-input {
    width: 100%;
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-main);
    outline: none;
    transition: border-color 0.18s, box-shadow 0.18s;
    -webkit-appearance: none;
}
.idea-form-input::placeholder { color: var(--text-muted); opacity: 0.7; }
.idea-form-input:focus {
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245,158,11,0.12);
}
.idea-form-error {
    display: none;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.75rem 0.875rem;
    background: rgba(239,68,68,0.08);
    border: 1.5px solid rgba(239,68,68,0.2);
    border-radius: 0.625rem;
    font-size: 0.8125rem;
    color: #ef4444;
    margin-bottom: 0.875rem;
}
.idea-form-error.visible { display: flex; }
.idea-info-box {
    display: flex;
    gap: 0.625rem;
    padding: 0.875rem 1rem;
    background: rgba(0,102,179,0.07);
    border: 1.5px solid rgba(0,102,179,0.15);
    border-radius: 0.75rem;
    font-size: 0.8125rem;
    color: var(--ibc-blue);
    line-height: 1.55;
}

/* Stagger animations */
@keyframes ideaCardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: none; }
}
.idea-card { animation: ideaCardIn 0.3s ease both; }
.idea-card:nth-child(2) { animation-delay: 0.06s; }
.idea-card:nth-child(3) { animation-delay: 0.11s; }
.idea-card:nth-child(4) { animation-delay: 0.16s; }
.idea-card:nth-child(5) { animation-delay: 0.20s; }
.idea-card:nth-child(6) { animation-delay: 0.24s; }
.idea-card:nth-child(n+7) { animation-delay: 0.26s; }

.idea-empty {
    background: var(--bg-card);
    border: 2px dashed var(--border-color);
    border-radius: 1rem;
    padding: 4rem 2rem;
    text-align: center;
}
</style>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(245,158,11,0.35);flex-shrink:0;">
            <i class="fas fa-lightbulb" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div>
            <h1 style="font-size:1.625rem;font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Ideenbox</h1>
            <p style="font-size:0.875rem;color:var(--text-muted);margin:0.125rem 0 0;">Teile Deine Ideen – stimme ab, was umgesetzt werden soll</p>
        </div>
    </div>
    <button id="openIdeaModalBtn"
            style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.1rem;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:0.75rem;font-size:0.875rem;font-weight:700;cursor:pointer;white-space:nowrap;box-shadow:0 3px 12px rgba(245,158,11,0.3);transition:opacity 0.18s,transform 0.18s;flex-shrink:0;"
            onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
            onmouseout="this.style.opacity='1';this.style.transform='none'">
        <i class="fas fa-plus" aria-hidden="true"></i>
        Neue Idee
    </button>
</div>

<?php if (empty($ideas)): ?>
<!-- ── Empty State ────────────────────────────────────────────── -->
<div class="idea-empty">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(245,158,11,0.1);border:1.5px solid rgba(245,158,11,0.2);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-lightbulb" style="font-size:1.75rem;color:#f59e0b;" aria-hidden="true"></i>
    </div>
    <p style="font-weight:800;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Noch keine Ideen vorhanden</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0 0 1.5rem;">Sei der Erste und teile Deine Idee mit dem Team!</p>
    <button onclick="document.getElementById('openIdeaModalBtn').click()"
            style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:0.75rem;font-size:0.875rem;font-weight:700;cursor:pointer;box-shadow:0 3px 12px rgba(245,158,11,0.3);">
        <i class="fas fa-lightbulb" aria-hidden="true"></i>
        Erste Idee einreichen
    </button>
</div>

<?php else: ?>
<!-- ── Idea Cards ─────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,24rem),1fr));gap:1rem;" id="ideas-list">
    <?php foreach ($ideas as $idea):
        $submitterEmail = $userInfoMap[$idea['user_id']] ?? 'unknown@example.com';
        $submitterName  = formatEntraName(explode('@', $submitterEmail)[0]);
        $nameParts = array_values(array_filter(explode(' ', $submitterName)));
        $initials  = count($nameParts) >= 2
            ? strtoupper(mb_substr($nameParts[0],0,1).mb_substr($nameParts[1],0,1))
            : strtoupper(mb_substr($submitterName,0,2));
        $avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#22c55e','#f59e0b','#06b6d4'];
        $avatarColor  = $avatarColors[abs(crc32($submitterName)) % count($avatarColors)];
        $sc           = $statusConfig[$idea['status']] ?? $statusConfig['new'];
        $userVote     = $idea['user_vote'] ?? null;
        $upvotes      = (int)($idea['upvotes'] ?? 0);
        $downvotes    = (int)($idea['downvotes'] ?? 0);
        $score        = $upvotes - $downvotes;
        $scoreClass   = $score > 0 ? 'vote-score--pos' : ($score < 0 ? 'vote-score--neg' : 'vote-score--zero');
    ?>
    <div class="idea-card" data-idea-id="<?php echo (int)$idea['id']; ?>"
         style="border-top: 3.5px solid <?php echo $sc['accent']; ?>;">
        <!-- Top section: vote + content -->
        <div style="display:flex;flex:1;">
            <!-- Vote Column -->
            <div class="idea-vote-col">
                <button class="vote-btn vote-btn--up <?php echo $userVote === 'up' ? 'vote-btn--up-active' : ''; ?>"
                        onclick="castVote(<?php echo (int)$idea['id']; ?>, 'up')"
                        aria-label="Upvote">
                    <i class="fas fa-chevron-up" aria-hidden="true"></i>
                </button>
                <span class="vote-score <?php echo $scoreClass; ?>"><?php echo $score; ?></span>
                <button class="vote-btn vote-btn--down <?php echo $userVote === 'down' ? 'vote-btn--down-active' : ''; ?>"
                        onclick="castVote(<?php echo (int)$idea['id']; ?>, 'down')"
                        aria-label="Downvote">
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Content -->
            <div style="flex:1;padding:0.875rem 1rem;min-width:0;display:flex;flex-direction:column;">
                <!-- Title row + status + menu -->
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                    <h3 style="font-size:0.9375rem;font-weight:800;color:var(--text-main);line-height:1.3;margin:0;word-break:break-word;hyphens:auto;">
                        <?php echo htmlspecialchars($idea['title']); ?>
                    </h3>
                    <div style="display:flex;align-items:center;gap:0.25rem;flex-shrink:0;">
                        <span class="idea-status-badge"
                              style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;border-color:<?php echo $sc['border']; ?>;">
                            <span style="width:0.4rem;height:0.4rem;border-radius:50%;background:<?php echo $sc['accent']; ?>;display:inline-block;flex-shrink:0;"></span>
                            <?php echo $sc['label']; ?>
                        </span>
                        <?php if (Auth::isBoard()): ?>
                        <div style="position:relative;">
                            <button class="idea-menu-btn" onclick="toggleStatusMenu(this)" aria-label="Status ändern">
                                <i class="fas fa-ellipsis-v" style="font-size:0.75rem;" aria-hidden="true"></i>
                            </button>
                            <div class="idea-status-menu">
                                <?php foreach ($statusConfig as $sKey => $sCfg): ?>
                                <button class="idea-status-menu-item"
                                        onclick="changeIdeaStatus(<?php echo (int)$idea['id']; ?>, '<?php echo $sKey; ?>')">
                                    <span class="idea-status-dot" style="background:<?php echo $sCfg['accent']; ?>;"></span>
                                    <?php echo $sCfg['label']; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Description -->
                <p style="font-size:0.8125rem;color:var(--text-muted);line-height:1.6;margin:0;flex:1;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;word-break:break-word;hyphens:auto;">
                    <?php echo nl2br(htmlspecialchars($idea['description'])); ?>
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="idea-footer">
            <div style="display:flex;align-items:center;gap:0.375rem;">
                <div class="idea-author-avatar" style="background:<?php echo $avatarColor; ?>;">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <span style="font-weight:600;color:var(--text-main);max-width:8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($submitterName); ?>
                </span>
            </div>
            <span style="color:var(--border-color);">·</span>
            <span><?php echo date('d.m.Y', strtotime($idea['created_at'])); ?></span>
            <div style="margin-left:auto;display:flex;align-items:center;gap:0.75rem;">
                <span class="idea-vote-stat" style="color:#22c55e;">
                    <i class="fas fa-arrow-up" style="font-size:0.6rem;" aria-hidden="true"></i>
                    <?php echo $upvotes; ?>
                </span>
                <span class="idea-vote-stat" style="color:#ef4444;">
                    <i class="fas fa-arrow-down" style="font-size:0.6rem;" aria-hidden="true"></i>
                    <?php echo $downvotes; ?>
                </span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── New Idea Modal ─────────────────────────────────────────── -->
<div id="ideaModal" class="idea-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ideaModalTitle">
    <div class="idea-modal-card">
        <!-- Modal Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.25rem 1.5rem;border-bottom:1px solid var(--border-color);">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <div style="width:2.5rem;height:2.5rem;border-radius:0.75rem;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-lightbulb" style="color:#f59e0b;font-size:1rem;" aria-hidden="true"></i>
                </div>
                <div>
                    <h2 id="ideaModalTitle" style="font-size:1rem;font-weight:800;color:var(--text-main);line-height:1.25;margin:0;">Neue Idee einreichen</h2>
                    <p style="font-size:0.75rem;color:var(--text-muted);margin:0.1rem 0 0;">Dein Vorschlag zählt!</p>
                </div>
            </div>
            <button id="closeIdeaModalBtn"
                    style="width:2.25rem;height:2.25rem;border-radius:0.5rem;display:flex;align-items:center;justify-content:center;background:transparent;border:1.5px solid var(--border-color);color:var(--text-muted);cursor:pointer;transition:background 0.15s,color 0.15s;flex-shrink:0;"
                    onmouseover="this.style.background='var(--bg-body)';this.style.color='var(--text-main)'"
                    onmouseout="this.style.background='transparent';this.style.color='var(--text-muted)'"
                    aria-label="Schließen">
                <i class="fas fa-times" style="font-size:0.8rem;" aria-hidden="true"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding:1.375rem 1.5rem;display:flex;flex-direction:column;gap:1rem;">
            <!-- Error box -->
            <div id="ideaFormError" class="idea-form-error" role="alert">
                <i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:0.1rem;" aria-hidden="true"></i>
                <span id="ideaFormErrorText"></span>
            </div>

            <!-- Title -->
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.375rem;">
                    <label for="ideaTitle" class="idea-form-label" style="margin:0;">
                        Titel <span style="color:#ef4444;">*</span>
                    </label>
                    <span id="titleCount" style="font-size:0.75rem;color:var(--text-muted);">0 / 200</span>
                </div>
                <input type="text" id="ideaTitle" maxlength="200" required
                       placeholder="Kurzer, aussagekräftiger Titel…"
                       class="idea-form-input">
            </div>

            <!-- Description -->
            <div>
                <label for="ideaDescription" class="idea-form-label">
                    Beschreibung <span style="color:#ef4444;">*</span>
                </label>
                <textarea id="ideaDescription" rows="5" required
                          placeholder="Beschreibe Deine Idee so detailliert wie möglich…"
                          class="idea-form-input"
                          style="resize:vertical;min-height:7rem;"></textarea>
            </div>

            <!-- Info note -->
            <div class="idea-info-box">
                <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:0.1rem;" aria-hidden="true"></i>
                <span>Deine Idee wird an den IBC-Vorstand und ERW weitergeleitet und sorgfältig geprüft.</span>
            </div>
        </div>

        <!-- Modal Footer -->
        <div style="padding:0.875rem 1.5rem 1.5rem;display:flex;gap:0.625rem;flex-wrap:wrap;">
            <button id="cancelIdeaBtn"
                    style="padding:0.625rem 1rem;background:var(--bg-body);border:1.5px solid var(--border-color);border-radius:0.625rem;font-size:0.875rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:border-color 0.15s,color 0.15s;flex:1;min-width:7rem;"
                    onmouseover="this.style.borderColor='var(--text-muted)'"
                    onmouseout="this.style.borderColor='var(--border-color)'">
                Abbrechen
            </button>
            <button id="submitIdeaBtn"
                    style="display:inline-flex;align-items:center;justify-content:center;gap:0.45rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:0.625rem;font-size:0.875rem;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(245,158,11,0.3);transition:opacity 0.18s;flex:2;min-width:10rem;">
                <i class="fas fa-paper-plane" aria-hidden="true"></i>
                Einreichen
            </button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const CREATE_URL = <?php echo json_encode(asset('api/create_idea.php')); ?>;
const VOTE_URL   = <?php echo json_encode(asset('api/vote_idea.php')); ?>;
const STATUS_URL = <?php echo json_encode(asset('api/update_idea_status.php')); ?>;

/* ── Modal ──────────────────────────────────────────────────── */
const ideaModal   = document.getElementById('ideaModal');
const openBtn     = document.getElementById('openIdeaModalBtn');
const closeBtn    = document.getElementById('closeIdeaModalBtn');
const cancelBtn   = document.getElementById('cancelIdeaBtn');
const submitBtn   = document.getElementById('submitIdeaBtn');
const titleInput  = document.getElementById('ideaTitle');
const descInput   = document.getElementById('ideaDescription');
const formError   = document.getElementById('ideaFormError');
const formErrText = document.getElementById('ideaFormErrorText');
const titleCount  = document.getElementById('titleCount');

if (titleInput) {
    titleInput.addEventListener('input', () => {
        titleCount.textContent = titleInput.value.length + ' / 200';
    });
}

function openIdeaModal() {
    ideaModal.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (titleInput) titleInput.focus();
}
function closeIdeaModal() {
    ideaModal.classList.remove('open');
    document.body.style.overflow = '';
    if (titleInput) titleInput.value = '';
    if (descInput)  descInput.value  = '';
    if (titleCount) titleCount.textContent = '0 / 200';
    formError.classList.remove('visible');
}

if (openBtn)   openBtn.addEventListener('click',   openIdeaModal);
if (closeBtn)  closeBtn.addEventListener('click',  closeIdeaModal);
if (cancelBtn) cancelBtn.addEventListener('click', closeIdeaModal);
ideaModal.addEventListener('click', e => { if (e.target === ideaModal) closeIdeaModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeIdeaModal(); });

if (submitBtn) {
    submitBtn.addEventListener('click', async () => {
        const title = titleInput.value.trim();
        const desc  = descInput.value.trim();
        formError.classList.remove('visible');

        if (!title || !desc) {
            formErrText.textContent = 'Bitte fülle alle Pflichtfelder aus.';
            formError.classList.add('visible');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>&nbsp;Wird eingereicht…';

        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('title', title);
            fd.append('description', desc);
            const res  = await fetch(CREATE_URL, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) { closeIdeaModal(); window.location.reload(); }
            else {
                formErrText.textContent = data.error || 'Unbekannter Fehler.';
                formError.classList.add('visible');
            }
        } catch (err) {
            formErrText.textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
            formError.classList.add('visible');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane" aria-hidden="true"></i>&nbsp;Einreichen';
        }
    });
}

/* ── Voting ─────────────────────────────────────────────────── */
async function castVote(ideaId, direction) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('idea_id',    ideaId);
    fd.append('vote',       direction);

    try {
        const res  = await fetch(VOTE_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { console.error(data.error); return; }

        const card    = document.querySelector('[data-idea-id="' + ideaId + '"]');
        if (!card) return;
        const upBtn   = card.querySelector('.vote-btn--up');
        const downBtn = card.querySelector('.vote-btn--down');
        const scoreEl = card.querySelector('.vote-score');
        const score   = data.upvotes - data.downvotes;

        // Update up button classes
        upBtn.className = 'vote-btn vote-btn--up' + (data.user_vote === 'up' ? ' vote-btn--up-active' : '');
        // Update down button classes
        downBtn.className = 'vote-btn vote-btn--down' + (data.user_vote === 'down' ? ' vote-btn--down-active' : '');

        scoreEl.textContent = score;
        scoreEl.className = 'vote-score ' + (score > 0 ? 'vote-score--pos' : (score < 0 ? 'vote-score--neg' : 'vote-score--zero'));

        // Update footer vote counts
        const stats = card.querySelectorAll('.idea-vote-stat');
        if (stats.length >= 2) {
            stats[0].lastChild.textContent = ' ' + data.upvotes;
            stats[1].lastChild.textContent = ' ' + data.downvotes;
        }
    } catch (err) {
        console.error('Vote error:', err);
    }
}

/* ── Status Menu ─────────────────────────────────────────────── */
function toggleStatusMenu(btn) {
    const menu = btn.nextElementSibling;
    document.querySelectorAll('.idea-status-menu').forEach(m => {
        if (m !== menu) m.classList.remove('open');
    });
    menu.classList.toggle('open');
}

document.addEventListener('click', e => {
    if (!e.target.closest('[onclick^="toggleStatusMenu"]')) {
        document.querySelectorAll('.idea-status-menu').forEach(m => m.classList.remove('open'));
    }
});

async function changeIdeaStatus(ideaId, status) {
    document.querySelectorAll('.idea-status-menu').forEach(m => m.classList.remove('open'));
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
