<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * EventRepository
 *
 * Encapsulates all database queries that operate on the `events` table in the
 * content database.  Controllers should inject this repository via the DI
 * container instead of querying the `Event` model or the database directly.
 */
class EventRepository extends BaseRepository
{
    protected string $table = 'events';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find all upcoming events (date >= now), ordered by date.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUpcoming(): array
    {
        return $this->findAll('date >= NOW()', [], 'date ASC');
    }

    /**
     * Find all past events, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPast(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `events` WHERE date < NOW() ORDER BY date DESC LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single event by its slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `events` WHERE slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find all signups for a given event, joined with basic user information.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSignupsForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT es.*, u.email AS user_email
             FROM event_signups es
             LEFT JOIN ' . (defined('DB_USER_NAME') ? DB_USER_NAME : 'users_db') . '.users u ON es.user_id = u.id
             WHERE es.event_id = ? AND es.status != \'cancelled\'
             ORDER BY es.created_at ASC'
        );
        $stmt->execute([$eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count the number of confirmed signups for an event.
     */
    public function countSignups(int $eventId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_signups WHERE event_id = ? AND status = 'confirmed'"
        );
        $stmt->execute([$eventId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Find the signup record for a specific user + event combination.
     *
     * @return array<string, mixed>|null
     */
    public function findUserSignup(int $eventId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_signups WHERE event_id = ? AND user_id = ? AND status != 'cancelled' LIMIT 1"
        );
        $stmt->execute([$eventId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find all events that have open helper slots.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findWithOpenHelperSlots(): array
    {
        $stmt = $this->pdo->query(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM event_helper_slots ehs WHERE ehs.event_id = e.id AND ehs.filled_by IS NULL) AS open_slots
             FROM events e
             WHERE e.date >= NOW()
               AND (SELECT COUNT(*) FROM event_helper_slots ehs WHERE ehs.event_id = e.id AND ehs.filled_by IS NULL) > 0
             ORDER BY e.date ASC'
        );

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
