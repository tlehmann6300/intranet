<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/BlogPost.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get current user info
$user = Auth::user();
$userId = $user['id'];
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Get post ID from query parameter
$postId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$postId) {
    $_SESSION['error_message'] = 'Kein Beitrag angegeben.';
    header('Location: index.php');
    exit;
}

// Get the post with all details
$post = BlogPost::getById($postId);

if (!$post) {
    $_SESSION['error_message'] = 'Beitrag nicht gefunden.';
    header('Location: index.php');
    exit;
}

// Check if current user has liked this post
$contentDb = Database::getContentDB();
$likeStmt = $contentDb->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_id = ? AND user_id = ?");
$likeStmt->execute([$postId, $userId]);
$userHasLiked = $likeStmt->fetchColumn() > 0;

$errors = [];
$successMessage = '';

// Handle like toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_like') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $newLikeState = BlogPost::toggleLike($postId, $userId);
    
    // Refresh post data to get updated like count
    $post = BlogPost::getById($postId);
    $userHasLiked = $newLikeState;
    
    $successMessage = $newLikeState ? 'Beitrag geliked!' : 'Like entfernt.';
}

// Handle add comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $commentContent = trim($_POST['comment_content'] ?? '');
    
    if (empty($commentContent)) {
        $errors[] = 'Bitte geben Sie einen Kommentar ein.';
    } elseif (strlen($commentContent) > 2000) {
        $errors[] = 'Der Kommentar ist zu lang. Maximum: 2000 Zeichen.';
    }
    
    if (empty($errors)) {
        try {
            BlogPost::addComment($postId, $userId, $commentContent);
            
            // Refresh post data to get new comment
            $post = BlogPost::getById($postId);
            
            $successMessage = 'Kommentar erfolgreich hinzugefügt!';
        } catch (Exception $e) {
            $errors[] = 'Fehler beim Hinzufügen des Kommentars. Bitte versuchen Sie es erneut.';
        }
    }
}

// Handle edit comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_comment') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $commentId      = (int)($_POST['comment_id'] ?? 0);
    $commentContent = trim($_POST['comment_content'] ?? '');

    if (empty($commentContent)) {
        $errors[] = 'Bitte geben Sie einen Kommentar ein.';
    } elseif (strlen($commentContent) > 2000) {
        $errors[] = 'Der Kommentar ist zu lang. Maximum: 2000 Zeichen.';
    }

    if (empty($errors) && $commentId > 0) {
        if (BlogPost::updateComment($commentId, $userId, $commentContent)) {
            $post = BlogPost::getById($postId);
            $successMessage = 'Kommentar erfolgreich bearbeitet!';
        } else {
            $errors[] = 'Kommentar konnte nicht bearbeitet werden.';
        }
    }
}

// Handle delete comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $commentId = (int)($_POST['comment_id'] ?? 0);

    if ($commentId > 0) {
        if (BlogPost::deleteComment($commentId, $userId, $userRole)) {
            $post = BlogPost::getById($postId);
            $successMessage = 'Kommentar erfolgreich gelöscht!';
        } else {
            $errors[] = 'Kommentar konnte nicht gelöscht werden.';
        }
    }
}

// Handle toggle comment reaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_reaction') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $commentId = (int)($_POST['comment_id'] ?? 0);
    $reaction  = $_POST['reaction'] ?? '';

    $allowedReactions = ['👍', '❤️', '😄', '😮', '😢', '🎉'];
    if ($commentId > 0 && in_array($reaction, $allowedReactions)) {
        BlogPost::toggleCommentReaction($commentId, $userId, $reaction);
        $post = BlogPost::getById($postId);
    }
}

// Load reactions for all comments
$commentIds = array_column($post['comments'] ?? [], 'id');
$commentReactions = BlogPost::getCommentReactions($commentIds, $userId);

// Function to get category color classes
function getCategoryColor($category) {
    $colors = [
        'Allgemein' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
        'IT' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'Marketing' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'Human Resources' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'Qualitätsmanagement' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'Akquise' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'Vorstand' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
    ];
    return $colors[$category] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
}

// Check if user can edit this post (is author OR admin/board)
$canEdit = ($post['author_id'] === $userId) || BlogPost::canAuth($userRole);
// Board roles can delete any comment
$canDeleteAnyComment = in_array($userRole, Auth::BOARD_ROLES);

$title = htmlspecialchars($post['title']) . ' - IBC Intranet';

