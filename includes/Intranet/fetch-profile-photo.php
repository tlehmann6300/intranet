<?php
/**
 * fetch-profile-photo.php
 *
 * Serves a Microsoft Entra (Azure AD) profile photo for a given user e-mail
 * address. Photos are cached locally for 24 hours to reduce API round-trips.
 *
 * Usage:  fetch-profile-photo.php?email=user@example.com
 *
 * If no ?email= parameter is provided, a built-in default address is used.
 * On any error (bad credentials, no photo, network failure) the script falls
 * back to the application's default profile image or a transparent 1×1 PNG
 * so that <img> tags in the frontend never break.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/Auth.php';

// ---------------------------------------------------------------------------
// Authentication guard – only logged-in users may fetch profile photos.
// This prevents unauthenticated enumeration of Entra user existence.
// ---------------------------------------------------------------------------
if (!Auth::check()) {
    http_response_code(401);
    // Return a transparent 1×1 PNG so <img> tags don't break for unauthenticated callers
    $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAALEwAACxMBAJqcGAAAAA1JREFUCNdjYGBg+A8AAQQAAbWJngcAAAAASUVORK5CYII=');
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo $pixel;
    exit;
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Default e-mail when no ?email= GET parameter is supplied. */
const DEFAULT_EMAIL = 'it@business-consulting.de';

/** Cache directory (relative to this file). */
const CACHE_DIR = __DIR__ . '/assets/img/cache/profile_photos/';

/** How long (seconds) a cached photo remains valid before a fresh fetch. */
const CACHE_TTL = 86400; // 24 hours

/** Fallback image shown when no profile photo is available. */
const DEFAULT_PROFILE_IMG = __DIR__ . '/assets/img/default_profil.png';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Output a transparent 1×1 PNG and terminate.
 */
function serveFallbackPixel(): never
{
    // Minimal valid transparent PNG (68 bytes)
    $pixel = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIA'
        . 'BQABNjN9GQAAAAlwSFlzAAALEwAACxMBAJqcGAAAAA1JREFUCNdjYGBg+A8AAQ'
        . 'QAAbWJngcAAAAASUVORK5CYII='
    );
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo $pixel;
    exit;
}

/**
 * Serve the default profile avatar (PNG) and terminate.
 * Falls back to serveFallbackPixel() when the file is missing.
 */
function serveDefaultAvatar(): never
{
    if (file_exists(DEFAULT_PROFILE_IMG)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        readfile(DEFAULT_PROFILE_IMG);
        exit;
    }
    serveFallbackPixel();
}

/**
 * Map a binary image blob to a MIME type and file extension.
 *
 * @param string $data Raw image bytes
 * @return array{mime: string, ext: string}
 */
function detectImageType(string $data): array
{
    // Use finfo when available (PHP ≥ 5.3 / ext-fileinfo)
    if (function_exists('finfo_open')) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($fi, $data);
        finfo_close($fi);
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (isset($extMap[$mime])) {
            return ['mime' => $mime, 'ext' => $extMap[$mime]];
        }
    }

    // Fallback: inspect magic bytes
    if (substr($data, 0, 2) === "\xFF\xD8") {
        return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    }
    if (substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
        return ['mime' => 'image/png', 'ext' => 'png'];
    }
    if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
        return ['mime' => 'image/webp', 'ext' => 'webp'];
    }
    if (substr($data, 0, 6) === 'GIF87a' || substr($data, 0, 6) === 'GIF89a') {
        return ['mime' => 'image/gif', 'ext' => 'gif'];
    }

    // Unknown – treat as JPEG (Graph API returns JPEG by default)
    return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
}

/**
 * Build a safe cache filename from an e-mail address.
 * All characters that are not alphanumeric, dot, underscore or hyphen are
 * replaced with an underscore to prevent path-traversal attacks.
 *
 * @param string $email Validated e-mail address
 * @return string       Safe base filename (without extension)
 */
function emailToCacheBase(string $email): string
{
    return preg_replace('/[^a-zA-Z0-9._\-]/', '_', strtolower($email));
}

/**
 * Attempt to read a cached photo that is still within CACHE_TTL.
 * Returns the raw bytes on success, or null when there is no valid cache.
 *
 * @param string $base Safe base filename (from emailToCacheBase())
 * @return array{data: string, mime: string}|null
 */
