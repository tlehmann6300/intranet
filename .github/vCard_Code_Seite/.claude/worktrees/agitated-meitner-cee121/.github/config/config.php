<?php
// Security Headers – comprehensive set (HSTS, CSP, X-Frame-Options, …)
require_once __DIR__ . '/../includes/security_headers.php';

// Load .env file
$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if (strpos($_line, '#') === 0 || strpos($_line, '=') === false) continue;
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val);
        if (strlen($_val) >= 2 && $_val[0] === '"' && substr($_val, -1) === '"') {
            $_val = substr($_val, 1, -1);
        } elseif (strlen($_val) >= 2 && $_val[0] === "'" && substr($_val, -1) === "'") {
            $_val = substr($_val, 1, -1);
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/i', $_key) && !isset($_ENV[$_key])) {
            $_ENV[$_key] = $_val;
        }
    }
    unset($_envFile, $_line, $_key, $_val);
} else {
    unset($_envFile);
}

// Helper to read env value with default
function _env($key, $default = '') {
    if (isset($_ENV[$key])) return $_ENV[$key];
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

// Application Settings
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(_env('BASE_URL', 'https://intra.business-consulting.de'), '/'));
}
define('ENVIRONMENT', _env('ENVIRONMENT', 'production'));

// Password hashing algorithm
define('HASH_ALGO', PASSWORD_BCRYPT);

// Session security – must be set BEFORE session_start() is called
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);

// Error reporting based on environment
if (ENVIRONMENT !== 'production') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');
}

// Database Settings (User DB)
define('DB_USER_HOST', _env('DB_USER_HOST', 'localhost'));
define('DB_USER_NAME', _env('DB_USER_NAME', ''));
define('DB_USER_USER', _env('DB_USER_USER', ''));
define('DB_USER_PASS', _env('DB_USER_PASS', ''));

// Database Settings (Content DB)
define('DB_CONTENT_HOST', _env('DB_CONTENT_HOST', 'localhost'));
define('DB_CONTENT_NAME', _env('DB_CONTENT_NAME', ''));
define('DB_CONTENT_USER', _env('DB_CONTENT_USER', ''));
define('DB_CONTENT_PASS', _env('DB_CONTENT_PASS', ''));

// Database Settings (Invoice/Rech DB)
define('DB_RECH_HOST', _env('DB_RECH_HOST', _env('DB_INVOICE_HOST', 'localhost')));
define('DB_RECH_PORT', _env('DB_RECH_PORT', _env('DB_INVOICE_PORT', '3306')));
define('DB_RECH_NAME', _env('DB_RECH_NAME', _env('DB_INVOICE_NAME', '')));
define('DB_RECH_USER', _env('DB_RECH_USER', _env('DB_INVOICE_USER', '')));
define('DB_RECH_PASS', _env('DB_RECH_PASS', _env('DB_INVOICE_PASS', '')));

// Database Settings (Inventory DB)
define('DB_INVENTORY_HOST', _env('DB_INVENTORY_HOST', 'localhost'));
define('DB_INVENTORY_PORT', _env('DB_INVENTORY_PORT', '3306'));
define('DB_INVENTORY_NAME', _env('DB_INVENTORY_NAME', ''));
define('DB_INVENTORY_USER', _env('DB_INVENTORY_USER', ''));
define('DB_INVENTORY_PASS', _env('DB_INVENTORY_PASS', ''));

// Database Settings (News DB)
define('DB_NEWS_HOST', _env('DB_NEWS_HOST', 'localhost'));
define('DB_NEWS_NAME', _env('DB_NEWS_NAME', ''));
define('DB_NEWS_USER', _env('DB_NEWS_USER', ''));
define('DB_NEWS_PASS', _env('DB_NEWS_PASS', ''));

// Database Settings (vCard DB – external)
// Host and credentials are read from .env; the values below serve as fallbacks
// for the non-sensitive connection coordinates only.  The password MUST be set
// via DB_VCARD_PASS in .env and has no default.
define('DB_VCARD_HOST', _env('DB_VCARD_HOST', 'db5016986508.hosting-data.io'));
define('DB_VCARD_NAME', _env('DB_VCARD_NAME', 'dbs13688083'));
define('DB_VCARD_USER', _env('DB_VCARD_USER', 'dbu5428642'));
define('DB_VCARD_PASS', _env('DB_VCARD_PASS', ''));

// Public vCard preview URL (the QR-scan landing page that reads vcards_table)
// Accepts a ?user=<id> query parameter. Override via .env VCARD_PUBLIC_URL if needed.
define('VCARD_PUBLIC_URL', _env('VCARD_PUBLIC_URL', 'https://vcard.business-consulting.de/vCard.php'));

