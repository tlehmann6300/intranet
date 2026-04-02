<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * UserRepository
 *
 * Encapsulates all database queries that operate on the `users` table in the
 * user database.  Controllers should inject this repository via the DI
 * container and never query the users table directly.
 */
class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find a user by their e-mail address.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `users` WHERE email = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find all active (non-deleted) users, ordered by email.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(): array
    {
        return $this->findAll('deleted_at IS NULL AND is_active = 1', [], 'email ASC');
    }

    /**
     * Find users whose birthday is today (month and day match).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findBirthdayUsers(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `users`
             WHERE DATE_FORMAT(birthday, '%m-%d') = DATE_FORMAT(NOW(), '%m-%d')
               AND deleted_at IS NULL
               AND is_active = 1"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find users whose profile has not been updated for over one year and
     * who have not yet received a reminder this cycle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findProfileReminderCandidates(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `users`
             WHERE last_profile_update < DATE_SUB(NOW(), INTERVAL 1 YEAR)
               AND profile_reminder_sent_at IS NULL
               AND deleted_at IS NULL
               AND is_active = 1
             ORDER BY last_profile_update ASC
             LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark the profile-reminder as sent for a specific user.
     */
    public function markProfileReminderSent(int $userId): bool
    {
        return $this->update($userId, ['profile_reminder_sent_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Update a user's role.
     */
    public function updateRole(int $userId, string $role): bool
    {
        return $this->update($userId, ['role' => $role]);
    }

    /**
     * Soft-delete a user (sets deleted_at timestamp).
     */
    public function softDelete(int $userId): bool
    {
        return $this->update($userId, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Search users by e-mail fragment (used in the admin panel).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `users`
             WHERE (email LIKE ? OR id = ?)
               AND deleted_at IS NULL
             LIMIT ?'
        );
        $stmt->execute(['%' . $query . '%', is_numeric($query) ? (int)$query : -1, $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
