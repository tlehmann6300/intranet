<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * BaseRepository
 *
 * Abstract foundation for all domain repositories.
 *
 * Provides a thin set of generic CRUD helpers (find, findAll, insert, update,
 * delete) built on top of a PDO connection.  Subclasses declare the table
 * name and, optionally, override the default helpers to add type-safe,
 * domain-specific query methods.
 *
 * Usage example:
 *   class EventRepository extends BaseRepository
 *   {
 *       protected string $table = 'events';
 *
 *       public function findUpcoming(): array
 *       {
 *           $stmt = $this->pdo->prepare('SELECT * FROM events WHERE date >= NOW()');
 *           $stmt->execute();
 *           return $stmt->fetchAll(PDO::FETCH_ASSOC);
 *       }
 *   }
 */
abstract class BaseRepository
{
    /** Name of the primary database table managed by this repository */
    protected string $table = '';

    /** Primary key column name */
    protected string $primaryKey = 'id';

    public function __construct(protected readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Generic CRUD
    // -------------------------------------------------------------------------

    /**
     * Find a single record by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Return all rows, optionally filtered by a WHERE clause.
     *
     * @param string               $where  SQL WHERE clause without the "WHERE" keyword (default: '1')
     * @param array<int, mixed>    $params Positional bind parameters
     * @param string               $orderBy Column and direction, e.g. 'created_at DESC'
     * @return array<int, array<string, mixed>>
     */
    public function findAll(
        string $where   = '1',
        array  $params  = [],
        string $orderBy = ''
    ): array {
        $sql = "SELECT * FROM `{$this->table}` WHERE {$where}";
        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new row and return the new auto-increment ID.
     *
     * @param array<string, mixed> $data Associative array of column → value pairs
     */
    public function insert(array $data): int
    {
        $cols   = implode(', ', array_map(fn (string $c) => "`{$c}`", array_keys($data)));
        $places = implode(', ', array_fill(0, count($data), '?'));
        $stmt   = $this->pdo->prepare("INSERT INTO `{$this->table}` ({$cols}) VALUES ({$places})");
        $stmt->execute(array_values($data));

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Update an existing row by primary key.
     *
     * @param array<string, mixed> $data Associative array of column → value pairs to update
     */
    public function update(int $id, array $data): bool
    {
        $sets = implode(', ', array_map(fn (string $c) => "`{$c}` = ?", array_keys($data)));
        $stmt = $this->pdo->prepare(
            "UPDATE `{$this->table}` SET {$sets} WHERE `{$this->primaryKey}` = ?"
        );

        return $stmt->execute([...array_values($data), $id]);
    }

    /**
     * Delete a row by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?"
        );

        return $stmt->execute([$id]);
    }

    /**
     * Count rows, optionally filtered by a WHERE clause.
     *
     * @param string            $where  SQL WHERE clause without "WHERE" keyword
     * @param array<int, mixed> $params Positional bind parameters
     */
    public function count(string $where = '1', array $params = []): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}"
        );
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
}
