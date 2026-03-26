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

<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <a href="index.php" class="text-blue-600 hover:text-blue-700 inline-flex items-center mb-4">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zu News & Updates
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <?php foreach ($errors as $error): ?>
            <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-4 sm:p-6 md:p-8">
        <div class="mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?> text-blue-600 dark:text-blue-400 mr-2"></i>
                <?php echo $isEdit ? 'Beitrag bearbeiten' : 'Neuen Beitrag erstellen'; ?>
            </h1>
            <p class="text-gray-600 dark:text-gray-300 mt-2">
                <?php echo $isEdit ? 'Bearbeite die Details Deines Beitrags.' : 'Erstelle einen neuen Beitrag für News & Updates.'; ?>
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            
            <!-- Title -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Titel *</label>
                <input 
                    type="text" 
                    name="title" 
                    required 
                    value="<?php echo htmlspecialchars($title); ?>"
                    placeholder="Geben Sie einen aussagekräftigen Titel ein"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400"
                >
            </div>

            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Kategorie *</label>
                <select 
                    name="category" 
                    required 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
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
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Inhalt *</label>
                <textarea 
                    name="content" 
                    required 
                    rows="10"
                    placeholder="Schreibe Deinen Beitrag hier..."
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-sans dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400"
                    style="resize: vertical; min-height: 200px;"
                ><?php echo htmlspecialchars($content); ?></textarea>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    Der Inhalt wird als reiner Text gespeichert. HTML-Tags werden nicht unterstützt.
                </p>
            </div>

            <!-- External Link -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Externer Link (optional)</label>
                <input 
                    type="url" 
                    name="external_link" 
                    value="<?php echo htmlspecialchars($externalLink); ?>"
                    placeholder="https://beispiel.de/artikel"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400"
                >
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    Link zu einer externen Quelle oder weiteren Informationen.
                </p>
            </div>

            <!-- Image Upload -->
            <div class="pb-6 border-b border-gray-200 dark:border-gray-700">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bild (optional)</label>
                <?php if ($imagePath): ?>
                <div class="mb-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Aktuelles Bild:</p>
                    <img src="/<?php echo htmlspecialchars($imagePath); ?>" alt="Aktuelles Bild" class="w-64 h-48 object-cover rounded-lg shadow-md">
                </div>
                <?php endif; ?>
                <input 
                    type="file" 
                    name="image" 
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-300"
                >
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    Erlaubt: JPG, PNG, GIF, WebP. Maximum: 5MB. Das Bild wird sicher verarbeitet und validiert.
                </p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end space-x-4 pt-6">
                <a href="index.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </a>
                <button type="submit" class="px-6 py-2 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i><?php echo $isEdit ? 'Änderungen speichern' : 'Beitrag erstellen'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
