-- Migration: Studenten-Status-Check (halbjährliche Abfrage am 01.04. und 01.08.)
-- Fügt zwei Spalten in `users` hinzu:
--   student_status_check_due    – wird vom Cron am Stichtag auf 1 gesetzt
--   student_status_check_set_at – Zeitpunkt der Cron-Markierung (NULL = unbeantwortet seit letztem Stichtag)

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `student_status_check_due` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Halbjährliche Abfrage offen: 1 = User muss beim Login bestätigen, ob er noch studiert',
    ADD COLUMN IF NOT EXISTS `student_status_check_set_at` DATETIME DEFAULT NULL
        COMMENT 'Zeitpunkt, an dem der Cron die Abfrage markiert hat',
    ADD INDEX IF NOT EXISTS `idx_student_status_check_due` (`student_status_check_due`);
