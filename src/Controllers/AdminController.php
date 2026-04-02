<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class AdminController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::isBoard()) {
            $this->redirect(\BASE_URL . '/login');
        }

        $userDb    = \Database::getUserDB();
        $contentDb = \Database::getContentDB();
        $metrics   = [];

        try {
            $stmt = $userDb->query("SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL");
            $metrics['total_users'] = $stmt->fetch()['count'] ?? 0;

            $stmt = $userDb->query("SELECT COUNT(*) as count FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL");
            $metrics['active_users_7d'] = $stmt->fetch()['count'] ?? 0;

            $stmt = $contentDb->query("SELECT COUNT(*) as count FROM system_logs WHERE action LIKE '%error%' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $metrics['recent_errors'] = $stmt->fetch()['count'] ?? 0;

            $stmt = $contentDb->query("SELECT COUNT(*) as count FROM system_logs WHERE action = 'login_failed' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $metrics['failed_logins_24h'] = $stmt->fetch()['count'] ?? 0;

            $stmt = $contentDb->query("SELECT COUNT(*) as count FROM system_logs WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $metrics['recent_logs'] = $stmt->fetch()['count'] ?? 0;
        } catch (\Exception $e) {
            error_log('admin/index metrics error: ' . $e->getMessage());
        }

        $this->render('admin/index.twig', [
            'metrics' => $metrics,
        ]);
    }

    public function users(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::canManageUsers()) {
            $this->redirect(\BASE_URL . '/login');
        }

        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['change_role'])) {
                $userId  = $_POST['user_id'] ?? 0;
                $newRole = $_POST['new_role'] ?? '';
                if ($userId == $_SESSION['user_id']) {
                    $error = 'Du kannst Deine eigene Rolle nicht ändern';
                } elseif (\User::update($userId, ['role' => $newRole])) {
                    $message = 'Rolle erfolgreich geändert';
                } else {
                    $error = 'Fehler beim Ändern der Rolle';
                }
            } elseif (isset($_POST['toggle_alumni_validation'])) {
                $userId      = $_POST['user_id'] ?? 0;
                $isValidated = $_POST['is_validated'] ?? 0;
                if (\User::update($userId, ['is_alumni_validated' => $isValidated])) {
                    $message = $isValidated ? 'Alumni-Profil freigegeben' : 'Alumni-Profil gesperrt';
                } else {
                    $error = 'Fehler beim Ändern des Alumni-Status';
                }
            } elseif (isset($_POST['delete_user'])) {
                $userId = $_POST['user_id'] ?? 0;
                if ($userId == $_SESSION['user_id']) {
                    $error = 'Du kannst Dich nicht selbst löschen';
                } elseif (\User::delete($userId)) {
                    $message = 'Benutzer erfolgreich gelöscht';
                } else {
                    $error = 'Fehler beim Löschen des Benutzers';
                }
            } elseif (isset($_POST['reset_2fa'])) {
                $canReset2fa = in_array($_SESSION['user_role'] ?? '', ['ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']);
                if (!$canReset2fa) {
                    $error = 'Keine Berechtigung zum Zurücksetzen von 2FA.';
                } else {
                    $userId = intval($_POST['user_id'] ?? 0);
                    if ($userId <= 0) {
                        $error = 'Ungültige Benutzer-ID.';
                    } elseif ($userId === (int)$_SESSION['user_id']) {
                        $error = 'Du kannst deine eigene 2FA nicht über diese Seite zurücksetzen.';
                    } else {
                        $db   = \Database::getUserDB();
                        $stmt = $db->prepare("UPDATE users SET tfa_secret = NULL, tfa_enabled = 0, tfa_failed_attempts = 0, tfa_locked_until = NULL WHERE id = ?");
                        $stmt->execute([$userId]);
                        $message = '2FA erfolgreich zurückgesetzt.';
                    }
                }
            }
        }

        $db    = \Database::getUserDB();
        $users = $db->query("SELECT * FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC")->fetchAll();

        $this->render('admin/users.twig', [
            'users'   => $users,
            'message' => $message,
            'error'   => $error,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }

    public function settings(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::canAccessSystemSettings()) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $message = '';
        $error   = '';
        $db      = \Database::getContentDB();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            try {
                try {
                    $db->query("SELECT 1 FROM system_settings LIMIT 1");
                } catch (\Exception $e) {
                    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT)");
                }

                if (isset($_POST['update_system_settings'])) {
                    $siteName          = $_POST['site_name'] ?? 'IBC Intranet';
                    $siteDescription   = $_POST['site_description'] ?? '';
                    $maintenanceMode   = isset($_POST['maintenance_mode']) ? 1 : 0;
                    $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;

                    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
                    $stmt->execute(['site_name', $siteName, $_SESSION['user_id']]);
                    $stmt->execute(['site_description', $siteDescription, $_SESSION['user_id']]);
                    $stmt->execute(['maintenance_mode', $maintenanceMode, $_SESSION['user_id']]);
                    $stmt->execute(['allow_registration', $allowRegistration, $_SESSION['user_id']]);

                    $logStmt = $db->prepare("INSERT INTO system_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
                    $logStmt->execute([$_SESSION['user_id'], 'update_system_settings', 'settings', 'System settings updated', $_SERVER['REMOTE_ADDR'] ?? '']);

                    $message = 'Einstellungen erfolgreich gespeichert';
                }
            } catch (\Exception $e) {
                $error = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        }

        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
            $settingsRaw = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            $settingsRaw = [];
        }

        $this->render('admin/settings.twig', [
            'settings'  => $settingsRaw,
            'message'   => $message,
            'error'     => $error,
            'csrfToken' => \CSRFHandler::getToken(),
        ]);
    }

    public function stats(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::canViewAdminStats()) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $userDb    = \Database::getUserDB();
        $contentDb = \Database::getContentDB();

        $stmt = $userDb->query("SELECT COUNT(*) as active_users FROM users WHERE last_login IS NOT NULL AND last_login > DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL");
        $activeUsersCount = $stmt->fetch()['active_users'] ?? 0;

        $stmt = $userDb->query("SELECT COUNT(*) as active_users_prev FROM users WHERE last_login IS NOT NULL AND DATE(last_login) BETWEEN DATE(DATE_SUB(NOW(), INTERVAL 14 DAY)) AND DATE(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND deleted_at IS NULL");
        $activeUsersPrev  = $stmt->fetch()['active_users_prev'] ?? 0;

        $stmt = $userDb->query("SELECT COUNT(*) as total_users FROM users WHERE deleted_at IS NULL");
        $totalUsersCount = $stmt->fetch()['total_users'] ?? 0;

        $stmt = $userDb->query("SELECT COUNT(*) as new_users FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL");
        $newUsersCount = $stmt->fetch()['new_users'] ?? 0;

        $recentActivity = [];
        try {
            $stmt           = $userDb->query("SELECT id, email, last_login, created_at FROM users WHERE deleted_at IS NULL AND last_login IS NOT NULL ORDER BY last_login DESC LIMIT 20");
            $recentActivity = $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log('admin stats recentActivity: ' . $e->getMessage());
        }

        $this->render('admin/stats.twig', [
            'activeUsersCount' => $activeUsersCount,
            'activeUsersPrev'  => $activeUsersPrev,
            'totalUsersCount'  => $totalUsersCount,
            'newUsersCount'    => $newUsersCount,
            'recentActivity'   => $recentActivity,
        ]);
    }

    public function audit(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::isBoard()) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $db     = \Database::getContentDB();
        $limit  = 100;
        $page   = intval($_GET['page'] ?? 1);
        $offset = ($page - 1) * $limit;

        $params = [];
        $sql    = "SELECT * FROM system_logs WHERE 1=1";

        if (!empty($_GET['action'])) {
            $sql      .= " AND action LIKE ?";
            $params[]  = '%' . $_GET['action'] . '%';
        }
        if (!empty($_GET['user_id'])) {
            $sql      .= " AND user_id = ?";
            $params[]  = $_GET['user_id'];
        }

        $sql      .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        $params[]  = $limit;
        $params[]  = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        $countSql    = "SELECT COUNT(*) as total FROM system_logs WHERE 1=1";
        $countParams = [];
        if (!empty($_GET['action'])) {
            $countSql    .= " AND action LIKE ?";
            $countParams[] = '%' . $_GET['action'] . '%';
        }
        if (!empty($_GET['user_id'])) {
            $countSql    .= " AND user_id = ?";
            $countParams[] = $_GET['user_id'];
        }

        $stmt       = $db->prepare($countSql);
        $stmt->execute($countParams);
        $totalLogs  = $stmt->fetch()['total'];
        $totalPages = ceil($totalLogs / $limit);

        $this->render('admin/audit.twig', [
            'logs'       => $logs,
            'page'       => $page,
            'totalPages' => $totalPages,
            'totalLogs'  => $totalLogs,
        ]);
    }

    public function inventory(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::isBoard() && !\Auth::hasRole(['alumni_finanz', 'alumni_vorstand'])) {
            $this->redirect(\BASE_URL . '/login');
        }

        $allowedFilters = ['all', 'rented'];
        $filter         = in_array($_GET['filter'] ?? '', $allowedFilters) ? $_GET['filter'] : 'all';

        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $csvItems = \Inventory::getAll();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="inventar_' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Artikel', 'Kategorie', 'Gesamtbestand', 'Ausgeliehen', 'Verfügbar', 'Einheit'], ';');
            foreach ($csvItems as $item) {
                fputcsv($out, [
                    \sanitizeCsvValue($item['name']),
                    \sanitizeCsvValue($item['category_name'] ?? ''),
                    $item['quantity'],
                    $item['quantity'] - $item['available_quantity'],
                    $item['available_quantity'],
                    \sanitizeCsvValue($item['unit']),
                ], ';');
            }
            fclose($out);
            return;
        }

        $checkedOutStats = \Inventory::getCheckedOutStats();
        $activeRentals   = array_filter($checkedOutStats['checkouts'], fn($r) => $r['status'] === 'rented');
        $pendingRequests = \Inventory::getPendingRequests();
        $allItems        = \Inventory::getAll();

        $this->render('admin/inventory_dashboard.twig', [
            'filter'          => $filter,
            'activeRentals'   => array_values($activeRentals),
            'pendingRequests' => $pendingRequests,
            'allItems'        => $allItems,
            'csrfToken'       => \CSRFHandler::getToken(),
        ]);
    }

    public function alumniRequests(array $vars = []): void
    {
        $this->requireAuth();
        $allowedRoles = ['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            return;
        }

        $requests  = \AlumniAccessRequest::getAll();
        $counts    = \AlumniAccessRequest::countByStatus();
        $csrfToken = \CSRFHandler::getToken();

        $this->render('admin/alumni_requests.twig', [
            'requests'  => $requests,
            'counts'    => $counts,
            'csrfToken' => $csrfToken,
        ]);
    }

    public function neueAlumniRequests(array $vars = []): void
    {
        $this->requireAuth();
        $allowedRoles = ['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            return;
        }

        $requests  = \NewAlumniRequest::getAll();
        $counts    = \NewAlumniRequest::countByStatus();
        $csrfToken = \CSRFHandler::getToken();

        $this->render('admin/neue_alumni_requests.twig', [
            'requests'  => $requests,
            'counts'    => $counts,
            'csrfToken' => $csrfToken,
        ]);
    }

    public function vcards(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::canCreateBasicContent()) {
            $this->redirect(\BASE_URL . '/login');
        }

        $vcardError = null;
        try {
            $vcards = \VCard::getAll();
        } catch (\Exception $e) {
            error_log("VCard page: failed to load vCards – " . $e->getMessage());
            $vcards     = [];
            $vcardError = 'Die Verbindung zur vCard-Datenbank ist fehlgeschlagen. Bitte später erneut versuchen.';
        }

        $this->render('admin/vcards.twig', [
            'vcards'     => $vcards,
            'vcardError' => $vcardError,
            'csrfToken'  => \CSRFHandler::getToken(),
        ]);
    }

    public function projectApplications(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::isBoard()) {
            $this->redirect(\BASE_URL . '/login');
        }

        $message = '';
        $error   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_application'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $applicationId = intval($_POST['application_id'] ?? 0);
            $role          = $_POST['role'] ?? 'member';

            if (!in_array($role, ['lead', 'member'])) {
                $error = 'Ungültige Rolle ausgewählt';
            } else {
                try {
                    $db          = \Database::getContentDB();
                    $stmt        = $db->prepare("SELECT * FROM project_applications WHERE id = ?");
                    $stmt->execute([$applicationId]);
                    $application = $stmt->fetch();

                    if (!$application) {
                        throw new \Exception('Bewerbung nicht gefunden');
                    }
                    if ($application['status'] === 'accepted') {
                        throw new \Exception('Diese Bewerbung wurde bereits akzeptiert');
                    }

                    $projectId = $application['project_id'];
                    $project   = \Project::getById($projectId);
                    if (!$project) {
                        throw new \Exception('Projekt nicht gefunden');
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
                $stmt = $db->prepare("UPDATE project_applications SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$applicationId]);
                $message = 'Bewerbung abgelehnt';
            } catch (\Exception $e) {
                $error = 'Fehler: ' . $e->getMessage();
            }
        }

        $db           = \Database::getContentDB();
        $stmt         = $db->query("SELECT pa.*, p.title as project_title, u.email FROM project_applications pa LEFT JOIN projects p ON p.id = pa.project_id LEFT JOIN users u ON u.id = pa.user_id ORDER BY pa.created_at DESC");
        $applications = $stmt->fetchAll();

        $this->render('admin/project_applications.twig', [
            'applications' => $applications,
            'message'      => $message,
            'error'        => $error,
            'csrfToken'    => \CSRFHandler::getToken(),
        ]);
    }

    public function dbMaintenance(array $vars = []): void
    {
        $this->requireAuth();
        if (!\Auth::isBoard()) {
            $this->redirect(\BASE_URL . '/login');
        }

        $message      = '';
        $error        = '';
        $actionResult = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                if (isset($_POST['clean_logs'])) {
                    $userDb    = \Database::getUserDB();
                    $contentDb = \Database::getContentDB();

                    $stmt = $userDb->prepare("DELETE FROM user_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                    $stmt->execute();
                    $sessionsDeleted = $stmt->rowCount();

                    $stmt = $contentDb->prepare("DELETE FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
                    $stmt->execute();
                    $systemLogsDeleted = $stmt->rowCount();

                    $stmt = $contentDb->prepare("DELETE FROM inventory_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
                    $stmt->execute();
                    $inventoryHistoryDeleted = $stmt->rowCount();

                    $stmt = $contentDb->prepare("DELETE FROM event_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
                    $stmt->execute();
                    $eventHistoryDeleted = $stmt->rowCount();

                    $actionResult = [
                        'type'    => 'success',
                        'title'   => 'Logs bereinigt',
                        'details' => [
                            "User Sessions gelöscht: $sessionsDeleted",
                            "System Logs gelöscht: $systemLogsDeleted",
                            "Inventory History gelöscht: $inventoryHistoryDeleted",
                            "Event History gelöscht: $eventHistoryDeleted",
                        ],
                    ];

                    $logStmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
                    $logStmt->execute([$_SESSION['user_id'], 'db_maintenance_clean_logs', 'system', json_encode($actionResult), $_SERVER['REMOTE_ADDR'] ?? '']);
                    $message = 'Datenbank erfolgreich bereinigt';
                }
            } catch (\Exception $e) {
                $error = 'Fehler: ' . $e->getMessage();
            }
        }

        $this->render('admin/db_maintenance.twig', [
            'message'      => $message,
            'error'        => $error,
            'actionResult' => $actionResult,
            'csrfToken'    => \CSRFHandler::getToken(),
        ]);
    }

    public function createVcard(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        $allowedRoles = [\Auth::ROLE_BOARD_FINANCE, \Auth::ROLE_BOARD_INTERNAL, \Auth::ROLE_BOARD_EXTERNAL, \Auth::ROLE_HEAD];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $vorname  = trim($_POST['vorname'] ?? '');
        $nachname = trim($_POST['nachname'] ?? '');

        if (empty($vorname) || empty($nachname)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Vorname und Nachname sind erforderlich']);
        }

        $data = [
            'vorname'    => $vorname,
            'nachname'   => $nachname,
            'rolle'      => trim($_POST['rolle'] ?? ''),
            'funktion'   => trim($_POST['funktion'] ?? ''),
            'telefon'    => trim($_POST['telefon'] ?? ''),
            'email'      => trim($_POST['email'] ?? ''),
            'linkedin'   => trim($_POST['linkedin'] ?? ''),
            'profilbild' => null,
        ];

        if (isset($_FILES['profilbild']) && $_FILES['profilbild']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = \SecureImageUpload::uploadImage($_FILES['profilbild']);
            if ($uploadResult['success']) {
                $data['profilbild'] = $uploadResult['path'];
            }
        }

        try {
            $id = \VCard::create($data);
            $this->json(['success' => true, 'id' => $id]);
        } catch (\Exception $e) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteVcard(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        $allowedRoles = [\Auth::ROLE_BOARD_FINANCE, \Auth::ROLE_BOARD_INTERNAL, \Auth::ROLE_BOARD_EXTERNAL, \Auth::ROLE_HEAD];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige ID']);
        }

        try {
            \VCard::delete($id);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateVcard(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        $allowedRoles = [\Auth::ROLE_BOARD_FINANCE, \Auth::ROLE_BOARD_INTERNAL, \Auth::ROLE_BOARD_EXTERNAL, \Auth::ROLE_HEAD];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige ID']);
        }

        $data = [
            'vorname'  => trim($_POST['vorname'] ?? ''),
            'nachname' => trim($_POST['nachname'] ?? ''),
            'rolle'    => trim($_POST['rolle'] ?? ''),
            'telefon'  => trim($_POST['telefon'] ?? ''),
            'email'    => trim($_POST['email'] ?? ''),
            'linkedin' => trim($_POST['linkedin'] ?? ''),
        ];

        if (isset($_FILES['profilbild']) && $_FILES['profilbild']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = \SecureImageUpload::uploadImage($_FILES['profilbild']);
            if ($uploadResult['success']) {
                $data['profilbild'] = $uploadResult['path'];
            }
        }

        try {
            \VCard::update($id, $data);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function processAlumniRequest(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        $allowedRoles = [\Auth::ROLE_BOARD_FINANCE, \Auth::ROLE_BOARD_INTERNAL, \Auth::ROLE_BOARD_EXTERNAL, \Auth::ROLE_ALUMNI_BOARD, \Auth::ROLE_ALUMNI_AUDITOR];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $action    = $_POST['action'] ?? '';

        if ($requestId <= 0 || !in_array($action, ['approve', 'reject'])) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Parameter']);
        }

        try {
            $result = \AlumniAccessRequest::process($requestId, $action, \Auth::user());
            $this->json($result);
        } catch (\Exception $e) {
            error_log('processAlumniRequest: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function processNeueAlumniRequest(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        $allowedRoles = [\Auth::ROLE_BOARD_FINANCE, \Auth::ROLE_BOARD_INTERNAL, \Auth::ROLE_BOARD_EXTERNAL, \Auth::ROLE_ALUMNI_BOARD, \Auth::ROLE_ALUMNI_AUDITOR];
        if (!\Auth::hasRole($allowedRoles)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $action    = $_POST['action'] ?? '';

        if ($requestId <= 0 || !in_array($action, ['approve', 'reject'])) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Parameter']);
        }

        try {
            $result = \NewAlumniRequest::process($requestId, $action, \Auth::user());
            $this->json($result);
        } catch (\Exception $e) {
            error_log('processNeueAlumniRequest: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateUserRole(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\AuthHandler::isAuthenticated() || !\AuthHandler::canManageUsers()) {
            $this->json(['success' => false, 'message' => 'Nicht autorisiert']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Ungültige Anfrage']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $userId  = intval($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';

        if ($userId <= 0) {
            $this->json(['success' => false, 'message' => 'Ungültige Benutzer-ID']);
        }
        if (!in_array($newRole, \Auth::VALID_ROLES, true)) {
            $this->json(['success' => false, 'message' => 'Ungültige Rolle']);
        }
        if ($userId === intval($_SESSION['user_id'] ?? 0)) {
            $this->json(['success' => false, 'message' => 'Du kannst Deine eigene Rolle nicht ändern']);
        }

        $entraWarning = null;
        try {
            $db   = \Database::getUserDB();
            $stmt = $db->prepare("SELECT azure_oid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row     = $stmt->fetch();
            $azureOid = $row['azure_oid'] ?? null;

            if ($azureOid) {
                $graphService = new \MicrosoftGraphService();
                $graphService->updateUserRole($azureOid, $newRole);
            }
        } catch (\Exception $e) {
            error_log('updateUserRole Entra sync failed: ' . $e->getMessage());
            $entraWarning = 'Rolle lokal aktualisiert, aber Entra-Sync fehlgeschlagen: ' . $e->getMessage();
        }

        if (\User::update($userId, ['role' => $newRole])) {
            $response = ['success' => true];
            if ($entraWarning) {
                $response['warning'] = $entraWarning;
            }
            $this->json($response);
        } else {
            $this->json(['success' => false, 'message' => 'Datenbankfehler']);
        }
    }

    public function searchEntraUsers(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json');

        if (!\Auth::canManageUsers()) {
            http_response_code(403);
            $this->json(['error' => 'Unauthorized']);
        }

        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) {
            $this->json(['users' => []]);
        }

        try {
            $graphService = new \MicrosoftGraphService();
            $users        = $graphService->searchUsers($query);
            $this->json(['users' => $users]);
        } catch (\Exception $e) {
            error_log('Entra user search failed: ' . $e->getMessage());
            http_response_code(500);
            $this->json(['error' => 'Suche fehlgeschlagen. Bitte prüfe die Azure-Konfiguration.']);
        }
    }
}
