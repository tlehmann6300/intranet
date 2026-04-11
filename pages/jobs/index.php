<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/JobBoard.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userId = $user['id'];

// Filter
$filterType = isset($_GET['type']) && in_array($_GET['type'], JobBoard::SEARCH_TYPES) ? $_GET['type'] : null;

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

$allListings = JobBoard::getAll($perPage + 1, $offset, $filterType);
$hasNextPage = count($allListings) > $perPage;
$listings = array_slice($allListings, 0, $perPage);

// Fetch author names from User DB
$userDb = Database::getUserDB();
$userIds = array_unique(array_column($listings, 'user_id'));
$authorNames = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $userDb->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $authorNames[$u['id']] = trim($u['first_name'] . ' ' . $u['last_name']);
    }
}

// Success/error flash messages
$successMessage = $_SESSION['success_message'] ?? null;
$errorMessage   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $deleteId = (int)$_POST['delete_id'];
    $listing  = JobBoard::getById($deleteId);

    if ($listing && (int)$listing['user_id'] === $userId) {
        // Delete PDF file if it exists
        if (!empty($listing['pdf_path'])) {
            $pdfFile = __DIR__ . '/../../' . $listing['pdf_path'];
            if (file_exists($pdfFile)) {
                $allowedDir = realpath(__DIR__ . '/../../uploads/jobs');
                $realFile = realpath($pdfFile);
                if ($realFile !== false && $allowedDir !== false && strpos($realFile, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                    unlink($realFile);
                }
            }
        }
        JobBoard::deleteByOwner($deleteId, $userId);
        $_SESSION['success_message'] = 'Anzeige erfolgreich gelöscht.';
    } else {
        $_SESSION['error_message'] = 'Die Anzeige konnte nicht gelöscht werden.';
    }
    header('Location: index.php' . ($filterType ? '?type=' . urlencode($filterType) : ''));
    exit;
}

// Type style map: semi-transparent colors work in both light + dark mode
$typeStyles = [
    'Festanstellung'          => ['c'=>'#3b82f6','b'=>'rgba(59,130,246,0.1)','e'=>'rgba(59,130,246,0.22)','icon'=>'fa-briefcase'],
    'Werksstudententätigkeit' => ['c'=>'#8b5cf6','b'=>'rgba(139,92,246,0.1)','e'=>'rgba(139,92,246,0.22)','icon'=>'fa-laptop-code'],
    'Praxissemester'          => ['c'=>'#22c55e','b'=>'rgba(34,197,94,0.1)','e'=>'rgba(34,197,94,0.22)','icon'=>'fa-graduation-cap'],
    'Praktikum'               => ['c'=>'#f59e0b','b'=>'rgba(245,158,11,0.1)','e'=>'rgba(245,158,11,0.22)','icon'=>'fa-star'],
];

