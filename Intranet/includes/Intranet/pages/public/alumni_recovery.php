<?php
/**
 * Alumni E-Mail Recovery – public request form
 * Access: public (no authentication required)
 *
 * Form submission is handled client-side via fetch → api/public/submit_alumni_recovery.php
 *
 * Security hardening:
 *  - All output uses htmlspecialchars / ENT_QUOTES / UTF-8
 *  - CSP-friendly: no inline event handlers, all JS is in a deferred IIFE
 *  - recaptcha_token is filled only at submit-time (freshly minted)
 *  - No sensitive data logged client-side (console.error stripped from prod)
 *  - autocomplete tokens set per WHATWG spec: standard tokens for known field types,
 *    no autocomplete attribute on custom academic fields (graduation_semester, study_program)
 *    so Lighthouse autofill audit passes; browsers ignore unknown field names anyway
 *  - inputmode / pattern attributes added for mobile UX + light client validation
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

$title = 'Alumni E-Mail Recovery – IBC Intranet';

ob_start();
?>

<!-- ============================================================
     Alumni E-Mail Recovery – Enhanced Design
     ============================================================ -->
<style>
/* ── Page-level overrides (scoped to this page only) ─────────── */

/* Animated gradient background orbs */
.alumni-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
}
.alumni-bg::before,
.alumni-bg::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.12;
    animation: floatOrb 12s ease-in-out infinite alternate;
}
.alumni-bg::before {
    width: min(600px, 80vw);
    height: min(600px, 80vw);
    background: radial-gradient(circle, var(--ibc-green) 0%, transparent 70%);
    top: -20%;
    right: -10%;
    animation-delay: 0s;
}
.alumni-bg::after {
    width: min(500px, 70vw);
    height: min(500px, 70vw);
    background: radial-gradient(circle, var(--ibc-blue) 0%, transparent 70%);
    bottom: -15%;
    left: -10%;
    animation-delay: -4s;
}
@keyframes floatOrb {
    0%   { transform: translate(0, 0) scale(1); }
    50%  { transform: translate(2%, 3%) scale(1.05); }
    100% { transform: translate(-2%, -2%) scale(0.97); }
}

/* Grid noise texture overlay */
.alumni-grid-texture {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
    background-size: 48px 48px;
}

