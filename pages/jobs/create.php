<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/JobBoard.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user   = Auth::user();
$userId = (int)$user['id'];

// Load the user's profile CV path so it can be reused in this listing
$profileCvPath = null;
$userRole = $user['role'] ?? '';
if (isMemberRole($userRole)) {
    $userProfile = Member::getProfileByUserId($userId);
} elseif (isAlumniRole($userRole)) {
    $userProfile = Alumni::getProfileByUserId($userId);
} else {
    $userProfile = null;
}
if (!empty($userProfile['cv_path'])) {
    $profileCvPath = $userProfile['cv_path'];
}

$errors = [];
$title       = '';
$searchType  = '';
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    // Per-form rate limiting: prevent job-posting spam without affecting other actions
    $rateLimitWait = checkFormRateLimit('last_job_submit_time');
    if ($rateLimitWait > 0) {
        $errors[] = 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Anzeige erstellst.';
    } else {
        $title       = strip_tags(trim($_POST['title'] ?? ''));
        $searchType  = strip_tags(trim($_POST['search_type'] ?? ''));
        $description = strip_tags(trim($_POST['description'] ?? ''));

        // Validate required fields
        if (empty($title)) {
            $errors[] = 'Bitte geben Sie einen Titel ein.';
        }

        if (empty($searchType) || !in_array($searchType, JobBoard::SEARCH_TYPES, true)) {
            $errors[] = 'Bitte wählen Sie einen gültigen Typ aus.';
        }

        if (empty($description)) {
            $errors[] = 'Bitte geben Sie eine Beschreibung ein.';
        }

        $pdfPath = null;

        // Option A: Reuse the CV from the user's profile
        $cvSource = $_POST['cv_source'] ?? 'upload';
        if ($cvSource === 'profile' && $profileCvPath !== null) {
            $srcFile      = realpath(__DIR__ . '/../../' . $profileCvPath);
            $allowedSrcDir = realpath(__DIR__ . '/../../uploads/cv');
            if ($srcFile !== false && $allowedSrcDir !== false && str_starts_with($srcFile, $allowedSrcDir . DIRECTORY_SEPARATOR)) {
                $uploadDir = __DIR__ . '/../../uploads/jobs/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $htaccess = $uploadDir . '.htaccess';
                if (!file_exists($htaccess)) {
                    file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .php3 .phtml\n");
                }
                $safeName = bin2hex(random_bytes(16)) . '.pdf';
                $destPath = $uploadDir . $safeName;
                if (copy($srcFile, $destPath)) {
                    chmod($destPath, 0644);
                    $pdfPath = 'uploads/jobs/' . $safeName;
                } else {
                    $errors[] = 'Lebenslauf aus Profil konnte nicht übernommen werden.';
                }
            } else {
                $errors[] = 'Lebenslauf aus Profil konnte nicht gefunden werden.';
            }
        }

        // Option B: Upload a new PDF (only when cv_source is not 'profile')
        if ($cvSource !== 'profile' && isset($_FILES['cv_pdf']) && $_FILES['cv_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['cv_pdf'];

            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Die hochgeladene Datei ist zu groß. Maximum: 5 MB.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Fehler beim Hochladen der Datei (Code: ' . (int)$file['error'] . ').';
            } else {
                // --- Strict PDF validation ---

                // 1. Size check (5 MB)
                if ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Die Datei überschreitet die maximale Größe von 5 MB.';
                }

                // 2. MIME type check via finfo (not spoofable via $_FILES['type'])
                if (empty($errors)) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($file['tmp_name']);
                    if ($mime !== 'application/pdf') {
                        $errors[] = 'Die hochgeladene Datei ist keine gültige PDF-Datei.';
                    }
                }

                // 3. Magic-bytes check – PDF starts with "%PDF"
                if (empty($errors)) {
                    $handle = fopen($file['tmp_name'], 'rb');
                    $magic  = fread($handle, 4);
                    fclose($handle);
                    if ($magic !== '%PDF') {
                        $errors[] = 'Die Datei enthält keine gültigen PDF-Daten.';
                    }
                }

                // 4. Move file if all checks passed
                if (empty($errors)) {
                    $uploadDir = __DIR__ . '/../../uploads/jobs/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    // Ensure PHP execution is disabled in the upload directory
                    $htaccess = $uploadDir . '.htaccess';
                    if (!file_exists($htaccess)) {
                        if (file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .php3 .phtml\n") === false) {
                            error_log('jobs/create.php: Failed to write .htaccess to ' . $uploadDir);
                            $errors[] = 'Upload-Konfiguration konnte nicht geschrieben werden.';
                        }
                    }
                    if (!is_writable($uploadDir)) {
                        $errors[] = 'Das Upload-Verzeichnis ist nicht beschreibbar.';
                    } else {
                        $safeName = bin2hex(random_bytes(16)) . '.pdf';
                        $destPath = $uploadDir . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $destPath)) {
                            $pdfPath = 'uploads/jobs/' . $safeName;
                        } else {
                            $errors[] = 'Die Datei konnte nicht gespeichert werden.';
                        }
                    }
                }
            }
        }

        if (empty($errors)) {
            $newId = JobBoard::create([
                'user_id'     => $userId,
                'title'       => $title,
                'search_type' => $searchType,
                'description' => $description,
                'pdf_path'    => $pdfPath,
            ]);

            if ($newId) {
                recordFormSubmit('last_job_submit_time');
                $_SESSION['success_message'] = 'Deine Anzeige wurde erfolgreich erstellt!';
                header('Location: index.php');
                exit;
            } else {
                $errors[] = 'Die Anzeige konnte nicht gespeichert werden. Bitte versuche es erneut.';
                // Clean up uploaded file to avoid orphaned files on disk
                if ($pdfPath !== null) {
                    $uploadedFile = __DIR__ . '/../../' . $pdfPath;
                    $allowedDir = realpath(__DIR__ . '/../../uploads/jobs');
                    $realUploadedFile = realpath($uploadedFile);
                    if ($realUploadedFile !== false && $allowedDir !== false && strpos($realUploadedFile, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                        unlink($realUploadedFile);
                    }
                }
            }
        }
    }
}