$title = 'Job- & Praktikumsbörse - IBC Intranet';
ob_start();
?>
<style>
/* ── Jobs Page ───────────────────────────────────────────── */
.job-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding: 0.125rem 0 0.375rem;
    -ms-overflow-style: none;
}
.job-filter-bar::-webkit-scrollbar { display: none; }
.job-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.875rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
    transition: opacity 0.18s, transform 0.18s, box-shadow 0.18s;
    border: 1.5px solid transparent;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
    min-height: 2.375rem;
}
.job-chip:hover { opacity: 0.85; transform: translateY(-1px); }
.job-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color 0.2s, box-shadow 0.22s, transform 0.22s cubic-bezier(0.34,1.56,0.64,1);
    position: relative;
}
.job-card:hover {
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    transform: translateY(-3px);
}
.job-card-accent {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    border-radius: 0 0 0 0;
}
.job-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    border: 1px solid transparent;
    white-space: nowrap;
}
.job-author-avatar {
    width: 1.625rem;
    height: 1.625rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
}
.job-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.5rem 0.875rem;
    border-radius: 0.5rem;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: 1.5px solid var(--border-color);
    background: var(--bg-body);
    color: var(--text-muted);
    transition: border-color 0.18s, background 0.18s, color 0.18s;
    white-space: nowrap;
    min-height: 2.25rem;
}
.job-action-btn:hover { border-color: var(--ibc-blue); color: var(--ibc-blue); background: rgba(0,102,179,0.05); }
.job-action-btn--delete:hover { border-color: #ef4444; color: #ef4444; background: rgba(239,68,68,0.06); }
.job-action-btn--pdf {
    border-color: rgba(239,68,68,0.25);
    background: rgba(239,68,68,0.07);
    color: #ef4444;
}
.job-action-btn--pdf:hover { background: rgba(239,68,68,0.14); border-color: rgba(239,68,68,0.4); color: #ef4444; }
.job-action-btn--contact {
    border-color: rgba(0,166,81,0.25);
    background: rgba(0,166,81,0.07);
    color: var(--ibc-green);
}
.job-action-btn--contact:hover { background: rgba(0,166,81,0.14); border-color: rgba(0,166,81,0.4); }
.job-flash {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.125rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1.25rem;
}
.job-flash--success { background: rgba(0,166,81,0.08); border: 1.5px solid rgba(0,166,81,0.2); color: var(--ibc-green); }
.job-flash--error { background: rgba(239,68,68,0.08); border: 1.5px solid rgba(239,68,68,0.2); color: #ef4444; }
.job-pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    text-decoration: none;
    transition: border-color 0.18s, color 0.18s, transform 0.18s;
}
.job-pagination-btn:hover { border-color: var(--ibc-blue); color: var(--ibc-blue); transform: translateY(-1px); }
.job-page-indicator {
    display: inline-flex;
    align-items: center;
    padding: 0.625rem 1.125rem;
    background: rgba(0,102,179,0.08);
    border: 1.5px solid rgba(0,102,179,0.2);
    border-radius: 0.625rem;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ibc-blue);
}
/* Contact Modal */
.jb-modal-overlay {
    position: fixed; inset: 0;
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
.jb-modal-overlay.open { opacity: 1; pointer-events: auto; }
.jb-modal-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1.25rem 1.25rem 0 0;
    width: 100%;
    max-width: 32rem;
    max-height: 90dvh;
    overflow-y: auto;
    transform: translateY(40px);
    transition: transform 0.28s cubic-bezier(0.32,0.72,0,1);
    box-shadow: 0 -8px 40px rgba(0,0,0,0.2);
}
.jb-modal-overlay.open .jb-modal-card { transform: translateY(0); }
@media (min-width: 600px) {
    .jb-modal-overlay { align-items: center; padding: 1.5rem; }
    .jb-modal-card { border-radius: 1.25rem; }
}
.jb-modal-header {
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    padding: 1.25rem 1.5rem 1.125rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.jb-form-input {
    width: 100%;
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-main);
    transition: border-color 0.18s, box-shadow 0.18s;
    outline: none;
    -webkit-appearance: none;
}
.jb-form-input:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0,102,179,0.1);
}
.jb-form-input::placeholder { color: var(--text-muted); opacity: 0.7; }
@keyframes jobCardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: none; }
}
.job-card { animation: jobCardIn 0.3s ease both; }
.job-card:nth-child(2) { animation-delay: 0.05s; }
.job-card:nth-child(3) { animation-delay: 0.10s; }
.job-card:nth-child(4) { animation-delay: 0.15s; }
.job-card:nth-child(5) { animation-delay: 0.20s; }
.job-card:nth-child(6) { animation-delay: 0.25s; }
.job-card:nth-child(n+7) { animation-delay: 0.28s; }
</style>

<!-- ── Page Header ─────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(34,197,94,0.28);flex-shrink:0;">
            <i class="fas fa-briefcase" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div>
            <h1 style="font-size:1.625rem;font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Job- &amp; Praktikumsbörse</h1>
            <p style="font-size:0.875rem;color:var(--text-muted);margin:0.125rem 0 0;">Stelle dein Profil vor oder finde Talente</p>
        </div>
    </div>
    <a href="create.php"
       style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 3px 12px rgba(34,197,94,0.3);transition:opacity 0.18s,transform 0.18s;flex-shrink:0;"
       onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
       onmouseout="this.style.opacity='1';this.style.transform='none'">
        <i class="fas fa-plus" aria-hidden="true"></i>
        Anzeige erstellen
    </a>
</div>

