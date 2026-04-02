<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AlumniRepository
 *
 * Encapsulates all database queries that operate on alumni profiles and
 * related data in the user database.  Controllers should inject this
 * repository via the DI container.
 */
class AlumniRepository extends BaseRepository
{
    protected string $table = 'alumni_profiles';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find an alumni profile by its associated user ID.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `alumni_profiles` WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Return all alumni profiles ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllPublished(): array
    {
        return $this->findAll('1=1', [], 'last_name ASC, first_name ASC');
    }

    /**
     * Full-text search across name, company, industry, and position fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 50): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `alumni_profiles`
             WHERE first_name LIKE ? OR last_name LIKE ?
                OR company LIKE ? OR industry LIKE ? OR position LIKE ?
             ORDER BY last_name ASC, first_name ASC
             LIMIT ?'
        );
        $stmt->execute([$like, $like, $like, $like, $like, $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all alumni profiles that have not been updated within the given
     * number of days and are flagged for reminder e-mails.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findReminderCandidates(int $daysStale = 365): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `alumni_profiles`
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
               AND reminder_sent_at IS NULL
             ORDER BY updated_at ASC'
        );
        $stmt->execute([$daysStale]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark the reminder as sent for the given profile.
     */
    public function markReminderSent(int $id): bool
    {
        return $this->update($id, ['reminder_sent_at' => date('Y-m-d H:i:s')]);
    }
}
