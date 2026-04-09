<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check() || !Auth::canManageUsers()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_role'])) {
        $userId = $_POST['user_id'] ?? 0;
        $newRole = $_POST['new_role'] ?? '';
        
        if ($userId == $_SESSION['user_id']) {
            $error = 'Du kannst Deine eigene Rolle nicht ändern';
        } else if (User::update($userId, ['role' => $newRole])) {
            $message = 'Rolle erfolgreich geändert';
        } else {
            $error = 'Fehler beim Ändern der Rolle';
        }
    } else if (isset($_POST['toggle_alumni_validation'])) {
        $userId = $_POST['user_id'] ?? 0;
        $isValidated = $_POST['is_validated'] ?? 0;
        
        if (User::update($userId, ['is_alumni_validated' => $isValidated])) {
            $message = $isValidated ? 'Alumni-Profil freigegeben' : 'Alumni-Profil gesperrt';
        } else {
            $error = 'Fehler beim Ändern des Alumni-Status';
        }
    } else if (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'] ?? 0;
        
        if ($userId == $_SESSION['user_id']) {
            $error = 'Du kannst Dich nicht selbst löschen';
        } else if (User::delete($userId)) {
            $message = 'Benutzer erfolgreich gelöscht';
        } else {
            $error = 'Fehler beim Löschen des Benutzers';
        }
    } else if (isset($_POST['reset_2fa'])) {
        // Only Ressortleiter and Vorstand roles may reset 2FA for other users
        $canReset2fa = in_array($_SESSION['user_role'] ?? '', ['ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']);
        if (!$canReset2fa) {
            $error = 'Keine Berechtigung zum Zurücksetzen von 2FA.';
        } else {
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $error = 'Ungültige Benutzer-ID.';
            } elseif ($userId === (int)$_SESSION['user_id']) {
                $error = 'Du kannst deine eigene 2FA nicht über diese Seite zurücksetzen.';
            } else {
                $db = Database::getUserDB();
                $stmt = $db->prepare("UPDATE users SET tfa_enabled = 0, tfa_secret = NULL, two_factor_secret = NULL WHERE id = ?");
                if ($stmt->execute([$userId])) {
                    $message = '2FA für den Benutzer wurde erfolgreich zurückgesetzt.';
                } else {
                    $error = 'Fehler beim Zurücksetzen der 2FA.';
                }
            }
        }
    } else if (isset($_POST['import_entra_user'])) {
        CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
        $entraId      = trim($_POST['entra_id'] ?? '');
        $displayName  = trim($_POST['display_name'] ?? '');
        $entraEmail   = trim($_POST['entra_email'] ?? '');
        $role         = $_POST['role'] ?? 'mitglied';
        $userType     = $_POST['user_type'] ?? 'member';

        // Normalize and validate user_type
        $userType = strtolower($userType) === 'guest' ? 'guest' : 'member';

        if (empty($entraId) || empty($entraEmail)) {
            $error = 'Entra-ID und E-Mail sind erforderlich.';
        } elseif (!filter_var($entraEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ungültige E-Mail-Adresse.';
        } elseif (!in_array($role, Auth::VALID_ROLES)) {
            $error = 'Ungültige Rolle.';
        } else {
            $db = Database::getUserDB();

            // Check for exact conflict: same azure_oid already linked to another account
            $stmt = $db->prepare("SELECT id, email, azure_oid FROM users WHERE azure_oid = ? LIMIT 1");
            $stmt->execute([$entraId]);
            $entraConflict = $stmt->fetch();

            if ($entraConflict) {
                $error = 'Diese Entra-ID ist bereits mit einem anderen Konto verknüpft.';
            } else {
                // Check if an account with this e-mail already exists but has no Entra-ID yet.
                // If so, link the Entra-ID to that existing account instead of creating a duplicate.
                $stmt = $db->prepare("SELECT id, azure_oid, role AS existing_role FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$entraEmail]);
                $existingByEmail = $stmt->fetch();

                if ($existingByEmail) {
                    if (!empty($existingByEmail['azure_oid'])) {
                        // E-mail is already linked to a different Entra-ID
                        $error = 'Ein Benutzer mit dieser E-Mail-Adresse ist bereits mit einer anderen Entra-ID verknüpft.';
                    } else {
                        // Account exists without an Entra-ID – link it now
                        $isAlumniValidated = ($role === 'alumni') ? 0 : 1;
                        $previousRole = $existingByEmail['existing_role'] ?? '';
                        $stmt = $db->prepare(
                            "UPDATE users SET azure_oid = ?, role = ?, user_type = ?, is_alumni_validated = ? WHERE id = ?"
                        );
                        if ($stmt->execute([$entraId, $role, $userType, $isAlumniValidated, $existingByEmail['id']])) {
                            $message = 'Bestehendes Konto für "' . htmlspecialchars($entraEmail) . '" erfolgreich mit Entra-ID verknüpft.';
                            if ($previousRole !== $role) {
                                $message .= ' Rolle geändert von "' . htmlspecialchars($previousRole) . '" zu "' . htmlspecialchars($role) . '".';
                            }
                        } else {
                            $error = 'Fehler beim Verknüpfen des bestehenden Kontos mit der Entra-ID.';
                        }
                    }
                } else {
                    // No existing account – create a new one
                    $nameParts = explode(' ', $displayName, 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName  = $nameParts[1] ?? '';

                    // Random password – login is Entra-only, password is never used
                    $randomPassword = bin2hex(random_bytes(32));
                    $passwordHash   = password_hash($randomPassword, HASH_ALGO);

                    $isAlumniValidated = ($role === 'alumni') ? 0 : 1;

                    $stmt = $db->prepare(
                        "INSERT INTO users (email, password, first_name, last_name, role, azure_oid, user_type, is_alumni_validated, profile_complete)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
                    );
                    if ($stmt->execute([$entraEmail, $passwordHash, $firstName, $lastName, $role, $entraId, $userType, $isAlumniValidated])) {
                        $message = 'Entra-Benutzer "' . htmlspecialchars($displayName) . '" erfolgreich hinzugefügt.';
                        if (!MailService::sendActivationEmail($entraEmail)) {
                            error_log("Activation email could not be sent to {$entraEmail}");
                            $message .= ' (Hinweis: Aktivierungs-E-Mail konnte nicht gesendet werden.)';
                        }
                    } else {
                        $error = 'Fehler beim Speichern des Benutzers.';
                    }
                }
            }
        }
    }
}

$users = User::getAll();

// Get current user data
$currentUser = Auth::user();
$currentUserRole = $currentUser['role'] ?? '';

$title = 'Benutzerverwaltung - IBC Intranet';
ob_start();
?>
<input type="hidden" id="csrf-token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">

<!-- Header Section with Gradient Background -->
<div class="mb-8 relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-purple-600 via-indigo-600 to-blue-600 opacity-90"></div>
    <div class="absolute inset-0 bg-pattern opacity-10"></div>
    <div class="relative px-6 py-8 md:px-8 md:py-10">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="flex items-center mb-3">
                    <div class="w-14 h-14 bg-white bg-opacity-20 rounded-xl flex items-center justify-center backdrop-blur-sm mr-4 shrink-0">
                        <i class="fas fa-users text-white text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-white mb-1">
                            Benutzerverwaltung
                        </h1>
                        <p class="text-white opacity-90"><?php echo count($users); ?> Benutzer im System</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modern Tab Navigation -->
<div class="mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
        <nav class="flex" aria-label="Tabs">
            <button 
                class="tab-button active flex-1 py-3 sm:py-4 px-3 sm:px-6 text-center font-semibold transition-all duration-200 relative overflow-hidden bg-gradient-to-r from-purple-600 to-indigo-600 text-white"
                data-tab="users"
            >
                <span class="relative z-10 flex items-center justify-center">
                    <i class="fas fa-users mr-2"></i>
                    <span>Benutzerliste</span>
                </span>
            </button>
            <?php if (Auth::canManageUsers()): ?>
            <button 
                class="tab-button flex-1 py-3 sm:py-4 px-3 sm:px-6 text-center font-semibold transition-all duration-200 relative overflow-hidden bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600"
                data-tab="entra-search"
            >
                <span class="relative z-10 flex items-center justify-center">
                    <i class="fab fa-microsoft mr-2"></i>
                    <span>Entra-Benutzer</span>
                </span>
            </button>
            <?php endif; ?>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-5 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/30 dark:to-emerald-900/30 border-l-4 border-green-500 dark:border-green-400 rounded-xl shadow-lg">
    <div class="flex items-center">
        <div class="flex-shrink-0 w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-check-circle text-white text-lg"></i>
        </div>
        <p class="ml-4 text-green-800 dark:text-green-200 font-medium"><?php echo htmlspecialchars($message); ?></p>
    </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-5 bg-gradient-to-r from-red-50 to-pink-50 dark:from-red-900/30 dark:to-pink-900/30 border-l-4 border-red-500 dark:border-red-400 rounded-xl shadow-lg">
    <div class="flex items-center">
        <div class="flex-shrink-0 w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center">
            <i class="fas fa-exclamation-circle text-white text-lg"></i>
        </div>
        <p class="ml-4 text-red-800 dark:text-red-200 font-medium"><?php echo htmlspecialchars($error); ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Tab Content: Users -->
<div id="tab-users" class="tab-content">
    <!-- Info Banner with Modern Design -->
    <div class="mb-6 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 dark:from-blue-900/20 dark:via-indigo-900/20 dark:to-purple-900/20 rounded-2xl overflow-hidden shadow-lg border border-blue-100 dark:border-blue-800">
        <div class="p-6">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center">
                        <i class="fas fa-info-circle text-white text-xl"></i>
                    </div>
                </div>
                <div class="ml-5 flex-1">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">Microsoft Only Authentifizierung</h3>
                    <p class="text-gray-700 dark:text-gray-300">
                        Benutzer werden ausschließlich über Microsoft Entra ID verwaltet. Neue Benutzer können über den Entra-Benutzer-Tab hinzugefügt werden.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Users List Card with Modern Design -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-100 dark:border-gray-700">
        <!-- Enhanced Search and Filter Bar -->
        <div class="p-6 bg-gradient-to-r from-gray-50 via-slate-50 to-gray-50 dark:from-gray-800 dark:via-gray-750 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col md:flex-row gap-4 mb-6">
                <div class="flex-1">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                        Suche
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            id="userSearch" 
                            placeholder="Nach E-Mail oder ID suchen..." 
                            class="w-full pl-11 pr-4 py-3 bg-white border-2 border-gray-200 text-gray-900 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white transition-all"
                        >
                    </div>
                </div>
                <div class="md:w-56">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                        Filter nach Rolle
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-filter text-gray-400"></i>
                        </div>
                        <select 
                            id="roleFilter" 
                            class="w-full pl-11 pr-4 py-3 bg-white border-2 border-gray-200 text-gray-900 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white appearance-none cursor-pointer transition-all"
                        >
                            <option value="">Alle Rollen</option>
                            <?php foreach (Auth::VALID_ROLES as $role): ?>
                            <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(translateRole($role)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                        </div>
                    </div>
                </div>
                <div class="md:w-56">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                        Sortierung
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-sort text-gray-400"></i>
                        </div>
                        <select 
                            id="sortBy" 
                            class="w-full pl-11 pr-4 py-3 bg-white border-2 border-gray-200 text-gray-900 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white appearance-none cursor-pointer transition-all"
                        >
                            <option value="email">E-Mail (A-Z)</option>
                            <option value="email-desc">E-Mail (Z-A)</option>
                            <option value="id">ID (aufsteigend)</option>
                            <option value="id-desc">ID (absteigend)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-sm"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                <div class="flex items-center space-x-2">
                    <div class="px-4 py-2 bg-white dark:bg-gray-700 rounded-lg shadow-sm border border-gray-200 dark:border-gray-600">
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            <span id="visibleCount"><?php echo count($users); ?></span> von 
                            <span id="totalCount"><?php echo count($users); ?></span> Benutzern
                        </span>
                    </div>
                </div>
                <button 
                    id="exportUsers" 
                    class="w-full sm:w-auto min-h-[44px] px-5 py-2.5 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl hover:from-green-700 hover:to-emerald-700 transition-all duration-200 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5"
                >
                    <i class="fas fa-download mr-2"></i>Export CSV
                </button>
            </div>
        </div>
        
        <div class="overflow-x-auto w-full has-action-dropdown">
            <table class="w-full card-table" id="usersTable">
                <thead class="bg-gradient-to-r from-gray-100 to-slate-100 dark:from-gray-700 dark:to-gray-600 border-b-2 border-purple-200 dark:border-purple-900">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap">Profil</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap">Benutzer</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap hidden lg:table-cell">Entra-Status</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap">Intranet-Rolle</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-bold text-gray-700 dark:text-gray-200 uppercase tracking-wider whitespace-nowrap">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                    <?php
                    // Build reverse lookup once: UUID => role key (e.g. '3ad43a76-...' => 'vorstand_finanzen')
                    $roleIdToKey = array_flip(ROLE_MAPPING);
                    foreach ($users as $user): ?>
                <tr class="user-row hover:bg-gradient-to-r hover:from-purple-50 hover:to-indigo-50 dark:hover:from-purple-900/20 dark:hover:to-indigo-900/20 transition-all duration-200" 
                    data-email="<?php echo htmlspecialchars(strtolower($user['email'])); ?>"
                    data-role="<?php echo htmlspecialchars($user['role']); ?>"
                    data-id="<?php echo $user['id']; ?>">
                    <td class="px-6 py-4 whitespace-nowrap" data-label="Profil">
                        <?php
                        $defaultImg = defined('DEFAULT_PROFILE_IMAGE') ? DEFAULT_PROFILE_IMAGE : 'assets/img/default_profil.png';
                        $resolvedImg = getProfileImageUrl($user['avatar_path'] ?? null);
                        $avatarImageUrl = ($resolvedImg !== $defaultImg) ? asset($resolvedImg) : null;
                        $avatarInitials = getMemberInitials($user['first_name'] ?? '', $user['last_name'] ?? '');
                        if ($avatarInitials === '?') {
                            $avatarInitials = strtoupper(mb_substr($user['email'] ?? '', 0, 2, 'UTF-8')) ?: '?';
                        }
                        ?>
                        <div class="h-10 w-10 rounded-full overflow-hidden bg-gradient-to-br from-purple-400 to-indigo-500 dark:from-purple-700 dark:to-indigo-800 flex items-center justify-center shadow-md shrink-0">
                            <?php if ($avatarImageUrl): ?>
                            <img src="<?php echo htmlspecialchars($avatarImageUrl); ?>"
                                 alt="<?php echo htmlspecialchars($avatarInitials); ?>"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div style="display:none;" class="w-full h-full flex items-center justify-center text-white text-xs font-bold">
                                <?php echo htmlspecialchars($avatarInitials ?: '?'); ?>
                            </div>
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-white text-xs font-bold">
                                <?php echo htmlspecialchars($avatarInitials ?: '?'); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap" data-label="Benutzer">
                        <div class="flex items-center min-w-0">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center flex-wrap gap-1 break-all">
                                    <?php echo htmlspecialchars($user['email']); ?>
                                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                    <span class="ml-1 px-2.5 py-0.5 text-xs bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-full font-bold shadow-sm">Du</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-1">ID: <?php echo $user['id']; ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap hidden lg:table-cell" data-label="Entra-Status">
                        <?php $userType = strtolower($user['user_type'] ?? 'member'); ?>
                        <?php if ($userType === 'guest'): ?>
                        <span class="inline-flex items-center px-2.5 py-1 text-xs bg-gradient-to-r from-orange-100 to-amber-100 dark:from-orange-900/50 dark:to-amber-900/50 text-orange-700 dark:text-orange-300 rounded-lg font-semibold shadow-sm">
                            <i class="fas fa-user-friends mr-1.5"></i>Gast
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-1 text-xs bg-gradient-to-r from-blue-100 to-indigo-100 dark:from-blue-900/50 dark:to-indigo-900/50 text-blue-700 dark:text-blue-300 rounded-lg font-semibold shadow-sm">
                            <i class="fas fa-user mr-1.5"></i>Mitglied
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap" data-label="Intranet-Rolle">
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <?php if (!empty($user['azure_oid'])): ?>
                        <div class="flex flex-col gap-1">
                            <span class="inline-flex items-center px-3 py-1.5 text-sm bg-gradient-to-r from-purple-100 to-indigo-100 dark:from-purple-900/50 dark:to-indigo-900/50 text-purple-800 dark:text-purple-200 rounded-lg font-semibold shadow-sm">
                                <i class="fab fa-microsoft mr-2 text-xs"></i>
                                <?php echo htmlspecialchars(translateRole($user['role'])); ?>
                            </span>
                            <span class="text-xs text-gray-400 dark:text-gray-500">
                                <i class="fas fa-lock mr-1"></i>Verwaltet via Microsoft Entra
                            </span>
                        </div>
                        <?php else: ?>
                        <select class="role-select text-sm border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-purple-400 focus:outline-none transition-all"
                            data-user-id="<?php echo $user['id']; ?>">
                            <?php foreach (Auth::VALID_ROLES as $r): ?>
                            <option value="<?php echo htmlspecialchars($r); ?>"<?php echo $r === $user['role'] ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars(translateRole($r)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1.5 text-sm bg-gradient-to-r from-purple-100 to-indigo-100 dark:from-purple-900/50 dark:to-indigo-900/50 text-purple-800 dark:text-purple-200 rounded-lg font-semibold shadow-sm">
                            <i class="fas fa-user-tag mr-2 text-xs"></i>
                            <?php echo htmlspecialchars(translateRole($user['role'])); ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap" data-label="Status">
                        <div class="flex flex-col space-y-2">
                            <?php
                            $isLocked = !empty($user['is_locked_permanently'])
                                || (!empty($user['locked_until']) && ($lockedTs = strtotime($user['locked_until'])) !== false && $lockedTs > time());
                            ?>
                            <?php if ($isLocked): ?>
                            <span class="inline-flex items-center px-2.5 py-1 text-xs bg-red-600 text-white rounded-lg font-semibold shadow-sm">
                                <i class="fas fa-ban mr-1.5"></i>Inaktiv
                            </span>
                            <?php elseif (!empty($user['azure_oid'])): ?>
                            <span class="inline-flex items-center px-2.5 py-1 text-xs bg-green-600 text-white rounded-lg font-semibold shadow-sm">
                                <i class="fas fa-circle mr-1.5"></i>Aktiv
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-1 text-xs bg-gray-500 text-white rounded-lg font-semibold shadow-sm">
                                <i class="fas fa-envelope mr-1.5"></i>Eingeladen
                            </span>
                            <?php endif; ?>
                            <?php if ($user['tfa_enabled']): ?>
                            <span class="inline-flex items-center px-2.5 py-1 text-xs bg-blue-600 text-white rounded-lg font-semibold shadow-sm">
                                <i class="fas fa-shield-alt mr-1.5"></i>2FA Aktiv
                            </span>
                            <?php endif; ?>
                            <?php if ($user['role'] == 'alumni'): ?>
                                <?php if ($user['is_alumni_validated']): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="is_validated" value="0">
                                    <button type="submit" name="toggle_alumni_validation" class="inline-flex items-center px-2.5 py-2 min-h-[44px] text-xs bg-gradient-to-r from-green-100 to-emerald-100 dark:from-green-900/50 dark:to-emerald-900/50 text-green-700 dark:text-green-300 rounded-lg font-semibold shadow-sm hover:shadow-md transition-all">
                                        <i class="fas fa-check-circle mr-1.5"></i>Verifiziert
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <input type="hidden" name="is_validated" value="1">
                                    <button type="submit" name="toggle_alumni_validation" class="inline-flex items-center px-2.5 py-2 min-h-[44px] text-xs bg-gradient-to-r from-yellow-100 to-amber-100 dark:from-yellow-900/50 dark:to-amber-900/50 text-yellow-700 dark:text-yellow-300 rounded-lg font-semibold shadow-sm hover:shadow-md transition-all">
                                        <i class="fas fa-clock mr-1.5"></i>Ausstehend
                                    </button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center" data-label="Aktionen">
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <div class="flex flex-col items-center gap-2">
                        <form method="POST" class="inline" onsubmit="return confirm('Bist Du sicher, dass Du diesen Benutzer löschen möchtest? Das Profil in alumni_profiles wird ebenfalls entfernt.');">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="delete_user" class="inline-flex items-center px-3 py-2 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-200 dark:hover:bg-red-900/50 transition-all shadow-sm hover:shadow-md transform hover:scale-105 text-sm font-medium">
                                <i class="fas fa-trash mr-1.5"></i>Benutzer löschen
                            </button>
                        </form>
                        <?php if ($user['tfa_enabled'] && in_array($_SESSION['user_role'] ?? '', ['ressortleiter', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'])): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('2FA für diesen Benutzer wirklich zurücksetzen?');">
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="reset_2fa" class="inline-flex items-center px-3 py-2 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 rounded-lg hover:bg-yellow-200 dark:hover:bg-yellow-900/50 transition-all shadow-sm hover:shadow-md transform hover:scale-105 text-sm font-medium">
                                <i class="fas fa-shield-alt mr-1.5"></i>2FA zurücksetzen
                            </button>
                        </form>
                        <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="inline-flex items-center justify-center w-10 h-10 bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 rounded-lg">
                            <i class="fas fa-lock"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>
<!-- End Tab Content: Users -->

<!-- Tab Content: Entra-Benutzer suchen -->
<?php if (Auth::canManageUsers()): ?>
<div id="tab-entra-search" class="tab-content hidden">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden border border-gray-100 dark:border-gray-700">
        <div class="p-6 bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 dark:from-blue-900/20 dark:via-indigo-900/20 dark:to-purple-900/20 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-1 flex items-center">
                <i class="fab fa-microsoft mr-2 text-blue-600"></i>
                Benutzer aus Microsoft Entra hinzufügen
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Suche nach Benutzern im Azure-Tenant (per Name oder E-Mail) und füge sie dem Intranet hinzu.
                Der Login erfolgt ausschließlich über Microsoft Entra – es wird kein Passwort benötigt.
            </p>
        </div>
        <div class="p-6">
            <!-- Search field -->
            <div class="flex flex-col sm:flex-row gap-3 mb-6">
                <div class="flex-1 relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input
                        type="text"
                        id="entraSearchInput"
                        placeholder="Name oder E-Mail eingeben (mind. 2 Zeichen)..."
                        class="w-full pl-11 pr-4 py-3 bg-white border-2 border-gray-200 text-gray-900 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white transition-all"
                    >
                </div>
                <button
                    id="entraSearchBtn"
                    type="button"
                    class="w-full sm:w-auto min-h-[44px] px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl hover:from-blue-700 hover:to-indigo-700 font-semibold shadow-md transition-all duration-200"
                >
                    <i class="fas fa-search mr-2"></i>Suchen
                </button>
            </div>

            <!-- Search status / spinner -->
            <div id="entraSearchStatus" class="hidden mb-4 text-sm text-gray-600 dark:text-gray-400 flex items-center">
                <i class="fas fa-spinner fa-spin mr-2"></i>Suche läuft...
            </div>

            <!-- Results -->
            <div id="entraSearchResults" class="hidden">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 mb-3 uppercase tracking-wider">Suchergebnisse</h4>
                <div id="entraResultsList" class="space-y-2"></div>
            </div>

            <!-- Import form (hidden, filled by JS) -->
            <form id="entraImportForm" method="POST" class="hidden mt-6 p-5 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border border-green-200 dark:border-green-800 rounded-xl">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="entra_id" id="importEntraId">
                <input type="hidden" name="display_name" id="importDisplayName">
                <input type="hidden" name="entra_email" id="importEntraEmail">
                <input type="hidden" name="user_type" id="importUserType">
                <h4 class="text-sm font-bold text-gray-700 dark:text-gray-300 mb-3">
                    <i class="fas fa-user-plus mr-2 text-green-600"></i>Benutzer hinzufügen
                </h4>
                <div class="mb-4 p-4 bg-white dark:bg-gray-700 rounded-lg border border-green-200 dark:border-green-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-lg flex items-center justify-center">
                            <i class="fab fa-microsoft text-white"></i>
                        </div>
                        <div>
                            <div id="importPreviewName" class="font-semibold text-gray-900 dark:text-gray-100"></div>
                            <div id="importPreviewEmail" class="text-sm text-gray-500 dark:text-gray-400"></div>
                            <div id="importPreviewId" class="text-xs text-gray-400 dark:text-gray-500 font-mono"></div>
                            <div id="importPreviewUserType" class="text-xs mt-1"></div>
                        </div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rolle im Intranet</label>
                    <div class="relative">
                        <select name="role" class="w-full pl-4 pr-4 py-3 bg-white border-2 border-gray-200 text-gray-900 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <?php foreach (Auth::VALID_ROLES as $role): ?>
                            <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(translateRole($role)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row gap-3">
                    <button type="submit" name="import_entra_user" class="w-full sm:w-auto min-h-[44px] px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl hover:from-green-700 hover:to-emerald-700 font-semibold shadow-md transition-all duration-200">
                        <i class="fas fa-user-plus mr-2"></i>Hinzufügen
                    </button>
                    <button type="button" id="cancelImport" class="w-full sm:w-auto min-h-[44px] px-6 py-3 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-xl hover:bg-gray-300 dark:hover:bg-gray-500 font-semibold transition-all duration-200">
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- End Tab Content: Entra-Benutzer suchen -->


<script>
// Tab switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');
            
            // Update button styles - Modern gradient design
            tabButtons.forEach(btn => {
                btn.classList.remove('active', 'bg-gradient-to-r', 'from-purple-600', 'to-indigo-600', 'text-white');
                btn.classList.add('bg-gray-50', 'dark:bg-gray-700', 'text-gray-600', 'dark:text-gray-300', 'hover:bg-gray-100', 'dark:hover:bg-gray-600');
            });
            this.classList.remove('bg-gray-50', 'dark:bg-gray-700', 'text-gray-600', 'dark:text-gray-300', 'hover:bg-gray-100', 'dark:hover:bg-gray-600');
            this.classList.add('active', 'bg-gradient-to-r', 'from-purple-600', 'to-indigo-600', 'text-white');
            
            // Update content visibility
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById('tab-' + targetTab).classList.remove('hidden');
        });
    });
});
</script>

<script>
// Search, Filter, and Sort functionality
document.addEventListener('DOMContentLoaded', function() {
    const userSearch = document.getElementById('userSearch');
    const roleFilter = document.getElementById('roleFilter');
    const sortBy = document.getElementById('sortBy');
    const exportBtn = document.getElementById('exportUsers');
    const userRows = document.querySelectorAll('.user-row');
    const visibleCount = document.getElementById('visibleCount');
    const totalCount = document.getElementById('totalCount');
    
    function filterAndSortUsers() {
        const searchTerm = userSearch.value.toLowerCase();
        const selectedRole = roleFilter.value;
        const sortOption = sortBy.value;
        
        // Convert NodeList to Array for sorting
        let rowsArray = Array.from(userRows);
        
        // Apply search and role filter
        let visibleRows = rowsArray.filter(row => {
            const email = row.getAttribute('data-email');
            const id = row.getAttribute('data-id');
            const role = row.getAttribute('data-role');
            
            const matchesSearch = email.includes(searchTerm) || id.toString().includes(searchTerm);
            const matchesRole = !selectedRole || role === selectedRole;
            
            return matchesSearch && matchesRole;
        });
        
        // Apply sorting
        visibleRows.sort((a, b) => {
            switch(sortOption) {
                case 'email':
                    return a.getAttribute('data-email').localeCompare(b.getAttribute('data-email'));
                case 'email-desc':
                    return b.getAttribute('data-email').localeCompare(a.getAttribute('data-email'));
                case 'id':
                    return parseInt(a.getAttribute('data-id')) - parseInt(b.getAttribute('data-id'));
                case 'id-desc':
                    return parseInt(b.getAttribute('data-id')) - parseInt(a.getAttribute('data-id'));
                default:
                    return 0;
            }
        });
        
        // Hide all rows
        userRows.forEach(row => {
            row.style.display = 'none';
        });
        
        // Show and reorder visible rows
        const tbody = document.querySelector('#usersTable tbody');
        visibleRows.forEach(row => {
            row.style.display = '';
            tbody.appendChild(row); // Reorder by appending
        });
        
        // Update counter
        visibleCount.textContent = visibleRows.length;
    }
    
    // Event listeners
    userSearch.addEventListener('input', filterAndSortUsers);
    roleFilter.addEventListener('change', filterAndSortUsers);
    sortBy.addEventListener('change', filterAndSortUsers);
    
    // Export to CSV functionality
    function sanitizeCsvValue(val) {
        var s = String(val).replace(/"/g, '""'); // escape double quotes for CSV
        if (/^[=+\-@]/.test(s)) {
            s = "'" + s;
        }
        return s;
    }

    exportBtn.addEventListener('click', function() {
        const visibleRows = Array.from(userRows).filter(row => row.style.display !== 'none');
        
        let csv = 'ID,E-Mail,Rolle,2FA Aktiviert,Alumni Verifiziert\n';
        
        visibleRows.forEach(row => {
            const id = row.getAttribute('data-id');
            const email = row.getAttribute('data-email');
            const role = row.getAttribute('data-role');
            
            // Get additional info from row cells
            const cells = row.querySelectorAll('td');
            const tfaBadge = cells[4].querySelector('.fa-shield-alt');
            const tfa = tfaBadge ? 'Ja' : 'Nein';
            
            const verifBadge = cells[4].querySelector('.fa-check-circle');
            const verif = verifBadge ? 'Ja' : (cells[4].querySelector('.fa-clock') ? 'Nein' : 'N/A');
            
            csv += `${sanitizeCsvValue(id)},"${sanitizeCsvValue(email)}","${sanitizeCsvValue(role)}","${sanitizeCsvValue(tfa)}","${sanitizeCsvValue(verif)}"\n`;
        });
        
        // Create download link
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        const dateStr = new Date().toLocaleDateString('de-DE').replace(/\./g, '-');
        
        link.setAttribute('href', url);
        link.setAttribute('download', 'benutzer_export_' + dateStr + '.csv');
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
});
</script>

<script>
// Role change via AJAX
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.getElementById('csrf-token') ? document.getElementById('csrf-token').value : '';

    document.querySelectorAll('.role-select').forEach(function(select) {
        // Store the current value before any change so we can revert on error
        select.dataset.originalValue = select.value;

        select.addEventListener('change', function() {
            const userId        = this.getAttribute('data-user-id');
            const newRole       = this.value;
            const originalValue = this.dataset.originalValue;

            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('new_role', newRole);
            formData.append('csrf_token', csrfToken);

            fetch('/api/update_user_role.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(function(data) {
                if (data.success) {
                    // Update data-role attribute on the row for filtering
                    const row = select.closest('tr');
                    if (row) {
                        row.setAttribute('data-role', newRole);
                    }
                    // Track the new original value after a successful save
                    select.dataset.originalValue = newRole;
                    // Brief visual feedback
                    select.classList.add('ring-2', 'ring-green-400');
                    setTimeout(function() { select.classList.remove('ring-2', 'ring-green-400'); }, 1500);
                } else {
                    alert('Fehler: ' + (data.message || 'Unbekannter Fehler'));
                    select.value = originalValue;
                }
            })
            .catch(function() {
                alert('Netzwerkfehler beim Speichern der Rolle.');
                select.value = originalValue;
            });
        });
    });
});
</script>

<script>
// Entra user search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput  = document.getElementById('entraSearchInput');
    const searchBtn    = document.getElementById('entraSearchBtn');
    const statusEl     = document.getElementById('entraSearchStatus');
    const resultsEl    = document.getElementById('entraSearchResults');
    const resultsList  = document.getElementById('entraResultsList');
    const importForm   = document.getElementById('entraImportForm');
    const cancelBtn    = document.getElementById('cancelImport');

    if (!searchInput) return; // Tab not rendered (no permission)

    let searchTimer = null;

    function doSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) {
            resultsEl.classList.add('hidden');
            return;
        }

        statusEl.classList.remove('hidden');
        resultsEl.classList.add('hidden');
        importForm.classList.add('hidden');

        fetch('/api/search_entra_users.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                statusEl.classList.add('hidden');
                if (data.error) {
                    resultsList.innerHTML = '<div class="text-red-600 dark:text-red-400 text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">'
                        + '<i class="fas fa-exclamation-circle mr-2"></i>' + escapeHtml(data.error) + '</div>';
                    resultsEl.classList.remove('hidden');
                    return;
                }
                const users = data.users || [];
                if (users.length === 0) {
                    resultsList.innerHTML = '<div class="text-gray-500 dark:text-gray-400 text-sm p-3 italic">Keine Benutzer gefunden.</div>';
                } else {
                    resultsList.innerHTML = users.map(u => {
                        const isGuest = (u.userType || '').toLowerCase() === 'guest';
                        const typeBadge = isGuest
                            ? '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300 ml-1">Gast</span>'
                            : '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 ml-1">Mitglied</span>';
                        return `
                        <div class="flex flex-wrap items-center justify-between gap-2 p-3 bg-gray-50 dark:bg-gray-700 rounded-xl border border-gray-200 dark:border-gray-600 hover:border-blue-300 dark:hover:border-blue-600 transition-all">
                            <div class="flex items-center space-x-3 min-w-0 flex-1">
                                <div class="w-9 h-9 flex-shrink-0 bg-gradient-to-br from-blue-400 to-indigo-500 rounded-lg flex items-center justify-center">
                                    <i class="fab fa-microsoft text-white text-sm"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100 text-sm break-words">${escapeHtml(u.displayName || '(kein Name)')}${typeBadge}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 break-all">${escapeHtml(u.mail || '(keine E-Mail)')}</div>
                                    <div class="text-xs text-gray-400 dark:text-gray-500 font-mono break-all">${escapeHtml(u.id)}</div>
                                </div>
                            </div>
                            <button type="button"
                                class="flex-shrink-0 min-h-[44px] px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition-colors font-semibold"
                                data-id="${escapeHtml(u.id)}"
                                data-name="${escapeHtml(u.displayName || '')}"
                                data-email="${escapeHtml(u.mail || '')}"
                                data-usertype="${escapeHtml(u.userType || 'member')}"
                                onclick="selectEntraUser(this)"
                            >
                                <i class="fas fa-plus mr-1"></i>Auswählen
                            </button>
                        </div>`;
                    }).join('');
                }
                resultsEl.classList.remove('hidden');
            })
            .catch(() => {
                statusEl.classList.add('hidden');
                resultsList.innerHTML = '<div class="text-red-600 dark:text-red-400 text-sm p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">'
                    + '<i class="fas fa-exclamation-circle mr-2"></i>Netzwerkfehler bei der Suche.</div>';
                resultsEl.classList.remove('hidden');
            });
    }

    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 400);
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            importForm.classList.add('hidden');
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    window.selectEntraUser = function(btn) {
        const id       = btn.getAttribute('data-id');
        const name     = btn.getAttribute('data-name');
        const email    = btn.getAttribute('data-email');
        const userType = btn.getAttribute('data-usertype') || 'member';

        const isGuest = userType.toLowerCase() === 'guest';
        const typeLabel = isGuest ? 'Gast' : 'Mitglied';
        const typeClass = isGuest
            ? 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
            : 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';

        document.getElementById('importEntraId').value      = id;
        document.getElementById('importDisplayName').value  = name;
        document.getElementById('importEntraEmail').value   = email;
        document.getElementById('importUserType').value     = isGuest ? 'guest' : 'member';
        document.getElementById('importPreviewName').textContent  = name || '(kein Name)';
        document.getElementById('importPreviewEmail').textContent = email || '(keine E-Mail)';
        document.getElementById('importPreviewId').textContent    = 'Entra-ID: ' + id;

        const typeEl = document.getElementById('importPreviewUserType');
        typeEl.innerHTML = `<span class="${typeClass}">Entra-Typ: ${typeLabel}</span>`;

        importForm.classList.remove('hidden');
        importForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
});
</script>

