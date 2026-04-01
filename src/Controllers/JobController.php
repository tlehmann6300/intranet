<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class JobController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user   = \Auth::user();
        $userId = $user['id'];

        $filterType = isset($_GET['type']) && in_array($_GET['type'], \JobBoard::SEARCH_TYPES) ? $_GET['type'] : null;
        $page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage    = 12;
        $offset     = ($page - 1) * $perPage;

        $allListings = \JobBoard::getAll($perPage + 1, $offset, $filterType);
        $hasNextPage = count($allListings) > $perPage;
        $listings    = array_slice($allListings, 0, $perPage);

        $userDb      = \Database::getUserDB();
        $userIds     = array_unique(array_column($listings, 'user_id'));
        $authorNames = [];
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt         = $userDb->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ($placeholders)");
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) {
                $authorNames[$u['id']] = trim($u['first_name'] . ' ' . $u['last_name']);
            }
        }

        $successMessage = $_SESSION['success_message'] ?? null;
        $errorMessage   = $_SESSION['error_message'] ?? null;
        unset($_SESSION['success_message'], $_SESSION['error_message']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            $deleteId = (int)$_POST['delete_id'];
            $listing  = \JobBoard::getById($deleteId);

            if ($listing && (int)$listing['user_id'] === $userId) {
                if (!empty($listing['pdf_path'])) {
                    $pdfFile     = __DIR__ . '/../../' . $listing['pdf_path'];
                    $allowedDir  = realpath(__DIR__ . '/../../uploads/jobs');
                    $realFile    = realpath($pdfFile);
                    if ($realFile !== false && $allowedDir !== false && strpos($realFile, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                        @unlink($realFile);
                    }
                }
                \JobBoard::delete($deleteId);
                $_SESSION['success_message'] = 'Anzeige erfolgreich gelöscht.';
            } else {
                $_SESSION['error_message'] = 'Keine Berechtigung zum Löschen.';
            }
            $this->redirect(\BASE_URL . '/jobs');
        }

        $this->render('jobs/index.twig', [
            'user'           => $user,
            'listings'       => $listings,
            'authorNames'    => $authorNames,
            'filterType'     => $filterType,
            'page'           => $page,
            'hasNextPage'    => $hasNextPage,
            'successMessage' => $successMessage,
            'errorMessage'   => $errorMessage,
            'csrfToken'      => \CSRFHandler::getToken(),
        ]);
    }

    public function create(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userId   = (int)$user['id'];
        $userRole = $user['role'] ?? '';

        $profileCvPath = null;
        if (\isMemberRole($userRole)) {
            $userProfile = \Member::getProfileByUserId($userId);
        } elseif (\isAlumniRole($userRole)) {
            $userProfile = \Alumni::getProfileByUserId($userId);
        } else {
            $userProfile = null;
        }
        if (!empty($userProfile['cv_path'])) {
            $profileCvPath = $userProfile['cv_path'];
        }

        $errors      = [];
        $title       = '';
        $searchType  = '';
        $description = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $rateLimitWait = \checkFormRateLimit('last_job_submit_time');
            if ($rateLimitWait > 0) {
                $errors[] = 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Anzeige erstellst.';
            } else {
                $title       = strip_tags(trim($_POST['title'] ?? ''));
                $searchType  = strip_tags(trim($_POST['search_type'] ?? ''));
                $description = strip_tags(trim($_POST['description'] ?? ''));

                if (empty($title)) {
                    $errors[] = 'Bitte geben Sie einen Titel ein.';
                }
                if (empty($searchType) || !in_array($searchType, \JobBoard::SEARCH_TYPES, true)) {
                    $errors[] = 'Bitte wählen Sie einen gültigen Typ aus.';
                }
                if (empty($description)) {
                    $errors[] = 'Bitte geben Sie eine Beschreibung ein.';
                }

                if (empty($errors)) {
                    $listingData = [
                        'user_id'     => $userId,
                        'title'       => $title,
                        'search_type' => $searchType,
                        'description' => $description,
                    ];

                    $cvSource = $_POST['cv_source'] ?? 'upload';
                    if ($cvSource === 'profile' && $profileCvPath !== null) {
                        $listingData['pdf_path'] = $profileCvPath;
                    } elseif (!empty($_FILES['pdf_file']['name'])) {
                        $pdfFile = $_FILES['pdf_file'];
                        if ($pdfFile['error'] === UPLOAD_ERR_OK) {
                            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                            $pdfMime = finfo_file($finfo, $pdfFile['tmp_name']);
                            finfo_close($finfo);
                            if ($pdfMime === 'application/pdf') {
                                $uploadDir  = __DIR__ . '/../../uploads/jobs/';
                                if (!is_dir($uploadDir)) {
                                    mkdir($uploadDir, 0755, true);
                                }
                                $pdfFilename = 'job_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
                                $pdfPath     = $uploadDir . $pdfFilename;
                                if (move_uploaded_file($pdfFile['tmp_name'], $pdfPath)) {
                                    $listingData['pdf_path'] = 'uploads/jobs/' . $pdfFilename;
                                }
                            }
                        }
                    }

                    $newId = \JobBoard::create($listingData);
                    if ($newId) {
                        $_SESSION['success_message'] = 'Anzeige erfolgreich erstellt.';
                        $this->redirect(\BASE_URL . '/jobs');
                    } else {
                        $errors[] = 'Fehler beim Erstellen der Anzeige.';
                    }
                }
            }
        }

        $this->render('jobs/create.twig', [
            'user'          => $user,
            'profileCvPath' => $profileCvPath,
            'errors'        => $errors,
            'title'         => $title,
            'searchType'    => $searchType,
            'description'   => $description,
            'csrfToken'     => \CSRFHandler::getToken(),
        ]);
    }

    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userId   = (int)$user['id'];
        $userRole = $user['role'] ?? '';

        $listingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $listing   = $listingId > 0 ? \JobBoard::getById($listingId) : null;

        if (!$listing || (int)$listing['user_id'] !== $userId) {
            $_SESSION['error_message'] = 'Die Anzeige wurde nicht gefunden oder du hast keine Berechtigung, sie zu bearbeiten.';
            $this->redirect(\BASE_URL . '/jobs');
        }

        $profileCvPath = null;
        if (\isMemberRole($userRole)) {
            $userProfile = \Member::getProfileByUserId($userId);
        } elseif (\isAlumniRole($userRole)) {
            $userProfile = \Alumni::getProfileByUserId($userId);
        } else {
            $userProfile = null;
        }
        if (!empty($userProfile['cv_path'])) {
            $profileCvPath = $userProfile['cv_path'];
        }

        $errors      = [];
        $title       = $listing['title'];
        $searchType  = $listing['search_type'];
        $description = $listing['description'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $title       = strip_tags(trim($_POST['title'] ?? ''));
            $searchType  = strip_tags(trim($_POST['search_type'] ?? ''));
            $description = strip_tags(trim($_POST['description'] ?? ''));
            $removePdf   = isset($_POST['remove_pdf']) && $_POST['remove_pdf'] === '1';

            if (empty($title)) {
                $errors[] = 'Bitte geben Sie einen Titel ein.';
            }
            if (empty($searchType) || !in_array($searchType, \JobBoard::SEARCH_TYPES, true)) {
                $errors[] = 'Bitte wählen Sie einen gültigen Typ aus.';
            }
            if (empty($description)) {
                $errors[] = 'Bitte geben Sie eine Beschreibung ein.';
            }

            if (empty($errors)) {
                $updateData = [
                    'title'       => $title,
                    'search_type' => $searchType,
                    'description' => $description,
                ];

                if ($removePdf && !empty($listing['pdf_path'])) {
                    $pdfFile    = __DIR__ . '/../../' . $listing['pdf_path'];
                    $allowedDir = realpath(__DIR__ . '/../../uploads/jobs');
                    $realFile   = realpath($pdfFile);
                    if ($realFile !== false && $allowedDir !== false && strpos($realFile, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                        @unlink($realFile);
                    }
                    $updateData['pdf_path'] = null;
                } elseif (!empty($_FILES['pdf_file']['name'])) {
                    $pdfFile = $_FILES['pdf_file'];
                    if ($pdfFile['error'] === UPLOAD_ERR_OK) {
                        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                        $pdfMime = finfo_file($finfo, $pdfFile['tmp_name']);
                        finfo_close($finfo);
                        if ($pdfMime === 'application/pdf') {
                            $uploadDir = __DIR__ . '/../../uploads/jobs/';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0755, true);
                            }
                            $pdfFilename = 'job_' . $userId . '_' . bin2hex(random_bytes(8)) . '.pdf';
                            $pdfPath     = $uploadDir . $pdfFilename;
                            if (move_uploaded_file($pdfFile['tmp_name'], $pdfPath)) {
                                if (!empty($listing['pdf_path'])) {
                                    $oldFile    = __DIR__ . '/../../' . $listing['pdf_path'];
                                    $allowedDir = realpath(__DIR__ . '/../../uploads/jobs');
                                    $realOld    = realpath($oldFile);
                                    if ($realOld !== false && $allowedDir !== false && strpos($realOld, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                                        @unlink($realOld);
                                    }
                                }
                                $updateData['pdf_path'] = 'uploads/jobs/' . $pdfFilename;
                            }
                        }
                    }
                }

                \JobBoard::update($listingId, $updateData);
                $_SESSION['success_message'] = 'Anzeige erfolgreich aktualisiert.';
                $this->redirect(\BASE_URL . '/jobs');
            }
        }

        $this->render('jobs/edit.twig', [
            'user'          => $user,
            'listing'       => $listing,
            'profileCvPath' => $profileCvPath,
            'errors'        => $errors,
            'title'         => $title,
            'searchType'    => $searchType,
            'description'   => $description,
            'csrfToken'     => \CSRFHandler::getToken(),
        ]);
    }

    public function contactListing(array $vars = []): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $rateLimitWait = \checkFormRateLimit('last_job_contact_time');
        if ($rateLimitWait > 0) {
            http_response_code(429);
            $this->json(['success' => false, 'message' => 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Nachricht sendest.']);
        }

        $listingId    = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $message      = trim($_POST['message'] ?? '');

        if ($listingId <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Anzeigen-ID']);
        }

        if (empty($contactEmail) || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Bitte gib eine gültige Kontakt-E-Mail-Adresse an.']);
        }

        if (empty($message)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Nachricht darf nicht leer sein.']);
        }

        $listing = \JobBoard::getById($listingId);
        if (!$listing) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Anzeige nicht gefunden']);
        }

        $userDb = \Database::getUserDB();
        $stmt   = $userDb->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$listing['user_id']]);
        $owner  = $stmt->fetch();

        if (!$owner) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Anzeigen-Ersteller nicht gefunden']);
        }

        $sender    = \Auth::user();
        $emailBody = '<h2>Neue Nachricht zu Ihrer Job-Anzeige</h2>';
        $emailBody .= '<p>Eine Person hat Ihre Anzeige "' . htmlspecialchars($listing['title']) . '" gesehen und möchte Kontakt aufnehmen.</p>';
        $emailBody .= '<p><strong>Kontakt-E-Mail:</strong> ' . htmlspecialchars($contactEmail) . '</p>';
        $emailBody .= '<p><strong>Nachricht:</strong></p>';
        $emailBody .= '<p>' . nl2br(htmlspecialchars($message)) . '</p>';

        $sent = \MailService::sendEmail($owner['email'], 'Kontaktanfrage zu Ihrer Anzeige: ' . $listing['title'], $emailBody);

        if ($sent) {
            $this->json(['success' => true, 'message' => 'Nachricht erfolgreich gesendet.']);
        } else {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Fehler beim Senden der Nachricht.']);
        }
    }
}
