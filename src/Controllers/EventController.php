<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class EventController extends BaseController
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
        $filter   = $_GET['filter'] ?? 'current';
        $filters  = [];
        $now      = date('Y-m-d H:i:s');

        if ($filter === 'current') {
            $filters['start_date'] = $now;
        }

        $events = \Event::getEvents($filters, $userRole, $user['id']);

        if ($filter === 'my_registrations') {
            $userSignups = \Event::getUserSignups($user['id']);
            $myEventIds  = array_column($userSignups, 'event_id');
            $events      = array_filter($events, fn($event) => in_array($event['id'], $myEventIds));
        } else {
            $canViewPastEvents = \Auth::isBoard() || \Auth::hasRole(['alumni_vorstand', 'alumni_finanz', 'manager']);
            if (!$canViewPastEvents) {
                $events = array_filter($events, fn($event) => $event['end_time'] >= $now);
            }
        }

        $userSignups = \Event::getUserSignups($user['id']);
        $myEventIds  = array_column($userSignups, 'event_id');

        $this->render('events/index.twig', [
            'user'        => $user,
            'userRole'    => $userRole,
            'events'      => array_values($events),
            'filter'      => $filter,
            'myEventIds'  => $myEventIds,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $_SESSION['user_role'] ?? 'mitglied';

        $eventId = $_GET['id'] ?? null;
        if (!$eventId) {
            $this->redirect(\BASE_URL . '/events');
        }

        $event = \Event::getById($eventId, true);
        if (!$event) {
            $this->redirect(\BASE_URL . '/events');
        }

        $allowedRoles = $event['allowed_roles'] ?? [];
        if (!empty($allowedRoles) && !in_array($userRole, $allowedRoles)) {
            $this->redirect(\BASE_URL . '/events');
        }

        $userSignups       = \Event::getUserSignups($user['id']);
        $isRegistered      = false;
        $userSignupId      = null;
        $userSlotId        = null;
        foreach ($userSignups as $signup) {
            if ($signup['event_id'] == $eventId) {
                $isRegistered = true;
                $userSignupId = $signup['id'];
                $userSlotId   = $signup['slot_id'];
                break;
            }
        }

        $registrationCount = \Event::getRegistrationCount($eventId);
        $participants      = \Event::getEventAttendees($eventId);
        $helperTypes       = [];

        if ($event['needs_helpers'] && $userRole !== 'alumni') {
            $helperTypes = \Event::getHelperTypes($eventId);
            foreach ($helperTypes as &$helperType) {
                $slots   = \Event::getSlots($helperType['id']);
                $signups = \Event::getSignups($eventId);
                foreach ($slots as &$slot) {
                    $confirmedCount = 0;
                    $userInSlot     = false;
                    foreach ($signups as $signup) {
                        if ($signup['slot_id'] == $slot['id'] && $signup['status'] == 'confirmed') {
                            $confirmedCount++;
                            if ($signup['user_id'] == $user['id']) {
                                $userInSlot = true;
                            }
                        }
                    }
                    $slot['signups_count'] = $confirmedCount;
                    $slot['user_in_slot']  = $userInSlot;
                    $slot['is_full']       = $confirmedCount >= $slot['quantity_needed'];
                }
                $helperType['slots'] = $slots;
            }
        }

        $signupDeadline  = $event['start_time'];
        $canCancel       = strtotime($signupDeadline) > time();
        $canAddStats     = in_array($userRole, array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand']));
        $feedbackContact = \Event::getFeedbackContact((int)$eventId);
        $feedbackContactRoles      = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
        $canBecomeFeedbackContact  = in_array($userRole, $feedbackContactRoles);

        $this->render('events/view.twig', [
            'user'                    => $user,
            'userRole'                => $userRole,
            'event'                   => $event,
            'isRegistered'            => $isRegistered,
            'userSignupId'            => $userSignupId,
            'userSlotId'              => $userSlotId,
            'registrationCount'       => $registrationCount,
            'participants'            => $participants,
            'helperTypes'             => $helperTypes,
            'canCancel'               => $canCancel,
            'canAddStats'             => $canAddStats,
            'feedbackContact'         => $feedbackContact,
            'canBecomeFeedbackContact' => $canBecomeFeedbackContact,
            'csrfToken'               => \CSRFHandler::getToken(),
        ]);
    }

    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        if (!(\Auth::hasPermission('manage_projects') || \Auth::isBoard() || \Auth::hasRole(['ressortleiter', 'alumni_vorstand']))) {
            $this->redirect(\BASE_URL . '/login');
        }

        $entraGroups = [];
        try {
            $graphService = new \MicrosoftGraphService();
            $entraGroups  = $graphService->getAllGroups();
        } catch (\Exception $e) {
            error_log("Could not fetch groups from Microsoft Graph: " . $e->getMessage());
        }

        $eventId  = intval($_GET['id'] ?? 0);
        $isNew    = isset($_GET['new']) && $_GET['new'] === '1';
        $isEdit   = $eventId > 0 && !$isNew;
        $readOnly = false;
        $lockWarning = '';
        $event    = null;
        $history  = [];

        if ($isEdit) {
            $event = \Event::getById($eventId);
            if (!$event) {
                $this->redirect(\BASE_URL . '/events/manage');
            }
            $lockResult = \Event::acquireLock($eventId, $_SESSION['user_id']);
            if (!$lockResult['success']) {
                $readOnly    = true;
                $lockedUser  = \User::getById($lockResult['locked_by']);
                $lockWarning = 'Dieses Event wird gerade von ' . htmlspecialchars(($lockedUser['first_name'] ?? '') . ' ' . ($lockedUser['last_name'] ?? '')) . ' bearbeitet. Du befindest Dich im Nur-Lesen-Modus.';
            }
            $history = \Event::getHistory($eventId, 10);
        }

        $message = '';
        $errors  = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
            try {
                \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }

            if (empty($errors)) {
                $startTime = $_POST['start_time'] ?? '';
                $endTime   = $_POST['end_time'] ?? '';

                if (empty($startTime) || empty($endTime)) {
                    $errors[] = 'Start- und Endzeit sind erforderlich';
                }
                if (empty($errors) && strtotime($startTime) >= strtotime($endTime)) {
                    $errors[] = 'Die Startzeit muss vor der Endzeit liegen';
                }

                $data = [
                    'title'                => trim($_POST['title'] ?? ''),
                    'description'          => trim($_POST['description'] ?? ''),
                    'location'             => trim($_POST['location'] ?? ''),
                    'maps_link'            => trim($_POST['maps_link'] ?? ''),
                    'start_time'           => $startTime,
                    'end_time'             => $endTime,
                    'registration_start'   => !empty($_POST['registration_start']) ? $_POST['registration_start'] : null,
                    'registration_end'     => !empty($_POST['registration_end']) ? $_POST['registration_end'] : null,
                    'is_external'          => isset($_POST['is_external']) ? 1 : 0,
                    'external_link'        => trim($_POST['external_link'] ?? ''),
                    'registration_link'    => trim($_POST['registration_link'] ?? ''),
                    'needs_helpers'        => isset($_POST['needs_helpers']) ? 1 : 0,
                    'is_internal_project'  => isset($_POST['is_internal_project']) ? 1 : 0,
                    'requires_application' => isset($_POST['requires_application']) ? 1 : 0,
                    'allowed_roles'        => $_POST['allowed_roles'] ?? [],
                ];

                if (in_array('board_roles', $data['allowed_roles'])) {
                    $data['allowed_roles'] = array_merge(
                        array_diff($data['allowed_roles'], ['board_roles']),
                        ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']
                    );
                }

                $data['status'] = isset($_POST['save_draft']) ? 'draft' : 'published';

                if (isset($_POST['delete_image']) && $_POST['delete_image'] === '1') {
                    $data['delete_image'] = true;
                }
                if ($data['needs_helpers']) {
                    $data['helper_types'] = json_decode($_POST['helper_types_json'] ?? '[]', true);
                }
                if (empty($data['title'])) {
                    $errors[] = 'Titel ist erforderlich';
                }

                if (empty($errors)) {
                    try {
                        if ($isEdit) {
                            \Event::update($eventId, $data, $_SESSION['user_id'], $_FILES);
                            \Event::releaseLock($eventId, $_SESSION['user_id']);
                            $this->redirect(\BASE_URL . '/events/edit?id=' . $eventId . '&success=1');
                        } else {
                            $newEventId = \Event::create($data, $_SESSION['user_id'], $_FILES);
                            $this->redirect(\BASE_URL . '/events/edit?id=' . $newEventId . '&success=1');
                        }
                    } catch (\Exception $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }

        $this->render('events/edit.twig', [
            'event'       => $event,
            'isNew'       => $isNew,
            'isEdit'      => $isEdit,
            'readOnly'    => $readOnly,
            'lockWarning' => $lockWarning,
            'history'     => $history,
            'message'     => $message,
            'errors'      => $errors,
            'entraGroups' => $entraGroups,
            'csrfToken'   => \CSRFHandler::getToken(),
            'success'     => isset($_GET['success']),
        ]);
    }

    public function manage(array $vars = []): void
    {
        $this->requireAuth();
        if (!(\Auth::hasPermission('manage_projects') || \Auth::isBoard() || \Auth::hasRole(['ressortleiter', 'alumni_vorstand']))) {
            $this->redirect(\BASE_URL . '/login');
        }

        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $eventId = intval($_POST['event_id'] ?? 0);
            try {
                \Event::delete($eventId, $_SESSION['user_id']);
                $message = 'Event erfolgreich gelöscht';
            } catch (\Exception $e) {
                $error = 'Fehler beim Löschen: ' . $e->getMessage();
            }
        }

        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] !== '') {
            $filters['needs_helpers'] = $_GET['needs_helpers'] == '1';
        }
        if (!empty($_GET['start_date'])) {
            $filters['start_date'] = $_GET['start_date'];
        }
        if (!empty($_GET['end_date'])) {
            $filters['end_date'] = $_GET['end_date'];
        }
        $filters['include_helpers'] = true;

        $userRole    = $_SESSION['user_role'] ?? 'mitglied';
        $events      = \Event::getEvents($filters, $userRole);
        $canAddStats = in_array($userRole, array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand']));

        $this->render('events/manage.twig', [
            'events'      => $events,
            'message'     => $message,
            'error'       => $error,
            'canAddStats' => $canAddStats,
            'csrfToken'   => \CSRFHandler::getToken(),
        ]);
    }

    public function statistics(array $vars = []): void
    {
        $this->requireAuth();
        $userRole    = $_SESSION['user_role'] ?? 'mitglied';
        $allowedDocRoles = array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand']);
        if (!in_array($userRole, $allowedDocRoles)) {
            $this->redirect(\BASE_URL . '/events');
        }

        $allDocs = \EventDocumentation::getAllWithEvents();

        $this->render('events/statistics.twig', [
            'allDocs' => $allDocs,
        ]);
    }
}
