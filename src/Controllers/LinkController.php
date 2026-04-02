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
}
