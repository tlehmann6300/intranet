<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class IdeaController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        if (!\Auth::canAccessPage('ideas')) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $csrfToken = \CSRFHandler::getToken();
        $ideas     = \Idea::getAll((int)$user['id']);

        $userDb      = \Database::getUserDB();
        $userInfoMap = [];
        if (!empty($ideas)) {
            $uids = array_unique(array_column($ideas, 'user_id'));
            $ph   = str_repeat('?,', count($uids) - 1) . '?';
            $stmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
            $stmt->execute($uids);
            foreach ($stmt->fetchAll() as $u) {
                $userInfoMap[$u['id']] = $u['email'];
            }
        }

        $statusConfig = [
            'new'         => ['label' => 'Neu',          'dot' => 'bg-sky-400'],
            'in_review'   => ['label' => 'In Prüfung',   'dot' => 'bg-amber-400'],
            'accepted'    => ['label' => 'Angenommen',   'dot' => 'bg-green-500'],
            'rejected'    => ['label' => 'Abgelehnt',    'dot' => 'bg-red-500'],
            'implemented' => ['label' => 'Umgesetzt',    'dot' => 'bg-purple-500'],
        ];

        $this->render('ideas/index.twig', [
            'user'         => $user,
            'ideas'        => $ideas,
            'userInfoMap'  => $userInfoMap,
            'statusConfig' => $statusConfig,
            'csrfToken'    => $csrfToken,
        ]);
    }

    public function create(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\Auth::canAccessPage('ideas')) {
            http_response_code(403);
            $this->json(['success' => false, 'error' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'error' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $rateLimitWait = \checkFormRateLimit('last_idea_submit_time');
        if ($rateLimitWait > 0) {
            http_response_code(429);
            $this->json(['success' => false, 'error' => 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Idee einreichst.']);
        }

        $user        = \Auth::user();
        $title       = strip_tags(trim($_POST['title'] ?? ''));
        $description = strip_tags(trim($_POST['description'] ?? ''));

        if (empty($title) || empty($description)) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Titel und Beschreibung sind erforderlich.']);
        }

        try {
            $result = \Idea::create((int)$user['id'], $title, $description);
            if ($result['success']) {
                try {
                    $username  = explode('@', $user['email'])[0];
                    $emailBody = '<h2>Neue Idee eingereicht</h2>'
                        . '<p>Von: ' . htmlspecialchars($username) . '</p>'
                        . '<p>Titel: ' . htmlspecialchars($title) . '</p>'
                        . '<p>Beschreibung: ' . nl2br(htmlspecialchars($description)) . '</p>';
                    \MailService::sendEmail(
                        defined('SMTP_FROM') && \SMTP_FROM !== '' ? \SMTP_FROM : 'vorstand@business-consulting.de',
                        'Neue Idee: ' . $title,
                        $emailBody
                    );
                } catch (\Exception $e) {
                    error_log('create_idea: email send failed: ' . $e->getMessage());
                }
                $this->json($result);
            } else {
                http_response_code(500);
                $this->json(['success' => false, 'error' => 'Fehler beim Erstellen der Idee.']);
            }
        } catch (\Exception $e) {
            error_log('create_idea: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'error' => 'Server-Fehler']);
        }
    }

    public function vote(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\Auth::canAccessPage('ideas')) {
            http_response_code(403);
            $this->json(['success' => false, 'error' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'error' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $user   = \Auth::user();
        $ideaId = isset($_POST['idea_id']) ? (int)$_POST['idea_id'] : 0;
        $vote   = $_POST['vote'] ?? '';

        if ($ideaId <= 0 || !in_array($vote, ['up', 'down'], true)) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Ungültige Parameter.']);
        }

        try {
            $result = \Idea::vote($ideaId, (int)$user['id'], $vote);
            if ($result['success']) {
                $this->json($result);
            } else {
                http_response_code(500);
                $this->json(['success' => false, 'error' => 'Fehler beim Abstimmen.']);
            }
        } catch (\Exception $e) {
            error_log('vote_idea: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'error' => 'Server-Fehler']);
        }
    }

    public function updateStatus(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\Auth::isBoard()) {
            http_response_code(403);
            $this->json(['success' => false, 'error' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'error' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $ideaId = isset($_POST['idea_id']) ? (int)$_POST['idea_id'] : 0;
        $status = $_POST['status'] ?? '';

        if ($ideaId <= 0 || empty($status)) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Ungültige Parameter.']);
        }

        try {
            $result = \Idea::updateStatus($ideaId, $status);
            if ($result) {
                $this->json(['success' => true]);
            } else {
                http_response_code(500);
                $this->json(['success' => false, 'error' => 'Fehler beim Aktualisieren des Status.']);
            }
        } catch (\Exception $e) {
            error_log('update_idea_status: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'error' => 'Server-Fehler']);
        }
    }
}
