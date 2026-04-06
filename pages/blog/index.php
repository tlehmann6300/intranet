<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/BlogPost.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';
$userId = $user['id'];

// Get filter from query parameters
$filterCategory = $_GET['category'] ?? null;

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get posts
$allPosts = BlogPost::getAll($perPage + 1, $offset, $filterCategory); // Get one extra to check if there's a next page
$hasNextPage = count($allPosts) > $perPage;
$posts = array_slice($allPosts, 0, $perPage);

// Get like and comment counts for each post
$contentDb = Database::getContentDB();

// Batch-fetch which posts the current user has liked in a single query
$postIds = array_column($posts, 'id');
$likedPostIds = [];
if (!empty($postIds)) {
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $userLikesStmt = $contentDb->prepare(
        "SELECT post_id FROM blog_likes WHERE post_id IN ($placeholders) AND user_id = ?"
    );
    $userLikesStmt->execute(array_merge($postIds, [$userId]));
    $likedPostIds = array_flip($userLikesStmt->fetchAll(PDO::FETCH_COLUMN));
}

foreach ($posts as &$post) {
    // Get like count
    $likeStmt = $contentDb->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_id = ?");
    $likeStmt->execute([$post['id']]);
    $post['like_count'] = $likeStmt->fetchColumn();
    
    // Check if current user has liked this post (resolved from batch result above)
    $post['user_has_liked'] = isset($likedPostIds[$post['id']]);
    
    // Get comment count
    $commentStmt = $contentDb->prepare("SELECT COUNT(*) FROM blog_comments WHERE post_id = ?");
    $commentStmt->execute([$post['id']]);
    $post['comment_count'] = $commentStmt->fetchColumn();
}

// Define categories with colors
$categories = [
    'Allgemein' => 'gray',
    'IT' => 'blue',
    'Marketing' => 'purple',
    'Human Resources' => 'green',
    'Qualitätsmanagement' => 'yellow',
    'Akquise' => 'red',
    'Vorstand' => 'indigo'
];

// Function to get category color classes
function getCategoryColor($category) {
    $colors = [
        'Allgemein' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        'IT' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
        'Marketing' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
        'Human Resources' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
        'Qualitätsmanagement' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
        'Akquise' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
        'Vorstand' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300'
    ];
    return $colors[$category] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}

// Function to truncate text
function truncateText($text, $maxLength = 150) {
    $text = strip_tags($text);
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, $maxLength) . '...';
}

