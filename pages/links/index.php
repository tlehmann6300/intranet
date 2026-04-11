<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Link.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$allowedRoles = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz'];
$currentUser = Auth::user();
if (!$currentUser || !in_array($currentUser['role'] ?? '', $allowedRoles)) {
    header('Location: /index.php');
    exit;
}

$userRole  = $currentUser['role'] ?? '';
$canManage = Link::canManage($userRole);

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $deleteId = (int)($_POST['link_id'] ?? 0);
    if ($deleteId > 0) {
        try {
            Link::delete($deleteId);
            $_SESSION['success_message'] = 'Link erfolgreich gelöscht.';
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Fehler beim Löschen des Links.';
        }
    }
    header('Location: index.php');
    exit;
}

$searchQuery = trim($_GET['q'] ?? '');

$links = [];
try {
    $links = Link::getAll($searchQuery);
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Fehler beim Laden der Links: ' . htmlspecialchars($e->getMessage());
}

$title = 'Nützliche Links - IBC Intranet';
ob_start();
?>

<style>
/* ── Nützliche Links Module ── */
.lnk-header-icon {
    width: 3rem; height: 3rem;
    border-radius: 0.875rem;
    background: linear-gradient(135deg, var(--ibc-green), var(--ibc-green-dark));
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(0,160,80,0.3);
    flex-shrink: 0;
}

