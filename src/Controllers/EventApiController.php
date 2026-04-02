<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class EventApiController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function eventSignup(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        }

        $action = $input['action'] ?? null;
        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        $user     = \Auth::user();
        $userId   = $user['id'];
        $userRole = $_SESSION['user_role'] ?? 'mitglied';

        try {
            switch ($action) {
                case 'signup':
                    $eventId   = isset($input['event_id']) ? (int)$input['event_id'] : null;
                    $slotId    = isset($input['slot_id']) ? (int)$input['slot_id'] : null;
                    $slotStart = $input['slot_start'] ?? null;
                    $slotEnd   = $input['slot_end'] ?? null;

                    if (!$eventId) {
                        throw new \Exception('Event-ID fehlt');
                    }

                    $event = \Event::getById($eventId, false);
                    if (!$event) {
                        throw new \Exception('Event nicht gefunden');
                    }

                    $userSignups    = \Event::getUserSignups($userId);
                    $existingSignup = null;
                    foreach ($userSignups as $signup) {
                        if ($signup['event_id'] == $eventId && $signup['status'] !== 'cancelled') {
                            $existingSignup = $signup;
                            break;
                        }
                    }

                    if ($slotId) {
                        $result = \Event::signup($eventId, $userId, $slotId);
                    } else {
                        $result = \Event::signup($eventId, $userId);
                    }

                    $this->json($result);
                    break;

                case 'cancel':
                    $eventId  = isset($input['event_id']) ? (int)$input['event_id'] : null;
                    $signupId = isset($input['signup_id']) ? (int)$input['signup_id'] : null;

                    if (!$eventId) {
                        throw new \Exception('Event-ID fehlt');
                    }

                    $result = \Event::cancelSignup($eventId, $userId, $signupId);
                    $this->json($result);
                    break;

                case 'simple_register':
                    $eventId = isset($input['event_id']) ? (int)$input['event_id'] : null;
                    if (!$eventId) {
                        throw new \Exception('Event-ID fehlt');
                    }

                    $userEmail = $user['email'];
                    $userName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $db        = \Database::getContentDB();

                    $stmt = $db->prepare("SELECT id, title, description, location, start_time, end_time, contact_person FROM events WHERE id = ?");
                    $stmt->execute([$eventId]);
                    $eventRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$eventRow) {
                        throw new \Exception('Event nicht gefunden');
                    }

                    $stmt = $db->prepare("SELECT id, status FROM event_registrations WHERE event_id = ? AND user_id = ?");
                    $stmt->execute([$eventId, $userId]);
                    $existingRegistration = $stmt->fetch(\PDO::FETCH_ASSOC);

                    if ($existingRegistration && $existingRegistration['status'] === 'confirmed') {
                        throw new \Exception('Du bist bereits für dieses Event angemeldet');
                    }

                    if ($existingRegistration) {
                        $stmt = $db->prepare("UPDATE event_registrations SET status = 'confirmed', registered_at = NOW(), updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$existingRegistration['id']]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO event_registrations (event_id, user_id, status, registered_at) VALUES (?, ?, 'confirmed', NOW())");
                        $stmt->execute([$eventId, $userId]);
                    }

                    try {
                        \MailService::sendEventConfirmation($userEmail, $userName, $eventRow);
                    } catch (\Exception $mailError) {
                        error_log('simple_register: email failed: ' . $mailError->getMessage());
                    }

                    $this->json(['success' => true, 'message' => 'Erfolgreich angemeldet']);
                    break;

                default:
                    throw new \Exception('Ungültige Aktion');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function eventSignupSimple(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Ungültiges JSON-Format');
            }

            \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

            $eventId = $input['event_id'] ?? null;
            if (!$eventId) {
                throw new \Exception('Event-ID fehlt');
            }

            $user     = \Auth::user();
            $userId   = $user['id'];
            $userEmail = $user['email'];
            $userName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT id, title, description, location, start_time, end_time, contact_person FROM events WHERE id = ?");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$event) {
                throw new \Exception('Event nicht gefunden');
            }

            $stmt = $db->prepare("SELECT id, status FROM event_registrations WHERE event_id = ? AND user_id = ?");
            $stmt->execute([$eventId, $userId]);
            $existingRegistration = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingRegistration) {
                if ($existingRegistration['status'] === 'confirmed') {
                    $stmt = $db->prepare("UPDATE event_registrations SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$existingRegistration['id']]);
                    $this->json(['success' => true, 'registered' => false, 'message' => 'Abmeldung erfolgreich']);
                }
                $stmt = $db->prepare("UPDATE event_registrations SET status = 'confirmed', registered_at = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$existingRegistration['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO event_registrations (event_id, user_id, status, registered_at) VALUES (?, ?, 'confirmed', NOW())");
                $stmt->execute([$eventId, $userId]);
            }

            try {
                \MailService::sendEventConfirmation($userEmail, $userName, $event);
            } catch (\Exception $mailError) {
                error_log('eventSignupSimple email failed: ' . $mailError->getMessage());
            }

            $this->json(['success' => true, 'registered' => true, 'message' => 'Erfolgreich angemeldet']);
        } catch (\Exception $e) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function downloadIcs(array $vars = []): void
    {
        $this->requireAuth();

        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        if (!$eventId || $eventId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            $this->json(['success' => false, 'error' => 'Ungültige Event ID']);
        }

        try {
            $event = \Event::getById($eventId, true);
            if (!$event) {
                http_response_code(404);
                header('Content-Type: application/json');
                $this->json(['success' => false, 'error' => 'Event nicht gefunden']);
            }

            $userRole     = $_SESSION['user_role'] ?? 'mitglied';
            $allowedRoles = $event['allowed_roles'] ?? [];
            if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
                http_response_code(403);
                header('Content-Type: application/json');
                $this->json(['success' => false, 'error' => 'Keine Berechtigung']);
            }

            $icsContent = \CalendarService::generateIcsFile($event);
            $safeEventId = (string)$eventId;
            $safeDate    = date('Ymd');
            $filename    = str_replace('"', '', 'event_' . $safeEventId . '_' . $safeDate . '.ics');

            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($icsContent));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $icsContent;
        } catch (\Exception $e) {
            error_log('downloadIcs: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            $this->json(['success' => false, 'error' => 'Server-Fehler']);
        }
    }

    public function saveEventDocumentation(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';
        $allowedRoles = array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand']);
        if (!in_array($userRole, $allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$data) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Daten']);
        }

        \CSRFHandler::verifyToken($data['csrf_token'] ?? '');

        $eventId         = isset($data['event_id']) ? (int)$data['event_id'] : null;
        $calculationLink = $data['calculation_link'] ?? null;
        $salesData       = $data['sales_data'] ?? [];
        $sellersData     = $data['sellers_data'] ?? [];

        if (!$eventId) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Event-ID fehlt']);
        }

        try {
            $result = \EventDocumentation::save($eventId, $calculationLink, $salesData, $sellersData, $user['id']);
            $this->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            error_log('saveEventDocumentation: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function saveFinancialStats(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';
        $allowedRoles = array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand']);
        if (!in_array($userRole, $allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$data) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Daten']);
        }

        \CSRFHandler::verifyToken($data['csrf_token'] ?? '');

        $eventId = isset($data['event_id']) ? (int)$data['event_id'] : null;
        if (!$eventId) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Event-ID fehlt']);
        }

        try {
            $result = \EventFinancialStats::save($eventId, $data, $user['id']);
            $this->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            error_log('saveFinancialStats: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function getMailTemplate(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\Auth::canManageUsers()) {
            http_response_code(403);
            $this->json(['error' => 'Unauthorized']);
        }

        $template = trim($_GET['template'] ?? '');
        if (empty($template)) {
            http_response_code(400);
            $this->json(['error' => 'Kein Template angegeben.']);
        }

        if (!preg_match('/^[a-zA-Z0-9_\- ]+$/', $template)) {
            http_response_code(400);
            $this->json(['error' => 'Ungültiger Template-Name.']);
        }

        $template    = basename($template);
        $templateDir = realpath(__DIR__ . '/../../assets/mail_vorlage');

        if ($templateDir === false) {
            http_response_code(403);
            $this->json(['error' => 'Zugriff verweigert.']);
        }

        $filePath = realpath($templateDir . '/' . $template . '.json');
        if ($filePath === false || !str_starts_with($filePath, $templateDir . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            $this->json(['error' => 'Zugriff verweigert.']);
        }

        if (!file_exists($filePath)) {
            http_response_code(404);
            $this->json(['error' => 'Template nicht gefunden.']);
        }

        $content = file_get_contents($filePath);
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(500);
            $this->json(['error' => 'Template konnte nicht geladen werden.']);
        }

        $this->json($decoded);
    }

    /**
     * Set or remove the feedback contact for an event.
     *
     * Only alumni roles may volunteer.  Auth is enforced by AuthMiddleware.
     */
    public function setFeedbackContact(array $vars = []): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiges JSON-Format']);
            return;
        }

        \CSRFHandler::verifyToken($input['csrf_token'] ?? '');

        $user     = \Auth::user();
        $userRole = $_SESSION['user_role'] ?? '';

        $allowedRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
        if (!in_array($userRole, $allowedRoles, true)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Nur Alumni-Rollen können Feedback-Ansprechpartner werden']);
            return;
        }

        $eventId = intval($input['id'] ?? 0);
        $action  = $input['action'] ?? 'set';

        if (!$eventId || !in_array($action, ['set', 'remove'], true)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
            return;
        }

        $event = \Event::getById($eventId, false);
        if (!$event) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Event nicht gefunden']);
            return;
        }

        if ($action === 'remove' && intval($event['feedback_contact_user_id'] ?? 0) !== $user['id']) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Du bist nicht der Ansprechpartner dieses Events']);
            return;
        }

        $userId = $action === 'set' ? $user['id'] : null;
        \Event::setFeedbackContact($eventId, $userId);

        $message = $action === 'set'
            ? 'Du bist jetzt Feedback-Ansprechpartner'
            : 'Du bist nicht mehr Feedback-Ansprechpartner';

        $this->json(['success' => true, 'message' => $message]);
    }
}
