<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CreateDocumentsTable
 *
 * Central document archive with version history.
 * Each row is one version of a document; the latest version for a given
 * (title, category) combination has `is_current = 1`.
 *
 * Run with:
 *   php vendor/bin/phinx migrate -e content
 */
final class CreateDocumentsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('documents')) {
            return;
        }

        $this->table('documents', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null'  => false,
            ])
            ->addColumn('category', 'enum', [
                'values'  => ['Satzung', 'Protokoll', 'Vorlage', 'Vertrag', 'Sonstiges'],
                'null'    => false,
                'default' => 'Sonstiges',
            ])
            ->addColumn('description', 'text', [
                'null'    => true,
                'default' => null,
            ])
            ->addColumn('file_path', 'string', [
                'limit'   => 512,
                'null'    => false,
                'comment' => 'Relative path under /private/documents/',
            ])
            ->addColumn('original_filename', 'string', [
                'limit'   => 255,
                'null'    => false,
            ])
            ->addColumn('mime_type', 'string', [
                'limit'   => 127,
                'null'    => true,
                'default' => null,
            ])
            ->addColumn('file_size', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'File size in bytes',
            ])
            ->addColumn('version', 'integer', [
                'null'    => false,
                'default' => 1,
                'comment' => 'Version number, incremented per title+category',
            ])
            ->addColumn('is_current', 'boolean', [
                'null'    => false,
                'default' => true,
                'comment' => 'Whether this is the latest version of the document',
            ])
            ->addColumn('uploaded_by', 'integer', [
                'null'    => false,
                'comment' => 'User ID of the uploader',
            ])
            ->addColumn('uploaded_at', 'datetime', [
                'null'    => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex('category')
            ->addIndex('is_current')
            ->addIndex('uploaded_by')
            ->addIndex(['title', 'category', 'is_current'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('documents')) {
            $this->dropTable('documents');
        }
    }
}
