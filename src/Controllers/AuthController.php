<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class AuthController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    // Leitet den User direkt zum Microsoft Login weiter – es gibt keine Anmeldemaske mehr
    public function loginWithMicrosoft(array $vars = []): void
    {
        if (\Auth::check()) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        try {
            \AuthHandler::initiateMicrosoftLogin();
        } catch (\Exception $e) {
            error_log('Microsoft login initiation error: ' . $e->getMessage());
            $error = urlencode('Microsoft Login konnte nicht gestartet werden. Bitte kontaktieren Sie den Administrator.');
            $this->redirect(\BASE_URL . '/?error=' . $error);
        }
    }

    public function logout(array $vars = []): void
    {
        \Auth::logout();
        $this->redirect(\BASE_URL . '/');
    }

    public function profile(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';
        $message  = '';
        $error    = '';

        if (isset($_SESSION['success_message'])) {
            $message = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            $error = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
        }

        $profile = null;
        if (\isMemberRole($userRole)) {
            $profile = \Member::getProfileByUserId($user['id']);
        } elseif (\isAlumniRole($userRole)) {
            $profile = \Alumni::getProfileByUserId($user['id']);
        }

        if (!$profile) {
            $profile = [
                'first_name'          => $user['first_name'] ?? '',
                'last_name'           => $user['last_name'] ?? '',
                'email'               => $user['email'],
                'secondary_email'     => '',
                'mobile_phone'        => '',
                'linkedin_url'        => '',
                'xing_url'            => '',
                'about_me'            => $user['about_me'] ?? '',
                'image_path'          => '',
                'study_program'       => '',
                'semester'            => null,
                'angestrebter_abschluss' => '',
                'company'             => '',
                'industry'            => '',
                'position'            => '',
                'gender'              => $user['gender'] ?? '',
                'birthday'            => $user['birthday'] ?? '',
                'show_birthday'       => $user['show_birthday'] ?? 1,
                'cv_path'             => null,
            ];
        } else {
            $profile['gender']       = $user['gender'] ?? ($profile['gender'] ?? '');
            $profile['birthday']     = $user['birthday'] ?? ($profile['birthday'] ?? '');
            $profile['show_birthday'] = $user['show_birthday'] ?? ($profile['show_birthday'] ?? 0);
            $profile['about_me']     = $user['about_me'] ?? ($profile['about_me'] ?? '');
        }

        $jobBoardCvPath = null;
        $db     = \Database::getContentDB();
        $jbStmt = $db->prepare("SELECT pdf_path FROM job_board WHERE user_id = ? AND pdf_path IS NOT NULL ORDER BY created_at DESC LIMIT 1");
        $jbStmt->execute([(int)$user['id']]);
        $jbRow  = $jbStmt->fetch(\PDO::FETCH_ASSOC);
        if ($jbRow && !empty($jbRow['pdf_path'])) {
            $jobBoardCvPath = $jbRow['pdf_path'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
            try {
                \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
                $profileData = [
                    'first_name'     => trim($_POST['first_name'] ?? ''),
                    'last_name'      => trim($_POST['last_name'] ?? ''),
                    'email'          => trim($_POST['profile_email'] ?? ''),
                    'secondary_email' => trim($_POST['secondary_email'] ?? ''),
                    'mobile_phone'   => trim($_POST['mobile_phone'] ?? ''),
                    'linkedin_url'   => trim($_POST['linkedin_url'] ?? ''),
                    'xing_url'       => trim($_POST['xing_url'] ?? ''),
                    'about_me'       => mb_substr(trim($_POST['about_me'] ?? ''), 0, 400),
                    'skills'         => mb_substr(trim($_POST['skills'] ?? ''), 0, 500),
                    'image_path'     => $profile['image_path'] ?? '',
                    'cv_path'        => $profile['cv_path'] ?? null,
                    'gender'         => trim($_POST['gender'] ?? ''),
                    'birthday'       => trim($_POST['birthday'] ?? ''),
                    'show_birthday'  => isset($_POST['show_birthday']) ? 1 : 0,
                ];

                if (empty($profileData['first_name'])) {
                    throw new \Exception('Vorname ist erforderlich');
                }
                if (empty($profileData['last_name'])) {
                    throw new \Exception('Nachname ist erforderlich');
                }
                if (empty($profileData['email'])) {
                    throw new \Exception('E-Mail ist erforderlich');
                }
                if (!filter_var($profileData['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('E-Mail-Adresse ist ungültig');
                }
                if (empty($profileData['mobile_phone'])) {
                    throw new \Exception('Telefonnummer ist ein Pflichtfeld.');
                }
                if (empty($profileData['birthday'])) {
                    throw new \Exception('Das Geburtsdatum ist ein Pflichtfeld.');
                }
                if (strtotime($profileData['birthday']) > strtotime('-16 years')) {
                    throw new \Exception('Du musst mindestens 16 Jahre alt sein.');
                }

                if (!empty($_POST['profile_picture_data'])) {
                    $base64Data = $_POST['profile_picture_data'];
                    if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/s', $base64Data, $matches)) {
                        throw new \Exception('Ungültiges Bildformat.');
                    }
                    $imageData = base64_decode($matches[2]);
                    if ($imageData === false || strlen($imageData) === 0) {
                        throw new \Exception('Bildverarbeitung fehlgeschlagen.');
                    }
                    if (strlen($imageData) > 5242880) {
                        throw new \Exception('Bild ist zu groß. Maximum: 5MB');
                    }
                    $tmpFile = tempnam(sys_get_temp_dir(), 'avatar_');
                    file_put_contents($tmpFile, $imageData);
                    try {
                        $finfo      = finfo_open(FILEINFO_MIME_TYPE);
                        $actualMime = finfo_file($finfo, $tmpFile);
                        finfo_close($finfo);
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                        if (!in_array($actualMime, $allowedMimes)) {
                            throw new \Exception('Ungültiger Bildtyp');
                        }
                        $imageInfo = @getimagesize($tmpFile);
                        if ($imageInfo === false) {
                            throw new \Exception('Datei ist kein gültiges Bild');
                        }
                        $uploadDir = __DIR__ . '/../../uploads/profile/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                        $ext      = $extMap[$actualMime] ?? 'jpg';
                        $filename = 'item_' . bin2hex(random_bytes(16)) . '.' . $ext;
                        $uploadPath = $uploadDir . $filename;
                        if (!copy($tmpFile, $uploadPath)) {
                            throw new \Exception('Fehler beim Speichern des Profilbildes');
                        }
                        chmod($uploadPath, 0644);
                        if (!empty($profile['image_path'])) {
                            \SecureImageUpload::deleteImage($profile['image_path']);
                        }
                        $projectRoot      = realpath(__DIR__ . '/../../');
                        $realUploadPath   = realpath($uploadPath);
                        $relativePath     = str_replace('\\', '/', substr($realUploadPath, strlen($projectRoot) + 1));
                        $profileData['image_path'] = $relativePath;
                    } finally {
                        @unlink($tmpFile);
                    }
                }

                $userUpdateData = [];
                foreach (['gender', 'birthday', 'show_birthday', 'about_me'] as $field) {
                    if (isset($profileData[$field])) {
                        $userUpdateData[$field] = $profileData[$field];
                    }
                }
                if (isset($_POST['job_title'])) {
                    $userUpdateData['job_title'] = trim($_POST['job_title']);
                }
                if (isset($_POST['company'])) {
                    $userUpdateData['company'] = trim($_POST['company']);
                }
                if (!empty($userUpdateData)) {
                    \User::updateProfile($user['id'], $userUpdateData);
                }

                if (\isMemberRole($userRole)) {
                    \Member::updateProfile($user['id'], $profileData);
                } elseif (\isAlumniRole($userRole)) {
                    \Alumni::updateProfile($user['id'], $profileData);
                }

                $_SESSION['success_message'] = 'Profil erfolgreich gespeichert';
                $this->redirect(\BASE_URL . '/profile');
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        $this->render('auth/profile.twig', [
            'user'           => $user,
            'userRole'       => $userRole,
            'profile'        => $profile,
            'jobBoardCvPath' => $jobBoardCvPath,
            'message'        => $message,
            'error'          => $error,
            'csrfToken'      => \CSRFHandler::getToken(),
        ]);
    }

    public function settings(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $message  = '';
        $error    = '';
        $showQRCode = false;
        $secret   = '';
        $qrCodeUrl = '';

        if (isset($_SESSION['success_message'])) {
            $message = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
        }
        if (isset($_SESSION['error_message'])) {
            $error = $_SESSION['error_message'];
            unset($_SESSION['error_message']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            if (isset($_POST['update_privacy'])) {
                $privacyData = [
                    'privacy_hide_email'  => isset($_POST['privacy_hide_email'])  ? 1 : 0,
                    'privacy_hide_phone'  => isset($_POST['privacy_hide_phone'])  ? 1 : 0,
                    'privacy_hide_career' => isset($_POST['privacy_hide_career']) ? 1 : 0,
                ];
                if (\User::updateProfile($user['id'], $privacyData)) {
                    $message = 'Datenschutz-Einstellungen erfolgreich gespeichert';
                    $user    = \Auth::user();
                } else {
                    $error = 'Fehler beim Speichern der Datenschutz-Einstellungen';
                }
            } elseif (isset($_POST['update_theme'])) {
                $theme = $_POST['theme'] ?? 'auto';
                if (!in_array($theme, ['light', 'dark', 'auto'])) {
                    $theme = 'auto';
                }
                if (\User::updateThemePreference($user['id'], $theme)) {
                    $message = 'Design-Einstellungen erfolgreich gespeichert';
                    $user    = \Auth::user();
                } else {
                    $error = 'Fehler beim Speichern der Design-Einstellungen';
                }
            } elseif (isset($_POST['enable_2fa'])) {
                $ga        = new \PHPGangsta_GoogleAuthenticator();
                $secret    = $ga->createSecret();
                $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
                $showQRCode = true;
            } elseif (isset($_POST['confirm_2fa'])) {
                $secret = $_POST['secret'] ?? '';
                $code   = $_POST['code'] ?? '';
                $ga     = new \PHPGangsta_GoogleAuthenticator();
                if ($ga->verifyCode($secret, $code, 2)) {
                    if (\User::enable2FA($user['id'], $secret)) {
                        $message = '2FA erfolgreich aktiviert';
                        $user    = \Auth::user();
                    } else {
                        $error = 'Fehler beim Aktivieren von 2FA';
                    }
                } else {
                    $error      = 'Ungültiger Code. Bitte versuche es erneut.';
                    $secret     = $_POST['secret'];
                    $ga         = new \PHPGangsta_GoogleAuthenticator();
                    $qrCodeUrl  = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
                    $showQRCode = true;
                }
            } elseif (isset($_POST['disable_2fa'])) {
                if (\User::disable2FA($user['id'])) {
                    $message = '2FA erfolgreich deaktiviert';
                    $user    = \Auth::user();
                } else {
                    $error = 'Fehler beim Deaktivieren von 2FA';
                }
            } elseif (isset($_POST['submit_change_request'])) {
                try {
                    $requestType   = trim($_POST['request_type'] ?? '');
                    $requestReason = trim($_POST['request_reason'] ?? '');
                    $allowedTypes  = ['Rollenänderung', 'E-Mail-Adressenänderung'];
                    if (!in_array($requestType, $allowedTypes, true)) {
                        throw new \Exception('Ungültiger Änderungstyp.');
                    }
                    if (strlen($requestReason) < 10) {
                        throw new \Exception('Bitte geben Sie eine ausführlichere Begründung an (mindestens 10 Zeichen).');
                    }
                    if (strlen($requestReason) > 1000) {
                        throw new \Exception('Die Begründung ist zu lang (maximal 1000 Zeichen).');
                    }
                    $currentRole  = \translateRole($user['role'] ?? '');
                    $emailBody    = \MailService::getTemplate(
                        'Änderungsantrag',
                        '<p>Ein Benutzer hat einen Änderungsantrag gestellt:</p>
                        <table class="info-table">
                            <tr><td class="info-label">E-Mail:</td><td class="info-value">' . htmlspecialchars($user['email']) . '</td></tr>
                            <tr><td class="info-label">Aktuelle Rolle:</td><td class="info-value">' . htmlspecialchars($currentRole) . '</td></tr>
                            <tr><td class="info-label">Art der Änderung:</td><td class="info-value">' . htmlspecialchars($requestType) . '</td></tr>
                            <tr><td class="info-label">Begründung / Neuer Wert:</td><td class="info-value">' . nl2br(htmlspecialchars($requestReason)) . '</td></tr>
                        </table>'
                    );
                    $emailSent = \MailService::sendEmail(
                        \MAIL_IT_RESSORT,
                        'Änderungsantrag: ' . $requestType . ' von ' . $user['email'],
                        $emailBody
                    );
                    if ($emailSent) {
                        $message = 'Ihr Änderungsantrag wurde erfolgreich eingereicht!';
                    } else {
                        $error = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
                    }
                } catch (\Exception $e) {
                    error_log('Error submitting change request: ' . $e->getMessage());
                    $error = htmlspecialchars($e->getMessage());
                }
            }
        }

        $this->render('auth/settings.twig', [
            'user'       => $user,
            'message'    => $message,
            'error'      => $error,
            'showQRCode' => $showQRCode,
            'secret'     => $secret,
            'qrCodeUrl'  => $qrCodeUrl,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }
    /**
     * Handles the Microsoft Entra ID OAuth callback.
     * Replaces auth/callback.php.
     */
    public function microsoftCallback(array $vars = []): void
    {
        $stateGet     = $_GET['state'] ?? null;
        $stateSession = $_SESSION['oauth2state'] ?? null;
        $stateMatch   = ($stateGet !== null && $stateSession !== null && $stateGet === $stateSession);
        $tokenError   = null;
        $azureOid     = null;
        $userEmail    = null;

        try {
            if (isset($_GET['error'])) {
                throw new \Exception('OAuth-Fehler von Microsoft: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
            }

            if (! $stateMatch) {
                unset($_SESSION['oauth2state']);
                throw new \Exception('State mismatch: Session-State ' . ($stateSession ?? 'not set') . ' vs GET-State ' . ($stateGet ?? 'not set'));
            }
            unset($_SESSION['oauth2state']);

            if (! isset($_GET['code'])) {
                throw new \Exception('No authorization code received');
            }

            $clientId     = defined('CLIENT_ID') ? \CLIENT_ID : '';
            $clientSecret = defined('CLIENT_SECRET') ? \CLIENT_SECRET : '';
            $redirectUri  = defined('REDIRECT_URI') ? \REDIRECT_URI : '';
            $tenantId     = defined('TENANT_ID') ? \TENANT_ID : '';

            if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $tenantId === '') {
                throw new \Exception('Missing Azure OAuth configuration');
            }

            // Exchange authorization code for access token
            $tokenUrl  = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
            $postFields = http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $_GET['code'],
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]);

            try {
                $ch = curl_init($tokenUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $tokenResponse = curl_exec($ch);
                $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError     = curl_error($ch);
                curl_close($ch);

                if ($curlError !== '') {
                    throw new \Exception('Token exchange cURL error: ' . $curlError);
                }

                $tokenData = json_decode((string) $tokenResponse, true);
                if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
                    $errorMsg = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown token error (HTTP ' . $tokenHttpCode . ')';
                    error_log('[OAuth] getAccessToken() failed: ' . $errorMsg);
                    throw new \Exception('Token-Fehler: ' . htmlspecialchars($errorMsg));
                }
            } catch (\Exception $tokenEx) {
                $tokenError = $tokenEx->getMessage();
                error_log('[OAuth] getAccessToken() failed: ' . $tokenError);
                throw $tokenEx;
            }

            $accessTokenValue = $tokenData['access_token'];
            $idToken          = $tokenData['id_token'] ?? null;

            // Fetch user profile from Microsoft Graph
            try {
                $ch = curl_init('https://graph.microsoft.com/v1.0/me');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessTokenValue]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $profileResponse = curl_exec($ch);
                $profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $profileCurlErr  = curl_error($ch);
                curl_close($ch);

                if ($profileCurlErr !== '') {
                    throw new \Exception('Profile fetch cURL error: ' . $profileCurlErr);
                }
                if ($profileHttpCode !== 200) {
                    throw new \Exception('Graph API returned HTTP ' . $profileHttpCode);
                }

                $claims = json_decode((string) $profileResponse, true) ?: [];
            } catch (\Exception $roEx) {
                error_log('[OAuth] getResourceOwner() failed: ' . $roEx->getMessage());
                throw new \Exception('Benutzerdetails konnten nicht von Microsoft abgerufen werden: ' . $roEx->getMessage());
            }

            // Merge JWT id_token claims (roles, oid, preferred_username)
            if ($idToken) {
                $tokenParts = explode('.', (string) $idToken);
                if (count($tokenParts) === 3) {
                    $jwtPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $tokenParts[1]));
                    if ($jwtPayload !== false && $jwtPayload !== '') {
                        $jwtClaims = json_decode($jwtPayload, true);
                        if (is_array($jwtClaims)) {
                            $claims = array_merge($claims, $jwtClaims);
                        }
                    }
                }
            }

            $azureOid  = $claims['oid'] ?? $claims['sub'] ?? null;
            $userEmail = $claims['email'] ?? $claims['mail'] ?? $claims['userPrincipalName'] ?? null;
            error_log(sprintf('[OAuth] Claims received. azure_oid: %s | email: %s', $azureOid ?? 'null', $userEmail ?? 'null'));

            $db           = \Database::getUserDB();
            $existingUser = null;

            if ($azureOid) {
                $stmt = $db->prepare('SELECT * FROM users WHERE azure_oid = ?');
                $stmt->execute([$azureOid]);
                $existingUser = $stmt->fetch() ?: null;
            }

            if (! $existingUser && ! empty($userEmail)) {
                $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
                $stmt->execute([$userEmail]);
                $existingUser = $stmt->fetch() ?: null;

                if ($existingUser && $azureOid) {
                    $updateStmt = $db->prepare('UPDATE users SET azure_oid = ? WHERE id = ? AND (azure_oid IS NULL OR azure_oid != ?)');
                    $updateStmt->execute([$azureOid, $existingUser['id'], $azureOid]);
                    error_log(sprintf('[OAuth] Stored azure_oid %s for user id %d (matched via email)', $azureOid, $existingUser['id']));
                }
            }

            if ($existingUser && $azureOid) {
                \AuthHandler::syncEntraData($existingUser['id'], $claims, $azureOid, $accessTokenValue);
            }

            \AuthHandler::completeMicrosoftLogin($claims, $existingUser, $accessTokenValue);

        } catch (\Exception $e) {
            error_log(sprintf(
                '[OAuth Callback] Authentifizierung fehlgeschlagen: %s | State-GET: %s | State-SESSION: %s | States match: %s | Token-Fehler: %s | azure_oid: %s | E-Mail: %s',
                $e->getMessage(),
                $stateGet     !== null ? 'ja' : 'nein',
                $stateSession !== null ? 'ja' : 'nein',
                $stateMatch ? 'ja' : 'nein',
                $tokenError   ?? 'keiner',
                $azureOid     ?? 'n/a',
                $userEmail    ?? 'n/a'
            ));
            error_log('[OAuth Callback] Stack Trace: ' . $e->getTraceAsString());

            $error = urlencode('Authentifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.');
            $this->redirect(\BASE_URL . '/?error=' . $error);
        }
    }
}
