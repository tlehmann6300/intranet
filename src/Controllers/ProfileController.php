<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class ProfileController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function uploadAvatar(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        \CSRFHandler::verifyToken($body['csrf_token'] ?? '');

        $base64Data = $body['image'] ?? '';
        if (empty($base64Data)) {
            $this->json(['success' => false, 'message' => 'Kein Bild übermittelt']);
        }

        if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/s', $base64Data, $matches)) {
            $this->json(['success' => false, 'message' => 'Ungültiges Bildformat']);
        }

        $imageData = base64_decode($matches[2]);
        if ($imageData === false || strlen($imageData) === 0) {
            $this->json(['success' => false, 'message' => 'Bildverarbeitung fehlgeschlagen']);
        }

        if (strlen($imageData) > 5242880) {
            $this->json(['success' => false, 'message' => 'Bild ist zu groß. Maximum: 5MB']);
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

            if (!copy($tmpFile, $uploadDir . $filename)) {
                throw new \Exception('Fehler beim Speichern des Profilbildes');
            }
            chmod($uploadDir . $filename, 0644);

            $projectRoot    = realpath(__DIR__ . '/../../');
            $realUploadPath = realpath($uploadDir . $filename);
            $relativePath   = str_replace('\\', '/', substr($realUploadPath, strlen($projectRoot) + 1));

            $user     = \Auth::user();
            $userId   = $user['id'];
            $userRole = $user['role'] ?? '';

            if (\isMemberRole($userRole)) {
                $existing = \Member::getProfileByUserId($userId);
            } else {
                $existing = \Alumni::getProfileByUserId($userId);
            }

            if (!empty($existing['image_path'])) {
                \SecureImageUpload::deleteImage($existing['image_path']);
            }

            if (\isMemberRole($userRole)) {
                \Member::updateProfile($userId, ['image_path' => $relativePath]);
            } else {
                \Alumni::updateProfile($userId, ['image_path' => $relativePath]);
            }

            $this->json(['success' => true, 'image_path' => '/' . $relativePath]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function deleteAvatar(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        \CSRFHandler::verifyToken($body['csrf_token'] ?? '');

        $user     = \Auth::user();
        $userId   = $user['id'];
        $userRole = $user['role'] ?? '';

        $existingProfile = \Member::getProfileByUserId($userId);

        if (!$existingProfile || empty($existingProfile['image_path'])) {
            $this->json(['success' => true]);
        }

        \SecureImageUpload::deleteImage($existingProfile['image_path']);

        if (\isMemberRole($userRole)) {
            \Member::updateProfile($userId, ['image_path' => null]);
        } else {
            \Alumni::updateProfile($userId, ['image_path' => null]);
        }

        $this->json(['success' => true]);
    }

    public function exportUserData(array $vars = []): void
    {
        $this->requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return;
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $user   = \Auth::user();
        $userId = (int)$user['id'];

        $sensitiveFields = ['password', 'tfa_secret', 'current_session_id'];
        $profile         = array_diff_key($user, array_flip($sensitiveFields));

        $eventSignups = [];
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT es.id, es.event_id, e.title AS event_title, e.start_date, es.slot_id, es.helper_type_id, es.role_id, es.created_at FROM event_signups es LEFT JOIN events e ON e.id = es.event_id WHERE es.user_id = ? ORDER BY es.created_at DESC");
            $stmt->execute([$userId]);
            $eventSignups = $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log('export_user_data: event_signups failed: ' . $e->getMessage());
        }

        $eventRegistrations = [];
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT er.id, er.event_id, e.title AS event_title, e.start_date, er.status, er.registered_at FROM event_registrations er LEFT JOIN events e ON e.id = er.event_id WHERE er.user_id = ? ORDER BY er.registered_at DESC");
            $stmt->execute([$userId]);
            $eventRegistrations = $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log('export_user_data: event_registrations failed: ' . $e->getMessage());
        }

        $filename = 'user_data_export_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Profil-Daten'], ';');
        foreach ($profile as $key => $value) {
            fputcsv($out, [$key, is_array($value) ? json_encode($value) : (string)$value], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['Event-Anmeldungen (Slots)'], ';');
        fputcsv($out, ['ID', 'Event-ID', 'Event-Titel', 'Startdatum', 'Slot-ID', 'Erstellt am'], ';');
        foreach ($eventSignups as $signup) {
            fputcsv($out, [$signup['id'], $signup['event_id'], $signup['event_title'], $signup['start_date'], $signup['slot_id'], $signup['created_at']], ';');
        }
        fputcsv($out, [], ';');
        fputcsv($out, ['Event-Registrierungen'], ';');
        fputcsv($out, ['ID', 'Event-ID', 'Event-Titel', 'Startdatum', 'Status', 'Registriert am'], ';');
        foreach ($eventRegistrations as $reg) {
            fputcsv($out, [$reg['id'], $reg['event_id'], $reg['event_title'], $reg['start_date'], $reg['status'], $reg['registered_at']], ';');
        }
        fclose($out);
    }

    public function dismissProfileReview(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        if (!isset($_SESSION['user_id'])) {
            $this->json(['success' => false, 'message' => 'Sitzung ungültig']);
        }

        try {
            $db   = \Database::getUserDB();
            $stmt = $db->prepare("UPDATE users SET prompt_profile_review = 0 WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('dismissProfileReview: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Fehler beim Speichern']);
        }
    }

    public function completeOnboarding(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $input = $_POST;
        }

        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        if (!isset($_SESSION['user_id'])) {
            $this->json(['success' => false, 'message' => 'Sitzung ungültig']);
        }

        $userId = $_SESSION['user_id'];
        $user   = \Auth::user();
        if (!$user) {
            $this->json(['success' => false, 'message' => 'Benutzer nicht gefunden']);
        }

        $userRole = $user['role'] ?? '';

        $profileData = [
            'first_name'  => trim($input['first_name'] ?? ''),
            'last_name'   => trim($input['last_name'] ?? ''),
            'mobile_phone' => trim($input['mobile_phone'] ?? ''),
        ];

        if (empty($profileData['first_name']) || empty($profileData['last_name'])) {
            $this->json(['success' => false, 'message' => 'Vorname und Nachname sind erforderlich']);
        }

        try {
            if (\isMemberRole($userRole)) {
                \Member::updateOrCreateProfile($userId, $profileData);
            } else {
                \Alumni::updateOrCreateProfile($userId, $profileData);
            }

            $db   = \Database::getUserDB();
            $stmt = $db->prepare("UPDATE users SET is_onboarded = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            $_SESSION['is_onboarded'] = true;

            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('completeOnboarding: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Fehler beim Speichern']);
        }
    }

    public function submitSupport(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $rateLimitWait = \checkFormRateLimit('last_support_submit_time');
        if ($rateLimitWait > 0) {
            http_response_code(429);
            $this->json(['success' => false, 'message' => 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Anfrage sendest.']);
        }

        $requestType = trim($_POST['request_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allowedTypes = ['bug', '2fa_reset', 'other'];

        if (empty($requestType) || !in_array($requestType, $allowedTypes, true)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Art der Anfrage']);
        }
        if (empty($description)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Beschreibung darf nicht leer sein']);
        }

        $typeLabels = ['bug' => 'Bug / Fehler', '2fa_reset' => '2FA zurücksetzen', 'other' => 'Sonstiges'];
        $typeLabel  = $typeLabels[$requestType] ?? $requestType;
        $user       = \Auth::user();

        $emailBody = '<h2>Support-Anfrage: ' . htmlspecialchars($typeLabel) . '</h2>';
        $emailBody .= '<p><strong>Von:</strong> ' . htmlspecialchars($user['email']) . '</p>';
        $emailBody .= '<p><strong>Beschreibung:</strong></p>';
        $emailBody .= '<p>' . nl2br(htmlspecialchars($description)) . '</p>';

        $sent = \MailService::sendEmail(
            defined('MAIL_IT_RESSORT') ? \MAIL_IT_RESSORT : \SMTP_FROM,
            'Support-Anfrage: ' . $typeLabel . ' von ' . $user['email'],
            $emailBody
        );

        if ($sent) {
            $this->json(['success' => true, 'message' => 'Anfrage erfolgreich gesendet.']);
        } else {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Fehler beim Senden der Anfrage.']);
        }
    }

    public function submit2faSupport(array $vars = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!isset($_SESSION['pending_2fa_user_id'])) {
            http_response_code(401);
            $this->json(['success' => false, 'message' => 'Keine ausstehende 2FA-Verifizierung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        try {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
        } catch (\Exception $csrfEx) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'CSRF-Token ungültig. Bitte lade die Seite neu.']);
        }

        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Bitte gib deinen Namen an']);
        }
        if (strlen($name) > 200) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Name ist zu lang (max. 200 Zeichen)']);
        }
        if (empty($description)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Beschreibung darf nicht leer sein']);
        }

        $userEmail = $_SESSION['pending_2fa_email'] ?? 'Unbekannt';
        $emailBody = '<h2>2FA Support-Anfrage</h2>';
        $emailBody .= '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>';
        $emailBody .= '<p><strong>E-Mail:</strong> ' . htmlspecialchars($userEmail) . '</p>';
        $emailBody .= '<p><strong>Beschreibung:</strong></p>';
        $emailBody .= '<p>' . nl2br(htmlspecialchars($description)) . '</p>';

        $sent = \MailService::sendEmail(
            defined('MAIL_IT_RESSORT') ? \MAIL_IT_RESSORT : \SMTP_FROM,
            '2FA Support-Anfrage von ' . $name,
            $emailBody
        );

        if ($sent) {
            $this->json(['success' => true, 'message' => 'Anfrage erfolgreich gesendet.']);
        } else {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Fehler beim Senden der Anfrage.']);
        }
    }

    /**
     * Serves a Microsoft Entra profile photo for a given e-mail address.
     * Photos are cached locally for 24 hours to reduce API round-trips.
     * Replaces fetch-profile-photo.php in the project root.
     *
     * Usage: GET /profile-photo?email=user@example.com
     */
    public function fetchProfilePhoto(array $vars = []): void
    {
        $cacheDir         = __DIR__ . '/../../assets/img/cache/profile_photos/';
        $cacheTtl         = 86400; // 24 hours
        $defaultProfileImg = __DIR__ . '/../../assets/img/default_profil.png';

        $serveFallbackPixel = static function (): never {
            self::serveTransparentPixel();
        };

        $serveDefaultAvatar = static function () use ($defaultProfileImg, $serveFallbackPixel): never {
            if (file_exists($defaultProfileImg)) {
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=3600');
                readfile($defaultProfileImg);
                exit;
            }
            $serveFallbackPixel();
        };

        $detectImageType = static function (string $data): array {
            if (function_exists('finfo_open')) {
                $fi   = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_buffer($fi, $data);
                finfo_close($fi);
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                if (isset($extMap[$mime])) {
                    return ['mime' => $mime, 'ext' => $extMap[$mime]];
                }
            }
            if (substr($data, 0, 2) === "\xFF\xD8") {
                return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
            }
            if (substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
                return ['mime' => 'image/png', 'ext' => 'png'];
            }
            if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
                return ['mime' => 'image/webp', 'ext' => 'webp'];
            }
            if (substr($data, 0, 6) === 'GIF87a' || substr($data, 0, 6) === 'GIF89a') {
                return ['mime' => 'image/gif', 'ext' => 'gif'];
            }
            return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
        };

        $emailToCacheBase = static function (string $email): string {
            return (string) preg_replace('/[^a-zA-Z0-9._\-]/', '_', strtolower($email));
        };

        // 1. Resolve user e-mail
        $rawEmail  = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
        $userEmail = ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) ? $rawEmail : 'it@business-consulting.de';
        $cacheBase = $emailToCacheBase($userEmail);

        // 2. Serve from cache if fresh
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = $cacheDir . $cacheBase . '.' . $ext;
            if (file_exists($path) && (time() - (int) filemtime($path)) < $cacheTtl) {
                $data = file_get_contents($path);
                if ($data !== false && strlen($data) > 0) {
                    $type = $detectImageType($data);
                    header('Content-Type: ' . $type['mime']);
                    header('Cache-Control: public, max-age=3600');
                    header('X-Photo-Cache: HIT');
                    echo $data;
                    return;
                }
            }
        }

        // 3. Obtain Azure credentials
        $tenantId     = defined('AZURE_TENANT_ID')     ? \AZURE_TENANT_ID     : '';
        $clientId     = defined('AZURE_CLIENT_ID')     ? \AZURE_CLIENT_ID     : '';
        $clientSecret = defined('AZURE_CLIENT_SECRET') ? \AZURE_CLIENT_SECRET : '';

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            error_log('ProfileController::fetchProfilePhoto: Azure credentials missing in .env');
            $serveDefaultAvatar();
        }

        // 4. Request access token via Client Credentials Flow
        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $ch       = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $tokenBody = curl_exec($ch);
        $tokenCode = $tokenBody !== false ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        curl_close($ch);

        if ($tokenCode !== 200 || $tokenBody === false) {
            error_log("ProfileController::fetchProfilePhoto: Token request failed (HTTP {$tokenCode})");
            $serveDefaultAvatar();
        }

        $tokenData   = json_decode((string) $tokenBody, true);
        $accessToken = $tokenData['access_token'] ?? '';

        if ($accessToken === '') {
            error_log('ProfileController::fetchProfilePhoto: No access_token in response');
            $serveDefaultAvatar();
        }

        // 5. Fetch profile photo from Microsoft Graph
        $photoUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userEmail) . '/photo/$value';
        $ch       = curl_init($photoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        $photoData = curl_exec($ch);
        $photoCode = $photoData !== false ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        curl_close($ch);

        if ($photoCode !== 200 || $photoData === false || strlen((string) $photoData) === 0) {
            if ($photoCode !== 404) {
                error_log("ProfileController::fetchProfilePhoto: Photo fetch failed (HTTP {$photoCode}) for {$userEmail}");
            }
            $serveDefaultAvatar();
        }

        // 6. Cache and serve
        $type = $detectImageType((string) $photoData);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0750, true);
        }
        foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
            $old = $cacheDir . $cacheBase . '.' . $oldExt;
            if (file_exists($old)) {
                @unlink($old);
            }
        }
        file_put_contents($cacheDir . $cacheBase . '.' . $type['ext'], $photoData);

        header('Content-Type: ' . $type['mime']);
        header('Cache-Control: public, max-age=3600');
        header('X-Photo-Cache: MISS');
        echo $photoData;
    }

    /**
     * Emit a transparent 1×1 PNG and terminate – used as an ultimate fallback
     * when neither a cached photo nor the default avatar is available.
     *
     * Minimal valid transparent PNG (68 bytes, RFC-2083 compliant).
     */
    private static function serveTransparentPixel(): never
    {
        // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
        $pixel = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIA'
            . 'BQABNjN9GQAAAAlwSFlzAAALEwAACxMBAJqcGAAAAA1JREFUCNdjYGBg+A8AAQ'
            . 'QAAbWJngcAAAAASUVORK5CYII='
        );
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo $pixel;
        exit;
    }
}
