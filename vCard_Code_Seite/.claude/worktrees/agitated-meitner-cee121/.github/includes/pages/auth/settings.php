<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../src/MailService.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$message = '';
$error = '';
$showQRCode = false;
$secret = '';
$qrCodeUrl = '';

// Check for session messages (from email confirmation, etc.)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    if (isset($_POST['update_privacy'])) {
        $privacyData = [
            'privacy_hide_email'  => isset($_POST['privacy_hide_email'])  ? 1 : 0,
            'privacy_hide_phone'  => isset($_POST['privacy_hide_phone'])  ? 1 : 0,
            'privacy_hide_career' => isset($_POST['privacy_hide_career']) ? 1 : 0,
        ];
        if (User::updateProfile($user['id'], $privacyData)) {
            $message = 'Datenschutz-Einstellungen erfolgreich gespeichert';
            $user = Auth::user();
        } else {
            $error = 'Fehler beim Speichern der Datenschutz-Einstellungen';
        }
    } elseif (isset($_POST['update_theme'])) {
        $theme = $_POST['theme'] ?? 'auto';
        
        // Validate theme value
        if (!in_array($theme, ['light', 'dark', 'auto'])) {
            $theme = 'auto';
        }
        
        if (User::updateThemePreference($user['id'], $theme)) {
            $message = 'Design-Einstellungen erfolgreich gespeichert';
            $user = Auth::user(); // Reload user data
        } else {
            $error = 'Fehler beim Speichern der Design-Einstellungen';
        }
    } elseif (isset($_POST['enable_2fa'])) {
        $ga = new PHPGangsta_GoogleAuthenticator();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
        $showQRCode = true;
    } elseif (isset($_POST['confirm_2fa'])) {
        $secret = $_POST['secret'] ?? '';
        $code = $_POST['code'] ?? '';
        
        $ga = new PHPGangsta_GoogleAuthenticator();
        if ($ga->verifyCode($secret, $code, 2)) {
            if (User::enable2FA($user['id'], $secret)) {
                $message = '2FA erfolgreich aktiviert';
                $user = Auth::user(); // Reload user
            } else {
                $error = 'Fehler beim Aktivieren von 2FA';
            }
        } else {
            $error = 'Ungültiger Code. Bitte versuche es erneut.';
            $secret = $_POST['secret'];
            $ga = new PHPGangsta_GoogleAuthenticator();
            $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
            $showQRCode = true;
        }
    } elseif (isset($_POST['disable_2fa'])) {
        if (User::disable2FA($user['id'])) {
            $message = '2FA erfolgreich deaktiviert';
            $user = Auth::user(); // Reload user
        } else {
            $error = 'Fehler beim Deaktivieren von 2FA';
        }
    } elseif (isset($_POST['submit_change_request'])) {
        try {
            $requestType = trim($_POST['request_type'] ?? '');
            $requestReason = trim($_POST['request_reason'] ?? '');

            $allowedTypes = ['Rollenänderung', 'E-Mail-Adressenänderung'];
            if (!in_array($requestType, $allowedTypes, true)) {
                throw new Exception('Ungültiger Änderungstyp. Bitte wählen Sie eine gültige Option.');
            }

            if (strlen($requestReason) < 10) {
                throw new Exception('Bitte geben Sie eine ausführlichere Begründung an (mindestens 10 Zeichen).');
            }
            if (strlen($requestReason) > 1000) {
                throw new Exception('Die Begründung ist zu lang (maximal 1000 Zeichen).');
            }

            $currentRole = translateRole($user['role'] ?? '');

            $emailBody = MailService::getTemplate(
                'Änderungsantrag',
                '<p>Ein Benutzer hat einen Änderungsantrag gestellt:</p>
                <table class="info-table">
                    <tr>
                        <td class="info-label">E-Mail:</td>
                        <td class="info-value">' . htmlspecialchars($user['email']) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Aktuelle Rolle:</td>
                        <td class="info-value">' . htmlspecialchars($currentRole) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Art der Änderung:</td>
                        <td class="info-value">' . htmlspecialchars($requestType) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Begründung / Neuer Wert:</td>
                        <td class="info-value">' . nl2br(htmlspecialchars($requestReason)) . '</td>
                    </tr>
                </table>'
            );

            $emailSent = MailService::sendEmail(
                MAIL_IT_RESSORT,
                'Änderungsantrag: ' . $requestType . ' von ' . $user['email'],
                $emailBody
            );

            if ($emailSent) {
                $message = 'Ihr Änderungsantrag wurde erfolgreich eingereicht!';
            } else {
                $error = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
            }
        } catch (Exception $e) {
            error_log('Error submitting change request: ' . $e->getMessage());
            $error = htmlspecialchars($e->getMessage());
        }
    }
}

