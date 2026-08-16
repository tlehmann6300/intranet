<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';

// Access Control: Users can edit their own profile based on their role
// - Alumni and alumni_board roles can edit their own profiles (alumni status)
// - Board, head, candidate, member roles can edit their own profiles (active member status)
// - Admin can edit their own profile
// Note: All profiles use the alumni_profiles table regardless of user role
// Note: This page only allows users to edit their own profile (no cross-user editing)
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get current user info
$user = Auth::user();
$userId = $_SESSION['user_id'];
$userRole = $user['role'] ?? '';

// Check permission: All authenticated users with these roles can edit their own profile
$allowedRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter', 'anwaerter', 'mitglied', 'ehrenmitglied'];
if (!in_array($userRole, $allowedRoles)) {
    $_SESSION['error_message'] = 'Du hast keine Berechtigung, Profile zu bearbeiten.';
    header('Location: ../dashboard/index.php');
    exit;
}

// Fetch profile for current user only ($userId from session) - this prevents cross-user edits
$profile = Alumni::getProfileByUserId($userId);

// Check if this is a first-time profile completion (profile_complete = 0)
$isFirstTimeSetup = isset($user['profile_complete']) && $user['profile_complete'] == 0;

$message = '';
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    // Get form data
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobilePhone = trim($_POST['mobile_phone'] ?? '');
    $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
    $xingUrl = trim($_POST['xing_url'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $position = trim($_POST['position'] ?? '');
    
    // Validate required fields
    // For first-time setup, only require first_name and last_name
    if ($isFirstTimeSetup) {
        if (empty($firstName) || empty($lastName)) {
            $errors[] = 'Bitte geben Sie Ihren Vornamen und Nachnamen ein, um fortzufahren.';
        }
    } else {
        // For normal edits, require name and email only (company and position are optional)
        if (empty($firstName) || empty($lastName) || empty($email)) {
            $errors[] = 'Bitte füllen Sie alle Pflichtfelder aus (Vorname, Nachname, E-Mail)';
        }
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein';
    }
    
    if (!empty($linkedinUrl) && !filter_var($linkedinUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte geben Sie eine gültige LinkedIn-URL ein';
    }
    
    if (!empty($xingUrl) && !filter_var($xingUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte geben Sie eine gültige Xing-URL ein';
    }
    
    if (empty($errors)) {
        // Prepare data array
        $data = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'mobile_phone' => $mobilePhone,
            'linkedin_url' => $linkedinUrl,
            'xing_url' => $xingUrl,
            'industry' => $industry,
            'company' => $company,
            'position' => $position
        ];
        
        // Handle image upload if provided
        if (isset($_FILES['image'])) {
            $uploadError = $_FILES['image']['error'];
            
            if ($uploadError === UPLOAD_ERR_OK) {
                $uploadResult = SecureImageUpload::uploadImage($_FILES['image']);
                
                if ($uploadResult['success']) {
                    // Delete old image if updating and old image exists
                    if ($profile && !empty($profile['image_path'])) {
                        SecureImageUpload::deleteImage($profile['image_path']);
                    }
                    $data['image_path'] = $uploadResult['path'];
                } else {
                    $errors[] = $uploadResult['error'];
                }
            } elseif ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Die hochgeladene Datei ist zu groß. Maximum: 5MB';
            } elseif ($uploadError === UPLOAD_ERR_PARTIAL) {
                $errors[] = 'Die Datei wurde nur teilweise hochgeladen. Bitte versuchen Sie es erneut.';
            } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Fehler beim Hochladen der Datei (Code: ' . $uploadError . ')';
            }
        }
        
        // Keep existing image if no new image uploaded and profile exists
        if (empty($errors) && $profile && !empty($profile['image_path']) && !isset($data['image_path'])) {
            $data['image_path'] = $profile['image_path'];
        }
        
        // If no errors, update or create the profile
        if (empty($errors)) {
            try {
                if (Alumni::updateOrCreateProfile($userId, $data)) {
                    // Update last_verified_at timestamp
                    Alumni::verifyProfile($userId);
                    
                    // If this was first-time setup and first_name and last_name are now provided,
                    // mark profile as complete
                    if ($isFirstTimeSetup && !empty($firstName) && !empty($lastName)) {
                        require_once __DIR__ . '/../../includes/models/User.php';
                        User::update($userId, ['profile_complete' => 1]);
                        // First-time setup complete - redirect to dashboard
                        $_SESSION['success_message'] = 'Profil erfolgreich erstellt!';
                        header('Location: ../dashboard/index.php');
                        exit;
                    }
                    
                    // Regular profile update - redirect back to alumni directory
                    $_SESSION['success_message'] = 'Profil erfolgreich gespeichert!';
                    header('Location: index.php');
                    exit;
                } else {
                    $errors[] = 'Fehler beim Speichern des Profils. Bitte versuchen Sie es erneut.';
                }
            } catch (Exception $e) {
                $errors[] = 'Fehler: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Pre-fill form values from existing profile or user data
$firstName = $profile['first_name'] ?? $user['first_name'] ?? '';
$lastName = $profile['last_name'] ?? $user['last_name'] ?? '';
$email = $profile['email'] ?? $user['email'] ?? '';
$mobilePhone = $profile['mobile_phone'] ?? '';
$linkedinUrl = $profile['linkedin_url'] ?? '';
$xingUrl = $profile['xing_url'] ?? '';
$industry = $profile['industry'] ?? '';
$company = $profile['company'] ?? '';
$position = $profile['position'] ?? '';
$imagePath = $profile['image_path'] ?? '';

$title = 'Mein Alumni-Profil bearbeiten - IBC Intranet';
ob_start();
?>

<style>
/* ── Alumni Edit Form ────────────────────────────────────── */
.alme-container {
    max-width: 56rem;
    margin: 0 auto;
}

.alme-back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--ibc-blue);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9375rem;
    margin-bottom: 1.5rem;
    transition: opacity 0.2s;
}

.alme-back-link:hover {
    opacity: 0.8;
}

.alme-alert {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1.125rem;
    border-radius: 0.875rem;
    margin-bottom: 1.5rem;
    font-size: 0.9375rem;
    border: 1.5px solid;
}

.alme-alert__icon {
    flex-shrink: 0;
    font-size: 1.25rem;
    margin-top: 0.125rem;
}

.alme-alert--warning {
    background: rgba(251,146,60,0.08);
    border-color: rgba(251,146,60,0.2);
    color: #92400e;
}

.alme-alert--info {
    background: rgba(59,130,246,0.08);
    border-color: rgba(59,130,246,0.2);
    color: #1e40af;
}

.alme-alert--error {
    background: rgba(239,68,68,0.08);
    border-color: rgba(239,68,68,0.2);
    color: #991b1b;
}

.dark-mode .alme-alert--warning {
    background: rgba(251,146,60,0.12);
    color: #fbbf24;
}

.dark-mode .alme-alert--info {
    background: rgba(59,130,246,0.12);
    color: #93c5fd;
}

.dark-mode .alme-alert--error {
    background: rgba(239,68,68,0.12);
    color: #fca5a5;
}

.alme-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    padding: 2rem;
    box-shadow: var(--shadow-card);
}

.alme-header {
    margin-bottom: 1.5rem;
}

.alme-title {
    font-size: 1.875rem;
    font-weight: 800;
    color: var(--text-main);
    margin: 0 0 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alme-title-icon {
    color: var(--ibc-blue);
    font-size: 1.5rem;
}

.alme-subtitle {
    font-size: 0.9375rem;
    color: var(--text-muted);
    margin: 0;
}

.alme-section {
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 1.5rem;
    margin-bottom: 1.5rem;
}

.alme-section:last-of-type {
    border-bottom: none;
}

.alme-section-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
}

.alme-form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

@media (min-width: 640px) {
    .alme-form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.alme-form-grid--full {
    grid-column: 1 / -1;
}

.alme-form-group {
    display: flex;
    flex-direction: column;
}

.alme-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.alme-label--required::after {
    content: ' *';
    color: #ef4444;
}

.alme-input,
.alme-file-input {
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    background: var(--bg-body);
    color: var(--text-main);
    font-size: 0.9375rem;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
    min-height: 44px;
}

.alme-input:focus,
.alme-file-input:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0,102,179,0.1);
}