$pageTitle = 'Anzeige erstellen - IBC Intranet';
ob_start();
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="index.php" class="text-blue-600 hover:text-blue-700 inline-flex items-center mb-4">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zur Job- &amp; Praktikumsbörse
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg space-y-1">
        <?php foreach ($errors as $error): ?>
        <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-8">
        <div class="mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-plus-circle text-blue-600 mr-2"></i>
                Anzeige erstellen
            </h1>
            <p class="text-gray-600 dark:text-gray-300 mt-2">
                Stelle deine Anzeige ein und lass andere Mitglieder wissen, wonach du suchst.
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div>
                <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Titel <span class="text-red-500">*</span>
                </label>
                <input
                    type="text"
                    name="title"
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="z.B. Suche Praktikum im Bereich Marketing"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
            </div>

            <!-- Search Type -->
            <div>
                <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Gesuchter Typ <span class="text-red-500">*</span>
                </label>
                <select
                    name="search_type"
                    required
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                >
                    <option value="">-- Typ wählen --</option>
                    <?php foreach (JobBoard::SEARCH_TYPES as $type): ?>
                    <option value="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $searchType === $type ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Description -->
            <div>
                <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beschreibung <span class="text-red-500">*</span>
                </label>
                <textarea
                    name="description"
                    required
                    rows="6"
                    placeholder="Beschreibe, wonach du suchst, deine Qualifikationen, Verfügbarkeit usw."
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                    style="resize: vertical; min-height: 120px;"
                ><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- PDF Upload -->
            <div>
                <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Lebenslauf (optional)
                </label>
                <?php if ($profileCvPath !== null): ?>
                <div class="mb-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-user-circle text-blue-500 mr-1"></i>
                        Du hast bereits einen Lebenslauf in deinem Profil hinterlegt.
                    </p>
                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="cv_source" value="profile" id="cv_source_profile" class="text-blue-600">
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                <i class="fas fa-file-pdf text-red-500 mr-1"></i>Lebenslauf aus Profil übernehmen
                            </span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="cv_source" value="upload" id="cv_source_upload" checked class="text-blue-600">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Neue Datei hochladen</span>
                        </label>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="cv_source" value="upload">
                <?php endif; ?>
                <div id="cv_upload_field">
                    <input
                        type="file"
                        name="cv_pdf"
                        id="cv_pdf_input"
                        accept=".pdf,application/pdf"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                    >
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        <i class="fas fa-shield-alt mr-1 text-green-500"></i>
                        Ausschließlich <strong>.pdf</strong>-Dateien erlaubt. Maximum: <strong>5 MB</strong>. Alle anderen Formate werden abgelehnt.
                    </p>
                </div>
                <?php if ($profileCvPath !== null): ?>
                <script>
                (function () {
                    var profileRadio = document.getElementById('cv_source_profile');
                    var uploadRadio  = document.getElementById('cv_source_upload');
                    var uploadField  = document.getElementById('cv_upload_field');
                    var pdfInput     = document.getElementById('cv_pdf_input');
                    function toggle() {
                        var showUpload = uploadRadio.checked;
                        uploadField.style.display = showUpload ? '' : 'none';
                        pdfInput.disabled = !showUpload;
                    }
                    profileRadio.addEventListener('change', toggle);
                    uploadRadio.addEventListener('change', toggle);
                    toggle();
                })();
                </script>
                <?php endif; ?>
            </div>

            <!-- Submit -->
            <div class="flex flex-col md:flex-row justify-end gap-2 pt-4">
                <a href="index.php"
                   class="w-full sm:w-auto text-center px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </a>
                <button type="submit"
                        class="w-full sm:w-auto px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-paper-plane mr-2"></i>Anzeige erstellen
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