$title = 'News & Updates - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-newspaper mr-3 text-blue-600"></i>
                News & Updates
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Bleiben Sie über wichtige Neuigkeiten und Updates informiert</p>
        </div>
        
        <?php if (BlogPost::canAuth($userRole)): ?>
            <a href="edit.php" 
               class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg">
                <i class="fas fa-plus mr-2"></i>
                Neuen Beitrag erstellen
            </a>
        <?php endif; ?>
    </div>

    <!-- Newsletter Banner (shown only to users without blog newsletter subscription) -->
    <?php if (!($user['blog_newsletter'] ?? false)): ?>
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/30 dark:to-indigo-900/30 border border-blue-200 dark:border-blue-700 rounded-xl shadow-sm">
        <div class="flex items-center gap-3">
            <i class="fas fa-bell text-blue-600 dark:text-blue-400 text-2xl flex-shrink-0"></i>
            <div>
                <p class="font-semibold text-gray-800 dark:text-gray-100">Immer auf dem Laufenden bleiben</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">Erhalte eine E-Mail, sobald ein neuer Artikel veröffentlicht wird.</p>
            </div>
        </div>
        <a href="../auth/settings.php#notifications" 
           class="w-full sm:w-auto inline-flex items-center justify-center px-5 py-2 min-h-[44px] bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-all shadow-md">
            <i class="fas fa-envelope mr-2"></i>
            Benachrichtigungen aktivieren
        </a>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="mb-6 card dark:bg-gray-800 p-4">
        <div class="overflow-x-auto flex flex-nowrap gap-2 pb-2 scrollbar-hide -mx-1 px-1">
            <a href="index.php"
               class="px-4 py-2 min-h-[44px] inline-flex items-center rounded-lg font-medium transition-all flex-shrink-0 whitespace-nowrap <?php echo $filterCategory === null ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'; ?>">
                <i class="fas fa-th mr-2"></i>Alle
            </a>
            <?php foreach ($categories as $cat => $color): ?>
                <a href="index.php?category=<?php echo urlencode($cat); ?>"
                   class="px-4 py-2 min-h-[44px] inline-flex items-center rounded-lg font-medium transition-all flex-shrink-0 whitespace-nowrap <?php echo $filterCategory === $cat ? 'bg-blue-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'; ?>">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Live Search -->
    <div class="mb-6 relative">
        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
        <input type="search" id="blogSearch" placeholder="Beiträge durchsuchen…"
               class="w-full pl-11 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50 shadow-sm transition">
    </div>

    <!-- News Grid -->
    <?php if (empty($posts)): ?>
        <div class="card dark:bg-gray-800 p-8 text-center">
            <i class="fas fa-inbox text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
            <p class="text-base sm:text-xl text-gray-600 dark:text-gray-300">Keine Beiträge gefunden</p>
            <?php if ($filterCategory): ?>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Versuchen Sie einen anderen Filter</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6" id="blogGrid">
            <?php foreach ($posts as $post):
                // Clean up author display: show name part before <email> if present
                $authorDisplay = $post['author_email'];
                if (preg_match('/^(.+?)\s*<[^>]+>$/', $authorDisplay, $m)) {
                    $authorDisplay = trim($m[1]);
                } elseif (strpos($authorDisplay, '@') !== false) {
                    $authorDisplay = explode('@', $authorDisplay)[0];
                }
            ?>
                <a href="view.php?id=<?php echo (int)$post['id']; ?>"
                   class="blog-card card w-full dark:bg-gray-800 overflow-hidden flex flex-col hover:shadow-xl hover:scale-[1.02] transition-all duration-200 cursor-pointer group"
                   data-title="<?php echo htmlspecialchars(strtolower($post['title']), ENT_QUOTES, 'UTF-8'); ?>"
                   data-category="<?php echo htmlspecialchars(strtolower($post['category']), ENT_QUOTES, 'UTF-8'); ?>"
                   aria-label="Beitrag lesen: <?php echo htmlspecialchars($post['title']); ?>">
                    <!-- Image -->
                    <div class="w-full h-48 bg-gray-200 dark:bg-gray-700 rounded-t-2xl overflow-hidden">
                        <?php if (!empty($post['image_path'])): ?>
                            <img src="/<?php echo htmlspecialchars($post['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($post['title']); ?>"
                                 loading="lazy"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-blue-500 via-blue-600 to-indigo-700">
                                <i class="fas fa-newspaper text-5xl text-white/40"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Content -->
                    <div class="p-5 flex-1 flex flex-col">
                        <!-- Category Badge -->
                        <div class="mb-3">
                            <span class="px-3 py-1 text-xs font-semibold rounded-full <?php echo getCategoryColor($post['category']); ?>">
                                <?php echo htmlspecialchars($post['category']); ?>
                            </span>
                        </div>

                        <!-- Title -->
                        <h3 class="text-base sm:text-lg font-bold text-gray-800 dark:text-gray-100 mb-2 break-words hyphens-auto leading-snug group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                            <?php echo htmlspecialchars($post['title']); ?>
                        </h3>

                        <!-- Date & Author -->
                        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400 mb-3 flex-wrap">
                            <span><i class="fas fa-calendar-alt mr-1"></i><?php $date = new DateTime($post['created_at']); echo $date->format('d.m.Y'); ?></span>
                            <span class="flex items-center min-w-0"><i class="fas fa-user-circle mr-1 text-blue-500 flex-shrink-0"></i><span class="truncate"><?php echo htmlspecialchars($authorDisplay); ?></span></span>
                        </div>

                        <!-- Excerpt -->
                        <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 flex-1 break-words hyphens-auto leading-relaxed">
                            <?php echo htmlspecialchars(truncateText($post['content'])); ?>
                        </p>

                        <!-- Footer -->
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                                <span class="flex items-center">
                                    <i class="fas fa-heart mr-1 <?php echo $post['user_has_liked'] ? 'text-red-500' : 'text-gray-400 dark:text-gray-500'; ?>"></i>
                                    <?php echo $post['like_count']; ?>
                                </span>
                                <span class="flex items-center">
                                    <i class="fas fa-comment mr-1 text-blue-500"></i>
                                    <?php echo $post['comment_count']; ?>
                                </span>
                            </div>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">
                                Lesen <i class="fas fa-arrow-right text-[10px]"></i>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- No search results -->
        <div id="blogEmpty" class="hidden text-center py-12 text-gray-400 dark:text-gray-500">
            <i class="fas fa-search text-3xl mb-3"></i>
            <p class="text-sm">Keine Beiträge gefunden.</p>
        </div>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($page > 1 || $hasNextPage): ?>
        <div class="mt-8 flex justify-center items-center gap-2">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $filterCategory ? '&category=' . urlencode($filterCategory) : ''; ?>"
                   class="px-4 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-all shadow-sm border border-gray-200 dark:border-gray-700">
                    <i class="fas fa-chevron-left mr-1 sm:mr-2"></i><span class="hidden sm:inline">Zurück</span>
                </a>
            <?php endif; ?>

            <div class="px-4 py-2.5 bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-300 rounded-lg font-semibold text-sm">
                <?php echo $page; ?>
            </div>

            <?php if ($hasNextPage): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $filterCategory ? '&category=' . urlencode($filterCategory) : ''; ?>"
                   class="px-4 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-all shadow-sm border border-gray-200 dark:border-gray-700">
                    <span class="hidden sm:inline">Weiter</span><i class="fas fa-chevron-right ml-1 sm:ml-2"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('blogSearch');
    if (!input) return;
    var cards = document.querySelectorAll('.blog-card');
    var emptyMsg = document.getElementById('blogEmpty');
    input.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        var visible = 0;
        cards.forEach(function (card) {
            var match = !q || card.dataset.title.includes(q) || card.dataset.category.includes(q);
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (emptyMsg) emptyMsg.classList.toggle('hidden', visible > 0);
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
