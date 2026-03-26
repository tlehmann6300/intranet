<?php
/**
 * Two-Factor Authentication Verification Page
 * 
 * This page is shown after successful Microsoft login when 2FA is enabled.
 * Users must enter their 2FA code to complete authentication.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/User.php';

// Start session
AuthHandler::startSession();

// Check if user has a pending 2FA verification
if (!isset($_SESSION['pending_2fa_user_id'])) {
    // No pending 2FA, redirect to login
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$csrfToken = CSRFHandler::getToken();
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

// Check if 2FA is temporarily locked due to too many failed attempts (on page load)
try {
    $db = Database::getUserDB();
    $stmt = $db->prepare("SELECT tfa_locked_until FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['pending_2fa_user_id']]);
    $lockRow = $stmt->fetch();
    if ($lockRow && !empty($lockRow['tfa_locked_until']) && strtotime($lockRow['tfa_locked_until']) > time()) {
        $remainingMinutes = ceil((strtotime($lockRow['tfa_locked_until']) - time()) / 60);
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_role'],
              $_SESSION['pending_2fa_profile_complete'], $_SESSION['pending_2fa_is_onboarded']);
        $loginUrl = (defined('BASE_URL') && BASE_URL)
            ? BASE_URL . '/pages/auth/login.php?error=' . urlencode("Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte warten Sie noch {$remainingMinutes} Minute(n).")
            : '/pages/auth/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
} catch (PDOException $e) {
    error_log('DB error checking 2FA lockout for user ' . $_SESSION['pending_2fa_user_id'] . ': ' . $e->getMessage());
}

// Handle 2FA verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    $code = $_POST['code'] ?? '';
    
    if (empty($code)) {
        $error = 'Bitte geben Sie den 2FA-Code ein.';
    } else {
        // Get user from pending session
        $userId = $_SESSION['pending_2fa_user_id'];
        
        // Fetch user's 2FA secret
        $db = Database::getUserDB();
        $stmt = $db->prepare("SELECT tfa_secret FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $error = 'Benutzer nicht gefunden.';
        } else {
            $tfaSecret = $user['tfa_secret'] ?? null;
            
            if (empty($tfaSecret)) {
                $error = '2FA ist nicht korrekt konfiguriert. Bitte kontaktieren Sie den Administrator.';
            } else {
                // Re-check brute-force lockout before verifying code
                $tfaLocked = false;
                try {
                    $stmt = $db->prepare("SELECT tfa_failed_attempts, tfa_locked_until FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $lockStatus = $stmt->fetch();
                    if ($lockStatus && !empty($lockStatus['tfa_locked_until']) && strtotime($lockStatus['tfa_locked_until']) > time()) {
                        $remainingMinutes = ceil((strtotime($lockStatus['tfa_locked_until']) - time()) / 60);
                        $error = "Zu viele fehlgeschlagene Versuche. Bitte warten Sie noch {$remainingMinutes} Minute(n).";
                        $tfaLocked = true;
                    }
                } catch (PDOException $e) {
                    error_log('DB error checking 2FA lockout for user ' . $userId . ': ' . $e->getMessage());
                }

                if (!$tfaLocked) {
                    // Verify 2FA code
                    $ga = new PHPGangsta_GoogleAuthenticator();
                    
                    if ($ga->verifyCode($tfaSecret, $code, 2)) {
                        // 2FA verified successfully - reset brute-force counters
                        try {
                            $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = 0, tfa_locked_until = NULL WHERE id = ?");
                            $stmt->execute([$userId]);
                        } catch (PDOException $e) {
                            error_log('DB error resetting 2FA counters for user ' . $userId . ': ' . $e->getMessage());
                        }

                        // Regenerate session ID to prevent session fixation attacks
                        // Must be called before setting privileged session variables
                        session_regenerate_id(true);

                        // Set session variables from pending data
                        $_SESSION['user_id'] = $_SESSION['pending_2fa_user_id'];
                        $_SESSION['user_email'] = $_SESSION['pending_2fa_email'];
                        $_SESSION['user_role'] = $_SESSION['pending_2fa_role'];
                        $_SESSION['authenticated'] = true;
                        $_SESSION['last_activity'] = time();
                        
                        // Set profile_incomplete flag from pending session data
                        $_SESSION['profile_incomplete'] = (intval($_SESSION['pending_2fa_profile_complete'] ?? 1) === 0);
                        // Set is_onboarded flag from pending session data
                        $_SESSION['is_onboarded'] = (bool)($_SESSION['pending_2fa_is_onboarded'] ?? false);

                        // Restore role notice flag if set during Microsoft login
                        if (!empty($_SESSION['pending_2fa_show_role_notice'])) {
                            $_SESSION['show_role_notice'] = true;
                        }

                        // Clear pending 2FA data
                        unset($_SESSION['pending_2fa_user_id']);
                        unset($_SESSION['pending_2fa_email']);
                        unset($_SESSION['pending_2fa_role']);
                        unset($_SESSION['pending_2fa_profile_complete']);
                        unset($_SESSION['pending_2fa_is_onboarded']);
                        unset($_SESSION['pending_2fa_show_role_notice']);

                        // Update last login
                        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $stmt->execute([$userId]);
                        // Generate a cryptographically random session token for single-session enforcement
                        $sessionToken = bin2hex(random_bytes(32));
                        // Store session token in database (invalidates all other active sessions for this user)
                        $stmt = $db->prepare("UPDATE users SET current_session_id = ?, session_token = ? WHERE id = ?");
                        $stmt->execute([session_id(), $sessionToken, $userId]);
                        // Store session token in session for subsequent verification
                        $_SESSION['session_token'] = $sessionToken;
                        
                        // Log successful 2FA verification
                        AuthHandler::logSystemAction($userId, 'login_2fa_success', 'user', $userId, '2FA verification successful');
                        
                        // Redirect to dashboard
                        $dashboardUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php';
                        header('Location: ' . $dashboardUrl);
                        exit;
                    } else {
                        // Failed 2FA attempt - track for brute-force protection (DB only)
                        try {
                            $stmt = $db->prepare("SELECT tfa_failed_attempts FROM users WHERE id = ?");
                            $stmt->execute([$userId]);
                            $r = $stmt->fetch();
                            $newAttempts = ($r['tfa_failed_attempts'] ?? 0) + 1;

                            if ($newAttempts >= 5) {
                                $lockedUntil = date('Y-m-d H:i:s', time() + 900);
                                $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ?, tfa_locked_until = ? WHERE id = ?");
                                $stmt->execute([$newAttempts, $lockedUntil, $userId]);
                                AuthHandler::logSystemAction($userId, 'login_2fa_locked', 'user', $userId, '2FA account locked after ' . $newAttempts . ' failed attempts');
                                // Clear pending session and force re-login
                                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_role'],
                                      $_SESSION['pending_2fa_profile_complete'], $_SESSION['pending_2fa_is_onboarded']);
                                $loginUrl = (defined('BASE_URL') && BASE_URL)
                                    ? BASE_URL . '/pages/auth/login.php?error=' . urlencode('Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte warten Sie 15 Minuten.')
                                    : '/pages/auth/login.php';
                                header('Location: ' . $loginUrl);
                                exit;
                            } else {
                                $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ? WHERE id = ?");
                                $stmt->execute([$newAttempts, $userId]);
                            }
                        } catch (PDOException $e) {
                            error_log('DB error tracking 2FA failed attempt for user ' . $userId . ': ' . $e->getMessage());
                            $newAttempts = 1; // cannot determine exact count; show generic message
                        }

                        $remainingAttempts = max(0, 5 - $newAttempts);
                        $error = $remainingAttempts > 0
                            ? "Ungültiger 2FA-Code. Noch {$remainingAttempts} Versuch(e) verbleibend."
                            : 'Ungültiger 2FA-Code. Bitte versuchen Sie es erneut.';
                        
                        // Log failed 2FA attempt
                        AuthHandler::logSystemAction($userId, 'login_2fa_failed', 'user', $userId, 'Invalid 2FA code entered');
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verifizierung - IBC Intranet</title>
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            max-width: 450px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
        }

        .icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .content {
            padding: 40px 30px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            font-family: monospace;
            letter-spacing: 3px;
            text-align: center;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .help-text {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }

        .help-text a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .help-text a:hover {
            text-decoration: underline;
        }

        .support-section {
            margin-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .support-toggle {
            display: block;
            width: 100%;
            padding: 14px 0;
            background: none;
            border: none;
            text-align: center;
            color: #667eea;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .support-toggle:hover { text-decoration: underline; }

        .support-form-wrapper {
            display: none;
            padding: 16px 0 8px;
        }

        .support-form-wrapper.open { display: block; }

        .support-form-wrapper label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            text-align: left;
        }

        .support-form-wrapper input[type="text"],
        .support-form-wrapper textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.25s;
            font-family: inherit;
            margin-bottom: 14px;
        }

        .support-form-wrapper input[type="text"]:focus,
        .support-form-wrapper textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-support {
            width: 100%;
            padding: 12px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-support:hover { background: #5a6fd6; }
        .btn-support:disabled { opacity: 0.7; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">🔐</div>
            <h1>Zwei-Faktor-Authentifizierung</h1>
            <p>Geben Sie den 6-stelligen Code aus Ihrer Authenticator-App ein</p>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="code">Authentifizierungscode</label>
                    <input 
                        type="text" 
                        id="code" 
                        name="code" 
                        maxlength="6" 
                        pattern="[0-9]{6}" 
                        placeholder="000000" 
                        required 
                        autofocus
                        autocomplete="off"
                    >
                </div>
                
                <button type="submit" name="verify_2fa" class="btn">
                    Verifizieren
                </button>
            </form>
            
            <div class="help-text">
                <a href="logout.php">Zurück zum Login</a>
            </div>

            <!-- Support Section -->
            <div class="support-section">
                <button type="button" class="support-toggle" onclick="toggleSupport()" aria-expanded="false" aria-controls="support-form-wrapper">
                    🆘 Probleme? Support kontaktieren
                </button>
                <div class="support-form-wrapper" id="support-form-wrapper">
                    <div id="support-feedback" class="alert" style="display:none;margin-bottom:12px;"></div>
                    <form id="support-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <label for="support-name">Dein Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="support-name" name="name" placeholder="Vor- und Nachname" required aria-required="true" maxlength="200">
                        <label for="support-description">Beschreibe dein Problem <span style="color:#ef4444;">*</span></label>
                        <textarea id="support-description" name="description" rows="3" placeholder="Z. B. Ich habe mein Smartphone verloren und kann nicht mehr auf meine Authenticator-App zugreifen." required aria-required="true" style="resize:vertical;"></textarea>
                        <button type="submit" class="btn-support" id="support-submit-btn">
                            Anfrage senden
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-submit form when 6 digits are entered
        document.getElementById('code').addEventListener('input', function(e) {
            // Only allow numbers
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Auto-submit when 6 digits are entered
            if (this.value.length === 6) {
                // Small delay for better UX
                setTimeout(() => {
                    this.form.submit();
                }, 300);
            }
        });

        function toggleSupport() {
            var wrapper = document.getElementById('support-form-wrapper');
            var btn = document.querySelector('.support-toggle');
            var isOpen = wrapper.classList.toggle('open');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        document.getElementById('support-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('support-submit-btn');
            var feedback = document.getElementById('support-feedback');

            btn.disabled = true;
            btn.textContent = 'Wird gesendet…';
            feedback.style.display = 'none';

            var formData = new FormData(this);

            try {
                var resp = await fetch('<?php echo $baseUrl; ?>/api/submit_2fa_support.php', {
                    method: 'POST',
                    body: formData
                });
                var json = await resp.json();

                feedback.style.display = 'block';
                if (json.success) {
                    feedback.className = 'alert alert-success';
                    feedback.textContent = json.message;
                    document.getElementById('support-form').style.display = 'none';
                } else {
                    feedback.className = 'alert alert-error';
                    feedback.textContent = json.message || 'Ein Fehler ist aufgetreten.';
                    btn.disabled = false;
                    btn.textContent = 'Anfrage senden';
                }
            } catch (err) {
                feedback.style.display = 'block';
                feedback.className = 'alert alert-error';
                feedback.textContent = 'Netzwerkfehler. Bitte versuche es erneut.';
                btn.disabled = false;
                btn.textContent = 'Anfrage senden';
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmLWYePkbKfTkJXaJ4pYoEnJaSRh" crossorigin="anonymous"></script>
</body>
</html>