$title = 'Einstellungen';
ob_start();
?>

<style>
/* ── Settings Page ────────────────────────────────────────── */
.set-container {
    max-width: 64rem;
    margin: 0 auto;
}

.set-page-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.set-header-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 1rem;
    background: rgba(0,102,179,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--ibc-blue);
    font-size: 1.25rem;
}

.set-page-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.02em;
    margin: 0;
}

.set-page-subtitle {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin: 0.25rem 0 0;
}

.set-alert {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1.125rem;
    border-radius: 0.875rem;
    border: 1.5px solid;
    margin-bottom: 1.5rem;
    font-size: 0.9375rem;
}

.set-alert--success {
    background: rgba(0,166,81,0.08);
    border-color: rgba(0,166,81,0.2);
    color: var(--ibc-green);
}

.set-alert--error {
    background: rgba(239,68,68,0.08);
    border-color: rgba(239,68,68,0.2);
    color: #ef4444;
}

.set-alert--info {
    background: rgba(59,130,246,0.08);
    border-color: rgba(59,130,246,0.2);
    color: var(--ibc-blue);
}

.set-notice {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 0.875rem;
    padding: 1rem 1.125rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

.set-notice-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: rgba(59,130,246,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--ibc-blue);
    font-size: 1rem;
    margin-top: 0.25rem;
}

.set-notice-content h3 {
    margin: 0 0 0.25rem;
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-main);
}

.set-notice-content p {
    margin: 0;
    font-size: 0.9375rem;
    color: var(--text-muted);
}

.set-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    box-shadow: var(--shadow-card);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.set-card-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: rgba(100,116,139,0.04);
}

.set-card-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1rem;
}

.set-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 0;
}

.set-card-subtitle {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin: 0;
}

.set-card-body {
    padding: 1.5rem;
}

.set-form-group {
    margin-bottom: 1.25rem;
}

.set-form-group:last-child {
    margin-bottom: 0;
}

.set-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.set-input,
.set-select,
.set-textarea {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    background: var(--bg-body);
    color: var(--text-main);
    font-size: 0.9375rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
    min-height: 44px;
}

.set-input:focus,
.set-select:focus,
.set-textarea:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0,102,179,0.1);
}

.set-input::placeholder,
.set-textarea::placeholder {
    color: var(--text-muted);
}

.set-input[type="email"]:read-only,
.set-input[type="text"]:read-only {
    background: rgba(100,116,139,0.06);
    cursor: not-allowed;
}

.set-textarea {
    min-height: auto;
    resize: vertical;
}

.set-toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem;
    border-radius: 0.875rem;
    background: rgba(100,116,139,0.04);
    margin-bottom: 0.75rem;
}

.set-toggle-row:last-child {
    margin-bottom: 0;
}

.set-toggle-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.set-toggle-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.875rem;
    background: rgba(100,116,139,0.1);
}

.set-toggle-label {
    font-weight: 600;
    color: var(--text-main);
}

.set-toggle {
    display: flex;
    align-items: center;
    cursor: pointer;
    min-height: 44px;
}

.set-toggle input[type="checkbox"] {
    display: none;
}

.set-toggle-switch {
    position: relative;
    width: 2.75rem;
    height: 1.5rem;
    background: var(--border-color);
    border-radius: 9999px;
    transition: background 0.2s;
}

.set-toggle input[type="checkbox"]:checked ~ .set-toggle-switch {
    background: var(--ibc-green);
}

.set-toggle-switch::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 1.25rem;
    height: 1.25rem;
    background: #fff;
    border-radius: 50%;
    transition: transform 0.2s;
}