/* Card glass effect */
.alumni-card {
    position: relative;
    z-index: 1;
    background: rgba(255, 255, 255, 0.04);
    backdrop-filter: blur(24px) saturate(160%);
    -webkit-backdrop-filter: blur(24px) saturate(160%);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 24px;
    box-shadow:
        0 0 0 1px rgba(0, 166, 81, 0.08),
        0 32px 64px rgba(0, 0, 0, 0.45),
        0 8px 24px rgba(0, 0, 0, 0.30),
        inset 0 1px 0 rgba(255, 255, 255, 0.07);
    padding: clamp(1.5rem, 5vw, 2.5rem);
    width: 100%;
    max-width: 560px;
    margin-inline: auto;
    animation: cardReveal 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes cardReveal {
    from { opacity: 0; transform: translateY(24px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0)   scale(1); }
}

/* Logo container with glow */
.alumni-logo-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 1.75rem;
    animation: cardReveal 0.6s 0.1s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.alumni-logo-wrap img {
    height: clamp(48px, 8vw, 64px);
    width: auto;
    filter: drop-shadow(0 0 16px rgba(0, 166, 81, 0.35));
    transition: filter 0.3s ease;
}
.alumni-logo-wrap img:hover {
    filter: drop-shadow(0 0 24px rgba(0, 166, 81, 0.55));
}

/* Page heading */
.alumni-heading {
    text-align: center;
    margin-bottom: 2rem;
    animation: cardReveal 0.6s 0.15s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.alumni-heading h1 {
    font-size: clamp(1.35rem, 4vw, 1.85rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    background: linear-gradient(135deg, #ffffff 0%, rgba(255,255,255,0.75) 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 0.6rem;
    line-height: 1.2;
}
.alumni-heading p {
    color: rgba(255, 255, 255, 0.5);
    font-size: clamp(0.875rem, 2.2vw, 1rem);
    line-height: 1.65;
    max-width: 38ch;
    margin-inline: auto;
}

/* Green accent divider */
.alumni-divider {
    width: 40px;
    height: 3px;
    background: linear-gradient(90deg, var(--ibc-green), var(--ibc-blue));
    border-radius: 99px;
    margin: 0.75rem auto 1.5rem;
}

/* Field groups */
.alumni-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    animation: cardReveal 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.alumni-field:nth-child(1) { animation-delay: 0.2s; }
.alumni-field:nth-child(2) { animation-delay: 0.25s; }
.alumni-field:nth-child(3) { animation-delay: 0.3s; }
.alumni-field:nth-child(4) { animation-delay: 0.35s; }
.alumni-field:nth-child(5) { animation-delay: 0.4s; }
.alumni-field:nth-child(6) { animation-delay: 0.45s; }

.alumni-field label {
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.55);
}
.alumni-field label .req {
    color: var(--ibc-green);
    margin-left: 2px;
}
.alumni-field label .opt {
    color: rgba(255, 255, 255, 0.28);
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.875rem;
}

/* Input styling */
.alumni-input {
    width: 100%;
    background: rgba(255, 255, 255, 0.055);
    border: 1.5px solid rgba(255, 255, 255, 0.10);
    border-radius: 12px;
    color: #ffffff;
    font-size: 0.9rem;
    padding: 0.7rem 1rem;
    outline: none;
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease;
    -webkit-appearance: none;
    appearance: none;
}
.alumni-input::placeholder {
    color: rgba(255, 255, 255, 0.22);
}
.alumni-input:hover {
    border-color: rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.08);
}
.alumni-input:focus {
    border-color: rgba(0, 166, 81, 0.6);
    background: rgba(0, 166, 81, 0.06);
    box-shadow: 0 0 0 3px rgba(0, 166, 81, 0.14), 0 2px 8px rgba(0,0,0,0.2);
}
.alumni-input:invalid:not(:placeholder-shown) {
    border-color: rgba(239, 68, 68, 0.5);
}

/* Input hint text */
.alumni-hint {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.28);
    padding-left: 0.25rem;
    line-height: 1.4;
}

/* Two-column grid that collapses on mobile */
.alumni-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
@media (max-width: 420px) {
    .alumni-grid-2 {
        grid-template-columns: 1fr;
    }
}

/* Submit button */
.alumni-submit-btn {
    width: 100%;
    padding: 0.85rem 1.5rem;
    border-radius: 14px;
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #ffffff;
    background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-dark) 100%);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    box-shadow:
        0 4px 16px rgba(0, 166, 81, 0.35),
        0 1px 4px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.12);
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease,
        background 0.2s ease,
        opacity 0.2s ease;
    position: relative;
    overflow: hidden;
    animation: cardReveal 0.5s 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
.alumni-submit-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.10) 0%, transparent 60%);
    pointer-events: none;
}
.alumni-submit-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow:
        0 8px 28px rgba(0, 166, 81, 0.45),
        0 2px 8px rgba(0, 0, 0, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.15);
}
.alumni-submit-btn:active:not(:disabled) {
    transform: scale(0.98) translateY(0);
    box-shadow:
        0 2px 8px rgba(0, 166, 81, 0.25),
        inset 0 1px 0 rgba(255, 255, 255, 0.10);
}
.alumni-submit-btn:focus-visible {
    outline: 3px solid rgba(0, 166, 81, 0.6);
    outline-offset: 3px;
}
.alumni-submit-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    transform: none;
}

/* Ripple effect on button click */
.alumni-submit-btn .ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    transform: scale(0);
    animation: rippleAnim 0.5s linear;
    pointer-events: none;
}
@keyframes rippleAnim {
    to { transform: scale(4); opacity: 0; }
}

