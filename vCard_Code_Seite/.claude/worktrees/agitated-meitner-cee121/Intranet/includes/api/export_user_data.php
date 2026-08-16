<?php
/**
 * API: GDPR User Data Export
 * Collects all data linked to the authenticated user and offers it as a CSV download.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!Auth::check()) {
    http_response_code(401);
    exit;
}

// Only allow POST requests with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

$user = Auth::user();
$userId = (int)$user['id'];

// ── 1. Profile data ──────────────────────────────────────────────────────────
$sensitiveFields = ['password', 'tfa_secret', 'current_session_id'];
$profile = array_diff_key($user, array_flip($sensitiveFields));

// ── 2. Event sign-ups (slot-based) ───────────────────────────────────────────
$eventSignups = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT es.id, es.event_id, e.title AS event_title, e.start_date,
                es.slot_id, es.helper_type_id, es.role_id, es.created_at
         FROM event_signups es
         LEFT JOIN events e ON e.id = es.event_id
         WHERE es.user_id = ?
         ORDER BY es.created_at DESC"
    );
    $stmt->execute([$userId]);
    $eventSignups = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: event_signups query failed: " . $e->getMessage());
}

// ── 3. Simple event registrations ────────────────────────────────────────────
$eventRegistrations = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT er.id, er.event_id, e.title AS event_title, e.start_date,
                er.status, er.registered_at
         FROM event_registrations er
         LEFT JOIN events e ON e.id = er.event_id
         WHERE er.user_id = ?
         ORDER BY er.registered_at DESC"
    );
    $stmt->execute([$userId]);
    $eventRegistrations = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: event_registrations query failed: " . $e->getMessage());
}

// ── 4. Inventory rentals ─────────────────────────────────────────────────────
$rentals = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT ir.id, ir.item_id, ii.name AS item_name,
                ir.rental_start, ir.rental_end, ir.returned_at,
                ir.status, ir.notes, ir.created_at
         FROM inventory_rentals ir
         LEFT JOIN inventory_items ii ON ii.id = ir.item_id
         WHERE ir.user_id = ?
         ORDER BY ir.created_at DESC"
    );
    $stmt->execute([$userId]);
    $rentals = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: inventory_rentals query failed: " . $e->getMessage());
}

// ── 5. Project applications ──────────────────────────────────────────────────
$projectApplications = [];
try {
    $db = Database::getContentDB();
    $stmt = $db->prepare(
        "SELECT pa.id, pa.project_id, p.title AS project_title,
                pa.status, pa.message, pa.created_at
         FROM project_applications pa
         LEFT JOIN projects p ON p.id = pa.project_id
         WHERE pa.user_id = ?
         ORDER BY pa.created_at DESC"
    );
    $stmt->execute([$userId]);
    $projectApplications = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("export_user_data: project_applications query failed: " . $e->getMessage());
}

// ── Output CSV ────────────────────────────────────────────────────────────────
$filename = 'meine_daten_' . gmdate('Y-m-d_H-i-s') . '.csv';
// Sanitize filename to prevent header injection
$safeFilename = str_replace(['"', '\\', "\r", "\n"], '', $filename);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

$out = fopen('php://output', 'w');
// UTF-8 BOM so that Excel opens the file with the correct encoding
fputs($out, "\xEF\xBB\xBF");

// ── Section: Profil ───────────────────────────────────────────────────────────
fputcsv($out, ['=== Profil ==='], ';');
fputcsv($out, ['Feld', 'Wert'], ';');
foreach ($profile as $key => $value) {
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    fputcsv($out, [sanitizeCsvValue((string)$key), sanitizeCsvValue((string)($value ?? ''))], ';');
}
fputcsv($out, [], ';');

// ── Section: Event-Anmeldungen (Slot-basiert) ─────────────────────────────────
fputcsv($out, ['=== Event-Anmeldungen (Slot-basiert) ==='], ';');
fputcsv($out, ['ID', 'Event-ID', 'Event-Titel', 'Startdatum', 'Slot-ID', 'Helfer-Typ-ID', 'Rollen-ID', 'Erstellt am'], ';');
foreach ($eventSignups as $row) {
    fputcsv($out, [
        sanitizeCsvValue((string)($row['id'] ?? '')),
        sanitizeCsvValue((string)($row['event_id'] ?? '')),
        sanitizeCsvValue((string)($row['event_title'] ?? '')),
        sanitizeCsvValue((string)($row['start_date'] ?? '')),
        sanitizeCsvValue((string)($row['slot_id'] ?? '')),
        sanitizeCsvValue((string)($row['helper_type_id'] ?? '')),
        sanitizeCsvValue((string)($row['role_id'] ?? '')),
        sanitizeCsvValue((string)($row['created_at'] ?? '')),
    ], ';');
}
fputcsv($out, [], ';');

// ── Section: Event-Registrierungen ───────────────────────────────────────────
fputcsv($out, ['=== Event-Registrierungen ==='], ';');
fputcsv($out, ['ID', 'Event-ID', 'Event-Titel', 'Startdatum', 'Status', 'Registriert am'], ';');
foreach ($eventRegistrations as $row) {
    fputcsv($out, [
        sanitizeCsvValue((string)($row['id'] ?? '')),
        sanitizeCsvValue((string)($row['event_id'] ?? '')),
        sanitizeCsvValue((string)($row['event_title'] ?? '')),
        sanitizeCsvValue((string)($row['start_date'] ?? '')),
        sanitizeCsvValue((string)($row['status'] ?? '')),
        sanitizeCsvValue((string)($row['registered_at'] ?? '')),
    ], ';');
}
fputcsv($out, [], ';');

// ── Section: Inventar-Ausleihen ───────────────────────────────────────────────
fputcsv($out, ['=== Inventar-Ausleihen ==='], ';');
fputcsv($out, ['ID', 'Artikel-ID', 'Artikelname', 'Ausleihe von', 'Ausleihe bis', 'Zurückgegeben am', 'Status', 'Notizen', 'Erstellt am'], ';');
foreach ($rentals as $row) {
    fputcsv($out, [
        sanitizeCsvValue((string)($row['id'] ?? '')),
        sanitizeCsvValue((string)($row['item_id'] ?? '')),
        sanitizeCsvValue((string)($row['item_name'] ?? '')),
        sanitizeCsvValue((string)($row['rental_start'] ?? '')),
        sanitizeCsvValue((string)($row['rental_end'] ?? '')),
        sanitizeCsvValue((string)($row['returned_at'] ?? '')),
        sanitizeCsvValue((string)($row['status'] ?? '')),
        sanitizeCsvValue((string)($row['notes'] ?? '')),
        sanitizeCsvValue((string)($row['created_at'] ?? '')),
    ], ';');
}
fputcsv($out, [], ';');

// ── Section: Projektbewerbungen ───────────────────────────────────────────────
fputcsv($out, ['=== Projektbewerbungen ==='], ';');
fputcsv($out, ['ID', 'Projekt-ID', 'Projekttitel', 'Status', 'Nachricht', 'Erstellt am'], ';');
foreach ($projectApplications as $row) {
    fputcsv($out, [
        sanitizeCsvValue((string)($row['id'] ?? '')),
        sanitizeCsvValue((string)($row['project_id'] ?? '')),
        sanitizeCsvValue((string)($row['project_title'] ?? '')),
        sanitizeCsvValue((string)($row['status'] ?? '')),
        sanitizeCsvValue((string)($row['message'] ?? '')),
        sanitizeCsvValue((string)($row['created_at'] ?? '')),
    ], ';');
}

fclose($out);
exit;
