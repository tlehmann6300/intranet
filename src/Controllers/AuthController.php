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

    public function login(array $vars = []): void
    {
        if (\Auth::check()) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $error = isset($_GET['error']) ? urldecode($_GET['error']) : '';

        $this->render('auth/login.twig', [
            'error' => $error,
        ]);
    }

    public function logout(array $vars = []): void
    {
        \Auth::logout();
        $this->redirect(\BASE_URL . '/login?logout=1');
    }

    public function verify2fa(array $vars = []): void
    {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            $this->redirect(\BASE_URL . '/login');
        }

        $error   = '';
        $success = '';
        $csrfToken = \CSRFHandler::getToken();

        // Check if 2FA is temporarily locked
        try {
            $db   = \Database::getUserDB();
            $stmt = $db->prepare("SELECT tfa_locked_until FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['pending_2fa_user_id']]);
            $lockRow = $stmt->fetch();
            if ($lockRow && !empty($lockRow['tfa_locked_until']) && strtotime($lockRow['tfa_locked_until']) > time()) {
                $remainingMinutes = ceil((strtotime($lockRow['tfa_locked_until']) - time()) / 60);
                unset(
                    $_SESSION['pending_2fa_user_id'],
                    $_SESSION['pending_2fa_email'],
                    $_SESSION['pending_2fa_role'],
                    $_SESSION['pending_2fa_profile_complete'],
                    $_SESSION['pending_2fa_is_onboarded']
                );
                $this->redirect(\BASE_URL . '/login?error=' . urlencode("Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte warten Sie noch {$remainingMinutes} Minute(n)."));
            }
        } catch (\PDOException $e) {
            error_log('DB error checking 2FA lockout: ' . $e->getMessage());
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
            $code = $_POST['code'] ?? '';

            if (empty($code)) {
                $error = 'Bitte geben Sie den 2FA-Code ein.';
            } else {
                $userId = $_SESSION['pending_2fa_user_id'];
                $db     = \Database::getUserDB();
                $stmt   = $db->prepare("SELECT tfa_secret FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Benutzer nicht gefunden.';
                } else {
                    $tfaSecret = $user['tfa_secret'] ?? null;

                    if (empty($tfaSecret)) {
                        $error = '2FA ist nicht korrekt konfiguriert. Bitte kontaktieren Sie den Administrator.';
                    } else {
                        $tfaLocked = false;
                        try {
                            $stmt = $db->prepare("SELECT tfa_failed_attempts, tfa_locked_until FROM users WHERE id = ?");
                            $stmt->execute([$userId]);
                            $lockStatus = $stmt->fetch();
                            if ($lockStatus && !empty($lockStatus['tfa_locked_until']) && strtotime($lockStatus['tfa_locked_until']) > time()) {
                                $remainingMinutes = ceil((strtotime($lockStatus['tfa_locked_until']) - time()) / 60);
                                $error     = "Zu viele fehlgeschlagene Versuche. Bitte warten Sie noch {$remainingMinutes} Minute(n).";
                                $tfaLocked = true;
                            }
                        } catch (\PDOException $e) {
                            error_log('DB error checking 2FA lockout: ' . $e->getMessage());
                        }

                        if (!$tfaLocked) {
                            $ga = new \PHPGangsta_GoogleAuthenticator();

                            if ($ga->verifyCode($tfaSecret, $code, 2)) {
                                try {
                                    $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = 0, tfa_locked_until = NULL WHERE id = ?");
                                    $stmt->execute([$userId]);
                                } catch (\PDOException $e) {
                                    error_log('DB error resetting 2FA counters: ' . $e->getMessage());
                                }

                                session_regenerate_id(true);

                                $_SESSION['user_id']       = $_SESSION['pending_2fa_user_id'];
                                $_SESSION['user_email']    = $_SESSION['pending_2fa_email'];
                                $_SESSION['user_role']     = $_SESSION['pending_2fa_role'];
                                $_SESSION['authenticated'] = true;
                                $_SESSION['last_activity'] = time();
                                $_SESSION['profile_incomplete'] = (intval($_SESSION['pending_2fa_profile_complete'] ?? 1) === 0);
                                $_SESSION['is_onboarded']  = (bool)($_SESSION['pending_2fa_is_onboarded'] ?? false);

                                if (!empty($_SESSION['pending_2fa_show_role_notice'])) {
                                    $_SESSION['show_role_notice'] = true;
                                }

                                unset(
                                    $_SESSION['pending_2fa_user_id'],
                                    $_SESSION['pending_2fa_email'],
                                    $_SESSION['pending_2fa_role'],
                                    $_SESSION['pending_2fa_profile_complete'],
                                    $_SESSION['pending_2fa_is_onboarded'],
                                    $_SESSION['pending_2fa_show_role_notice']
                                );

                                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                                $stmt->execute([$userId]);
                                $sessionToken = bin2hex(random_bytes(32));
                                $stmt = $db->prepare("UPDATE users SET current_session_id = ?, session_token = ? WHERE id = ?");
                                $stmt->execute([session_id(), $sessionToken, $userId]);
                                $_SESSION['session_token'] = $sessionToken;

                                \AuthHandler::logSystemAction($userId, 'login_2fa_success', 'user', $userId, '2FA verification successful');

                                $this->redirect(\BASE_URL . '/dashboard');
                            } else {
                                try {
                                    $stmt = $db->prepare("SELECT tfa_failed_attempts FROM users WHERE id = ?");
                                    $stmt->execute([$userId]);
                                    $r           = $stmt->fetch();
                                    $newAttempts = ($r['tfa_failed_attempts'] ?? 0) + 1;

                                    if ($newAttempts >= 5) {
                                        $lockedUntil = date('Y-m-d H:i:s', time() + 900);
                                        $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ?, tfa_locked_until = ? WHERE id = ?");
                                        $stmt->execute([$newAttempts, $lockedUntil, $userId]);
                                        \AuthHandler::logSystemAction($userId, 'login_2fa_locked', 'user', $userId, '2FA account locked after ' . $newAttempts . ' failed attempts');
                                        unset(
                                            $_SESSION['pending_2fa_user_id'],
                                            $_SESSION['pending_2fa_email'],
                                            $_SESSION['pending_2fa_role'],
                                            $_SESSION['pending_2fa_profile_complete'],
                                            $_SESSION['pending_2fa_is_onboarded']
                                        );
                                        $this->redirect(\BASE_URL . '/login?error=' . urlencode('Konto gesperrt. Zu viele fehlgeschlagene 2FA-Versuche. Bitte warten Sie 15 Minuten.'));
                                    } else {
                                        $stmt = $db->prepare("UPDATE users SET tfa_failed_attempts = ? WHERE id = ?");
                                        $stmt->execute([$newAttempts, $userId]);
                                    }
                                } catch (\PDOException $e) {
                                    error_log('DB error tracking 2FA failed attempt: ' . $e->getMessage());
                                    $newAttempts = 1;
                                }

                                $remainingAttempts = max(0, 5 - ($newAttempts ?? 1));
                                $error = $remainingAttempts > 0
                                    ? "Ungültiger 2FA-Code. Noch {$remainingAttempts} Versuch(e) verbleibend."
                                    : 'Ungültiger 2FA-Code. Bitte versuchen Sie es erneut.';

                                \AuthHandler::logSystemAction($userId, 'login_2fa_failed', 'user', $userId, 'Invalid 2FA code entered');
                            }
                        }
                    }
                }
            }
        }

        $this->render('auth/verify_2fa.twig', [
            'error'     => $error,
            'success'   => $success,
            'csrfToken' => $csrfToken,
        ]);
    }

    public function onboarding(array $vars = []): void
    {
        if (!\Auth::check()) {
            $this->redirect(\BASE_URL . '/login');
        }

        if (!empty($_SESSION['is_onboarded'])) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $user      = \Auth::user();
        $csrfToken = \CSRFHandler::getToken();
        $step      = $_GET['step'] ?? '1';
        $message   = '';
        $error     = '';
        $showQRCode = false;
        $qrCodeUrl  = '';
        $secret     = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $postAction = $_POST['action'] ?? '';
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            if ($postAction === 'start_2fa') {
                $ga        = new \PHPGangsta_GoogleAuthenticator();
                $secret    = $ga->createSecret();
                $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
                $showQRCode = true;
                $step      = '1b';
            } elseif ($postAction === 'confirm_2fa') {
                $secret = $_POST['secret'] ?? '';
                $code   = trim($_POST['code'] ?? '');
                $ga     = new \PHPGangsta_GoogleAuthenticator();

                if ($ga->verifyCode($secret, $code, 2)) {
                    if (\User::enable2FA($user['id'], $secret)) {
                        $this->redirect(\BASE_URL . '/onboarding?step=2&tfa_done=1');
                    } else {
                        $error      = 'Fehler beim Aktivieren von 2FA. Bitte versuche es erneut.';
                        $showQRCode = true;
                        $ga         = new \PHPGangsta_GoogleAuthenticator();
                        $qrCodeUrl  = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
                    }
                } else {
                    $error      = 'Ungültiger Code. Bitte versuche es erneut.';
                    $showQRCode = true;
                    $ga         = new \PHPGangsta_GoogleAuthenticator();
                    $qrCodeUrl  = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
                }
                $step = '1b';
            }
        }

        if ($showQRCode) {
            $step = '1b';
        }

        $this->render('auth/onboarding.twig', [
            'user'       => $user,
            'csrfToken'  => $csrfToken,
            'step'       => $step,
            'message'    => $message,
            'error'      => $error,
            'showQRCode' => $showQRCode,
            'qrCodeUrl'  => $qrCodeUrl,
            'secret'     => $secret,
            'tfaDone'    => !empty($_GET['tfa_done']),
        ]);
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
}
