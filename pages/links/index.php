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

$userRole = $currentUser['role'] ?? '';
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

// Load search query from URL
$searchQuery = trim($_GET['q'] ?? '');

// Load links from DB
$links = [];
try {
    $links = Link::getAll($searchQuery);
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Fehler beim Laden der Links aus der Datenbank: ' . htmlspecialchars($e->getMessage());
}

$title = 'Nützliche Links - IBC Intranet';
ob_start();
?>

<div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
    <div>
        <div class="flex items-center gap-3 mb-2">
            <div class="w-11 h-11 rounded-2xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center shadow-sm">
                <i class="fas fa-link text-ibc-green text-xl"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Nützliche Links</h1>
        </div>
        <p class="text-gray-500 dark:text-gray-400 text-sm">Schnellzugriff auf häufig genutzte Tools und Ressourcen</p>
    </div>

    <?php if ($canManage): ?>
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <button id="toggle-edit-mode"
                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 rounded-xl font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-400 dark:hover:border-gray-500 transition-all text-sm shadow-sm w-full sm:w-auto">
            <i class="fas fa-pencil-alt"></i>
            Bearbeiten
        </button>
        <a href="edit.php"
           class="btn-primary w-full sm:w-auto justify-center">
            <i class="fas fa-plus"></i>
            Neuer Link
        </a>
    </div>
    <?php endif; ?>
</div>

<form method="GET" class="mb-6 flex items-stretch gap-2 w-full max-w-lg">
    <input type="text" name="q"
           value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
           placeholder="Links durchsuchen..."
           class="flex-1 rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
    <button type="submit"
            class="inline-flex items-center justify-center gap-2 px-5 rounded-xl bg-ibc-green text-white font-semibold hover:bg-ibc-green-dark transition-colors shadow-sm text-sm">
        <i class="fas fa-search"></i>Suchen
    </button>
    <?php if ($searchQuery !== ''): ?>
    <a href="index.php"
       class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 rounded-xl font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition text-sm">
        <i class="fas fa-times"></i>Zurücksetzen
    </a>
    <?php endif; ?>
</form>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-xl text-sm">
    <i class="fas fa-check-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<?php if (empty($links)): ?>
<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-12 text-center">
    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
        <i class="fas fa-link text-gray-400 dark:text-gray-600 text-2xl" aria-hidden="true"></i>
    </div>
    <?php if ($searchQuery !== ''): ?>
    <p class="text-gray-600 dark:text-gray-400 font-medium">Keine Links für „<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" gefunden.</p>
    <?php else: ?>
    <p class="text-gray-600 dark:text-gray-400 font-medium">Noch keine Links vorhanden.</p>
    <?php if ($canManage): ?>
    <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">Klicken Sie auf „Neuer Link", um den ersten Link hinzuzufügen.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">
    <?php foreach ($links as $link):
        $rawUrl  = $link['url'] ?? '';
        $parsed  = parse_url($rawUrl);
        $scheme  = strtolower($parsed['scheme'] ?? '');
        $url     = (in_array($scheme, ['http', 'https']) && !empty($parsed['host'])) ? $rawUrl : '#';
        $icon = htmlspecialchars($link['icon'] ?? 'fas fa-external-link-alt', ENT_QUOTES, 'UTF-8');
        $linkDbId = $link['id'] ?? null;
    ?>
    <div class="w-full group bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm hover:shadow-md hover:border-ibc-green/30 dark:hover:border-ibc-green/30 transition-all duration-200 flex flex-col overflow-hidden">
        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="flex items-start gap-4 p-5 flex-1">
            <div class="flex-shrink-0 w-11 h-11 bg-green-50 dark:bg-green-900/30 rounded-xl flex items-center justify-center group-hover:scale-110 group-hover:bg-ibc-green group-hover:text-white transition-all duration-200">
                <i class="<?php echo $icon; ?> text-ibc-green group-hover:text-white text-lg transition-colors duration-200"></i>
            </div>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 group-hover:text-ibc-green dark:group-hover:text-ibc-green-light transition-colors duration-200 leading-snug break-words hyphens-auto">
                    <?php echo htmlspecialchars($link['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <?php if (!empty($link['description'])): ?>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed break-words hyphens-auto">
                    <?php echo htmlspecialchars($link['description'], ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php endif; ?>
            </div>
            <i class="fas fa-external-link-alt text-gray-300 dark:text-gray-600 text-xs flex-shrink-0 mt-1 group-hover:text-ibc-green transition-colors duration-200"></i>
        </a>

        <?php if ($canManage && $linkDbId !== null): ?>
        <div class="link-actions hidden px-5 pb-4 pt-0 flex justify-end gap-4">
            <a href="edit.php?id=<?php echo (int)$linkDbId; ?>"
               class="inline-flex items-center gap-1.5 px-3 py-2 min-h-[44px] text-xs bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-700 transition font-medium">
                <i class="fas fa-edit"></i>Bearbeiten
            </a>
            <form method="POST" action="index.php" data-confirm="Link wirklich löschen?" class="inline delete-form">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="link_id" value="<?php echo (int)$linkDbId; ?>">
                <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-2 min-h-[44px] text-xs bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/50 transition font-medium">
                    <i class="fas fa-trash"></i>Löschen
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var msg = this.dataset.confirm || 'Wirklich löschen?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});

const toggleBtn = document.getElementById('toggle-edit-mode');
if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        const isActive = toggleBtn.classList.toggle('bg-ibc-green');
        toggleBtn.classList.toggle('text-white', isActive);
        toggleBtn.classList.toggle('border-ibc-green', isActive);
        toggleBtn.classList.toggle('shadow-md', isActive);
        toggleBtn.classList.toggle('hover:bg-gray-50', !isActive);
        toggleBtn.classList.toggle('dark:hover:bg-gray-700', !isActive);
        toggleBtn.classList.toggle('hover:border-gray-400', !isActive);
        toggleBtn.classList.toggle('dark:hover:border-gray-500', !isActive);
        toggleBtn.classList.toggle('bg-white', !isActive);
        toggleBtn.classList.toggle('dark:bg-gray-800', !isActive);
        toggleBtn.classList.toggle('border-gray-300', !isActive);
        toggleBtn.classList.toggle('dark:border-gray-600', !isActive);
        toggleBtn.classList.toggle('text-gray-600', !isActive);
        toggleBtn.classList.toggle('dark:text-gray-300', !isActive);
        document.querySelectorAll('.link-actions').forEach(function(el) {
            el.classList.toggle('hidden', !isActive);
        });
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
