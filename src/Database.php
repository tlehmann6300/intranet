<?php
/**
 * Database Class Wrapper
 *
 * Lädt die eigentliche Database-Implementierung aus includes/database.php
 * und aktiviert den PSR-3-kompatiblen FileLogger für persistentes Fehler-Logging
 * in logs/db.log.
 *
 * Zum Überschreiben des Loggers (z. B. im Test):
 *   Database::setLogger(new \Psr\Log\NullLogger());
 */

require_once __DIR__ . '/../includes/database.php';

// FileLogger in logs/db.log aktivieren, sobald das Verzeichnis vorhanden ist
$_dbLogFile = __DIR__ . '/../logs/db.log';
if (is_dir(dirname($_dbLogFile))) {
    Database::setLogger(new FileLogger($_dbLogFile));
}
unset($_dbLogFile);
