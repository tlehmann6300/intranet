<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Newsletter.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$currentUser = Auth::user();
$canManage   = Newsletter::canManage($currentUser['role'] ?? '');
$error       = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && $canManage) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $nlTitle   = trim($_POST['title'] ?? '');
    $monthYear = trim($_POST['month_year'] ?? '');

    if ($nlTitle === '') {
        $error = 'Bitte geben Sie einen Titel an.';
    } elseif (!isset($_FILES['newsletter_file']) || $_FILES['newsletter_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Bitte wählen Sie eine Datei aus.';
    } else {
        $file = $_FILES['newsletter_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Fehler beim Hochladen der Datei (Code ' . $file['error'] . ').';
        } elseif ($file['size'] > 20971520) {
            $error = 'Die Datei überschreitet die maximale Größe von 20 MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['eml'], true)) {
                $error = 'Nur .eml-Dateien sind erlaubt.';
            } else {
                $uploadDir   = __DIR__ . '/../../uploads/newsletters/';
                $filename    = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destination = $uploadDir . $filename;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $error = 'Die Datei konnte nicht gespeichert werden.';
                } else {
                    try {
                        Newsletter::create([
                            'title'       => $nlTitle,
                            'month_year'  => $monthYear !== '' ? $monthYear : null,
                            'file_path'   => $filename,
                            'uploaded_by' => $currentUser['id'],
                        ]);
                        $_SESSION['success_message'] = 'Newsletter erfolgreich hochgeladen.';
                        header('Location: index.php');
                        exit;
                    } catch (Exception $e) {
                        @unlink($destination);
                        $error = 'Fehler beim Speichern in der Datenbank.';
                    }
                }
            }
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $deleteId = (int) ($_POST['newsletter_id'] ?? 0);
    if ($deleteId > 0) {
        try {
            Newsletter::delete($deleteId);
            $_SESSION['success_message'] = 'Newsletter erfolgreich gelöscht.';
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Fehler beim Löschen des Newsletters.';
        }
    }
    header('Location: index.php');
    exit;
}

$newsletters = [];
try {
    $newsletters = Newsletter::getAll();
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Fehler beim Laden der Newsletter.';
}

$title = 'Newsletter - IBC Intranet';
ob_start();
?>

<div class="overflow-x-hidden">
<!-- Gradient Header Banner -->
<div class="relative mb-8 rounded-2xl overflow-hidden bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 shadow-lg">
    <div class="absolute inset-0 opacity-10 newsletter-hero-pattern"></div>
    <div class="relative px-6 py-8 sm:px-10 sm:py-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center shadow-inner flex-shrink-0">
                <i class="fas fa-envelope-open-text text-white text-2xl"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">Newsletter</h1>
                <p class="text-blue-100 text-sm mt-0.5">Archiv aller versendeten Newsletter</p>
            </div>
        </div>
        <div class="flex items-center gap-2 bg-white/20 backdrop-blur-sm rounded-xl px-5 py-3 self-start sm:self-auto">
            <i class="fas fa-archive text-white text-lg"></i>
            <div>
                <span class="text-2xl font-bold text-white"><?php echo count($newsletters); ?></span>
                <span class="text-blue-100 text-sm ml-1"><?php echo count($newsletters) === 1 ? 'Eintrag' : 'Einträge'; ?></span>
            </div>
        </div>
    </div>
</div>

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

<?php if ($error): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-5 flex items-center gap-2">
        <span class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/40 flex items-center justify-center flex-shrink-0">
            <i class="fas fa-upload text-ibc-blue text-sm"></i>
        </span>
        Newsletter hochladen
    </h2>
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="action" value="upload">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="title" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center gap-1.5">
                    <i class="fas fa-tag text-gray-400 text-xs"></i>
                    Titel <span class="text-red-500">*</span>
                </label>
                <input type="text" id="title" name="title" required
                       value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="z. B. IBC Newsletter März 2025"
                       class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
            </div>

            <div>
                <label for="month_year" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center gap-1.5">
                    <i class="fas fa-calendar-alt text-gray-400 text-xs"></i>
                    Monat / Jahr <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input type="text" id="month_year" name="month_year"
                       value="<?php echo htmlspecialchars($_POST['month_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="z. B. März 2025"
                       class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
            </div>
        </div>

        <div>
            <label for="newsletter_file" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center gap-1.5">
                <i class="fas fa-paperclip text-gray-400 text-xs"></i>
                Datei <span class="text-red-500">*</span>
            </label>
            <input type="file" id="newsletter_file" name="newsletter_file" required accept=".eml"
                   class="w-full rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm py-2.5 px-4 text-sm text-gray-700 dark:text-gray-300
                          file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-ibc-blue file:text-white hover:file:bg-ibc-blue-dark file:cursor-pointer file:transition-colors">
            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Erlaubtes Format: <strong>.eml</strong> &ndash; Max. 20 MB
            </p>
        </div>

        <div class="pt-2">
            <button type="submit" class="btn-primary">
                <i class="fas fa-upload"></i>
                Hochladen
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (empty($newsletters)): ?>
<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-14 text-center">
    <div class="w-20 h-20 mx-auto mb-5 rounded-full bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-blue-900/30 dark:to-indigo-900/30 flex items-center justify-center">
        <i class="fas fa-envelope-open-text text-blue-400 dark:text-blue-500 text-3xl" aria-hidden="true"></i>
    </div>
    <p class="text-gray-700 dark:text-gray-300 font-semibold text-lg mb-1">Noch keine Newsletter vorhanden.</p>
    <p class="text-gray-400 dark:text-gray-500 text-sm">
        <?php if ($canManage): ?>Nutze das Formular oben, um den ersten Newsletter hochzuladen.<?php else: ?>Schau später noch einmal vorbei.<?php endif; ?>
    </p>
