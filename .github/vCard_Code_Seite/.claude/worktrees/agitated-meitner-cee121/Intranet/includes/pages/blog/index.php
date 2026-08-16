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

// Category styling: semi-transparent colors work in both light + dark mode
function getCategoryStyle($category) {
    $styles = [
        'Allgemein'          => ['c'=>'#6b7280','b'=>'rgba(107,114,128,0.1)','e'=>'rgba(107,114,128,0.2)'],
        'IT'                 => ['c'=>'#3b82f6','b'=>'rgba(59,130,246,0.1)','e'=>'rgba(59,130,246,0.2)'],
        'Marketing'          => ['c'=>'#8b5cf6','b'=>'rgba(139,92,246,0.1)','e'=>'rgba(139,92,246,0.2)'],
        'Human Resources'    => ['c'=>'#22c55e','b'=>'rgba(34,197,94,0.1)','e'=>'rgba(34,197,94,0.2)'],
        'Qualitätsmanagement'=> ['c'=>'#f59e0b','b'=>'rgba(245,158,11,0.1)','e'=>'rgba(245,158,11,0.2)'],
        'Akquise'            => ['c'=>'#ef4444','b'=>'rgba(239,68,68,0.1)','e'=>'rgba(239,68,68,0.2)'],
        'Vorstand'           => ['c'=>'#6366f1','b'=>'rgba(99,102,241,0.1)','e'=>'rgba(99,102,241,0.2)'],
    ];
    return $styles[$category] ?? $styles['Allgemein'];
}

// Format author display from email
function formatAuthorDisplay($email) {
    if (empty($email)) return 'IBC Mitglied';
    $local = explode('@', $email)[0];
    return ucwords(str_replace(['.', '_', '-'], ' ', $local));
}

// Get 1-2 initials from formatted author name
function getAuthorInitials($email) {
    $name  = formatAuthorDisplay($email);
    $parts = array_filter(explode(' ', $name));
    $parts = array_values($parts);
    if (count($parts) >= 2) return strtoupper(mb_substr($parts[0],0,1) . mb_substr($parts[1],0,1));
    return strtoupper(mb_substr($name, 0, 1));
}

// Function to truncate text
function truncateText($text, $maxLength = 150) {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $maxLength) return $text;
    return mb_substr($text, 0, $maxLength) . '…';
}

$title = 'News & Updates - IBC Intranet';
ob_start();
?>
<style>
/* ── Blog Page ───────────────────────────────────────────── */
.blog-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding: 0.125rem 0 0.375rem;
    -ms-overflow-style: none;
}
.blog-filter-bar::-webkit-scrollbar { display: none; }
.blog-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.875rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    text-decoration: none;
    transition: opacity 0.18s, transform 0.18s, box-shadow 0.18s;
    border: 1.5px solid transparent;
    -webkit-tap-highlight-color: transparent;
    flex-shrink: 0;
    min-height: 2.375rem;
}
.blog-chip:hover { opacity: 0.85; transform: translateY(-1px); }
.blog-chip--all-off {
    background: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-muted);
}
.blog-chip--all-on {
    background: var(--ibc-blue);
    color: #fff;
    box-shadow: 0 2px 10px rgba(0,102,179,0.3);
}
.blog-chip--cat-off {
    background: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-muted);
}
/* active state applied inline via PHP */

