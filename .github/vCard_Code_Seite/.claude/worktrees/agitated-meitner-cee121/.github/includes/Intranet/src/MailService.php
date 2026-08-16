<?php
/**
 * Mail Service
 * Handles email sending using SMTP configuration with IBC Corporate Design
 */

require_once __DIR__ . '/../config/config.php';

// Check if vendor autoload exists and load it
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Check if PHPMailer classes are available
define('MAIL_SERVICE_VENDOR_AVAILABLE', class_exists('PHPMailer\PHPMailer\PHPMailer'));

class MailService {
    
    /**
     * Check if vendor autoload is available
     * @return bool True if vendor autoload is missing
     */
    private static function isVendorMissing() {
        return !MAIL_SERVICE_VENDOR_AVAILABLE;
    }
    
    /**
     * Get the professional HTML email template with IBC corporate design
     * 
     * @param string $title Email title/heading
     * @param string $bodyContent Main body content (HTML)
     * @param string|null $callToAction Optional call-to-action button HTML
     * @return string Complete HTML email template
     */
    public static function getTemplate($title, $bodyContent, $callToAction = null) {
        $html = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .email-wrapper {
            width: 100%;
            background-color: #f3f4f6;
            padding: 20px 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .email-header {
            background-color: #20234A;
            padding: 30px 20px;
            text-align: center;
        }
        .email-header img {
            max-width: 200px;
            height: auto;
        }
        .email-body {
            padding: 30px 40px;
        }
        .email-title {
            color: #6D9744;
            font-size: 24px;
            font-weight: bold;
            margin: 0 0 20px 0;
        }
        .email-text {
            color: #333;
            font-size: 16px;
            margin: 15px 0;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background-color: #f9fafb;
            border-radius: 6px;
            overflow: hidden;
        }
        .info-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        .info-table tr:last-child {
            border-bottom: none;
        }
        .info-table td {
            padding: 12px 15px;
            font-size: 15px;
        }
        .info-table td:first-child {
            font-weight: bold;
            color: #6D9744;
            width: 35%;
        }
        .button-container {
            text-align: center;
            margin: 25px 0;
        }
        .button {
            display: inline-block;
            padding: 14px 30px;
            background-color: #6D9744;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .button:hover {
            background-color: #5d8038;
        }
        .email-footer {
            background-color: #f3f4f6;
            padding: 20px 40px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        .email-footer a {
            color: #6D9744;
            text-decoration: none;
        }
        .email-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <div class="email-header">
                <img src="cid:ibc_logo" alt="IBC Logo" style="max-width: 100%; height: auto; display: block;" />
            </div>
            <div class="email-body">
                <h1 class="email-title">' . htmlspecialchars($title) . '</h1>
                ' . $bodyContent . '
                ' . ($callToAction ? '<div class="button-container">' . $callToAction . '</div>' : '') . '
            </div>
            <div class="email-footer">
                <p>Diese E-Mail wurde automatisch vom IBC Intranet generiert.</p>
                <p><a href="' . BASE_URL . '">IBC Intranet</a> | <a href="' . BASE_URL . '/pages/impressum.php">Impressum</a></p>
                <p>&copy; ' . date('Y') . ' IBC - Alle Rechte vorbehalten.</p>
            </div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Get a beautiful, modern HTML email template with Apple/Stripe-inspired design
     * 
     * Features:
     * - Clean, modern design similar to Apple and Stripe emails
     * - Centered container with subtle shadow
     * - IBC logo with nice header background
     * - Readable sans-serif fonts with optimal line height
     * - Fully responsive design
     * - Compatible with Outlook, Gmail, and mobile devices
     * - Table-based layout for maximum email client compatibility
     * 
     * @param string $title Email title/subject
     * @param string $content Main content body (HTML). IMPORTANT: Must be pre-sanitized trusted HTML. 
     *                        This content is inserted directly into the template without further sanitization.
     * @param string|null $ctaLink Optional call-to-action link URL
     * @param string|null $ctaText Optional call-to-action button text
     * @return string Complete HTML email template
     */
    public static function getBeautifulEmailTemplate(string $title, string $content, ?string $ctaLink = null, ?string $ctaText = null): string {
        // Build CTA button if both link and text are provided
        $ctaButton = '';
        if ($ctaLink && $ctaText) {
            $ctaButton = '
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 30px auto;">
                    <tr>
                        <td style="border-radius: 8px; background: linear-gradient(135deg, #6D9744 0%, #5d8038 100%); text-align: center;">
                            <a href="' . htmlspecialchars($ctaLink) . '" target="_blank" style="background: transparent; border: none; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px; padding: 16px 40px; display: inline-block; mso-padding-alt: 0; text-transform: none;">
                                <!--[if mso]>
                                <i style="letter-spacing: 25px; mso-font-width: -100%; mso-text-raise: 30pt;">&nbsp;</i>
                                <![endif]-->
                                <span style="mso-text-raise: 15pt;">' . htmlspecialchars($ctaText) . '</span>
                                <!--[if mso]>
                                <i style="letter-spacing: 25px; mso-font-width: -100%;">&nbsp;</i>
                                <![endif]-->
                            </a>
                        </td>
                    </tr>
                </table>';
        }
        
        $html = '<!DOCTYPE html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>' . htmlspecialchars($title) . '</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td {font-family: Arial, Helvetica, sans-serif !important;}
    </style>
    <![endif]-->
    <style type="text/css">
        /* Reset styles */
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table {
            border-spacing: 0;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        table td {
            border-collapse: collapse;
        }
        img {
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            -ms-interpolation-mode: bicubic;
        }
        
        /* Main styles */
        body {
            background-color: #f5f5f7;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #1d1d1f;
        }
        
        .email-wrapper {
            width: 100%;
            background-color: #f5f5f7;
            padding: 40px 0;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        
        .email-header {
            background: linear-gradient(135deg, #20234A 0%, #2a2e5c 100%);
            padding: 40px 30px;
            text-align: center;
        }
        
        .email-header img {
            max-width: 180px;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        
        .email-body {
            padding: 40px 30px;
        }
        
        .email-title {
            color: #1d1d1f;
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 24px 0;
            line-height: 1.3;
            letter-spacing: -0.02em;
        }
        
        .email-content {
            color: #1d1d1f;
            font-size: 16px;
            line-height: 1.7;
            margin: 0 0 20px 0;
        }
        
        .email-content p {
            margin: 0 0 16px 0;
        }
        
        .email-content p:last-child {
            margin-bottom: 0;
        }
        
        .cta-button {
            background: linear-gradient(135deg, #6D9744 0%, #5d8038 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            padding: 16px 40px;
            display: inline-block;
            font-weight: 600;
            font-size: 16px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(109, 151, 68, 0.3);
        }
        
        .email-footer {
            background-color: #f5f5f7;
            padding: 30px;
            text-align: center;
            font-size: 13px;
            color: #86868b;
            line-height: 1.5;
        }
        
        .email-footer p {
            margin: 8px 0;
        }
        
        .email-footer a {
            color: #6D9744;
            text-decoration: none;
            font-weight: 500;
        }
        
        /* Responsive styles */
        @media screen and (max-width: 600px) {
            .email-wrapper {
                padding: 20px 0 !important;
            }
            .email-container {
                width: 100% !important;
                border-radius: 0 !important;
            }
            .email-header {
                padding: 30px 20px !important;
            }
            .email-body {
                padding: 30px 20px !important;
            }
            .email-title {
                font-size: 24px !important;
            }
            .email-content {
                font-size: 15px !important;
            }
            .email-footer {
                padding: 25px 20px !important;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .email-content {
                color: #1d1d1f;
            }
            .email-title {
                color: #1d1d1f;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f7;">
    <!-- Email wrapper table for Outlook -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f7;">
        <tr>
            <td style="padding: 40px 0;">
                <!-- Main container table -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" align="center" style="margin: 0 auto; max-width: 600px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #20234A 0%, #2a2e5c 100%); padding: 40px 30px; text-align: center; border-radius: 12px 12px 0 0;">
                            <img src="cid:ibc_logo" alt="IBC Logo" style="max-width: 180px; height: auto; display: block; margin: 0 auto;" />
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h1 style="color: #1d1d1f; font-size: 28px; font-weight: 700; margin: 0 0 24px 0; line-height: 1.3; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">' . htmlspecialchars($title) . '</h1>
                            <div style="color: #1d1d1f; font-size: 16px; line-height: 1.7; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
                                ' . $content . '
                            </div>
                            ' . $ctaButton . '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f5f5f7; padding: 30px; text-align: center; font-size: 13px; color: #86868b; border-radius: 0 0 12px 12px;">
                            <p style="margin: 8px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">Diese E-Mail wurde automatisch vom IBC Intranet generiert.</p>
                            <p style="margin: 8px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
                                <a href="' . BASE_URL . '" style="color: #6D9744; text-decoration: none; font-weight: 500;">IBC Intranet</a> | 
                                <a href="' . BASE_URL . '/pages/impressum.php" style="color: #6D9744; text-decoration: none; font-weight: 500;">Impressum</a>
                            </p>
                            <p style="margin: 8px 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">&copy; ' . date('Y') . ' IBC - Alle Rechte vorbehalten.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Get a festive birthday email template with celebratory design
     * 
     * @param string $firstName User's first name for personalization
     * @param string $gender User's gender ('m', 'f', or other) for proper salutation
     * @return string Complete HTML birthday email template
     */
    public static function getBirthdayEmailTemplate(string $firstName, string $gender = ''): string {
        // Load the new responsive email templates
        require_once __DIR__ . '/../includes/templates/email_templates.php';
        
        // Use the new responsive birthday template
        return EmailTemplates::getBirthdayTemplate($firstName, $gender);
    }
    
    /**
     * Get profile reminder email template
     * Uses the modern responsive profile reminder email design from EmailTemplates
     * 
     * @param string $firstName User's first name for personalization
     * @param string $profileLink URL to the profile page
     * @return string Complete HTML profile reminder email template
     */
    public static function getProfileReminderEmailTemplate(string $firstName, string $profileLink): string {
        // Load the new responsive email templates
        require_once __DIR__ . '/../includes/templates/email_templates.php';
        
        // Use the new responsive profile reminder template
        return EmailTemplates::getProfileReminderTemplate($firstName, $profileLink);
    }
    
    /**
     * Create and configure PHPMailer instance with SMTP settings
     * SMTPDebug is hard-coded to 0 (disabled) by default for security.
     * Debug mode is only enabled when ENVIRONMENT === 'development'.
     * 
     * @param bool $enableDebug Deprecated - Debug mode is controlled by ENVIRONMENT constant only
     * @deprecated Parameter is ignored. Debug mode is controlled by ENVIRONMENT constant.
     * @return \PHPMailer\PHPMailer\PHPMailer|null Configured PHPMailer instance or null if vendor missing
     * @throws \PHPMailer\PHPMailer\Exception If SMTP credentials are missing
     */
    private static function createMailer($enableDebug = false) {
        // Check if PHPMailer is available
        if (self::isVendorMissing()) {
            throw new \Exception("PHPMailer not available: Composer vendor missing");
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Get SMTP Host from configuration
            $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : ($_ENV['SMTP_HOST'] ?? '');
            
            // Check if SMTP_HOST is configured
            if (empty($smtpHost)) {
                // Fallback: Check if PHP mail() function is available
                if (function_exists('mail')) {
                    error_log("Warning: SMTP_HOST not configured in .env. Falling back to PHP mail() function.");
                    $mail->isMail(); // Use PHP's mail() function
                } else {
                    // Critical error: Neither SMTP nor mail() available
                    error_log("CRITICAL ERROR: SMTP_HOST not configured and PHP mail() function is not available. Cannot send email.");
                    throw new \Exception("Email configuration error: SMTP_HOST not set and PHP mail() not available");
                }
            } else {
                // SMTP configuration - load dynamically from constants or $_ENV
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = defined('SMTP_USER') ? SMTP_USER : ($_ENV['SMTP_USER'] ?? '');
                $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : ($_ENV['SMTP_PASS'] ?? '');
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : ($_ENV['SMTP_PORT'] ?? 587);
                
                // Validate SMTP credentials are configured
                if (empty($mail->Username) || empty($mail->Password)) {
                    error_log("Warning: SMTP credentials are not configured. Email sending may fail.");
                }
            }
            
            // Set sender (common for both SMTP and mail() fallback)
            $mail->setFrom(
                defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ($_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@localhost'),
                defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : ($_ENV['SMTP_FROM_NAME'] ?? 'IBC Intranet')
            );
            
            // Character encoding
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            
            // SMTPDebug is hard-coded to 0 (disabled) by default for security
            // Only enable in explicit development mode
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = 'error_log'; // Default to error_log for any debug output
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                $mail->SMTPDebug = 2; // Verbose debug output only in development
                $mail->Debugoutput = 'html'; // Format debug output for browser viewing
            }
            
        } catch (\Exception $e) {
            // Catch both PHPMailer exceptions and the vendor missing exception thrown when PHPMailer is unavailable
            error_log("Failed to configure PHPMailer: " . $e->getMessage());
            throw $e;
        }
        
        return $mail;
    }
    
    /**
     * Send test email with SMTP debug output enabled
     * 
     * @param string $toEmail Recipient email address
     * @return bool Success status
     */
    public static function sendTestMail($toEmail) {
        if (self::isVendorMissing()) {
            error_log("Cannot send test email: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer(true); // Enable debug output
            
            // Set recipient
            $mail->addAddress($toEmail);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email - SMTP Configuration';
            
            // Build simple test email body
            $bodyContent = '<p class="email-text">Dies ist eine Test-E-Mail zur Überprüfung der SMTP-Konfiguration.</p>
            <p class="email-text">Wenn Sie diese E-Mail erhalten, funktioniert die SMTP-Verbindung korrekt.</p>
            <p class="email-text">Konfiguration:</p>
            <table class="info-table">
                <tr>
                    <td>SMTP Host</td>
                    <td>' . htmlspecialchars(defined('SMTP_HOST') ? SMTP_HOST : ($_ENV['SMTP_HOST'] ?? 'N/A')) . '</td>
                </tr>
                <tr>
                    <td>SMTP Port</td>
                    <td>' . htmlspecialchars(defined('SMTP_PORT') ? SMTP_PORT : ($_ENV['SMTP_PORT'] ?? 'N/A')) . '</td>
                </tr>
                <tr>
                    <td>Von</td>
                    <td>' . htmlspecialchars(defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ($_ENV['SMTP_FROM_EMAIL'] ?? 'N/A')) . '</td>
                </tr>
            </table>';
            
            $mail->Body = self::getTemplate('SMTP Test', $bodyContent);
            
            // Embed logo
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            }
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            error_log("Test email sent successfully to {$toEmail}");
            return true;
            
        } catch (\Exception $e) {
            error_log("Failed to send test email to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send helper confirmation email with ICS attachment (Updated with new template)
     * 
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient name
     * @param array $event Event data
     * @param array|null $slot Slot data (if specific slot)
     * @param string $icsContent ICS file content
     * @param string $googleCalendarLink Google Calendar link
     * @return bool Success status
     */
    public static function sendHelperConfirmation($toEmail, $toName, $event, $slot, $icsContent, $googleCalendarLink) {
        if (self::isVendorMissing()) {
            error_log("Cannot send helper confirmation: Composer vendor missing");
            return false;
        }
        
        $subject = "Danke für deine Anmeldung als Helfer bei " . $event['title'];
        
        // Build email body with new template
        $body = self::buildHelperConfirmationBody($toName, $event, $slot, $googleCalendarLink);
        
        // Create filename for ICS attachment
        $icsFilename = 'helferschicht.ics';
        
        // Send email with attachment (has its own exception handling)
        return self::sendEmailWithAttachment(
            $toEmail,
            $toName,
            $subject,
            $body,
            $icsFilename,
            $icsContent
        );
    }
    
    /**
     * Build HTML email body for helper confirmation using new template
     * 
     * @param string $userName User name
     * @param array $event Event data
     * @param array|null $slot Slot data
     * @param string $googleCalendarLink Google Calendar link
     * @return string HTML email body
     */
    private static function buildHelperConfirmationBody($userName, $event, $slot, $googleCalendarLink) {
        // Determine times
        if ($slot !== null) {
            $start = new DateTime($slot['start_time']);
            $end = new DateTime($slot['end_time']);
            $when = $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i');
            $role = 'Helfer';
        } else {
            $start = new DateTime($event['start_time']);
            $end = new DateTime($event['end_time']);
            $when = $start->format('d.m.Y H:i') . ' - ' . $end->format('d.m.Y H:i');
            $role = 'Helfer';
        }
        
        // Build body content
        $bodyContent = '<p class="email-text">Hallo ' . htmlspecialchars($userName) . ',</p>
        <p class="email-text">Danke für deine Anmeldung als Helfer bei ' . htmlspecialchars($event['title']) . '! Hier sind die Details zu deinem Einsatz:</p>
        
        <table class="info-table">
            <tr>
                <td>Event</td>
                <td>' . htmlspecialchars($event['title']) . '</td>
            </tr>
            <tr>
                <td>Wann</td>
                <td>' . htmlspecialchars($when) . '</td>
            </tr>';
        
        if (!empty($event['location'])) {
            $bodyContent .= '<tr>
                <td>Wo</td>
                <td>' . htmlspecialchars($event['location']) . '</td>
            </tr>';
        }
        
        $bodyContent .= '<tr>
                <td>Rolle</td>
                <td>' . htmlspecialchars($role) . '</td>
            </tr>';
        
        if (!empty($event['contact_person'])) {
            $bodyContent .= '<tr>
                <td>Kontaktperson</td>
                <td>' . htmlspecialchars($event['contact_person']) . '</td>
            </tr>';
        }
        
        $bodyContent .= '</table>';
        
        if (!empty($event['description'])) {
            $bodyContent .= '<p class="email-text"><strong>Beschreibung:</strong><br>' . nl2br(htmlspecialchars($event['description'])) . '</p>';
        }
        
        $bodyContent .= '<p class="email-text">Die angehängte .ics-Datei kann in allen gängigen Kalender-Anwendungen (Outlook, Apple Calendar, etc.) verwendet werden.</p>
        <p class="email-text">Wir freuen uns auf deinen Einsatz!</p>';
        
        // Create call-to-action button for Google Calendar
        $callToAction = '<a href="' . htmlspecialchars($googleCalendarLink) . '" class="button" target="_blank">In Kalender speichern</a>';
        
        return self::getTemplate('Einsatzbestätigung', $bodyContent, $callToAction);
    }
    
    /**
     * Send event confirmation email for general event registration
     * 
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient name
     * @param array $event Event data with keys: id, title, description, location, start_time, end_time, contact_person
     * @return bool Success status
     */
    public static function sendEventConfirmation($toEmail, $toName, $event) {
        if (self::isVendorMissing()) {
            error_log("Cannot send event confirmation: Composer vendor missing");
            return false;
        }
        
        $subject = "Anmeldebestätigung: " . $event['title'];
        
        // Build email body
        $body = self::buildEventConfirmationBody($toName, $event);
        
        // Send email (has its own exception handling)
        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $body);
    }
    
    /**
     * Build HTML email body for event confirmation
     * 
     * @param string $userName User name
     * @param array $event Event data
     * @return string HTML email body
     */
    private static function buildEventConfirmationBody($userName, $event) {
        // Format dates
        $start = new DateTime($event['start_time']);
        $end = new DateTime($event['end_time']);
        
        // Check if event spans multiple days
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            // Same day event
            $when = $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i');
        } else {
            // Multi-day event
            $when = $start->format('d.m.Y H:i') . ' - ' . $end->format('d.m.Y H:i');
        }
        
        // Build body content
        $bodyContent = '<p class="email-text">Hallo ' . htmlspecialchars($userName) . ',</p>
        <p class="email-text">vielen Dank für deine Anmeldung! Hier sind die Details zum Event:</p>
        
        <table class="info-table">
            <tr>
                <td>Event</td>
                <td>' . htmlspecialchars($event['title']) . '</td>
            </tr>
            <tr>
                <td>Wann</td>
                <td>' . htmlspecialchars($when) . '</td>
            </tr>';
        
        if (!empty($event['location'])) {
            $bodyContent .= '<tr>
                <td>Wo</td>
                <td>' . htmlspecialchars($event['location']) . '</td>
            </tr>';
        }
        
        if (!empty($event['contact_person'])) {
            $bodyContent .= '<tr>
                <td>Kontaktperson</td>
                <td>' . htmlspecialchars($event['contact_person']) . '</td>
            </tr>';
        }
        
        $bodyContent .= '</table>';
        
        if (!empty($event['description'])) {
            $bodyContent .= '<p class="email-text"><strong>Beschreibung:</strong><br>' . nl2br(htmlspecialchars($event['description'])) . '</p>';
        }
        
        $bodyContent .= '<p class="email-text">Wir freuen uns auf deine Teilnahme!</p>';
        
        // Create call-to-action button
        $eventLink = BASE_URL . '/pages/events/index.php';
        $callToAction = '<a href="' . htmlspecialchars($eventLink) . '" class="button">Zu den Events</a>';
        
        return self::getTemplate('Anmeldebestätigung', $bodyContent, $callToAction);
    }
    
    /**
     * Send email with attachment and embedded logo using PHPMailer with SMTP
     * 
     * @param string $toEmail Recipient email
     * @param string $toName Recipient name
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string $attachmentFilename Attachment filename
     * @param string $attachmentContent Attachment content
     * @return bool Success status
     */
    private static function sendEmailWithAttachment($toEmail, $toName, $subject, $htmlBody, $attachmentFilename, $attachmentContent) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email with attachment: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer();
            
            // Set recipient
            $mail->addAddress($toEmail, $toName);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            
            // Embed logo
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            }
            
            // Add ICS attachment
            $mail->addStringAttachment($attachmentContent, $attachmentFilename, 'base64', 'text/calendar');
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            error_log("Successfully sent helper confirmation email to {$toEmail}");
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending email to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send email with embedded logo (no attachment)
     * 
     * @param string $toEmail Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @return bool Success status
     */
    private static function sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email with embedded image: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer();
            
            // Set recipient
            $mail->addAddress($toEmail);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            
            // Embed logo
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            }
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            error_log("Successfully sent invitation email to {$toEmail}");
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending email to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send a simple email (used for waitlist notifications, etc.)
     * 
     * @param string $toEmail Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @return bool Success status
     */
    public static function sendEmail($toEmail, $subject, $htmlBody) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer();
            
            // Set recipient
            $mail->addAddress($toEmail);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            
            // Embed logo so cid:ibc_logo is rendered in the email template
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            } else {
                error_log("MailService::sendEmail: Logo file not found; email logo (cid:ibc_logo) will not render.");
            }
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending email to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generic send method with attachment support
     * 
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param array $attachments Optional array of file paths to attach
     * @return bool Success status
     */
    public static function send($to, $subject, $body, $attachments = []) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer();
            
            // Set recipient
            $mail->addAddress($to);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // Add attachments if provided
            if (!empty($attachments) && is_array($attachments)) {
                foreach ($attachments as $filePath) {
                    if (file_exists($filePath)) {
                        $mail->addAttachment($filePath);
                    } else {
                        error_log("Warning: Attachment file not found: {$filePath}");
                    }
                }
            }
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending email to {$to}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send project acceptance notification email
     * 
     * @param string $toEmail Recipient email address
     * @param array $project Project data
     * @param string $role Assigned role (lead or member)
     * @return bool Success status
     */
    public static function sendProjectAcceptance($toEmail, $project, $role) {
        if (self::isVendorMissing()) {
            error_log("Cannot send project acceptance: Composer vendor missing");
            return false;
        }
        
        $subject = "Projektzusage: " . $project['title'];
        
        // Build body content
        $roleName = ($role === 'lead') ? 'Projektleitung' : 'Projektmitglied';
        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">deine Bewerbung für das Projekt "<strong>' . htmlspecialchars($project['title']) . '</strong>" wurde akzeptiert!</p>
        <p class="email-text">Du wurdest als <strong>' . htmlspecialchars($roleName) . '</strong> zum Projekt hinzugefügt.</p>
        <p class="email-text">Weitere Details zum Projekt findest du im IBC Intranet.</p>';
        
        // Create call-to-action button
        $projectLink = BASE_URL . '/pages/projects/manage.php';
        $callToAction = '<a href="' . htmlspecialchars($projectLink) . '" class="button">Zum Projekt</a>';
        
        // Get complete HTML template
        $htmlBody = self::getTemplate('Projektzusage', $bodyContent, $callToAction);
        
        // Send email (has its own exception handling)
        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }
    
    /**
     * Send project application status email (accepted or rejected)
     * 
     * @param string $userEmail Recipient email address
     * @param string $projectTitle Project title
     * @param string $status Status: 'accepted' or 'rejected'
     * @param int $projectId Project ID for linking to project view page
     * @param array|null $clientData Client data with 'name' and 'contact' keys (only for accepted status)
     * @return bool Success status
     */
    public static function sendProjectApplicationStatus($userEmail, $projectTitle, $status, $projectId, $clientData = null) {
        if (self::isVendorMissing()) {
            error_log("Cannot send project application status: Composer vendor missing");
            return false;
        }
        
        if ($status === 'accepted') {
            $subject = "Projektzusage: " . $projectTitle;
            $htmlBody = self::buildProjectApplicationAcceptedBody($projectTitle, $projectId, $clientData);
            return self::sendEmailWithEmbeddedImage($userEmail, $subject, $htmlBody);
        } elseif ($status === 'rejected') {
            $subject = "Projektbewerbung: " . $projectTitle;
            $htmlBody = self::buildProjectApplicationRejectedBody($projectTitle);
            return self::sendEmailWithEmbeddedImage($userEmail, $subject, $htmlBody);
        }
        
        error_log("Invalid status '{$status}' for sendProjectApplicationStatus");
        return false;
    }
    
    /**
     * Build HTML body for project application acceptance email
     * 
     * @param string $projectTitle Project title
     * @param int $projectId Project ID for linking to project view page
     * @param array|null $clientData Client data with 'name' and 'contact' keys
     * @return string HTML email body
     */
    private static function buildProjectApplicationAcceptedBody($projectTitle, $projectId, $clientData) {
        // Build body content
        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">wir freuen uns, dir mitteilen zu können, dass deine Bewerbung für das Projekt "<strong>' . htmlspecialchars($projectTitle) . '</strong>" <strong>angenommen</strong> wurde!</p>';
        
        // Add client data if provided
        if ($clientData !== null && is_array($clientData)) {
            $bodyContent .= '<p class="email-text">Nachfolgend findest du die Kontaktdaten des Auftraggebers:</p>
            <table class="info-table">
                <tr>
                    <td>Name</td>
                    <td>' . htmlspecialchars($clientData['name'] ?? 'N/A') . '</td>
                </tr>
                <tr>
                    <td>Kontakt</td>
                    <td>' . htmlspecialchars($clientData['contact'] ?? 'N/A') . '</td>
                </tr>
            </table>';
        }
        
        // Add confidentiality notice
        $bodyContent .= '<p class="email-text" style="margin-top: 25px; padding: 15px; background-color: #fff3cd; border-left: 4px solid #ffc107; color: #856404;">
            <strong>⚠️ Vertraulichkeit:</strong><br>
            Bitte behandle alle Informationen zu diesem Projekt und insbesondere die Kundendaten streng vertraulich. 
            Diese Informationen dürfen nicht an Dritte weitergegeben werden.
        </p>';
        
        $bodyContent .= '<p class="email-text">Weitere Details zum Projekt findest du im IBC Intranet.</p>';
        
        // Create call-to-action button
        $projectLink = BASE_URL . '/pages/projects/view.php?id=' . intval($projectId);
        $callToAction = '<a href="' . htmlspecialchars($projectLink) . '" class="button">Zum Projekt</a>';
        
        // Get complete HTML template
        return self::getTemplate('Projektzusage', $bodyContent, $callToAction);
    }
    
    /**
     * Build HTML body for project application rejection email
     * 
     * @param string $projectTitle Project title
     * @return string HTML email body
     */
    private static function buildProjectApplicationRejectedBody($projectTitle) {
        // Build body content
        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">vielen Dank für dein Interesse am Projekt "<strong>' . htmlspecialchars($projectTitle) . '</strong>".</p>
        <p class="email-text">Leider müssen wir dir mitteilen, dass wir uns diesmal für andere Bewerber entschieden haben.</p>
        <p class="email-text">Diese Entscheidung war nicht einfach und bedeutet keine Bewertung deiner Fähigkeiten. 
        Wir ermutigen dich, dich auch in Zukunft auf weitere interessante Projekte zu bewerben.</p>
        <p class="email-text">Bei Fragen stehen wir dir gerne zur Verfügung.</p>
        <p class="email-text">Wir wünschen dir weiterhin viel Erfolg!</p>';
        
        // Create call-to-action button
        $projectsLink = BASE_URL . '/pages/projects/index.php';
        $callToAction = '<a href="' . htmlspecialchars($projectsLink) . '" class="button">Weitere Projekte ansehen</a>';
        
        // Get complete HTML template
        return self::getTemplate('Projektbewerbung', $bodyContent, $callToAction);
    }
    
    /**
     * Send team completion notification to project leads
     * 
     * @param string $toEmail Recipient email address
     * @param string $projectTitle Project title
     * @return bool Success status
     */
    public static function sendTeamCompletionNotification($toEmail, $projectTitle) {
        if (self::isVendorMissing()) {
            error_log("Cannot send team completion notification: Composer vendor missing");
            return false;
        }
        
        $subject = "Team vollständig: " . $projectTitle;
        
        // Build body content
        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">das Team für das Projekt "<strong>' . htmlspecialchars($projectTitle) . '</strong>" ist jetzt vollständig besetzt.</p>
        <p class="email-text">Der Projektstatus wurde automatisch aktualisiert.</p>
        <p class="email-text">Weitere Details zum Projekt findest du im IBC Intranet.</p>';
        
        // Create call-to-action button
        $projectLink = BASE_URL . '/pages/projects/manage.php';
        $callToAction = '<a href="' . htmlspecialchars($projectLink) . '" class="button">Zum Projekt</a>';
        
        // Get complete HTML template
        $htmlBody = self::getTemplate('Team vollständig', $bodyContent, $callToAction);
        
        // Send email (has its own exception handling)
        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }
    
    /**
     * Send alumni profile verification reminder email
     * 
     * @param string $toEmail Recipient email address
     * @param string $firstName Recipient first name
     * @return bool Success status
     */
    public static function sendAlumniReminder($toEmail, $firstName) {
        if (self::isVendorMissing()) {
            error_log("Cannot send alumni reminder: Composer vendor missing");
            return false;
        }
        
        $subject = "Bitte aktualisiere dein IBC Alumni Profil";
        
        // Build body content
        $bodyContent = '<p class="email-text">Hallo ' . htmlspecialchars($firstName) . ',</p>
        <p class="email-text">Dein Profil wurde seit über einem Jahr nicht aktualisiert. 
        Bitte prüfe, ob deine Job-Daten noch aktuell sind, damit wir in Kontakt bleiben können.</p>
        <p class="email-text">Bitte nimm dir einen Moment Zeit, um dein Profil zu überprüfen und bei Bedarf zu aktualisieren.</p>';
        
        // Create call-to-action button with link to edit page
        $editLink = BASE_URL . '/pages/alumni/edit.php';
        $callToAction = '<a href="' . htmlspecialchars($editLink) . '" class="button">Profil aktualisieren</a>';
        
        // Get complete HTML template
        $htmlBody = self::getTemplate('Alumni Profil Aktualisierung', $bodyContent, $callToAction);
        
        // Send email (has its own exception handling)
        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }
    
    /**
     * Send email change confirmation email
     * 
     * @param string $toEmail New email address to send confirmation to
     * @param string $token Confirmation token
     * @return bool Success status
     */
    public static function sendEmailChangeConfirmation($toEmail, $token) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email change confirmation: Composer vendor missing");
            return false;
        }
        
        $subject = "E-Mail-Adresse bestätigen - IBC Intranet";
        
        // Build confirmation link
        $confirmLink = BASE_URL . '/api/public/confirm_email.php?token=' . urlencode($token);
        
        // Build body content
        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">du hast eine Änderung deiner E-Mail-Adresse im IBC Intranet beantragt.</p>
        <p class="email-text">Um die neue E-Mail-Adresse zu bestätigen und die Änderung abzuschließen, klicke bitte auf den folgenden Button:</p>';
        
        // Create call-to-action button
        $callToAction = '<a href="' . htmlspecialchars($confirmLink) . '" class="button">E-Mail-Adresse bestätigen</a>';
        
        $bodyContent .= '<p class="email-text" style="margin-top: 20px; font-size: 14px; color: #6b7280;">Dieser Bestätigungslink ist 24 Stunden gültig. Falls du diese Änderung nicht beantragt hast, ignoriere diese E-Mail einfach.</p>';
        
        // Get complete HTML template
        $htmlBody = self::getTemplate('E-Mail-Adresse bestätigen', $bodyContent, $callToAction);
        
        // Send email without attachment but with embedded logo (has its own exception handling)
        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }
    
    /**
     * Send activation email to a newly added Entra user
     *
     * @param string $email Recipient email address
     * @return bool Success status
     */
    public static function sendActivationEmail($email) {
        $subject = 'Dein IBC Intranet Zugang wurde aktiviert';

        $bodyContent = '<p class="email-text">Hallo,</p>
        <p class="email-text">Dein Zugang zum IBC Intranet wurde aktiviert. Du kannst dich ab sofort mit deinem Microsoft-Konto anmelden.</p>';

        $htmlBody = self::getTemplate('Willkommen im IBC Intranet', $bodyContent);

        return self::sendEmailWithEmbeddedImage($email, $subject, $htmlBody);
    }

    /**
     * Send email with file attachment from file path
     * 
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML email body
     * @param string $filePath Absolute path to file to attach
     * @return bool Success status
     */
    public static function sendEmailWithFileAttachment($toEmail, $subject, $htmlBody, $filePath) {
        if (self::isVendorMissing()) {
            error_log("Cannot send email with file attachment: Composer vendor missing");
            return false;
        }
        
        try {
            $mail = self::createMailer();
            
            // Set recipient
            $mail->addAddress($toEmail);
            
            // Email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            
            // Embed logo
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            }
            
            // Add file attachment if it exists
            if (file_exists($filePath)) {
                $mail->addAttachment($filePath);
            } else {
                error_log("Warning: Attachment file not found: {$filePath}");
            }
            
            // Send (with output buffering to capture any debug output)
            ob_start();
            $mail->send();
            ob_end_clean();
            error_log("Successfully sent email with attachment to {$toEmail}");
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending email with attachment to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send blog newsletter email to a single subscriber
     *
     * @param string $toEmail Recipient email address
     * @param string $firstName Recipient first name (for personalization)
     * @param string $postTitle Title of the new blog post
     * @param string $postExcerpt Short excerpt/preview of the post content
     * @param int $postId ID of the new blog post
     * @return bool Success status
     */
    public static function sendBlogNewsletter(string $toEmail, string $firstName, string $postTitle, string $postExcerpt, int $postId): bool {
        if (self::isVendorMissing()) {
            error_log("Cannot send blog newsletter: Composer vendor missing");
            return false;
        }

        $subject = 'Neuer Blog-Artikel: ' . $postTitle;

        $greeting = !empty($firstName) ? 'Hallo ' . htmlspecialchars($firstName) . ',' : 'Hallo,';

        $bodyContent = '<p class="email-text">' . $greeting . '</p>
        <p class="email-text">es gibt einen neuen Artikel im IBC Intranet Blog:</p>
        <p class="email-text"><strong>' . htmlspecialchars($postTitle) . '</strong></p>
        <p class="email-text">' . htmlspecialchars($postExcerpt) . '</p>';

        $postLink = BASE_URL . '/pages/blog/view.php?id=' . $postId;
        $callToAction = '<a href="' . htmlspecialchars($postLink) . '" class="button">Artikel lesen</a>';

        $settingsLink = BASE_URL . '/pages/auth/settings.php';
        $bodyContent .= '<p class="email-text" style="margin-top:20px;font-size:13px;color:#6b7280;">
            Du erhältst diese E-Mail, weil du den Blog-Newsletter abonniert hast.
            Du kannst diese Benachrichtigung jederzeit in deinen
            <a href="' . htmlspecialchars($settingsLink) . '" style="color:#6D9744;">Profileinstellungen</a> deaktivieren.
        </p>';

        $htmlBody = self::getTemplate('Neuer Blog-Artikel', $bodyContent, $callToAction);

        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }

    /**
     * Send a contact message from one user to a job listing owner
     *
     * @param string $toEmail        Recipient (listing owner) email address
     * @param string $toName         Recipient display name
     * @param string $senderName     Display name of the message sender
     * @param string $senderContact  Contact email address provided by the sender
     * @param string $listingTitle   Title of the job listing
     * @param string $message        The message body
     * @return bool
     */
    public static function sendJobListingContact(
        string $toEmail,
        string $toName,
        string $senderName,
        string $senderContact,
        string $listingTitle,
        string $message
    ): bool {
        $subject = '[IBC Jobbörse] Neue Nachricht zu deiner Anzeige: ' . $listingTitle;

        $bodyContent =
            '<p class="email-text">Hallo ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',</p>' .
            '<p class="email-text">ein Mitglied hat dir eine Nachricht zu deiner Anzeige <strong>' . htmlspecialchars($listingTitle, ENT_QUOTES, 'UTF-8') . '</strong> gesendet.</p>' .
            '<table class="info-table">' .
            '<tr><td style="padding:10px 15px;font-weight:bold;width:40%">Von</td><td style="padding:10px 15px">' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">Kontakt-E-Mail</td><td style="padding:10px 15px"><a href="mailto:' . htmlspecialchars($senderContact, ENT_QUOTES, 'UTF-8') . '" style="color:#6D9744">' . htmlspecialchars($senderContact, ENT_QUOTES, 'UTF-8') . '</a></td></tr>' .
            '</table>' .
            '<p class="email-text"><strong>Nachricht:</strong></p>' .
            '<div style="background:#f9fafb;border-left:4px solid #6D9744;padding:15px 20px;margin:10px 0;border-radius:0 6px 6px 0;color:#333;">' .
            nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) .
            '</div>' .
            '<p class="email-text">Du kannst dem Absender direkt unter <a href="mailto:' . htmlspecialchars($senderContact, ENT_QUOTES, 'UTF-8') . '" style="color:#6D9744">' . htmlspecialchars($senderContact, ENT_QUOTES, 'UTF-8') . '</a> antworten.</p>';

        $htmlBody = self::getTemplate('Neue Nachricht zu deiner Anzeige', $bodyContent);

        return self::sendEmailWithEmbeddedImage($toEmail, $subject, $htmlBody);
    }

    /**
     * Send welcome email to a new alumni including intranet login info and
     * the alumni contract as attachments (DOCX + PDF).
     *
     * Only called when the new alumni indicated they have NOT yet received the
     * alumni contract (has_alumni_contract = 0).
     *
     * @param string $toEmail    Recipient e-mail address
     * @param string $firstName  Recipient first name
     * @param string $lastName   Recipient last name
     * @param string $vorstandEmail  E-mail address where the signed contract must be sent
     * @return bool  true on success, false on failure
     */
    public static function sendNewAlumniWelcomeWithContract(
        string $toEmail,
        string $firstName,
        string $lastName,
        string $vorstandEmail
    ): bool {
        if (self::isVendorMissing()) {
            error_log('sendNewAlumniWelcomeWithContract: Composer vendor missing');
            return false;
        }

        $intranetUrl = defined('BASE_URL') ? BASE_URL : 'https://intra.business-consulting.de';
        $subject     = 'Willkommen im IBC Alumni-Netzwerk – deine Zugangsdaten & Alumni Vertrag';

        $bodyContent =
            '<p class="email-text">Hallo ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . ',</p>' .
            '<p class="email-text">herzlich willkommen im IBC Alumni-Netzwerk! Dein Zugang zum IBC Intranet wurde soeben freigeschaltet.</p>' .
            '<table class="info-table">' .
            '<tr><td style="padding:10px 15px;font-weight:bold;width:40%">Intranet-Login</td>' .
            '<td style="padding:10px 15px"><a href="' . htmlspecialchars($intranetUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#6D9744">' . htmlspecialchars($intranetUrl, ENT_QUOTES, 'UTF-8') . '</a></td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">E-Mail</td><td style="padding:10px 15px">' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '</table>' .
            '<p class="email-text">Im Anhang dieser E-Mail findest du den <strong>Alumni Vertrag</strong> (als PDF und DOCX). ' .
            'Bitte drucke den Vertrag aus, fülle ihn vollständig aus, unterschreibe ihn und sende das ausgefüllte Exemplar an den Vorstand:</p>' .
            '<p class="email-text" style="text-align:center;">' .
            '<a href="mailto:' . htmlspecialchars($vorstandEmail, ENT_QUOTES, 'UTF-8') . '" style="color:#6D9744;font-weight:bold;">' .
            htmlspecialchars($vorstandEmail, ENT_QUOTES, 'UTF-8') . '</a></p>' .
            '<p class="email-text">Bei Fragen stehen wir dir gerne zur Verfügung.</p>';

        $callToAction = '<a href="' . htmlspecialchars($intranetUrl, ENT_QUOTES, 'UTF-8') . '" class="button">Zum Intranet</a>';

        $htmlBody = self::getTemplate(
            'Willkommen im Alumni-Netzwerk',
            $bodyContent,
            $callToAction
        );

        // Locate the alumni contract attachments
        $baseDir    = __DIR__ . '/../assets/mail_data/';
        $attachPdf  = $baseDir . 'Alumni-Vertrag-IBC.pdf';
        $attachDocx = $baseDir . 'Alumni-Vertrag-IBC.docx';

        $pdfExists  = file_exists($attachPdf);
        $docxExists = file_exists($attachDocx);

        if (!$pdfExists && !$docxExists) {
            error_log(
                'sendNewAlumniWelcomeWithContract: neither PDF nor DOCX attachment found in '
                . $baseDir . ' – email to ' . $toEmail . ' NOT sent'
            );
            return false;
        }

        try {
            $mail = self::createMailer();
            $mail->addAddress($toEmail, $firstName . ' ' . $lastName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            // Embed logo
            $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.webp';
            if (!file_exists($imagePath)) {
                $imagePath = __DIR__ . '/../assets/img/ibc_logo_original_navbar.png';
            }
            if (file_exists($imagePath)) {
                $mail->addEmbeddedImage($imagePath, 'ibc_logo');
            }

            // Attach the alumni contract in both formats
            if ($pdfExists) {
                $mail->addAttachment($attachPdf, 'Alumni-Vertrag-IBC.pdf');
            } else {
                error_log('sendNewAlumniWelcomeWithContract: PDF attachment not found at ' . $attachPdf);
            }
            if ($docxExists) {
                $mail->addAttachment(
                    $attachDocx,
                    'Alumni-Vertrag-IBC.docx',
                    'base64',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                );
            } else {
                error_log('sendNewAlumniWelcomeWithContract: DOCX attachment not found at ' . $attachDocx);
            }

            ob_start();
            $mail->send();
            ob_end_clean();
            return true;

        } catch (\Exception $e) {
            error_log('sendNewAlumniWelcomeWithContract: failed to send to ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a deactivation request to the IT department asking them to disable
     * an old user account now that a new account has been created.
     *
     * This replaces the old behaviour of automatically disabling the account
     * via Graph API during the approval process. The IT team receives the
     * request and carries out the deactivation manually.
     *
     * @param string $oldEmail        E-mail / UPN of the account to be deactivated
     * @param string $newUserName     Display name of the newly created user
     * @param string $newUserEmail    E-mail of the newly created user
     * @param string $requestType     Short description: 'Alumni Recovery' or 'Neue Alumni'
     * @return bool  true on success, false on failure
     */
    public static function sendDeactivationRequest(
        string $oldEmail,
        string $newUserName,
        string $newUserEmail,
        string $requestType = 'Alumni'
    ): bool {
        $itEmail = defined('MAIL_IT_RESSORT') ? MAIL_IT_RESSORT : 'it@business-consulting.de';
        $subject = '[IBC Intranet] Bitte um User-Deaktivierung – ' . $requestType;

        $bodyContent =
            '<p class="email-text">Hallo IT-Ressort,</p>' .
            '<p class="email-text">im Rahmen des ' . htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8') . '-Prozesses wurde ein neuer Benutzer angelegt. ' .
            'Bitte deaktiviere den nachfolgenden alten Benutzer-Account im Microsoft Entra / Active Directory:</p>' .
            '<table class="info-table">' .
            '<tr><td style="padding:10px 15px;font-weight:bold;width:40%">Zu deaktivierender Account</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">Neuer Account (Ersatz)</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($newUserName, ENT_QUOTES, 'UTF-8') .
            ' &lt;' . htmlspecialchars($newUserEmail, ENT_QUOTES, 'UTF-8') . '&gt;</td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">Prozess</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($requestType, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '</table>' .
            '<p class="email-text">Diese Anfrage wurde automatisch nach Genehmigung durch den Vorstand bzw. die Alumni-Führung erstellt.</p>' .
            '<p class="email-text">Bitte bestätige die Deaktivierung nach Abschluss.</p>';

        $htmlBody = self::getTemplate('Bitte um User-Deaktivierung', $bodyContent);

        return self::sendEmail($itEmail, $subject, $htmlBody);
    }

    /**
     * Send a manual task request to the IT department.
     *
     * Use this method whenever the IT team needs to carry out a manual step
     * (e.g. adding a user to an Alumni distribution list) that cannot be
     * performed automatically by the application.
     *
     * @param string $userName     Full name of the affected user
     * @param string $userEmail    New e-mail address of the affected user
     * @param string $task         Short description of the task to be carried out
     *                             (e.g. 'Manuelle Aufnahme in den Alumni-Verteiler 9e927fce-9029-4564-b2b6-e52c9f1588dd')
     * @return bool  true on success, false on failure
     */
    public static function sendITManualTaskRequest(
        string $userName,
        string $userEmail,
        string $task
    ): bool {
        $itEmail = defined('MAIL_IT_RESSORT') ? MAIL_IT_RESSORT : 'it@business-consulting.de';
        $subject = '[IBC Intranet] Manuelle Aufgabe erforderlich';

        $bodyContent =
            '<p class="email-text">Hallo IT-Ressort,</p>' .
            '<p class="email-text">im Rahmen eines automatisierten Prozesses ist eine manuelle Aufgabe angefallen, ' .
            'die bitte zeitnah erledigt werden sollte:</p>' .
            '<table class="info-table">' .
            '<tr><td style="padding:10px 15px;font-weight:bold;width:40%">Vollständiger Name</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">Neue E-Mail-Adresse</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '<tr><td style="padding:10px 15px;font-weight:bold">Aufgabe</td>' .
            '<td style="padding:10px 15px">' . htmlspecialchars($task, ENT_QUOTES, 'UTF-8') . '</td></tr>' .
            '</table>' .
            '<p class="email-text">Diese Anfrage wurde automatisch durch das IBC Intranet generiert.</p>' .
            '<p class="email-text">Bitte bestätige die Erledigung nach Abschluss.</p>';

        $htmlBody = self::getTemplate('Manuelle Aufgabe erforderlich', $bodyContent);

        return self::sendEmail($itEmail, $subject, $htmlBody);
    }
}
