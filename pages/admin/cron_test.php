<?php
/**
 * Cronjob-Testseite (Vorstand/Admin).
 *
 * - Zeigt alle Cronjobs, ihren Takt und den Konfigurationsstatus (CRON_TOKEN).
 * - Sicherer Geburtstags-Test: schickt eine Beispiel-Geburtstagsmail an den
 *   aufrufenden Admin und listet die heutigen Geburtstage (ohne Massenversand).
 * - Live-Test: ruft den echten Cron-Endpunkt per HTTP (mit Token) auf und zeigt
 *   HTTP-Status + Ausgabe – damit lässt sich die komplette Cron-Kette prüfen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../src/MailService.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}
$me   = Auth::user();
$role = $me['role'] ?? '';
if (!Auth::isBoard() && $role !== 'admin') {
    http_response_code(403);
    exit('Zugriff verweigert – nur Vorstand/Admin.');
}

// Whitelist aller Cronjobs (Skript => [Beschreibung, Takt, sendetMails?])
$cronJobs = [
    'send_birthday_wishes.php'      => ['Geburtstagsmails an Mitglieder',                 'täglich 07:00',  true],
    'refresh_easyverein_token.php'  => ['EasyVerein-API-Token erneuern',                  'täglich 03:30',  false],
    'mark_student_status_check.php' => ['Studenten-Status-Abfrage (01.04. / 01.08.)',     'täglich 06:00',  false],
    'process_mail_queue.php'        => ['Mail-Warteschlange abarbeiten',                  'alle 5 Minuten', true],
    'send_alumni_reminders.php'     => ['Alumni-Erinnerungen versenden',                  'monatlich',      true],
    'send_profile_reminders.php'    => ['Profil-Aktualisierungs-Erinnerungen',            'täglich',        true],
    'sync_easyverein.php'           => ['EasyVerein-Mitgliederdaten synchronisieren',     'täglich',        false],
    'reconcile_bank_payments.php'   => ['Bankzahlungen abgleichen',                       'täglich',        false],
    'backup_database.php'           => ['Datenbank-Backup erstellen',                     'täglich',        false],
];

$cronToken      = defined('CRON_TOKEN') ? CRON_TOKEN : '';
$tokenConfigured = is_string($cronToken) && strlen($cronToken) >= 16;

$flash  = ['ok' => null, 'err' => null];
$output = null;   // Ausgabe eines Live-Tests
$outputTitle = '';

/* ── POST-Aktionen ──────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // Sichere Geburtstags-Testmail an den aufrufenden Admin
    if ($action === 'bday_self') {
        $email = (string) ($me['email'] ?? '');
        if ($email === '') {
            $flash['err'] = 'Für deinen Account ist keine E-Mail hinterlegt.';
        } else {
            try {
                $html = MailService::getBirthdayEmailTemplate(
                    (string) ($me['first_name'] ?: 'Mitglied'),
                    (string) ($me['gender'] ?? '')
                );
                $sent = MailService::sendEmail($email, '🎂 TEST: Geburtstags-Mail (IBC Intranet)', $html);
                $flash[$sent ? 'ok' : 'err'] = $sent
                    ? 'Test-Geburtstagsmail wurde an ' . $email . ' gesendet. Bitte Postfach prüfen.'
                    : 'Versand fehlgeschlagen – SMTP/Mailer prüfen (siehe logs/error.log).';
            } catch (Throwable $e) {
                error_log('cron_test bday_self: ' . $e->getMessage());
                $flash['err'] = 'Fehler beim Versand: ' . $e->getMessage();
            }
        }
    }

    // Live-Test: echten Cron-Endpunkt per HTTP aufrufen
    if ($action === 'run_http') {
        $script = (string) ($_POST['script'] ?? '');
        if (!isset($cronJobs[$script])) {
            $flash['err'] = 'Unbekanntes Cron-Skript.';
        } elseif (!$tokenConfigured) {
            $flash['err'] = 'CRON_TOKEN ist nicht (sicher) konfiguriert – Live-Test nicht möglich.';
        } else {
            $url = rtrim(BASE_URL, '/') . '/cron/' . $script . '?token=' . urlencode($cronToken);
            [$code, $body, $err] = cron_http_get($url);
            $outputTitle = $script;
            if ($err !== '') {
                $flash['err'] = 'HTTP-Aufruf fehlgeschlagen: ' . $err;
            } else {
                $flash['ok'] = 'Live-Test ausgeführt (HTTP ' . $code . ').';
            }
            $output = $body !== '' ? $body : '(keine Ausgabe)';
        }
    }
}

// Heutige Geburtstage (read-only) für die Anzeige
$todaysBirthdays = [];
try {
    $userDb = Database::getUserDB();
    $stmt = $userDb->prepare(
        "SELECT COALESCE(NULLIF(TRIM(u.first_name),''),'Mitglied') AS first_name, u.last_name, u.email, u.birthday
         FROM users u
         WHERE u.birthday IS NOT NULL
           AND DATE_FORMAT(u.birthday,'%m-%d') = :t
           AND u.deleted_at IS NULL AND u.email IS NOT NULL AND u.email <> ''
         ORDER BY u.first_name"
    );
    $stmt->execute([':t' => date('m-d')]);
    $todaysBirthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('cron_test birthdays: ' . $e->getMessage());
}

/** Interner HTTP-GET (curl bevorzugt, sonst stream). @return array{0:int,1:string,2:string} */
function cron_http_get(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, is_string($body) ? $body : '', $err];
    }
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    // Statuscode aus den Response-Headern (PHP 8.4-kompatibel ermittelt)
    $code = 0;
    $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : null;
    if (is_array($hdrs) && isset($hdrs[0]) && preg_match('~\s(\d{3})\s~', (string) $hdrs[0], $m)) {
        $code = (int) $m[1];
    }
    return [$code, is_string($body) ? $body : '', $body === false ? 'Aufruf fehlgeschlagen' : ''];
}

