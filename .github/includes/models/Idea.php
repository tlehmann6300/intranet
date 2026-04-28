<?php
/**
 * Idea Model
 * Manages idea submissions and voting
 */

require_once __DIR__ . '/../database.php';

class Idea {

    /**
     * Create a new idea
     *
     * @param int    $userId      User ID of the submitter
     * @param string $title       Idea title (max 200 chars)
     * @param string $description Idea description
     * @return array ['success' => bool, 'id' => int|null, 'error' => string|null]
     */
    public static function create(int $userId, string $title, string $description): array {
        $title       = trim($title);
        $description = trim($description);

        if (empty($title) || empty($description)) {
            return ['success' => false, 'id' => null, 'error' => 'Titel und Beschreibung sind erforderlich.'];
        }

        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                'INSERT INTO ideas (user_id, title, description) VALUES (?, ?, ?)'
            );
            $stmt->execute([$userId, $title, $description]);
            return ['success' => true, 'id' => (int) $db->lastInsertId(), 'error' => null];
        } catch (Exception $e) {
            error_log('Idea::create error: ' . $e->getMessage());
            return ['success' => false, 'id' => null, 'error' => 'Datenbankfehler beim Speichern der Idee.'];
        }
    }

    /**
     * Get all ideas with vote counts and the current user's vote
     *
     * @param int $currentUserId
     * @return array
     */
    public static function getAll(int $currentUserId): array {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                'SELECT i.*,
                        COALESCE(SUM(v.vote = \'up\'),   0) AS upvotes,
                        COALESCE(SUM(v.vote = \'down\'), 0) AS downvotes,
                        MAX(CASE WHEN v.user_id = ? THEN v.vote ELSE NULL END) AS user_vote
                 FROM ideas i
                 LEFT JOIN idea_votes v ON v.idea_id = i.id
                 GROUP BY i.id
                 ORDER BY i.created_at DESC'
            );
            $stmt->execute([$currentUserId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Idea::getAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Record or toggle a vote.  Removes the vote when the user repeats the same direction.
     *
     * @param int    $ideaId
     * @param int    $userId
     * @param string $vote   'up' or 'down'
     * @return array ['success' => bool, 'upvotes' => int, 'downvotes' => int, 'user_vote' => string|null, 'error' => string|null]
     */
    public static function vote(int $ideaId, int $userId, string $vote): array {
        if (!in_array($vote, ['up', 'down'], true)) {
            return ['success' => false, 'error' => 'Ungültige Stimme.'];
        }

        try {
            $db = Database::getContentDB();

            // Fetch existing vote
            $stmt = $db->prepare('SELECT vote FROM idea_votes WHERE idea_id = ? AND user_id = ?');
            $stmt->execute([$ideaId, $userId]);
            $existing = $stmt->fetchColumn();

            if ($existing === $vote) {
                // Same direction → remove vote (toggle off)
                $db->prepare('DELETE FROM idea_votes WHERE idea_id = ? AND user_id = ?')
                   ->execute([$ideaId, $userId]);
                $newUserVote = null;
            } else {
                // Insert or update
                $db->prepare(
                    'INSERT INTO idea_votes (idea_id, user_id, vote) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE vote = VALUES(vote)'
                )->execute([$ideaId, $userId, $vote]);
                $newUserVote = $vote;
            }

            // Fetch updated counts
            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(vote=\'up\'),0) AS upvotes,
                        COALESCE(SUM(vote=\'down\'),0) AS downvotes
                 FROM idea_votes WHERE idea_id = ?'
            );
            $stmt->execute([$ideaId]);
            $counts = $stmt->fetch();

            return [
                'success'   => true,
                'upvotes'   => (int) $counts['upvotes'],
                'downvotes' => (int) $counts['downvotes'],
                'user_vote' => $newUserVote,
                'error'     => null,
            ];
        } catch (Exception $e) {
            error_log('Idea::vote error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Datenbankfehler beim Abstimmen.'];
        }
    }

    /**
     * Update the status of an idea (board only)
     *
     * @param int    $ideaId
     * @param string $status
     * @return bool
     */
    public static function updateStatus(int $ideaId, string $status): bool {
        $allowed = ['new', 'in_review', 'accepted', 'rejected', 'implemented'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare('UPDATE ideas SET status = ? WHERE id = ?');
            $stmt->execute([$status, $ideaId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Idea::updateStatus error: ' . $e->getMessage());
            return false;
        }
    }
}
