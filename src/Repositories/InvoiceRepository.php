<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * InvoiceRepository
 *
 * Encapsulates all database queries that operate on the `invoices` table in
 * the rechnungs (billing) database.  Controllers should inject this
 * repository via the DI container.
 */
class InvoiceRepository extends BaseRepository
{
    protected string $table = 'invoices';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find all invoices belonging to a given user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUserId(int $userId): array
    {
        return $this->findAll('user_id = ?', [$userId], 'created_at DESC');
    }

    /**
     * Find all invoices with a specific status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findAll('status = ?', [$status], 'created_at DESC');
    }

    /**
     * Find all pending invoices (submitted but not yet approved/paid).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPending(): array
    {
        return $this->findByStatus('pending');
    }

    /**
     * Find all invoices within a date range (inclusive).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findInDateRange(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `invoices`
             WHERE created_at BETWEEN ? AND ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$from, $to]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the status of an invoice.
     */
    public function updateStatus(int $id, string $status): bool
    {
        return $this->update($id, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark an invoice as paid and record the payment date.
     */
    public function markPaid(int $id): bool
    {
        return $this->update($id, [
            'status'     => 'paid',
            'paid_at'    => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Calculate the total amount of all paid invoices in a given year.
     */
    public function totalPaidByYear(int $year): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM `invoices`
             WHERE status = 'paid'
               AND YEAR(paid_at) = ?"
        );
        $stmt->execute([$year]);

        return (float) $stmt->fetchColumn();
    }
}
