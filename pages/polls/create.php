<?php
/**
 * Create Poll - Form to create a new poll
 * Access: board (all variants), alumni_board, alumni_auditor, head
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to create polls
if (!Auth::canCreatePolls()) {
    header('Location: index.php');
    exit;
}

$successMessage = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_poll'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $microsoftFormsUrl = trim($_POST['microsoft_forms_url'] ?? '');
    $targetRoles  = $_POST['target_roles']  ?? [];
    $visibleToAll = isset($_POST['visible_to_all']) ? 1 : 0;
    $isInternal = isset($_POST['is_internal']) ? 1 : 0;
    
    // Validation
    if (empty($title)) {
        $errorMessage = 'Bitte geben Sie einen Titel ein.';
    } elseif (empty($microsoftFormsUrl)) {
        $errorMessage = 'Bitte geben Sie die Microsoft Forms URL ein.';
    } elseif (!$visibleToAll && empty($targetRoles)) {
        $errorMessage = 'Bitte wählen Sie mindestens eine Zielgruppe aus oder aktivieren Sie "Für alle sichtbar".';
    } else {
        try {
            $db = Database::getContentDB();
            
            // Determine target_groups value and expand board_roles if needed
            if ($visibleToAll) {
                $targetGroupsValue = 'all';
                $targetRolesJson   = null;
            } else {
                $targetGroupsValue = null;
                // Replace the virtual 'board_roles' entry with the three concrete board roles
                if (in_array('board_roles', $targetRoles)) {
                    $targetRoles = array_filter($targetRoles, fn($r) => $r !== 'board_roles');
                    $targetRoles = array_merge(array_values($targetRoles), ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']);
                }
                $targetRolesJson = !empty($targetRoles) ? json_encode(array_values(array_unique($targetRoles))) : null;
            }

            // Insert poll with Microsoft Forms URL and new fields
            $stmt = $db->prepare("
                INSERT INTO polls (title, description, created_by, microsoft_forms_url, target_groups, 
                                   allowed_roles, target_roles, visible_to_all, is_internal, is_active, end_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL 30 DAY))
            ");
            $stmt->execute([
                $title, 
                $description, 
                $user['id'], 
                $microsoftFormsUrl, 
                $targetGroupsValue,
                null,
                $targetRolesJson,
                $visibleToAll,
                $isInternal
            ]);
            
            // Redirect to polls list
            header('Location: ' . asset('pages/polls/index.php'));
            exit;
            
        } catch (Exception $e) {
            error_log('Error creating poll: ' . $e->getMessage());
            $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}

$title = 'Umfrage erstellen - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <i class="fas fa-poll mr-3 text-blue-500"></i>
            Umfrage erstellen
        </h1>
        <p class="text-gray-600 dark:text-gray-300">Erstellen Sie eine neue Umfrage für Ihre Mitglieder</p>
    </div>

    <!-- Error Message -->
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Create Poll Form -->
    <div class="card p-8">
        <form method="POST" class="space-y-6" id="pollForm">
            <!-- Title -->
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Titel <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="text" 
                    id="title" 
                    name="title" 
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white"
                    placeholder="Z.B. Wahl des Veranstaltungsortes"
                >
            </div>

            <!-- Description -->
            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beschreibung (optional)
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    rows="4"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white"
                    placeholder="Zusätzliche Informationen zur Umfrage..."
                ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <!-- Microsoft Forms URL -->
            <div>
                <label for="microsoft_forms_url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Microsoft Forms URL <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="url" 
                    id="microsoft_forms_url" 
                    name="microsoft_forms_url" 
                    required
                    value="<?php echo htmlspecialchars($_POST['microsoft_forms_url'] ?? ''); ?>"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white"
                    placeholder="https://forms.office.com/Pages/ResponsePage.aspx?id=..."
                >
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle mr-1"></i>
                    Fügen Sie die Embed-URL oder die direkte URL zu Ihrem Microsoft Forms ein.
                </p>
                <p class="mt-2 text-sm text-blue-600 dark:text-blue-400">
                    <i class="fas fa-lightbulb mr-1"></i>
                    <strong>Hinweis:</strong> Microsoft bietet keine API zum automatischen erstellen. Bitte erstellen Sie das Formular manuell auf forms.office.com und fügen Sie hier den Einbettungs-Code ein.
                </p>
            </div>

            <!-- Zielgruppen / Sichtbarkeit -->
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                    Zielgruppen / Sichtbarkeit <span class="text-red-500 dark:text-red-400">*</span>
                </label>

                <!-- Für alle sichtbar -->
                <label class="flex items-start cursor-pointer mb-5 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                    <input
                        type="checkbox"
                        name="visible_to_all"
                        id="visible_to_all"
                        value="1"
                        <?php echo (isset($_POST['visible_to_all'])) ? 'checked' : ''; ?>
                        class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 mt-0.5 shrink-0"
                    >
                    <div class="ml-3">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Für alle sichtbar</span>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Wenn aktiviert, wird die Umfrage für alle Benutzer angezeigt, unabhängig von ihren Rollen.
                        </p>
                    </div>
                </label>

                <!-- Entra Role Checkboxes -->
                <div id="role_checkboxes" class="space-y-2">
                    <?php
                    $roleOptions = [
                        ['label' => 'Vorstand',           'value' => 'board_roles'],
                        ['label' => 'Alumni Finanzprüfer','value' => 'alumni_finanz'],
                        ['label' => 'Alumni Vorstand',    'value' => 'alumni_vorstand'],
                        ['label' => 'Alumni',             'value' => 'alumni'],
                        ['label' => 'Ehrenmitglied',      'value' => 'ehrenmitglied'],
                        ['label' => 'Mitglieder',         'value' => 'mitglied'],
                        ['label' => 'Anwärter',           'value' => 'anwaerter'],
                        ['label' => 'Resortleiter',       'value' => 'ressortleiter'],
                    ];
                    $selectedTargetRoles = $_POST['target_roles'] ?? [];
                    foreach ($roleOptions as $opt):
                    ?>
                    <label class="role-checkbox-label flex items-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <input
                            type="checkbox"
                            name="target_roles[]"
                            value="<?php echo htmlspecialchars($opt['value']); ?>"
                            <?php echo (in_array($opt['value'], $selectedTargetRoles)) ? 'checked' : ''; ?>
                            class="role-checkbox w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
                        >
                        <span class="ml-3 text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($opt['label']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Is Internal Option -->
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <label class="flex items-start cursor-pointer">
                    <input 
                        type="checkbox" 
                        name="is_internal" 
                        value="1"
                        <?php echo (!isset($_POST['create_poll']) || (isset($_POST['create_poll']) && isset($_POST['is_internal']))) ? 'checked' : ''; ?>
                        class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 mt-0.5"
                    >
                    <div class="ml-3">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Interne Umfrage</span>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Wenn aktiviert, wird die Umfrage automatisch ausgeblendet, nachdem der Benutzer abgestimmt hat. 
                            Deaktivieren Sie diese Option für externe Microsoft Forms-Umfragen, um den "Erledigt / Ausblenden"-Button anzuzeigen.
                        </p>
                    </div>
                </label>
            </div>

            <!-- Info Box -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                    <div class="text-sm text-blue-800 dark:text-blue-300">
                        <p class="font-semibold mb-1">Hinweis:</p>
                        <p>Die Umfrage wird automatisch aktiv und die ausgewählten Zielgruppen können über Microsoft Forms teilnehmen. Die Umfrage läuft standardmäßig 30 Tage.</p>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex gap-4">
                <button 
                    type="submit"
                    name="create_poll"
                    class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg"
                >
                    <i class="fas fa-check mr-2"></i>
                    Umfrage erstellen
                </button>
                <a 
                    href="<?php echo asset('pages/polls/index.php'); ?>"
                    class="px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all text-center"
                >
                    Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Disable/gray out role checkboxes when "Für alle sichtbar" is checked
document.addEventListener('DOMContentLoaded', function() {
    const visibleToAllCheckbox = document.getElementById('visible_to_all');
    const roleCheckboxes = document.querySelectorAll('.role-checkbox');
    const roleLabels = document.querySelectorAll('.role-checkbox-label');

    function toggleRoleCheckboxes(disabled) {
        roleCheckboxes.forEach(function(cb) {
            cb.disabled = disabled;
            if (disabled) cb.checked = false;
        });
        roleLabels.forEach(function(label) {
            if (disabled) {
                label.classList.add('opacity-50', 'cursor-not-allowed');
                label.classList.remove('cursor-pointer', 'hover:bg-gray-100', 'dark:hover:bg-gray-700');
            } else {
                label.classList.remove('opacity-50', 'cursor-not-allowed');
                label.classList.add('cursor-pointer', 'hover:bg-gray-100', 'dark:hover:bg-gray-700');
            }
        });
    }

    if (visibleToAllCheckbox) {
        toggleRoleCheckboxes(visibleToAllCheckbox.checked);
        visibleToAllCheckbox.addEventListener('change', function() {
            toggleRoleCheckboxes(this.checked);
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