.blog-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    text-decoration: none;
    transition: border-color 0.2s, box-shadow 0.22s, transform 0.22s cubic-bezier(0.34,1.56,0.64,1);
    cursor: pointer;
    position: relative;
}
.blog-card:hover {
    border-color: var(--ibc-blue);
    box-shadow: 0 8px 32px rgba(0,0,0,0.1);
    transform: translateY(-3px);
}
.blog-card-img {
    width: 100%;
    height: 11rem;
    overflow: hidden;
    position: relative;
    flex-shrink: 0;
}
.blog-card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s ease;
}
.blog-card:hover .blog-card-img img { transform: scale(1.05); }
.blog-cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    border: 1px solid transparent;
}
.blog-author-avatar {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.625rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
    background: var(--ibc-blue);
}
.blog-stat {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-muted);
}
.blog-stat i { font-size: 0.75rem; }
.blog-top-accent {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    z-index: 1;
}
.blog-pagination-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    text-decoration: none;
    transition: border-color 0.18s, background 0.18s, transform 0.18s;
}
.blog-pagination-btn:hover {
    border-color: var(--ibc-blue);
    color: var(--ibc-blue);
    transform: translateY(-1px);
}
.blog-page-indicator {
    display: inline-flex;
    align-items: center;
    padding: 0.625rem 1.125rem;
    background: rgba(0,102,179,0.08);
    border: 1.5px solid rgba(0,102,179,0.2);
    border-radius: 0.625rem;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ibc-blue);
}
.blog-sub-banner {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-left: 4px solid var(--ibc-blue);
    border-radius: 0 0.75rem 0.75rem 0;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.blog-sub-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 1rem;
    background: var(--ibc-blue);
    color: #fff;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    transition: opacity 0.18s;
}
.blog-sub-btn:hover { opacity: 0.88; color: #fff; }
@keyframes blogCardIn {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: none; }
}
.blog-card { animation: blogCardIn 0.3s ease both; }
.blog-card:nth-child(2) { animation-delay: 0.05s; }
.blog-card:nth-child(3) { animation-delay: 0.10s; }
.blog-card:nth-child(4) { animation-delay: 0.15s; }
.blog-card:nth-child(5) { animation-delay: 0.20s; }
.blog-card:nth-child(6) { animation-delay: 0.25s; }
.blog-card:nth-child(n+7) { animation-delay: 0.28s; }
@media (max-width: 640px) {
    .blog-card-img { height: 9.5rem; }
}
</style>

<!-- ── Page Header ─────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,#8b5cf6,#6366f1);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(99,102,241,0.3);flex-shrink:0;">
            <i class="fas fa-newspaper" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div>
            <h1 style="font-size:clamp(1.25rem,4vw,1.625rem);font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">News &amp; Updates</h1>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0.125rem 0 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Neuigkeiten und Beiträge aus dem IBC</p>
        </div>
    </div>
    <?php if (BlogPost::canAuth($userRole)): ?>
    <a href="edit.php"
       style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 3px 12px rgba(99,102,241,0.3);transition:opacity 0.18s,transform 0.18s;flex-shrink:0;"
       onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
       onmouseout="this.style.opacity='1';this.style.transform='none'">
        <i class="fas fa-plus" aria-hidden="true"></i>
        Beitrag erstellen
    </a>
    <?php endif; ?>
</div>

<?php if (!($user['blog_newsletter'] ?? false)): ?>
<!-- ── Subscription Banner ─────────────────────────────────── -->
<div class="blog-sub-banner">
    <div style="display:flex;align-items:center;gap:0.875rem;min-width:0;">
        <i class="fas fa-bell" style="color:var(--ibc-blue);font-size:1.2rem;flex-shrink:0;" aria-hidden="true"></i>
        <div>
            <p style="font-weight:700;font-size:0.9rem;color:var(--text-main);margin:0;">Immer auf dem Laufenden</p>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:0.1rem 0 0;">E-Mail-Benachrichtigung bei neuen Beiträgen</p>
        </div>
    </div>
    <a href="../auth/settings.php#notifications" class="blog-sub-btn">
        <i class="fas fa-envelope" aria-hidden="true"></i>
        Aktivieren
    </a>
</div>
<?php endif; ?>

<!-- ── Category Filter Bar ────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:0.875rem;padding:0.875rem 1rem;margin-bottom:1.75rem;">
    <div class="blog-filter-bar" role="navigation" aria-label="Kategorie filtern">
        <a href="index.php"
           class="blog-chip <?php echo $filterCategory === null ? 'blog-chip--all-on' : 'blog-chip--all-off'; ?>">
            <i class="fas fa-th-large" style="font-size:0.7rem;" aria-hidden="true"></i>
            Alle
        </a>
        <?php foreach ($categories as $cat => $color):
            $cs = getCategoryStyle($cat);
            $isActive = $filterCategory === $cat;
        ?>
        <a href="index.php?category=<?php echo urlencode($cat); ?>"
           class="blog-chip"
           style="<?php echo $isActive
               ? "background:{$cs['c']};color:#fff;border-color:{$cs['c']};box-shadow:0 2px 10px {$cs['b']};"
               : "background:{$cs['b']};color:{$cs['c']};border-color:{$cs['e']};"; ?>">
            <?php echo htmlspecialchars($cat); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($posts)): ?>
<!-- ── Empty State ─────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:1rem;padding:4rem 2rem;text-align:center;">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(139,92,246,0.08);border:1.5px solid rgba(139,92,246,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-newspaper" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Keine Beiträge gefunden</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">
        <?php echo $filterCategory ? 'Versuche einen anderen Filter.' : 'Noch keine Beiträge vorhanden.'; ?>
    </p>
</div>

