<?php
/**
 * Authentifizierter Download-Proxy für Bewerbungsdateien (Profilbild / Lebenslauf).
 *
 * Die Dateien liegen im öffentlichen Karriere-Portal (Schwester-Ordner
 * `karriere/`, parallel zu `intra/`), dessen Upload-Ordner per .htaccess
 * gegen direkten Web-Zugriff gesperrt ist. Dieser Endpunkt liest sie
 * serverseitig vom Dateisystem und liefert sie NUR an berechtigte
 * Intranet-Nutzer aus (Vorstand + ERW-Mitglieder).
 *
 * Sicherheit:
 *  - Auth- und Rollenprüfung
 *  - strikte Pfad-Validierung (nur uploads/profilbilder & uploads/lebenslaeufe,
 *    realpath-Eingrenzung gegen Path-Traversal)
 *  - Whitelist der erlaubten Dateitypen (jpg/png/pdf)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

if (!Auth::check() || !Auth::canAccessAdminArea()) {
    http_response_code(403);
    exit('Zugriff verweigert');
}

$rel = (string) ($_GET['f'] ?? '');

// Nur die beiden erlaubten Unterordner zulassen, keine Traversal-Zeichen.
if ($rel === ''
    || strpos($rel, '..') !== false
    || strpos($rel, "\0") !== false
    || !preg_match('#^uploads/(profilbilder|lebenslaeufe)/[A-Za-z0-9._-]+$#', $rel)) {
    http_response_code(400);
    exit('Ungültiger Pfad');
}

// Basisverzeichnis = Schwester-Ordner karriere/
$karriereRoot = realpath(__DIR__ . '/../../karriere');
if ($karriereRoot === false) {
    http_response_code(404);
    exit('Karriere-Verzeichnis nicht gefunden');
}

$full = realpath($karriereRoot . '/' . $rel);
if ($full === false || strpos($full, $karriereRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit('Datei nicht gefunden');
}

// Dateityp-Whitelist (zusätzlich zum Upload-seitigen Schutz)
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
if (!isset($mimeMap[$ext])) {
    http_response_code(415);
    exit('Dateityp nicht erlaubt');
}

// Inhalt zusätzlich verifizieren (Magic/Bild), bevor ausgeliefert wird.
if ($ext === 'pdf') {
    $fh = fopen($full, 'rb');
    $magic = $fh ? fread($fh, 5) : '';
    if ($fh) { fclose($fh); }
    if ($magic !== '%PDF-') {
        http_response_code(415);
        exit('Ungültige Datei');
    }
} else {
    $info = @getimagesize($full);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        http_response_code(415);
        exit('Ungültige Datei');
    }
}

header('Content-Type: ' . $mimeMap[$ext]);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($full);