</div>
<?php else: ?>

<!-- Search Bar -->
<div class="sticky top-0 z-10 mb-4 -mx-4 px-4 py-2 bg-white/80 dark:bg-gray-900/80 backdrop-blur-sm border-b border-gray-100 dark:border-gray-800 sm:static sm:z-auto sm:mb-6 sm:mx-0 sm:px-0 sm:py-0 sm:bg-transparent sm:dark:bg-transparent sm:backdrop-blur-none sm:border-0">
    <div class="relative">
        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
        <input type="search" id="nlSearch" placeholder="Newsletter durchsuchen…"
               class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-ibc-blue/50 transition">
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6" id="nlGrid">
    <?php foreach ($newsletters as $nl):
        $nlId      = (int) ($nl['id'] ?? 0);
        $monthYear = $nl['month_year'] ?? null;
        $createdAt = isset($nl['created_at']) ? date('d.m.Y', strtotime($nl['created_at'])) : '';
        $displayDate = $monthYear ?: $createdAt;
    ?>
    <div class="nl-card w-full bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm hover:shadow-md hover:border-ibc-blue/30 dark:hover:border-ibc-blue/30 hover:scale-[1.02] transition-all duration-200 flex flex-col overflow-hidden"
         data-title="<?php echo htmlspecialchars(strtolower($nl['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
         data-date="<?php echo htmlspecialchars(strtolower($displayDate), ENT_QUOTES, 'UTF-8'); ?>">
        <!-- Blue accent top bar -->
        <div class="h-1.5 w-full bg-gradient-to-r from-ibc-blue to-blue-400"></div>
        <div class="p-5 flex-1 flex flex-col gap-3">
            <!-- Date chip - prominent -->
            <?php if ($displayDate): ?>
            <div class="inline-flex items-center gap-1.5 text-xs font-semibold text-ibc-blue bg-blue-50 dark:bg-blue-900/30 px-2.5 py-1 rounded-full self-start">
                <i class="fas fa-calendar-alt"></i>
                <?php echo htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <div class="flex items-start gap-3 flex-1">
                <div class="w-10 h-10 bg-blue-50 dark:bg-blue-900/30 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5">
                    <i class="fas fa-envelope-open-text text-ibc-blue"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 break-words hyphens-auto leading-snug">
                        <?php echo htmlspecialchars($nl['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </h3>
                </div>
            </div>
            <div class="mt-auto pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center gap-2">
                <a href="view.php?id=<?php echo $nlId; ?>"
                   class="inline-flex items-center gap-2 px-4 py-2.5 text-sm bg-ibc-blue text-white rounded-xl hover:bg-ibc-blue-dark transition font-medium flex-1 justify-center shadow-sm hover:shadow-md">
                    <i class="fas fa-eye"></i>
                    Öffnen
                </a>
                <?php if ($canManage): ?>
                <form method="POST" action="index.php"
                      data-confirm="Newsletter „<?php echo htmlspecialchars($nl['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" wirklich löschen?"
                      class="inline delete-form">
                    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="newsletter_id" value="<?php echo $nlId; ?>">
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 px-3 py-2.5 text-sm bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/50 transition font-medium">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- No results message -->
<div id="nlEmpty" class="hidden text-center py-12 text-gray-400 dark:text-gray-500">
    <i class="fas fa-search text-3xl mb-3"></i>
    <p class="text-sm">Keine Newsletter gefunden.</p>
</div>
<?php endif; ?>

</div><!-- end overflow-x-hidden wrapper -->

<style>
.newsletter-hero-pattern {
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
</style>

<script>
document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = this.dataset.confirm || 'Wirklich löschen?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});

(function () {
    var input = document.getElementById('nlSearch');
    if (!input) return;
    var cards = document.querySelectorAll('.nl-card');
    var emptyMsg = document.getElementById('nlEmpty');
    input.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        var visible = 0;
        cards.forEach(function (card) {
            var match = !q || card.dataset.title.includes(q) || card.dataset.date.includes(q);
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (emptyMsg) emptyMsg.classList.toggle('hidden', visible > 0);
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
