<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/GoogleAuthenticator.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/models/JobBoard.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? ''; // Retrieve role from Auth
$message = '';
$error = '';
$showQRCode = false;
$qrCodeUrl = '';
$secret = '';

// Check for session messages from email confirmation
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Load user's profile based on role
// If User is 'mitglied'/'board'/'ressortleiter'/'anwaerter' -> Use Member::getProfileByUserId()
// If User is 'alumni'/'alumni_vorstand'/'ehrenmitglied' -> Use Alumni::getProfileByUserId()
$profile = null;
if (isMemberRole($userRole)) {
    $profile = Member::getProfileByUserId($user['id']);
} elseif (isAlumniRole($userRole)) {
    $profile = Alumni::getProfileByUserId($user['id']);
}

// If profile not found, initialize empty profile to show "Profil erstellen" form
if (!$profile) {
    $profile = [
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'email' => $user['email'],
        'secondary_email' => '',
        'mobile_phone' => '',
        'linkedin_url' => '',
        'xing_url' => '',
        'about_me' => $user['about_me'] ?? '',
        'image_path' => '',
        'study_program' => '',
        'semester' => null,  // Numeric value, null when not set
        'angestrebter_abschluss' => '',
        'company' => '',
        'industry' => '',
        'position' => '',
        'gender' => $user['gender'] ?? '',
        'birthday' => $user['birthday'] ?? '',
        'show_birthday' => $user['show_birthday'] ?? 1,
        'cv_path' => null
    ];
} else {
    // Ensure gender, birthday, show_birthday, and about_me from users table are included
    $profile['gender'] = $user['gender'] ?? ($profile['gender'] ?? '');
    $profile['birthday'] = $user['birthday'] ?? ($profile['birthday'] ?? '');
    $profile['show_birthday'] = $user['show_birthday'] ?? ($profile['show_birthday'] ?? 0);
    $profile['about_me'] = $user['about_me'] ?? ($profile['about_me'] ?? '');
}

// Load the most recent job board listing with a PDF so it can be reused as profile CV
$jobBoardCvPath = null;
$db = Database::getContentDB();
$jbStmt = $db->prepare(
    "SELECT pdf_path FROM job_board WHERE user_id = ? AND pdf_path IS NOT NULL ORDER BY created_at DESC LIMIT 1"
);
$jbStmt->execute([(int)$user['id']]);
$jbRow = $jbStmt->fetch(PDO::FETCH_ASSOC);
if ($jbRow && !empty($jbRow['pdf_path'])) {
    $jobBoardCvPath = $jbRow['pdf_path'];
}
unset($db, $jbStmt, $jbRow);

