<?php
/**
 * Security Headers Configuration
 *
 * Sets HTTP response headers to harden the application's security posture.
 * Headers are only set if they haven't been sent already to avoid conflicts.
 *
 * Include this file early in the application lifecycle, before any output
 * is produced (e.g. at the top of config/config.php).
 */

/**
 * Helper: check whether a response header with the given name has already been queued.
 *
 * @param string $header_name Case-insensitive header name (without colon)
 * @return bool
 */
function header_sent_check($header_name) {
    foreach (headers_list() as $header) {
        if (stripos($header, $header_name . ':') === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Generate (or retrieve) a per-request CSP nonce for use with inline scripts/styles.
 *
 * A fresh nonce is generated once per PHP process (i.e. per HTTP request) and
 * cached in a static variable so every call within the same request returns the
 * same value.  It is NOT stored in the session to ensure uniqueness across
 * requests.  Templates can retrieve the nonce via csp_nonce().
 *
 * @return string Base64-encoded nonce value
 */
function generate_csp_nonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(32));
    }
    return $nonce;
}

/**
 * Return the current CSP nonce, safe for embedding in HTML attributes.
 * Convenience alias for templates.
 *
 * @return string
 */
function csp_nonce() {
    return htmlspecialchars(generate_csp_nonce(), ENT_QUOTES, 'UTF-8');
}

// Only set headers if output has not started yet
if (!headers_sent()) {

    // ------------------------------------------------------------------
    // Strict-Transport-Security (HSTS)
    // Instructs browsers to use HTTPS exclusively for this origin.
    // Sent whenever the current request is already over HTTPS so that
    // the header is also delivered in non-production environments.
    // ------------------------------------------------------------------
    if (!header_sent_check('Strict-Transport-Security')) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        if ($isHttps) {
            // 1 year max-age; includeSubDomains and preload for production readiness
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    // ------------------------------------------------------------------
    // X-Content-Type-Options
    // Prevents MIME-type sniffing; browsers must honour the declared
    // Content-Type and not guess from the response body.
    // ------------------------------------------------------------------
    if (!header_sent_check('X-Content-Type-Options')) {
        header('X-Content-Type-Options: nosniff');
    }

    // ------------------------------------------------------------------
    // X-Frame-Options
    // SAMEORIGIN restricts rendering this page inside a frame or iframe
    // to the same origin only, protecting against clickjacking attacks
    // from external sites.
    // (Reinforced at the CSP level via frame-ancestors 'self' below.)
    // ------------------------------------------------------------------
    if (!header_sent_check('X-Frame-Options')) {
        header('X-Frame-Options: SAMEORIGIN');
    }

    // ------------------------------------------------------------------
    // X-XSS-Protection
    // Activates the legacy XSS auditor in older browsers (IE/early Chrome).
    // Modern browsers rely on CSP instead; this header is included for
    // backwards compatibility.
    // ------------------------------------------------------------------
    if (!header_sent_check('X-XSS-Protection')) {
        header('X-XSS-Protection: 1; mode=block');
    }

    // ------------------------------------------------------------------
    // Referrer-Policy
    // Sends the full URL for same-origin requests; only the origin for
    // cross-origin HTTPS requests; nothing for cross-origin HTTP.
    // ------------------------------------------------------------------
    if (!header_sent_check('Referrer-Policy')) {
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    // ------------------------------------------------------------------
    // Permissions-Policy (formerly Feature-Policy)
    // Disables browser features that the application does not use.
    // ------------------------------------------------------------------
    if (!header_sent_check('Permissions-Policy')) {
        $permissions = [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
            'magnetometer=()',
            'gyroscope=()',
            'accelerometer=()',
            'fullscreen=(self)',
            'display-capture=()',
        ];
        header('Permissions-Policy: ' . implode(', ', $permissions));
    }

    // ------------------------------------------------------------------
    // Content-Security-Policy (CSP)
    //
    // Key directives:
    //   default-src 'self'          – fallback: only same-origin resources
    //   script-src                  – trusted CDNs; 'unsafe-inline' kept for
    //                                 backwards compatibility (inline event
    //                                 handlers / script blocks in templates).
    //                                 google.com + gstatic.com required for
    //                                 reCAPTCHA v2 widget script.
    //                                 NOTE: migrate templates to nonce-based
    //                                 scripts and remove 'unsafe-inline' once
    //                                 all <script> tags carry the nonce
    //                                 attribute (use csp_nonce() helper).
    //   style-src                   – trusted CDNs + inline styles required
    //                                 by Tailwind utility classes;
    //                                 gstatic.com required for reCAPTCHA styles
    //   img-src                     – self + data: (SVG / base64) + blob:
    //                                 + gstatic.com for reCAPTCHA images
    //   font-src                    – Google Fonts CDN + Flaticon
    //   connect-src 'self'          – XHR/fetch only to same origin;
    //                                 login.microsoftonline.com and
    //                                 graph.microsoft.com required for
    //                                 Microsoft Entra authentication;
    //                                 cdn.jsdelivr.net allowed for source
    //                                 map fetches by browser DevTools
    //   frame-src 'self'            – explicit allowlist for iframes;
    //                                 google.com required for reCAPTCHA
    //                                 challenge iframe;
    //                                 forms.office.com required for
    //                                 Microsoft Forms polls embedding
    //   form-action 'self'          – form submissions only to same origin
    //   base-uri 'self'             – prevents <base> tag hijacking
    //   object-src 'none'           – blocks Flash / plugins entirely
    //   frame-ancestors 'self'      – allows same-origin framing only;
    //                                 reinforces X-Frame-Options: SAMEORIGIN
    //   upgrade-insecure-requests   – transparently upgrades HTTP sub-
    //                                 resource requests to HTTPS
    // ------------------------------------------------------------------
    if (!header_sent_check('Content-Security-Policy')) {
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn-uicons.flaticon.com https://cdn.jsdelivr.net https://www.gstatic.com",
            "img-src 'self' data: blob: https://www.gstatic.com",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn-uicons.flaticon.com",
            "connect-src 'self' https://login.microsoftonline.com https://graph.microsoft.com https://cdn.jsdelivr.net",
            "frame-src 'self' https://www.google.com https://forms.office.com",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "upgrade-insecure-requests",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp_directives));
    }

    // ------------------------------------------------------------------
    // X-Permitted-Cross-Domain-Policies
    // Prevents Adobe Flash / Acrobat from loading cross-domain content.
    // ------------------------------------------------------------------
    if (!header_sent_check('X-Permitted-Cross-Domain-Policies')) {
        header('X-Permitted-Cross-Domain-Policies: none');
    }

    // ------------------------------------------------------------------
    // Cache-Control for dynamic pages
    // Static assets (CSS, JS, images, fonts) are handled by .htaccess;
    // all other responses must not be cached by proxies or the browser.
    // ------------------------------------------------------------------
    if (!header_sent_check('Cache-Control')) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!preg_match('/\.(css|js|jpg|jpeg|png|gif|webp|svg|woff|woff2|ttf|eot|ico)$/i', $requestUri)) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }
}
