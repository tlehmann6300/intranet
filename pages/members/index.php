<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Access Control: Accessible by ALL active roles (admin, board, head, member, candidate)
// Use Auth::check() which is the standard authentication method in this codebase
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to access members page
// Allowed: board members, head, member, candidate
$hasMembersAccess = Auth::canAccessPage('members');
if (!$hasMembersAccess) {
    header('Location: ../dashboard/index.php');
    exit;
}

// Determine whether the current viewer may see hidden contact data
$viewerRole = $user['role'] ?? '';
$canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);

// Get search filters
$searchKeyword = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? '';

// Get members using Member model
$members = Member::getAllActive(
    !empty($searchKeyword) ? $searchKeyword : null,
    !empty($roleFilter) ? $roleFilter : null
);

$title = 'Mitgliederverzeichnis - IBC Intranet';
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

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-users mr-3 text-green-600 dark:text-green-400"></i>
                Mitgliederverzeichnis
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Entdecken und vernetzen Sie sich mit unseren aktiven Mitgliedern</p>
        </div>
        
        <!-- Edit My Profile Button - Only for Vorstand (all types), Resortleiter, Mitglied, Anwärter -->
        <?php if (Auth::isBoard() || Auth::hasRole(['ressortleiter', 'mitglied', 'anwaerter'])): ?>
        <a href="../auth/profile.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg font-semibold hover:from-green-700 hover:to-green-800 transition-all shadow-lg hover:shadow-xl">
            <i class="fas fa-user-edit mr-2"></i>
            Profil bearbeiten
        </a>
        <?php endif; ?>
    </div>

    <!-- Filter/Search Toolbar -->
    <div class="directory-toolbar mb-8">
        <form method="GET" action="">
            <div class="directory-toolbar-group">
                <label for="search"><i class="fas fa-search me-1" aria-hidden="true"></i>Suche</label>
                <div class="directory-search-wrapper">
                    <i class="fas fa-search directory-search-icon" aria-hidden="true"></i>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?php echo htmlspecialchars($searchKeyword); ?>"
                        placeholder="Name eingeben..."
                    >
                </div>
            </div>
            <div class="directory-toolbar-group">
                <label for="role"><i class="fas fa-filter me-1" aria-hidden="true"></i>Rolle</label>
                <select id="role" name="role" class="form-select rounded-pill">
                    <option value="">Alle</option>
                    <option value="anwaerter" <?php echo $roleFilter === 'anwaerter' ? 'selected' : ''; ?>>Anwärter</option>
                    <option value="mitglied" <?php echo $roleFilter === 'mitglied' ? 'selected' : ''; ?>>Mitglieder</option>
                    <option value="ressortleiter" <?php echo $roleFilter === 'ressortleiter' ? 'selected' : ''; ?>>Ressortleiter</option>
                    <option value="vorstand_finanzen" <?php echo $roleFilter === 'vorstand_finanzen' ? 'selected' : ''; ?>>Vorstand Finanzen und Recht</option>
                    <option value="vorstand_intern" <?php echo $roleFilter === 'vorstand_intern' ? 'selected' : ''; ?>>Vorstand Intern</option>
                    <option value="vorstand_extern" <?php echo $roleFilter === 'vorstand_extern' ? 'selected' : ''; ?>>Vorstand Extern</option>
                </select>
            </div>
            <div class="directory-toolbar-actions">
                <button type="submit" class="btn fw-semibold text-white" style="background:linear-gradient(135deg,var(--ibc-green-dark),var(--ibc-green));padding:0.6rem 1.25rem;">
                    <i class="fas fa-search me-2"></i>Suchen
                </button>
                <?php if (!empty($searchKeyword) || !empty($roleFilter)): ?>
                <a href="index.php" class="btn btn-outline-secondary" title="Alle Filter zurücksetzen">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div class="mb-6 directory-results-count">
        <p class="text-gray-600 dark:text-gray-300">
            <strong><?php echo count($members); ?></strong> 
            <?php echo count($members) === 1 ? 'Mitglied' : 'Mitglieder'; ?> gefunden
        </p>
    </div>

    <!-- Results Grid: Responsive (1 col mobile, 2 col 440px+, 3 cols desktop) -->
    <?php if (empty($members)): ?>
        <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
                 alt="Keine Mitglieder"
                 class="w-32 h-32 mx-auto mb-5 opacity-60">
            <?php if (!empty($searchKeyword) || !empty($roleFilter)): ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Mitglieder gefunden</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Bitte passe Deinen Suchfilter an.</p>
            <?php else: ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Noch keine Mitglieder vorhanden.</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Schau später wieder vorbei!</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
        // Pre-compute display data for each member once, shared by both mobile and desktop views
        $roleBadgeColors = [
            'vorstand_finanzen'   => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
            'vorstand_intern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
            'vorstand_extern'     => 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900 dark:text-purple-200 dark:border-purple-700',
            'ressortleiter'       => 'bg-teal-100 text-teal-800 border-teal-300 dark:bg-teal-900 dark:text-teal-200 dark:border-teal-700',
            'mitglied'            => 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900 dark:text-green-200 dark:border-green-700',
            'anwaerter'           => 'bg-yellow-100 text-yellow-800 border-yellow-300 dark:bg-yellow-900 dark:text-yellow-200 dark:border-yellow-700',
            'alumni'              => 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600',
            'alumni_vorstand'     => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-200 dark:border-indigo-700',
            'alumni_finanz'       => 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900 dark:text-indigo-200 dark:border-indigo-700',
            'ehrenmitglied'       => 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900 dark:text-amber-200 dark:border-amber-700',
        ];
        $memberDisplayData = [];
        foreach ($members as $idx => $member) {
            $displayRoleKey = Auth::getPrimaryEntraRoleKey($member['entra_roles'] ?? null, $member['role']);
            $badgeClass     = $roleBadgeColors[$displayRoleKey] ?? 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600';
            $displayRole    = htmlspecialchars($member['display_role'] ?? Auth::getRoleLabel($member['role']));
            $initials       = getMemberInitials($member['first_name'], $member['last_name']);
            $memberEmail    = $member['email'] ?? '';
            $imageSrc       = !empty($memberEmail)
                ? asset('fetch-profile-photo.php') . '?email=' . urlencode($memberEmail)
                : asset(getProfileImageUrl($member['avatar_path'] ?? null));
            $avatarColor    = getAvatarColor($member['first_name'] . ' ' . $member['last_name']);

            // Info snippet: Show position, or study_program + degree
            $infoSnippet = '';
            if (!empty($member['position'])) {
                $infoSnippet = $member['position'];
            } else {
                $studyParts  = [];
                $studyProgram = !empty($member['study_program']) ? $member['study_program'] :
                                (!empty($member['studiengang']) ? $member['studiengang'] : '');
                $degree       = !empty($member['degree']) ? $member['degree'] :
                                (!empty($member['angestrebter_abschluss']) ? $member['angestrebter_abschluss'] : '');
                if (!empty($studyProgram)) { $studyParts[] = $studyProgram; }
                if (!empty($degree))       { $studyParts[] = $degree; }
                if (!empty($studyParts))   { $infoSnippet = implode(' - ', $studyParts); }
            }

            // Validate social URLs to prevent XSS attacks
            $linkedinUrl     = $member['linkedin_url'] ?? '';
            $isValidLinkedIn = !empty($linkedinUrl) && (
                strpos($linkedinUrl, 'https://linkedin.com') === 0 ||
                strpos($linkedinUrl, 'https://www.linkedin.com') === 0 ||
                strpos($linkedinUrl, 'http://linkedin.com') === 0 ||
                strpos($linkedinUrl, 'http://www.linkedin.com') === 0
            );
            $xingUrl     = $member['xing_url'] ?? '';
            $isValidXing = !empty($xingUrl) && (
                strpos($xingUrl, 'https://xing.com') === 0 ||
                strpos($xingUrl, 'https://www.xing.com') === 0 ||
                strpos($xingUrl, 'http://xing.com') === 0 ||
                strpos($xingUrl, 'http://www.xing.com') === 0
            );

            $memberDisplayData[$idx] = compact(
                'displayRoleKey', 'badgeClass', 'displayRole', 'initials',
                'imageSrc', 'avatarColor', 'infoSnippet',
                'linkedinUrl', 'isValidLinkedIn', 'xingUrl', 'isValidXing'
            );
        }
        ?>

        <!-- Mobile Card View (visible on small screens only) -->
        <div class="md:hidden directory-grid-responsive grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php foreach ($members as $idx => $member):
                extract($memberDisplayData[$idx]);
            ?>
                <div class="card directory-card directory-card--members d-flex flex-column h-100">
                    <!-- Card Header: gradient band with avatar -->
                    <div class="directory-card-header">
                        <div class="position-absolute top-0 end-0 mt-2 me-2">
                            <span class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold directory-role-badge border <?php echo $badgeClass; ?>">
                                <i class="fas <?php echo getRoleIcon($displayRoleKey); ?>" aria-hidden="true"></i>
                                <?php echo $displayRole; ?>
                            </span>
                        </div>
                        <div class="directory-card-avatar-wrap">
                            <div class="directory-avatar directory-avatar--sm rounded-circle overflow-hidden border border-3 border-white shadow"
                                 style="background-color:<?php echo htmlspecialchars($avatarColor); ?>;position:relative;color:#fff;font-weight:700;">
                                <div style="position:absolute;inset:0;" class="d-flex align-items-center justify-content-center">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                                <img
                                    src="<?php echo htmlspecialchars($imageSrc); ?>"
                                    alt="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>"
                                    loading="lazy"
                                    style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;"
                                    onerror="this.onerror=null; this.style.display='none';"
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="directory-card-body">
                        <!-- Name (Bold) -->
                        <h3 class="fs-6 directory-card-name text-gray-800 dark:text-gray-100 text-center mb-2">
                            <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                        </h3>
                        
                        <!-- Info Snippet: 'Position' or 'Studium + Degree' -->
                        <?php if (!empty($infoSnippet)): ?>
                        <div class="text-center mb-3 flex-grow-1 d-flex align-items-center justify-content-center" style="min-height:3rem;">
                            <p class="small text-secondary mb-0">
                                <i class="fas fa-briefcase me-1 text-muted"></i>
                                <?php echo htmlspecialchars($infoSnippet); ?>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="flex-grow-1" style="min-height:3rem;"></div>
                        <?php endif; ?>
                        
                        <!-- Contact Icons: Round buttons for Mail, LinkedIn and Xing (if set) -->
                        <div class="d-flex justify-content-center gap-3 mb-3">
                            <!-- Mail Icon -->
                            <?php if (!empty($member['email']) && ($canViewPrivate || empty($member['privacy_hide_email']))): ?>
                                <a 
                                    href="mailto:<?php echo htmlspecialchars($member['email']); ?>" 
                                    class="directory-contact-icon"
                                    title="E-Mail senden"
                                >
                                    <i class="fas fa-envelope"></i>
                                </a>
                            <?php endif; ?>
                            
                            <!-- LinkedIn Icon (if set) -->
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
                            
                            <!-- Xing Icon (if set) -->
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
                        </div>
                        
                        <!-- Action: 'Profil ansehen' Button -->
                        <a 
                            href="view.php?id=<?php echo $member['profile_id']; ?>"
                            class="btn w-100 fw-semibold shadow-sm text-white"
                            style="background:linear-gradient(135deg,var(--ibc-green-dark),var(--ibc-green));"
                        >
                            <i class="fas fa-user me-2"></i>
                            Profil ansehen
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View (hidden on mobile, visible on md+ screens) -->
        <div class="overflow-x-auto w-full hidden md:block">
            <table class="w-full card-table">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Mitglied</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rolle</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Info</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Kontakt</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aktion</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($members as $idx => $member):
                        extract($memberDisplayData[$idx]);
                    ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors duration-150">
                            <td class="px-4 py-3 whitespace-nowrap" data-label="Mitglied">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full overflow-hidden flex-shrink-0"
                                         style="background-color:<?php echo htmlspecialchars($avatarColor); ?>;position:relative;color:#fff;font-weight:700;">
                                        <div style="position:absolute;inset:0;" class="d-flex align-items-center justify-content-center text-xs">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <img
                                            src="<?php echo htmlspecialchars($imageSrc); ?>"
                                            alt="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>"
                                            loading="lazy"
                                            style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;"
                                            onerror="this.onerror=null; this.style.display='none';"
                                        >
                                    </div>
                                    <span class="font-semibold text-sm text-gray-800 dark:text-gray-100">
                                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" data-label="Rolle">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold directory-role-badge border <?php echo $badgeClass; ?>">
                                    <i class="fas <?php echo getRoleIcon($displayRoleKey); ?>" aria-hidden="true"></i>
                                    <?php echo $displayRole; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs" data-label="Info">
                                <?php if (!empty($infoSnippet)): ?>
                                    <span class="truncate block"><?php echo htmlspecialchars($infoSnippet); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" data-label="Kontakt">
                                <div class="flex items-center gap-2">
                                    <?php if (!empty($member['email']) && ($canViewPrivate || empty($member['privacy_hide_email']))): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>"
                                           class="directory-contact-icon"
                                           title="E-Mail senden">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($isValidLinkedIn): ?>
                                        <a href="<?php echo htmlspecialchars($linkedinUrl); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="directory-contact-icon"
                                           title="LinkedIn Profil">
                                            <i class="fab fa-linkedin-in"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($isValidXing): ?>
                                        <a href="<?php echo htmlspecialchars($xingUrl); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="directory-contact-icon"
                                           title="Xing Profil">
                                            <i class="fab fa-xing"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" data-label="Aktion">
                                <a href="view.php?id=<?php echo $member['profile_id']; ?>"
                                   class="btn btn-sm fw-semibold shadow-sm text-white"
                                   style="background:linear-gradient(135deg,var(--ibc-green-dark),var(--ibc-green));">
                                    <i class="fas fa-user me-1"></i>
                                    Profil ansehen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