.alme-input::placeholder {
    color: var(--text-muted);
}

.alme-file-input {
    padding: 0.75rem;
    cursor: pointer;
}

.alme-help-text {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin-top: 0.375rem;
}

.alme-image-preview {
    width: 8rem;
    height: 8rem;
    border-radius: 9999px;
    object-fit: cover;
    box-shadow: var(--shadow-card);
    margin-bottom: 1rem;
}

.alme-image-label {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
}

.alme-button-group {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

@media (min-width: 768px) {
    .alme-button-group {
        flex-direction: row;
        justify-content: flex-end;
    }
}

.alme-btn-primary,
.alme-btn-secondary {
    padding: 0.75rem 1.5rem;
    border-radius: 0.625rem;
    border: none;
    font-size: 0.9375rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(.22,.68,0,1.2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    min-height: 44px;
}

.alme-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    color: #fff;
    box-shadow: 0 2px 10px rgba(0,102,179,0.25);
}

.alme-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,102,179,0.35);
}

.alme-btn-primary:active {
    transform: translateY(0);
}

.alme-btn-secondary {
    background: var(--bg-card);
    color: var(--text-main);
    border: 1.5px solid var(--border-color);
}

.alme-btn-secondary:hover {
    background: rgba(100,116,139,0.1);
}

