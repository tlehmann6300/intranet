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
            if (!in_array($ext, ['eml', 'msg'], true)) {
                $error = 'Nur .eml- und .msg-Dateien sind erlaubt.';
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

<div class="mb-8">
    <div class="flex items-center gap-3 mb-2">
        <div class="w-11 h-11 rounded-2xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center shadow-sm">
            <i class="fas fa-envelope-open-text text-ibc-blue text-xl"></i>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Newsletter</h1>
    </div>
    <p class="text-gray-500 dark:text-gray-400 text-sm">Archiv aller versendeten Newsletter</p>
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
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
        <i class="fas fa-upload text-ibc-blue"></i>
        Newsletter hochladen
    </h2>
    <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="action" value="upload">

        <div>
            <label for="title" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Titel <span class="text-red-500">*</span>
            </label>
            <input type="text" id="title" name="title" required
                   value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="z. B. IBC Newsletter März 2025"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
        </div>

        <div>
            <label for="month_year" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Monat / Jahr <span class="text-gray-400 font-normal">(optional)</span>
            </label>
            <input type="text" id="month_year" name="month_year"
                   value="<?php echo htmlspecialchars($_POST['month_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="z. B. März 2025"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
        </div>

        <div class="sm:col-span-2">
            <label for="newsletter_file" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Datei <span class="text-red-500">*</span>
            </label>
            <input type="file" id="newsletter_file" name="newsletter_file" required accept=".eml,.msg"
                   class="w-full rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm py-2.5 px-4 text-sm text-gray-700 dark:text-gray-300
                          file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-ibc-blue file:text-white hover:file:bg-ibc-blue-dark file:cursor-pointer file:transition-colors">
            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">Erlaubte Formate: <strong>.eml</strong>, <strong>.msg</strong> &ndash; Max. 20 MB</p>
        </div>

        <div class="sm:col-span-2">
            <button type="submit" class="btn-primary">
                <i class="fas fa-upload"></i>
                Hochladen
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (empty($newsletters)): ?>
<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-12 text-center">
    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
        <i class="fas fa-envelope-open-text text-gray-400 dark:text-gray-600 text-2xl" aria-hidden="true"></i>
    </div>
    <p class="text-gray-600 dark:text-gray-400 font-medium">Noch keine Newsletter vorhanden.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($newsletters as $nl):
        $nlId      = (int) ($nl['id'] ?? 0);
        $monthYear = $nl['month_year'] ?? null;
        $createdAt = isset($nl['created_at']) ? date('d.m.Y', strtotime($nl['created_at'])) : '';
    ?>
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm hover:shadow-md hover:border-ibc-blue/30 dark:hover:border-ibc-blue/30 transition-all duration-200 flex flex-col">
        <div class="p-5 flex-1 flex flex-col gap-3">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 bg-blue-50 dark:bg-blue-900/30 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-envelope-open-text text-ibc-blue"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 break-words hyphens-auto leading-snug">
                        <?php echo htmlspecialchars($nl['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </h3>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo htmlspecialchars($monthYear ?: $createdAt, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </div>
            <div class="mt-auto pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center gap-2">
                <a href="view.php?id=<?php echo $nlId; ?>"
                   class="inline-flex items-center gap-2 px-4 py-2 text-xs bg-ibc-blue text-white rounded-lg hover:bg-ibc-blue-dark transition font-medium flex-1 justify-center">
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
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/50 transition font-medium">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var msg = this.dataset.confirm || 'Wirklich löschen?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
