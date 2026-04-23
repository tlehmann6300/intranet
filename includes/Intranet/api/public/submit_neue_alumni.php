<?php
/**
 * API: Public Neue Alumni Registration Request (Isolated Gateway)
 *
 * SECURITY NOTICE: This script intentionally loads NO Microsoft Entra / Graph API code.
 * It acts as an isolated public gateway for new alumni registration submissions.
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
require_once __DIR__ . '/../../includes/models/NewAlumniRequest.php';

// ── Configuration constants ────────────────────────────────────────────────────
define('NEW_ALUMNI_RATE_LIMIT_MAX',     3);    // Max requests per window
define('NEW_ALUMNI_RATE_LIMIT_WINDOW',  3600); // Window in seconds (1 hour)

// Field-length limits (must stay in sync with DB column definitions)
define('NEW_ALUMNI_MAX_NAME_LENGTH',     100);
define('NEW_ALUMNI_MAX_EMAIL_LENGTH',    254);
define('NEW_ALUMNI_MAX_SEMESTER_LENGTH',  20);
define('NEW_ALUMNI_MAX_PROGRAM_LENGTH',  200);

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
function checkAndRecordIpRateLimitNewAlumni(string $ip, int $maxRequests = 3, int $windowSeconds = 3600): bool {
    $dir = sys_get_temp_dir() . '/offera_rate_limits';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('neue_alumni: could not create rate-limit directory: ' . $dir);
            return true;
        }
    }

    $salt   = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
    $ipHash = hash('sha256', $salt . $ip);
    $file   = $dir . '/' . $ipHash . '_neue_alumni.json';

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

    $timestamps = array_values(
        array_filter($timestamps, static fn($ts): bool => is_int($ts) && ($now - $ts) < $windowSeconds)
    );

    if (count($timestamps) >= $maxRequests) {
        return false;
    }

    $timestamps[] = $now;
    $written = @file_put_contents($file, json_encode($timestamps), LOCK_EX);
    if ($written === false) {
        error_log('neue_alumni: failed to write rate-limit file: ' . $file);
    }

    return true;
}

$clientIp = getClientIp();
if (!checkAndRecordIpRateLimitNewAlumni($clientIp, NEW_ALUMNI_RATE_LIMIT_MAX, NEW_ALUMNI_RATE_LIMIT_WINDOW)) {
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
function verifyRecaptchaNewAlumni(string $token, string $secretKey, string $remoteIp): ?bool {
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
        error_log('neue_alumni: curl_init failed');
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
        error_log('neue_alumni: reCAPTCHA cURL request failed (errno ' . $errno . ': ' . $curlError . ')');
        return null;
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return false;
    }

    return ($result['success'] === true);
}

if (!empty(RECAPTCHA_SECRET_KEY)) {
    $recaptchaToken        = trim($data['recaptcha_token'] ?? '');
    $recaptchaVerification = verifyRecaptchaNewAlumni($recaptchaToken, RECAPTCHA_SECRET_KEY, $clientIp);

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
$firstName          = htmlspecialchars(mb_substr(trim($data['first_name']          ?? ''), 0, NEW_ALUMNI_MAX_NAME_LENGTH,     'UTF-8'), ENT_QUOTES, 'UTF-8');
$lastName           = htmlspecialchars(mb_substr(trim($data['last_name']           ?? ''), 0, NEW_ALUMNI_MAX_NAME_LENGTH,     'UTF-8'), ENT_QUOTES, 'UTF-8');
$graduationSemester = htmlspecialchars(mb_substr(trim($data['graduation_semester'] ?? ''), 0, NEW_ALUMNI_MAX_SEMESTER_LENGTH, 'UTF-8'), ENT_QUOTES, 'UTF-8');
$studyProgram       = htmlspecialchars(mb_substr(trim($data['study_program']       ?? ''), 0, NEW_ALUMNI_MAX_PROGRAM_LENGTH,  'UTF-8'), ENT_QUOTES, 'UTF-8');

// Email fields
$newEmailRaw = mb_substr(trim($data['new_email'] ?? ''), 0, NEW_ALUMNI_MAX_EMAIL_LENGTH, 'UTF-8');
$oldEmailRaw = mb_substr(trim($data['old_email'] ?? ''), 0, NEW_ALUMNI_MAX_EMAIL_LENGTH, 'UTF-8');

// Alumni contract field: must be '0' or '1'
$hasAlumniContractRaw = trim($data['has_alumni_contract'] ?? '');
if (!in_array($hasAlumniContractRaw, ['0', '1'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte angeben, ob du bereits einen Alumni Vertrag erhalten hast']);
    exit;
}
$hasAlumniContract = (int) $hasAlumniContractRaw;

if (empty($firstName) || empty($lastName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vor- und Nachname sind erforderlich']);
    exit;
}

if (empty($newEmailRaw) || filter_var($newEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige E-Mail-Adresse an']);
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
if (NewAlumniRequest::hasPendingRequest($newEmail)) {
    echo json_encode([
        'success' => true,
        'message' => 'Deine Anfrage wird bereits geprüft.',
    ]);
    exit;
}

// ── Persist via model (status defaults to 'pending') ─────────────────────────
try {
    NewAlumniRequest::create([
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'new_email'           => $newEmail,
        'old_email'           => $oldEmail,
        'graduation_semester' => $graduationSemester,
        'study_program'       => $studyProgram,
        'has_alumni_contract' => $hasAlumniContract,
    ]);
} catch (Exception $e) {
    error_log('neue_alumni: DB insert failed – ' . $e->getMessage());
    // Intentionally fall through
}

sendGenericSuccess();
