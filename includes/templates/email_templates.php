<?php
/**
 * Modern Responsive Email Templates
 * 
 * This file contains award-winning HTML email templates with:
 * - Mobile-first responsive design
 * - Glassmorphism effects (where supported)
 * - Dark mode support
 * - Cross-client compatibility (Gmail, Outlook, Apple Mail, etc.)
 * - Accessibility features
 * 
 * IMPORTANT: Logo Image Attachment
 * All templates reference 'cid:ibc_logo' for the IBC logo image.
 * When sending emails, you MUST attach the logo image with Content-ID 'ibc_logo':
 * 
 * Example usage with PHPMailer:
 *   $mail->addEmbeddedImage('/path/to/logo.png', 'ibc_logo', 'logo.png');
 * 
 * If the logo is not attached, email clients will show a broken image icon.
 */

class EmailTemplates {
    
    /**
     * Get the base responsive email template
     * 
     * @param string $title Email title
     * @param string $content Email content (HTML)
     * @param array $options Optional settings
     * @return string Complete HTML email
     */
    public static function getBaseTemplate($title, $content, $options = []) {
        $primaryColor = $options['primaryColor'] ?? '#6cb73e';
        $secondaryColor = $options['secondaryColor'] ?? '#1e4c9c';
        
        return '<!DOCTYPE html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>' . htmlspecialchars($title) . '</title>
    <style type="text/css">
        /* Reset and base styles */
        * { margin: 0; padding: 0; }
        body { 
            margin: 0; 
            padding: 0; 
            width: 100% !important; 
            background-color: #f5f5f7;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table { 
            border-spacing: 0; 
            border-collapse: collapse;
            width: 100%;
        }
        img { 
            border: 0; 
            height: auto; 
            line-height: 100%; 
            outline: none; 
            text-decoration: none;
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body { background-color: #1a1a1a !important; }
            .email-container { background-color: #2a2a2a !important; }
            .email-body { background-color: #2a2a2a !important; color: #ffffff !important; }
            .text-muted { color: #b0b0b0 !important; }
        }
        
        /* Responsive styles */
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; }
            .content-padding { padding: 20px !important; }
            h1 { font-size: 24px !important; }
            .mobile-hide { display: none !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f7;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f7;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <!-- Main Container -->
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="email-container" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 40px 20px; background: linear-gradient(135deg, ' . $primaryColor . ' 0%, ' . $secondaryColor . ' 100%); border-radius: 16px 16px 0 0;">
                            <img src="cid:ibc_logo" alt="IBC" style="width: 150px; height: auto;" />
                        </td>
                    </tr>
                    
                    <!-- Body -->
                    <tr>
                        <td class="email-body content-padding" style="padding: 40px 40px; background-color: #ffffff;">
                            ' . $content . '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f9f9f9; border-radius: 0 0 16px 16px; text-align: center;">
                            <p style="margin: 0 0 10px; font-size: 14px; color: #666666; line-height: 1.6;">
                                Diese E-Mail wurde automatisch vom IBC Intranet generiert.
                            </p>
                            <p style="margin: 0; font-size: 14px; color: #666666;">
                                &copy; ' . date('Y') . ' IBC Business Consulting. Alle Rechte vorbehalten.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Get festive birthday email template with inline CSS
     * HTML template with festive design ("Alles Gute zum Geburtstag")
     * Uses inline CSS for maximum compatibility
     * 
     * @param string $name User's name
     * @param string $gender User's gender (m/f) - optional
     * @return string Complete HTML email
     */
    public static function getBirthdayTemplate($name, $gender = '') {
        // Determine salutation
        if ($gender === 'f') {
            $salutation = 'Liebe ' . htmlspecialchars($name);
        } elseif ($gender === 'm') {
            $salutation = 'Lieber ' . htmlspecialchars($name);
        } else {
            $salutation = 'Hallo ' . htmlspecialchars($name);
        }
        
        $content = '
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <!-- Celebration Banner -->
            <tr>
                <td align="center" style="padding: 0 0 30px;">
                    <div style="font-size: 48px; line-height: 1; margin: 0;">🎉</div>
                    <h1 style="margin: 15px 0; font-size: 32px; font-weight: 700; color: #1a1a1a; line-height: 1.2;">
                        Alles Gute zum Geburtstag!
                    </h1>
                    <div style="font-size: 48px; line-height: 1; margin: 15px 0;">🎂</div>
                </td>
            </tr>
            
            <!-- Greeting Card -->
            <tr>
                <td>
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background: linear-gradient(135deg, #fff5e6 0%, #ffe6f0 100%); border-radius: 12px; padding: 30px; margin: 20px 0;">
                        <tr>
                            <td>
                                <p style="margin: 0 0 20px; font-size: 20px; font-weight: 600; color: #1a1a1a;">
                                    ' . $salutation . ',
                                </p>
                                
                                <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                                    heute ist dein besonderer Tag! 🎈
                                </p>
                                
                                <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                                    Wir wünschen dir von Herzen alles Gute zum Geburtstag! Möge dieser Tag voller Freude, Lachen und wunderbarer Momente sein.
                                </p>
                                
                                <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                                    Wir hoffen, dass all deine Wünsche in Erfüllung gehen und dass das kommende Jahr dir Gesundheit, Erfolg und Glück bringt.
                                </p>
                                
                                <p style="margin: 20px 0 0; font-size: 18px; font-weight: 600; color: #6cb73e; line-height: 1.6;">
                                    Herzliche Grüße und feiere schön! 🥳<br>
                                    Dein IBC-Team
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            
            <!-- Emojis -->
            <tr>
                <td align="center" style="padding: 20px 0 0;">
                    <div style="font-size: 32px; line-height: 1.5; letter-spacing: 8px;">
                        🎊 🎁 🎈 🎉 🎂
                    </div>
                </td>
            </tr>
        </table>';
        
        return self::getBaseTemplate('Herzlichen Glückwunsch zum Geburtstag!', $content, [
            'primaryColor' => '#6cb73e',
            'secondaryColor' => '#1e4c9c'
        ]);
    }
    
    /**
     * Get notification email template
     * 
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $options Optional settings (icon, ctaLink, ctaText)
     * @return string Complete HTML email
     */
    public static function getNotificationTemplate($title, $message, $options = []) {
        $icon = $options['icon'] ?? '📢';
        $ctaLink = $options['ctaLink'] ?? null;
        $ctaText = $options['ctaText'] ?? 'Mehr erfahren';
        
        $ctaButton = '';
        if ($ctaLink) {
            $ctaButton = '
            <tr>
                <td align="center" style="padding: 30px 0 0;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                        <tr>
                            <td align="center" style="border-radius: 8px; background: linear-gradient(135deg, #6cb73e 0%, #5a9933 100%);">
                                <a href="' . htmlspecialchars($ctaLink) . '" target="_blank" style="display: inline-block; padding: 14px 36px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                                    ' . htmlspecialchars($ctaText) . '
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>';
        }
        
        $content = '
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td align="center" style="padding: 0 0 20px;">
                    <div style="font-size: 64px; line-height: 1;">' . $icon . '</div>
                </td>
            </tr>
            <tr>
                <td>
                    <h2 style="margin: 0 0 20px; font-size: 24px; font-weight: 600; color: #1a1a1a; text-align: center;">
                        ' . htmlspecialchars($title) . '
                    </h2>
                </td>
            </tr>
            <tr>
                <td>
                    <div style="padding: 20px; background-color: #f9f9f9; border-left: 4px solid #6cb73e; border-radius: 4px;">
                        <p style="margin: 0; font-size: 16px; color: #333333; line-height: 1.6;">
                            ' . nl2br(htmlspecialchars($message)) . '
                        </p>
                    </div>
                </td>
            </tr>
            ' . $ctaButton . '
        </table>';
        
        return self::getBaseTemplate($title, $content);
    }
    
    /**
     * Get welcome email template for new users
     * 
     * @param string $firstName User's first name
     * @param string $profileLink Link to complete profile
     * @return string Complete HTML email
     */
    public static function getWelcomeTemplate($firstName, $profileLink) {
        $content = '
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td align="center" style="padding: 0 0 30px;">
                    <div style="font-size: 64px; line-height: 1;">👋</div>
                </td>
            </tr>
            <tr>
                <td>
                    <h2 style="margin: 0 0 20px; font-size: 28px; font-weight: 700; color: #1a1a1a; text-align: center;">
                        Willkommen bei IBC!
                    </h2>
                </td>
            </tr>
            <tr>
                <td>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        Hallo ' . htmlspecialchars($firstName) . ',
                    </p>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        herzlich willkommen im IBC Intranet! Wir freuen uns, dich in unserem Team begrüßen zu dürfen.
                    </p>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        Um dein Profil zu vervollständigen und alle Funktionen nutzen zu können, klicke bitte auf den Button unten.
                    </p>
                </td>
            </tr>
            <tr>
                <td align="center" style="padding: 30px 0;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                        <tr>
                            <td align="center" style="border-radius: 8px; background: linear-gradient(135deg, #6cb73e 0%, #5a9933 100%); box-shadow: 0 4px 15px rgba(108, 183, 62, 0.3);">
                                <a href="' . htmlspecialchars($profileLink) . '" target="_blank" style="display: inline-block; padding: 16px 42px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                                    Profil vervollständigen
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td>
                    <p style="margin: 0; font-size: 14px; color: #666666; line-height: 1.6; text-align: center;">
                        Bei Fragen stehen wir dir jederzeit gerne zur Verfügung.
                    </p>
                </td>
            </tr>
        </table>';
        
        return self::getBaseTemplate('Willkommen bei IBC', $content);
    }
    
    /**
     * Get professional profile reminder email template
     * Professional template ("Bitte aktualisieren Sie Ihre Daten...")
     * Uses inline CSS for maximum compatibility
     * 
     * @param string $name User's name
     * @param string $profileLink Link to profile page - optional
     * @return string Complete HTML email
     */
    public static function getProfileReminderTemplate($name, $profileLink = '') {
        // Build button section only if profileLink is provided
        $buttonSection = '';
        if (!empty($profileLink)) {
            $buttonSection = '
            <tr>
                <td align="center" style="padding: 30px 0;">
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                        <tr>
                            <td align="center" style="border-radius: 8px; background: linear-gradient(135deg, #6cb73e 0%, #5a9933 100%); box-shadow: 0 4px 15px rgba(108, 183, 62, 0.3);">
                                <a href="' . htmlspecialchars($profileLink) . '" target="_blank" style="display: inline-block; padding: 16px 42px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">
                                    Profil aktualisieren
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>';
        }
        
        $content = '
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            <tr>
                <td align="center" style="padding: 0 0 30px;">
                    <div style="font-size: 64px; line-height: 1;">📝</div>
                </td>
            </tr>
            <tr>
                <td>
                    <h2 style="margin: 0 0 20px; font-size: 28px; font-weight: 700; color: #1a1a1a; text-align: center;">
                        Zeit für ein Profil-Update!
                    </h2>
                </td>
            </tr>
            <tr>
                <td>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        Hallo ' . htmlspecialchars($name) . ',
                    </p>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        dein Profil wurde seit über einem Jahr nicht aktualisiert. Um sicherzustellen, dass wir immer aktuelle Informationen haben und in Kontakt bleiben können, bitten wir dich, dein Profil zu überprüfen.
                    </p>
                    <p style="margin: 0 0 16px; font-size: 16px; color: #333333; line-height: 1.6;">
                        Bitte nimm dir einen kurzen Moment Zeit, um deine Daten zu überprüfen und bei Bedarf zu aktualisieren.
                    </p>
                </td>
            </tr>
            ' . $buttonSection . '
            <tr>
                <td>
                    <div style="padding: 20px; background-color: #f0fdf4; border-left: 4px solid #6cb73e; border-radius: 8px; margin-top: 20px;">
                        <p style="margin: 0; font-size: 14px; color: #166534; line-height: 1.6;">
                            <strong>💡 Tipp:</strong> Ein aktuelles Profil hilft uns, dich besser zu unterstützen und relevante Informationen mit dir zu teilen.
                        </p>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <p style="margin: 20px 0 0; font-size: 14px; color: #666666; line-height: 1.6; text-align: center;">
                        Vielen Dank für deine Unterstützung!<br>
                        Dein IBC-Team
                    </p>
                </td>
            </tr>
        </table>';
        
        return self::getBaseTemplate('Profil Aktualisierung', $content);
    }
}