<script>
// Bulk invite: template loader + recipient selection
document.addEventListener('DOMContentLoaded', function() {
    const templateSelect    = document.getElementById('eventTemplateSelect');
    const loadStatus        = document.getElementById('templateLoadStatus');
    const subjectInput      = document.getElementById('bulkSubject');
    const bodyTextarea      = document.getElementById('bulkBody');
    const userSearchInput   = document.getElementById('bulkUserSearch');
    const selectAllBtn      = document.getElementById('selectAllUsers');
    const deselectAllBtn    = document.getElementById('deselectAllUsers');
    const selectedCount     = document.getElementById('bulkSelectedCount');
    const recipientTabUsers = document.getElementById('recipientTabUsers');
    const recipientTabCsv   = document.getElementById('recipientTabCsv');
    const panelUsers        = document.getElementById('recipientPanelUsers');
    const panelCsv          = document.getElementById('recipientPanelCsv');
    const csvInput          = document.getElementById('bulkCsvInput');

    if (!templateSelect) return;

    // Load template via AJAX when a template is selected
    templateSelect.addEventListener('change', function() {
        const tpl = this.value;
        if (!tpl) return;

        loadStatus.classList.remove('hidden');

        fetch('/api/get_mail_template.php?template=' + encodeURIComponent(tpl))
            .then(r => r.json())
            .then(data => {
                loadStatus.classList.add('hidden');
                if (data.error) {
                    alert('Fehler beim Laden der Vorlage: ' + data.error);
                    return;
                }
                if (subjectInput)  subjectInput.value  = data.subject || '';
                if (bodyTextarea)  bodyTextarea.value  = data.content || '';
            })
            .catch(() => {
                loadStatus.classList.add('hidden');
                alert('Netzwerkfehler beim Laden der Vorlage.');
            });
    });

    // Recipient tab switching
    function activateRecipientTab(tab) {
        if (tab === 'users') {
            recipientTabUsers.classList.remove('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            recipientTabUsers.classList.add('bg-indigo-600', 'text-white');
            recipientTabCsv.classList.remove('bg-indigo-600', 'text-white');
            recipientTabCsv.classList.add('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            panelUsers.classList.remove('hidden');
            panelCsv.classList.add('hidden');
            if (csvInput) csvInput.disabled = true;
        } else {
            recipientTabCsv.classList.remove('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            recipientTabCsv.classList.add('bg-indigo-600', 'text-white');
            recipientTabUsers.classList.remove('bg-indigo-600', 'text-white');
            recipientTabUsers.classList.add('bg-gray-200', 'dark:bg-gray-600', 'text-gray-700', 'dark:text-gray-200');
            panelCsv.classList.remove('hidden');
            panelUsers.classList.add('hidden');
            if (csvInput) csvInput.disabled = false;
        }
    }

    // Disable CSV input by default (users tab is active)
    if (csvInput) csvInput.disabled = true;

    recipientTabUsers.addEventListener('click', () => activateRecipientTab('users'));
    recipientTabCsv.addEventListener('click',   () => activateRecipientTab('csv'));

    // User search/filter
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.bulk-user-checkbox:checked').length;
        if (selectedCount) selectedCount.textContent = checked + ' Benutzer ausgewählt';
    }

    if (userSearchInput) {
        userSearchInput.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('.bulk-user-row').forEach(row => {
                const email = row.getAttribute('data-email') || '';
                const name  = row.getAttribute('data-name')  || '';
                row.style.display = (email.includes(term) || name.includes(term)) ? '' : 'none';
            });
        });
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.bulk-user-row').forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.bulk-user-checkbox');
                    if (cb) cb.checked = true;
                }
            });
            updateSelectedCount();
        });
    }

    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.bulk-user-checkbox').forEach(cb => { cb.checked = false; });
            updateSelectedCount();
        });
    }

    document.querySelectorAll('.bulk-user-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
});
</script>