$title = 'Cronjob-Test - IBC Intranet';
ob_start();
?>
<style>
.ct-wrap { max-width: 60rem; }
.ct-card { background:var(--bg-card); border:1.5px solid var(--border-color); border-radius:1rem; padding:1.4rem; margin-bottom:1.4rem; }
.ct-flash { padding:.85rem 1.1rem; border-radius:.75rem; margin-bottom:1.2rem; font-size:.9rem; }
.ct-flash--ok { background:rgba(0,166,81,.12); border:1px solid rgba(0,166,81,.35); color:#10b981; }
.ct-flash--err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5; }
.ct-warn { background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.4); color:#fbbf24; }
.ct-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1rem; border:0; border-radius:.6rem;
          font-weight:700; font-size:.82rem; cursor:pointer; color:#fff; min-height:40px;
          background:linear-gradient(135deg,var(--ibc-blue),var(--ibc-green)); }
.ct-btn--soft { background:transparent; color:var(--text-muted); border:1.5px solid var(--border-color); }
.ct-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.ct-table th, .ct-table td { text-align:left; padding:.55rem .5rem; border-bottom:1px solid var(--border-color); vertical-align:middle; }
.ct-table th { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
.ct-mail-badge { font-size:.65rem; font-weight:700; padding:.1rem .45rem; border-radius:999px; background:rgba(245,158,11,.18); color:#fbbf24; }
.ct-out { background:#0b1120; color:#cbd5e1; border:1px solid var(--border-color); border-radius:.6rem;
          padding:1rem; font-family:ui-monospace,Menlo,monospace; font-size:.8rem; white-space:pre-wrap;
          max-height:24rem; overflow:auto; }
.ct-code { background:var(--bg-input); border:1px solid var(--border-color); border-radius:.4rem; padding:.15rem .4rem; font-family:ui-monospace,monospace; font-size:.8rem; word-break:break-all; }
</style>

<div class="ct-wrap">
    <h1 style="font-size:clamp(1.4rem,1.1rem+1.5vw,1.9rem);font-weight:800;margin:0 0 .3rem;">Cronjob-Test</h1>
    <p style="color:var(--text-muted);margin:0 0 1.4rem;">Cronjobs prüfen und manuell auslösen (nur Vorstand/Admin).</p>

    <?php if (!empty($flash['ok'])): ?><div class="ct-flash ct-flash--ok"><?= e($flash['ok']) ?></div><?php endif; ?>
    <?php if (!empty($flash['err'])): ?><div class="ct-flash ct-flash--err"><?= e($flash['err']) ?></div><?php endif; ?>

    <!-- Konfig-Status -->
    <div class="ct-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 .8rem;">Konfiguration</h2>
        <?php if ($tokenConfigured): ?>
            <p style="margin:0;color:#10b981;"><i class="fas fa-check-circle"></i> <strong>CRON_TOKEN</strong> ist konfiguriert – HTTP-Aufrufe der Cronjobs sind möglich.</p>
        <?php else: ?>
            <div class="ct-flash ct-warn" style="margin:0;">
                <strong>CRON_TOKEN fehlt!</strong> Ohne gesetzten <code>CRON_TOKEN</code> (≥16 Zeichen) in der <code>.env</code>
                lehnen die Cronjobs HTTP-Aufrufe ab. Trage z. B. ein:
                <div style="margin-top:.5rem;"><span class="ct-code">CRON_TOKEN=<?= e(bin2hex(random_bytes(24))) ?></span></div>
                (Vorschlagswert – frei wählbar, danach Seite neu laden.)
            </div>
        <?php endif; ?>
    </div>

    <!-- Geburtstags-Test (sicher) -->
    <div class="ct-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 .3rem;">🎂 Geburtstags-Job testen (sicher)</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 1rem;">
            Sendet eine Beispiel-Geburtstagsmail <strong>nur an dich</strong> (<?= e($me['email'] ?? '') ?>) und zeigt die heutigen Geburtstage – ohne Massenversand.
        </p>
        <form method="POST" style="display:inline;margin:0 .5rem .5rem 0;">
            <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
            <input type="hidden" name="action" value="bday_self">
            <button type="submit" class="ct-btn"><i class="fas fa-paper-plane"></i> Test-Mail an mich senden</button>
        </form>

        <h3 style="font-size:.9rem;font-weight:700;margin:1.2rem 0 .5rem;">Heutige Geburtstage (<?= date('d.m.') ?>): <?= count($todaysBirthdays) ?></h3>
        <?php if (empty($todaysBirthdays)): ?>
            <p style="color:var(--text-muted);font-size:.85rem;margin:0;">Heute hat niemand Geburtstag – der echte Job würde keine Mails senden.</p>
        <?php else: ?>
            <table class="ct-table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>Geburtsdatum</th></tr></thead>
                <tbody>
                <?php foreach ($todaysBirthdays as $b): ?>
                    <tr>
                        <td><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?></td>
                        <td><?= e($b['email'] ?? '') ?></td>
                        <td><?= e(!empty($b['birthday']) ? date('d.m.Y', strtotime((string)$b['birthday'])) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Live-Test aller Cronjobs -->
    <div class="ct-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 .3rem;">Cronjobs live testen</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 1rem;">
            Ruft den echten Cron-Endpunkt per HTTP (mit Token) auf – prüft die komplette Kette wie der echte Cron.
            <strong style="color:#fbbf24;">Achtung:</strong> Jobs mit <span class="ct-mail-badge">Mail</span> versenden dabei echte E-Mails.
        </p>
        <table class="ct-table">
            <thead><tr><th>Cronjob</th><th>Takt</th><th></th><th>Live-Test</th></tr></thead>
            <tbody>
            <?php foreach ($cronJobs as $script => $info): ?>
                <tr>
                    <td>
                        <strong><?= e($info[0]) ?></strong><br>
                        <span style="color:var(--text-muted);font-size:.72rem;"><?= e($script) ?></span>
                    </td>
                    <td style="white-space:nowrap;"><?= e($info[1]) ?></td>
                    <td><?= $info[2] ? '<span class="ct-mail-badge">Mail</span>' : '' ?></td>
                    <td>
                        <form method="POST" style="margin:0;"
                              onsubmit="return confirm('<?= $info[2] ? 'Achtung: Dieser Job versendet echte E-Mails. Jetzt ausführen?' : 'Cronjob jetzt live ausführen?' ?>');">
                            <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
                            <input type="hidden" name="action" value="run_http">
                            <input type="hidden" name="script" value="<?= e($script) ?>">
                            <button type="submit" class="ct-btn ct-btn--soft" <?= $tokenConfigured ? '' : 'disabled title="CRON_TOKEN fehlt"' ?>>
                                <i class="fas fa-play"></i> Ausführen
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($output !== null): ?>
            <h3 style="font-size:.9rem;font-weight:700;margin:1.2rem 0 .5rem;">Ausgabe: <?= e($outputTitle) ?></h3>
            <div class="ct-out"><?= e($output) ?></div>
        <?php endif; ?>
    </div>

    <!-- Cron-URLs zum Eintragen beim Hoster -->
    <?php if ($tokenConfigured): ?>
    <div class="ct-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 .8rem;">Cron-URLs (für den Hoster)</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin:0 0 .8rem;">Diese URLs im Cron-Dienst des Hosters mit dem jeweiligen Takt hinterlegen:</p>
        <?php foreach ($cronJobs as $script => $info): ?>
            <div style="margin-bottom:.5rem;">
                <span style="font-size:.75rem;color:var(--text-muted);"><?= e($info[1]) ?> –</span>
                <span class="ct-code"><?= e(rtrim(BASE_URL, '/') . '/cron/' . $script . '?token=' . $cronToken) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
