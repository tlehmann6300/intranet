<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CreateNotificationsTable
 *
 * Stores per-user notifications for the polling-based notification system.
 * Each row represents one unread (or archived) notification for a user.
 *
 * Run with:
 *   php vendor/bin/phinx migrate -e content
 */
final class CreateNotificationsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('notifications')) {
            return;
        }

        $this->table('notifications', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', [
                'null'    => false,
                'comment' => 'Recipient user ID',
            ])
            ->addColumn('type', 'string', [
                'limit'   => 80,
                'null'    => false,
                'comment' => 'Notification type, e.g. new_blog, new_poll, event_update',
            ])
            ->addColumn('title', 'string', [
                'limit'   => 255,
                'null'    => false,
            ])
            ->addColumn('message', 'text', [
                'null'    => true,
                'default' => null,
            ])
            ->addColumn('url', 'string', [
                'limit'   => 512,
                'null'    => true,
                'default' => null,
                'comment' => 'Optional deep-link URL',
            ])
            ->addColumn('read_at', 'datetime', [
                'null'    => true,
                'default' => null,
            ])
            ->addColumn('created_at', 'datetime', [
                'null'    => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex('user_id')
            ->addIndex(['user_id', 'read_at'])
            ->addIndex('created_at')
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('notifications')) {
            $this->dropTable('notifications');
        }
    }
}
