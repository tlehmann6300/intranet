<?php
/**
 * Studenten-Status-Check Modal-Seite.
 *
 * Wird aufgerufen, sobald `users.student_status_check_due = 1` gesetzt ist (durch
 * cron/mark_student_status_check.php). Bis der User antwortet, wird er bei jedem
 * Seitenaufruf hierher umgeleitet (siehe includes/handlers/StudentStatusCheck.php).
 *
 * Antworten:
 *  - studying       → Flag wird gelöscht, weiter zur Ursprungsseite.
 *  - exmatriculated → Mail an Vorstand + IT, Flag wird gelöscht, Logout (Konto bleibt bis zur Klärung).
 *  - finished       → Mail an Vorstand + IT, Flag wird gelöscht, Weiterleitung zur Alumni-Registrierung.
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentUser = Auth::user();

// Wenn nicht (mehr) fällig, direkt zurück zum Dashboard.
if (empty($currentUser['student_status_check_due'])) {
    header('Location: ../dashboard/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $answer = $_POST['answer'] ?? '';

    if (!in_array($answer, ['studying', 'exmatriculated', 'finished'], true)) {
        $error = 'Bitte wähle eine gültige Antwort aus.';
    } else {
        $db = Database::getUserDB();

        // Flag in jedem Fall löschen, der User hat geantwortet.
        $clear = $db->prepare("UPDATE users SET student_status_check_due = 0 WHERE id = ?");
        $clear->execute([$currentUser['id']]);

        $vorstandMail = defined('INVOICE_NOTIFICATION_EMAIL') ? INVOICE_NOTIFICATION_EMAIL : 'vorstand@business-consulting.de';
        $itMail       = defined('MAIL_IT_RESSORT') ? MAIL_IT_RESSORT : 'it@business-consulting.de';

        $fullName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $currentUser['email'] ?? ('User #' . $currentUser['id']);
        }
        $email = $currentUser['email'] ?? '';

        if ($answer === 'studying') {
            // Nichts zu tun – Flag ist gelöscht, weiter geht's.
            header('Location: ../dashboard/index.php');
            exit;
        }

        if ($answer === 'exmatriculated') {
            $subject = '[IBC Intranet] Mitglied exmatrikuliert: ' . $fullName;
            $body = sprintf(
                '<p>Hallo Vorstand,</p>'
                . '<p>Das Mitglied <strong>%s</strong> (%s) hat im Studenten-Status-Check angegeben, dass es exmatrikuliert wurde.</p>'
                . '<p>Bitte das weitere Vorgehen (Mitgliedschaft, Datenpflege) prüfen.</p>'
                . '<p>– IBC Intranet (automatisch)</p>',
                htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
            );
            @MailService::sendEmail($vorstandMail, $subject, $body);
            @MailService::sendEmail($itMail, $subject, $body);

            // User abmelden, Hinweis hinterlegen.
            $_SESSION['logout_message'] = 'Vielen Dank für deine Rückmeldung. Der Vorstand wurde informiert.';
            Auth::logout();
            header('Location: login.php?msg=exmatrikuliert');
            exit;
        }

        if ($answer === 'finished') {
            $subject = '[IBC Intranet] Mitglied wechselt in den Alumni-Status: ' . $fullName;
            $body = sprintf(
                '<p>Hallo Vorstand,</p>'
                . '<p>Das Mitglied <strong>%s</strong> (%s) hat sein Studium abgeschlossen und wird in den Alumni-Status überführt.</p>'
                . '<p>Es wurde an die Alumni-Registrierung weitergeleitet.</p>'
                . '<p>– IBC Intranet (automatisch)</p>',
                htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
            );
            @MailService::sendEmail($vorstandMail, $subject, $body);
            @MailService::sendEmail($itMail, $subject, $body);

            // Hinweis für die Alumni-Registrierung in Session ablegen.
            $_SESSION['alumni_registration_prefill'] = [
                'name'  => $fullName,
                'email' => $email,
            ];
            header('Location: ../public/neue_alumni.php?from=status_check');
            exit;
        }
    }
}

$pageTitle = 'Studenten-Status bestätigen – IBC Intranet';
ob_start();
?>
<style>
.ssc-container { max-width: 640px; margin: 4rem auto; padding: 0 1rem; }
.ssc-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 2.25rem;
    box-shadow: var(--shadow-card);
}
.ssc-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--text-main); }
.ssc-subtitle { color: var(--text-muted); margin-bottom: 1.5rem; line-height: 1.5; }
.ssc-options { display: flex; flex-direction: column; gap: 0.75rem; margin: 1.5rem 0; }
.ssc-option {
    display: flex; align-items: flex-start; gap: 0.75rem;
    padding: 1rem 1.25rem; border-radius: 0.75rem;
    border: 1px solid var(--border-color);
    background: var(--bg-body); cursor: pointer; transition: all 0.15s;
}
.ssc-option:hover { border-color: var(--ibc-blue); transform: translateY(-1px); }
.ssc-option input[type=radio] { margin-top: 0.25rem; }
.ssc-option-label { font-weight: 600; color: var(--text-main); }
.ssc-option-desc { font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem; }
.ssc-submit {
    width: 100%;
    padding: 0.85rem 1.5rem;
    border: none; border-radius: 0.75rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    color: white; font-weight: 700; font-size: 1rem; cursor: pointer;
}
.ssc-submit:hover { filter: brightness(1.05); }
.ssc-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
</style>

<div class="ssc-container">
    <div class="ssc-card">
        <h1 class="ssc-title"><i class="fas fa-user-graduate"></i> Studenten-Status bestätigen</h1>
        <p class="ssc-subtitle">
            Halbjährlich (am 1. April und 1. August) prüfen wir, ob unsere Mitglieder noch
            eingeschrieben sind. Bitte teile uns deinen aktuellen Status mit – das dauert nur einen Klick.
        </p>

        <?php if ($error): ?>
            <div class="ssc-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="ssc-options">
                <label class="ssc-option">
                    <input type="radio" name="answer" value="studying" required>
                    <span>
                        <span class="ssc-option-label">Ja, ich studiere weiterhin.</span>
                        <span class="ssc-option-desc">Du bleibst aktives Mitglied – nichts ändert sich.</span>
                    </span>
                </label>
                <label class="ssc-option">
                    <input type="radio" name="answer" value="exmatriculated">
                    <span>
                        <span class="ssc-option-label">Nein, ich wurde exmatrikuliert.</span>
                        <span class="ssc-option-desc">Vorstand und IT werden informiert.</span>
                    </span>
                </label>
                <label class="ssc-option">
                    <input type="radio" name="answer" value="finished">
                    <span>
                        <span class="ssc-option-label">Nein, ich habe mein Studium abgeschlossen.</span>
                        <span class="ssc-option-desc">Du wirst zur Alumni-Registrierung weitergeleitet, Vorstand und IT erhalten eine Info-Mail.</span>
                    </span>
                </label>
            </div>
            <button type="submit" class="ssc-submit">Antwort speichern</button>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