<?php if ($successMessage): ?>
<div class="job-flash job-flash--success"><i class="fas fa-check-circle" style="flex-shrink:0;"></i><span><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
<div class="job-flash job-flash--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i><span><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<!-- ── Type Filter Bar ─────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:0.875rem;padding:0.875rem 1rem;margin-bottom:1.75rem;">
    <div class="job-filter-bar" role="navigation" aria-label="Typ filtern">
        <a href="index.php"
           class="job-chip"
           style="<?php echo $filterType === null
               ? 'background:var(--ibc-blue);color:#fff;border-color:var(--ibc-blue);box-shadow:0 2px 10px rgba(0,102,179,0.25);'
               : 'background:var(--bg-body);color:var(--text-muted);border-color:var(--border-color);'; ?>">
            <i class="fas fa-th-large" style="font-size:0.7rem;" aria-hidden="true"></i>
            Alle
        </a>
        <?php foreach (JobBoard::SEARCH_TYPES as $type):
            $ts = $typeStyles[$type] ?? ['c'=>'#6b7280','b'=>'rgba(107,114,128,0.1)','e'=>'rgba(107,114,128,0.2)','icon'=>'fa-tag'];
            $isActive = $filterType === $type;
        ?>
        <a href="index.php?type=<?php echo urlencode($type); ?>"
           class="job-chip"
           style="<?php echo $isActive
               ? "background:{$ts['c']};color:#fff;border-color:{$ts['c']};box-shadow:0 2px 10px {$ts['b']};"
               : "background:{$ts['b']};color:{$ts['c']};border-color:{$ts['e']};"; ?>">
            <i class="fas <?php echo $ts['icon']; ?>" style="font-size:0.7rem;" aria-hidden="true"></i>
            <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($listings)): ?>
<!-- ── Empty State ─────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:1rem;padding:4rem 2rem;text-align:center;">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(34,197,94,0.08);border:1.5px solid rgba(34,197,94,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-briefcase" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Keine Anzeigen gefunden</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">
        <?php echo $filterType ? 'Versuche einen anderen Typ.' : 'Noch keine Anzeigen vorhanden.'; ?>
    </p>
</div>

