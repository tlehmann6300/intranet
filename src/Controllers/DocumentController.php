<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Utils\SecureImageUpload;
use Twig\Environment;

/**
 * DocumentController
 *
 * Central document archive with version history.
 *
 * Routes:
 *   GET    /documents           → index (list all current documents)
 *   GET    /documents/{id}      → view single document + version history
 *   GET    /documents/create    → upload form (admin/board only)
 *   POST   /documents/create    → process upload (admin/board only)
 *   GET    /documents/{id}/download → download file
 *   POST   /documents/{id}/delete  → soft-delete / deactivate (admin/board only)
 */
class DocumentController extends BaseController
{
    private const UPLOAD_DIR      = __DIR__ . '/../../private/documents/';
    private const ALLOWED_TYPES   = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
    ];
    private const MAX_BYTES          = 20 * 1024 * 1024; // 20 MB
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    private const VALID_CATEGORIES   = ['Satzung', 'Protokoll', 'Vorlage', 'Vertrag', 'Sonstiges'];

    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0750, true);
        }
    }

    // ── List ───────────────────────────────────────────────────────────────

    public function index(array $vars = []): void
    {
        $this->requireAuth();

        $category = $_GET['category'] ?? '';
        $params   = [];
        $where    = 'is_current = 1';

        if ($category !== '' && in_array($category, self::VALID_CATEGORIES, true)) {
            $where   .= ' AND category = ?';
            $params[] = $category;
        }

        $documents = [];
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT d.*, u.firstname, u.lastname
                 FROM documents d
                 LEFT JOIN " . \DB_USER_NAME . ".users u ON u.id = d.uploaded_by
                 WHERE {$where}
                 ORDER BY d.category ASC, d.title ASC"
            );
            $stmt->execute($params);
            $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('DocumentController::index failed: ' . $e->getMessage());
        }

        // Group by category for display
        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc['category']][] = $doc;
        }

        $this->render('documents/index.twig', [
            'grouped'           => $grouped,
            'categories'        => self::VALID_CATEGORIES,
            'selectedCategory'  => $category,
            'canUpload'         => $this->canManage(),
        ]);
    }

    // ── View ───────────────────────────────────────────────────────────────

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $id = (int)($vars['id'] ?? 0);

        $document = $this->fetchDocument($id);
        if (!$document) {
            $this->redirect(\BASE_URL . '/documents');
        }

        // Version history for this title+category
        $versions = [];
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT d.*, u.firstname, u.lastname
                 FROM documents d
                 LEFT JOIN " . \DB_USER_NAME . ".users u ON u.id = d.uploaded_by
                 WHERE d.title = ? AND d.category = ?
                 ORDER BY d.version DESC"
            );
            $stmt->execute([$document['title'], $document['category']]);
            $versions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('DocumentController::view versions failed: ' . $e->getMessage());
        }

        $this->render('documents/view.twig', [
            'document'  => $document,
            'versions'  => $versions,
            'canUpload' => $this->canManage(),
        ]);
    }

    // ── Create / Upload ────────────────────────────────────────────────────

    public function create(array $vars = []): void
    {
        $this->requireAuth();
        if (!$this->canManage()) {
            $this->redirect(\BASE_URL . '/documents');
        }

        $errors  = [];
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
            [$errors, $success] = $this->processUpload();
            if (empty($errors)) {
                $this->redirect(\BASE_URL . '/documents');
            }
        }

        $this->render('documents/create.twig', [
            'categories' => self::VALID_CATEGORIES,
            'csrfToken'  => \CSRFHandler::getToken(),
            'errors'     => $errors,
            'success'    => $success,
        ]);
    }

    // ── Download ──────────────────────────────────────────────────────────

    public function download(array $vars = []): void
    {
        $this->requireAuth();
        $id = (int)($vars['id'] ?? 0);

        $document = $this->fetchDocument($id);
        if (!$document) {
            http_response_code(404);
            echo 'Dokument nicht gefunden';
            exit;
        }

        // Validate file_path: must be a safe filename (hex string + allowed extension only)
        $storedPath = $document['file_path'];
        $safeBasename = basename($storedPath);
        if (!preg_match('/^[0-9a-f]{32}\.[a-z]{2,4}$/i', $safeBasename)) {
            http_response_code(400);
            echo 'Ungültiger Dateipfad';
            exit;
        }

        $filePath = self::UPLOAD_DIR . $safeBasename;
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'Datei nicht gefunden';
            exit;
        }

        $mime = $document['mime_type'] ?? 'application/octet-stream';
        // Only allow safe MIME types in the Content-Type header
        if (!in_array($mime, self::ALLOWED_TYPES, true)) {
            $mime = 'application/octet-stream';
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($document['original_filename']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filePath);
        exit;
    }

    // ── Delete ────────────────────────────────────────────────────────────

    public function delete(array $vars = []): void
    {
        $this->requireAuth();
        if (!$this->canManage()) {
            $this->json(['success' => false, 'message' => 'Keine Berechtigung']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
        $id = (int)($vars['id'] ?? 0);

        $document = $this->fetchDocument($id);
        if (!$document) {
            $this->json(['success' => false, 'message' => 'Dokument nicht gefunden']);
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("UPDATE documents SET is_current = 0 WHERE id = ?");
            $stmt->execute([$id]);
            \App\Services\AuditLogger::log(
                (int)($_SESSION['user_id'] ?? 0),
                'document_delete',
                'document',
                $id,
                'Dokument deaktiviert: ' . $document['title'] . ' v' . $document['version']
            );
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('DocumentController::delete failed: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Datenbankfehler']);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: list<string>, 1: string} [errors, successMessage]
     */
    private function processUpload(): array
    {
        $errors  = [];
        $title    = trim($_POST['title'] ?? '');
        $category = $_POST['category'] ?? '';
        $description = trim($_POST['description'] ?? '');

        if ($title === '') {
            $errors[] = 'Titel ist erforderlich.';
        }
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            $errors[] = 'Ungültige Kategorie.';
        }
        if (empty($_FILES['file']['name'])) {
            $errors[] = 'Bitte wähle eine Datei aus.';
        }

        if (!empty($errors)) {
            return [$errors, ''];
        }

        $file = $_FILES['file'];

        if ($file['size'] > self::MAX_BYTES) {
            return [['Datei zu groß (max. 20 MB).'], ''];
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return [['Dateityp nicht erlaubt. Erlaubt: PDF, Word, Excel, PowerPoint, Text.'], ''];
        }

        // Validate MIME type (using the actual file content, not the client-reported type)
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_TYPES, true)) {
            return [['Dateityp nicht erlaubt. Erlaubt: PDF, Word, Excel, PowerPoint, Text.'], ''];
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = self::UPLOAD_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return [['Fehler beim Speichern der Datei.'], ''];
        }

        try {
            $db     = \Database::getContentDB();
            $userId = (int)($_SESSION['user_id'] ?? 0);

            // Determine next version number for this title+category
            $stmt = $db->prepare(
                "SELECT COALESCE(MAX(version), 0) + 1 as next_version
                 FROM documents WHERE title = ? AND category = ?"
            );
            $stmt->execute([$title, $category]);
            $nextVersion = (int)$stmt->fetchColumn();

            // Mark previous versions as not current
            $stmt = $db->prepare(
                "UPDATE documents SET is_current = 0 WHERE title = ? AND category = ?"
            );
            $stmt->execute([$title, $category]);

            // Insert new version
            $stmt = $db->prepare(
                "INSERT INTO documents (title, category, description, file_path, original_filename, mime_type, file_size, version, is_current, uploaded_by, uploaded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())"
            );
            $stmt->execute([
                $title,
                $category,
                $description ?: null,
                $filename,
                $file['name'],
                $mime,
                $file['size'],
                $nextVersion,
                $userId,
            ]);

            \App\Services\AuditLogger::log(
                $userId,
                'document_upload',
                'document',
                (int)$db->lastInsertId(),
                "Dokument hochgeladen: {$title} (v{$nextVersion}, {$category})"
            );

            return [[], 'Dokument erfolgreich hochgeladen (Version ' . $nextVersion . ').'];
        } catch (\Exception $e) {
            // Remove the uploaded file on DB error
            @unlink($dest);
            error_log('DocumentController::processUpload DB error: ' . $e->getMessage());
            return [['Datenbankfehler beim Speichern des Dokuments.'], ''];
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchDocument(int $id): ?array
    {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\Exception $e) {
            error_log('DocumentController::fetchDocument failed: ' . $e->getMessage());
            return null;
        }
    }

    private function canManage(): bool
    {
        return \Auth::isBoard() || \Auth::hasRole(['manager']);
    }
}