// SMTP Settings
define('SMTP_HOST',       _env('SMTP_HOST', ''));
define('SMTP_PORT',       (int) _env('SMTP_PORT', '587'));
define('SMTP_USER',       _env('SMTP_USER', ''));
define('SMTP_PASS',       _env('SMTP_PASS', ''));
define('SMTP_FROM',       _env('SMTP_FROM', ''));
define('SMTP_FROM_EMAIL', _env('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME',  _env('SMTP_FROM_NAME', 'IBC Intranet'));

// Azure / Microsoft Entra Settings
define('AZURE_TENANT_ID',     _env('AZURE_TENANT_ID',     _env('TENANT_ID', '')));
define('AZURE_CLIENT_ID',     _env('AZURE_CLIENT_ID',     _env('CLIENT_ID', '')));
define('AZURE_CLIENT_SECRET', _env('AZURE_CLIENT_SECRET', _env('CLIENT_SECRET', '')));
define('AZURE_REDIRECT_URI',  _env('AZURE_REDIRECT_URI',  ''));

if (AZURE_CLIENT_ID === '' || AZURE_CLIENT_SECRET === '') {
    error_log('Warning: Azure configuration is missing or incomplete in .env');
}

// Legacy aliases for backward compatibility
define('TENANT_ID',    AZURE_TENANT_ID);
define('CLIENT_ID',    AZURE_CLIENT_ID);
define('CLIENT_SECRET', AZURE_CLIENT_SECRET);
define('REDIRECT_URI', AZURE_REDIRECT_URI);

// Default profile image fallback path
define('DEFAULT_PROFILE_IMAGE', 'assets/img/default_profil.png');

// Invoice Settings
define('INVOICE_NOTIFICATION_EMAIL', _env('INVOICE_NOTIFICATION_EMAIL', 'vorstand@business-consulting.de'));

// Inventory Settings
define('INVENTORY_BOARD_EMAIL', _env('INVENTORY_BOARD_EMAIL', 'vorstand@business-consulting.de'));

// Rental Settings
define('INTRA_RENTAL_USER_NAME', _env('INTRA_RENTAL_USER_NAME', 'Intra Ausleihe'));

// Mail Recipient Settings
// IT_Mail acts as a shared fallback for both MAIL_SUPPORT and MAIL_IT_RESSORT
// so that a single IT_Mail= entry in .env is sufficient.
define('MAIL_SUPPORT',    _env('SUPPORT_EMAIL',    _env('IT_Mail', 'it@business-consulting.de')));
define('MAIL_IDEAS',      _env('IDEAS_EMAIL',       'ideas@business-consulting.de'));
define('MAIL_FINANCE',    _env('FINANCE_EMAIL',     'finance@business-consulting.de'));
define('MAIL_INVENTORY',  _env('INVENTORY_EMAIL',   'inventory@business-consulting.de'));
define('MAIL_IT_RESSORT', _env('IT_RESSORT_MAIL',   _env('IT_Mail', 'it@business-consulting.de')));

// EasyVerein API
define('EASYVEREIN_API_TOKEN', _env('EASYVEREIN_API_TOKEN', ''));

// Cron Security Token – must be a long random secret set in .env.
// Cron scripts allow access either via CLI or when this token is supplied via ?token=…
define('CRON_TOKEN', _env('CRON_TOKEN', ''));

// Google reCAPTCHA v2
// RECAPTCHA_SITE_KEY is the public key embedded in HTML – safe to ship as default.
// RECAPTCHA_SECRET_KEY is sensitive and must be set via .env on each deployment.
define('RECAPTCHA_SITE_KEY',   _env('RECAPTCHA_SITE_KEY',   '6LfvX4ssAAAAAJDjL5xtcpXkjbRww5FRtHC1DYqX'));
define('RECAPTCHA_SECRET_KEY', _env('RECAPTCHA_SECRET_KEY', ''));

// Trusted reverse-proxy IP addresses (comma-separated in .env).
// X-Forwarded-For is only honoured when REMOTE_ADDR exactly matches one of
// these IPs; leave the env variable empty when there is no reverse proxy.
// Each entry is validated with filter_var() – malformed values are discarded
// and logged so misconfigurations are caught early.
define('TRUSTED_PROXIES', (static function (): array {
    $raw = _env('TRUSTED_PROXIES', '');
    if ($raw === '') {
        return [];
    }
    $result = [];
    foreach (explode(',', $raw) as $entry) {
        $ip = trim($entry);
        if ($ip === '') {
            continue;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $result[] = $ip;
        } else {
            error_log('TRUSTED_PROXIES: invalid IP address ignored: ' . $ip);
        }
    }
    return $result;
})());

// Role Mapping (IDs aus Entra -> Interne Rollen)
define('ROLE_MAPPING', [
    'vorstand_finanzen'       => '3ad43a76-75af-48a7-9974-7a2cf350f349',
    'vorstand_intern'         => 'f61e99e2-2717-4aff-b3f5-ef2ec489b598',
    'vorstand_extern'         => 'bf17e26b-e5f1-4a63-ae56-91ab69ae33ca',
    'alumni_vorstand'         => '8a45c6aa-e791-422e-b964-986d8bdd2ed8',
    'alumni_finanz'           => '39597941-0a22-4922-9587-e3d62ab986d6',
    'alumni'                  => '7ffd9c73-a828-4e34-a9f4-10f4ed00f796',
    'ehrenmitglied'           => '09686b92-dbc8-4e66-a851-2dafea64df89',
    'ressortleiter'           => '9456552d-0f49-42ff-bbde-495a60e61e61',
    'mitglied'                => '70f07477-ea4e-4edc-b0e6-7e25968f16c0',
    'anwaerter'               => '75edcb0a-c610-4ceb-82f2-457a9dde4fc0'
]);

function isActivePath($path) {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false;
}
?>
