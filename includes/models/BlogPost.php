<?php
/**
 * BlogPost Model
 * Manages blog posts, comments, and likes with cross-database user integration
 */

class BlogPost {
    
    // Default image used when no blog post image has been uploaded
    const DEFAULT_IMAGE = 'assets/img/ibc_logo_original.webp';

    /**
     * Get the image URL for a blog post, falling back to the default image if none is set.
     *
     * @param string|null $imagePath Stored image path from the database
     * @return string URL-ready image path
     */
    public static function getImageUrl(?string $imagePath): string {
        return !empty($imagePath) ? $imagePath : self::DEFAULT_IMAGE;
    }

    /**
     * Get all blog posts with optional filtering and pagination
     * Joins with User DB to get author names
     * 
     * @param int $limit Maximum number of posts to retrieve
     * @param int $offset Starting position for pagination
     * @param string|null $filterCategory Optional category filter
     * @return array Array of blog posts with author information
     */
    public static function getAll($limit, $offset, $filterCategory = null) {
        $contentDb = Database::getContentDB();
        $userDb = Database::getUserDB();
        
        // Build the query with category filter if provided
        $sql = "SELECT 
                    p.id,
                    p.title,
                    p.content,
                    p.image_path,
                    p.external_link,
                    p.category,
                    p.author_id,
                    p.created_at,
                    p.updated_at
                FROM blog_posts p
                WHERE 1=1";
        
        $params = [];
        
        if ($filterCategory !== null) {
            $sql .= " AND p.category = ?";
            $params[] = $filterCategory;
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        $stmt = $contentDb->prepare($sql);
        $stmt->execute($params);
        $posts = $stmt->fetchAll();
        
        if (empty($posts)) {
            return $posts;
        }
        
        // Collect all unique author IDs
        $authorIds = array_unique(array_column($posts, 'author_id'));
        
        // Fetch all author emails in a single query
        $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
        $userStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
        $userStmt->execute($authorIds);
        $authors = $userStmt->fetchAll();
        
        // Create a map of author_id => email
        $authorMap = [];
        foreach ($authors as $author) {
            $authorMap[$author['id']] = $author['email'];
        }
        
        // Add author email and image fallback to each post
        foreach ($posts as &$post) {
            $post['author_email'] = $authorMap[$post['author_id']] ?? 'Unknown';
            $post['image_path'] = self::getImageUrl($post['image_path']);
        }
        
        return $posts;
    }
    
    /**
     * Get a single blog post by ID with like count and comments
     * 
     * @param int $id Post ID
     * @return array|false Post data with like count and comments, or false if not found
     */
    public static function getById($id) {
        $contentDb = Database::getContentDB();
        $userDb = Database::getUserDB();
        
        // Get the post
        $stmt = $contentDb->prepare("
            SELECT 
                p.id,
                p.title,
                p.content,
                p.image_path,
                p.external_link,
                p.category,
                p.author_id,
                p.created_at,
                p.updated_at,
                (SELECT COUNT(*) FROM blog_likes WHERE post_id = p.id) as like_count
            FROM blog_posts p
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $post = $stmt->fetch();
        
        if (!$post) {
            return false;
        }
        
        // Get author email from User DB
        $userStmt = $userDb->prepare("SELECT email FROM users WHERE id = ?");
        $userStmt->execute([$post['author_id']]);
        $user = $userStmt->fetch();
        $post['author_email'] = $user ? $user['email'] : 'Unknown';
        
        // Get comments with commenter names
        $commentsStmt = $contentDb->prepare("
            SELECT 
                c.id,
                c.post_id,
                c.user_id,
                c.content,
                c.created_at,
                c.updated_at
            FROM blog_comments c
            WHERE c.post_id = ?
            ORDER BY c.created_at ASC
        ");
        $commentsStmt->execute([$id]);
        $comments = $commentsStmt->fetchAll();
        
        if (!empty($comments)) {
            // Collect all unique user IDs
            $userIds = array_unique(array_column($comments, 'user_id'));
            
            // Fetch all commenter emails in a single query
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $userStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
            $userStmt->execute($userIds);
            $commenters = $userStmt->fetchAll();
            
            // Create a map of user_id => email
            $commenterMap = [];
            foreach ($commenters as $commenter) {
                $commenterMap[$commenter['id']] = $commenter['email'];
            }
            
            // Add commenter email to each comment
            foreach ($comments as &$comment) {
                $comment['commenter_email'] = $commenterMap[$comment['user_id']] ?? 'Unknown';
            }
        }
        
        $post['comments'] = $comments;
        
        return $post;
    }
    
    /**
     * Create a new blog post
     * 
     * @param array $data Post data (title, content, category, author_id, external_link, image_path)
     * @return int The ID of the newly created post
     */
    public static function create($data) {
        $db = Database::getContentDB();
        
        $sql = "INSERT INTO blog_posts (title, content, category, author_id, external_link, image_path) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $data['title'],
            $data['content'],
            $data['category'],
            $data['author_id'],
            $data['external_link'] ?? null,
            $data['image_path'] ?? null
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Update an existing blog post
     * 
     * @param int $id Post ID
     * @param array $data Post data to update (title, content, category, external_link, image_path)
     * @return bool Success status
     */
    public static function update($id, $data) {
        $db = Database::getContentDB();
        
        // Whitelist of allowed fields
        $allowedFields = ['title', 'content', 'category', 'external_link', 'image_path'];
        
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        $sql = "UPDATE blog_posts SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Toggle like status for a post
     * If user has liked the post, remove the like. If not, add the like.
     * 
     * @param int $postId Post ID
     * @param int $userId User ID
     * @return bool New like state (true = liked, false = unliked)
     */
    public static function toggleLike($postId, $userId) {
        $db = Database::getContentDB();
        
        // Check if like exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM blog_likes WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        $likeExists = $stmt->fetchColumn() > 0;
        
        if ($likeExists) {
            // Delete the like
            $stmt = $db->prepare("DELETE FROM blog_likes WHERE post_id = ? AND user_id = ?");
            $stmt->execute([$postId, $userId]);
            return false; // Unliked
        } else {
            // Insert the like
            $stmt = $db->prepare("INSERT INTO blog_likes (post_id, user_id) VALUES (?, ?)");
            $stmt->execute([$postId, $userId]);
            return true; // Liked
        }
    }
    
    /**
     * Add a comment to a post
     * 
     * @param int $postId Post ID
     * @param int $userId User ID of the commenter
     * @param string $content Comment content
     * @return int The ID of the newly created comment
     */
    public static function addComment($postId, $userId, $content) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("INSERT INTO blog_comments (post_id, user_id, content) VALUES (?, ?, ?)");
        $stmt->execute([$postId, $userId, $content]);
        
        return $db->lastInsertId();
    }

    /**
     * Update an existing comment (only allowed by the comment owner)
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID (must match comment owner)
     * @param string $content New comment content
     * @return bool True if updated, false if not found or not owner
     */
    public static function updateComment($commentId, $userId, $content) {
        $db = Database::getContentDB();
        $stmt = $db->prepare("UPDATE blog_comments SET content = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$content, $commentId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a comment by ID
     * Board roles may delete any comment; regular users may delete their own.
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID of the requester
     * @param string $userRole Role of the requester
     * @return bool True if deleted, false otherwise
     */
    public static function deleteComment($commentId, $userId, $userRole) {
        $db = Database::getContentDB();
        if (in_array($userRole, Auth::BOARD_ROLES)) {
            $stmt = $db->prepare("DELETE FROM blog_comments WHERE id = ?");
            $stmt->execute([$commentId]);
        } else {
            $stmt = $db->prepare("DELETE FROM blog_comments WHERE id = ? AND user_id = ?");
            $stmt->execute([$commentId, $userId]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle a reaction on a comment for a user.
     * If the reaction already exists it is removed, otherwise it is added.
     *
     * @param int $commentId Comment ID
     * @param int $userId User ID
     * @param string $reaction Emoji string (e.g. '👍')
     * @return bool True if reaction was added, false if removed
     */
    public static function toggleCommentReaction($commentId, $userId, $reaction) {
        $db = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM blog_comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction = ?"
        );
        $stmt->execute([$commentId, $userId, $reaction]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $db->prepare(
                "DELETE FROM blog_comment_reactions WHERE comment_id = ? AND user_id = ? AND reaction = ?"
            );
            $stmt->execute([$commentId, $userId, $reaction]);
            return false;
        } else {
            $stmt = $db->prepare(
                "INSERT INTO blog_comment_reactions (comment_id, user_id, reaction) VALUES (?, ?, ?)"
            );
            $stmt->execute([$commentId, $userId, $reaction]);
            return true;
        }
    }

    /**
     * Get reaction counts grouped by emoji for a set of comment IDs.
     *
     * @param array $commentIds Array of comment IDs
     * @param int $currentUserId The current user's ID (to flag their own reactions)
     * @return array Map of comment_id => [ reaction => ['count' => int, 'reacted' => bool] ]
     */
    public static function getCommentReactions(array $commentIds, $currentUserId) {
        if (empty($commentIds)) {
            return [];
        }
        $db = Database::getContentDB();
        // Dynamic placeholders are required for the IN clause; all values are bound via PDO.
        $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
        $stmt = $db->prepare(
            "SELECT comment_id, reaction, COUNT(*) as count,
                    MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as user_reacted
             FROM blog_comment_reactions
             WHERE comment_id IN ($placeholders)
             GROUP BY comment_id, reaction"
        );
        $stmt->execute(array_merge([$currentUserId], $commentIds));
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['comment_id']][$row['reaction']] = [
                'count'   => (int)$row['count'],
                'reacted' => (bool)$row['user_reacted'],
            ];
        }
        return $result;
    }

    /**
     * Check if a user role can create/edit blog posts
     * 
     * @param string $userRole User role
     * @return bool True if role is authorized (board roles, head), false otherwise
     */
    public static function canAuth($userRole) {
        $authorizedRoles = array_merge(Auth::BOARD_ROLES, ['ressortleiter']);
        return in_array($userRole, $authorizedRoles);
    }
}