// Open Graph meta tags for link preview
$og_title       = $post['title'];
$og_type        = 'article';
$og_url         = url('pages/blog/view.php?id=' . (int)$post['id']);
$og_description = !empty($post['content'])
    ? mb_strimwidth(strip_tags($post['content']), 0, 200, '...')
    : '';
$og_image       = asset($post['image_path'] ?? BlogPost::DEFAULT_IMAGE);

ob_start();
?>

<style>
@keyframes bvFadeIn {
    from {
        opacity: 0;
        transform: translateY(16px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.bv-container {
    max-width: 64rem;
    margin: 0 auto;
}

.bv-back-btn {
    display: inline-flex;
    align-items: center;
    margin-bottom: 1.5rem;
    color: var(--ibc-blue);
    text-decoration: none;
    font-weight: 500;
    transition: opacity 0.2s ease;
}

.bv-back-btn:hover {
    opacity: 0.8;
}

.bv-alert {
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    animation: bvFadeIn 0.3s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.bv-alert-success {
    background-color: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--ibc-green);
}

.bv-alert-error {
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.dark-mode .bv-alert-success {
    background-color: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.4);
}

.dark-mode .bv-alert-error {
    background-color: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
}

.bv-post-card {
    overflow: hidden;
    margin-bottom: 2rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-card);
    animation: bvFadeIn 0.4s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .bv-post-card {
    background: linear-gradient(135deg, rgba(26, 31, 46, 0.95) 0%, rgba(20, 25, 40, 0.98) 100%);
    border-color: rgba(255, 255, 255, 0.07);
    box-shadow: var(--shadow-card);
}

.bv-post-image {
    width: 100%;
    height: 12rem;
    overflow: hidden;
    background-color: var(--bg-body);
}

@media (min-width: 640px) {
    .bv-post-image {
        height: 16rem;
    }
}

@media (min-width: 900px) {
    .bv-post-image {
        height: 24rem;
    }
}

.bv-post-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.bv-post-content {
    padding: 1rem;
}

@media (min-width: 640px) {
    .bv-post-content {
        padding: 1.5rem;
    }
}

@media (min-width: 900px) {
    .bv-post-content {
        padding: 2rem;
    }
}

.bv-category-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 600;
    border-radius: 9999px;
    margin-bottom: 1rem;
    background-color: rgba(59, 130, 246, 0.1);
    color: var(--ibc-blue);
}

.dark-mode .bv-category-badge {
    background-color: rgba(59, 130, 246, 0.2);
}

.bv-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
    word-break: break-word;
}

@media (min-width: 640px) {
    .bv-title {
        font-size: 2.25rem;
    }
}

@media (min-width: 900px) {
    .bv-title {
        font-size: 2.25rem;
    }
}

.bv-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    color: var(--text-muted);
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.dark-mode .bv-meta {
    border-color: rgba(255, 255, 255, 0.1);
}

.bv-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.bv-meta-icon {
    color: var(--ibc-blue);
    flex-shrink: 0;
}

.bv-post-body {
    color: var(--text-main);
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 2rem;
    white-space: pre-wrap;
    word-break: break-word;
}

@media (min-width: 640px) {
    .bv-post-body {
        font-size: 1.125rem;
    }
}

.bv-action-buttons {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

@media (min-width: 640px) {
    .bv-action-buttons {
        flex-direction: row;
    }
}

.bv-btn {
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
    width: 100%;
}

@media (min-width: 640px) {
    .bv-btn {
        width: auto;
    }
}

.bv-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue) 0%, rgba(37, 99, 235, 0.9) 100%);
    color: white;
    box-shadow: var(--shadow-card);
}

.bv-btn-primary:hover {
    box-shadow: var(--shadow-card-hover);
}

.bv-btn-secondary {
    background: linear-gradient(135deg, var(--ibc-green) 0%, rgba(16, 185, 129, 0.9) 100%);
    color: white;
    box-shadow: var(--shadow-card);
}

.bv-btn-secondary:hover {
    box-shadow: var(--shadow-card-hover);
}

.bv-btn-gap {
    gap: 0.5rem;
}

.bv-interaction-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-card);
    animation: bvFadeIn 0.5s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .bv-interaction-card {
    background: linear-gradient(135deg, rgba(26, 31, 46, 0.95) 0%, rgba(20, 25, 40, 0.98) 100%);
    border-color: rgba(255, 255, 255, 0.07);
}

@media (min-width: 640px) {
    .bv-interaction-card {
        padding: 1.5rem;
    }
}

