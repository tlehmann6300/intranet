<?php
/**
 * Studenten-Status-Check Cron
 *
 * Setzt am 01.04. und 01.08. den Flag `student_status_check_due` für alle
 * aktiven Mitglieder, sodass diese beim nächsten Login bestätigen müssen, ob
 * sie noch studieren. Antwort erfolgt über pages/auth/student_status_check.php.
 *
 * Empfohlene crontab-Zeile (täglich 06:00, Script prüft selbst auf Datum):
 *   0 6 * * * php /pfad/zum/intranet/cron/mark_student_status_check.php
 *
 * Oder direkt am Stichtag:
 *   0 6 1 4,8 * php /pfad/zum/intranet/cron/mark_student_status_check.php --force
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

if (PHP_SAPI !== 'cli') {
    if (CRON_TOKEN === '' || !isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals(CRON_TOKEN, $_GET['token'])) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$force = in_array('--force', $argv ?? [], true);
$today = date('m-d');

if (!$force && $today !== '04-01' && $today !== '08-01') {
    echo "Heute ({$today}) ist kein Stichtag (01-04 oder 01-08). Abbruch.\n";
    exit(0);
}

echo "=== Studenten-Status-Check Markierung ===\n";
echo "Start: " . date('Y-m-d H:i:s') . "\n";

try {
    $db = Database::getUserDB();

    // Aktive Studierende: Rollen, die typischerweise Studierende sind.
    // Alumni / ehrenmitglied bleiben außen vor.
    $activeStudentRoles = ['anwaerter', 'mitglied', 'ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'];
    $placeholders = implode(',', array_fill(0, count($activeStudentRoles), '?'));

    $sql = "UPDATE users
            SET student_status_check_due = 1,
                student_status_check_set_at = NOW()
            WHERE role IN ($placeholders)
              AND deleted_at IS NULL";

    $stmt = $db->prepare($sql);
    $stmt->execute($activeStudentRoles);
    $affected = $stmt->rowCount();

    echo "Markierte User: {$affected}\n";

    try {
        $contentDb = Database::getContentDB();
        $log = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (0, 'cron_student_status_mark', 'cron', NULL, ?, 'cron', 'cron')");
        $log->execute(["Markiert {$affected} User für Studenten-Status-Check am {$today}"]);
    } catch (Throwable $e) { /* ignore */ }

    echo "Fertig: " . date('Y-m-d H:i:s') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FEHLER: " . $e->getMessage() . "\n");
    exit(1);
}