<style>
/* Modern User Management Design Enhancements */
.bg-pattern {
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.4'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

/* Smooth animations */
.tab-button,
.user-row,
button,
select,
input {
    transition: all 0.2s ease-in-out;
}

/* Custom select dropdown styling */
select {
    background-image: none !important;
}

/* Enhanced focus states */
input:focus,
select:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(147, 51, 234, 0.2);
}

/* Table row animation - optimized for performance */
.user-row {
    transform: translateZ(0);
    will-change: transform;
}

.user-row:hover {
    transform: translate3d(0, -2px, 0);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* Tablet: reduce table cell padding to help the table fit better */
@media (min-width: 641px) and (max-width: 1023px) {
    #usersTable th,
    #usersTable td {
        padding-left: 0.75rem;
        padding-right: 0.75rem;
    }
}

/* Responsive table: Mobile-Card layout */
@media (max-width: 640px) {
    #usersTable thead {
        display: none;
    }

    #usersTable,
    #usersTable tbody,
    #usersTable tr,
    #usersTable td {
        display: block;
        width: 100%;
    }

    #usersTable tr {
        margin-bottom: 1rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    #usersTable td {
        display: flex;
        align-items: flex-start;
        padding: 0.625rem 1rem;
        border-bottom: 1px solid #f3f4f6;
        white-space: normal;
        min-width: 0;
    }

    #usersTable td:last-child {
        border-bottom: none;
    }

    #usersTable td::before {
        content: attr(data-label);
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #374151;
        min-width: 7rem;
        flex-shrink: 0;
        padding-top: 0.2rem;
    }

    #usersTable td > div,
    #usersTable td > span {
        min-width: 0;
        word-break: break-word;
        overflow-wrap: break-word;
    }

    .dark #usersTable td,
    .dark-mode #usersTable td {
        border-bottom-color: #374151;
    }

    .dark #usersTable tr,
    .dark-mode #usersTable tr {
        border-color: #374151;
        background-color: #1f2937;
    }

    .dark #usersTable td::before,
    .dark-mode #usersTable td::before {
        color: #9ca3af;
    }

    .user-row:hover {
        transform: none;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