@media (min-width: 900px) {
    .bv-interaction-card {
        padding: 2rem;
    }
}

.bv-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (min-width: 640px) {
    .bv-section-title {
        font-size: 1.5rem;
    }
}

.bv-like-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    border-radius: 0.5rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    gap: 0.5rem;
}

.bv-like-btn.liked {
    background: linear-gradient(135deg, #ef4444 0%, rgba(239, 68, 68, 0.9) 100%);
    color: white;
}

.bv-like-btn.unliked {
    background: var(--bg-body);
    color: var(--text-main);
    border: 1px solid var(--border-color);
}

.bv-like-btn:hover {
    box-shadow: var(--shadow-card-hover);
}

.bv-like-count {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 9999px;
    font-size: 0.875rem;
    margin-left: 0.5rem;
}

.bv-comments-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1rem;
    box-shadow: var(--shadow-card);
    animation: bvFadeIn 0.6s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .bv-comments-card {
    background: linear-gradient(135deg, rgba(26, 31, 46, 0.95) 0%, rgba(20, 25, 40, 0.98) 100%);
    border-color: rgba(255, 255, 255, 0.07);
}

@media (min-width: 640px) {
    .bv-comments-card {
        padding: 1.5rem;
    }
}

@media (min-width: 900px) {
    .bv-comments-card {
        padding: 2rem;
    }
}

.bv-comments-list {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.bv-comment {
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1rem;
    animation: bvFadeIn 0.3s cubic-bezier(0.22, 0.68, 0, 1.2);
}

.dark-mode .bv-comment {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.05);
}

@media (min-width: 640px) {
    .bv-comment {
        padding: 1.5rem;
    }
}

.bv-comment-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.bv-comment-author {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.bv-comment-name {
    font-weight: 600;
    color: var(--text-main);
    word-break: break-all;
}

.bv-comment-time {
    font-size: 0.875rem;
    color: var(--text-muted);
}

.bv-comment-time-italic {
    font-style: italic;
    opacity: 0.7;
}

.bv-comment-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.bv-comment-btn {
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 0.375rem;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    background: rgba(59, 130, 246, 0.1);
    color: var(--ibc-blue);
}

.bv-comment-btn:hover {
    background: rgba(59, 130, 246, 0.2);
}

.bv-comment-btn-delete {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.bv-comment-btn-delete:hover {
    background: rgba(239, 68, 68, 0.2);
}

.dark-mode .bv-comment-btn {
    background: rgba(59, 130, 246, 0.15);
}

.dark-mode .bv-comment-btn-delete {
    background: rgba(239, 68, 68, 0.15);
}

.bv-comment-body {
    color: var(--text-main);
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: 1rem;
}

.bv-comment-reactions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1rem;
}

.bv-reaction-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 0.75rem;
    min-height: 44px;
    border-radius: 9999px;
    border: 1px solid var(--border-color);
    font-size: 0.875rem;
    background: var(--bg-card);
    color: var(--text-main);
    transition: all 0.2s ease;
    cursor: pointer;
}

.bv-reaction-btn:hover {
    background: var(--bg-body);
}

.bv-reaction-btn.active {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.5);
    color: var(--ibc-blue);
}

.bv-comment-empty {
    color: var(--text-muted);
    margin-bottom: 2rem;
}

.bv-comment-form-section {
    border-top: 1px solid var(--border-color);
    padding-top: 1.5rem;
}

.bv-form-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bv-form-group {
    margin-bottom: 1rem;
}

.bv-form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.bv-textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    background: var(--bg-body);
    color: var(--text-main);
    font-family: sans-serif;
    font-size: 1rem;
    line-height: 1.5;
    resize: vertical;
    min-height: 100px;
    transition: all 0.2s ease;
}

.bv-textarea:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.bv-form-help {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.bv-hidden {
    display: none;
}

.bv-edit-form {
    margin-top: 0.75rem;
}

.bv-edit-form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 0.5rem;
}

.bv-edit-save-btn {
    padding: 0.5rem 1rem;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    background: var(--ibc-blue);
    color: white;
    border: none;
    border-radius: 0.375rem;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.875rem;
    gap: 0.25rem;
}

.bv-edit-save-btn:hover {
    opacity: 0.9;
}

.bv-edit-cancel-btn {
    padding: 0.5rem 1rem;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    background: var(--bg-body);
    color: var(--text-main);
    border: 1px solid var(--border-color);
    border-radius: 0.375rem;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.875rem;
}

