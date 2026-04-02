<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class NewsletterController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $currentUser = \Auth::user();
        $canManage   = \Newsletter::canManage($currentUser['role'] ?? '');
        $error       = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && $canManage) {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $nlTitle   = trim($_POST['title'] ?? '');
            $monthYear = trim($_POST['month_year'] ?? '');

            if ($nlTitle === '') {
                $error = 'Bitte geben Sie einen Titel an.';
            } elseif (!isset($_FILES['newsletter_file']) || $_FILES['newsletter_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Bitte wählen Sie eine Datei aus.';
            } else {
                $file = $_FILES['newsletter_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Fehler beim Hochladen der Datei (Code ' . $file['error'] . ').';
                } elseif ($file['size'] > 20971520) {
                    $error = 'Die Datei überschreitet die maximale Größe von 20 MB.';
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['eml'], true)) {
                        $error = 'Nur .eml-Dateien sind erlaubt.';
                    } else {
                        $uploadDir   = __DIR__ . '/../../uploads/newsletters/';
                        $filename    = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $destination = $uploadDir . $filename;

                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $error = 'Die Datei konnte nicht gespeichert werden.';
                        } else {
                            try {
                                \Newsletter::create([
                                    'title'       => $nlTitle,
                                    'month_year'  => $monthYear !== '' ? $monthYear : null,
                                    'file_path'   => $filename,
                                    'uploaded_by' => $currentUser['id'],
                                ]);
                                $_SESSION['success_message'] = 'Newsletter erfolgreich hochgeladen.';
                                $this->redirect(\BASE_URL . '/newsletter');
                            } catch (\Exception $e) {
                                @unlink($destination);
                                $error = 'Fehler beim Speichern in der Datenbank.';
                            }
                        }
                    }
                }
            }
        }

        $newsletters    = \Newsletter::getAll();
        $successMessage = $_SESSION['success_message'] ?? null;
        unset($_SESSION['success_message']);

        $this->render('newsletter/index.twig', [
            'currentUser'    => $currentUser,
            'canManage'      => $canManage,
            'newsletters'    => $newsletters,
            'error'          => $error,
            'successMessage' => $successMessage,
            'csrfToken'      => \CSRFHandler::getToken(),
        ]);
    }

    public function download(array $vars = []): void
    {
        $this->requireAuth();

        $newsletterId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($newsletterId <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Newsletter-ID']);
        }

        $newsletter = \Newsletter::getById($newsletterId);
        if (!$newsletter) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Newsletter nicht gefunden']);
        }

        $newslettersDir = realpath(__DIR__ . '/../../uploads/newsletters');
        if ($newslettersDir === false) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Server-Konfigurationsfehler']);
        }

        $safeBasename = basename($newsletter['file_path'] ?? '');
        if ($safeBasename === '') {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Datei nicht gefunden']);
        }

        $filePath     = $newslettersDir . DIRECTORY_SEPARATOR . $safeBasename;
        $realFilePath = realpath($filePath);

        if ($realFilePath === false || !str_starts_with($realFilePath, $newslettersDir . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Zugriff verweigert']);
        }

        if (!file_exists($realFilePath)) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Datei nicht auf dem Server gefunden']);
        }

        $safeTitle    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $newsletter['title'] ?? 'newsletter');
        $dlFilename   = $safeTitle . '.eml';

        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . $dlFilename . '"');
        header('Content-Length: ' . filesize($realFilePath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($realFilePath);
    }

    public function downloadAttachment(array $vars = []): void
    {
        $this->requireAuth();

        $newsletterId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($newsletterId <= 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültige Newsletter-ID']);
        }

        if (!isset($_GET['index']) || !ctype_digit((string)$_GET['index'])) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiger Anhang-Index']);
        }
        $attachmentIndex = (int)$_GET['index'];
        if ($attachmentIndex < 0) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Ungültiger Anhang-Index']);
        }

        $newsletter = \Newsletter::getById($newsletterId);
        if (!$newsletter) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Newsletter nicht gefunden']);
        }

        $newslettersDir = realpath(__DIR__ . '/../../uploads/newsletters');
        if ($newslettersDir === false) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Server-Konfigurationsfehler']);
        }

        $safeBasename = basename($newsletter['file_path'] ?? '');
        $filePath     = $newslettersDir . DIRECTORY_SEPARATOR . $safeBasename;
        $realFilePath = realpath($filePath);

        if ($realFilePath === false || !str_starts_with($realFilePath, $newslettersDir . DIRECTORY_SEPARATOR) || !file_exists($realFilePath)) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Datei nicht gefunden']);
        }

        $message     = \ZBateson\MailMimeParser\Message::from(file_get_contents($realFilePath), false);
        $attachments = $message->getAllAttachmentParts();

        if (!isset($attachments[$attachmentIndex])) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Anhang nicht gefunden']);
        }

        $attachment   = $attachments[$attachmentIndex];
        $filename     = $attachment->getFilename() ?? 'anhang_' . $attachmentIndex;
        $contentType  = $attachment->getContentType() ?? 'application/octet-stream';
        $safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        echo $attachment->getContent();
    }
}
