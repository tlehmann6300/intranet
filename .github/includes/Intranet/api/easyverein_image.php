<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';

// Authentication check
if (!Auth::check()) {
    http_response_code(401);
    exit;
}

// Security check: Only allow EasyVerein URLs with strict hostname validation
$url = $_GET['url'] ?? '';
if (empty($url)) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

$parsedUrl = parse_url($url);
$scheme    = strtolower($parsedUrl['scheme'] ?? '');
$host      = strtolower($parsedUrl['host']   ?? '');

// Require HTTPS and ensure the host is easyverein.com or a subdomain of it
if (
    $scheme !== 'https' ||
    ($host !== 'easyverein.com' && !str_ends_with($host, '.easyverein.com'))
) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

// Get API Token from config or env
$token = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : ($_ENV['EASYVEREIN_API_TOKEN'] ?? '');

// Validate token exists
if (empty($token)) {
    header("HTTP/1.0 500 Internal Server Error");
    exit;
}

// Init cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $token"
]);
// Disable SSL check strictly for debugging if needed, otherwise keep true
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$data = curl_exec($ch);
$rawContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Strip charset/boundary parameters: "image/jpeg; charset=utf-8" → "image/jpeg"
$mimeOnly = strtolower(trim(explode(';', $rawContentType)[0]));

// Strict whitelist – only known image MIME types; prevents response header injection
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if ($httpCode === 200 && $data !== false && strlen($data) > 0 && in_array($mimeOnly, $allowedMimes, true)) {
    header('Content-Type: ' . $mimeOnly);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    echo $data;
} else {
    // Fallback – serve the local placeholder image
    header('Location: /assets/img/ibc_logo_original.webp');
}
