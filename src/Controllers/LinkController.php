<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class LinkController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $allowedRoles = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz'];
        $currentUser  = \Auth::user();
        if (!$currentUser || !in_array($currentUser['role'] ?? '', $allowedRoles)) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $userRole  = $currentUser['role'] ?? '';
        $canManage = \Link::canManage($userRole);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $deleteId = (int)($_POST['link_id'] ?? 0);
            if ($deleteId > 0) {
                try {
                    \Link::delete($deleteId);
                    $_SESSION['success_message'] = 'Link erfolgreich gelöscht.';
                } catch (\Exception $e) {
                    $_SESSION['error_message'] = 'Fehler beim Löschen des Links.';
                }
            }
            $this->redirect(\BASE_URL . '/links');
        }

        $searchQuery    = trim($_GET['q'] ?? '');
        $links          = [];
        $successMessage = $_SESSION['success_message'] ?? null;
        $errorMessage   = $_SESSION['error_message'] ?? null;
        unset($_SESSION['success_message'], $_SESSION['error_message']);

        try {
            $links = \Link::getAll($searchQuery);
        } catch (\Exception $e) {
            $errorMessage = 'Fehler beim Laden der Links aus der Datenbank: ' . htmlspecialchars($e->getMessage());
        }

        $this->render('links/index.twig', [
            'currentUser'    => $currentUser,
            'canManage'      => $canManage,
            'links'          => $links,
            'searchQuery'    => $searchQuery,
            'successMessage' => $successMessage,
            'errorMessage'   => $errorMessage,
            'csrfToken'      => \CSRFHandler::getToken(),
        ]);
    }

    /**
     * Create a new link (GET: show form, POST: save).
     */
    public function create(array $vars = []): void
    {
        $this->requireAuth();
        $currentUser = \Auth::user();
        $userRole    = $currentUser['role'] ?? '';

        if (! \Link::canManage($userRole)) {
            $this->redirect(\BASE_URL . '/links');
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            [$errors] = $this->processLinkForm(null, $currentUser['id'] ?? 0);
            if (empty($errors)) {
                $this->redirect(\BASE_URL . '/links');
            }
        }

        $this->render('links/edit.twig', [
            'currentUser' => $currentUser,
            'link'        => null,
            'isEdit'      => false,
            'errors'      => $errors,
            'post'        => $_POST,
            'csrfToken'   => \CSRFHandler::getToken(),
        ]);
    }

    /**
     * Edit an existing link (GET: show form, POST: save).
     */
    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        $currentUser = \Auth::user();
        $userRole    = $currentUser['role'] ?? '';

        if (! \Link::canManage($userRole)) {
            $this->redirect(\BASE_URL . '/links');
        }

        $linkId = isset($vars['id']) ? (int)$vars['id'] : 0;
        $link   = $linkId > 0 ? \Link::getById($linkId) : null;

        if (! $link) {
            $this->redirect(\BASE_URL . '/links');
        }

        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            [$errors] = $this->processLinkForm($linkId, $currentUser['id'] ?? 0);
            if (empty($errors)) {
                $this->redirect(\BASE_URL . '/links');
            }
        }

        $this->render('links/edit.twig', [
            'currentUser' => $currentUser,
            'link'        => $link,
            'isEdit'      => true,
            'errors'      => $errors,
            'post'        => $_POST,
            'csrfToken'   => \CSRFHandler::getToken(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Validate and save a link from the form POST data.
     *
     * @param int|null $linkId  Null for create, ID for update
     * @param int      $userId  Current user's ID (for created_by on create)
     * @return array{0: string[]}  [errors]
     */
    private function processLinkForm(?int $linkId, int $userId): array
    {
        $title       = trim($_POST['title'] ?? '');
        $url         = trim($_POST['url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon        = trim($_POST['icon'] ?? 'fas fa-link') ?: 'fas fa-link';

        $errors = [];

        if (empty($title)) {
            $errors[] = 'Bitte geben Sie einen Titel ein.';
        }
        if (empty($url)) {
            $errors[] = 'Bitte geben Sie eine URL ein.';
        } elseif (! filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Bitte geben Sie eine gültige URL ein (z.B. https://beispiel.de).';
        } else {
            $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
            if (! in_array($scheme, ['http', 'https'], true)) {
                $errors[] = 'Nur http:// und https:// URLs sind erlaubt.';
            }
        }

        if (empty($errors)) {
            $data = [
                'title'       => $title,
                'url'         => $url,
                'description' => $description ?: null,
                'icon'        => $icon,
                'sort_order'  => 0,
            ];

            try {
                if ($linkId !== null) {
                    \Link::update($linkId, $data);
                } else {
                    $data['created_by'] = $userId;
                    \Link::create($data);
                }
            } catch (\Exception $e) {
                $errors[] = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
            }
        }

        return [$errors];
    }
}
