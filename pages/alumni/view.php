<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow all logged-in users
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Get profile ID from URL
$profileId = $_GET['id'] ?? null;

// Get return location (default to alumni index)
// Check GET parameter return_to first, then check referrer URL
$returnTo = 'alumni'; // Default value

// Check GET parameter return_to
if (isset($_GET['return_to'])) {
    // If return_to is explicitly set, use it (only 'members' is valid, anything else defaults to 'alumni')
    $returnTo = ($_GET['return_to'] === 'members') ? 'members' : 'alumni';
} 
// Check referrer URL if return_to parameter is not set
elseif (isset($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    $parsedUrl = parse_url($referer);
    // Check if parse_url succeeded and the path contains '/pages/members/' to ensure it's specifically the members page
    if ($parsedUrl !== false && isset($parsedUrl['path']) && 
        strpos($parsedUrl['path'], '/pages/members/') !== false) {
        $returnTo = 'members';
    }
}

if (!$profileId) {
    header('Location: index.php');
    exit;
}

// Get profile data
$profile = Alumni::getProfileById((int)$profileId);

if (!$profile) {
    $_SESSION['error_message'] = 'Profil nicht gefunden';
    header('Location: index.php');
    exit;
}

// Get the user's role from the users table
$profileUser = User::findById($profile['user_id']);
if (!$profileUser) {
    $_SESSION['error_message'] = 'Benutzer nicht gefunden';
    header('Location: index.php');
    exit;
}

// Get role information - prioritize Entra roles over internal role
$profileUserRole = $profileUser['role'];
$profileUserEntraRoles = $profileUser['entra_roles'] ?? null;

// Resolve display role: prefer Entra display names, fall back to internal role label
$displayRoleKey = Auth::getPrimaryEntraRoleKey($profileUserEntraRoles, $profileUserRole);
$resolvedDisplayRole = resolveDisplayRole($profileUserRole, $profileUserEntraRoles);

// Determine whether the current viewer can see private data
$viewerRole = $user['role'] ?? '';
$canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);

// Calculate profile completeness (only for alumni roles)
$profileCompletenessPercent = 0;
$isAlumniProfile = isAlumniRole($profileUserRole);
if ($isAlumniProfile) {
    $completenessFields = [
        'first_name'   => $profileUser['first_name'] ?? null,
        'last_name'    => $profileUser['last_name'] ?? null,
        'email'        => $profileUser['email'] ?? null,
        'mobile_phone' => $profile['mobile_phone'] ?? null,
        'gender'       => $profileUser['gender'] ?? null,
        'birthday'     => $profileUser['birthday'] ?? null,
        'skills'       => $profile['skills'] ?? null,
        'about_me'     => $profileUser['about_me'] ?? null,
    ];
    $filledCount = 0;
    foreach ($completenessFields as $value) {
        if (!empty($value)) {
            $filledCount++;
        }
    }
    $profileCompletenessPercent = (int)round(($filledCount / count($completenessFields)) * 100);
}