.bv-edit-cancel-btn:hover {
    background: rgba(0, 0, 0, 0.05);
}

.dark-mode .bv-edit-cancel-btn:hover {
    background: rgba(255, 255, 255, 0.05);
}
</style>

<div class="bv-container">
    <!-- Back Button -->
    <a href="index.php" class="bv-back-btn">
        <i class="fas fa-arrow-left" style="margin-right: 0.5rem;"></i>Zurück zu News & Updates
    </a>

    <!-- Success Message -->
    <?php if ($successMessage): ?>
    <div class="bv-alert bv-alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($successMessage); ?></span>
    </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="bv-alert bv-alert-error">
        <div>
            <i class="fas fa-exclamation-circle"></i>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Post Card -->
    <div class="bv-post-card">
        <!-- Full Width Header Image -->
        <div class="bv-post-image">
            <?php if (!empty($post['image_path'])): ?>
                <img src="/<?php echo htmlspecialchars($post['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($post['title']); ?>">
            <?php else: ?>
                <img src="/<?php echo htmlspecialchars(BlogPost::DEFAULT_IMAGE); ?>"
                     alt="">
            <?php endif; ?>
        </div>

        <!-- Post Content -->
        <div class="bv-post-content">
            <!-- Category Badge -->
            <div class="bv-category-badge">
                <?php echo htmlspecialchars($post['category']); ?>
            </div>

            <!-- Title -->
            <h1 class="bv-title">
                <?php echo htmlspecialchars($post['title']); ?>
            </h1>

            <!-- Meta Information -->
            <div class="bv-meta">
                <div class="bv-meta-item">
                    <i class="fas fa-user-circle bv-meta-icon"></i>
                    <span><?php echo htmlspecialchars($post['author_email']); ?></span>
                </div>
                <div class="bv-meta-item">
                    <i class="fas fa-calendar-alt bv-meta-icon"></i>
                    <span>
                        <?php
                            $date = new DateTime($post['created_at']);
                            echo $date->format('d.m.Y H:i');
                        ?>
                    </span>
                </div>
            </div>

            <!-- Full Content -->
            <div class="bv-post-body">
                <?php echo htmlspecialchars($post['content']); ?>
            </div>

            <!-- Action Buttons -->
            <div class="bv-action-buttons">
                <?php if (!empty($post['external_link'])): ?>
                <a href="<?php echo htmlspecialchars($post['external_link']); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="bv-btn bv-btn-secondary bv-btn-gap">
                    <i class="fas fa-external-link-alt"></i>
                    Mehr Informationen
                </a>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                <a href="edit.php?id=<?php echo (int)$post['id']; ?>"
                   class="bv-btn bv-btn-primary bv-btn-gap">
                    <i class="fas fa-edit"></i>
                    Beitrag bearbeiten
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Interaction Section -->
    <div class="bv-interaction-card">
        <h2 class="bv-section-title">
            <i class="fas fa-heart"></i>
            Interaktion
        </h2>

        <!-- Like Button -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="action" value="toggle_like">

            <button type="submit" class="bv-like-btn <?php echo $userHasLiked ? 'liked' : 'unliked'; ?>">
                <i class="fas fa-heart"></i>
                <span><?php echo $userHasLiked ? 'Geliked' : 'Liken'; ?></span>
                <span class="bv-like-count"><?php echo (int)$post['like_count']; ?></span>
            </button>
        </form>
    </div>

    <!-- Comments Section -->
    <div class="bv-comments-card">
        <h2 class="bv-section-title">
            <i class="fas fa-comments"></i>
            Kommentare (<?php echo count($post['comments']); ?>)
        </h2>

        <!-- Existing Comments -->
        <?php if (!empty($post['comments'])): ?>
            <div class="bv-comments-list">
                <?php foreach ($post['comments'] as $comment): ?>
                <?php
                    $isOwner = ((int)$comment['user_id'] === (int)$userId);
                    $reactions = $commentReactions[$comment['id']] ?? [];
                    $allowedReactions = ['👍', '❤️', '😄', '😮', '😢', '🎉'];
                ?>
                    <div class="bv-comment" id="comment-<?php echo (int)$comment['id']; ?>">
                        <div class="bv-comment-header">
                            <div class="bv-comment-author">
                                <i class="fas fa-user-circle" style="color: var(--ibc-blue); font-size: 1.25rem;"></i>
                                <div>
                                    <div class="bv-comment-name"><?php echo htmlspecialchars($comment['commenter_email']); ?></div>
                                    <div class="bv-comment-time">
                                        <i class="fas fa-clock"></i>
                                        <?php
                                            $commentDate = new DateTime($comment['created_at']);
                                            echo $commentDate->format('d.m.Y H:i');
                                        ?>
                                        <?php if (!empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                            <span class="bv-comment-time-italic">(bearbeitet)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Comment actions -->
                            <div class="bv-comment-actions">
                                <?php if ($isOwner): ?>
                                <button onclick="openEditComment(<?php echo (int)$comment['id']; ?>, <?php echo json_encode($comment['content'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)"
                                        class="bv-comment-btn">
                                    <i class="fas fa-edit"></i>Bearbeiten
                                </button>
                                <?php endif; ?>
                                <?php if ($isOwner || $canDeleteAnyComment): ?>
                                <form method="POST" onsubmit="return confirm('Kommentar wirklich löschen?');" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                    <button type="submit" class="bv-comment-btn bv-comment-btn-delete">
                                        <i class="fas fa-trash"></i>Löschen
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Comment content (view mode) -->
                        <div class="bv-comment-body comment-text-<?php echo (int)$comment['id']; ?>">
                            <?php echo htmlspecialchars($comment['content']); ?>
                        </div>

                        <!-- Edit form (hidden by default) -->
                        <?php if ($isOwner): ?>
                        <div id="edit-form-<?php echo (int)$comment['id']; ?>" class="bv-hidden bv-edit-form">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                <input type="hidden" name="action" value="edit_comment">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                <textarea name="comment_content" rows="4" maxlength="2000" required
                                          id="edit-textarea-<?php echo (int)$comment['id']; ?>"
                                          class="bv-textarea"
                                          style="min-height: 80px;"></textarea>
                                <div class="bv-edit-form-actions">
                                    <button type="submit" class="bv-edit-save-btn">
                                        <i class="fas fa-save"></i>Speichern
                                    </button>
                                    <button type="button" onclick="closeEditComment(<?php echo (int)$comment['id']; ?>)"
                                            class="bv-edit-cancel-btn">
                                        Abbrechen
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- Reactions -->
                        <div class="bv-comment-reactions">
                            <?php foreach ($allowedReactions as $emoji): ?>
                            <?php
                                $reactionData = $reactions[$emoji] ?? ['count' => 0, 'reacted' => false];
                            ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                <input type="hidden" name="action" value="toggle_reaction">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                <input type="hidden" name="reaction" value="<?php echo htmlspecialchars($emoji); ?>">
                                <button type="submit" class="bv-reaction-btn <?php echo $reactionData['reacted'] ? 'active' : ''; ?>">
                                    <?php echo $emoji; ?>
                                    <?php if ($reactionData['count'] > 0): ?>
                                    <span><?php echo $reactionData['count']; ?></span>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="bv-comment-empty">Noch keine Kommentare. Seien Sie der Erste!</p>
        <?php endif; ?>

        <!-- Write Comment Form -->
        <div class="bv-comment-form-section">
            <h3 class="bv-form-title">
                <i class="fas fa-pen"></i>
                Kommentar schreiben
            </h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                <input type="hidden" name="action" value="add_comment">

                <div class="bv-form-group">
                    <label class="bv-form-label">Ihr Kommentar</label>
                    <textarea
                        name="comment_content"
                        required
                        rows="4"
                        maxlength="2000"
                        placeholder="Schreibe Deinen Kommentar hier..."
                        class="bv-textarea"
                        style="min-height: 100px;"
                    ></textarea>
                    <p class="bv-form-help">
                        Maximum: 2000 Zeichen
                    </p>
                </div>

                <div>
                    <button type="submit" class="bv-btn bv-btn-primary bv-btn-gap">
                        <i class="fas fa-paper-plane"></i>
                        Kommentar absenden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditComment(commentId, currentContent) {
    document.getElementById('edit-form-' + commentId).classList.remove('bv-hidden');
    document.querySelector('.comment-text-' + commentId).classList.add('bv-hidden');
    document.getElementById('edit-textarea-' + commentId).value = currentContent;
    document.getElementById('edit-textarea-' + commentId).focus();
}

function closeEditComment(commentId) {
    document.getElementById('edit-form-' + commentId).classList.add('bv-hidden');
    document.querySelector('.comment-text-' + commentId).classList.remove('bv-hidden');
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
