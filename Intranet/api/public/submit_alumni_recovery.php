<?php
/**
 * API: Public Alumni Recovery Request (Isolated Gateway)
 *
 * SECURITY NOTICE: This script intentionally loads NO Microsoft Entra / Graph API code.
 * It acts as an isolated public gateway for alumni e-mail recovery submissions.
 *
 * Protection layers (in order):
 *  1. IP-based rate limiting  – max 3 requests per hour per IP (file-based)
 *  2. reCAPTCHA v2 validation – reject if success=false
 *  3. Input sanitization      – htmlspecialchars + email format validation
 *  4. DB storage              – isolated PDO INSERT (status 'pending')
 *  5. Generic response        – always identical success message (no info leakage)
 */

// ── Minimal dependency surface – NO auth, NO Entra, NO Graph API ──────────────
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';

// ── Configuration constants ────────────────────────────────────────────────────
define('ALUMNI_RATE_LIMIT_MAX',     3);    // Max requests per window
define('ALUMNI_RATE_LIMIT_WINDOW',  3600); // Window in seconds (1 hour)

// Field-length limits (must stay in sync with DB column definitions)
define('ALUMNI_MAX_NAME_LENGTH',     100);
define('ALUMNI_MAX_EMAIL_LENGTH',    254);
define('ALUMNI_MAX_SEMESTER_LENGTH',  20);
define('ALUMNI_MAX_PROGRAM_LENGTH',  200);

header('Content-Type: application/json; charset=utf-8');

// Only POST requests are accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// ── Generic success helper (always send the same message) ─────────────────────
function sendGenericSuccess(): void {
    echo json_encode([
        'success' => true,
        'message' => 'Deine Anfrage wird geprüft. Wir melden uns in Kürze bei dir.',
    ]);
    exit;
}

// ── Determine client IP ────────────────────────────────────────────────────────
/**
 * Return the client IP address to use for rate-limiting purposes.
 *
 * Checks proxy-forwarded headers in priority order so that the real client IP
 * is used even when the application sits behind a reverse proxy:
 *   1. HTTP_X_FORWARDED_FOR – leftmost (originating) entry of the header list
 *   2. HTTP_CLIENT_IP       – set by some proxy configurations
 *   3. REMOTE_ADDR          – direct TCP peer (final fallback)
 *
 * Each candidate from forwarded headers is validated with filter_var() and
 * private / reserved IP ranges are rejected to prevent spoofing with internal
 * addresses. REMOTE_ADDR is used as-is since it is the direct TCP peer.
 *
 * @return string A validated IP address string, or '0.0.0.0' as a safe fallback.
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = trim($_SERVER['HTTP_CLIENT_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $ip;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── IP-based rate limiting (max 3 requests / hour) ────────────────────────────
/**
 * Check whether the given IP is below the rate limit and, if so, record the
 * current request atomically.
 *
 * Rate-limit state is stored in PHP's system temp directory to keep it outside
 * the web root and to benefit from automatic cleanup on server restart.
 *
 * @param string $ip            Client IP address
 * @param int    $maxRequests   Maximum allowed requests within the window
 * @param int    $windowSeconds Size of the sliding window in seconds
 * @return bool  true = request is allowed, false = rate limit exceeded
 */
function checkAndRecordIpRateLimit(string $ip, int $maxRequests = 3, int $windowSeconds = 3600): bool {
    $dir = sys_get_temp_dir() . '/offera_rate_limits';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('alumni_recovery: could not create rate-limit directory: ' . $dir);
            return true; // Fail open so a temp-fs issue does not block all requests
        }
    }

    // Salt the hash with a server-side secret so that IP addresses cannot be
    // recovered from the file names via a rainbow-table attack against the
    // small IPv4 address space.
    $salt   = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
    $ipHash = hash('sha256', $salt . $ip);
    $file   = $dir . '/' . $ipHash . '_alumni.json';

    $now        = time();
    $timestamps = [];

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $timestamps = $decoded;
            }
        }
    }

    // Slide the window: discard entries older than $windowSeconds; also guard
    // against corrupted entries that may not be integers.
    $timestamps = array_values(
        array_filter($timestamps, static fn($ts): bool => is_int($ts) && ($now - $ts) < $windowSeconds)
    );

    if (count($timestamps) >= $maxRequests) {
        return false; // Rate limit exceeded – do NOT record
    }

    // Record this request and persist
    $timestamps[] = $now;
    $written = @file_put_contents($file, json_encode($timestamps), LOCK_EX);
    if ($written === false) {
        error_log('alumni_recovery: failed to write rate-limit file: ' . $file);
    }

    return true; // Allowed
}

$clientIp = getClientIp();
if (!checkAndRecordIpRateLimit($clientIp, ALUMNI_RATE_LIMIT_MAX, ALUMNI_RATE_LIMIT_WINDOW)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Zu viele Anfragen. Bitte versuche es später erneut.']);
    exit;
}

// ── Parse JSON request body ───────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?? [];
if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges Eingabeformat']);
    exit;
}

// ── reCAPTCHA v2 validation ───────────────────────────────────────────────────
/**
 * Verify a reCAPTCHA v2 token against Google's siteverify endpoint.
 *
 * Uses cURL instead of file_get_contents() so that the request works even when
 * allow_url_fopen is disabled on the server.
 *
 * @param string $token     Token submitted by the client
 * @param string $secretKey RECAPTCHA_SECRET_KEY from config
 * @param string $remoteIp  Client IP for additional signal
 * @return bool|null  true = human (success=true),
 *                    false = bot / invalid token,
 *                    null = network / timeout error (service unavailable)
 */
