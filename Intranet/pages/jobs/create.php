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

<style>
.jcr-container {
    max-width: 42rem;
    margin-left: auto;
    margin-right: auto;
    animation: springFadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes springFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.jcr-back-link {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    margin-bottom: 1.5rem;
    transition: color 0.2s;
}

.jcr-back-link:hover {
    color: var(--ibc-green);
}

.jcr-error-box {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 0.5rem;
    color: #dc2626;
}

.dark-mode .jcr-error-box {
    background-color: rgba(127, 29, 29, 0.2);
    border-color: rgba(239, 68, 68, 0.4);
    color: #fca5a5;
}

.jcr-error-item {
    display: flex;
    align-items: center;
    margin: 0.25rem 0;
    font-size: 0.875rem;
}

.jcr-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    padding: 2rem;
    box-shadow: var(--shadow-card);
    transition: box-shadow 0.2s;
}

.jcr-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.dark-mode .jcr-card {
    border: 1px solid var(--border-color);
}

.jcr-header {
    margin-bottom: 1.5rem;
}

.jcr-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (max-width: 640px) {
    .jcr-title {
        font-size: 1.5rem;
    }
}

.jcr-subtitle {
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.jcr-form-group {
    margin-bottom: 1.5rem;
}

.jcr-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.jcr-required {
    color: #ef4444;
}

.jcr-input,
.jcr-select,
.jcr-textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 1rem;
    transition: all 0.2s;
    min-height: 44px;
}

.jcr-input:focus,
.jcr-select:focus,
.jcr-textarea:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.dark-mode .jcr-input:focus,
.dark-mode .jcr-select:focus,
.dark-mode .jcr-textarea:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

.jcr-textarea {
    resize: vertical;
    min-height: 120px;
}

.jcr-hint {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.jcr-hint-success {
    color: var(--ibc-green);
}

.jcr-info-box {
    padding: 0.75rem;
    background-color: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 0.5rem;
    margin-bottom: 0.75rem;
}

.dark-mode .jcr-info-box {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.jcr-info-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.jcr-radio-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.jcr-radio-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.875rem;
}

.jcr-radio-option input[type="radio"] {
    min-height: 44px;
    min-width: 44px;
    accent-color: var(--ibc-blue);
}

.jcr-button-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding-top: 1rem;
    margin-top: 1rem;
}

@media (min-width: 768px) {
    .jcr-button-group {
        flex-direction: row;
        justify-content: flex-end;
    }
}

.jcr-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    min-height: 44px;
    width: 100%;
}

@media (min-width: 640px) {
    .jcr-btn {
        width: auto;
    }
}

.jcr-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    color: white;
    box-shadow: var(--shadow-card);
}

.jcr-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-card-hover);
}

.jcr-btn-secondary {
    background-color: var(--border-color);
    color: var(--text-main);
}

.jcr-btn-secondary:hover {
    background-color: var(--text-muted);
}

.dark-mode .jcr-btn-secondary {
    background-color: var(--text-muted);
    color: var(--bg-body);
}

.dark-mode .jcr-btn-secondary:hover {
    background-color: var(--text-main);
}

.jcr-upload-field {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<div class="jcr-container">
    <div class="jcr-header">
        <a href="index.php" class="jcr-back-link">
            <i class="fas fa-arrow-left"></i>Zurück zur Job- &amp; Praktikumsbörse
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="jcr-error-box">
        <?php foreach ($errors as $error): ?>
        <div class="jcr-error-item">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="jcr-card">
        <div class="jcr-header">
            <h1 class="jcr-title">
                <i class="fas fa-plus-circle"></i>
                Anzeige erstellen
            </h1>
            <p class="jcr-subtitle">
                Stelle deine Anzeige ein und lass andere Mitglieder wissen, wonach du suchst.
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div class="jcr-form-group">
                <label class="jcr-label">
                    Titel <span class="jcr-required">*</span>
                </label>
                <input
                    type="text"
                    name="title"
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="z.B. Suche Praktikum im Bereich Marketing"
                    class="jcr-input"
                >
            </div>

            <!-- Search Type -->
            <div class="jcr-form-group">
                <label class="jcr-label">
                    Gesuchter Typ <span class="jcr-required">*</span>
                </label>
                <select name="search_type" required class="jcr-select">
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
            <div class="jcr-form-group">
                <label class="jcr-label">
                    Beschreibung <span class="jcr-required">*</span>
                </label>
                <textarea
                    name="description"
                    required
                    rows="6"
                    placeholder="Beschreibe, wonach du suchst, deine Qualifikationen, Verfügbarkeit usw."
                    class="jcr-textarea"
                ><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <!-- PDF Upload -->
            <div class="jcr-form-group">
                <label class="jcr-label">Lebenslauf (optional)</label>
                <?php if ($profileCvPath !== null): ?>
                <div class="jcr-info-box">
                    <div class="jcr-info-title">
                        <i class="fas fa-user-circle"></i>
                        Du hast bereits einen Lebenslauf in deinem Profil hinterlegt.
                    </div>
                    <div class="jcr-radio-group">
                        <label class="jcr-radio-option">
                            <input type="radio" name="cv_source" value="profile" id="cv_source_profile">
                            <span><i class="fas fa-file-pdf"></i>Lebenslauf aus Profil übernehmen</span>
                        </label>
                        <label class="jcr-radio-option">
                            <input type="radio" name="cv_source" value="upload" id="cv_source_upload" checked>
                            <span>Neue Datei hochladen</span>
                        </label>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="cv_source" value="upload">
                <?php endif; ?>
                <div id="cv_upload_field" class="jcr-upload-field">
                    <input
                        type="file"
                        name="cv_pdf"
                        id="cv_pdf_input"
                        accept=".pdf,application/pdf"
                        class="jcr-input"
                    >
                    <p class="jcr-hint jcr-hint-success">
                        <i class="fas fa-shield-alt"></i>
                        <span>Ausschließlich <strong>.pdf</strong>-Dateien erlaubt. Maximum: <strong>5 MB</strong>.</span>
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
            <div class="jcr-button-group">
                <a href="index.php" class="jcr-btn jcr-btn-secondary">
                    Abbrechen
                </a>
                <button type="submit" class="jcr-btn jcr-btn-primary">
                    <i class="fas fa-paper-plane"></i>Anzeige erstellen
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