.set-toggle input[type="checkbox"]:checked ~ .set-toggle-switch::after {
    transform: translateX(1.25rem);
}

.set-button-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.set-btn-primary,
.set-btn-secondary {
    padding: 0.75rem 1.5rem;
    border-radius: 0.625rem;
    border: none;
    font-size: 0.9375rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(.22,.68,0,1.2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    min-height: 44px;
}

.set-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    color: #fff;
    box-shadow: 0 2px 10px rgba(0,102,179,0.25);
}

.set-btn-primary:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

.set-btn-primary:active {
    transform: translateY(0);
}

.set-btn-secondary {
    background: var(--bg-body);
    color: var(--text-main);
    border: 1.5px solid var(--border-color);
}

.set-btn-secondary:hover {
    background: rgba(100,116,139,0.1);
}

.set-btn-danger {
    background: #ef4444;
    color: #fff;
    box-shadow: 0 2px 10px rgba(239,68,68,0.25);
}

.set-btn-danger:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

.set-grid-themes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
    gap: 1rem;
    margin: 1rem 0;
}

.set-theme-option {
    padding: 1rem;
    border: 2px solid var(--border-color);
    border-radius: 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    background: var(--bg-body);
}

.set-theme-option input[type="radio"] {
    display: none;
}

.set-theme-option input[type="radio"]:checked + .set-theme-option-content {
    color: var(--ibc-blue);
}

.set-theme-option input[type="radio"]:checked ~ .set-theme-option {
    border-color: var(--ibc-blue);
    background: rgba(0,102,179,0.05);
}

.set-theme-icon {
    font-size: 2.5rem;
}

.set-theme-label {
    font-weight: 700;
    font-size: 0.9375rem;
    color: var(--text-main);
}

.set-theme-desc {
    font-size: 0.8125rem;
    color: var(--text-muted);
}

.set-qrcode-section {
    text-align: center;
}

.set-qrcode-box {
    display: inline-block;
    padding: 1rem;
    background: var(--bg-body);
    border-radius: 0.875rem;
    margin-bottom: 1rem;
}

.set-secret-code {
    display: inline-block;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-family: 'Monaco', 'Menlo', monospace;
    font-size: 0.875rem;
    color: var(--text-main);
    word-break: break-all;
}

.set-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
}

.set-status-active {
    background: rgba(0,166,81,0.1);
    color: var(--ibc-green);
    border: 1px solid rgba(0,166,81,0.2);
}

.set-status-inactive {
    background: rgba(100,116,139,0.1);
    color: var(--text-muted);
    border: 1px solid rgba(100,116,139,0.2);
}

.set-help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: 1rem;
}

.set-help-button {
    padding: 1rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.875rem;
    background: var(--bg-body);
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 1rem;
    min-height: 44px;
}

.set-help-button:hover {
    border-color: var(--ibc-blue);
    background: rgba(0,102,179,0.05);
}

.set-help-icon {
    font-size: 1.75rem;
    width: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.set-help-text-title {
    font-weight: 700;
    font-size: 0.9375rem;
    color: var(--text-main);
    margin: 0;
}

.set-help-text-desc {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin: 0.25rem 0 0;
}

.dark-mode .set-input[type="email"]:read-only,
.dark-mode .set-input[type="text"]:read-only {
    background: rgba(0,102,179,0.08);
}

@media (max-width: 640px) {
    .set-grid-themes {
        grid-template-columns: 1fr;
    }

    .set-help-grid {
        grid-template-columns: 1fr;
    }

    .set-button-group {
        flex-direction: column;
    }

    .set-btn-primary,
    .set-btn-secondary {
        width: 100%;
    }
}

/* ── Support Modal – dark mode overrides ─────────────────────── */
.dark-mode #supportModal > div {
    background: var(--bg-card) !important;
    border: 1.5px solid rgba(255,255,255,.08);
    box-shadow: 0 16px 56px rgba(0,0,0,.65);
}
.dark-mode #supportModal h3,
.dark-mode #supportModal label {
    color: var(--text-main) !important;
}
.dark-mode #supportModal button[onclick="hideSupportModal()"] {
    color: var(--text-muted) !important;
}
.dark-mode #supportModal button[onclick="hideSupportModal()"]:hover {
    color: var(--text-main) !important;
}
.dark-mode #supportModal select,
.dark-mode #supportModal textarea {
    background: rgba(255,255,255,.05) !important;
    border-color: rgba(255,255,255,.12) !important;
    color: var(--text-main) !important;
}
.dark-mode #supportModal select:focus,
.dark-mode #supportModal textarea:focus {
    border-color: rgba(0,102,179,.5) !important;
    box-shadow: 0 0 0 3px rgba(0,102,179,.15) !important;
    outline: none;
}
.dark-mode #supportModal .flex.flex-col button:first-of-type {
    background: rgba(255,255,255,.07) !important;
    color: var(--text-muted) !important;
}
.dark-mode #supportModal .flex.flex-col button:first-of-type:hover {
    background: rgba(255,255,255,.12) !important;
    color: var(--text-main) !important;
}
</style>