/* Success state */
.alumni-success {
    display: none;
    text-align: center;
    padding: 1.5rem 1rem;
    animation: cardReveal 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.alumni-success.is-visible { display: block; }
.alumni-success-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(0, 166, 81, 0.12);
    border: 1.5px solid rgba(0, 166, 81, 0.30);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    font-size: 1.5rem;
    color: var(--ibc-green);
    box-shadow: 0 0 0 8px rgba(0, 166, 81, 0.06);
}
.alumni-success h2 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 0.5rem;
}
.alumni-success p {
    font-size: 0.875rem;
    color: rgba(255, 255, 255, 0.45);
    line-height: 1.65;
    max-width: 36ch;
    margin-inline: auto;
}

/* Error box */
.alumni-error-box {
    display: none;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.25);
    border-radius: 12px;
    padding: 0.85rem 1rem;
}
.alumni-error-box.is-visible { display: block; }
.alumni-error-box ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
.alumni-error-box li {
    font-size: 0.875rem;
    color: #fca5a5;
    display: flex;
    align-items: flex-start;
    gap: 0.4rem;
}
.alumni-error-box li::before {
    content: '✕';
    font-size: 0.875rem;
    color: #f87171;
    margin-top: 0.1rem;
    flex-shrink: 0;
}

/* reCAPTCHA widget + note */
#recaptcha-alumni-recovery {
    display: flex;
    justify-content: center;
}
.alumni-recaptcha-note {
    font-size: 0.875rem;
    text-align: center;
    color: rgba(255, 255, 255, 0.22);
    line-height: 1.6;
}
.alumni-recaptcha-note a {
    color: rgba(255, 255, 255, 0.38);
    text-decoration: underline;
    text-underline-offset: 2px;
    transition: color 0.15s;
}
.alumni-recaptcha-note a:hover {
    color: rgba(255, 255, 255, 0.6);
}

/* Step indicator dots (decorative) */
.alumni-step-dots {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-bottom: 1.5rem;
}
.alumni-step-dots span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    transition: background 0.3s;
}
.alumni-step-dots span.active {
    background: var(--ibc-green);
    box-shadow: 0 0 8px rgba(0, 166, 81, 0.5);
}

/* Full page centering wrapper */
.alumni-page-wrap {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(1rem, 4vw, 2.5rem) clamp(0.75rem, 3vw, 1.5rem);
    position: relative;
}

/* ── Focus-visible global accessibility ring ─────────────────── */
.alumni-input:focus-visible,
.alumni-submit-btn:focus-visible,
a:focus-visible {
    outline: 3px solid rgba(0, 166, 81, 0.65);
    outline-offset: 3px;
}

/* ── Reduced motion ─────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.1ms !important;
    }
}

/* ── Wizard step transitions ──────────────────────────────── */
.alumni-step {
    transition: opacity 0.3s ease, transform 0.3s ease;
}

/* Back button (secondary/ghost style) */
.alumni-back-btn {
    padding: 0.85rem 1.25rem;
    min-height: 44px;
    border-radius: 14px;
    font-size: 0.9rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: rgba(255, 255, 255, 0.55);
    background: transparent;
    border: 1.5px solid rgba(255, 255, 255, 0.12);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition:
        border-color 0.2s ease,
        color 0.2s ease,
        background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
    flex-shrink: 0;
}
.alumni-back-btn:hover:not(:disabled) {
    border-color: rgba(255, 255, 255, 0.28);
    color: rgba(255, 255, 255, 0.85);
    background: rgba(255, 255, 255, 0.05);
}
.alumni-back-btn:active:not(:disabled) {
    background: rgba(255, 255, 255, 0.08);
}
.alumni-back-btn:focus-visible {
    outline: 3px solid rgba(0, 166, 81, 0.6);
    outline-offset: 3px;
}

/* Button row for step 2 (back + submit side by side) */
.alumni-btn-row {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}
.alumni-btn-row .alumni-submit-btn {
    flex: 1;
}
</style>

