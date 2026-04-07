<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Allow all logged-in users (Admin, Board, Head, Member, Candidate, Alumni)
// No role restrictions - any authenticated user can view the alumni directory
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Determine whether the current viewer may see hidden contact data
$viewerRole = $user['role'] ?? '';
$canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);

// Get search filters
$searchKeyword = $_GET['search'] ?? '';
$industryFilter = $_GET['industry'] ?? '';

// Build filters array
$filters = [];
if (!empty($searchKeyword)) {
    $filters['search'] = $searchKeyword;
}
if (!empty($industryFilter)) {
    $filters['industry'] = $industryFilter;
}

// Get alumni profiles
$profiles = Alumni::searchProfiles($filters);

// Get all industries for dropdown
$industries = Alumni::getAllIndustries();

$title = 'Alumni-Verzeichnis - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Success Message -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success_message']); ?>
    </div>
    <?php 
        unset($_SESSION['success_message']); 
    endif; 
    ?>

    <!-- Header with Edit Button -->
    <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-user-graduate mr-3 text-purple-600 dark:text-purple-400"></i>
                Alumni-Verzeichnis
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Entdecke und vernetze dich mit unserem Alumni-Netzwerk</p>
        </div>
        
        <!-- Edit My Profile Button - Only for Alumni, Alumni-Vorstand, Alumni-Finanzprüfer, and Ehrenmitglied -->
        <?php if (in_array($user['role'], ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'])): ?>
        <a href="../auth/profile.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-purple-600 to-purple-700 text-white rounded-lg font-semibold hover:from-purple-700 hover:to-purple-800 transition-all shadow-lg hover:shadow-xl">
            <i class="fas fa-user-edit mr-2"></i>
            Profil bearbeiten
        </a>
        <?php endif; ?>
    </div>

    <!-- Search/Filter Toolbar -->
    <div class="directory-toolbar mb-8">
        <form method="GET" action="">
            <div class="directory-toolbar-group">
                <label for="search"><i class="fas fa-search me-1" aria-hidden="true"></i>Suche</label>
                <div class="directory-search-wrapper">
                    <i class="fas fa-search directory-search-icon directory-search-icon--purple" aria-hidden="true"></i>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo htmlspecialchars($searchKeyword); ?>"
                        placeholder="Name, Position, Unternehmen..."
                    >
                </div>
            </div>
            <div class="directory-toolbar-group">
                <label for="industry"><i class="fas fa-industry me-1" aria-hidden="true"></i>Branche</label>
                <select id="industry" name="industry" class="form-select rounded-pill">
                    <option value="">Alle Branchen</option>
                    <?php foreach ($industries as $industry): ?>
                        <option value="<?php echo htmlspecialchars($industry); ?>" <?php echo $industryFilter === $industry ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($industry); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="directory-toolbar-actions">
                <button type="submit" class="btn fw-semibold text-white" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);padding:0.6rem 1.25rem;">
                    <i class="fas fa-search me-2"></i>Suchen
                </button>
                <?php if (!empty($searchKeyword) || !empty($industryFilter)): ?>
                <a href="index.php" class="btn btn-outline-secondary" title="Filter zurücksetzen">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div class="mb-6 directory-results-count">
        <p class="text-gray-600 dark:text-gray-300">
            <strong><?php echo count($profiles); ?></strong> 
            <?php echo count($profiles) === 1 ? 'Profil' : 'Profile'; ?> gefunden
        </p>
    </div>

    <!-- Alumni Profiles Grid -->
    <?php if (empty($profiles)): ?>
        <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
                 alt="Keine Profile"
                 class="w-32 h-32 mx-auto mb-5 opacity-60">
            <?php if (!empty($searchKeyword) || !empty($industryFilter)): ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Profile gefunden</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Bitte passe Deinen Suchfilter an.</p>
            <?php else: ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Noch keine Alumni-Profile vorhanden.</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Schau später wieder vorbei!</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="directory-grid-responsive grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            <?php foreach ($profiles as $profile): ?>
                <?php
                // Determine role badge color
                $roleBadgeColors = [
                    'alumni'          => 'bg-gray-100 text-gray-900 border-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:border-gray-600',
                    'alumni_vorstand' => 'bg-indigo-100 text-indigo-900 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-100 dark:border-indigo-700',
                    'alumni_finanz'   => 'bg-indigo-100 text-indigo-900 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-100 dark:border-indigo-700',
                    'ehrenmitglied'   => 'bg-amber-100 text-amber-900 border-amber-300 dark:bg-amber-900 dark:text-amber-100 dark:border-amber-700',
                ];
                $displayRoleKey = Auth::getPrimaryEntraRoleKey($profile['entra_roles'] ?? null, $profile['role'] ?? '');
                $badgeClass = $roleBadgeColors[$displayRoleKey] ?? 'bg-gray-100 text-gray-900 border-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:border-gray-600';
                $displayRole = htmlspecialchars(Auth::getRoleLabel($displayRoleKey));
                ?>
                <div class="card directory-card directory-card--alumni d-flex flex-column h-100">
                    <!-- Card Header: gradient band with avatar -->
                    <div class="directory-card-header">
                        <?php 
                        // Generate initials for fallback
                        $initials = getMemberInitials($profile['first_name'], $profile['last_name']);
                        $avatarColor = getAvatarColor($profile['first_name'] . ' ' . $profile['last_name']);
                        $alumniEmail = $profile['user_email'] ?? '';
                        $isEntraUser = !empty($profile['entra_roles']) || !empty($profile['entra_photo_path']);
                        if (!empty($profile['entra_photo_path'])) {
                            $imagePath = asset($profile['entra_photo_path']);
                        } elseif ($isEntraUser && !empty($alumniEmail)) {
                            $imagePath = asset('fetch-profile-photo.php') . '?email=' . urlencode($alumniEmail);
                        } else {
                            $imagePath = asset(getProfileImageUrl($profile['avatar_path'] ?? null));
                        }
                        ?>
                        <div class="directory-card-avatar-wrap">
                            <div class="directory-avatar rounded-circle overflow-hidden border border-3 border-white shadow"
                                 style="background-color:<?php echo htmlspecialchars($avatarColor); ?>;position:relative;color:#fff;font-weight:700;">
                                <div style="position:absolute;inset:0;" class="d-flex align-items-center justify-content-center">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                                <img
                                    src="<?php echo htmlspecialchars($imagePath); ?>"
                                    alt="<?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>"
                                    loading="lazy"
                                    style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;"
                                    onerror="this.onerror=null; this.style.display='none';"
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="directory-card-body">
                        <!-- Name -->
                        <h3 class="fs-6 directory-card-name text-gray-800 dark:text-gray-100 text-center mb-2">
                            <?php echo htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']); ?>
                        </h3>
                        
                        <!-- Position & Company -->
                        <div class="text-center mb-3 flex-grow-1">
                            <p class="small text-secondary mb-1 directory-card-text-truncate">
                                <?php echo htmlspecialchars($profile['position']); ?>
                            </p>
                            <p class="small text-muted mb-0 directory-card-text-truncate">
                                <?php echo htmlspecialchars($profile['company']); ?>
                            </p>
                            <?php if (!empty($profile['industry'])): ?>
                                <p class="text-muted mt-1 mb-0 directory-card-text-truncate" style="font-size:0.875rem;">
                                    <i class="fas fa-briefcase me-1"></i>
                                    <?php echo htmlspecialchars($profile['industry']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Social Icons & Contact -->
                        <div class="d-flex justify-content-center gap-3 mb-3">
                            <!-- Mail Icon -->
                            <?php if (!empty($profile['email']) && ($canViewPrivate || empty($profile['privacy_hide_email']))): ?>
                                <a 
                                    href="mailto:<?php echo htmlspecialchars($profile['email']); ?>"
                                    class="directory-contact-icon"
                                    title="E-Mail senden"
                                >
                                    <i class="fas fa-envelope"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($profile['linkedin_url'])): ?>
                                <?php
                                $linkedinUrl = $profile['linkedin_url'];
                                $isValidLinkedIn = (
                                    strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                                    strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                                    strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                                    strpos($linkedinUrl, 'http://www.linkedin.com') === 0
                                );
                                ?>
                                <?php if ($isValidLinkedIn): ?>
                                <a 
                                    href="<?php echo htmlspecialchars($linkedinUrl); ?>" 
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="directory-contact-icon"
                                    title="LinkedIn Profil"
                                >
                                    <i class="fab fa-linkedin-in"></i>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($profile['xing_url'])): ?>
                                <?php
                                $xingUrl = $profile['xing_url'];
                                $isValidXing = (
                                    strpos($xingUrl, 'https://xing.com') === 0 ||
                                    strpos($xingUrl, 'https://www.xing.com') === 0 ||
                                    strpos($xingUrl, 'http://xing.com') === 0 ||
                                    strpos($xingUrl, 'http://www.xing.com') === 0
                                );
                                ?>
                                <?php if ($isValidXing): ?>
                                <a 
                                    href="<?php echo htmlspecialchars($xingUrl); ?>" 
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="directory-contact-icon"
                                    title="Xing Profil"
                                >
                                    <i class="fab fa-xing"></i>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Action: 'Profil ansehen' Button -->
                        <a 
                            href="view.php?id=<?php echo $profile['id']; ?>"
                            class="btn w-100 fw-semibold shadow-sm text-white"
                            style="background:linear-gradient(135deg,#7c3aed,#6d28d9);"
                        >
                            <i class="fas fa-user me-2"></i>
                            Profil ansehen
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