$title = htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name'], ENT_QUOTES, 'UTF-8') . ' - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6">
        <?php if ($returnTo === 'members'): ?>
            <a href="../members/index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Zurück zum Mitgliederverzeichnis
            </a>
        <?php else: ?>
            <a href="index.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 transition-colors font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Zurück zum Alumni-Verzeichnis
            </a>
        <?php endif; ?>
    </div>

    <!-- Profile Header Card -->
    <div class="card directory-profile-header mb-6">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Profile Image -->
            <div class="flex justify-center md:justify-start flex-shrink-0">
                <?php 
                $initials = strtoupper(substr($profile['first_name'], 0, 1) . substr($profile['last_name'], 0, 1));
                // Prefer Entra ID photo via fetch-profile-photo.php when an e-mail address is
                // available; fall back to the locally stored avatar / default image otherwise.
                $_profileEmail = $profileUser['email'] ?? '';
                if (!empty($_profileEmail)) {
                    $hasActualImage = true;
                    $imagePath = asset('fetch-profile-photo.php') . '?email=' . urlencode($_profileEmail);
                } else {
                    $_defaultImg = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';
                    $_pictureUrl = User::getProfilePictureUrl($profile['user_id']);
                    $hasActualImage = $_pictureUrl !== $_defaultImg;
                    $imagePath = $hasActualImage ? asset($_pictureUrl) : '';
                }
                ?>
                <div class="w-32 h-32 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white text-4xl font-bold overflow-hidden shadow-lg">
                    <?php if ($hasActualImage): ?>
                        <img 
                            src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>" 
                            alt="<?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="w-full h-full object-cover"
                            onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"
                        >
                        <div style="display:none;" class="w-full h-full flex items-center justify-center text-4xl">
                            <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php else: ?>
                        <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Info -->
            <div class="flex-1 min-w-0">
                <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                    <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                </h1>

                <!-- Role Badge -->
                <?php
                $roleBadgeColors = [
                    'vorstand_finanzen'   => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'vorstand_intern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'vorstand_extern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'ressortleiter'       => 'bg-teal-100 text-teal-800 border-teal-300 dark:bg-teal-900 dark:text-teal-200 dark:border-teal-700',
                    'mitglied'            => 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-200 dark:border-green-700',
                    'anwaerter'           => 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900 dark:text-yellow-200 dark:border-yellow-700',
                    'alumni'              => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
                    'alumni_vorstand'     => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-300 dark:border-indigo-500',
                    'alumni_finanz'       => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-300 dark:border-indigo-500',
                    'ehrenmitglied'       => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900 dark:text-amber-200 dark:border-amber-700',
                ];
                $badgeClass = $roleBadgeColors[$displayRoleKey] ?? 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600';
                ?>
                <div class="mb-3">
                    <span class="inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold rounded-full border <?php echo $badgeClass; ?>">
                        <i class="fas <?php echo getRoleIcon($displayRoleKey); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($resolvedDisplayRole, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <!-- Position / Company snippet -->
                <?php if (!empty($profile['position'])): ?>
                <p class="text-base text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-2">
                    <i class="fas fa-briefcase text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['position'], ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['company'])): ?>
                <p class="text-sm text-gray-500 mb-1 flex items-center gap-2">
                    <i class="fas fa-building text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['company'], ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['industry'])): ?>
                <p class="text-sm text-gray-500 flex items-center gap-2">
                    <i class="fas fa-industry text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['industry'], ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Completeness (only for alumni roles) -->
        <?php if ($isAlumniProfile): ?>
        <div class="mt-6 p-4 rounded-xl bg-gray-50 dark:bg-gray-700" style="border-left: 4px solid #a855f7">
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Profil-Fortschritt</span>
                <span class="text-xs font-bold" style="color: #a855f7"><?php echo $profileCompletenessPercent; ?>%</span>
            </div>
            <div class="w-full rounded-full h-2.5 overflow-hidden bg-gray-200 dark:bg-gray-600">
                <div class="h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $profileCompletenessPercent; ?>%; background: linear-gradient(90deg, #a855f7, #ec4899)"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Über mich -->
    <?php if (!empty($profileUser['about_me'])): ?>
    <div class="card directory-detail-card mb-6">
        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600">
                <i class="fas fa-quote-left text-sm"></i>
            </span>
            Über mich
        </h2>
        <p class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line break-words hyphens-auto"><?php echo htmlspecialchars($profileUser['about_me'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- Kontaktinformationen -->
        <div class="card directory-detail-card">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-address-card text-sm"></i>
                </span>
                Kontakt
            </h2>
            <div class="space-y-3">
                <!-- E-Mail -->
                <?php if (!empty($profile['email'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 flex-shrink-0">
                        <i class="fas fa-envelope text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">E-Mail</p>
                        <?php if (!empty($profileUser['privacy_hide_email']) && !$canViewPrivate): ?>
                        <p class="text-gray-400 italic text-sm">Privat</p>
                        <?php else: ?>
                        <a href="mailto:<?php echo htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm break-all block">
                            <?php echo htmlspecialchars($profile['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Zweite E-Mail -->
                <?php if (!empty($profile['secondary_email'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 flex-shrink-0">
                        <i class="fas fa-envelope text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">Zweite E-Mail</p>
                        <?php if (!empty($profileUser['privacy_hide_email']) && !$canViewPrivate): ?>
                        <p class="text-gray-400 italic text-sm">Privat</p>
                        <?php else: ?>
                        <a href="mailto:<?php echo htmlspecialchars($profile['secondary_email'], ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm break-all block">
                            <?php echo htmlspecialchars($profile['secondary_email'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Telefon -->
                <?php if (!empty($profile['mobile_phone'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center text-green-600 flex-shrink-0">
                        <i class="fas fa-phone text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 font-medium">Telefon</p>
                        <?php if (!empty($profileUser['privacy_hide_phone']) && !$canViewPrivate): ?>
                        <p class="text-gray-400 italic text-sm">Privat</p>
                        <?php else: ?>
                        <a href="tel:<?php echo htmlspecialchars($profile['mobile_phone'], ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            <?php echo htmlspecialchars($profile['mobile_phone'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Geschlecht -->
                <?php if (!empty($profileUser['gender'])): ?>
                <?php
                $genderLabels = ['m' => 'Männlich', 'f' => 'Weiblich', 'd' => 'Divers'];
                $genderLabel = $genderLabels[$profileUser['gender']] ?? htmlspecialchars($profileUser['gender'], ENT_QUOTES, 'UTF-8');
                ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 flex-shrink-0">
                        <i class="fas fa-venus-mars text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">Geschlecht</p>
                        <p class="font-medium text-gray-800 dark:text-gray-200 text-sm"><?php echo $genderLabel; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Geburtstag (only if show_birthday) -->
                <?php if (!empty($profileUser['birthday']) && !empty($profileUser['show_birthday'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-pink-100 flex items-center justify-center text-pink-600 flex-shrink-0">
                        <i class="fas fa-birthday-cake text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">Geburtstag</p>
                        <p class="font-medium text-gray-800 dark:text-gray-200 text-sm"><?php echo date('d.m.Y', strtotime($profileUser['birthday'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- LinkedIn -->
                <?php if (!empty($profile['linkedin_url'])):
                    $linkedinUrl = $profile['linkedin_url'];
                    $isValidLinkedIn = (
                        strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                        strpos($linkedinUrl, 'http://www.linkedin.com') === 0
                    );
                    if ($isValidLinkedIn): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-blue-700 flex items-center justify-center text-white flex-shrink-0">
                        <i class="fab fa-linkedin-in text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">LinkedIn</p>
                        <a href="<?php echo htmlspecialchars($linkedinUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            Profil ansehen <i class="fas fa-external-link-alt text-xs ml-1"></i>
                        </a>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Xing -->
                <?php if (!empty($profile['xing_url'])):
                    $xingUrl = $profile['xing_url'];
                    $isValidXing = (
                        strpos($xingUrl, 'https://xing.com') === 0 ||
                        strpos($xingUrl, 'https://www.xing.com') === 0 ||
                        strpos($xingUrl, 'http://xing.com') === 0 ||
                        strpos($xingUrl, 'http://www.xing.com') === 0
                    );
                    if ($isValidXing): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white flex-shrink-0">
                        <i class="fab fa-xing text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 font-medium">Xing</p>
                        <a href="<?php echo htmlspecialchars($xingUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                            Profil ansehen <i class="fas fa-external-link-alt text-xs ml-1"></i>
                        </a>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (empty($profile['email']) && empty($profile['mobile_phone']) && empty($profile['linkedin_url']) && empty($profile['secondary_email']) && empty($profile['xing_url']) && (empty($profileUser['birthday']) || empty($profileUser['show_birthday'])) && empty($profileUser['gender'])): ?>
                <p class="text-sm text-gray-400 italic">Keine Kontaktinformationen hinterlegt</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Berufliche Informationen -->
        <?php if (!empty($profile['company']) || !empty($profile['position']) || !empty($profile['industry'])): ?>
        <div class="card directory-detail-card">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-orange-100 text-orange-600">
                    <i class="fas fa-briefcase text-sm"></i>
                </span>
                Berufliches
            </h2>
            <?php if (!empty($profileUser['privacy_hide_career']) && !$canViewPrivate): ?>
            <p class="text-sm text-gray-400 italic">Karrieredaten sind privat.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php if (!empty($profile['company'])): ?>
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Arbeitgeber</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['company'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['position'])): ?>
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Position</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['position'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['industry'])): ?>
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Branche</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['industry'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Absolviertes Studium -->
    <?php if (!empty($profile['study_program']) || !empty($profile['semester']) || !empty($profile['angestrebter_abschluss']) || !empty($profile['graduation_year']) || !empty($profile['degree'])): ?>
    <div class="card directory-detail-card mb-6">
        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-600">
                <i class="fas fa-graduation-cap text-sm"></i>
            </span>
            Absolviertes Studium
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php if (!empty($profile['study_program'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Studiengang</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['study_program'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['semester'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Abschlussjahr</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['semester'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['degree'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Abschluss</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['degree'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['angestrebter_abschluss'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Master-Studiengang</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['angestrebter_abschluss'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['graduation_year'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Master-Abschlussjahr</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['graduation_year'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fähigkeiten / Skills -->
    <?php
    $skillsList = !empty($profile['skills']) ? array_values(array_filter(array_map('trim', explode(',', $profile['skills'])))) : [];
    if (!empty($skillsList)):
    ?>
    <div class="card directory-detail-card mb-6">
        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-teal-100 text-teal-600">
                <i class="fas fa-tags text-sm"></i>
            </span>
            Fähigkeiten
        </h2>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($skillsList as $skill): ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300 border border-teal-200 dark:border-teal-700">
                <?php echo htmlspecialchars($skill, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lebenslauf / CV -->
    <?php if (!empty($profile['cv_path'])): ?>
    <div class="card directory-detail-card mb-6">
        <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-600">
                <i class="fas fa-file-pdf text-sm"></i>
            </span>
            Lebenslauf
        </h2>
        <a href="<?php echo htmlspecialchars(asset($profile['cv_path']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-700 hover:bg-red-100 dark:hover:bg-red-900/40 transition font-medium text-sm">
            <i class="fas fa-download"></i>
            Lebenslauf herunterladen
        </a>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