<?php else: ?>
<!-- ── Listings Grid ───────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,19rem),1fr));gap:1.125rem;">
    <?php foreach ($listings as $listing):
        $ts = $typeStyles[$listing['search_type']] ?? ['c'=>'#6b7280','b'=>'rgba(107,114,128,0.1)','e'=>'rgba(107,114,128,0.2)','icon'=>'fa-tag'];
        $authorName = $authorNames[$listing['user_id']] ?? 'Unbekannt';
        $authorInitials = '';
        $nameParts = array_filter(explode(' ', $authorName));
        $nameParts = array_values($nameParts);
        if (count($nameParts) >= 2) $authorInitials = strtoupper(mb_substr($nameParts[0],0,1).mb_substr($nameParts[1],0,1));
        else $authorInitials = strtoupper(mb_substr($authorName, 0, 1));
        $avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#22c55e','#f59e0b','#06b6d4'];
        $avatarColor  = $avatarColors[abs(crc32($authorName)) % count($avatarColors)];
    ?>
    <div class="job-card" style="border-left: 4px solid <?php echo $ts['c']; ?>;">
        <div style="padding:1.125rem 1.125rem 0.875rem;">
            <!-- Type badge + date -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
                <span class="job-type-badge" style="color:<?php echo $ts['c']; ?>;background:<?php echo $ts['b']; ?>;border-color:<?php echo $ts['e']; ?>;">
                    <i class="fas <?php echo $ts['icon']; ?>" style="font-size:0.6rem;" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($listing['search_type'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span style="font-size:0.725rem;color:var(--text-muted);white-space:nowrap;">
                    <i class="fas fa-calendar-alt" style="font-size:0.625rem;" aria-hidden="true"></i>
                    <?php echo (new DateTime($listing['created_at']))->format('d.m.Y'); ?>
                </span>
            </div>

            <!-- Title -->
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.35;margin:0 0 0.625rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                <?php echo htmlspecialchars($listing['title'], ENT_QUOTES, 'UTF-8'); ?>
            </h3>

            <!-- Author -->
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.875rem;">
                <div class="job-author-avatar" style="background:<?php echo $avatarColor; ?>;">
                    <?php echo htmlspecialchars($authorInitials); ?>
                </div>
                <span style="font-size:0.8rem;font-weight:600;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>

            <!-- Description excerpt -->
            <p style="font-size:0.8125rem;color:var(--text-muted);line-height:1.55;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
                <?php
                    $desc = strip_tags($listing['description']);
                    echo htmlspecialchars(mb_strlen($desc) > 200 ? mb_substr($desc, 0, 200).'…' : $desc, ENT_QUOTES, 'UTF-8');
                ?>
            </p>
        </div>

        <!-- Footer actions -->
        <div style="padding:0.75rem 1.125rem;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;margin-top:auto;">
            <?php if (!empty($listing['pdf_path'])): ?>
            <a href="<?php echo htmlspecialchars(asset($listing['pdf_path']), ENT_QUOTES, 'UTF-8'); ?>"
               download
               class="job-action-btn job-action-btn--pdf">
                <i class="fas fa-file-pdf" aria-hidden="true"></i>
                Lebenslauf
            </a>
            <?php else: ?>
            <span style="font-size:0.75rem;color:var(--text-muted);font-style:italic;">Kein Lebenslauf</span>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                <?php if ((int)$listing['user_id'] === $userId): ?>
                <a href="edit.php?id=<?php echo (int)$listing['id']; ?>" class="job-action-btn">
                    <i class="fas fa-edit" aria-hidden="true"></i>
                    Bearbeiten
                </a>
                <form method="POST" action="index.php<?php echo $filterType ? '?type='.urlencode($filterType) : ''; ?>"
                      onsubmit="return confirm('Anzeige wirklich löschen?');" style="display:contents;">
                    <input type="hidden" name="csrf_token" value="<?php
                        require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
                        echo CSRFHandler::getToken();
                    ?>">
                    <input type="hidden" name="delete_id" value="<?php echo (int)$listing['id']; ?>">
                    <button type="submit" class="job-action-btn job-action-btn--delete">
                        <i class="fas fa-trash-alt" aria-hidden="true"></i>
                        Löschen
                    </button>
                </form>
                <?php else: ?>
                <button type="button" class="job-action-btn job-action-btn--contact"
                        onclick="openContactModal(<?php echo (int)$listing['id']; ?>, <?php echo htmlspecialchars(json_encode($listing['title']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($authorName), ENT_QUOTES, 'UTF-8'); ?>)">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    Kontakt
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Pagination ─────────────────────────────────────────── -->
<?php if ($page > 1 || $hasNextPage): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:0.75rem;margin-top:2.5rem;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
    <a href="?page=<?php echo $page - 1; ?><?php echo $filterType ? '&type='.urlencode($filterType) : ''; ?>" class="job-pagination-btn">
        <i class="fas fa-chevron-left" style="font-size:0.75rem;" aria-hidden="true"></i>
        Zurück
    </a>
    <?php endif; ?>
    <span class="job-page-indicator">Seite <?php echo $page; ?></span>
    <?php if ($hasNextPage): ?>
    <a href="?page=<?php echo $page + 1; ?><?php echo $filterType ? '&type='.urlencode($filterType) : ''; ?>" class="job-pagination-btn">
        Weiter
        <i class="fas fa-chevron-right" style="font-size:0.75rem;" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Contact Modal ──────────────────────────────────────── -->
<div id="contactModal" class="jb-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="contactModalTitle">
    <div class="jb-modal-card">
        <!-- Header -->
        <div class="jb-modal-header">
            <div>
                <p style="font-size:0.7rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:rgba(255,255,255,0.65);margin:0 0 2px;">Stellenanzeige</p>
                <h2 id="contactModalTitle" style="font-size:1.125rem;font-weight:800;color:#fff;margin:0;">Kontakt aufnehmen</h2>
                <p id="contactModalSubtitle" style="font-size:0.8125rem;color:rgba(255,255,255,0.7);margin:0.25rem 0 0;"></p>
            </div>
            <button onclick="closeContactModal()"
                    style="display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:50%;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);color:#fff;cursor:pointer;flex-shrink:0;transition:background 0.15s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.25)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.15)'"
                    aria-label="Schließen">
                <i class="fas fa-times" style="font-size:0.875rem;" aria-hidden="true"></i>
            </button>
        </div>

        <!-- Body -->
        <div style="padding:1.375rem 1.5rem 1.5rem;">
            <div id="contactModalSuccess" style="display:none;align-items:center;gap:0.75rem;padding:0.875rem 1rem;background:rgba(0,166,81,0.08);border:1.5px solid rgba(0,166,81,0.2);border-radius:0.75rem;color:var(--ibc-green);font-size:0.875rem;margin-bottom:1.25rem;">
                <i class="fas fa-check-circle" style="flex-shrink:0;" aria-hidden="true"></i>
                <span>Deine Nachricht wurde erfolgreich gesendet!</span>
            </div>
            <div id="contactModalError" style="display:none;align-items:center;gap:0.75rem;padding:0.875rem 1rem;background:rgba(239,68,68,0.08);border:1.5px solid rgba(239,68,68,0.2);border-radius:0.75rem;color:#ef4444;font-size:0.875rem;margin-bottom:1.25rem;">
                <i class="fas fa-exclamation-circle" style="flex-shrink:0;" aria-hidden="true"></i>
                <span id="contactModalErrorText"></span>
            </div>

            <form id="contactModalForm" style="display:flex;flex-direction:column;gap:1rem;">
                <input type="hidden" id="contactListingId" name="listing_id" value="">
                <input type="hidden" id="contactCsrfToken" name="csrf_token" value="<?php
                    require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
                    echo CSRFHandler::getToken();
                ?>">

                <div>
                    <label for="contactEmail" style="display:block;font-size:0.8125rem;font-weight:600;color:var(--text-main);margin-bottom:0.375rem;">
                        Deine Kontakt-E-Mail <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="email" id="contactEmail" name="contact_email" required
                           value="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="deine@email.de"
                           class="jb-form-input">
                    <p style="font-size:0.75rem;color:var(--text-muted);margin:0.375rem 0 0;">Diese Adresse wird dem Empfänger als Antwortadresse übermittelt.</p>
                </div>

                <div>
                    <label for="contactMessage" style="display:block;font-size:0.8125rem;font-weight:600;color:var(--text-main);margin-bottom:0.375rem;">
                        Nachricht <span style="color:#ef4444;">*</span>
                    </label>
                    <textarea id="contactMessage" name="message" required rows="4"
                              placeholder="Schreibe hier deine Nachricht…"
                              class="jb-form-input"
                              style="resize:vertical;min-height:6rem;"></textarea>
                </div>

                <div style="display:flex;gap:0.625rem;flex-wrap:wrap;padding-top:0.25rem;">
                    <button type="button" onclick="closeContactModal()"
                            style="flex:1;min-width:7rem;padding:0.625rem 1rem;background:var(--bg-body);border:1.5px solid var(--border-color);border-radius:0.625rem;font-size:0.875rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:border-color 0.15s;">
                        Abbrechen
                    </button>
                    <button type="submit" id="contactSubmitBtn"
                            style="flex:2;min-width:10rem;display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,var(--ibc-blue),#0088ee);color:#fff;border:none;border-radius:0.625rem;font-size:0.875rem;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(0,102,179,0.25);transition:opacity 0.18s;">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        Nachricht senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openContactModal(listingId, listingTitle, authorName) {
    document.getElementById('contactListingId').value = listingId;
    document.getElementById('contactModalSubtitle').textContent = listingTitle + ' – ' + authorName;
    var suc = document.getElementById('contactModalSuccess');
    var err = document.getElementById('contactModalError');
    suc.style.display = 'none';
    err.style.display = 'none';
    document.getElementById('contactModalForm').style.display = 'flex';
    document.getElementById('contactMessage').value = '';
    var modal = document.getElementById('contactModal');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeContactModal() {
    document.getElementById('contactModal').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeContactModal();
});

document.getElementById('contactModal').addEventListener('click', function(e) {
    if (e.target === this) closeContactModal();
});

document.getElementById('contactModalForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('contactSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>&nbsp;Senden…';

    var suc = document.getElementById('contactModalSuccess');
    var err = document.getElementById('contactModalError');
    suc.style.display = 'none';
    err.style.display = 'none';

    fetch('<?php echo htmlspecialchars(BASE_URL . '/api/contact_job_listing.php', ENT_QUOTES, 'UTF-8'); ?>', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            suc.style.display = 'flex';
            document.getElementById('contactModalForm').style.display = 'none';
        } else {
            document.getElementById('contactModalErrorText').textContent = data.message || 'Ein Fehler ist aufgetreten.';
            err.style.display = 'flex';
        }
    })
    .catch(function() {
        document.getElementById('contactModalErrorText').textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
        err.style.display = 'flex';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane" aria-hidden="true"></i>&nbsp;Nachricht senden';
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
