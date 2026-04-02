<?php

declare(strict_types=1);

namespace App\Services;

use Database;
use PDOException;

/**
 * AuditLogger
 *
 * Centralised, tamper-resistant audit-logging service.
 *
 * Every log entry is linked to the previous one via a SHA-256 chain hash:
 *
 *   prev_hash₀ = '0000...000'   (64 zeros – genesis entry)
 *   hash_n     = SHA-256(prev_hash_{n-1} | user_id | action | entity_type |
 *                         entity_id | details | ip | timestamp)
 *
 * The chain makes it detectable when entries are deleted or altered after
 * the fact: any modification of an intermediate entry will break the chain
 * from that point forward.
 *
 * Usage:
 *   AuditLogger::log($userId, 'role_change', 'user', $targetId, 'Rolle geändert zu admin');
 *
 * Replacement for the scattered inline INSERTs into system_logs.
 */
final class AuditLogger
{
    /** All-zeros genesis hash (64 hex chars = 256 bits) */
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Write an audit log entry with a chained SHA-256 hash.
     *
     * @param int|null    $userId     Authenticated user (null = system/cron)
     * @param string      $action     Action type, e.g. 'login_failed', 'role_change'
     * @param string|null $entityType Type of affected entity, e.g. 'user', 'event'
     * @param int|null    $entityId   ID of the affected entity
     * @param string|null $details    Free-text or JSON details
     * @param string|null $ipAddress  Client IP (defaults to REMOTE_ADDR)
     * @param string|null $userAgent  User-Agent header (defaults to HTTP_USER_AGENT)
     */
    public static function log(
        ?int    $userId,
        string  $action,
        ?string $entityType = null,
        ?int    $entityId   = null,
        ?string $details    = null,
        ?string $ipAddress  = null,
        ?string $userAgent  = null
    ): void {
        $ipAddress ??= $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent ??= $_SERVER['HTTP_USER_AGENT'] ?? null;

        try {
            $db        = Database::getContentDB();
            $prevHash  = self::fetchLastHash($db);
            $timestamp = date('Y-m-d H:i:s');
            $newHash   = self::computeHash(
                $prevHash,
                $userId,
                $action,
                $entityType,
                $entityId,
                $details,
                $ipAddress,
                $timestamp
            );

            $stmt = $db->prepare(
                'INSERT INTO system_logs
                    (user_id, action, entity_type, entity_id, details, ip_address, user_agent, prev_hash, timestamp)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $details,
                $ipAddress,
                $userAgent,
                $newHash,
                $timestamp,
            ]);
        } catch (\Exception $e) {
            // Never let audit failures take down the application
            error_log('AuditLogger::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify the hash chain integrity of the audit log.
     *
     * @return array{valid: bool, broken_at: int|null, total: int}
     *   - valid:     true when every entry's hash matches what we re-compute
     *   - broken_at: id of the first entry that breaks the chain (null when valid)
     *   - total:     number of entries checked
     */
    public static function verifyChain(): array
    {
        try {
            $db   = Database::getContentDB();
            $stmt = $db->query(
                'SELECT id, user_id, action, entity_type, entity_id, details, ip_address, prev_hash, timestamp
                 FROM system_logs
                 ORDER BY id ASC'
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $prevHash = self::GENESIS_HASH;
            foreach ($rows as $row) {
                $expected = self::computeHash(
                    $prevHash,
                    $row['user_id'] !== null ? (int)$row['user_id'] : null,
                    $row['action'],
                    $row['entity_type'],
                    $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
                    $row['details'],
                    $row['ip_address'],
                    $row['timestamp']
                );

                if ($row['prev_hash'] !== $expected) {
                    return ['valid' => false, 'broken_at' => (int)$row['id'], 'total' => count($rows)];
                }

                $prevHash = $expected;
            }

            return ['valid' => true, 'broken_at' => null, 'total' => count($rows)];
        } catch (\Exception $e) {
            error_log('AuditLogger::verifyChain failed: ' . $e->getMessage());
            return ['valid' => false, 'broken_at' => null, 'total' => 0];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the hash of the most recent log entry (or the genesis hash for the first entry).
     */
    private static function fetchLastHash(\PDO $db): string
    {
        try {
            $stmt = $db->query(
                'SELECT user_id, action, entity_type, entity_id, details, ip_address, prev_hash, timestamp
                 FROM system_logs ORDER BY id DESC LIMIT 1'
            );
            $last = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$last) {
                return self::GENESIS_HASH;
            }
            return self::computeHash(
                $last['prev_hash'] ?? self::GENESIS_HASH,
                $last['user_id'] !== null ? (int)$last['user_id'] : null,
                $last['action'],
                $last['entity_type'],
                $last['entity_id'] !== null ? (int)$last['entity_id'] : null,
                $last['details'],
                $last['ip_address'],
                $last['timestamp']
            );
        } catch (PDOException $e) {
            // prev_hash column may not exist yet (before migration runs)
            if (str_contains($e->getMessage(), "Unknown column 'prev_hash'")) {
                return self::GENESIS_HASH;
            }
            error_log('AuditLogger::fetchLastHash failed: ' . $e->getMessage());
            return self::GENESIS_HASH;
        }
    }

    /**
     * Compute the SHA-256 entry hash from its field values.
     */
    private static function computeHash(
        string  $prevHash,
        ?int    $userId,
        string  $action,
        ?string $entityType,
        ?int    $entityId,
        ?string $details,
        ?string $ipAddress,
        string  $timestamp
    ): string {
        $payload = implode('|', [
            $prevHash,
            (string)($userId ?? ''),
            $action,
            (string)($entityType ?? ''),
            (string)($entityId ?? ''),
            (string)($details ?? ''),
            (string)($ipAddress ?? ''),
            $timestamp,
        ]);

        return hash('sha256', $payload);
    }
}
