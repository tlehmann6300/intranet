<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow all logged-in users with members page access
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

if (!Auth::canAccessPage('members')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$user = Auth::user();

// Get profile ID from URL
$profileId = $_GET['id'] ?? null;

if (!$profileId) {
    header('Location: index.php');
    exit;
}

// Get profile data (members and alumni share the same alumni_profiles table)
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
// Privileged roles: alumni, vorstand_intern, vorstand_extern, vorstand_finanzen
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

<div class="memv-container max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="memv-back mb-6">
        <a href="index.php" class="memv-back-link inline-flex items-center transition-colors font-medium">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zum Mitgliederverzeichnis
        </a>
    </div>

    <!-- Profile Header Card -->
    <div class="memv-header card mb-6">
        <div class="memv-header-content flex flex-col md:flex-row gap-6">
            <!-- Profile Image -->
            <div class="memv-avatar-container flex justify-center md:justify-start flex-shrink-0">
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
                <div class="memv-avatar w-32 h-32 rounded-full flex items-center justify-center text-white text-4xl font-bold overflow-hidden shadow-lg">
                    <?php if ($hasActualImage): ?>
                        <img
                            src="<?php echo htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            class="memv-avatar-img w-full h-full object-cover"
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
            <div class="memv-info flex-1 min-w-0">
                <h1 class="memv-name text-xl sm:text-2xl md:text-3xl font-bold mb-2 break-words hyphens-auto">
                    <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                </h1>

                <!-- Role Badge -->
                <div class="memv-role mb-3">
                    <span class="memv-role-badge inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold rounded-full border">
                        <i class="fas <?php echo getRoleIcon($displayRoleKey); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars($resolvedDisplayRole, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <!-- Position / Company snippet -->
                <?php if (!empty($profile['position']) || !empty($profileUser['job_title'])): ?>
                <p class="text-base text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-2">
                    <i class="fas fa-briefcase text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['position'] ?? $profileUser['job_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
                <?php endif; ?>
                <?php if (!empty($profile['company']) || !empty($profileUser['company'])): ?>
                <p class="text-sm text-gray-500 mb-2 flex items-center gap-2">
                    <i class="fas fa-building text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['company'] ?? $profileUser['company'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
                <?php endif; ?>

                <?php if (!empty($profile['study_program'])): ?>
                <p class="text-sm text-gray-500 flex items-center gap-2">
                    <i class="fas fa-graduation-cap text-gray-400 w-4"></i>
                    <span><?php echo htmlspecialchars($profile['study_program'], ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($profile['semester'])): ?> &middot; <?php echo htmlspecialchars($profile['semester'], ENT_QUOTES, 'UTF-8'); ?>. Semester<?php endif; ?></span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Completeness (only for alumni roles) -->
        <?php if ($isAlumniProfile): ?>
        <div class="memv-progress mt-6 p-4 rounded-xl">
            <div class="memv-progress-header flex items-center justify-between mb-1.5">
                <span class="memv-progress-label text-xs font-semibold">Profil-Fortschritt</span>
                <span class="memv-progress-percent text-xs font-bold"><?php echo $profileCompletenessPercent; ?>%</span>
            </div>
            <div class="memv-progress-bar w-full rounded-full h-2.5 overflow-hidden">
                <div class="memv-progress-fill h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $profileCompletenessPercent; ?>%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Über mich -->
    <?php if (!empty($profileUser['about_me'])): ?>
    <div class="memv-card card mb-6">
        <h2 class="memv-card-title text-lg font-bold mb-3 flex items-center gap-2">
            <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                <i class="fas fa-quote-left text-sm"></i>
            </span>
            Über mich
        </h2>
        <p class="memv-about text-leading-relaxed whitespace-pre-line break-words hyphens-auto"><?php echo htmlspecialchars($profileUser['about_me'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php endif; ?>

    <div class="memv-grid grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-6 mb-6">
        <!-- Kontaktinformationen -->
        <div class="memv-card card">
            <h2 class="memv-card-title text-lg font-bold mb-4 flex items-center gap-2">
                <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                    <i class="fas fa-address-card text-sm"></i>
                </span>
                Kontakt
            </h2>
            <div class="space-y-3">
                <!-- E-Mail -->
                <?php if (!empty($profile['email'])): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <div class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center text-green-600 flex-shrink-0">
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

        <!-- Studium -->
        <?php if (!empty($profile['study_program']) || !empty($profile['semester']) || !empty($profile['angestrebter_abschluss']) || !empty($profile['graduation_year']) || !empty($profile['degree'])): ?>
        <div class="memv-card card">
            <h2 class="memv-card-title text-lg font-bold mb-4 flex items-center gap-2">
                <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                    <i class="fas fa-graduation-cap text-sm"></i>
                </span>
                Studium
            </h2>
            <div class="space-y-3">
                <?php if (!empty($profile['study_program'])): ?>
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Studiengang</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['study_program'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!empty($profile['semester'])): ?>
                <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Bachelor-Semester</p>
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
                    <p class="text-xs text-gray-400 font-medium mb-0.5">Master-Semester</p>
                    <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['graduation_year'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Berufliches (shown if any professional info is set) -->
    <?php if (!empty($profile['company']) || !empty($profileUser['company']) || !empty($profile['position']) || !empty($profile['industry']) || !empty($profileUser['job_title'])): ?>
    <div class="memv-card card mb-6">
        <h2 class="memv-card-title text-lg font-bold mb-4 flex items-center gap-2">
            <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                <i class="fas fa-briefcase text-sm"></i>
            </span>
            Berufliches
        </h2>
        <?php if (!empty($profileUser['privacy_hide_career']) && !$canViewPrivate): ?>
        <p class="text-sm text-gray-400 italic">Karrieredaten sind privat.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php if (!empty($profile['company']) || !empty($profileUser['company'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Arbeitgeber</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['company'] ?? $profileUser['company'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($profile['position']) || !empty($profileUser['job_title'])): ?>
            <div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-700/50">
                <p class="text-xs text-gray-400 font-medium mb-0.5">Position</p>
                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($profile['position'] ?? $profileUser['job_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
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

    <!-- Fähigkeiten / Skills -->
    <?php
    $skillsList = !empty($profile['skills']) ? array_values(array_filter(array_map('trim', explode(',', $profile['skills'])))) : [];
    if (!empty($skillsList)):
    ?>
    <div class="memv-card card mb-6">
        <h2 class="memv-card-title text-lg font-bold mb-4 flex items-center gap-2">
            <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                <i class="fas fa-tags text-sm"></i>
            </span>
            Fähigkeiten
        </h2>
        <div class="memv-skills-list flex flex-wrap gap-2">
            <?php foreach ($skillsList as $skill): ?>
            <span class="memv-skill-badge inline-flex items-center px-3 py-1 rounded-full text-sm font-medium border">
                <?php echo htmlspecialchars($skill, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lebenslauf / CV -->
    <?php if (!empty($profile['cv_path'])): ?>
    <div class="memv-card card mb-6">
        <h2 class="memv-card-title text-lg font-bold mb-4 flex items-center gap-2">
            <span class="memv-card-icon inline-flex items-center justify-center w-8 h-8 rounded-full">
                <i class="fas fa-file-pdf text-sm"></i>
            </span>
            Lebenslauf
        </h2>
        <a href="<?php echo htmlspecialchars(asset($profile['cv_path']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"
           class="memv-cv-btn inline-flex items-center gap-2 px-4 py-2 rounded-lg border transition font-medium text-sm">
            <i class="fas fa-download"></i>
            Lebenslauf herunterladen
        </a>
    </div>
    <?php endif; ?>
</div>

<style>
/* MEMV (Member View) scoped styles */
.memv-container {
    animation: fadeIn 0.3s ease-out cubic-bezier(.22,.68,0,1.2);
}

.memv-back {
    margin-bottom: 1.5rem;
}

.memv-back-link {
    color: var(--ibc-blue);
}

.memv-back-link:hover {
    color: var(--ibc-blue);
    text-decoration: underline;
}

.memv-header {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-card);
    padding: 2rem;
}

.memv-header:hover {
    box-shadow: var(--shadow-card-hover);
}

.memv-header-content {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .memv-header-content {
        flex-direction: row;
    }
}

.memv-avatar {
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    flex-shrink: 0;
    width: 8rem;
    height: 8rem;
}

.memv-info {
    flex: 1;
    min-width: 0;
}

.memv-name {
    color: var(--text-main);
}

.memv-role-badge {
    background-color: rgba(59, 130, 246, 0.1);
    color: var(--ibc-blue);
    border: 1px solid rgba(59, 130, 246, 0.3);
}

.dark-mode .memv-role-badge {
    background-color: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.4);
}

.memv-progress {
    background-color: var(--bg-body);
    border-left: 4px solid var(--ibc-blue);
}

.memv-progress-label {
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.memv-progress-percent {
    color: var(--ibc-blue);
}

.memv-progress-bar {
    background-color: var(--border-color);
}

.memv-progress-fill {
    background: linear-gradient(90deg, var(--ibc-blue), var(--ibc-green));
}

.memv-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.75rem;
}

@media (min-width: 640px) {
    .memv-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 900px) {
    .memv-grid {
        gap: 1.5rem;
    }
}

.memv-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-card);
    padding: 1.5rem;
    transition: all 0.2s ease;
}

.memv-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.memv-card-title {
    color: var(--text-main);
}

.memv-card-icon {
    background-color: var(--bg-body);
    color: var(--ibc-blue);
}

.memv-about {
    color: var(--text-main);
}

.memv-skills-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.memv-skill-badge {
    background-color: rgba(20, 184, 166, 0.1);
    color: #0d9488;
    border: 1px solid rgba(20, 184, 166, 0.3);
}

.dark-mode .memv-skill-badge {
    background-color: rgba(20, 184, 166, 0.15);
    border-color: rgba(20, 184, 166, 0.4);
}

.memv-cv-btn {
    background-color: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
    text-decoration: none;
    transition: all 0.2s ease;
}

.memv-cv-btn:hover {
    background-color: rgba(239, 68, 68, 0.2);
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.dark-mode .memv-cv-btn {
    background-color: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