<?php else: ?>
<!-- ── Posts Grid ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,19rem),1fr));gap:1.125rem;">
    <?php foreach ($posts as $post):
        $cs     = getCategoryStyle($post['category'] ?? 'Allgemein');
        $date   = new DateTime($post['created_at']);
        $author = formatAuthorDisplay($post['author_email'] ?? '');
        $initials = getAuthorInitials($post['author_email'] ?? '');
        // Avatar color based on initials
        $avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#22c55e','#06b6d4','#6366f1'];
        $avatarColor  = $avatarColors[abs(crc32($post['author_email'] ?? '')) % count($avatarColors)];
    ?>
    <a href="view.php?id=<?php echo (int)$post['id']; ?>"
       class="blog-card"
       aria-label="Beitrag lesen: <?php echo htmlspecialchars($post['title']); ?>">

        <!-- Category top accent -->
        <div class="blog-top-accent" style="background:<?php echo $cs['c']; ?>;"></div>

        <!-- Image area -->
        <div class="blog-card-img">
            <?php if (!empty($post['image_path'])): ?>
                <img src="/<?php echo htmlspecialchars($post['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($post['title']); ?>"
                     loading="lazy">
            <?php else: ?>
                <div style="width:100%;height:100%;background:linear-gradient(135deg,<?php echo $cs['b']; ?>,<?php echo str_replace('0.1)','0.18)',$cs['b']); ?>);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-newspaper" style="font-size:2.5rem;color:<?php echo $cs['c']; ?>;opacity:0.4;" aria-hidden="true"></i>
                </div>
            <?php endif; ?>
        </div>

        <!-- Content -->
        <div style="padding:1.125rem;flex:1;display:flex;flex-direction:column;gap:0.625rem;">
            <!-- Category + date row -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                <span class="blog-cat-badge" style="color:<?php echo $cs['c']; ?>;background:<?php echo $cs['b']; ?>;border-color:<?php echo $cs['e']; ?>;">
                    <?php echo htmlspecialchars($post['category'] ?? 'Allgemein'); ?>
                </span>
                <span style="font-size:0.725rem;color:var(--text-muted);white-space:nowrap;">
                    <i class="fas fa-calendar-alt" aria-hidden="true" style="font-size:0.625rem;"></i>
                    <?php echo $date->format('d.m.Y'); ?>
                </span>
            </div>

            <!-- Title -->
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.35;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                <?php echo htmlspecialchars($post['title']); ?>
            </h3>

            <!-- Excerpt -->
            <p style="font-size:0.8125rem;color:var(--text-muted);line-height:1.55;flex:1;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
                <?php echo htmlspecialchars(truncateText($post['content'], 180)); ?>
            </p>

            <!-- Footer: author + stats -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
                <div style="display:flex;align-items:center;gap:0.5rem;min-width:0;overflow:hidden;">
                    <div class="blog-author-avatar" style="background:<?php echo $avatarColor; ?>;">
                        <?php echo htmlspecialchars($initials); ?>
                    </div>
                    <span style="font-size:0.75rem;font-weight:600;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo htmlspecialchars($author); ?>
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:0.75rem;flex-shrink:0;">
                    <span class="blog-stat">
                        <i class="fas fa-heart" style="color:<?php echo $post['user_has_liked'] ? '#ef4444' : 'var(--text-muted)'; ?>;" aria-hidden="true"></i>
                        <?php echo (int)$post['like_count']; ?>
                    </span>
                    <span class="blog-stat">
                        <i class="fas fa-comment" style="color:#3b82f6;" aria-hidden="true"></i>
                        <?php echo (int)$post['comment_count']; ?>
                    </span>
                </div>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Pagination ─────────────────────────────────────────── -->
<?php if ($page > 1 || $hasNextPage): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:0.75rem;margin-top:2.5rem;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
    <a href="?page=<?php echo $page - 1; ?><?php echo $filterCategory ? '&category='.urlencode($filterCategory) : ''; ?>"
       class="blog-pagination-btn">
        <i class="fas fa-chevron-left" style="font-size:0.75rem;" aria-hidden="true"></i>
        Zurück
    </a>
    <?php endif; ?>
    <span class="blog-page-indicator">Seite <?php echo $page; ?></span>
    <?php if ($hasNextPage): ?>
    <a href="?page=<?php echo $page + 1; ?><?php echo $filterCategory ? '&category='.urlencode($filterCategory) : ''; ?>"
       class="blog-pagination-btn">
        Weiter
        <i class="fas fa-chevron-right" style="font-size:0.75rem;" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