function verifyRecaptcha(string $token, string $secretKey, string $remoteIp): ?bool {
    if ($token === '' || $secretKey === '') {
        return false;
    }

    $postData = http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch === false) {
        error_log('alumni_recovery: curl_init failed');
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response  = curl_exec($ch);
    $errno     = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($errno !== CURLE_OK) {
        error_log('alumni_recovery: reCAPTCHA cURL request failed (errno ' . $errno . ': ' . $curlError . ')');
        return null; // Network / timeout error – signal caller to return friendly message
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return false;
    }

    return ($result['success'] === true);
}

// Only run reCAPTCHA validation when the secret key is configured.
// If RECAPTCHA_SECRET_KEY is empty the feature is intentionally disabled
// (e.g. local/dev environment) and submissions are accepted without a token.
if (!empty(RECAPTCHA_SECRET_KEY)) {
    $recaptchaToken        = trim($data['recaptcha_token'] ?? '');
    $recaptchaVerification = verifyRecaptcha($recaptchaToken, RECAPTCHA_SECRET_KEY, $clientIp);

    if ($recaptchaVerification === null) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Die Überprüfung konnte momentan nicht abgeschlossen werden. Bitte versuche es in wenigen Minuten erneut.']);
        exit;
    }

    if (empty($recaptchaVerification) || $recaptchaVerification !== true) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'reCAPTCHA ungültig. Bitte Haken erneut setzen.']);
        exit;
    }
}

// ── Input sanitization & validation ──────────────────────────────────────────
// Strict length truncation: never trust HTML maxlength; always enforce the DB
// column limit in PHP before any further processing, validation, or DB write.
$firstName          = htmlspecialchars(mb_substr(trim($data['first_name']          ?? ''), 0, ALUMNI_MAX_NAME_LENGTH,     'UTF-8'), ENT_QUOTES, 'UTF-8');
$lastName           = htmlspecialchars(mb_substr(trim($data['last_name']           ?? ''), 0, ALUMNI_MAX_NAME_LENGTH,     'UTF-8'), ENT_QUOTES, 'UTF-8');
$graduationSemester = htmlspecialchars(mb_substr(trim($data['graduation_semester'] ?? ''), 0, ALUMNI_MAX_SEMESTER_LENGTH, 'UTF-8'), ENT_QUOTES, 'UTF-8');
$studyProgram       = htmlspecialchars(mb_substr(trim($data['study_program']       ?? ''), 0, ALUMNI_MAX_PROGRAM_LENGTH,  'UTF-8'), ENT_QUOTES, 'UTF-8');

// Email fields: truncate to RFC/DB limit, then validate format and normalise
$newEmailRaw = mb_substr(trim($data['new_email'] ?? ''), 0, ALUMNI_MAX_EMAIL_LENGTH, 'UTF-8');
$oldEmailRaw = mb_substr(trim($data['old_email'] ?? ''), 0, ALUMNI_MAX_EMAIL_LENGTH, 'UTF-8');

if (empty($firstName) || empty($lastName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vor- und Nachname sind erforderlich']);
    exit;
}

if (empty($newEmailRaw) || filter_var($newEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige neue E-Mail-Adresse an']);
    exit;
}
$newEmail = strtolower($newEmailRaw);

// Old email is optional but must be valid when supplied
if ($oldEmailRaw !== '') {
    if (filter_var($oldEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige alte E-Mail-Adresse an']);
        exit;
    }
    $oldEmail = strtolower($oldEmailRaw);
} else {
    $oldEmail = '';
}

if (empty($graduationSemester)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Abschlusssemester ist erforderlich']);
    exit;
}

if (empty($studyProgram)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Studiengang ist erforderlich']);
    exit;
}

// ── Duplicate-pending check ────────────────────────────────────────────────────
// Prevent unnecessary DB duplicates: if a pending request for this e-mail
// already exists, abort cleanly and return a friendly informational message.
if (AlumniAccessRequest::hasPendingRequest($newEmail)) {
    echo json_encode([
        'success' => true,
        'message' => 'Deine Anfrage wird bereits geprüft.',
    ]);
    exit;
}

// ── Persist via isolated PDO INSERT (status defaults to 'pending') ────────────
try {
    $db   = Database::getContentDB();
    $stmt = $db->prepare(
        "INSERT INTO alumni_access_requests
             (first_name, last_name, new_email, old_email, graduation_semester, study_program)
         VALUES (:first_name, :last_name, :new_email, :old_email, :graduation_semester, :study_program)"
    );
    $stmt->execute([
        ':first_name'          => $firstName,
        ':last_name'           => $lastName,
        ':new_email'           => $newEmail,
        ':old_email'           => $oldEmail !== '' ? $oldEmail : null,
        ':graduation_semester' => $graduationSemester,
        ':study_program'       => $studyProgram,
    ]);
} catch (Exception $e) {
    error_log('alumni_recovery: DB insert failed – ' . $e->getMessage());
    // Intentionally fall through: always return the same generic success response
    // to avoid leaking whether the request was stored or why it failed.
}

// ── Generic success response ───────────────────────────────────────────────────
// Returned unconditionally so attackers cannot infer anything about the system
// (e.g. whether an e-mail address already exists in the database).
sendGenericSuccess();
