<?php
/**
 * Onboarding Page
 *
 * Mandatory first-login workflow:
 *  Step 1  – 2FA question: Yes → step 1b, No → step 2
 *  Step 1b – Inline 2FA QR-code setup
 *  Step 2  – Required profile fields form
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../includes/models/User.php';

// Must be authenticated
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Already onboarded – send to dashboard
if (!empty($_SESSION['is_onboarded'])) {
    $dashboardUrl = (defined('BASE_URL') && BASE_URL) ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php';
    header('Location: ' . $dashboardUrl);
    exit;
}

$user = Auth::user();
$csrfToken = CSRFHandler::getToken();
$baseUrl = defined('BASE_URL') ? BASE_URL : '';

// Determine current step
$step = $_GET['step'] ?? '1';

$message = '';
$error   = '';
$showQRCode = false;
$qrCodeUrl  = '';
$secret     = '';

// Handle POST actions for the 2FA inline setup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    if ($postAction === 'start_2fa') {
        // Generate new 2FA secret and QR code
        $ga = new PHPGangsta_GoogleAuthenticator();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
        $showQRCode = true;
        $step = '1b';

    } elseif ($postAction === 'confirm_2fa') {
        $secret = $_POST['secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');

        $ga = new PHPGangsta_GoogleAuthenticator();
        if ($ga->verifyCode($secret, $code, 2)) {
            if (User::enable2FA($user['id'], $secret)) {
                $message = '2FA erfolgreich aktiviert! Weiter zum nächsten Schritt.';
                // Proceed to step 2
                header('Location: ?step=2&tfa_done=1');
                exit;
            } else {
                $error = 'Fehler beim Aktivieren von 2FA. Bitte versuche es erneut.';
                $showQRCode = true;
                $ga = new PHPGangsta_GoogleAuthenticator();
                $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
            }
        } else {
            $error = 'Ungültiger Code. Bitte versuche es erneut.';
            $showQRCode = true;
            $ga = new PHPGangsta_GoogleAuthenticator();
            $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
        }
        $step = '1b';
    }
}

// Map step values
if ($showQRCode) {
    $step = '1b';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Willkommen – IBC Intranet</title>
    <link rel="icon" type="image/webp" href="<?php echo asset('assets/img/flaticon.webp'); ?>">
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a237e 0%, #0d47a1 50%, #1565c0 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .card {
            max-width: 28rem;
            width: 100%;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #1a237e 0%, #1565c0 100%);
            padding: 36px 36px 28px;
            text-align: center;
            color: #fff;
        }

        .card-header .icon {
            width: 72px; height: 72px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 34px;
        }

        .card-header h1 { font-size: 24px; margin-bottom: 6px; }
        .card-header p  { font-size: 14px; opacity: 0.9; }

        .card-body { padding: 32px; }

        .step-indicator {
            display: flex; align-items: center; justify-content: center;
            gap: 8px; margin-bottom: 26px;
        }
        .step-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: #d1d5db; transition: background 0.3s;
        }
        .step-dot.active { background: #1565c0; }

        .question { font-size: 17px; font-weight: 600; color: #1a237e; margin-bottom: 8px; }
        .sub-text  { font-size: 14px; color: #6b7280; margin-bottom: 24px; line-height: 1.6; }

        .btn-row   { display: flex; gap: 12px; }

        .btn {
            flex: 1; padding: 12px 18px; border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600; cursor: pointer;
            transition: all 0.25s; text-decoration: none; text-align: center; display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1a237e 0%, #1565c0 100%);
            color: #fff;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(21,101,192,0.35); color: #fff; }
        .btn-secondary {
            background: #f3f4f6; color: #374151;
            border: 1.5px solid #d1d5db;
        }
        .btn-secondary:hover { background: #e5e7eb; color: #374151; }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #374151; font-size: 14px; }
        label .required { color: #ef4444; margin-left: 3px; }

        input[type="text"], input[type="tel"], input[type="date"] {
            width: 100%;
            padding: 11px 13px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.25s;
        }
        input:focus { outline: none; border-color: #1565c0; box-shadow: 0 0 0 3px rgba(21,101,192,0.12); }

        .alert {
            padding: 13px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; line-height: 1.5;
        }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }

        .qr-wrapper { text-align: center; margin-bottom: 20px; }
        .qr-wrapper img { max-width: 200px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 8px; }

        .code-input {
            font-family: monospace; letter-spacing: 4px; text-align: center; font-size: 22px;
        }
    </style>
</head>
<body>

<div class="card">
    <!-- Header -->
    <div class="card-header">
        <div class="icon">
            <?php if ($step === '1'): ?>
                <i class="fas fa-door-open" style="color:#fff;"></i>
            <?php elseif ($step === '1b'): ?>
                <i class="fas fa-shield-alt" style="color:#fff;"></i>
            <?php else: ?>
                <i class="fas fa-user-edit" style="color:#fff;"></i>
            <?php endif; ?>
        </div>
        <h1>
            <?php if ($step === '1'): ?>
                Herzlich willkommen! 👋
            <?php elseif ($step === '1b'): ?>
                2FA einrichten
            <?php else: ?>
                Dein Profil einrichten
            <?php endif; ?>
        </h1>
        <p>
            <?php if ($step === '1'): ?>
                Schön, dass du dabei bist. Lass uns kurz dein Konto einrichten.
            <?php elseif ($step === '1b'): ?>
                Scanne den QR-Code mit deiner Authenticator-App.
            <?php else: ?>
                Bitte füll die Pflichtfelder aus, um das Intranet zu nutzen.
            <?php endif; ?>
        </p>
    </div>

    <!-- Body -->
    <div class="card-body">

        <!-- Step indicator: dots for step 1/1b and step 2 -->
        <div class="step-indicator">
            <div class="step-dot <?php echo in_array($step, ['1','1b']) ? 'active' : ''; ?>"></div>
            <div class="step-dot <?php echo $step === '2' ? 'active' : ''; ?>"></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($step === '1'): ?>
            <!-- Step 1: 2FA question -->
            <p class="question">Möchtest du 2FA für zusätzliche Sicherheit aktivieren?</p>
            <p class="sub-text">
                Die Zwei-Faktor-Authentifizierung schützt dein Konto zusätzlich zum Passwort.
                Du benötigst eine Authenticator-App auf deinem Smartphone (z.&nbsp;B. Google Authenticator oder Authy).
            </p>

            <div class="btn-row">
                <!-- Yes: POST to self to generate QR code -->
                <form method="POST" style="flex:1;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="start_2fa">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-shield-alt me-1"></i> Ja, aktivieren
                    </button>
                </form>
                <!-- No: go straight to profile step -->
                <a href="?step=2" class="btn btn-secondary">
                    Nein, überspringen
                </a>
            </div>

        <?php elseif ($step === '1b'): ?>
            <!-- Step 1b: QR code + code confirmation -->
            <?php if ($showQRCode && $qrCodeUrl): ?>
                <div class="qr-wrapper">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo urlencode($qrCodeUrl); ?>&size=200x200"
                         alt="2FA QR-Code">
                </div>
                <p class="sub-text" style="text-align:center;">
                    Scanne den Code mit deiner App und gib den 6-stelligen Code unten ein.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="confirm_2fa">
                    <input type="hidden" name="secret" value="<?php echo htmlspecialchars($secret); ?>">
                    <div class="form-group">
                        <label for="code">Bestätigungscode</label>
                        <input type="text" id="code" name="code" class="code-input"
                               maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-1"></i> Code bestätigen
                        </button>
                        <a href="?step=2" class="btn btn-secondary">Überspringen</a>
                    </div>
                </form>
            <?php else: ?>
                <!-- Fallback: should not happen, redirect back to step 1 -->
                <script>window.location.href = '?step=1';</script>
            <?php endif; ?>

        <?php else: ?>
            <!-- Step 2: Required profile fields -->
            <div id="error-message" class="alert alert-error" style="display:none;"></div>
            <div id="success-message" class="alert alert-success" style="display:none;"></div>

            <?php if (!empty($_GET['tfa_done'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-1"></i>
                    2FA wurde erfolgreich aktiviert!
                </div>
            <?php endif; ?>

            <form id="profile-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">Vorname<span class="required">*</span></label>
                        <input type="text" id="first_name" name="first_name"
                               value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                               placeholder="Max" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Nachname<span class="required">*</span></label>
                        <input type="text" id="last_name" name="last_name"
                               value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                               placeholder="Mustermann" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="mobile_phone">Mobiltelefon<span class="required">*</span></label>
                    <input type="tel" id="mobile_phone" name="mobile_phone"
                           placeholder="+49 123 456789" required>
                </div>

                <div class="form-group">
                    <label for="birthday">Geburtsdatum<span class="required">*</span></label>
                    <input type="date" id="birthday" name="birthday"
                           max="<?php echo date('Y-m-d', strtotime('-16 years')); ?>" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;" id="submit-btn">
                    <i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen
                </button>
            </form>
        <?php endif; ?>

    </div><!-- /.card-body -->
</div><!-- /.card -->

<?php if ($step === '2'): ?>
<script>
document.getElementById('profile-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn    = document.getElementById('submit-btn');
    const errBox = document.getElementById('error-message');
    const okBox  = document.getElementById('success-message');

    errBox.style.display = 'none';
    okBox.style.display  = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Wird gespeichert\u2026';

    const data = {
        csrf_token:   document.querySelector('[name="csrf_token"]').value,
        first_name:   document.getElementById('first_name').value.trim(),
        last_name:    document.getElementById('last_name').value.trim(),
        mobile_phone: document.getElementById('mobile_phone').value.trim(),
        birthday:     document.getElementById('birthday').value,
    };

    if (!data.first_name) {
        errBox.textContent = 'Vorname ist erforderlich.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
        return;
    }
    if (!data.last_name) {
        errBox.textContent = 'Nachname ist erforderlich.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
        return;
    }
    if (!data.mobile_phone) {
        errBox.textContent = 'Mobiltelefon ist ein Pflichtfeld.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
        return;
    }
    if (!data.birthday) {
        errBox.textContent = 'Geburtsdatum ist ein Pflichtfeld.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
        return;
    }

    try {
        const resp = await fetch('<?php echo $baseUrl; ?>/api/complete_onboarding.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const json = await resp.json();

        if (json.success) {
            okBox.textContent   = 'Profil gespeichert! Du wirst weitergeleitet\u2026';
            okBox.style.display = 'block';
            setTimeout(() => {
                window.location.href = '<?php echo $baseUrl; ?>/pages/dashboard/index.php';
            }, 1200);
        } else {
            errBox.textContent   = json.message || 'Ein Fehler ist aufgetreten.';
            errBox.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
        }
    } catch (err) {
        errBox.textContent   = 'Netzwerkfehler. Bitte versuche es erneut.';
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Profil speichern &amp; loslegen';
    }
});
</script>
<?php endif; ?>

</body>
</html>
