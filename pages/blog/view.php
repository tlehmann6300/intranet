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

<div class="max-w-6xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="index.php" class="text-blue-600 hover:text-blue-700 inline-flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zu News & Updates
        </a>
    </div>

    <!-- Success Message -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <?php foreach ($errors as $error): ?>
            <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Main Post Card -->
    <div class="card overflow-hidden mb-8">
        <!-- Full Width Header Image -->
        <?php if (!empty($post['image_path'])): ?>
            <div class="w-full h-48 sm:h-64 md:h-96 overflow-hidden bg-gray-200 dark:bg-gray-700">
                <img src="/<?php echo htmlspecialchars($post['image_path']); ?>" 
                     alt="<?php echo htmlspecialchars($post['title']); ?>"
                     class="w-full h-full object-cover">
            </div>
        <?php else: ?>
            <div class="w-full h-48 sm:h-64 md:h-96 overflow-hidden bg-gray-200 dark:bg-gray-700">
                <img src="/<?php echo htmlspecialchars(BlogPost::DEFAULT_IMAGE); ?>"
                     alt=""
                     class="w-full h-full object-cover">
            </div>
        <?php endif; ?>
        
        <!-- Post Content -->
        <div class="p-4 sm:p-6 md:p-8">
            <!-- Category Badge -->
            <div class="mb-4">
                <span class="px-4 py-2 text-sm font-semibold rounded-full <?php echo getCategoryColor($post['category']); ?>">
                    <?php echo htmlspecialchars($post['category']); ?>
                </span>
            </div>
            
            <!-- Title -->
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-4 break-words hyphens-auto">
                <?php echo htmlspecialchars($post['title']); ?>
            </h1>
            
            <!-- Meta Information -->
            <div class="flex flex-wrap items-center gap-4 text-gray-600 dark:text-gray-400 mb-6 pb-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center min-w-0">
                    <i class="fas fa-user-circle mr-2 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <span class="break-all"><?php echo htmlspecialchars($post['author_email']); ?></span>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-calendar-alt mr-2 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                    <span>
                        <?php 
                            $date = new DateTime($post['created_at']);
                            echo $date->format('d.m.Y H:i');
                        ?>
                    </span>
                </div>
            </div>
            
            <!-- Full Content -->
            <div class="prose max-w-none mb-8">
                <div class="text-gray-700 dark:text-gray-300 text-base sm:text-lg leading-relaxed whitespace-pre-wrap break-words hyphens-auto">
                    <?php echo htmlspecialchars($post['content']); ?>
                </div>
            </div>
            
            <!-- External Link Button -->
            <?php if (!empty($post['external_link'])): ?>
            <div class="mb-2">
                <a href="<?php echo htmlspecialchars($post['external_link']); ?>" 
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center justify-center w-full sm:w-auto px-6 p-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg font-semibold hover:from-green-700 hover:to-green-800 transition-all shadow-lg">
                    <i class="fas fa-external-link-alt mr-2"></i>
                    Mehr Informationen
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Edit Button (Only for Author or Admin/Board) -->
            <?php if ($canEdit): ?>
            <div class="mb-2">
                <a href="edit.php?id=<?php echo (int)$post['id']; ?>" 
                   class="inline-flex items-center justify-center w-full sm:w-auto px-6 p-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg">
                    <i class="fas fa-edit mr-2"></i>
                    Beitrag bearbeiten
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Interaction Section -->
    <div class="card p-4 sm:p-6 md:p-8 mb-8">
        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-heart mr-2 text-red-500"></i>
            Interaktion
        </h2>
        
        <!-- Like Button -->
        <form method="POST" class="mb-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="action" value="toggle_like">
            
            <button type="submit" 
                    class="inline-flex items-center px-6 py-3 rounded-lg font-semibold transition-all shadow-md hover:shadow-lg <?php echo $userHasLiked ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                <i class="fas fa-heart mr-2"></i>
                <?php echo $userHasLiked ? 'Geliked' : 'Liken'; ?>
                <span class="ml-2 px-2 py-1 bg-white bg-opacity-20 rounded-full text-sm">
                    <?php echo (int)$post['like_count']; ?>
                </span>
            </button>
        </form>
    </div>

    <!-- Comments Section -->
    <div class="card p-4 sm:p-6 md:p-8">
        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-comments mr-2 text-blue-500"></i>
            Kommentare (<?php echo count($post['comments']); ?>)
        </h2>
        
        <!-- Existing Comments -->
        <?php if (!empty($post['comments'])): ?>
            <div class="space-y-6 mb-8">
                <?php foreach ($post['comments'] as $comment): ?>
                <?php
                    $isOwner = ((int)$comment['user_id'] === (int)$userId);
                    $reactions = $commentReactions[$comment['id']] ?? [];
                    $allowedReactions = ['👍', '❤️', '😄', '😮', '😢', '🎉'];
                ?>
                    <div class="bg-gray-50 dark:bg-gray-800 p-4 sm:p-6 rounded-lg border border-gray-200 dark:border-gray-700" id="comment-<?php echo (int)$comment['id']; ?>">
                        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div class="flex flex-wrap items-center gap-3 min-w-0">
                                <div class="flex items-center text-gray-700 dark:text-gray-300 min-w-0">
                                    <i class="fas fa-user-circle mr-2 text-blue-600 dark:text-blue-400 text-xl flex-shrink-0"></i>
                                    <span class="font-semibold break-all"><?php echo htmlspecialchars($comment['commenter_email']); ?></span>
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?php 
                                        $commentDate = new DateTime($comment['created_at']);
                                        echo $commentDate->format('d.m.Y H:i');
                                    ?>
                                    <?php if (!empty($comment['updated_at']) && $comment['updated_at'] !== $comment['created_at']): ?>
                                        <span class="ml-1 italic text-gray-400 dark:text-gray-500">(bearbeitet)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Comment actions -->
                            <div class="flex gap-4">
                                <?php if ($isOwner): ?>
                                <button onclick="openEditComment(<?php echo (int)$comment['id']; ?>, <?php echo json_encode($comment['content'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)"
                                        class="text-sm px-3 py-2 min-h-[44px] inline-flex items-center bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-800 transition">
                                    <i class="fas fa-edit mr-1"></i>Bearbeiten
                                </button>
                                <?php endif; ?>
                                <?php if ($isOwner || $canDeleteAnyComment): ?>
                                <form method="POST" onsubmit="return confirm('Kommentar wirklich löschen?');" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                    <button type="submit"
                                            class="text-sm px-3 py-2 min-h-[44px] inline-flex items-center bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 rounded hover:bg-red-200 dark:hover:bg-red-800 transition">
                                        <i class="fas fa-trash mr-1"></i>Löschen
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Comment content (view mode) -->
                        <div class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-words hyphens-auto comment-text-<?php echo (int)$comment['id']; ?>">
                            <?php echo htmlspecialchars($comment['content']); ?>
                        </div>

                        <!-- Edit form (hidden by default) -->
                        <?php if ($isOwner): ?>
                        <div id="edit-form-<?php echo (int)$comment['id']; ?>" class="hidden mt-3">
                            <form method="POST" class="space-y-2">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                <input type="hidden" name="action" value="edit_comment">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                <textarea name="comment_content" rows="4" maxlength="2000" required
                                          id="edit-textarea-<?php echo (int)$comment['id']; ?>"
                                          class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-sans dark:bg-gray-700 dark:text-gray-100"
                                          style="resize: vertical; min-height: 80px;"></textarea>
                                <div class="flex gap-4">
                                    <button type="submit"
                                            class="px-4 py-2 min-h-[44px] inline-flex items-center bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition text-sm">
                                        <i class="fas fa-save mr-1"></i>Speichern
                                    </button>
                                    <button type="button" onclick="closeEditComment(<?php echo (int)$comment['id']; ?>)"
                                            class="px-4 py-2 min-h-[44px] inline-flex items-center bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition text-sm">
                                        Abbrechen
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>

                        <!-- Reactions -->
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <?php foreach ($allowedReactions as $emoji): ?>
                            <?php
                                $reactionData = $reactions[$emoji] ?? ['count' => 0, 'reacted' => false];
                                $activeClass  = $reactionData['reacted']
                                    ? 'bg-blue-100 dark:bg-blue-900 border-blue-400 dark:border-blue-600 text-blue-800 dark:text-blue-200'
                                    : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600';
                            ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                                <input type="hidden" name="action" value="toggle_reaction">
                                <input type="hidden" name="comment_id" value="<?php echo (int)$comment['id']; ?>">
                                <input type="hidden" name="reaction" value="<?php echo htmlspecialchars($emoji); ?>">
                                <button type="submit"
                                        class="inline-flex items-center gap-1 px-3 py-2 min-h-[44px] rounded-full border text-sm transition <?php echo $activeClass; ?>">
                                    <?php echo $emoji; ?>
                                    <?php if ($reactionData['count'] > 0): ?>
                                    <span class="text-xs font-semibold"><?php echo $reactionData['count']; ?></span>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-gray-500 dark:text-gray-400 mb-8">Noch keine Kommentare. Seien Sie der Erste!</p>
        <?php endif; ?>
        
        <!-- Write Comment Form -->
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
            <h3 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-pen mr-2"></i>
                Kommentar schreiben
            </h3>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                <input type="hidden" name="action" value="add_comment">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ihr Kommentar</label>
                    <textarea 
                        name="comment_content" 
                        required 
                        rows="4"
                        maxlength="2000"
                        placeholder="Schreibe Deinen Kommentar hier..."
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-sans dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400"
                        style="resize: vertical; min-height: 100px;"
                    ></textarea>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        Maximum: 2000 Zeichen
                    </p>
                </div>
                
                <div>
                    <button type="submit" 
                            class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Kommentar absenden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditComment(commentId, currentContent) {
    // Show edit form, hide text
    document.getElementById('edit-form-' + commentId).classList.remove('hidden');
    document.querySelector('.comment-text-' + commentId).classList.add('hidden');
    document.getElementById('edit-textarea-' + commentId).value = currentContent;
    document.getElementById('edit-textarea-' + commentId).focus();
}

function closeEditComment(commentId) {
    document.getElementById('edit-form-' + commentId).classList.add('hidden');
    document.querySelector('.comment-text-' + commentId).classList.remove('hidden');
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
