<?php
/**
 * Globaler Guard für den halbjährlichen Studenten-Status-Check.
 *
 * Inkludiert von Dashboard und ggf. weiteren Einstiegspunkten. Sobald der Cron
 * `student_status_check_due = 1` gesetzt hat, wird der eingeloggte User auf das
 * Check-Modal umgeleitet, bevor er die eigentliche Seite sehen kann.
 *
 * Aufruf:  require_once __DIR__ . '/../../includes/handlers/StudentStatusCheck.php';
 *          StudentStatusCheck::enforce();
 */

require_once __DIR__ . '/../../src/Auth.php';

class StudentStatusCheck {
    public static function enforce(): void {
        if (!Auth::check()) {
            return;
        }
        $user = Auth::user();
        if (empty($user['student_status_check_due'])) {
            return;
        }

        // Auf der Check-Seite selbst nicht weiter umleiten.
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($script, 'student_status_check.php') !== false
            || strpos($script, 'logout.php') !== false) {
            return;
        }

        // Relative Pfade ab Web-Root robust auflösen.
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        // Es wird ein absoluter Pfad benötigt; Annahme: Pages liegen unter /pages/...
        $target = '/pages/auth/student_status_check.php';
        // App-Subpath berücksichtigen, falls vorhanden.
        if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') {
            $target = rtrim(APP_BASE_PATH, '/') . $target;
        }
        header('Location: ' . $target);
        exit;
    }
}