/* Action buttons in header */
.lnk-edit-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.625rem 1.125rem; border-radius: 0.75rem;
    background-color: var(--bg-card);
    border: 1.5px solid var(--border-color);
    color: var(--text-muted); font-weight: 600; font-size: 0.875rem;
    cursor: pointer;
    transition: border-color .2s, color .2s, background .2s, box-shadow .2s;
    white-space: nowrap;
}
.lnk-edit-btn:hover,
.lnk-edit-btn--active {
    border-color: var(--ibc-green);
    color: var(--ibc-green);
    box-shadow: 0 3px 12px rgba(0,160,80,0.18);
}
.lnk-edit-btn--active {
    background: rgba(0,160,80,0.08);
}
.lnk-new-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.625rem 1.125rem; border-radius: 0.75rem;
    background: linear-gradient(135deg, var(--ibc-green), var(--ibc-green-dark));
    color: #fff; font-weight: 600; font-size: 0.875rem;
    text-decoration: none;
    box-shadow: 0 3px 12px rgba(0,160,80,0.3);
    transition: opacity .2s, transform .15s;
    white-space: nowrap;
}
.lnk-new-btn:hover { opacity: .9; transform: scale(1.03); color: #fff; }

/* Search bar */
.lnk-search-wrap {
    margin-bottom: 1.5rem;
    display: flex; align-items: stretch; gap: 0.5rem;
    max-width: 32rem;
}
.lnk-search-input {
    flex: 1; padding: 0.625rem 1rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.75rem;
    background-color: var(--bg-card);
    color: var(--text-main); font-size: 0.9rem;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.lnk-search-input:focus {
    border-color: var(--ibc-green);
    box-shadow: 0 0 0 3px rgba(0,160,80,0.14);
}
.lnk-search-input::placeholder { color: var(--text-muted); }
.lnk-search-btn {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.625rem 1.125rem; border-radius: 0.75rem; border: none;
    background: var(--ibc-green); color: #fff;
    font-weight: 600; font-size: 0.875rem; cursor: pointer;
    transition: background .2s;
    white-space: nowrap;
}
.lnk-search-btn:hover { background: var(--ibc-green-dark); }
.lnk-clear-btn {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.625rem 0.875rem; border-radius: 0.75rem;
    background-color: rgba(100,116,139,0.1);
    color: var(--text-muted); font-size: 0.875rem; font-weight: 600;
    text-decoration: none;
    transition: background .2s;
}
.lnk-clear-btn:hover { background-color: rgba(100,116,139,0.2); }

/* Flash */
.lnk-flash {
    margin-bottom: 1.25rem; padding: 0.875rem 1.125rem;
    border-radius: 0.75rem; border: 1px solid;
    display: flex; align-items: center; gap: 0.625rem; font-size: 0.9rem;
}
.lnk-flash--ok  { background:rgba(22,163,74,0.08);  border-color:rgba(22,163,74,0.3);  color:#15803d; }
.lnk-flash--err { background:rgba(220,38,38,0.08);  border-color:rgba(220,38,38,0.3);  color:#b91c1c; }

/* Cards grid */
.lnk-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
    gap: 1rem;
}

/* Link card */
.lnk-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: box-shadow .25s, border-color .2s, transform .2s;
    animation: lnkCardIn .4s ease both;
}
.lnk-card:hover {
    box-shadow: 0 6px 24px rgba(0,160,80,0.12);
    border-color: rgba(0,160,80,0.35);
    transform: translateY(-3px);
}
@keyframes lnkCardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}
.lnk-card:nth-child(1)  { animation-delay:.04s }
.lnk-card:nth-child(2)  { animation-delay:.08s }
.lnk-card:nth-child(3)  { animation-delay:.12s }
.lnk-card:nth-child(4)  { animation-delay:.16s }
.lnk-card:nth-child(5)  { animation-delay:.20s }
.lnk-card:nth-child(6)  { animation-delay:.24s }
.lnk-card:nth-child(n+7){ animation-delay:.28s }

/* Card link area */
.lnk-card-link {
    display: flex; align-items: flex-start; gap: 1rem;
    padding: 1.125rem; flex: 1; text-decoration: none;
}
.lnk-card-link:hover { text-decoration: none; }

/* Icon box */
.lnk-card-icon {
    width: 2.75rem; height: 2.75rem; border-radius: 0.625rem;
    background: rgba(0,160,80,0.1);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .25s, transform .2s;
}
.lnk-card-icon i { color: var(--ibc-green); font-size: 1.1rem; transition: color .2s; }
.lnk-card:hover .lnk-card-icon {
    background: var(--ibc-green);
    transform: scale(1.1);
}
.lnk-card:hover .lnk-card-icon i { color: #fff; }

/* Card text */
.lnk-card-body { flex: 1; min-width: 0; }
.lnk-card-title {
    font-size: 0.9375rem; font-weight: 600;
    color: var(--text-main);
    line-height: 1.35; word-break: break-word;
    transition: color .2s;
    display: block;
}
.lnk-card:hover .lnk-card-title { color: var(--ibc-green); }
.lnk-card-desc {
    font-size: 0.8rem; color: var(--text-muted);
    margin-top: 0.3rem; line-height: 1.5; word-break: break-word;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.lnk-card-ext {
    color: var(--border-color); font-size: 0.7rem;
    flex-shrink: 0; margin-top: 0.1rem;
    transition: color .2s;
}
.lnk-card:hover .lnk-card-ext { color: var(--ibc-green); }

/* Edit actions – shown only when editing */
.lnk-card-actions {
    padding: 0 1.125rem 1rem;
    display: none;
    justify-content: flex-end;
    gap: 0.625rem;
}
.lnk-page--editing .lnk-card-actions { display: flex; }

.lnk-action-edit {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.4375rem 0.875rem; border-radius: 0.5rem;
    background: rgba(100,116,139,0.1); color: var(--text-muted);
    font-size: 0.8rem; font-weight: 600; text-decoration: none;
    transition: background .2s, color .2s;
}
.lnk-action-edit:hover { background: rgba(100,116,139,0.2); color: var(--text-main); }
.lnk-action-del {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.4375rem 0.875rem; border-radius: 0.5rem;
    background: rgba(220,38,38,0.08); color: #dc2626;
    font-size: 0.8rem; font-weight: 600; cursor: pointer; border: none;
    transition: background .2s;
}
.lnk-action-del:hover { background: rgba(220,38,38,0.16); }

/* Empty state */
.lnk-empty {
    background-color: var(--bg-card);
    border: 1.5px dashed var(--border-color);
    border-radius: 1rem; padding: 3.5rem 2rem; text-align: center;
}
</style>

<div id="lnkPage">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="lnk-flash lnk-flash--ok">
    <i class="fas fa-check-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="lnk-flash lnk-flash--err">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<!-- Page Header -->
<div style="margin-bottom:2rem; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
    <div style="display:flex; align-items:center; gap:0.875rem;">
        <div class="lnk-header-icon">
            <i class="fas fa-link" style="color:#fff; font-size:1.1875rem;"></i>
        </div>
        <div>
            <h1 style="font-size:1.75rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2;">Nützliche Links</h1>
            <p style="color:var(--text-muted); margin:0.2rem 0 0; font-size:0.9rem;">Schnellzugriff auf häufig genutzte Tools und Ressourcen</p>
        </div>
    </div>

    <?php if ($canManage): ?>
    <div style="display:flex; gap:0.625rem; align-items:center; flex-wrap:wrap;">
        <button id="lnkToggleEdit" class="lnk-edit-btn" type="button">
            <i class="fas fa-pencil-alt"></i>Bearbeiten
        </button>
        <a href="edit.php" class="lnk-new-btn">
            <i class="fas fa-plus"></i>Neuer Link
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Search -->
<form method="GET" class="lnk-search-wrap">
    <input type="text" name="q" class="lnk-search-input"
           value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
           placeholder="Links durchsuchen...">
    <button type="submit" class="lnk-search-btn">
        <i class="fas fa-search"></i>Suchen
    </button>
    <?php if ($searchQuery !== ''): ?>
    <a href="index.php" class="lnk-clear-btn">
        <i class="fas fa-times"></i>Zurücksetzen
    </a>
    <?php endif; ?>
</form>

<?php if (empty($links)): ?>
<div class="lnk-empty">
    <div style="width:4rem; height:4rem; border-radius:50%; background:rgba(0,160,80,0.08); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem;">
        <i class="fas fa-link" style="font-size:1.75rem; color:rgba(0,160,80,0.3);"></i>
    </div>
    <?php if ($searchQuery !== ''): ?>
    <p style="font-weight:600; color:var(--text-main); margin:0 0 0.25rem;">Keine Links für „<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" gefunden.</p>
    <?php else: ?>
    <p style="font-weight:600; color:var(--text-main); margin:0 0 0.25rem;">Noch keine Links vorhanden.</p>
    <?php if ($canManage): ?>
    <p style="font-size:0.875rem; color:var(--text-muted); margin:0;">Klicke auf „Neuer Link", um den ersten Link hinzuzufügen.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="lnk-grid" id="lnkGrid">
    <?php foreach ($links as $link):
        $rawUrl  = $link['url'] ?? '';
        $parsed  = parse_url($rawUrl);
        $scheme  = strtolower($parsed['scheme'] ?? '');
        $url     = (in_array($scheme, ['http', 'https']) && !empty($parsed['host'])) ? $rawUrl : '#';
        $icon    = htmlspecialchars($link['icon'] ?? 'fas fa-external-link-alt', ENT_QUOTES, 'UTF-8');
        $linkDbId = $link['id'] ?? null;
    ?>
    <div class="lnk-card">
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
           target="_blank" rel="noopener noreferrer"
           class="lnk-card-link">
            <div class="lnk-card-icon">
                <i class="<?php echo $icon; ?>"></i>
            </div>
            <div class="lnk-card-body">
                <span class="lnk-card-title"><?php echo htmlspecialchars($link['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (!empty($link['description'])): ?>
                <p class="lnk-card-desc"><?php echo htmlspecialchars($link['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
            <i class="fas fa-external-link-alt lnk-card-ext"></i>
        </a>

        <?php if ($canManage && $linkDbId !== null): ?>
        <div class="lnk-card-actions">
            <a href="edit.php?id=<?php echo (int)$linkDbId; ?>" class="lnk-action-edit">
                <i class="fas fa-edit"></i>Bearbeiten
            </a>
            <form method="POST" action="index.php" class="lnk-delete-form" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="link_id" value="<?php echo (int)$linkDbId; ?>">
                <button type="submit" class="lnk-action-del">
                    <i class="fas fa-trash"></i>Löschen
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /#lnkPage -->

<script>
// Delete confirmation
document.querySelectorAll('.lnk-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!confirm('Link wirklich löschen?')) e.preventDefault();
    });
});

// Edit-mode toggle
(function () {
    var btn  = document.getElementById('lnkToggleEdit');
    var page = document.getElementById('lnkPage');
    if (!btn || !page) return;

    btn.addEventListener('click', function () {
        var active = page.classList.toggle('lnk-page--editing');
        btn.classList.toggle('lnk-edit-btn--active', active);
        btn.innerHTML = active
            ? '<i class="fas fa-times"></i>Fertig'
            : '<i class="fas fa-pencil-alt"></i>Bearbeiten';
    });
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