<div class="set-container">
    <!-- Page Header -->
    <div class="set-page-header">
        <div class="set-header-icon">
            <i class="fas fa-cog"></i>
        </div>
        <div>
            <h1 class="set-page-title">Einstellungen</h1>
            <p class="set-page-subtitle">Verwalte deine persönlichen Einstellungen</p>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="set-alert set-alert--success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="set-alert set-alert--error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Microsoft Notice -->
    <div class="set-notice">
        <div class="set-notice-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div class="set-notice-content">
            <h3>Zentral verwaltetes Profil</h3>
            <p>Ihr Profil wird zentral über Microsoft verwaltet. Änderungen an E-Mail oder Rolle können über das untenstehende Formular beantragt werden.</p>
        </div>
    </div>

    <!-- Current Profile (Read-Only) -->
    <div class="set-card">
        <div class="set-card-header">
            <div class="set-card-icon" style="background: rgba(59,130,246,0.1); color: var(--ibc-blue);">
                <i class="fas fa-user"></i>
            </div>
            <div>
                <p class="set-card-title">Aktuelles Profil</p>
            </div>
        </div>
        <div class="set-card-body">
            <div class="set-form-group">
                <label class="set-label">E-Mail-Adresse</label>
                <input
                    type="email"
                    readonly
                    value="<?php echo htmlspecialchars($user['email']); ?>"
                    class="set-input"
                >
            </div>
            <div class="set-form-group">
                <label class="set-label">Rolle</label>
                <input
                    type="text"
                    readonly
                    value="<?php echo htmlspecialchars(translateRole($user['role'])); ?>"
                    class="set-input"
                >
            </div>
        </div>
    </div>

    <!-- Settings Grid -->
    <div>

        <!-- 2FA Settings -->
        <div class="set-card">
            <!-- Section header -->
            <div class="set-card-header" style="background: rgba(0,166,81,0.08);">
                <div class="set-card-icon" style="background: rgba(0,166,81,0.1); color: var(--ibc-green);">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <p class="set-card-title">Sicherheit</p>
                    <p class="set-card-subtitle">Zwei-Faktor-Authentifizierung (2FA)</p>
                </div>
            </div>
            <div class="set-card-body">

                <?php if (!$showQRCode): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; gap: 1rem;">
                    <div>
                        <p style="margin: 0 0 0.5rem; color: var(--text-main);">
                            Status:
                            <?php if ($user['tfa_enabled']): ?>
                            <span class="set-status-badge set-status-active">
                                <i class="fas fa-check-circle"></i>Aktiviert
                            </span>
                            <?php else: ?>
                            <span class="set-status-badge set-status-inactive">
                                <i class="fas fa-times-circle"></i>Deaktiviert
                            </span>
                            <?php endif; ?>
                        </p>
                        <p style="margin: 0; font-size: 0.9375rem; color: var(--text-muted);">
                            Schütze dein Konto mit einer zusätzlichen Sicherheitsebene
                        </p>
                    </div>
                    <div>
                        <?php if ($user['tfa_enabled']): ?>
                        <form method="POST" onsubmit="return confirm('Möchtest du 2FA wirklich deaktivieren?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="disable_2fa" class="set-btn-danger">
                                <i class="fas fa-times"></i>2FA deaktivieren
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="enable_2fa" class="set-btn-primary">
                                <i class="fas fa-plus"></i>2FA aktivieren
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="set-notice" style="border-color: rgba(59,130,246,0.2); background: rgba(59,130,246,0.04);">
                    <div class="set-notice-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="set-notice-content">
                        <p style="margin: 0; font-size: 0.9375rem; color: var(--text-muted);"><strong>Empfehlung:</strong> Aktiviere 2FA für zusätzliche Sicherheit. Du benötigst eine Authenticator-App wie Google Authenticator oder Authy.</p>
                    </div>
                </div>
                <?php else: ?>
                <!-- QR Code Setup -->
                <div class="set-qrcode-section">
                    <div style="margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-main); margin: 0 0 0.5rem;">2FA einrichten</h3>
                        <p style="font-size: 0.9375rem; color: var(--text-muted); margin: 0 0 1rem;">
                            Scanne den QR-Code mit deiner Authenticator-App und gib den generierten Code ein
                        </p>
                        <div class="set-qrcode-box" id="qrcode"></div>
                        <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
                            Geheimer Schlüssel (manuell): <code class="set-secret-code"><?php echo htmlspecialchars($secret); ?></code>
                        </p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="secret" value="<?php echo htmlspecialchars($secret); ?>">
                        <div class="set-form-group">
                            <label class="set-label">6-stelliger Code</label>
                            <input
                                type="text"
                                name="code"
                                required
                                maxlength="6"
                                pattern="[0-9]{6}"
                                class="set-input"
                                style="text-align: center; font-size: 1.5rem; letter-spacing: 0.25em;"
                                placeholder="000000"
                                autofocus
                            >
                        </div>
                        <div class="set-button-group">
                            <a href="settings.php" class="set-btn-secondary">
                                Abbrechen
                            </a>
                            <button type="submit" name="confirm_2fa" class="set-btn-primary">
                                <i class="fas fa-check"></i>Bestätigen
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Privacy Settings -->
        <div class="set-card">
            <!-- Section header -->
            <div class="set-card-header" style="background: rgba(220,38,38,0.08);">
                <div class="set-card-icon" style="background: rgba(220,38,38,0.1); color: #ef4444;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <p class="set-card-title">Datenschutz</p>
                    <p class="set-card-subtitle">Sichtbarkeit deiner Profildaten für andere Mitglieder</p>
                </div>
            </div>
            <div class="set-card-body">
                <p style="margin: 0 0 1.25rem; font-size: 0.9375rem; color: var(--text-muted);">
                    Lege fest, welche Informationen deines Profils für reguläre Mitglieder sichtbar sind.
                    Verborgen gestellte Daten sind weiterhin für Vorstände und Alumni sichtbar.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php
                    $privacyItems = [
                        ['key' => 'privacy_hide_email',  'label' => 'E-Mail verbergen',         'icon' => 'fa-envelope'],
                        ['key' => 'privacy_hide_phone',  'label' => 'Telefonnummer verbergen',   'icon' => 'fa-phone'],
                        ['key' => 'privacy_hide_career', 'label' => 'Karrieredaten verbergen',   'icon' => 'fa-briefcase'],
                    ];
                    foreach ($privacyItems as $item):
                        $isHidden = !empty($user[$item['key']]);
                    ?>
                    <div class="set-toggle-row">
                        <div class="set-toggle-content">
                            <div class="set-toggle-icon" style="background: rgba(220,38,38,0.1); color: #ef4444;">
                                <i class="fas <?php echo $item['icon']; ?>"></i>
                            </div>
                            <span class="set-toggle-label"><?php echo $item['label']; ?></span>
                        </div>
                        <label class="set-toggle">
                            <input type="checkbox" name="<?php echo $item['key']; ?>" value="1" <?php echo $isHidden ? 'checked' : ''; ?>>
                            <div class="set-toggle-switch"></div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" name="update_privacy" class="set-btn-primary" style="width: 100%; margin-top: 1rem;">
                        <i class="fas fa-save"></i>Datenschutz-Einstellungen speichern
                    </button>
                </form>
            </div>
        </div>

        <!-- Theme Settings -->
        <div class="set-card">
            <!-- Section header -->
            <div class="set-card-header" style="background: rgba(168,85,247,0.08);">
                <div class="set-card-icon" style="background: rgba(168,85,247,0.1); color: #a855f7;">
                    <i class="fas fa-palette"></i>
                </div>
                <div>
                    <p class="set-card-title">Erscheinungsbild</p>
                    <p class="set-card-subtitle">Design-Theme auswählen</p>
                </div>
            </div>
            <div class="set-card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="set-grid-themes">
                        <!-- Light Theme -->
                        <div class="set-theme-option" style="<?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'border-color: var(--ibc-blue); background: rgba(0,102,179,0.05);' : ''; ?>">
                            <input type="radio" name="theme" value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'checked' : ''; ?>>
                            <div class="set-theme-icon" style="color: #eab308;">
                                <i class="fas fa-sun"></i>
                            </div>
                            <span class="set-theme-label">Hellmodus</span>
                            <span class="set-theme-desc">Immer helles Design</span>
                        </div>

                        <!-- Dark Theme -->
                        <div class="set-theme-option" style="<?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'border-color: var(--ibc-blue); background: rgba(0,102,179,0.05);' : ''; ?>">
                            <input type="radio" name="theme" value="dark" <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'checked' : ''; ?>>
                            <div class="set-theme-icon" style="color: #6366f1;">
                                <i class="fas fa-moon"></i>
                            </div>
                            <span class="set-theme-label">Dunkelmodus</span>
                            <span class="set-theme-desc">Immer dunkles Design</span>
                        </div>

                        <!-- Auto Theme -->
                        <div class="set-theme-option" style="<?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'border-color: var(--ibc-blue); background: rgba(0,102,179,0.05);' : ''; ?>">
                            <input type="radio" name="theme" value="auto" <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'checked' : ''; ?>>
                            <div class="set-theme-icon" style="color: var(--text-muted);">
                                <i class="fas fa-adjust"></i>
                            </div>
                            <span class="set-theme-label">Automatisch</span>
                            <span class="set-theme-desc">Folgt Systemeinstellung</span>
                        </div>
                    </div>

                    <button type="submit" name="update_theme" class="set-btn-primary" style="width: 100%; margin-top: 1rem;">
                        <i class="fas fa-save"></i>Design-Einstellungen speichern
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- GDPR Data Export -->
    <div class="set-card">
        <!-- Section header -->
        <div class="set-card-header" style="background: rgba(20,184,166,0.08);">
            <div class="set-card-icon" style="background: rgba(20,184,166,0.1); color: #14b8a6;">
                <i class="fas fa-file-export"></i>
            </div>
            <div>
                <p class="set-card-title">DSGVO / Datenschutz</p>
                <p class="set-card-subtitle">Datenschutz-Grundverordnung – Deine Rechte</p>
            </div>
        </div>
        <div class="set-card-body">
            <p style="margin: 0 0 1.25rem; font-size: 0.9375rem; color: var(--text-muted);">
                Gemäß DSGVO Art. 20 kannst du alle zu deiner Person gespeicherten Daten als CSV-Datei herunterladen (Profil, Ausleihen, Event-Teilnahmen).
            </p>
            <form method="POST" action="<?php echo asset('api/export_user_data.php'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="set-btn-primary" style="width: 100%; background: linear-gradient(135deg, #14b8a6, #06b6d4);">
                    <i class="fas fa-download"></i>Meine Daten anfordern / exportieren
                </button>
            </form>
        </div>
    </div>

    <!-- Änderungsantrag Section -->
    <div id="aenderungsantrag" class="set-card">
        <!-- Section header -->
        <div class="set-card-header" style="background: rgba(234,88,12,0.08);">
            <div class="set-card-icon" style="background: rgba(234,88,12,0.1); color: #ea580c;">
                <i class="fas fa-file-alt"></i>
            </div>
            <div>
                <p class="set-card-title">Änderungsantrag</p>
                <p class="set-card-subtitle">Rollenänderung oder E-Mail-Adressenänderung beantragen</p>
            </div>
        </div>
        <div class="set-card-body">
            <p style="margin: 0 0 1.25rem; font-size: 0.9375rem; color: var(--text-muted);">
                Wenn deine Rolle nicht korrekt ist oder du eine andere E-Mail-Adresse hinterlegen möchtest, kannst du hier einen Änderungsantrag stellen. Der Antrag wird per E-Mail an den Vorstand weitergeleitet.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="set-form-group">
                    <label for="change-request-type" class="set-label">Art der Änderung</label>
                    <select id="change-request-type" name="request_type" required class="set-select">
                        <option value="">Bitte auswählen...</option>
                        <option value="Rollenänderung">Rollenänderung</option>
                        <option value="E-Mail-Adressenänderung">E-Mail-Adressenänderung</option>
                    </select>
                </div>
                <div class="set-form-group">
                    <label for="change-request-reason" class="set-label">Begründung / Neuer Wert</label>
                    <textarea id="change-request-reason" name="request_reason" rows="4" required minlength="10" maxlength="1000"
                              placeholder="Beschreibe dein Anliegen ausführlich (z. B. welche Rolle du haben solltest und warum)..."
                              class="set-textarea"></textarea>
                    <p style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.375rem;">Mindestens 10 Zeichen, maximal 1000 Zeichen.</p>
                </div>
                <button type="submit" name="submit_change_request" class="set-btn-primary" style="width: 100%; background: linear-gradient(135deg, #ea580c, #f97316); margin-top: 1rem;">
                    <i class="fas fa-paper-plane"></i>Änderungsantrag senden
                </button>
            </form>
        </div>
    </div>

    <!-- Support Section -->
    <div class="set-card">
        <!-- Section header -->
        <div class="set-card-header" style="background: rgba(59,130,246,0.08);">
            <div class="set-card-icon" style="background: rgba(59,130,246,0.1); color: var(--ibc-blue);">
                <i class="fas fa-life-ring"></i>
            </div>
            <div>
                <p class="set-card-title">Hilfe &amp; Support</p>
                <p class="set-card-subtitle">Wende dich bei Fragen direkt an die IT-Ressortleitung</p>
            </div>
        </div>
        <div class="set-card-body">
            <div class="set-help-grid">
                <button type="button" onclick="showSupportModal('2fa_reset')" class="set-help-button" style="border-color: rgba(245,158,11,0.2); background: rgba(245,158,11,0.04);">
                    <div class="set-help-icon" style="color: #f59e0b;">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <p class="set-help-text-title">2FA zurücksetzen</p>
                        <p class="set-help-text-desc">Anfrage per E-Mail senden</p>
                    </div>
                </button>
                <button type="button" onclick="showSupportModal('bug')" class="set-help-button" style="border-color: rgba(239,68,68,0.2); background: rgba(239,68,68,0.04);">
                    <div class="set-help-icon" style="color: #ef4444;">
                        <i class="fas fa-bug"></i>
                    </div>
                    <div>
                        <p class="set-help-text-title">Bug melden</p>
                        <p class="set-help-text-desc">Fehler per E-Mail melden</p>
                    </div>
                </button>
            </div>
        </div>
    </div>

</div>

<!-- Support Modal -->
<div id="supportModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold dark:text-white">
                    <i class="fas fa-headset text-blue-600 mr-2"></i>Hilfe
                </h3>
                <button type="button" onclick="hideSupportModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form id="support-modal-form" method="POST" action="<?php echo asset('api/submit_support.php'); ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label for="support-modal-type" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Art der Anfrage</label>
                    <select id="support-modal-type" name="request_type" required
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Bitte auswählen...</option>
                        <option value="2fa_reset">2FA zurücksetzen</option>
                        <option value="bug">Bug / Fehler</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                <div>
                    <label for="support-modal-description" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung</label>
                    <textarea id="support-modal-description" name="description" rows="4" required
                              placeholder="Beschreibe dein Anliegen..."
                              class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                <div id="support-modal-feedback" class="hidden"></div>
                <div class="flex flex-col md:flex-row gap-3 justify-end">
                    <button type="button" onclick="hideSupportModal()"
                            class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                        Abbrechen
                    </button>
                    <button type="submit" id="support-modal-submit-btn" class="btn-primary">
                        <i class="fas fa-paper-plane mr-2"></i>Senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QR Code Library -->
<?php if ($showQRCode): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<?php endif; ?>

<script>
// Make theme selection more interactive
document.querySelectorAll('input[name="theme"]').forEach(radio => {
    radio.addEventListener('change', function() {
        // Remove highlight from all labels
        document.querySelectorAll('label[class*="border-2"]').forEach(label => {
            label.classList.remove('border-purple-500', 'bg-purple-50');
            label.classList.add('border-gray-200');
        });
        
        // Add highlight to selected label
        const selectedLabel = this.closest('label');
        selectedLabel.classList.remove('border-gray-200');
        selectedLabel.classList.add('border-purple-500', 'bg-purple-50');
    });
});

// Show the support modal with a pre-selected type
function showSupportModal(type) {
    const modal = document.getElementById('supportModal');
    if (!modal) return;
    const select = document.getElementById('support-modal-type');
    if (select && type) {
        select.value = type;
        select.disabled = true;
        let hidden = document.getElementById('support-modal-type-hidden');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'support-modal-type-hidden';
            hidden.name = 'request_type';
            select.parentNode.appendChild(hidden);
        }
        hidden.value = type;
    }
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function hideSupportModal() {
    const modal = document.getElementById('supportModal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    const select = document.getElementById('support-modal-type');
    if (select) {
        select.disabled = false;
    }
    const hidden = document.getElementById('support-modal-type-hidden');
    if (hidden) hidden.remove();
    const form = document.getElementById('support-modal-form');
    if (form) form.reset();
    const feedback = document.getElementById('support-modal-feedback');
    if (feedback) {
        feedback.className = 'hidden';
        feedback.textContent = '';
    }
}

// Close modal on backdrop click
document.getElementById('supportModal') && document.getElementById('supportModal').addEventListener('click', function(e) {
    if (e.target === this) hideSupportModal();
});

// AJAX submission for the support modal form
(function() {
    const supportForm = document.getElementById('support-modal-form');
    if (!supportForm) return;
    supportForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('support-modal-submit-btn');
        const feedback = document.getElementById('support-modal-feedback');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Wird gesendet...';

        fetch(this.action, { method: 'POST', body: new FormData(this) })
            .then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function(data) {
                feedback.classList.remove('hidden');
                if (data.success) {
                    feedback.className = 'mt-2 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg';
                    feedback.textContent = data.message || 'Anfrage erfolgreich gesendet!';
                    supportForm.reset();
                    submitBtn.innerHTML = originalText;
                } else {
                    feedback.className = 'mt-2 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg';
                    feedback.textContent = data.message || 'Fehler beim Senden der Anfrage.';
                    submitBtn.innerHTML = originalText;
                }
                submitBtn.disabled = false;
            })
            .catch(function() {
                feedback.className = 'mt-2 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg';
                feedback.classList.remove('hidden');
                feedback.textContent = 'Fehler beim Senden der Anfrage.';
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
    });
}());

// Sync theme preference with localStorage after successful save
<?php if ($message && strpos($message, 'Design-Einstellungen') !== false): ?>
// Theme was just saved, update data-user-theme attribute and apply theme immediately
const newTheme = '<?php echo htmlspecialchars($user['theme_preference'] ?? 'auto'); ?>';
document.body.setAttribute('data-user-theme', newTheme);
localStorage.setItem('theme', newTheme);

// Apply theme immediately
// Note: Both 'dark-mode' and 'dark' classes are required:
// - 'dark-mode' is used by custom CSS rules for sidebar and specific components
// - 'dark' is used by Tailwind's dark mode (darkMode: 'class' in config)
if (newTheme === 'dark') {
    document.body.classList.add('dark-mode', 'dark');
} else if (newTheme === 'light') {
    document.body.classList.remove('dark-mode', 'dark');
} else { // auto
    // Check system preference
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.body.classList.add('dark-mode', 'dark');
    } else {
        document.body.classList.remove('dark-mode', 'dark');
    }
}
<?php endif; ?>

// Generate QR Code for 2FA if needed
<?php if ($showQRCode): ?>
document.addEventListener('DOMContentLoaded', function() {
    const qrcodeElement = document.getElementById('qrcode');
    if (qrcodeElement) {
        qrcodeElement.innerHTML = '';
        new QRCode(qrcodeElement, {
            text: <?php echo json_encode($qrCodeUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>,
            width: 200,
            height: 200,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