@media (max-width: 640px) {
    .alme-card {
        padding: 1.25rem;
    }

    .alme-title {
        font-size: 1.5rem;
    }

    .alme-section {
        margin-bottom: 1.25rem;
        padding-bottom: 1.25rem;
    }

    .alme-button-group {
        gap: 0.75rem;
    }

    .alme-btn-primary,
    .alme-btn-secondary {
        width: 100%;
    }
}
</style>

<div class="alme-container">
    <?php if (!$isFirstTimeSetup): ?>
    <a href="index.php" class="alme-back-link">
        <i class="fas fa-arrow-left"></i>Zurück zum Alumni Directory
    </a>
    <?php endif; ?>

    <?php if ($isFirstTimeSetup): ?>
    <div class="alme-alert alme-alert--warning">
        <i class="fas fa-exclamation-triangle alme-alert__icon"></i>
        <div>
            <strong>Profil vervollständigen erforderlich</strong>
            <p style="margin-top: 0.25rem; margin-bottom: 0;">Bitte geben Sie Ihren Vornamen und Nachnamen ein, um fortzufahren. Diese Informationen sind erforderlich.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['profile_incomplete_message'])): ?>
    <div class="alme-alert alme-alert--info">
        <i class="fas fa-info-circle alme-alert__icon"></i>
        <span><?php echo htmlspecialchars($_SESSION['profile_incomplete_message']); ?></span>
    </div>
    <?php unset($_SESSION['profile_incomplete_message']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alme-alert alme-alert--error">
        <i class="fas fa-exclamation-circle alme-alert__icon"></i>
        <div>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="alme-card">
        <div class="alme-header">
            <h1 class="alme-title">
                <i class="fas fa-user-edit alme-title-icon"></i>
                <?php echo $profile ? 'Profil bearbeiten' : 'Profil erstellen'; ?>
            </h1>
            <p class="alme-subtitle">Vervollständigen Sie Ihr Alumni-Profil, damit andere Sie finden und kontaktieren können.</p>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Personal Information -->
            <div class="alme-section">
                <h2 class="alme-section-title">Persönliche Informationen</h2>
                <div class="alme-form-grid">
                    <div class="alme-form-group">
                        <label class="alme-label alme-label--required">Vorname</label>
                        <input
                            type="text"
                            name="first_name"
                            required
                            value="<?php echo htmlspecialchars($firstName); ?>"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group">
                        <label class="alme-label alme-label--required">Nachname</label>
                        <input
                            type="text"
                            name="last_name"
                            required
                            value="<?php echo htmlspecialchars($lastName); ?>"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group">
                        <label class="alme-label alme-label--required">E-Mail</label>
                        <input
                            type="email"
                            name="email"
                            required
                            value="<?php echo htmlspecialchars($email); ?>"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group">
                        <label class="alme-label">Mobiltelefon</label>
                        <input
                            type="text"
                            name="mobile_phone"
                            value="<?php echo htmlspecialchars($mobilePhone); ?>"
                            placeholder="+49 123 4567890"
                            class="alme-input"
                        >
                    </div>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="alme-section">
                <h2 class="alme-section-title">Berufliche Informationen</h2>
                <div class="alme-form-grid">
                    <div class="alme-form-group">
                        <label class="alme-label alme-label--required">Firma</label>
                        <input
                            type="text"
                            name="company"
                            required
                            value="<?php echo htmlspecialchars($company); ?>"
                            placeholder="z.B. ABC GmbH"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group">
                        <label class="alme-label alme-label--required">Position</label>
                        <input
                            type="text"
                            name="position"
                            required
                            value="<?php echo htmlspecialchars($position); ?>"
                            placeholder="z.B. Senior Consultant"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group alme-form-grid--full">
                        <label class="alme-label">Branche</label>
                        <input
                            type="text"
                            name="industry"
                            value="<?php echo htmlspecialchars($industry); ?>"
                            placeholder="z.B. IT, Consulting, Finance"
                            class="alme-input"
                        >
                    </div>
                </div>
            </div>

            <!-- Social Media Links -->
            <div class="alme-section">
                <h2 class="alme-section-title">Social Media</h2>
                <div class="alme-form-grid">
                    <div class="alme-form-group">
                        <label class="alme-label">
                            <i class="fab fa-linkedin" style="color: var(--ibc-blue); margin-right: 0.25rem;"></i>
                            LinkedIn URL
                        </label>
                        <input
                            type="url"
                            name="linkedin_url"
                            value="<?php echo htmlspecialchars($linkedinUrl); ?>"
                            placeholder="https://www.linkedin.com/in/ihr-profil"
                            class="alme-input"
                        >
                    </div>

                    <div class="alme-form-group">
                        <label class="alme-label">
                            <i class="fab fa-xing" style="color: var(--ibc-green); margin-right: 0.25rem;"></i>
                            Xing URL
                        </label>
                        <input
                            type="url"
                            name="xing_url"
                            value="<?php echo htmlspecialchars($xingUrl); ?>"
                            placeholder="https://www.xing.com/profile/ihr-profil"
                            class="alme-input"
                        >
                    </div>
                </div>
            </div>

            <!-- Profile Picture -->
            <div class="alme-section">
                <h2 class="alme-section-title">Profilbild</h2>
                <?php if ($imagePath): ?>
                <div>
                    <p class="alme-image-label">Aktuelles Profilbild:</p>
                    <img src="/<?php echo htmlspecialchars($imagePath); ?>" alt="Aktuelles Profilbild" class="alme-image-preview">
                </div>
                <?php endif; ?>
                <div class="alme-form-group">
                    <label class="alme-label">
                        <?php echo $imagePath ? 'Neues Bild hochladen (optional)' : 'Bild hochladen (optional)'; ?>
                    </label>
                    <input
                        type="file"
                        name="image"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        class="alme-file-input"
                    >
                    <p class="alme-help-text">
                        <i class="fas fa-info-circle" style="margin-right: 0.25rem;"></i>
                        Erlaubt: JPG, PNG, GIF, WebP. Maximum: 5MB. Wird sicher verarbeitet und validiert.
                    </p>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="alme-button-group">
                <?php if (!$isFirstTimeSetup): ?>
                <a href="index.php" class="alme-btn-secondary">
                    Abbrechen
                </a>
                <?php endif; ?>
                <button type="submit" class="alme-btn-primary">
                    <i class="fas fa-save"></i>Profil speichern
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($isFirstTimeSetup): ?>
<script>
// Prevent navigation away from page during first-time profile setup
(function() {
    // Disable back button functionality
    history.pushState(null, null, location.href);
    window.onpopstate = function() {
        history.go(1);
    };
    
    // Warn user if they try to leave the page
    const beforeUnloadHandler = function(e) {
        e.preventDefault();
        e.returnValue = '';
        return '';
    };
    window.addEventListener('beforeunload', beforeUnloadHandler);
    
    // Allow navigation when form is submitted
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
        });
    }
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
