<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/BlogPost.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get current user info
$user = Auth::user();
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Security: Check if user is authorized to create/edit blog posts
if (!BlogPost::canAuth($userRole)) {
    header('Location: index.php');
    exit;
}

// Determine if this is an edit or create operation
$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$post = null;
$isEdit = false;

if ($postId) {
    $post = BlogPost::getById($postId);
    if (!$post) {
        // Post not found, redirect to index
        $_SESSION['error_message'] = 'Beitrag nicht gefunden.';
        header('Location: index.php');
        exit;
    }
    $isEdit = true;
}

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    // Get form data
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $externalLink = trim($_POST['external_link'] ?? '');
    
    // Validate required fields
    if (empty($title)) {
        $errors[] = 'Bitte geben Sie einen Titel ein.';
    }
    
    if (empty($category)) {
        $errors[] = 'Bitte wählen Sie eine Kategorie aus.';
    }
    
    if (empty($content)) {
        $errors[] = 'Bitte geben Sie einen Inhalt ein.';
    }
    
    // Validate category is one of the allowed values
    $allowedCategories = ['Allgemein', 'IT', 'Marketing', 'Human Resources', 'Qualitätsmanagement', 'Akquise', 'Vorstand'];
    if (!empty($category) && !in_array($category, $allowedCategories)) {
        $errors[] = 'Ungültige Kategorie ausgewählt.';
    }

    // Only board roles may use the Vorstand category
    if ($category === 'Vorstand' && !in_array($userRole, Auth::BOARD_ROLES)) {
        $errors[] = 'Die Kategorie "Vorstand" darf nur von Vorstandsmitgliedern verwendet werden.';
    }
    
    // Validate external link if provided
    if (!empty($externalLink) && !filter_var($externalLink, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte geben Sie eine gültige URL für den externen Link ein.';
    }
    
    if (empty($errors)) {
        // Prepare data array
        $data = [
            'title' => $title,
            'category' => $category,
            'content' => $content,
            'external_link' => $externalLink ?: null
        ];
        
        // Handle image upload if provided
        if (isset($_FILES['image'])) {
            $uploadError = $_FILES['image']['error'];
            
            if ($uploadError === UPLOAD_ERR_OK) {
                $blogUploadDir = __DIR__ . '/../../uploads/blog/';
                if (!is_dir($blogUploadDir)) {
                    mkdir($blogUploadDir, 0775, true);
                }
                if (!is_writable($blogUploadDir)) {
                    $errors[] = 'Das Upload-Verzeichnis ist nicht beschreibbar. Bitte kontaktieren Sie den Administrator.';
                }
                if (empty($errors)) {
                $uploadResult = SecureImageUpload::uploadImage($_FILES['image'], $blogUploadDir);
                
                if ($uploadResult['success']) {
                    // Delete old image if updating and old image exists
                    if ($isEdit && !empty($post['image_path'])) {
                        SecureImageUpload::deleteImage($post['image_path']);
                    }
                    $data['image_path'] = $uploadResult['path'];
                } else {
                    $errors[] = $uploadResult['error'];
                }
                } // end if (empty($errors)) - is_writable check
            } elseif ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Die hochgeladene Datei ist zu groß. Maximum: 5MB';
            } elseif ($uploadError === UPLOAD_ERR_PARTIAL) {
                $errors[] = 'Die Datei wurde nur teilweise hochgeladen. Bitte versuchen Sie es erneut.';
            } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Fehler beim Hochladen der Datei (Code: ' . $uploadError . ')';
            }
        }
        
        // Keep existing image if no new image uploaded and post exists
        if (empty($errors) && $isEdit && !empty($post['image_path']) && !isset($data['image_path'])) {
            $data['image_path'] = $post['image_path'];
        }
        
        // If no errors, create or update the post
        if (empty($errors)) {
            try {
                if ($isEdit) {
                    // Update existing post
                    if (BlogPost::update($postId, $data)) {
                        $_SESSION['success_message'] = 'Beitrag erfolgreich aktualisiert!';
                        header('Location: index.php');
                        exit;
                    } else {
                        $errors[] = 'Fehler beim Aktualisieren des Beitrags. Bitte versuchen Sie es erneut.';
                    }
                } else {
                    // Create new post
                    $data['author_id'] = $userId;
                    $newPostId = BlogPost::create($data);
                    if ($newPostId) {
                        // Send newsletter email to all subscribed users
                        $subscribers = User::getNewsletterSubscribers();
                        $excerpt = mb_strimwidth(strip_tags($content), 0, 200, '...');
                        foreach ($subscribers as $subscriber) {
                            $sent = MailService::sendBlogNewsletter(
                                $subscriber['email'],
                                $subscriber['first_name'] ?? '',
                                $title,
                                $excerpt,
                                $newPostId
                            );
                            if (!$sent) {
                                error_log("Blog newsletter: failed to send to " . $subscriber['email'] . " for post #{$newPostId}");
                            }
                        }

                        $_SESSION['success_message'] = 'Beitrag erfolgreich erstellt!';
                        header('Location: index.php');
                        exit;
                    } else {
                        $errors[] = 'Fehler beim Erstellen des Beitrags. Bitte versuchen Sie es erneut.';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'Fehler: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Pre-fill form values - Use POST data if form was submitted (even with errors), otherwise use existing post or empty
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Preserve user input on validation errors
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $externalLink = trim($_POST['external_link'] ?? '');
    $imagePath = $post['image_path'] ?? '';
} else {
    // Initial form load - use existing post data or empty
    $title = $post['title'] ?? '';
    $category = $post['category'] ?? '';
    $content = $post['content'] ?? '';
    $externalLink = $post['external_link'] ?? '';
    $imagePath = $post['image_path'] ?? '';
}

$pageTitle = $isEdit ? 'Beitrag bearbeiten - IBC Intranet' : 'Neuen Beitrag erstellen - IBC Intranet';
ob_start();
?>

<style>
@keyframes beFadeIn {
    from {
        opacity: 0;
        transform: translateY(16px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.be-container {
    max-width: 56rem;
    margin: 0 auto;
}

.be-header {
    margin-bottom: 1.5rem;
}

.be-back-btn {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    font-weight: 500;
    margin-bottom: 1rem;
    transition: opacity 0.2s ease;
}

.be-back-btn:hover {
    opacity: 0.8;
}

.be-alert {
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 0.5rem;
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
    animation: beFadeIn 0.3s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .be-alert {
    background-color: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
}

.be-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1rem;
    box-shadow: var(--shadow-card);
    animation: beFadeIn 0.4s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .be-card {
    background: linear-gradient(135deg, rgba(26, 31, 46, 0.95) 0%, rgba(20, 25, 40, 0.98) 100%);
    border-color: rgba(255, 255, 255, 0.07);
}

@media (min-width: 640px) {
    .be-card {
        padding: 1.5rem;
    }
}

@media (min-width: 900px) {
    .be-card {
        padding: 2rem;
    }
}

.be-card-header {
    margin-bottom: 1.5rem;
}

.be-page-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

@media (min-width: 640px) {
    .be-page-title {
        font-size: 2rem;
    }
}

.be-page-subtitle {
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.be-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

.be-form-group {
    display: flex;
    flex-direction: column;
}

.be-form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.be-form-required {
    color: #ef4444;
    margin-left: 0.25rem;
}

.be-input,
.be-select,
.be-textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    background: var(--bg-body);
    color: var(--text-main);
    font-size: 1rem;
    transition: all 0.2s ease;
    font-family: sans-serif;
}

.be-input::placeholder,
.be-textarea::placeholder {
    color: var(--text-muted);
}

.be-input:focus,
.be-select:focus,
.be-textarea:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.be-textarea {
    resize: vertical;
    min-height: 200px;
}

.be-form-help {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.be-image-preview-section {
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.dark-mode .be-image-preview-section {
    border-color: rgba(255, 255, 255, 0.1);
}

.be-current-image {
    margin-bottom: 1rem;
}

.be-current-image-label {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    display: block;
}

.be-current-image-img {
    max-width: 16rem;
    height: 12rem;
    object-fit: cover;
    border-radius: 0.5rem;
    box-shadow: var(--shadow-card);
}

.be-form-actions {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding-top: 1.5rem;
}

@media (min-width: 900px) {
    .be-form-actions {
        flex-direction: row;
        justify-content: flex-end;
    }
}

.be-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    border-radius: 0.5rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    gap: 0.5rem;
}

.be-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue) 0%, rgba(37, 99, 235, 0.9) 100%);
    color: white;
    box-shadow: var(--shadow-card);
}

.be-btn-primary:hover {
    box-shadow: var(--shadow-card-hover);
}

.be-btn-secondary {
    background: var(--bg-body);
    color: var(--text-main);
    border: 1px solid var(--border-color);
}

.be-btn-secondary:hover {
    background: rgba(0, 0, 0, 0.05);
}

.dark-mode .be-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.05);
}

.be-btn-delete {
    background: linear-gradient(135deg, #ef4444 0%, rgba(239, 68, 68, 0.9) 100%);
    color: white;
    box-shadow: var(--shadow-card);
}

.be-btn-delete:hover {
    box-shadow: var(--shadow-card-hover);
}

@media (max-width: 899px) {
    .be-btn {
        width: 100%;
    }
}
</style>

<div class="be-container">
    <!-- Back Button -->
    <div class="be-header">
        <a href="index.php" class="be-back-btn">
            <i class="fas fa-arrow-left" style="margin-right: 0.5rem;"></i>Zurück zu News & Updates
        </a>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="be-alert">
        <?php foreach ($errors as $error): ?>
            <div><i class="fas fa-exclamation-circle" style="margin-right: 0.5rem;"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Main Card -->
    <div class="be-card">
        <!-- Header -->
        <div class="be-card-header">
            <h1 class="be-page-title">
                <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?>" style="color: var(--ibc-blue);"></i>
                <?php echo $isEdit ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?>
            </h1>
            <p class="be-page-subtitle">
                <?php echo $isEdit ? 'Bearbeite die Details Deines Beitrags.' : 'Erstelle einen neuen Beitrag für News & Updates.'; ?>
            </p>
        </div>

        <!-- Form -->
        <form method="POST" enctype="multipart/form-data" class="be-form">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div class="be-form-group">
                <label class="be-form-label">
                    Titel
                    <span class="be-form-required">*</span>
                </label>
                <input
                    type="text"
                    name="title"
                    required
                    value="<?php echo htmlspecialchars($title); ?>"
                    placeholder="Geben Sie einen aussagekräftigen Titel ein"
                    class="be-input"
                >
            </div>

            <!-- Category -->
            <div class="be-form-group">
                <label class="be-form-label">
                    Kategorie
                    <span class="be-form-required">*</span>
                </label>
                <select
                    name="category"
                    required
                    class="be-select"
                >
                    <option value="">-- Kategorie wählen --</option>
                    <option value="Allgemein" <?php echo $category === 'Allgemein' ? 'selected' : ''; ?>>Allgemein</option>
                    <option value="IT" <?php echo $category === 'IT' ? 'selected' : ''; ?>>IT</option>
                    <option value="Marketing" <?php echo $category === 'Marketing' ? 'selected' : ''; ?>>Marketing</option>
                    <option value="Human Resources" <?php echo $category === 'Human Resources' ? 'selected' : ''; ?>>Human Resources</option>
                    <option value="Qualitätsmanagement" <?php echo $category === 'Qualitätsmanagement' ? 'selected' : ''; ?>>Qualitätsmanagement</option>
                    <option value="Akquise" <?php echo $category === 'Akquise' ? 'selected' : ''; ?>>Akquise</option>
                    <?php if (in_array($userRole, Auth::BOARD_ROLES)): ?>
                    <option value="Vorstand" <?php echo $category === 'Vorstand' ? 'selected' : ''; ?>>Vorstand</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Content -->
            <div class="be-form-group">
                <label class="be-form-label">
                    Inhalt
                    <span class="be-form-required">*</span>
                </label>
                <textarea
                    name="content"
                    required
                    rows="10"
                    placeholder="Schreibe Deinen Beitrag hier..."
                    class="be-textarea"
                ><?php echo htmlspecialchars($content); ?></textarea>
                <p class="be-form-help">
                    Der Inhalt wird als reiner Text gespeichert. HTML-Tags werden nicht unterstützt.
                </p>
            </div>

            <!-- External Link -->
            <div class="be-form-group">
                <label class="be-form-label">Externer Link (optional)</label>
                <input
                    type="url"
                    name="external_link"
                    value="<?php echo htmlspecialchars($externalLink); ?>"
                    placeholder="https://beispiel.de/artikel"
                    class="be-input"
                >
                <p class="be-form-help">
                    Link zu einer externen Quelle oder weiteren Informationen.
                </p>
            </div>

            <!-- Image Upload -->
            <div class="be-image-preview-section">
                <label class="be-form-label">Bild (optional)</label>

                <!-- Current Image Preview -->
                <?php if ($imagePath): ?>
                <div class="be-current-image">
                    <span class="be-current-image-label">Aktuelles Bild:</span>
                    <img src="/<?php echo htmlspecialchars($imagePath); ?>"
                         alt="Aktuelles Bild"
                         class="be-current-image-img">
                </div>
                <?php endif; ?>

                <!-- File Input -->
                <input
                    type="file"
                    name="image"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    class="be-input"
                >
                <p class="be-form-help">
                    Erlaubt: JPG, PNG, GIF, WebP. Maximum: 5MB. Das Bild wird sicher verarbeitet und validiert.
                </p>
            </div>

            <!-- Submit Buttons -->
            <div class="be-form-actions">
                <a href="index.php" class="be-btn be-btn-secondary">
                    Abbrechen
                </a>
                <button type="submit" class="be-btn be-btn-primary">
                    <i class="fas fa-save"></i>
                    <?php echo $isEdit ? 'Änderungen speichern' : 'Beitrag erstellen'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
