<?php
// index.php - Haupt-Einstiegspunkt (Bulletproof Version)

// 1. Buffer starten (Verhindert "Headers already sent" Fehler)
ob_start();

try {
    // 3. Prüfen & Laden der Config (Mit absolutem Pfad!)
    $configFile = __DIR__ . '/config/config.php';
    if (!file_exists($configFile)) {
        throw new Exception("Kritisch: config.php nicht gefunden in $configFile");
    }
    require_once $configFile;

    // 4. Prüfen & Laden der Helper
    $helperFile = __DIR__ . '/includes/helpers.php';
    if (!file_exists($helperFile)) {
        throw new Exception("Kritisch: helpers.php nicht gefunden in $helperFile");
    }
    require_once $helperFile;

    // 5. Prüfen & Laden von Auth
    $authFile = __DIR__ . '/src/Auth.php';
    if (!file_exists($authFile)) {
        throw new Exception("Kritisch: Auth.php nicht gefunden in $authFile");
    }
    require_once $authFile;

    // 6. BASE_URL Check
    if (!defined('BASE_URL')) {
        // Fallback, falls Config versagt hat
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'];
        // Optional: Unterordner erkennen
        $path = dirname($_SERVER['PHP_SELF']);
        if ($path !== '/' && $path !== '\\') {
            $baseUrl .= $path;
        }
        define('BASE_URL', rtrim($baseUrl, '/'));
    }

    // 7. Routing-Logik
    $target = '';
    if (class_exists('Auth') && Auth::check()) {
        // Check if profile is incomplete and redirect to profile page
        if (isset($_SESSION['profile_incomplete']) && $_SESSION['profile_incomplete'] === true) {
            $target = BASE_URL . '/pages/auth/profile.php';
        } else {
            $target = BASE_URL . '/pages/dashboard/index.php';
        }
    } else {
        $target = BASE_URL . '/pages/auth/login.php';
    }

    // Puffer leeren
    ob_end_clean();

    // 8. Weiterleitung
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    } else {
        throw new Exception("Header konnten nicht gesendet werden (Ausgabe vor header()).");
    }

} catch (Exception $e) {
    ob_end_clean(); // Puffer verwerfen

    $isProduction = !defined('ENVIRONMENT') || ENVIRONMENT === 'production';

    if ($isProduction) {
        // Log silently to error log, show no details to the user
        $logFile = __DIR__ . '/logs/error.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents(
            $logFile,
            "[$timestamp] " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        http_response_code(500);
        echo "<p>Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.</p>";
    } else {
        // Fehlerbehandlung: Zeige den Fehler an, statt einer weißen Seite
        echo "<div style='font-family:sans-serif; padding:20px; background:#ffebee; border:1px solid #c62828; color:#b71c1c;'>";
        echo "<h2>⚠️ System Fehler (500 Avoidance)</h2>";
        echo "<p><strong>Fehler:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Datei:</strong> " . $e->getFile() . " (Zeile " . $e->getLine() . ")</p>";
        echo "</div>";

        // Fallback-Link anzeigen
        if (defined('BASE_URL')) {
            echo "<p><a href='" . BASE_URL . "/pages/auth/login.php'>Versuche direkten Login-Link</a></p>";
        }
    }
    exit;
}
?>