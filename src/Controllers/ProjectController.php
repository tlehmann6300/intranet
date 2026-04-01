<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class ProjectController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $_SESSION['user_role'] ?? 'mitglied';

        $typeFilter  = $_GET['type'] ?? 'all';
        $validTypes  = ['all', 'internal', 'external'];
        if (!in_array($typeFilter, $validTypes)) {
            $typeFilter = 'all';
        }
        $searchQuery = trim($_GET['q'] ?? '');
        $isAdmin     = \Auth::isBoard() || \Auth::hasPermission('manage_projects');
        $db          = \Database::getContentDB();

        if ($typeFilter === 'all') {
            if ($isAdmin) {
                $stmt = $db->query("SELECT * FROM projects WHERE status != 'draft' ORDER BY created_at DESC");
            } else {
                $stmt = $db->query("SELECT * FROM projects WHERE status IN ('open', 'running', 'applying', 'completed') ORDER BY created_at DESC");
            }
        } else {
            if ($isAdmin) {
                $stmt = $db->prepare("SELECT * FROM projects WHERE status != 'draft' AND type = ? ORDER BY created_at DESC");
                $stmt->execute([$typeFilter]);
            } else {
                $stmt = $db->prepare("SELECT * FROM projects WHERE status IN ('open', 'running', 'applying', 'completed') AND type = ? ORDER BY created_at DESC");
                $stmt->execute([$typeFilter]);
            }
        }

        $projects         = $stmt->fetchAll();
        $filteredProjects = array_map(fn($project) => \Project::filterSensitiveData($project, $userRole, $user['id']), $projects);

        if ($searchQuery !== '') {
            $searchLower      = mb_strtolower($searchQuery);
            $filteredProjects = array_filter($filteredProjects, function ($project) use ($searchLower) {
                return str_contains(mb_strtolower($project['title'] ?? ''), $searchLower)
                    || str_contains(mb_strtolower($project['description'] ?? ''), $searchLower)
                    || str_contains(mb_strtolower($project['client_name'] ?? ''), $searchLower);
            });
        }

        $this->render('projects/index.twig', [
            'user'             => $user,
            'userRole'         => $userRole,
            'projects'         => array_values($filteredProjects),
            'typeFilter'       => $typeFilter,
            'searchQuery'      => $searchQuery,
            'isAdmin'          => $isAdmin,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user      = \Auth::user();
        $userRole  = $_SESSION['user_role'] ?? 'mitglied';
        $projectId = intval($_GET['id'] ?? 0);

        if ($projectId <= 0) {
            $this->redirect(\BASE_URL . '/projects');
        }

        $project = \Project::getById($projectId);
        if (!$project) {
            $this->redirect(\BASE_URL . '/projects');
        }

        if (isset($project['status']) && $project['status'] === 'draft' && !\Auth::hasPermission('manage_projects')) {
            $_SESSION['error'] = 'Zugriff verweigert. Dieses Projekt ist noch im Entwurf.';
            $this->redirect(\BASE_URL . '/projects');
        }

        $project         = \Project::filterSensitiveData($project, $userRole, $user['id']);
        $userApplication = null;
        if ($userRole !== 'alumni') {
            $userApplication = \Project::getUserApplication($projectId, $user['id']);
        }

        $isLead      = \Project::isLead($projectId, $user['id']);
        $canComplete = $isLead || \Auth::isBoard() || $userRole === 'manager';
        $teamSize    = \Project::getTeamSize($projectId);
        $maxConsultants = intval($project['max_consultants'] ?? 1);
        $teamPercentage = $maxConsultants > 0 ? min(100, round(($teamSize / $maxConsultants) * 100)) : 0;

        $isInternalProject   = ($project['type'] ?? 'internal') === 'internal';
        $requiresApplication = !$isInternalProject || (bool)($project['requires_application'] ?? 1);

        $isParticipant = false;
        if ($isInternalProject && $userRole !== 'alumni') {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT id FROM project_assignments WHERE project_id = ? AND user_id = ?");
            $stmt->execute([$projectId, $user['id']]);
            $isParticipant = (bool)$stmt->fetch();
        }

        $participants = [];
        if ($isInternalProject || $isLead || \Auth::isBoard() || \Auth::hasPermission('manage_projects')) {
            $participants = \Project::getParticipants($projectId);
        }

        $feedbackContact          = \Project::getFeedbackContact($projectId);
        $feedbackContactRoles     = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
        $canBecomeFeedbackContact = in_array($userRole, $feedbackContactRoles);
        $isFeedbackContact        = $feedbackContact && (int)($feedbackContact['user_id'] ?? 0) === (int)$user['id'];

        $this->render('projects/view.twig', [
            'user'                    => $user,
            'userRole'                => $userRole,
            'project'                 => $project,
            'userApplication'         => $userApplication,
            'isLead'                  => $isLead,
            'canComplete'             => $canComplete,
            'teamSize'                => $teamSize,
            'maxConsultants'          => $maxConsultants,
            'teamPercentage'          => $teamPercentage,
            'isInternalProject'       => $isInternalProject,
            'requiresApplication'     => $requiresApplication,
            'isParticipant'           => $isParticipant,
            'participants'            => $participants,
            'feedbackContact'         => $feedbackContact,
            'canBecomeFeedbackContact' => $canBecomeFeedbackContact,
            'isFeedbackContact'       => $isFeedbackContact,
            'csrfToken'               => \CSRFHandler::getToken(),
        ]);
    }

    public function manage(array $vars = []): void
    {
        $this->requireAuth();
        $canManageProjects = \Auth::hasPermission('manage_projects') || \Auth::isBoard() || \Auth::hasRole(['ressortleiter', 'alumni_vorstand']);
        if (!$canManageProjects) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $message  = '';
        $error    = '';
        $showForm = isset($_GET['new']) || isset($_GET['edit']);
        $project  = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            try {
                $projectId = intval($_POST['project_id'] ?? 0);

                if ($projectId > 0) {
                    $status = $_POST['status'] ?? 'draft';
                } else {
                    $isDraft = isset($_POST['save_draft']);
                    $status  = $isDraft ? 'draft' : 'open';
                }

                $isInternal  = isset($_POST['is_internal']);
                $projectData = [
                    'title'                   => trim($_POST['title'] ?? ''),
                    'description'             => trim($_POST['description'] ?? ''),
                    'client_name'             => trim($_POST['client_name'] ?? ''),
                    'client_contact_details'  => trim($_POST['client_contact_details'] ?? ''),
                    'priority'                => $_POST['priority'] ?? 'medium',
                    'type'                    => $isInternal ? 'internal' : 'external',
                    'status'                  => $status,
                    'max_consultants'         => $isInternal ? null : max(1, intval($_POST['max_consultants'] ?? 1)),
                    'requires_application'    => $isInternal ? intval($_POST['requires_application'] ?? 1) : 1,
                    'start_date'              => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                    'end_date'                => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    'created_by'              => \Auth::user()['id'] ?? null,
                ];

                if (empty($projectData['title'])) {
                    throw new \Exception('Titel ist erforderlich');
                }

                if ($projectId === 0 && $status !== 'draft') {
                    $requiredFields = [
                        'description'            => 'Beschreibung',
                        'client_name'            => 'Kundenname',
                        'client_contact_details' => 'Kontaktdaten',
                        'start_date'             => 'Startdatum',
                        'end_date'               => 'Enddatum',
                    ];
                    foreach ($requiredFields as $field => $label) {
                        if (empty($projectData[$field])) {
                            throw new \Exception($label . ' ist erforderlich für die Veröffentlichung');
                        }
                    }
                }

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = \SecureImageUpload::uploadImage($_FILES['image']);
                    if ($uploadResult['success']) {
                        $projectData['image_path'] = $uploadResult['path'];
                    }
                }

                if ($projectId > 0) {
                    \Project::update($projectId, $projectData);
                    $message = 'Projekt erfolgreich aktualisiert';
                } else {
                    $newProjectId = \Project::create($projectData);
                    $message      = 'Projekt erfolgreich erstellt';
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        if (isset($_GET['edit'])) {
            $editId  = intval($_GET['edit']);
            $project = \Project::getById($editId);
            $showForm = true;
        }

        $allProjects = \Project::getAll();

        $this->render('projects/manage.twig', [
            'projects'  => $allProjects,
            'showForm'  => $showForm,
            'project'   => $project,
            'message'   => $message,
            'error'     => $error,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }

    public function applications(array $vars = []): void
    {
        $this->requireAuth();
        \Auth::requireRole('manager');

        $message   = '';
        $error     = '';
        $projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

        if ($projectId <= 0) {
            $this->redirect(\BASE_URL . '/projects/manage');
        }

        $project = \Project::getById($projectId);
        if (!$project) {
            $this->redirect(\BASE_URL . '/projects/manage?error=' . urlencode('Projekt nicht gefunden'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_application'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $applicationId = intval($_POST['application_id'] ?? 0);
            $role          = $_POST['role'] ?? 'member';

            if (!in_array($role, ['lead', 'member'])) {
                $error = 'Ungültige Rolle ausgewählt';
            } else {
                try {
                    $db   = \Database::getContentDB();
                    $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ? AND project_id = ?");
                    $stmt->execute([$applicationId, $projectId]);
                    $application = $stmt->fetch();

                    if (!$application) {
                        throw new \Exception('Bewerbung nicht gefunden');
                    }
                    if ($application['status'] === 'accepted') {
                        throw new \Exception('Diese Bewerbung wurde bereits akzeptiert');
                    }

                    $db->beginTransaction();
                    try {
                        \Project::assignMember($projectId, $application['user_id'], $role);
                        $stmt = $db->prepare("UPDATE project_applications SET status = 'accepted' WHERE id = ?");
                        $stmt->execute([$applicationId]);
                        $db->commit();
                        $message = 'Bewerbung erfolgreich akzeptiert';
                    } catch (\Exception $e) {
                        $db->rollBack();
                        throw $e;
                    }
                } catch (\Exception $e) {
                    $error = 'Fehler: ' . $e->getMessage();
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_application'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $applicationId = intval($_POST['application_id'] ?? 0);
            try {
                $db   = \Database::getContentDB();
                $stmt = $db->prepare("UPDATE project_applications SET status = 'rejected' WHERE id = ? AND project_id = ?");
                $stmt->execute([$applicationId, $projectId]);
                $message = 'Bewerbung abgelehnt';
            } catch (\Exception $e) {
                $error = 'Fehler: ' . $e->getMessage();
            }
        }

        $db           = \Database::getContentDB();
        $stmt         = $db->prepare("SELECT pa.*, u.email FROM project_applications pa LEFT JOIN users u ON u.id = pa.user_id WHERE pa.project_id = ? ORDER BY pa.created_at DESC");
        $stmt->execute([$projectId]);
        $applications = $stmt->fetchAll();

        $this->render('projects/applications.twig', [
            'project'      => $project,
            'applications' => $applications,
            'message'      => $message,
            'error'        => $error,
            'csrfToken'    => \CSRFHandler::getToken(),
        ]);
    }
}
