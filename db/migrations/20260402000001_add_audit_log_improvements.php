<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AddAuditLogImprovements
 *
 * 1. Adds `prev_hash` (VARCHAR 64) to `system_logs` for tamper-resistant
 *    SHA-256 chained hashing (see AuditLogger service).
 * 2. Ensures the `user_agent` column exists (idempotent – column was added
 *    manually on some environments).
 *
 * Run with:
 *   php vendor/bin/phinx migrate -e content
 */
final class AddAuditLogImprovements extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('system_logs');

        // Add user_agent if it is not already present (save immediately so we can safely reference it next)
        if (!$table->hasColumn('user_agent')) {
            $table->addColumn('user_agent', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => 'HTTP User-Agent string',
            ])->save();

            // Refresh column state after saving
            $table = $this->table('system_logs');
        }

        // Add prev_hash for tamper-resistant chained logging
        if (!$table->hasColumn('prev_hash')) {
            $table->addColumn('prev_hash', 'string', [
                'limit'   => 64,
                'null'    => true,
                'default' => null,
                'comment' => 'SHA-256 of previous row hash + this row fields',
            ])->save();
        }
    }

    public function down(): void
    {
        $table = $this->table('system_logs');

        if ($table->hasColumn('prev_hash')) {
            $table->removeColumn('prev_hash')->save();
        }
    }
}
