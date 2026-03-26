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

<div class="max-w-4xl mx-auto">
    <?php if (!$isFirstTimeSetup): ?>
    <div class="mb-6">
        <a href="index.php" class="text-purple-600 hover:text-purple-700 inline-flex items-center mb-4">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zum Alumni Directory
        </a>
    </div>
    <?php endif; ?>
    
    <?php if ($isFirstTimeSetup): ?>
    <div class="mb-6 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800">
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle mt-0.5 mr-3 text-xl"></i>
            <div>
                <h3 class="font-bold mb-2">Profil vervollständigen erforderlich</h3>
                <p>Bitte geben Sie Ihren Vornamen und Nachnamen ein, um fortzufahren. Diese Informationen sind erforderlich.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['profile_incomplete_message'])): ?>
    <div class="mb-6 p-4 bg-blue-100 border border-blue-400 text-blue-700 rounded-lg">
        <i class="fas fa-info-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['profile_incomplete_message']); ?>
    </div>
    <?php unset($_SESSION['profile_incomplete_message']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <?php foreach ($errors as $error): ?>
            <div><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card p-8">
        <div class="mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-user-edit text-purple-600 mr-2"></i>
                <?php echo $profile ? 'Profil bearbeiten' : 'Profil erstellen'; ?>
            </h1>
            <p class="text-gray-600 mt-2">
                Vervollständigen Sie Ihr Alumni-Profil, damit andere Sie finden und kontaktieren können.
            </p>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            
            <!-- Personal Information -->
            <div class="border-b pb-6">
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">Persönliche Informationen</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vorname *</label>
                        <input 
                            type="text" 
                            name="first_name" 
                            required 
                            value="<?php echo htmlspecialchars($firstName); ?>"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nachname *</label>
                        <input 
                            type="text" 
                            name="last_name" 
                            required 
                            value="<?php echo htmlspecialchars($lastName); ?>"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">E-Mail *</label>
                        <input 
                            type="email" 
                            name="email" 
                            required 
                            value="<?php echo htmlspecialchars($email); ?>"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mobiltelefon</label>
                        <input 
                            type="text" 
                            name="mobile_phone" 
                            value="<?php echo htmlspecialchars($mobilePhone); ?>"
                            placeholder="+49 123 4567890"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="border-b pb-6">
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">Berufliche Informationen</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Firma *</label>
                        <input 
                            type="text" 
                            name="company" 
                            required 
                            value="<?php echo htmlspecialchars($company); ?>"
                            placeholder="z.B. ABC GmbH"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Position *</label>
                        <input 
                            type="text" 
                            name="position" 
                            required 
                            value="<?php echo htmlspecialchars($position); ?>"
                            placeholder="z.B. Senior Consultant"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Branche</label>
                        <input 
                            type="text" 
                            name="industry" 
                            value="<?php echo htmlspecialchars($industry); ?>"
                            placeholder="z.B. IT, Consulting, Finance"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Social Media Links -->
            <div class="border-b pb-6">
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">Social Media</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fab fa-linkedin text-blue-600 mr-1"></i>
                            LinkedIn URL
                        </label>
                        <input 
                            type="url" 
                            name="linkedin_url" 
                            value="<?php echo htmlspecialchars($linkedinUrl); ?>"
                            placeholder="https://www.linkedin.com/in/ihr-profil"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fab fa-xing text-green-700 mr-1"></i>
                            Xing URL
                        </label>
                        <input 
                            type="url" 
                            name="xing_url" 
                            value="<?php echo htmlspecialchars($xingUrl); ?>"
                            placeholder="https://www.xing.com/profile/ihr-profil"
                            class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Profile Picture -->
            <div class="pb-6">
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">Profilbild</h2>
                <?php if ($imagePath): ?>
                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-2">Aktuelles Profilbild:</p>
                    <img src="/<?php echo htmlspecialchars($imagePath); ?>" alt="Aktuelles Profilbild" class="w-32 h-32 rounded-full object-cover shadow-lg">
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <?php echo $imagePath ? 'Neues Bild hochladen (optional)' : 'Bild hochladen (optional)'; ?>
                    </label>
                    <input 
                        type="file" 
                        name="image" 
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    >
                    <p class="text-sm text-gray-500 mt-2">
                        Erlaubt: JPG, PNG, GIF, WebP. Maximum: 5MB. Wird sicher verarbeitet und validiert.
                    </p>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end space-x-4 pt-6 border-t">
                <?php if (!$isFirstTimeSetup): ?>
                <a href="index.php" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </a>
                <?php endif; ?>
                <button type="submit" class="px-6 py-2 bg-gradient-to-r from-purple-600 to-purple-700 text-white rounded-lg font-semibold hover:from-purple-700 hover:to-purple-800 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i>Profil speichern
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