function readCache(string $base): ?array
{
    // Try all supported extensions
    foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
        $path = CACHE_DIR . $base . '.' . $ext;
        if (file_exists($path) && (time() - filemtime($path)) < CACHE_TTL) {
            $data = file_get_contents($path);
            if ($data !== false && strlen($data) > 0) {
                $type = detectImageType($data);
                return ['data' => $data, 'mime' => $type['mime']];
            }
        }
    }
    return null;
}

/**
 * Write raw image bytes to the cache.
 *
 * @param string $base Safe base filename
 * @param string $data Raw image bytes
 * @param string $ext  File extension (jpg|png|webp|gif)
 */
function writeCache(string $base, string $data, string $ext): void
{
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0750, true);
    }
    // Remove any previously cached file for this address (all extensions)
    foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
        $old = CACHE_DIR . $base . '.' . $oldExt;
        if (file_exists($old)) {
            @unlink($old);
        }
    }
    $written = file_put_contents(CACHE_DIR . $base . '.' . $ext, $data);
    if ($written === false) {
        error_log('fetch-profile-photo.php: Failed to write cache file for ' . $base);
    }
}

/**
 * Perform a cURL POST and return [http_code, body].
 *
 * @param string $url
 * @param array<string, string> $postFields
 * @param array<string> $headers
 * @return array{int, string|false}
 */
function curlPost(string $url, array $postFields, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = $body !== false ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
    curl_close($ch);
    return [$code, $body];
}

/**
 * Perform a cURL GET and return [http_code, body].
 *
 * @param string $url
 * @param array<string> $headers
 * @return array{int, string|false}
 */
function curlGet(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = $body !== false ? (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
    curl_close($ch);
    return [$code, $body];
}

// ---------------------------------------------------------------------------
// Main logic
// ---------------------------------------------------------------------------

// 1. Resolve user e-mail from GET parameter or fall back to default.
$rawEmail = isset($_GET['email']) ? trim($_GET['email']) : '';
if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
    $userEmail = $rawEmail;
} else {
    $userEmail = DEFAULT_EMAIL;
}

$cacheBase = emailToCacheBase($userEmail);

// 2. Serve from cache if still fresh.
$cached = readCache($cacheBase);
if ($cached !== null) {
    header('Content-Type: ' . $cached['mime']);
    header('Cache-Control: public, max-age=3600');
    header('X-Photo-Cache: HIT');
    echo $cached['data'];
    exit;
}

// 3. Obtain Azure credentials from configuration constants.
$tenantId     = defined('AZURE_TENANT_ID')     ? AZURE_TENANT_ID     : '';
$clientId     = defined('AZURE_CLIENT_ID')     ? AZURE_CLIENT_ID     : '';
$clientSecret = defined('AZURE_CLIENT_SECRET') ? AZURE_CLIENT_SECRET : '';

if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
    error_log('fetch-profile-photo.php: Azure credentials missing in .env');
    serveDefaultAvatar();
}

// 4. Request an access token via Client Credentials Flow.
$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
[$tokenCode, $tokenBody] = curlPost($tokenUrl, [
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'scope'         => 'https://graph.microsoft.com/.default',
    'grant_type'    => 'client_credentials',
]);

if ($tokenCode !== 200 || $tokenBody === false) {
    error_log("fetch-profile-photo.php: Token request failed (HTTP {$tokenCode})");
    serveDefaultAvatar();
}

$tokenData   = json_decode($tokenBody, true);
$accessToken = $tokenData['access_token'] ?? '';

if ($accessToken === '') {
    error_log('fetch-profile-photo.php: No access_token in response');
    serveDefaultAvatar();
}

// 5. Fetch the profile photo from Microsoft Graph.
$photoUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($userEmail) . '/photo/$value';
[$photoCode, $photoData] = curlGet($photoUrl, [
    'Authorization: Bearer ' . $accessToken,
]);

if ($photoCode !== 200 || $photoData === false || strlen($photoData) === 0) {
    // 404 means no photo set in Entra; anything else is a real error – both
    // are handled identically: show the default avatar.
    if ($photoCode !== 404) {
        error_log("fetch-profile-photo.php: Photo fetch failed (HTTP {$photoCode}) for {$userEmail}");
    }
    serveDefaultAvatar();
}

// 6. Detect image type, cache the photo, and serve it.
$type = detectImageType($photoData);
writeCache($cacheBase, $photoData, $type['ext']);

header('Content-Type: ' . $type['mime']);
header('Cache-Control: public, max-age=3600');
header('X-Photo-Cache: MISS');
echo $photoData;
exit;
