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

<div class="max-w-6xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8 flex items-center gap-4">
        <div class="w-12 h-12 rounded-2xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center shadow-sm">
            <i class="fas fa-cog text-purple-600 dark:text-purple-400 text-xl"></i>
        </div>
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Einstellungen</h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Verwalte deine persönlichen Einstellungen</p>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-start">
            <i class="fas fa-check-circle mt-0.5 mr-3"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-start">
            <i class="fas fa-exclamation-circle mt-0.5 mr-3"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Microsoft Notice -->
    <div class="mb-6 p-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl mr-4 mt-1"></i>
            <div>
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">
                    Zentral verwaltetes Profil
                </h3>
                <p class="text-blue-800 dark:text-blue-200">
                    Ihr Profil wird zentral über Microsoft verwaltet. Änderungen an E-Mail oder Rolle können über das untenstehende Formular beantragt werden.
                </p>
            </div>
        </div>
    </div>

    <!-- Current Profile (Read-Only) -->
    <div class="mb-6">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-user text-blue-600 mr-2"></i>
                Aktuelles Profil
            </h2>
            <div class="space-y-4">
                <div>
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">E-Mail-Adresse</label>
                    <input 
                        type="email" 
                        readonly
                        value="<?php echo htmlspecialchars($user['email']); ?>"
                        class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-300 rounded-lg cursor-not-allowed"
                    >
                </div>
                <div>
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rolle</label>
                    <input 
                        type="text" 
                        readonly
                        value="<?php echo htmlspecialchars(translateRole($user['role'])); ?>"
                        class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-300 rounded-lg cursor-not-allowed"
                    >
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-6">

        <!-- 2FA Settings -->
        <div class="lg:col-span-2">
            <div class="card rounded-xl shadow-sm overflow-hidden">
                <!-- Section header -->
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-green-50 dark:bg-green-900/10">
                    <div class="w-9 h-9 rounded-xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-shield-alt text-green-600 dark:text-green-400"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Sicherheit</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Zwei-Faktor-Authentifizierung (2FA)</p>
                    </div>
                </div>
                <div class="p-6">

                <?php if (!$showQRCode): ?>
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <p class="text-gray-700 dark:text-gray-300 mb-2">
                            Status: 
                            <?php if ($user['tfa_enabled']): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-semibold">
                                <i class="fas fa-check-circle mr-1"></i>Aktiviert
                            </span>
                            <?php else: ?>
                            <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-full font-semibold">
                                <i class="fas fa-times-circle mr-1"></i>Deaktiviert
                            </span>
                            <?php endif; ?>
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Schütze dein Konto mit einer zusätzlichen Sicherheitsebene
                        </p>
                    </div>
                    <div>
                        <?php if ($user['tfa_enabled']): ?>
                        <form method="POST" onsubmit="return confirm('Möchtest du 2FA wirklich deaktivieren?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="disable_2fa" class="w-full px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                <i class="fas fa-times mr-2"></i>2FA deaktivieren
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="enable_2fa" class="btn-primary">
                                <i class="fas fa-plus mr-2"></i>2FA aktivieren
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-400 dark:border-blue-500 p-4">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Empfehlung:</strong> Aktiviere 2FA für zusätzliche Sicherheit. Du benötigst eine Authenticator-App wie Google Authenticator oder Authy.
                    </p>
                </div>
                <?php else: ?>
                <!-- QR Code Setup -->
                <div class="max-w-md mx-auto">
                    <div class="text-center mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">2FA einrichten</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                            Scanne den QR-Code mit deiner Authenticator-App und gib den generierten Code ein
                        </p>
                        <div id="qrcode" class="mx-auto mb-4 inline-block"></div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            Geheimer Schlüssel (manuell): <code class="bg-gray-100 dark:bg-gray-700 dark:text-gray-300 px-2 py-1 rounded"><?php echo htmlspecialchars($secret); ?></code>
                        </p>
                    </div>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="secret" value="<?php echo htmlspecialchars($secret); ?>">
                        <div>
                            <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">6-stelliger Code</label>
                            <input 
                                type="text" 
                                name="code" 
                                required 
                                maxlength="6"
                                pattern="[0-9]{6}"
                                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg text-center text-2xl tracking-widest"
                                placeholder="000000"
                                autofocus
                            >
                        </div>
                        <div class="flex flex-col md:flex-row gap-3">
                            <a href="settings.php" class="flex-1 text-center px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                Abbrechen
                            </a>
                            <button type="submit" name="confirm_2fa" class="flex-1 btn-primary">
                                <i class="fas fa-check mr-2"></i>Bestätigen
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                </div><!-- /p-6 -->
            </div>
        </div>

        <!-- Privacy Settings -->
        <div class="lg:col-span-2">
            <div class="card rounded-xl shadow-sm overflow-hidden">
                <!-- Section header -->
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-red-50 dark:bg-red-900/10">
                    <div class="w-9 h-9 rounded-xl bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user-shield text-red-600 dark:text-red-400"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Datenschutz</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Sichtbarkeit deiner Profildaten für andere Mitglieder</p>
                    </div>
                </div>
                <div class="p-6">
                <p class="text-gray-600 dark:text-gray-300 mb-5 text-sm">
                    Lege fest, welche Informationen deines Profils für reguläre Mitglieder sichtbar sind.
                    Verborgen gestellte Daten sind weiterhin für Vorstände und Alumni sichtbar.
                </p>
                <form method="POST" class="space-y-4">
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
                    <div class="flex items-center justify-between p-4 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center text-red-600 dark:text-red-400 flex-shrink-0">
                                <i class="fas <?php echo $item['icon']; ?> text-sm"></i>
                            </div>
                            <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo $item['label']; ?></span>
                        </div>
                        <label class="inline-flex items-center cursor-pointer min-h-[44px]">
                            <input type="checkbox" name="<?php echo $item['key']; ?>" value="1" class="sr-only peer" <?php echo $isHidden ? 'checked' : ''; ?>>
                            <div class="relative w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-red-400 dark:bg-gray-600 rounded-full peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-500"></div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" name="update_privacy" class="w-full btn-primary">
                        <i class="fas fa-save mr-2"></i>Datenschutz-Einstellungen speichern
                    </button>
                </form>
                </div><!-- /p-6 -->
            </div>
        </div>

        <!-- Theme Settings -->
        <div class="lg:col-span-2">
            <div class="card rounded-xl shadow-sm overflow-hidden">
                <!-- Section header -->
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-purple-50 dark:bg-purple-900/10">
                    <div class="w-9 h-9 rounded-xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-palette text-purple-600 dark:text-purple-400"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Erscheinungsbild</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Design-Theme auswählen</p>
                    </div>
                </div>
                <div class="p-6">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 md:gap-4">
                        <!-- Light Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700'; ?>">
                            <input type="radio" name="theme" value="light" <?php echo ($user['theme_preference'] ?? 'auto') === 'light' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-sun text-4xl text-yellow-500 mb-2"></i>
                            <span class="font-semibold text-gray-800 dark:text-gray-100">Hellmodus</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400 text-center mt-1">Immer helles Design</span>
                        </label>

                        <!-- Dark Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700'; ?>">
                            <input type="radio" name="theme" value="dark" <?php echo ($user['theme_preference'] ?? 'auto') === 'dark' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-moon text-4xl text-indigo-500 mb-2"></i>
                            <span class="font-semibold text-gray-800 dark:text-gray-100">Dunkelmodus</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400 text-center mt-1">Immer dunkles Design</span>
                        </label>

                        <!-- Auto Theme -->
                        <label class="flex flex-col items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-purple-500 <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700'; ?>">
                            <input type="radio" name="theme" value="auto" <?php echo ($user['theme_preference'] ?? 'auto') === 'auto' ? 'checked' : ''; ?> class="sr-only">
                            <i class="fas fa-adjust text-4xl text-gray-500 dark:text-gray-400 mb-2"></i>
                            <span class="font-semibold text-gray-800 dark:text-gray-100">Automatisch</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400 text-center mt-1">Folgt Systemeinstellung</span>
                        </label>
                    </div>

                    <button type="submit" name="update_theme" class="w-full btn-primary">
                        <i class="fas fa-save mr-2"></i>Design-Einstellungen speichern
                    </button>
                </form>
                </div><!-- /p-6 -->
            </div>
        </div>

    </div>

    <!-- GDPR Data Export -->
    <div class="mt-6">
        <div class="card rounded-xl shadow-sm overflow-hidden">
            <!-- Section header -->
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-teal-50 dark:bg-teal-900/10">
                <div class="w-9 h-9 rounded-xl bg-teal-100 dark:bg-teal-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-file-export text-teal-600 dark:text-teal-400"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">DSGVO / Datenschutz</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Datenschutz-Grundverordnung – Deine Rechte</p>
                </div>
            </div>
            <div class="p-6">
            <p class="text-gray-600 dark:text-gray-300 mb-5 text-sm">
                Gemäß DSGVO Art. 20 kannst du alle zu deiner Person gespeicherten Daten als CSV-Datei herunterladen (Profil, Ausleihen, Event-Teilnahmen).
            </p>
            <form method="POST" action="<?php echo asset('api/export_user_data.php'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="w-full inline-flex items-center justify-center px-5 py-3 bg-teal-600 text-white rounded-xl hover:bg-teal-700 transition font-semibold text-sm">
                    <i class="fas fa-download mr-2"></i>Meine Daten anfordern / exportieren
                </button>
            </form>
            </div><!-- /p-6 -->
        </div>
    </div>

    <!-- Änderungsantrag Section -->
    <div class="mt-6" id="aenderungsantrag">
        <div class="card rounded-xl shadow-sm overflow-hidden">
            <!-- Section header -->
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-orange-50 dark:bg-orange-900/10">
                <div class="w-9 h-9 rounded-xl bg-orange-100 dark:bg-orange-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-file-alt text-orange-600 dark:text-orange-400"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Änderungsantrag</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Rollenänderung oder E-Mail-Adressenänderung beantragen</p>
                </div>
            </div>
            <div class="p-6">
            <p class="text-gray-600 dark:text-gray-300 mb-5 text-sm">
                Wenn deine Rolle nicht korrekt ist oder du eine andere E-Mail-Adresse hinterlegen möchtest, kannst du hier einen Änderungsantrag stellen. Der Antrag wird per E-Mail an den Vorstand weitergeleitet.
            </p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label for="change-request-type" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Art der Änderung</label>
                    <select id="change-request-type" name="request_type" required
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-orange-500 focus:border-orange-500">
                        <option value="">Bitte auswählen...</option>
                        <option value="Rollenänderung">Rollenänderung</option>
                        <option value="E-Mail-Adressenänderung">E-Mail-Adressenänderung</option>
                    </select>
                </div>
                <div>
                    <label for="change-request-reason" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Begründung / Neuer Wert</label>
                    <textarea id="change-request-reason" name="request_reason" rows="4" required
                              minlength="10" maxlength="1000"
                              placeholder="Beschreibe dein Anliegen ausführlich (z. B. welche Rolle du haben solltest und warum)..."
                              class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-orange-500 focus:border-orange-500 resize-none"></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mindestens 10 Zeichen, maximal 1000 Zeichen.</p>
                </div>
                <button type="submit" name="submit_change_request"
                        class="w-full inline-flex items-center justify-center px-5 py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-xl transition font-semibold text-sm">
                    <i class="fas fa-paper-plane mr-2"></i>Änderungsantrag senden
                </button>
            </form>
            </div><!-- /p-6 -->
        </div>
    </div>

    <!-- Support Section -->
    <div class="mt-6">
        <div class="card rounded-xl shadow-sm overflow-hidden">
            <!-- Section header -->
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center gap-3 bg-blue-50 dark:bg-blue-900/10">
                <div class="w-9 h-9 rounded-xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-life-ring text-blue-600 dark:text-blue-400"></i>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Hilfe &amp; Support</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Wende dich bei Fragen direkt an die IT-Ressortleitung</p>
                </div>
            </div>
            <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <button type="button" onclick="showSupportModal('2fa_reset')"
                   class="flex items-center p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-xl hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition text-left w-full">
                    <i class="fas fa-shield-alt text-yellow-600 dark:text-yellow-400 text-2xl mr-4 shrink-0"></i>
                    <div>
                        <span class="block font-semibold text-gray-800 dark:text-gray-100">2FA zurücksetzen</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">Anfrage per E-Mail senden</span>
                    </div>
                </button>
                <button type="button" onclick="showSupportModal('bug')"
                   class="flex items-center p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/40 transition text-left w-full">
                    <i class="fas fa-bug text-red-600 dark:text-red-400 text-2xl mr-4 shrink-0"></i>
                    <div>
                        <span class="block font-semibold text-gray-800 dark:text-gray-100">Bug melden</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">Fehler per E-Mail melden</span>
                    </div>
                </button>
            </div>
            </div><!-- /p-6 -->
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
                <div class="flex gap-3 justify-end">
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
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
