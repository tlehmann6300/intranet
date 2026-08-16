<?php
/**
 * EasyVerein Token Refresh Cron Script
 *
 * Calls https://easyverein.com/api/v3.0/refresh-token to obtain a new long-lived
 * Bearer token and persists it so all subsequent API calls keep working.
 *
 * EasyVerein tokens expire after a sliding window of inactivity. To make sure
 * the inventory sync (and any other EasyVerein-backed feature) never breaks,
 * this script runs every night and rotates the token proactively.
 *
 * Persistence priority order (highest first):
 *   1. system_settings DB table  (key: `easyverein_api_token`)
 *   2. .env file                 (line: `EASYVEREIN_API_TOKEN=…`) — best-effort
 *
 * Recommended schedule (every day at 03:30):
 *   30 3 * * * /usr/bin/php /path/to/cron/refresh_easyverein_token.php >> /path/to/logs/easyverein_token.log 2>&1
 *
 * HTTP-trigger variant (e.g. cron-job.org):
 *   GET https://intra.business-consulting.de/cron/refresh_easyverein_token.php?token=<CRON_TOKEN>
 *
 * Required ENV / config keys:
 *   - EASYVEREIN_API_TOKEN  Initial token used for the very first call.
 *                           Subsequent runs read the rotated token from
 *                           system_settings.
 *   - CRON_TOKEN            Shared secret used to authorise HTTP triggers.
 *
 * Exit codes:
 *   0  Success — new token persisted.
 *   1  No initial token configured.
 *   2  EasyVerein API request failed.
 *   3  EasyVerein response did not contain a new token.
 *   4  Persistence (DB and .env) both failed.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/database.php';

// ── HTTP / CLI guard ─────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    if (empty($_ENV['CRON_TOKEN']) || !is_string($_ENV['CRON_TOKEN']) || strlen($_ENV['CRON_TOKEN']) < 16) {
        http_response_code(500);
        exit('CRON_TOKEN not configured securely');
    }
    $__cronToken = CRON_TOKEN;
    if ($__cronToken === '' || !isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals($__cronToken, $_GET['token'])) {
        http_response_code(403);
        exit('Forbidden.' . PHP_EOL);
    }
    unset($__cronToken);
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== EasyVerein Token Refresh ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// ── 1. Resolve current token ─────────────────────────────────────────────────
$currentToken = '';
try {
    $db   = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT setting_value
           FROM system_settings
          WHERE setting_key = 'easyverein_api_token'
          LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['setting_value'])) {
        $currentToken = $row['setting_value'];
        echo "Token source: system_settings DB\n";
    }
} catch (Throwable $e) {
    error_log('refresh_easyverein_token: DB read failed - ' . $e->getMessage());
}

if ($currentToken === '' && defined('EASYVEREIN_API_TOKEN') && EASYVEREIN_API_TOKEN !== '') {
    $currentToken = EASYVEREIN_API_TOKEN;
    echo "Token source: .env constant\n";
}

if ($currentToken === '') {
    echo "FEHLER: Kein EasyVerein-Token konfiguriert. Bitte EASYVEREIN_API_TOKEN setzen.\n";
    exit(1);
}

// ── 2. Call refresh endpoint ─────────────────────────────────────────────────
$ch = curl_init('https://easyverein.com/api/v3.0/refresh-token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $currentToken,
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo "FEHLER (cURL): {$curlError}\n";
    exit(2);
}
if ($httpCode < 200 || $httpCode >= 300) {
    echo "FEHLER: EasyVerein antwortete mit HTTP {$httpCode}\n";
    echo "Response: {$response}\n";
    exit(2);
}

$data     = json_decode($response, true);
$newToken = $data['token'] ?? null;
if (!$newToken || !is_string($newToken) || $newToken === '') {
    echo "FEHLER: Antwort enthält kein neues Token.\n";
    echo "Response: {$response}\n";
    exit(3);
}

// Don't print the token, but show how much it actually changed
$changed = ($newToken !== $currentToken);
echo "Token erfolgreich abgerufen (" . strlen($newToken) . " Zeichen, "
    . ($changed ? 'rotiert' : 'unverändert') . ").\n";

// ── 3. Persist ────────────────────────────────────────────────────────────────
$savedToDb = false;
try {
    $db = Database::getContentDB();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key   VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by    INT
        )"
    );
    $stmt = $db->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('easyverein_api_token', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$newToken]);
    $savedToDb = true;
    echo "Token in system_settings gespeichert.\n";
} catch (Throwable $e) {
    error_log('refresh_easyverein_token: DB write failed - ' . $e->getMessage());
    echo "WARNUNG: Speichern in DB fehlgeschlagen: " . $e->getMessage() . "\n";
}

// Best-effort .env fallback
$envFile = __DIR__ . '/../.env';
if (!$savedToDb && file_exists($envFile) && is_writable($envFile)) {
    $contents = file_get_contents($envFile);
    if ($contents !== false) {
        $line = 'EASYVEREIN_API_TOKEN=' . $newToken;
        if (preg_match('/^EASYVEREIN_API_TOKEN=.*/m', $contents)) {
            $contents = preg_replace('/^EASYVEREIN_API_TOKEN=.*/m', $line, $contents);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }
        if (file_put_contents($envFile, $contents) !== false) {
            $savedToDb = true; // treat as success
            echo "Token in .env aktualisiert.\n";
        }
    }
}

if (!$savedToDb) {
    echo "FEHLER: Persistierung sowohl in DB als auch in .env fehlgeschlagen.\n";
    echo "→ Manueller Eingriff notwendig: bitte den neuen Token in system_settings\n";
    echo "  oder .env eintragen.\n";
    exit(4);
}

// ── 4. Audit log (best effort) ───────────────────────────────────────────────
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "INSERT INTO system_logs
            (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
         VALUES (0, 'cron_easyverein_token_refresh', 'cron', NULL, ?, 'cron', 'cron')"
    );
    $stmt->execute([
        'EasyVerein-Token erfolgreich rotiert (changed=' . ($changed ? '1' : '0') . ')'
    ]);
} catch (Throwable $e) {
    // ignore – audit log is optional
}

echo "\nFertig: " . date('Y-m-d H:i:s') . "\n";
exit(0);