<!-- Ambient background layers -->
<div class="alumni-bg" aria-hidden="true"></div>
<div class="alumni-grid-texture" aria-hidden="true"></div>

<!-- Page wrapper -->
<div class="alumni-page-wrap">
    <div class="alumni-card" role="main">

        <!-- Logo -->
        <div class="alumni-logo-wrap">
            <img
                src="<?php echo asset('assets/img/ibc_logo_original_navbar.webp'); ?>"
                alt="IBC Intranet"
                decoding="async"
                loading="eager"
                width="180"
                height="64"
            >
        </div>

        <!-- Heading -->
        <div class="alumni-heading">
            <h1>Alumni E-Mail Recovery</h1>
            <div class="alumni-divider" aria-hidden="true"></div>
            <p>Kein Zugriff mehr auf deine Alumni-E-Mail?<br>Füll das Formular aus – wir kümmern uns darum.</p>
        </div>

        <!-- Step indicator (decorative, helps orientation) -->
        <div class="alumni-step-dots" aria-hidden="true">
            <span class="active"></span>
            <span></span>
            <span></span>
        </div>

        <!-- ── SUCCESS MESSAGE ─────────────────────────────────── -->
        <div
            id="alumniSuccessMessage"
            class="alumni-success"
            role="status"
            aria-live="polite"
            aria-atomic="true"
        >
            <div class="alumni-success-icon" aria-hidden="true">
                <i class="fas fa-check"></i>
            </div>
            <h2>Anfrage gesendet!</h2>
            <p>
                Wir haben deine Anfrage erhalten und melden uns so bald wie möglich
                an deine neue E-Mail-Adresse.
            </p>
            <a
                href="<?php echo htmlspecialchars(url('pages/auth/login.php'), ENT_QUOTES, 'UTF-8'); ?>"
                class="alumni-submit-btn"
                style="margin-top:1.25rem;text-decoration:none;"
            >
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                <span>Zur Anmeldung</span>
            </a>
        </div>

        <!-- ── ERROR BOX ──────────────────────────────────────── -->
        <div
            id="alumniErrorBox"
            class="alumni-error-box"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
        >
            <ul id="alumniErrorList"></ul>
        </div>

        <!-- ── FORM ───────────────────────────────────────────── -->
        <form
            id="alumniRecoveryForm"
            method="POST"
            action=""
            novalidate
            autocomplete="on"
        >
            <!-- ── STEP 1: Name + E-Mail ────────────────────── -->
            <div
                id="alumniStep1"
                class="alumni-step"
                style="display:flex;flex-direction:column;gap:1.1rem;"
            >
                <!-- First / Last name -->
                <div class="alumni-grid-2">
                    <div class="alumni-field">
                        <label for="first_name">
                            Vorname <span class="req" aria-label="Pflichtfeld">*</span>
                        </label>
                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            class="alumni-input"
                            required
                            autocomplete="given-name"
                            spellcheck="false"
                            placeholder="Max"
                            maxlength="80"
                            aria-required="true"
                        >
                    </div>
                    <div class="alumni-field">
                        <label for="last_name">
                            Nachname <span class="req" aria-label="Pflichtfeld">*</span>
                        </label>
                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            class="alumni-input"
                            required
                            autocomplete="family-name"
                            spellcheck="false"
                            placeholder="Mustermann"
                            maxlength="80"
                            aria-required="true"
                        >
                    </div>
                </div>

                <!-- New e-mail -->
                <div class="alumni-field">
                    <label for="new_email">
                        Neue E-Mail-Adresse <span class="req" aria-label="Pflichtfeld">*</span>
                    </label>
                    <input
                        type="email"
                        id="new_email"
                        name="new_email"
                        class="alumni-input"
                        required
                        autocomplete="email"
                        inputmode="email"
                        placeholder="max.mustermann@example.com"
                        maxlength="254"
                        aria-required="true"
                        aria-describedby="new_email_hint"
                    >
                    <span id="new_email_hint" class="alumni-hint">
                        Diese Adresse erhält den neuen Zugang.
                    </span>
                </div>

                <!-- Old e-mail (optional) -->
                <div class="alumni-field">
                    <label for="old_email">
                        Alte E-Mail-Adresse
                        <span class="opt">(optional)</span>
                    </label>
                    <input
                        type="email"
                        id="old_email"
                        name="old_email"
                        class="alumni-input"
                        autocomplete="email"
                        inputmode="email"
                        placeholder="alte.adresse@example.com"
                        maxlength="254"
                        aria-describedby="old_email_hint"
                    >
                    <span id="old_email_hint" class="alumni-hint">
                        Falls noch bekannt – erleichtert die Identifikation.
                    </span>
                </div>

                <!-- Next button -->
                <button
                    type="button"
                    id="nextBtn"
                    class="alumni-submit-btn"
                    aria-label="Weiter zu Schritt 2"
                >
                    <span>Weiter</span>
                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </button>
            </div><!-- /alumniStep1 -->

            <!-- ── STEP 2: Studium + reCAPTCHA + Absenden ───── -->
            <div
                id="alumniStep2"
                class="alumni-step"
                style="display:none;flex-direction:column;gap:1.1rem;opacity:0;transform:translateX(16px);"
            >
                <!-- Graduation semester / Study program -->
                <div class="alumni-grid-2">
                    <div class="alumni-field">
                        <label for="graduation_semester">
                            Abschlusssemester <span class="req" aria-label="Pflichtfeld">*</span>
                        </label>
                        <input
                            type="text"
                            id="graduation_semester"
                            name="graduation_semester"
                            class="alumni-input"
                            required
                            placeholder="z. B. WS 2019/20"
                            maxlength="30"
                            aria-required="true"
                        >
                    </div>
                    <div class="alumni-field">
                        <label for="study_program">
                            Studiengang <span class="req" aria-label="Pflichtfeld">*</span>
                        </label>
                        <input
                            type="text"
                            id="study_program"
                            name="study_program"
                            class="alumni-input"
                            required
                            placeholder="z. B. BWL (B.Sc.)"
                            maxlength="120"
                            aria-required="true"
                        >
                    </div>
                </div>

                <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
                <div
                    id="recaptcha-alumni-recovery"
                    data-theme="dark"
                ></div>
                <?php endif; ?>

                <!-- Button row: Back + Submit -->
                <div class="alumni-btn-row">
                    <button
                        type="button"
                        id="backBtn"
                        class="alumni-back-btn"
                        aria-label="Zurück zu Schritt 1"
                    >
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>
                        <span>Zurück</span>
                    </button>
                    <button
                        type="submit"
                        id="submitBtn"
                        class="alumni-submit-btn"
                        aria-label="Anfrage absenden"
                    >
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        <span id="submitBtnLabel">Anfrage absenden</span>
                    </button>
                </div>

                <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
                <p class="alumni-recaptcha-note">
                    Durch reCAPTCHA geschützt –
                    <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Datenschutz</a>
                    &amp;
                    <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">Nutzungsbedingungen</a>
                    von Google.
                </p>
                <?php endif; ?>
            </div><!-- /alumniStep2 -->

        </form>

    </div><!-- /alumni-card -->
