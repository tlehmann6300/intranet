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
if (!Newsletter::canManage($currentUser['role'] ?? '')) {
    header('Location: index.php');
    exit;
}

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $title      = trim($_POST['title'] ?? '');
    $monthYear  = trim($_POST['month_year'] ?? '');

    if ($title === '') {
        $error = 'Bitte geben Sie einen Titel an.';
    } elseif (!isset($_FILES['newsletter_file']) || $_FILES['newsletter_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Bitte wählen Sie eine Newsletter-Datei aus.';
    } else {
        $uploadResult = Newsletter::handleUpload($_FILES['newsletter_file']);

        if (!$uploadResult['success']) {
            $error = $uploadResult['error'];
        } else {
            try {
                Newsletter::create([
                    'title'       => $title,
                    'month_year'  => $monthYear !== '' ? $monthYear : null,
                    'file_path'   => $uploadResult['file_path'],
                    'uploaded_by' => $currentUser['id'],
                ]);
                $_SESSION['success_message'] = 'Newsletter erfolgreich hochgeladen.';
                header('Location: index.php');
                exit;
            } catch (Exception $e) {
                // Clean up the orphaned file if the DB insert failed
                $uploadDir = __DIR__ . '/../../uploads/newsletters/';
                $filePath  = realpath($uploadDir . basename($uploadResult['file_path']));
                if ($filePath !== false && str_starts_with($filePath, realpath($uploadDir))) {
                    @unlink($filePath);
                }
                $error = 'Fehler beim Speichern des Newsletters in der Datenbank.';
            }
        }
    }
}

$title = 'Newsletter hochladen - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex items-center gap-3 mb-2">
        <div class="w-11 h-11 rounded-2xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center shadow-sm">
            <i class="fas fa-upload text-ibc-blue text-xl"></i>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Newsletter hochladen</h1>
    </div>
    <p class="text-gray-500 dark:text-gray-400 text-sm">
        Laden Sie eine Newsletter-Datei (.eml) in das interne Archiv hoch.
    </p>
</div>

<?php if ($error): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-6 sm:p-8 max-w-2xl">
    <form method="POST" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

        <!-- Title -->
        <div>
            <label for="title" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Titel <span class="text-red-500">*</span>
            </label>
            <input type="text" id="title" name="title" required
                   value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="z. B. IBC Newsletter März 2025"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
        </div>

        <!-- Month / Year -->
        <div>
            <label for="month_year" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Monat / Jahr <span class="text-gray-400 font-normal">(optional)</span>
            </label>
            <input type="text" id="month_year" name="month_year"
                   value="<?php echo htmlspecialchars($_POST['month_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   placeholder="z. B. März 2025"
                   class="w-full rounded-xl border-gray-300 dark:border-gray-700 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm">
        </div>

        <!-- File Upload -->
        <div>
            <label for="newsletter_file" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Newsletter-Datei <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <input type="file" id="newsletter_file" name="newsletter_file" required
                       accept=".eml"
                       class="w-full rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm focus:ring-ibc-green focus:border-ibc-green py-2.5 px-4 text-sm text-gray-700 dark:text-gray-300
                              file:mr-4 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-ibc-blue file:text-white hover:file:bg-ibc-blue-dark file:cursor-pointer file:transition-colors">
            </div>
            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                Erlaubtes Format: <strong>.eml</strong> &ndash; Maximale Dateigröße: 20 MB
            </p>
        </div>

        <!-- Actions -->
        <div class="flex flex-col md:flex-row gap-3 pt-2">
            <button type="submit"
                    class="btn-primary w-full sm:w-auto justify-center">
                <i class="fas fa-upload"></i>
                Hochladen
            </button>
            <a href="index.php"
               class="inline-flex items-center justify-center gap-2 px-5 py-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 rounded-xl font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-all text-sm shadow-sm w-full sm:w-auto">
                <i class="fas fa-arrow-left"></i>
                Zurück
            </a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
