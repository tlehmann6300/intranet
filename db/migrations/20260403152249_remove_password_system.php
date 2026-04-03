<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RemovePasswordSystem extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('users');

        // Entfernt alle lokalen Sicherheits-Features, die nun Microsoft übernimmt
        if ($table->hasColumn('password')) {
            $table->removeColumn('password');
        }
        if ($table->hasColumn('tfa_secret')) {
            $table->removeColumn('tfa_secret');
        }
        if ($table->hasColumn('tfa_enabled')) {
            $table->removeColumn('tfa_enabled');
        }
        if ($table->hasColumn('tfa_failed_attempts')) {
            $table->removeColumn('tfa_failed_attempts');
        }
        if ($table->hasColumn('tfa_locked_until')) {
            $table->removeColumn('tfa_locked_until');
        }
        if ($table->hasColumn('failed_login_attempts')) {
            $table->removeColumn('failed_login_attempts');
        }
        if ($table->hasColumn('locked_until')) {
            $table->removeColumn('locked_until');
        }

        $table->update();
    }
}
