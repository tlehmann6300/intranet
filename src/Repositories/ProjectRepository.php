<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ProjectRepository
 *
 * Encapsulates all database queries that operate on the `projects` table and
 * related join tables in the content database.  Controllers should inject
 * this repository via the DI container.
 */
class ProjectRepository extends BaseRepository
{
    protected string $table = 'projects';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find all active (non-archived) projects, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(): array
    {
        return $this->findAll('archived = 0', [], 'name ASC');
    }

    /**
     * Find all archived projects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findArchived(): array
    {
        return $this->findAll('archived = 1', [], 'name ASC');
    }

    /**
     * Find all projects managed by a given user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByManager(int $userId): array
    {
        return $this->findAll('manager_id = ?', [$userId], 'name ASC');
    }

    /**
     * Find all project memberships (join table) for a given user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findMembershipsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pm.*, p.name AS project_name, p.description
             FROM `project_members` pm
             JOIN `projects` p ON p.id = pm.project_id
             WHERE pm.user_id = ?
             ORDER BY p.name ASC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find pending membership applications for a specific project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingApplications(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pa.*, u.email, u.role
             FROM `project_applications` pa
             JOIN `users` u ON u.id = pa.user_id
             WHERE pa.project_id = ? AND pa.status = 'pending'
             ORDER BY pa.created_at ASC"
        );
        $stmt->execute([$projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a user to a project (inserts into the project_members join table).
     *
     * @param array<string, mixed> $data Extra columns (e.g. role, joined_at)
     */
    public function addMember(int $projectId, int $userId, array $data = []): int
    {
        $row = array_merge([
            'project_id' => $projectId,
            'user_id'    => $userId,
            'joined_at'  => date('Y-m-d H:i:s'),
        ], $data);

        $cols   = implode(', ', array_map(fn (string $c) => "`{$c}`", array_keys($row)));
        $places = implode(', ', array_fill(0, count($row), '?'));
        $stmt   = $this->pdo->prepare("INSERT INTO `project_members` ({$cols}) VALUES ({$places})");
        $stmt->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update the status of a project application.
     */
    public function updateApplicationStatus(int $applicationId, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `project_applications` SET status = ?, updated_at = ? WHERE id = ?'
        );

        return $stmt->execute([$status, date('Y-m-d H:i:s'), $applicationId]);
    }
}