// Handle 2FA setup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Handle profile update
        try {
            $profileData = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'email' => trim($_POST['profile_email'] ?? ''),
                'secondary_email' => trim($_POST['secondary_email'] ?? ''),
                'mobile_phone' => trim($_POST['mobile_phone'] ?? ''),
                'linkedin_url' => trim($_POST['linkedin_url'] ?? ''),
                'xing_url' => trim($_POST['xing_url'] ?? ''),
                'about_me' => mb_substr(trim($_POST['about_me'] ?? ''), 0, 400), // Limit to 400 chars
                'skills' => mb_substr(trim($_POST['skills'] ?? ''), 0, 500), // Limit to 500 chars
                'image_path' => $profile['image_path'] ?? '', // Keep existing image by default
                'cv_path' => $profile['cv_path'] ?? null, // Keep existing CV by default
                'gender' => trim($_POST['gender'] ?? ''),
                'birthday' => trim($_POST['birthday'] ?? ''),
                'show_birthday' => isset($_POST['show_birthday']) ? 1 : 0
            ];
            
            // Validate required fields for profile completion
            if (empty($profileData['first_name'])) {
                throw new Exception('Vorname ist erforderlich');
            }
            if (empty($profileData['last_name'])) {
                throw new Exception('Nachname ist erforderlich');
            }
            if (empty($profileData['email'])) {
                throw new Exception('E-Mail ist erforderlich');
            }
            if (!filter_var($profileData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-Mail-Adresse ist ungültig');
            }
            if (empty($profileData['mobile_phone'])) {
                throw new Exception('Telefonnummer ist ein Pflichtfeld.');
            }
            if (empty($profileData['birthday'])) {
                throw new Exception('Das Geburtsdatum ist ein Pflichtfeld.');
            }
            if (strtotime($profileData['birthday']) > strtotime('-16 years')) {
                throw new Exception('Du musst mindestens 16 Jahre alt sein.');
            }
            
            // Handle cropped profile picture (base64 data from Cropper.js)
            if (!empty($_POST['profile_picture_data'])) {
                $base64Data = $_POST['profile_picture_data'];
                // Validate format: data:image/<type>;base64,<data>
                if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/s', $base64Data, $matches)) {
                    throw new Exception('Ungültiges Bildformat. Bitte wähle ein gültiges Bild.');
                }
                $imageData = base64_decode($matches[2]);
                if ($imageData === false || strlen($imageData) === 0) {
                    throw new Exception('Bildverarbeitung fehlgeschlagen. Bitte versuche es erneut.');
                }
                // Enforce 5MB size limit
                if (strlen($imageData) > 5242880) {
                    throw new Exception('Bild ist zu groß. Maximum: 5MB');
                }
                // Write to temp file for validation
                $tmpFile = tempnam(sys_get_temp_dir(), 'avatar_');
                file_put_contents($tmpFile, $imageData);
                try {
                    // Validate actual MIME type (not trusting the declared type)
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $actualMime = finfo_file($finfo, $tmpFile);
                    finfo_close($finfo);
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    if (!in_array($actualMime, $allowedMimes)) {
                        throw new Exception('Ungültiger Bildtyp');
                    }
                    // Validate that the file is a real image
                    $imageInfo = @getimagesize($tmpFile);
                    if ($imageInfo === false) {
                        throw new Exception('Datei ist kein gültiges Bild');
                    }
                    // Save to upload directory
                    $uploadDir = __DIR__ . '/../../uploads/profile/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $ext = $extMap[$actualMime] ?? 'jpg';
                    $filename = 'item_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    $uploadPath = $uploadDir . $filename;
                    if (!copy($tmpFile, $uploadPath)) {
                        throw new Exception('Fehler beim Speichern des Profilbildes');
                    }
                    chmod($uploadPath, 0644);
                    // Delete old profile picture if exists
                    if (!empty($profile['image_path'])) {
                        SecureImageUpload::deleteImage($profile['image_path']);
                    }
                    // Calculate relative path for database storage
                    $projectRoot = realpath(__DIR__ . '/../../');
                    $realUploadPath = realpath($uploadPath);
                    $relativePath = str_replace('\\', '/', substr($realUploadPath, strlen($projectRoot) + 1));
                    $profileData['image_path'] = $relativePath;
                } finally {
                    @unlink($tmpFile);
                }
            }
            
            // Handle CV: either reuse from job board listing or upload a new file
            $cvSource = $_POST['cv_source'] ?? 'upload';
            if ($cvSource === 'job_board' && $jobBoardCvPath !== null) {
                // Option A: Reuse CV from the user's most recent job board listing
                $srcFile       = realpath(__DIR__ . '/../../' . $jobBoardCvPath);
                $allowedSrcDir = realpath(__DIR__ . '/../../uploads/jobs');
                if ($srcFile !== false && $allowedSrcDir !== false && str_starts_with($srcFile, $allowedSrcDir . DIRECTORY_SEPARATOR)) {
                    $cvUploadDir = __DIR__ . '/../../uploads/cv/';
                    if (!is_dir($cvUploadDir)) {
                        mkdir($cvUploadDir, 0755, true);
                    }
                    $cvFilename  = 'cv_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.pdf';
                    $cvUploadPath = $cvUploadDir . $cvFilename;
                    if (copy($srcFile, $cvUploadPath)) {
                        chmod($cvUploadPath, 0644);
                        // Delete old profile CV if it exists
                        if (!empty($profile['cv_path'])) {
                            $projectRootCheck = realpath(__DIR__ . '/../../');
                            if ($projectRootCheck !== false) {
                                $oldCvFull = $projectRootCheck . '/' . ltrim($profile['cv_path'], '/');
                                if (file_exists($oldCvFull)) {
                                    $realOld = realpath($oldCvFull);
                                    $cvAllowedDir = realpath(__DIR__ . '/../../uploads/cv');
                                    if ($realOld !== false && $cvAllowedDir !== false && str_starts_with($realOld, $cvAllowedDir . DIRECTORY_SEPARATOR)) {
                                        @unlink($realOld);
                                    }
                                }
                            }
                        }
                        $cvProjectRoot = realpath(__DIR__ . '/../../');
                        $realCvPath    = realpath($cvUploadPath);
                        $cvRelativePath = str_replace('\\', '/', substr($realCvPath, strlen($cvProjectRoot) + 1));
                        $profileData['cv_path'] = $cvRelativePath;
                    } else {
                        throw new Exception('Lebenslauf aus Job-Gesuch konnte nicht übernommen werden.');
                    }
                } else {
                    throw new Exception('Lebenslauf aus Job-Gesuch konnte nicht gefunden werden.');
                }
            } elseif (!empty($_FILES['cv_file']['name'])) {
                // Option B: Upload a new CV file (PDF only)
                $cvFile = $_FILES['cv_file'];
                if ($cvFile['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Fehler beim Hochladen des Lebenslaufs (Code: ' . $cvFile['error'] . ')');
                }
                // Enforce 10 MB size limit for CV
                if ($cvFile['size'] > 10485760) {
                    throw new Exception('Lebenslauf ist zu groß. Maximum: 10MB');
                }
                // Validate actual MIME type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $cvMime = finfo_file($finfo, $cvFile['tmp_name']);
                finfo_close($finfo);
                if ($cvMime !== 'application/pdf') {
                    throw new Exception('Nur PDF-Dateien sind als Lebenslauf erlaubt');
                }
                $cvUploadDir = __DIR__ . '/../../uploads/cv/';
                if (!is_dir($cvUploadDir)) {
                    mkdir($cvUploadDir, 0755, true);
                }
                $cvFilename = 'cv_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.pdf';
                $cvUploadPath = $cvUploadDir . $cvFilename;
                if (!move_uploaded_file($cvFile['tmp_name'], $cvUploadPath)) {
                    throw new Exception('Fehler beim Speichern des Lebenslaufs');
                }
                chmod($cvUploadPath, 0644);
                // Delete old CV if it exists (only files within uploads/cv/)
                if (!empty($profile['cv_path'])) {
                    $cvAllowedDir = realpath(__DIR__ . '/../../uploads/cv');
                    if ($cvAllowedDir !== false) {
                        $oldCvFull = realpath(__DIR__ . '/../../' . $profile['cv_path']);
                        if ($oldCvFull !== false && str_starts_with($oldCvFull, $cvAllowedDir . DIRECTORY_SEPARATOR)) {
                            @unlink($oldCvFull);
                        }
                    }
                }
                $cvProjectRoot = realpath(__DIR__ . '/../../');
                $realCvPath = realpath($cvUploadPath);
                $cvRelativePath = str_replace('\\', '/', substr($realCvPath, strlen($cvProjectRoot) + 1));
                $profileData['cv_path'] = $cvRelativePath;
            }
            
            // Update user fields (about_me, gender, birthday, show_birthday, job_title, company) in users table
            $userUpdateData = [];
            if (isset($profileData['gender'])) {
                $userUpdateData['gender'] = $profileData['gender'];
            }
            if (isset($profileData['birthday'])) {
                $userUpdateData['birthday'] = $profileData['birthday'];
            }
            if (isset($profileData['show_birthday'])) {
                $userUpdateData['show_birthday'] = $profileData['show_birthday'];
            }
            if (isset($profileData['about_me'])) {
                $userUpdateData['about_me'] = $profileData['about_me'];
            }
            // Add job_title and company from POST data
            if (isset($_POST['job_title'])) {
                $userUpdateData['job_title'] = trim($_POST['job_title']);
            }
            if (isset($_POST['company'])) {
                $userUpdateData['company'] = trim($_POST['company']);
            }
            
            if (!empty($userUpdateData)) {
                require_once __DIR__ . '/../../includes/models/User.php';
                User::updateProfile($user['id'], $userUpdateData);
            }
            
            // Add role-specific fields based on user role
            // Student View: member, candidate, head, board -> Show study fields
            if (isMemberRole($userRole)) {
                // Fields for students (candidates, members, board, and heads)
                // Map new field names to legacy database columns
                $profileData['studiengang'] = trim($_POST['bachelor_studiengang'] ?? '');
                // study_program: Database column alias for legacy schema compatibility
                $profileData['study_program'] = trim($_POST['bachelor_studiengang'] ?? '');
                $profileData['semester'] = trim($_POST['bachelor_semester'] ?? '');
                // Use 'angestrebter_abschluss' for master program (repurposed for master program name)
                $profileData['angestrebter_abschluss'] = trim($_POST['master_studiengang'] ?? '');
                // Note: graduation_year is repurposed to store master semester for current students
                // This is a limitation of the existing database schema
                $profileData['graduation_year'] = trim($_POST['master_semester'] ?? '') ? intval(trim($_POST['master_semester'] ?? '')) : null;
                // Note: Arbeitgeber (company) fields are optional/hidden for students
            } elseif (isAlumniRole($userRole)) {
                // Alumni View: Show employment fields and completed studies
                // Map study fields
                $profileData['studiengang'] = trim($_POST['bachelor_studiengang'] ?? '');
                $profileData['study_program'] = trim($_POST['bachelor_studiengang'] ?? '');
                // Use 'semester' for bachelor graduation year (repurposed for year storage)
                $profileData['semester'] = trim($_POST['bachelor_year'] ?? '');
                // Use 'angestrebter_abschluss' for master program name
                $profileData['angestrebter_abschluss'] = trim($_POST['master_studiengang'] ?? '');
                // graduation_year stores actual graduation year for alumni (correct usage)
                $profileData['graduation_year'] = trim($_POST['master_year'] ?? '') ? intval(trim($_POST['master_year'] ?? '')) : null;
                // Employment fields
                $profileData['company'] = trim($_POST['company'] ?? '');
                $profileData['industry'] = trim($_POST['industry'] ?? '');
                // Only update 'position' in alumni_profiles if explicitly submitted via the form
                if (isset($_POST['position']) && $_POST['position'] !== '') {
                    $profileData['position'] = trim($_POST['position']);
                }
            }
            
            // Update or create profile (only for the current user)
            // Use the appropriate method based on role
            $updateSuccess = false;
            if (isMemberRole($userRole)) {
                // For member roles (anwaerter, mitglied, resortleiter, board), use Member::updateProfile
                $updateSuccess = Member::updateProfile($user['id'], $profileData);
            } elseif (isAlumniRole($userRole)) {
                // For alumni roles (alumni, alumni_vorstand, ehrenmitglied), use Alumni::updateOrCreateProfile
                $updateSuccess = Alumni::updateOrCreateProfile($user['id'], $profileData);
            } else {
                // Log warning for unexpected role
                error_log("Unexpected user role in profile update: " . $userRole . " for user ID: " . $user['id']);
                $error = 'Ihre Rolle unterstützt keine Profilaktualisierung. Bitte kontaktieren Sie den Administrator.';
            }
            
            if ($updateSuccess) {
                $message = 'Profil erfolgreich aktualisiert';
                
                // Reset the profile reminder cycle: record when the profile was last updated
                // and clear the reminder-sent flag so the 1-year interval starts fresh.
                try {
                    $userDb = Database::getUserDB();
                    $stmt = $userDb->prepare("UPDATE users SET last_profile_update = NOW(), profile_reminder_sent_at = NULL WHERE id = ?");
                    $stmt->execute([$user['id']]);
                } catch (Exception $e) {
                    error_log("Failed to update profile reminder timestamps: " . $e->getMessage());
                }
                
                // Mark profile as complete if all required fields are provided:
                // first_name, last_name, and email
                if (!empty($profileData['first_name']) && 
                    !empty($profileData['last_name']) && 
                    !empty($profileData['email'])) {
                    try {
                        $userDb = Database::getUserDB();
                        $stmt = $userDb->prepare("UPDATE users SET profile_complete = 1, is_onboarded = 1, has_seen_onboarding = 1 WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        // Clear the profile_incomplete and is_onboarded session flags
                        unset($_SESSION['profile_incomplete']);
                        $_SESSION['is_onboarded'] = true;
                    } catch (Exception $e) {
                        error_log("Failed to mark profile as complete: " . $e->getMessage());
                    }
                }
                
                // Reload user data to get updated gender and birthday
                $user = Auth::user();
                // Reload profile based on role
                if (isMemberRole($userRole)) {
                    $profile = Member::getProfileByUserId($user['id']);
                } elseif (isAlumniRole($userRole)) {
                    $profile = Alumni::getProfileByUserId($user['id']);
                }
                // Ensure gender, birthday, show_birthday, and about_me from users table are included in profile
                if ($profile) {
                    $profile['gender'] = $user['gender'] ?? ($profile['gender'] ?? '');
                    $profile['birthday'] = $user['birthday'] ?? ($profile['birthday'] ?? '');
                    $profile['show_birthday'] = $user['show_birthday'] ?? ($profile['show_birthday'] ?? 0);
                    $profile['about_me'] = $user['about_me'] ?? ($profile['about_me'] ?? '');
                }
                // If neither member nor alumni role, profile will remain as-is
            } else {
                $error = 'Fehler beim Aktualisieren des Profils';
            }
        } catch (PDOException $e) {
            // Database protection: Graceful error handling for database issues
            error_log("Profile update database error: " . $e->getMessage());
            $error = 'Datenbank nicht aktuell. Bitte Admin kontaktieren.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else if (isset($_POST['enable_2fa'])) {
        $ga = new PHPGangsta_GoogleAuthenticator();
        $secret = $ga->createSecret();
        $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
        $showQRCode = true;
    } else if (isset($_POST['confirm_2fa'])) {
        $secret = $_POST['secret'] ?? '';
        $code = $_POST['code'] ?? '';
        
        $ga = new PHPGangsta_GoogleAuthenticator();
        if ($ga->verifyCode($secret, $code, 2)) {
            if (User::enable2FA($user['id'], $secret)) {
                $message = '2FA erfolgreich aktiviert';
                $user = Auth::user(); // Reload user
            } else {
                $error = 'Fehler beim Aktivieren von 2FA';
            }
        } else {
            $error = 'Ungültiger Code. Bitte versuche es erneut.';
            $secret = $_POST['secret'];
            $ga = new PHPGangsta_GoogleAuthenticator();
            $qrCodeUrl = $ga->getQRCodeUrl($user['email'], $secret, 'IBC Intranet');
            $showQRCode = true;
        }
    } else if (isset($_POST['disable_2fa'])) {
        if (User::disable2FA($user['id'])) {
            $message = '2FA erfolgreich deaktiviert';
            $user = Auth::user(); // Reload user
        } else {
            $error = 'Fehler beim Deaktivieren von 2FA';
        }
    } else if (isset($_POST['submit_change_request'])) {
        // Handle change request submission
        try {
            $requestType = trim($_POST['request_type'] ?? '');
            $requestReason = trim($_POST['request_reason'] ?? '');
            
            // Validate request type
            $allowedTypes = ['Rollenänderung', 'E-Mail-Adressenänderung'];
            if (!in_array($requestType, $allowedTypes, true)) {
                throw new Exception('Ungültiger Änderungstyp. Bitte wählen Sie eine gültige Option.');
            }
            
            // Validate request reason (minimum 10 characters, maximum 1000)
            if (strlen($requestReason) < 10) {
                throw new Exception('Bitte geben Sie eine ausführlichere Begründung an (mindestens 10 Zeichen).');
            }
            if (strlen($requestReason) > 1000) {
                throw new Exception('Die Begründung ist zu lang (maximal 1000 Zeichen).');
            }
            
            // Get user's name
            $userName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
            if (empty($userName) || $userName === ' ') {
                $userName = $user['email'];
            }
            
            // Get current role
            $currentRole = '';
            if (!empty($user['entra_roles'])) {
                $currentRole = $user['entra_roles'];
            } elseif (!empty($user['role'])) {
                $currentRole = translateRole($user['role']);
            }
            
            // Prepare email body
            $emailBody = MailService::getTemplate(
                'Änderungsantrag',
                '<p>Ein Benutzer hat einen Änderungsantrag gestellt:</p>
                <table class="info-table">
                    <tr>
                        <td class="info-label">Name:</td>
                        <td class="info-value">' . htmlspecialchars($userName) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Aktuelle E-Mail:</td>
                        <td class="info-value">' . htmlspecialchars($user['email']) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Aktuelle Rolle:</td>
                        <td class="info-value">' . htmlspecialchars($currentRole) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Art der Änderung:</td>
                        <td class="info-value">' . htmlspecialchars($requestType) . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">Begründung / Neuer Wert:</td>
                        <td class="info-value">' . nl2br(htmlspecialchars($requestReason)) . '</td>
                    </tr>
                </table>'
            );
            
            // Send email to IT
            $emailSent = MailService::sendEmail(
                MAIL_SUPPORT,
                'Änderungsantrag: ' . $requestType . ' von ' . $userName,
                $emailBody
            );
            
            if ($emailSent) {
                $message = 'Ihr Änderungsantrag wurde erfolgreich eingereicht!';
            } else {
                $error = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
            }
        } catch (Exception $e) {
            error_log('Error submitting change request: ' . $e->getMessage());
            $error = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}

// --- Profile completion calculation (8 required fields only) ---
$completionFields = [
    'first_name'   => !empty($profile['first_name'] ?? ''),
    'last_name'    => !empty($profile['last_name'] ?? ''),
    'email'        => !empty($user['email'] ?? ''),
    'mobile_phone' => !empty($profile['mobile_phone'] ?? ''),
    'gender'       => !empty($profile['gender'] ?? ''),
    'birthday'     => !empty($profile['birthday'] ?? ''),
    'skills'       => !empty($profile['skills'] ?? ''),
    'about_me'     => !empty($profile['about_me'] ?? ''),
];
$completionFieldLabels = [
    'first_name'   => 'Vorname',
    'last_name'    => 'Nachname',
    'email'        => 'E-Mail',
    'mobile_phone' => 'Mobiltelefon',
    'gender'       => 'Geschlecht',
    'birthday'     => 'Geburtstag',
    'skills'       => 'Fähigkeiten',
    'about_me'     => 'Über mich',
];
$completionDone    = count(array_filter($completionFields));
$completionTotal   = count($completionFields);
$completionPercent = $completionTotal > 0 ? (int) round(($completionDone / $completionTotal) * 100) : 0;
$completionMissing = array_keys(array_filter($completionFields, fn($v) => !$v));
$isProfileComplete = $completionPercent === 100;
// --- End profile completion ---

$title = 'Profil - IBC Intranet';
// Pass $user as $userData to avoid a redundant DB query – Auth::user() already fetched
// avatar_path via SELECT *.

// Determine the current photo source so the UI can inform the user and offer the
// appropriate action buttons.
// users.avatar_path holds either a custom_* (manually uploaded) or entra_* (Entra ID) path.
// resolveImagePath() confirms the file actually exists on disk.
$currentAvatarPath = $user['avatar_path'] ?? null;
$hasValidAvatar    = !empty($currentAvatarPath) && resolveImagePath($currentAvatarPath) !== null;
$hasManualUpload   = $hasValidAvatar && strpos($currentAvatarPath, 'custom_') !== false;
$hasEntraPhoto     = $hasValidAvatar && strpos($currentAvatarPath, 'entra_') !== false;
// A Microsoft (Entra) account is present when azure_oid is set, even if the photo
// has not yet been cached locally.
$hasMicrosoftAccount = !empty($user['azure_oid']);
if ($hasManualUpload) {
    $photoSource = 'manual';
} elseif ($hasEntraPhoto || $hasMicrosoftAccount) {
    $photoSource = 'entra';
} else {
    $photoSource = 'default';
}

// Use fetch-profile-photo.php (live Entra ID photo, cached 24 h) when the user
// has no manually uploaded photo and an e-mail address is available.
// This mirrors the logic in main_layout.php so the sidebar and profile page
// always show the same image.
if ($photoSource !== 'manual' && !empty($user['email'])) {
    $profile_image = asset('fetch-profile-photo.php') . '?email=' . urlencode($user['email']);
} else {
    $profile_image = asset(User::getProfilePictureUrl($user['id'], $user));
}
ob_start();
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<style>.cropper-view-box,.cropper-face{border-radius:50%;}</style>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-user text-purple-600 mr-2"></i>
                Mein Profil
            </h1>
            <p class="text-gray-600 dark:text-gray-300 break-words hyphens-auto">Verwalte deine Kontoinformationen und Sicherheitseinstellungen</p>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Microsoft Entra Notice -->
<div class="mb-6 p-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
    <div class="flex items-start">
        <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-2xl mr-4 mt-1 shrink-0"></i>
        <div class="min-w-0">
            <h3 class="text-lg sm:text-xl font-semibold text-blue-900 dark:text-blue-100 mb-2">
                Zentral verwaltetes Profil
            </h3>
            <p class="text-blue-800 dark:text-blue-200 break-words hyphens-auto">
                Ihr Profil wird zentral über Microsoft Entra verwaltet. Für Änderungen wenden Sie sich bitte an IT@business-consulting.com. Vielen Dank.
            </p>
        </div>
    </div>
</div>

<?php if (!$isProfileComplete): ?>
<!-- Profile Completion Banner -->
<div class="mb-6 p-5 bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-900/20 dark:to-blue-900/20 border border-purple-200 dark:border-purple-700 rounded-lg">
    <div class="flex items-start gap-4">
        <div class="flex-shrink-0 mt-1">
            <i class="fas fa-user-check text-purple-600 dark:text-purple-400 text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-base font-semibold text-purple-900 dark:text-purple-100 break-words hyphens-auto">
                Vervollständige dein Profil!
            </p>
            <p class="text-sm text-purple-800 dark:text-purple-200 mt-1 break-words hyphens-auto">
                Ein vollständiges Profil hilft deinen Kolleginnen und Kollegen, dich besser kennenzulernen.
                Du bist schon zu <strong><?php echo $completionPercent; ?>%</strong> fertig – fast geschafft!
            </p>
            <!-- Progress bar -->
            <div class="mt-3 w-full bg-purple-200 dark:bg-purple-800 rounded-full h-2.5" role="progressbar" aria-valuenow="<?php echo $completionPercent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Profilfortschritt <?php echo $completionPercent; ?>%">
                <div class="bg-purple-600 h-2.5 rounded-full transition-all duration-300" style="width: <?php echo $completionPercent; ?>%"></div>
            </div>
            <?php if (!empty($completionMissing)): ?>
            <p class="text-xs text-purple-700 dark:text-purple-300 mt-2">
                <span class="font-medium">Noch fehlende Pflichtfelder:</span>
                <?php echo implode(', ', array_map(fn($k) => htmlspecialchars($completionFieldLabels[$k] ?? $k), $completionMissing)); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Account Info -->
    <div class="card p-6">
        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
            Kontoinformationen
        </h2>
        <div class="space-y-4">
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400">E-Mail</label>
                <p class="text-lg font-semibold text-gray-800 dark:text-gray-100 break-all"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            <?php 
            // Display role: Priority order is entra_roles > azure_roles > internal role
            $displayRoles = [];
            
            // 1. Check for entra_roles (JSON array of App Roles from JWT or Graph groups)
            if (!empty($user['entra_roles'])):
                $entraRoles = json_decode($user['entra_roles'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($entraRoles)) {
                    // Each entry is either an object with 'displayName' (Graph groups) or a plain role string (App Roles)
                    $displayRoles = array_values(array_filter(array_map(function($role) {
                        if (is_array($role) && isset($role['displayName'])) {
                            return $role['displayName'];
                        }
                        return translateAzureRole((string)$role);
                    }, $entraRoles)));
                } else {
                    error_log("Failed to decode entra_roles for user ID " . intval($user['id']) . ": " . json_last_error_msg());
                }
            
            // 2. If no entra_roles, check azure_roles (legacy format, requires translation)
            elseif (!empty($user['azure_roles'])):
                $azureRoles = json_decode($user['azure_roles'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($azureRoles)) {
                    $displayRoles = array_filter(array_map('translateAzureRole', $azureRoles));
                } else {
                    error_log("Failed to decode azure_roles for user ID " . intval($user['id']) . ": " . json_last_error_msg());
                }
            elseif (!empty($_SESSION['azure_roles'])):
                // Check session variable as alternative
                if (is_array($_SESSION['azure_roles'])) {
                    $displayRoles = array_filter(array_map('translateAzureRole', $_SESSION['azure_roles']));
                } else {
                    // Try to decode if it's JSON string
                    $sessionRoles = json_decode($_SESSION['azure_roles'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($sessionRoles)) {
                        $displayRoles = array_filter(array_map('translateAzureRole', $sessionRoles));
                    } else {
                        error_log("Failed to decode session azure_roles for user ID " . intval($user['id']) . ": " . json_last_error_msg());
                    }
                }
            endif;
            
            // 3. If still no roles, use internal role as fallback
            if (empty($displayRoles) && !empty($user['role'])):
                $displayRoles = [getFormattedRoleName($user['role'])];
            endif;
            
            // Display roles if we have any
            if (!empty($displayRoles)):
            ?>
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($displayRoles) === 1 ? 'Rolle' : 'Rollen'; ?></label>
                <div class="flex flex-wrap gap-2 mt-2">
                    <?php foreach ($displayRoles as $role): ?>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300 rounded-full font-semibold text-sm">
                            <?php echo htmlspecialchars($role); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php 
            endif; 
            ?>
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400">Letzter Login</label>
                <p class="text-lg text-gray-800 dark:text-gray-100">
                    <?php echo $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : 'Nie'; ?>
                </p>
            </div>
            <div>
                <label class="text-sm text-gray-500 dark:text-gray-400">Mitglied seit</label>
                <p class="text-lg text-gray-800 dark:text-gray-100"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></p>
            </div>
        </div>
    </div>

    <!-- Profile Information -->
    <div class="lg:col-span-2">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-user-edit text-purple-600 mr-2"></i>
                Profilangaben
            </h2>
            <p class="text-gray-600 dark:text-gray-300 mb-6 break-words hyphens-auto">
                Aktualisiere deine persönlichen Informationen und Kontaktdaten
            </p>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <!-- Common Fields -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vorname *</label>
                        <input 
                            type="text" 
                            name="first_name" 
                            required 
                            value="<?php echo htmlspecialchars($profile['first_name'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nachname *</label>
                        <input 
                            type="text" 
                            name="last_name" 
                            required 
                            value="<?php echo htmlspecialchars($profile['last_name'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">E-Mail (Profil) *</label>
                        <input 
                            type="email" 
                            name="profile_email" 
                            required 
                            value="<?php echo htmlspecialchars($profile['email'] ?? $user['email']); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        >
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Die erste E-Mail ist immer die von Microsoft Entra</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Zweite E-Mail (optional)</label>
                        <input 
                            type="email" 
                            name="secondary_email" 
                            value="<?php echo htmlspecialchars($profile['secondary_email'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="zusätzliche@email.de"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Telefon <span class="text-red-500">*</span></label>
                        <input 
                            type="tel" 
                            name="mobile_phone" 
                            required
                            value="<?php echo htmlspecialchars($profile['mobile_phone'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="+49 123 456789"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">LinkedIn URL <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input 
                            type="url" 
                            name="linkedin_url" 
                            value="<?php echo htmlspecialchars($profile['linkedin_url'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="https://linkedin.com/in/..."
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Xing URL <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input 
                            type="url" 
                            name="xing_url" 
                            value="<?php echo htmlspecialchars($profile['xing_url'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="https://xing.com/profile/..."
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Position im Verein</label>
                        <input 
                            type="text" 
                            value="<?php echo htmlspecialchars(getFormattedRoleName($userRole), ENT_QUOTES, 'UTF-8'); ?>"
                            readonly
                            class="w-full px-4 py-2 bg-gray-100 border border-gray-300 text-gray-600 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-400 rounded-lg cursor-not-allowed"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Position im Unternehmen <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input 
                            type="text" 
                            name="job_title"
                            value="<?php echo htmlspecialchars($user['job_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Senior Consultant"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Unternehmen (optional)</label>
                        <input 
                            type="text" 
                            name="company" 
                            value="<?php echo htmlspecialchars($user['company'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Acme Corporation"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Geschlecht</label>
                        <select 
                            name="gender"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        >
                            <option value="">Bitte wählen</option>
                            <option value="m" <?php echo ($profile['gender'] ?? '') === 'm' ? 'selected' : ''; ?>>Männlich</option>
                            <option value="f" <?php echo ($profile['gender'] ?? '') === 'f' ? 'selected' : ''; ?>>Weiblich</option>
                            <option value="d" <?php echo ($profile['gender'] ?? '') === 'd' ? 'selected' : ''; ?>>Divers</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Geburtstag <span class="text-red-500">*</span></label>
                        <input 
                            type="date" 
                            name="birthday" 
                            value="<?php echo htmlspecialchars($profile['birthday'] ?? ''); ?>"
                            max="<?php echo date('Y-m-d', strtotime('-16 years')); ?>"
                            required
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        >
                        <div class="mt-2">
                            <label class="inline-flex items-center cursor-pointer min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    name="show_birthday" 
                                    value="1"
                                    <?php echo (!empty($profile['show_birthday'])) ? 'checked' : ''; ?>
                                    class="w-4 h-4 text-blue-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 dark:bg-gray-700 dark:border-gray-600"
                                >
                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Geburtstag öffentlich anzeigen</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Profilbild</label>
                        <div class="flex flex-col items-center gap-2">
                            <label for="avatarInput" class="relative block w-32 h-32 mx-auto cursor-pointer group rounded-full">
                                <img src="<?= htmlspecialchars($profile_image) ?>" id="currentAvatar" class="w-full h-full object-cover rounded-full shadow-md group-hover:opacity-75 transition-opacity">
                                <div class="absolute inset-0 flex items-center justify-center rounded-full bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="fas fa-camera text-white text-xl"></i>
                                </div>
                            </label>
                            <?php if ($photoSource === 'manual'): ?>
                                <?php $_removeAvatarBtnClass = 'text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 underline min-h-[44px] px-2'; ?>
                                <p class="text-xs text-gray-500 dark:text-gray-400 text-center">
                                    <i class="fas fa-upload text-gray-400 mr-1"></i>Eigenes Foto hochgeladen<br>
                                    <span class="text-gray-400">Klicke auf das Bild zum Ändern</span>
                                </p>
                                <?php if (!empty($user['azure_oid'])): ?>
                                    <button type="button" id="removeAvatarBtn" class="<?= $_removeAvatarBtnClass ?>">
                                        <i class="fas fa-trash-alt mr-1"></i>Foto entfernen und Microsoft-Foto verwenden
                                    </button>
                                <?php else: ?>
                                    <button type="button" id="removeAvatarBtn" class="<?= $_removeAvatarBtnClass ?>">
                                        <i class="fas fa-trash-alt mr-1"></i>Foto entfernen
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($photoSource === 'entra'): ?>
                                <p class="text-xs text-blue-600 dark:text-blue-400 text-center">
                                    <i class="fab fa-microsoft mr-1"></i>Microsoft-Foto wird verwendet
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 text-center">Klicke auf das Bild zum Ändern<br>JPG, PNG, GIF oder WEBP (Max. 5MB)</p>
                            <?php else: ?>
                                <p class="text-xs text-gray-500 dark:text-gray-400 text-center">Klicke auf das Bild zum Ändern<br>JPG, PNG, GIF oder WEBP (Max. 5MB)</p>
                            <?php endif; ?>
                        </div>
                        <input type="file" id="avatarInput" accept="image/*" class="hidden">
                        <!-- Hidden field that carries the cropped base64 image to the server -->
                        <input type="hidden" name="profile_picture_data" id="profilePictureData">
                    </div>
                    
                    <?php if (isMemberRole($userRole)): ?>
                    <!-- Fields for Students: Candidates, Members, Board, and Heads -->
                    <!-- Student View: Show Studium -->
                    <div class="md:col-span-2">
                        <h3 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100 mb-3 border-b border-gray-300 dark:border-gray-600 pb-2">
                            Studium
                        </h3>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bachelor-Studiengang</label>
                        <input 
                            type="text" 
                            name="bachelor_studiengang" 
                            value="<?php echo htmlspecialchars($profile['study_program'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Wirtschaftsingenieurwesen"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bachelor-Semester</label>
                        <input 
                            type="text" 
                            name="bachelor_semester" 
                            value="<?php echo htmlspecialchars($profile['semester'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. 5"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Master-Studiengang (optional)</label>
                        <input 
                            type="text" 
                            name="master_studiengang" 
                            value="<?php echo htmlspecialchars($profile['angestrebter_abschluss'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Management & Engineering"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Master-Semester (optional)</label>
                        <input 
                            type="text" 
                            name="master_semester" 
                            value="<?php echo htmlspecialchars($profile['graduation_year'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. 2"
                        >
                    </div>
                    <?php elseif (isAlumniRole($userRole)): ?>
                    <!-- Fields for Alumni and Honorary Members -->
                    <!-- Alumni View: Show Absolviertes Studium -->
                    <div class="md:col-span-2">
                        <h3 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100 mb-3 border-b border-gray-300 dark:border-gray-600 pb-2">
                            Absolviertes Studium
                        </h3>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bachelor-Studiengang</label>
                        <input 
                            type="text" 
                            name="bachelor_studiengang" 
                            value="<?php echo htmlspecialchars($profile['study_program'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Wirtschaftsingenieurwesen"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bachelor-Abschlussjahr</label>
                        <input 
                            type="text" 
                            name="bachelor_year" 
                            value="<?php echo htmlspecialchars($profile['semester'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. 2020"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Master-Studiengang (optional)</label>
                        <input 
                            type="text" 
                            name="master_studiengang" 
                            value="<?php echo htmlspecialchars($profile['angestrebter_abschluss'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Management & Engineering"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Master-Abschlussjahr (optional)</label>
                        <input 
                            type="text" 
                            name="master_year" 
                            value="<?php echo htmlspecialchars($profile['graduation_year'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. 2022"
                        >
                    </div>
                    
                    <div class="md:col-span-2">
                        <h3 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100 mb-3 border-b border-gray-300 dark:border-gray-600 pb-2 mt-4">
                            Berufliche Informationen
                        </h3>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Aktueller Arbeitgeber (optional)</label>
                        <input 
                            type="text" 
                            name="company" 
                            value="<?php echo htmlspecialchars($profile['company'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="Firmenname"
                        >
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Branche</label>
                        <input 
                            type="text" 
                            name="industry" 
                            value="<?php echo htmlspecialchars($profile['industry'] ?? ''); ?>"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                            placeholder="z.B. Beratung, IT, Finanzen"
                        >
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- About Me - Full Width -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Über mich
                        <span class="text-xs text-gray-500 dark:text-gray-400 ml-2">
                            (<span id="char-count">0</span>/400 Zeichen)
                        </span>
                    </label>
                    <textarea 
                        id="about_me"
                        name="about_me" 
                        rows="4"
                        maxlength="400"
                        class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                        placeholder="Erzähle etwas über dich..."
                    ><?php echo htmlspecialchars($profile['about_me'] ?? ''); ?></textarea>
                </div>
                
                <!-- Skills - Full Width -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-tags text-teal-600 mr-1"></i>
                        Fähigkeiten / Skills
                    </label>
                    <?php
                    $skillsPreview = !empty($profile['skills']) ? array_values(array_filter(array_map('trim', explode(',', $profile['skills'])))) : [];
                    if (!empty($skillsPreview)):
                    ?>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach ($skillsPreview as $skill): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300 border border-teal-200 dark:border-teal-700">
                            <?php echo htmlspecialchars($skill, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <input
                        type="text"
                        name="skills"
                        id="skills"
                        value="<?php echo htmlspecialchars($profile['skills'] ?? ''); ?>"
                        maxlength="500"
                        placeholder="z.B. PHP, JavaScript, Design"
                        class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
                    >
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        <i class="fas fa-info-circle mr-1"></i>Trenne deine Fähigkeiten mit einem Komma. Fähigkeiten sind in der Mitgliedersuche auffindbar.
                    </p>
                </div>
                
                <!-- CV Upload - Full Width -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <i class="fas fa-file-pdf text-red-500 mr-1"></i>
                        Lebenslauf (CV)
                        <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">(PDF, max. 10MB)</span>
                    </label>
                    <?php if (!empty($profile['cv_path'])): ?>
                    <div class="mb-2 flex items-center gap-3 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700">
                        <i class="fas fa-file-pdf text-red-500 text-xl"></i>
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Lebenslauf hochgeladen</p>
                            <a href="<?php echo htmlspecialchars(asset($profile['cv_path']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-xs text-blue-600 hover:underline">
                                <i class="fas fa-download mr-1"></i>Herunterladen
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($jobBoardCvPath !== null): ?>
                    <div class="mb-2 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-briefcase text-blue-500 mr-1"></i>
                            Du hast einen Lebenslauf in einem Job-Gesuch hinterlegt.
                        </p>
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="cv_source" value="job_board" id="cv_source_job" class="text-blue-600">
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    <i class="fas fa-file-pdf text-red-500 mr-1"></i>Lebenslauf aus Job-Gesuch ins Profil übernehmen
                                </span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="cv_source" value="upload" id="cv_source_upload_profile" checked class="text-blue-600">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Neue Datei hochladen</span>
                            </label>
                        </div>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="cv_source" value="upload">
                    <?php endif; ?>
                    <div id="cv_profile_upload_field">
                        <input
                            type="file"
                            name="cv_file"
                            id="cv_file_input"
                            accept="application/pdf,.pdf"
                            class="w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-blue-900/30 dark:file:text-blue-300 dark:hover:file:bg-blue-900/50"
                        >
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Lade deinen Lebenslauf als PDF hoch. <?php if (!empty($profile['cv_path'])): ?>Das Hochladen einer neuen Datei ersetzt den bestehenden Lebenslauf.<?php endif; ?></p>
                    </div>
                    <?php if ($jobBoardCvPath !== null): ?>
                    <script>
                    (function () {
                        var jobRadio     = document.getElementById('cv_source_job');
                        var uploadRadio  = document.getElementById('cv_source_upload_profile');
                        var uploadField  = document.getElementById('cv_profile_upload_field');
                        var fileInput    = document.getElementById('cv_file_input');
                        function toggle() {
                            var showUpload = uploadRadio.checked;
                            uploadField.style.display = showUpload ? '' : 'none';
                            fileInput.disabled = !showUpload;
                        }
                        jobRadio.addEventListener('change', toggle);
                        uploadRadio.addEventListener('change', toggle);
                        toggle();
                    })();
                    </script>
                    <?php endif; ?>
                </div>
                
                <button type="submit" name="update_profile" class="w-full btn-primary">
                    <i class="fas fa-save mr-2"></i>Profil speichern
                </button>
            </form>
        </div>
    </div>

    <!-- 2FA Settings -->
    <div class="lg:col-span-2">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-shield-alt text-green-600 mr-2"></i>
                Zwei-Faktor-Authentifizierung (2FA)
            </h2>

            <?php if (!$showQRCode): ?>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="text-gray-700 dark:text-gray-300 mb-2">
                        Status: 
                        <?php if ($user['tfa_enabled']): ?>
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-semibold">
                            <i class="fas fa-check-circle mr-1"></i>Aktiviert
                        </span>
                        <?php else: ?>
                        <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-full font-semibold">
                            <i class="fas fa-times-circle mr-1"></i>Deaktiviert
                        </span>
                        <?php endif; ?>
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Schütze dein Konto mit einer zusätzlichen Sicherheitsebene
                    </p>
                </div>
                <div>
                    <?php if ($user['tfa_enabled']): ?>
                    <form method="POST" onsubmit="return confirm('Möchtest du 2FA wirklich deaktivieren?');">
                        <button type="submit" name="disable_2fa" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            <i class="fas fa-times mr-2"></i>2FA deaktivieren
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST">
                        <button type="submit" name="enable_2fa" class="btn-primary">
                            <i class="fas fa-plus mr-2"></i>2FA aktivieren
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-400 dark:border-blue-500 p-4">
                <p class="text-sm text-blue-700 dark:text-blue-300">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Empfehlung:</strong> Aktiviere 2FA für zusätzliche Sicherheit. Du benötigst eine Authenticator-App wie Google Authenticator oder Authy.
                </p>
            </div>
            <?php else: ?>
            <!-- QR Code Setup -->
            <div class="max-w-md mx-auto">
                <div class="text-center mb-6">
                    <h3 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100 mb-2">2FA einrichten</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                        Scanne den QR-Code mit deiner Authenticator-App und gib den generierten Code ein
                    </p>
                    <div id="qrcode" class="mx-auto mb-4 inline-block"></div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                        Geheimer Schlüssel (manuell): <code class="bg-gray-100 dark:bg-gray-700 dark:text-gray-300 px-2 py-1 rounded"><?php echo htmlspecialchars($secret); ?></code>
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="secret" value="<?php echo htmlspecialchars($secret); ?>">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">6-stelliger Code</label>
                        <input 
                            type="text" 
                            name="code" 
                            required 
                            maxlength="6"
                            pattern="[0-9]{6}"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg text-center text-2xl tracking-widest"
                            placeholder="000000"
                            autofocus
                        >
                    </div>
                    <div class="flex space-x-4">
                        <a href="profile.php" class="flex-1 text-center px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                            Abbrechen
                        </a>
                        <button type="submit" name="confirm_2fa" class="flex-1 btn-primary">
                            <i class="fas fa-check mr-2"></i>Bestätigen
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Change Request Section -->
<div class="card p-6 mt-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-edit text-green-600 mr-2"></i>
        Änderungsantrag
    </h2>
    <p class="text-gray-600 dark:text-gray-300 mb-6">
        Beantragen Sie Änderungen an Ihrer Rolle oder E-Mail-Adresse
    </p>
    
    <form method="POST" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Art der Änderung *</label>
            <select 
                name="request_type" 
                required 
                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
            >
                <option value="">Bitte wählen...</option>
                <option value="Rollenänderung">Rollenänderung</option>
                <option value="E-Mail-Adressenänderung">E-Mail-Adressenänderung</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Begründung / Neuer Wert *</label>
            <textarea 
                name="request_reason" 
                required 
                minlength="10"
                maxlength="1000"
                rows="4"
                placeholder="Bitte geben Sie eine Begründung oder den neuen gewünschten Wert an..."
                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 rounded-lg"
            ></textarea>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mindestens 10, maximal 1000 Zeichen</p>
        </div>
        
        <button 
            type="submit" 
            name="submit_change_request"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-200"
        >
            <i class="fas fa-paper-plane mr-2"></i>
            Beantragen
        </button>
    </form>
</div>

<!-- Help & Changes Card -->
<div class="card p-6 mt-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        <i class="fas fa-life-ring text-blue-600 mr-2"></i>
        Hilfe
    </h2>
    <p class="text-gray-600 dark:text-gray-300 mb-5">
        Benötigst du Hilfe oder möchtest etwas ändern? Hier geht's direkt zur richtigen Stelle.
    </p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <button type="button" onclick="showSupportModal('2fa_reset')"
           class="flex items-center p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition text-left w-full">
            <i class="fas fa-shield-alt text-yellow-600 dark:text-yellow-400 text-2xl mr-4 shrink-0"></i>
            <div class="min-w-0">
                <span class="block font-semibold text-gray-800 dark:text-gray-100 break-words hyphens-auto">2FA zurücksetzen</span>
                <span class="block text-sm text-gray-600 dark:text-gray-400 break-words hyphens-auto">Anfrage per E-Mail senden</span>
            </div>
        </button>
        <button type="button" onclick="showSupportModal('bug')"
           class="flex items-center p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/40 transition text-left w-full">
            <i class="fas fa-bug text-red-600 dark:text-red-400 text-2xl mr-4 shrink-0"></i>
            <div class="min-w-0">
                <span class="block font-semibold text-gray-800 dark:text-gray-100 break-words hyphens-auto">Bug melden</span>
                <span class="block text-sm text-gray-600 dark:text-gray-400 break-words hyphens-auto">Fehler per E-Mail melden</span>
            </div>
        </button>
    </div>
</div>

<!-- Support Modal -->
<div id="supportModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg sm:text-xl font-bold dark:text-white">
                    <i class="fas fa-headset text-blue-600 mr-2"></i>Hilfe
                </h3>
                <button type="button" onclick="hideSupportModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <form id="support-modal-form" method="POST" action="<?php echo asset('api/submit_support.php'); ?>" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label for="support-modal-type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Art der Anfrage</label>
                    <select id="support-modal-type" name="request_type" required
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Bitte auswählen...</option>
                        <option value="2fa_reset">2FA zurücksetzen</option>
                        <option value="bug">Bug / Fehler</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                <div>
                    <label for="support-modal-description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung</label>
                    <textarea id="support-modal-description" name="description" rows="4" required
                              placeholder="Beschreibe dein Anliegen..."
                              class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                <div id="support-modal-feedback" class="hidden"></div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="hideSupportModal()"
                            class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                        Abbrechen
                    </button>
                    <button type="submit" id="support-modal-submit-btn" class="btn-primary">
                        <i class="fas fa-paper-plane mr-2"></i>Senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cropper.js Modal -->
<div id="cropperModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <h3 class="text-lg sm:text-xl font-bold mb-4 dark:text-white">Profilbild zuschneiden</h3>
            <div class="w-full max-h-96 mb-4 overflow-hidden flex justify-center">
                <img id="cropperImage" class="max-w-full">
            </div>
        </div>
        <div class="flex justify-end gap-3 px-6 pb-6 pt-2">
            <button type="button" id="closeCropperBtn" class="px-4 py-2 rounded-xl bg-gray-200 text-gray-800 hover:bg-gray-300">Abbrechen</button>
            <button type="button" id="saveCropperBtn" class="px-4 py-2 rounded-xl bg-ibc-green text-white hover:bg-green-600">Speichern</button>
        </div>
    </div>
</div>

<!-- QRCode.js Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
// Generate QR Code if the element exists
<?php if ($showQRCode && !empty($qrCodeUrl)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const qrcodeElement = document.getElementById('qrcode');
    if (qrcodeElement) {
        // Clear any existing QR code
        qrcodeElement.innerHTML = '';
        
        // Generate QR Code
        new QRCode(qrcodeElement, {
            text: <?php echo json_encode($qrCodeUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            width: 200,
            height: 200,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
    }
});
<?php endif; ?>

// Character counter for "Über mich" field
document.addEventListener('DOMContentLoaded', function() {
    const aboutMeTextarea = document.getElementById('about_me');
    const charCount = document.getElementById('char-count');
    
    if (aboutMeTextarea && charCount) {
        // Update counter on page load
        charCount.textContent = aboutMeTextarea.value.length;
        
        // Update counter on input
        aboutMeTextarea.addEventListener('input', function() {
            charCount.textContent = this.value.length;
        });
    }
    
    // Email change confirmation for non-alumni users
    const profileForm = document.querySelector('form[enctype="multipart/form-data"]');
    const emailInput = document.querySelector('input[name="profile_email"]');
    const originalEmail = <?php echo json_encode($profile['email'] ?? $user['email'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const userRole = <?php echo json_encode($userRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    
    if (profileForm && emailInput) {
        profileForm.addEventListener('submit', function(e) {
            // Only check for profile update submissions
            if (!e.submitter || e.submitter.name !== 'update_profile') {
                return true;
            }
            
            // Check if email has changed and user is not alumni
            const isAlumniRole = ['alumni', 'alumni_vorstand', 'ehrenmitglied'].includes(userRole);
            const emailChanged = emailInput.value.trim() !== originalEmail;
            
            if (emailChanged && !isAlumniRole) {
                const confirmed = confirm('Willst du deine E-Mail wirklich ändern? Dies ändert deinen Login-Namen.');
                if (!confirmed) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
});
</script>

<script>
// Cropper.js – profile picture upload
(function () {
    let cropperInstance = null;

    const avatarInput  = document.getElementById('avatarInput');
    const cropperModal = document.getElementById('cropperModal');
    const cropperImage = document.getElementById('cropperImage');
    const saveBtn      = document.getElementById('saveCropperBtn');
    const closeBtn     = document.getElementById('closeCropperBtn');

    if (!avatarInput || !cropperModal) {
        return;
    }

    // When user selects a file, read via FileReader and open the cropper modal
    avatarInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            cropperImage.src = e.target.result;
            cropperModal.classList.remove('hidden');

            if (cropperInstance) {
                cropperInstance.destroy();
                cropperInstance = null;
            }
            cropperInstance = new Cropper(cropperImage, {
                aspectRatio: 1,
                viewMode: 1,
            });
        };
        reader.readAsDataURL(file);
    });

    function closeModal() {
        cropperModal.classList.add('hidden');
        if (cropperInstance) {
            cropperInstance.destroy();
            cropperInstance = null;
        }
        cropperImage.src = '';
        avatarInput.value = '';
    }

    // "Speichern" – send base64 via fetch to api/upload_avatar.php, then reload
    saveBtn.addEventListener('click', function () {
        if (!cropperInstance) return;

        const canvas = cropperInstance.getCroppedCanvas({ width: 400, height: 400 });
        if (!canvas) return;

        const dataUrl = canvas.toDataURL('image/jpeg', 0.92);

        saveBtn.disabled = true;
        saveBtn.textContent = 'Speichern...';

        fetch('<?php echo asset('api/upload_avatar.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ image: dataUrl, csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                closeModal();
                window.location.reload();
            } else {
                alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
                saveBtn.disabled = false;
                saveBtn.textContent = 'Speichern';
            }
        })
        .catch(function () {
            alert('Netzwerkfehler. Bitte versuche es erneut.');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Speichern';
        });
    });

    closeBtn.addEventListener('click', closeModal);

    // Close on backdrop click
    cropperModal.addEventListener('click', function (e) {
        if (e.target === cropperModal) {
            closeModal();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !cropperModal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // "Foto entfernen" – clears the manually uploaded photo and reverts to Entra/default
    const removeAvatarBtn = document.getElementById('removeAvatarBtn');
    if (removeAvatarBtn) {
        removeAvatarBtn.addEventListener('click', function () {
            if (!confirm('Eigenes Profilbild wirklich entfernen?')) return;
            removeAvatarBtn.disabled = true;
            fetch('<?php echo asset('api/delete_avatar.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: <?php echo json_encode(CSRFHandler::getToken()); ?> }),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
                    removeAvatarBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Netzwerkfehler. Bitte versuche es erneut.');
                removeAvatarBtn.disabled = false;
            });
        });
    }
}());
</script>

<script>
// Support Modal
function showSupportModal(type) {
    const modal = document.getElementById('supportModal');
    if (!modal) return;
    const select = document.getElementById('support-modal-type');
    if (select && type) {
        select.value = type;
        select.disabled = true;
        let hidden = document.getElementById('support-modal-type-hidden');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.id = 'support-modal-type-hidden';
            hidden.name = 'request_type';
            select.parentNode.appendChild(hidden);
        }
        hidden.value = type;
    }
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function hideSupportModal() {
    const modal = document.getElementById('supportModal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    const select = document.getElementById('support-modal-type');
    if (select) {
        select.disabled = false;
    }
    const hidden = document.getElementById('support-modal-type-hidden');
    if (hidden) hidden.remove();
    const form = document.getElementById('support-modal-form');
    if (form) form.reset();
    const feedback = document.getElementById('support-modal-feedback');
    if (feedback) {
        feedback.className = 'hidden';
        feedback.textContent = '';
    }
}

(function() {
    const modal = document.getElementById('supportModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) hideSupportModal();
        });
    }

    const supportForm = document.getElementById('support-modal-form');
    if (!supportForm) return;
    supportForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('support-modal-submit-btn');
        const feedback = document.getElementById('support-modal-feedback');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Wird gesendet...';

        fetch(this.action, { method: 'POST', body: new FormData(this) })
            .then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function(data) {
                feedback.classList.remove('hidden');
                if (data.success) {
                    feedback.className = 'mt-2 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg';
                    feedback.textContent = data.message || 'Anfrage erfolgreich gesendet!';
                    supportForm.reset();
                    submitBtn.innerHTML = originalText;
                } else {
                    feedback.className = 'mt-2 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg';
                    feedback.textContent = data.message || 'Fehler beim Senden der Anfrage.';
                    submitBtn.innerHTML = originalText;
                }
                submitBtn.disabled = false;
            })
            .catch(function() {
                feedback.className = 'mt-2 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg';
                feedback.classList.remove('hidden');
                feedback.textContent = 'Fehler beim Senden der Anfrage.';
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
    });
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
