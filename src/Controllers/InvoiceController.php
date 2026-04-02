<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class InvoiceController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        if (!\Auth::canAccessPage('invoices')) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $userRole        = $user['role'] ?? '';
        $canViewTable    = in_array($userRole, ['vorstand_intern', 'vorstand_extern', 'alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen']);
        $canEditInvoices = ($userRole === 'vorstand_finanzen');
        $canSubmitInvoice = in_array($userRole, ['alumni', 'ehrenmitglied', 'anwaerter', 'mitglied', 'ressortleiter', 'vorstand_finanzen']);
        $canMarkAsPaid   = $canEditInvoices;

        $invoices    = [];
        $stats       = null;
        if ($canViewTable) {
            $invoices = \Invoice::getAll($userRole, $user['id']);
            $stats    = \Invoice::getStats();
        }

        $userDb      = \Database::getUserDB();
        $userInfoMap = [];
        if (!empty($invoices)) {
            $allUids = array_unique(array_merge(
                array_column($invoices, 'user_id'),
                array_filter(array_column($invoices, 'paid_by_user_id'))
            ));
            if (!empty($allUids)) {
                $ph    = str_repeat('?,', count($allUids) - 1) . '?';
                $uStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
                $uStmt->execute($allUids);
                foreach ($uStmt->fetchAll() as $u) {
                    $userInfoMap[$u['id']] = $u['email'];
                }
            }
        }

        $openInvoices              = array_values(array_filter($invoices, fn($inv) => in_array($inv['status'], ['pending', 'approved'])));
        $overdueThresholdDays      = 14;
        foreach ($invoices as &$inv) {
            $inv['_display_status'] = ($inv['status'] === 'approved' && (time() - strtotime($inv['created_at'])) / 86400 > $overdueThresholdDays)
                ? 'overdue' : $inv['status'];
        }
        unset($inv);

        $summaryOpenAmount    = 0.0;
        $summaryInReviewCount = 0;
        $summaryPaidAmount    = 0.0;
        $summaryPaidCount     = 0;
        foreach ($invoices as $inv) {
            if (in_array($inv['status'], ['pending', 'approved'])) {
                $summaryOpenAmount += (float)$inv['amount'];
            }
            if ($inv['status'] === 'pending') {
                $summaryInReviewCount++;
            }
            if ($inv['status'] === 'paid') {
                $summaryPaidAmount += (float)$inv['amount'];
                $summaryPaidCount++;
            }
        }

        $this->render('invoices/index.twig', [
            'user'                 => $user,
            'userRole'             => $userRole,
            'invoices'             => $invoices,
            'stats'                => $stats,
            'userInfoMap'          => $userInfoMap,
            'openInvoices'         => $openInvoices,
            'canViewTable'         => $canViewTable,
            'canEditInvoices'      => $canEditInvoices,
            'canSubmitInvoice'     => $canSubmitInvoice,
            'canMarkAsPaid'        => $canMarkAsPaid,
            'summaryOpenAmount'    => $summaryOpenAmount,
            'summaryInReviewCount' => $summaryInReviewCount,
            'summaryPaidAmount'    => $summaryPaidAmount,
            'summaryPaidCount'     => $summaryPaidCount,
            'csrfToken'            => \CSRFHandler::getToken(),
        ]);
    }

    public function submit(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        $hasInvoiceSubmitAccess = in_array($user['role'], [
            'alumni', 'ehrenmitglied', 'anwaerter', 'mitglied', 'ressortleiter', 'vorstand_finanzen',
        ]);
        if (!$hasInvoiceSubmitAccess) {
            $_SESSION['error_message'] = 'Keine Berechtigung';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['error_message'] = 'Methode nicht erlaubt';
            $this->redirect(\BASE_URL . '/invoices');
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $amount      = $_POST['amount'] ?? null;
        $description = $_POST['description'] ?? null;
        $date        = $_POST['date'] ?? null;

        if (empty($amount) || empty($description) || empty($date)) {
            $_SESSION['error_message'] = 'Alle Felder sind erforderlich';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $_SESSION['error_message'] = 'Ungültiger Betrag';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if (!isset($_FILES['file'])) {
            $_SESSION['error_message'] = 'Datei-Upload fehlgeschlagen';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
            $_SESSION['error_message'] = 'Datei ist zu groß. Maximum: 10MB';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = 'Fehler beim Hochladen der Datei';
            $this->redirect(\BASE_URL . '/invoices');
        }

        $file     = $_FILES['file'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            $_SESSION['error_message'] = 'Ungültiger Dateityp. Nur PDF und Bilder sind erlaubt.';
            $this->redirect(\BASE_URL . '/invoices');
        }

        if ($file['size'] > 10485760) {
            $_SESSION['error_message'] = 'Datei ist zu groß. Maximum: 10MB';
            $this->redirect(\BASE_URL . '/invoices');
        }

        $uploadDir = __DIR__ . '/../../uploads/invoices/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'invoice_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
        $filePath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $_SESSION['error_message'] = 'Fehler beim Speichern der Datei';
            $this->redirect(\BASE_URL . '/invoices');
        }

        $relPath  = 'uploads/invoices/' . $filename;
        $invoiceId = \Invoice::create([
            'user_id'     => $user['id'],
            'amount'      => $amount,
            'description' => $description,
            'date'        => $date,
            'file_path'   => $relPath,
        ]);

        if ($invoiceId) {
            $_SESSION['success_message'] = 'Rechnung erfolgreich eingereicht';
        } else {
            $_SESSION['error_message'] = 'Fehler beim Speichern der Rechnung';
        }

        $this->redirect(\BASE_URL . '/invoices');
    }

    public function markPaid(array $vars = []): void
    {
        $this->requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'error' => 'Methode nicht erlaubt']);
            return;
        }

        if (!\Auth::canManageInvoices()) {
            http_response_code(403);
            $this->json(['success' => false, 'error' => 'Keine Berechtigung - nur Vorstand Finanzen und Recht']);
            return;
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
        if (empty($invoiceId)) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Invoice ID erforderlich']);
        }

        $invoice = \Invoice::getById($invoiceId);
        if (!$invoice) {
            http_response_code(404);
            $this->json(['success' => false, 'error' => 'Rechnung nicht gefunden']);
        }

        if ($invoice['status'] !== 'approved') {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Nur genehmigte Rechnungen können als bezahlt markiert werden']);
        }

        $user = \Auth::user();
        if (\Invoice::markAsPaid($invoiceId, $user['id'])) {
            $this->json(['success' => true]);
        } else {
            http_response_code(500);
            $this->json(['success' => false, 'error' => 'Fehler beim Markieren der Rechnung']);
        }
    }

    public function updateStatus(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';

        if ($userRole !== 'vorstand_finanzen') {
            http_response_code(403);
            $this->json(['success' => false, 'error' => 'Keine Berechtigung']);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'error' => 'Methode nicht erlaubt']);
        }

        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
        $status    = $_POST['status'] ?? null;
        $reason    = $_POST['reason'] ?? null;

        if (empty($invoiceId) || empty($status)) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Invoice ID und Status sind erforderlich']);
        }

        if (!in_array($status, ['pending', 'approved', 'rejected'])) {
            http_response_code(400);
            $this->json(['success' => false, 'error' => 'Ungültiger Status']);
        }

        try {
            $result = \Invoice::updateStatus($invoiceId, $status, $reason);
            if ($result) {
                $this->json(['success' => true]);
            } else {
                http_response_code(500);
                $this->json(['success' => false, 'error' => 'Fehler beim Aktualisieren des Status']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            $this->json(['success' => false, 'error' => 'Server-Fehler']);
        }
    }

    public function exportInvoices(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        $hasInvoiceAccess = \Auth::isBoard() || \Auth::hasRole(['alumni_vorstand', 'alumni_finanz']);
        if (!$hasInvoiceAccess) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $userRole = $user['role'] ?? '';
        $invoices = \Invoice::getAll($userRole, $user['id']);

        if (empty($invoices)) {
            $_SESSION['error_message'] = 'Keine Rechnungen zum Exportieren vorhanden';
            $this->redirect(\BASE_URL . '/invoices');
        }

        $csvFileName     = 'rechnungen_export_' . date('Y-m-d_H-i-s') . '.csv';
        $safeCsvFileName = str_replace(['"', '\\', "\r", "\n"], '', $csvFileName);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeCsvFileName . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Benutzer', 'Beschreibung', 'Betrag (€)', 'Status', 'Ablehnungsgrund', 'Erstellt am', 'Bezahlt am'], ';');

        foreach ($invoices as $invoice) {
            fputcsv($out, [
                \sanitizeCsvValue((string)($invoice['id'] ?? '')),
                \sanitizeCsvValue((string)($invoice['user_email'] ?? '')),
                \sanitizeCsvValue((string)($invoice['description'] ?? '')),
                \sanitizeCsvValue(number_format((float)($invoice['amount'] ?? 0), 2, ',', '.')),
                \sanitizeCsvValue((string)($invoice['status'] ?? '')),
                \sanitizeCsvValue((string)($invoice['rejection_reason'] ?? '')),
                \sanitizeCsvValue((string)($invoice['created_at'] ?? '')),
                \sanitizeCsvValue((string)($invoice['paid_at'] ?? '')),
            ], ';');
        }

        fclose($out);
    }

    public function downloadFile(array $vars = []): void
    {
        $this->requireAuth();
        $currentUser   = \Auth::user();
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $currentRole   = $currentUser['role'] ?? '';

        $invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($invoiceId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            $this->json(['success' => false, 'message' => 'Ungültige Rechnungs-ID']);
        }

        $invoice = \Invoice::getById($invoiceId);
        if (!$invoice) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Rechnung nicht gefunden']);
        }

        $privilegedRoles = array_merge(\Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']);
        $isOwner         = ((int)$invoice['user_id'] === $currentUserId);
        $isPrivileged    = in_array($currentRole, $privilegedRoles, true);

        if (!$isOwner && !$isPrivileged) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Zugriff verweigert']);
        }

        $relativePath = $invoice['file_path'] ?? '';
        if (empty($relativePath)) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Keine Datei für diese Rechnung vorhanden']);
        }

        $invoicesDir = realpath(__DIR__ . '/../../uploads/invoices');
        if ($invoicesDir === false) {
            http_response_code(500);
            $this->json(['success' => false, 'message' => 'Server-Konfigurationsfehler']);
        }

        $safeBasename = basename($relativePath);
        $filePath     = $invoicesDir . DIRECTORY_SEPARATOR . $safeBasename;
        $realFilePath = realpath($filePath);

        if ($realFilePath === false || !str_starts_with($realFilePath, $invoicesDir . DIRECTORY_SEPARATOR)) {
            http_response_code(403);
            $this->json(['success' => false, 'message' => 'Zugriff verweigert']);
        }

        if (!file_exists($realFilePath)) {
            http_response_code(404);
            $this->json(['success' => false, 'message' => 'Datei nicht gefunden']);
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $realFilePath);
        finfo_close($finfo);

        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $safeBasename);

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . filesize($realFilePath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($realFilePath);
    }
}