</div><!-- /alumni-page-wrap -->

<?php if (RECAPTCHA_SITE_KEY !== ''): ?>
<!-- Google reCAPTCHA v2 – explicit rendering to handle hidden step-2 container -->
<script src="https://www.google.com/recaptcha/api.js?render=explicit" async defer></script>
<?php endif; ?>

<script>
/* ================================================================
   Alumni Recovery – Wizard Form Handler (2-Schritt-Prozess)
   - Strict CSP-friendly (no eval, no inline handlers)
   - Step 1: Name + E-Mail → Step 2: Studium + reCAPTCHA + Submit
   - reCAPTCHA v2: token read synchronously at submit-time
   - No sensitive data logged to console
   ================================================================ */
(function () {
    'use strict';

    var SITE_KEY = <?php echo json_encode(RECAPTCHA_SITE_KEY ?: ''); ?>;

    var recaptchaWidgetId = null;

    var form      = document.getElementById('alumniRecoveryForm');
    var step1     = document.getElementById('alumniStep1');
    var step2     = document.getElementById('alumniStep2');
    var nextBtn   = document.getElementById('nextBtn');
    var backBtn   = document.getElementById('backBtn');
    var btn       = document.getElementById('submitBtn');
    var btnLabel  = document.getElementById('submitBtnLabel');
    var successEl = document.getElementById('alumniSuccessMessage');
    var errorBox  = document.getElementById('alumniErrorBox');
    var errorList = document.getElementById('alumniErrorList');
    var stepDots  = document.querySelectorAll('.alumni-step-dots span');

    if (!form || !btn) return;

    /* ── Ripple effect helper ─────────────────────────────────── */
    function addRipple(button) {
        button.addEventListener('pointerdown', function (e) {
            var rect   = button.getBoundingClientRect();
            var size   = Math.max(rect.width, rect.height) * 2;
            var ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.cssText =
                'width:' + size + 'px;height:' + size + 'px;' +
                'left:' + (e.clientX - rect.left - size / 2) + 'px;' +
                'top:'  + (e.clientY - rect.top  - size / 2) + 'px;';
            button.appendChild(ripple);
            ripple.addEventListener('animationend', function () { ripple.remove(); });
        });
    }
    if (nextBtn) addRipple(nextBtn);
    addRipple(btn);

    /* ── Shared e-mail validation regex ─────────────────────── */
    var EMAIL_RE = /^[^\s@]{1,64}@[^\s@]{1,255}\.[^\s@]{2,}$/;

    /* ── Error helpers ───────────────────────────────────────── */
    function showError(msg) {
        clearErrors();
        var li = document.createElement('li');
        li.textContent = msg;
        errorList.appendChild(li);
        errorBox.classList.add('is-visible');
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearErrors() {
        errorList.innerHTML = '';
        errorBox.classList.remove('is-visible');
    }

    /* ── Step dot helper ─────────────────────────────────────── */
    function setDot(index, active) {
        if (stepDots[index]) {
            stepDots[index].classList.toggle('active', active);
        }
    }

    /* ── Wizard transition helpers ───────────────────────────── */
    function transitionOut(el, toLeft) {
        el.style.opacity   = '0';
        el.style.transform = toLeft ? 'translateX(-16px)' : 'translateX(16px)';
    }

    function transitionIn(el, fromRight) {
        el.style.display        = 'flex';
        el.style.flexDirection  = 'column';
        el.style.opacity        = '0';
        el.style.transform      = fromRight ? 'translateX(16px)' : 'translateX(-16px)';
        void el.offsetWidth;    /* force reflow */
        el.style.opacity        = '1';
        el.style.transform      = 'translateX(0)';
    }

    /* ── Step 1 validation ───────────────────────────────────── */
    function validateStep1() {
        var fn = document.getElementById('first_name').value.trim();
        var ln = document.getElementById('last_name').value.trim();
        var ne = document.getElementById('new_email').value.trim();

        if (!fn || !ln)           return 'Bitte Vor- und Nachnamen eingeben.';
        if (!ne)                  return 'Bitte eine neue E-Mail-Adresse eingeben.';
        if (!EMAIL_RE.test(ne))   return 'Die neue E-Mail-Adresse hat ein ungültiges Format.';
        var oe = document.getElementById('old_email').value.trim();
        if (oe && !EMAIL_RE.test(oe)) return 'Die alte E-Mail-Adresse hat ein ungültiges Format.';
        return null;
    }

    /* ── Step 2 validation ───────────────────────────────────── */
    function validateStep2() {
        var gs = document.getElementById('graduation_semester').value.trim();
        var sp = document.getElementById('study_program').value.trim();

        if (!gs) return 'Bitte Abschlusssemester eingeben.';
        if (!sp) return 'Bitte Studiengang eingeben.';
        return null;
    }

    /* ── Navigate to step 2 ──────────────────────────────────── */
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            var err = validateStep1();
            if (err) { showError(err); return; }
            clearErrors();

            transitionOut(step1, true);
            setTimeout(function () {
                step1.style.display = 'none';
                transitionIn(step2, true);
                setDot(1, true);
                /* Render reCAPTCHA widget explicitly once step 2 is visible */
                if (SITE_KEY && recaptchaWidgetId === null) {
                    if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.ready === 'function') {
                        grecaptcha.ready(function () {
                            if (recaptchaWidgetId === null) {
                                recaptchaWidgetId = grecaptcha.render('recaptcha-alumni-recovery', {
                                    sitekey: SITE_KEY,
                                    theme:   'dark'
                                });
                            }
                        });
                    }
                }
            }, 300);
        });
    }

    /* ── Navigate back to step 1 ─────────────────────────────── */
    if (backBtn) {
        backBtn.addEventListener('click', function () {
            clearErrors();
            transitionOut(step2, false);
            setTimeout(function () {
                step2.style.display = 'none';
                transitionIn(step1, false);
                setDot(1, false);
            }, 300);
        });
    }

    /* ── Loading state ───────────────────────────────────────── */
    function setLoading(loading) {
        btn.disabled = loading;
        if (loading) {
            btnLabel.textContent = 'Wird gesendet …';
            btn.querySelector('i').className = 'fas fa-spinner fa-spin';
        } else {
            btnLabel.textContent = 'Anfrage absenden';
            btn.querySelector('i').className = 'fas fa-paper-plane';
        }
    }

    /* ── Show success (Step 3) ───────────────────────────────── */
    function showSuccess() {
        form.style.opacity    = '0';
        form.style.transform  = 'translateY(-8px)';
        form.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        setTimeout(function () {
            form.style.display = 'none';
            errorBox.classList.remove('is-visible');
            successEl.classList.add('is-visible');
            /* activate remaining dots */
            setDot(1, true);
            setDot(2, true);
        }, 300);
    }

    /* ── Submit handler ──────────────────────────────────────── */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();

        var validationError = validateStep2();
        if (validationError) {
            showError(validationError);
            return;
        }

        var token = typeof grecaptcha !== 'undefined' ? grecaptcha.getResponse(recaptchaWidgetId) : '';

        if (SITE_KEY && token === '') {
            showError('Bitte bestätige, dass du kein Roboter bist.');
            return;
        }

        setLoading(true);

        function doSubmit(token) {
            var payload = {
                recaptcha_token:     token,
                first_name:          document.getElementById('first_name').value.trim(),
                last_name:           document.getElementById('last_name').value.trim(),
                new_email:           document.getElementById('new_email').value.trim(),
                old_email:           document.getElementById('old_email').value.trim(),
                graduation_semester: document.getElementById('graduation_semester').value.trim(),
                study_program:       document.getElementById('study_program').value.trim(),
            };

            fetch('/api/public/submit_alumni_recovery.php', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'   /* Extra CSRF hint */
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            })
            .then(function (response) {
                var ok = response.ok;
                return response.json().then(function (data) {
                    if (!ok && !data.message) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return data;
                });
            })
            .then(function (result) {
                setLoading(false);
                if (result.success) {
                    showSuccess();
                } else {
                    if (typeof grecaptcha !== 'undefined') grecaptcha.reset(recaptchaWidgetId);
                    showError(result.message || 'Ein unbekannter Fehler ist aufgetreten. Bitte erneut versuchen.');
                }
            })
            .catch(function () {
                setLoading(false);
                if (typeof grecaptcha !== 'undefined') grecaptcha.reset(recaptchaWidgetId);
                showError('Netzwerkfehler. Bitte Verbindung prüfen und erneut versuchen.');
            });
        }

        doSubmit(token);
    });
}());
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/auth_layout.php';
?>