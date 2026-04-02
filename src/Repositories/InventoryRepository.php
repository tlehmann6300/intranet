<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * InventoryRepository
 *
 * Encapsulates all database queries that operate on inventory items and
 * related rental/checkout tables in the content database.  Controllers
 * should inject this repository via the DI container.
 */
class InventoryRepository extends BaseRepository
{
    protected string $table = 'inventory_items';

    // -------------------------------------------------------------------------
    // Domain-specific query methods
    // -------------------------------------------------------------------------

    /**
     * Find all available (non-deleted) inventory items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllAvailable(): array
    {
        return $this->findAll('deleted_at IS NULL', [], 'name ASC');
    }

    /**
     * Find items by category.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCategory(int $categoryId): array
    {
        return $this->findAll('category_id = ? AND deleted_at IS NULL', [$categoryId], 'name ASC');
    }

    /**
     * Find all active checkouts (items currently checked out by any user).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveCheckouts(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ic.*, i.name AS item_name, i.description
             FROM `inventory_checkouts` ic
             JOIN `inventory_items` i ON i.id = ic.item_id
             WHERE ic.returned_at IS NULL
             ORDER BY ic.checked_out_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all checkouts made by a specific user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findCheckoutsByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ic.*, i.name AS item_name, i.description
             FROM `inventory_checkouts` ic
             JOIN `inventory_items` i ON i.id = ic.item_id
             WHERE ic.user_id = ?
             ORDER BY ic.checked_out_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all pending rental requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingRentalRequests(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rr.*, i.name AS item_name
             FROM `inventory_rental_requests` rr
             JOIN `inventory_items` i ON i.id = rr.item_id
             WHERE rr.status = 'pending'
             ORDER BY rr.created_at ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Record a new checkout for an inventory item.
     */
    public function recordCheckout(int $itemId, int $userId, ?string $dueDate = null): int
    {
        $cols   = ['item_id', 'user_id', 'checked_out_at'];
        $values = [$itemId, $userId, date('Y-m-d H:i:s')];

        if ($dueDate !== null) {
            $cols[]   = 'due_at';
            $values[] = $dueDate;
        }

        $colsSql   = implode(', ', array_map(fn (string $c) => "`{$c}`", $cols));
        $placesSql = implode(', ', array_fill(0, count($values), '?'));
        $stmt      = $this->pdo->prepare("INSERT INTO `inventory_checkouts` ({$colsSql}) VALUES ({$placesSql})");
        $stmt->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mark a checkout as returned.
     */
    public function recordReturn(int $checkoutId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `inventory_checkouts` SET returned_at = ? WHERE id = ?'
        );

        return $stmt->execute([date('Y-m-d H:i:s'), $checkoutId]);
    }

    /**
     * Update the status of a rental request.
     */
    public function updateRentalRequestStatus(int $requestId, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `inventory_rental_requests` SET status = ?, updated_at = ? WHERE id = ?'
        );

        return $stmt->execute([$status, date('Y-m-d H:i:s'), $requestId]);
    }

    /**
     * Check whether an item is currently checked out (not returned).
     */
    public function isCheckedOut(int $itemId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM `inventory_checkouts`
             WHERE item_id = ? AND returned_at IS NULL'
        );
        $stmt->execute([$itemId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
