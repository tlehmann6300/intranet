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

<style>
.pcr-container {
    max-width: 56rem;
    margin-left: auto;
    margin-right: auto;
    animation: springFadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes springFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.pcr-header {
    margin-bottom: 2rem;
}

.pcr-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

@media (min-width: 640px) {
    .pcr-title {
        font-size: 2.25rem;
    }
}

.pcr-subtitle {
    color: var(--text-muted);
}

.pcr-error-box {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 0.5rem;
    color: #dc2626;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.dark-mode .pcr-error-box {
    background-color: rgba(127, 29, 29, 0.2);
    border-color: rgba(239, 68, 68, 0.4);
    color: #fca5a5;
}

.pcr-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    padding: 2rem;
    box-shadow: var(--shadow-card);
    transition: box-shadow 0.2s;
}

.pcr-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.dark-mode .pcr-card {
    border: 1px solid var(--border-color);
}

.pcr-form-group {
    margin-bottom: 1.5rem;
}

.pcr-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.pcr-required {
    color: #ef4444;
}

.pcr-input,
.pcr-textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 1rem;
    transition: all 0.2s;
    min-height: 44px;
}

.pcr-input:focus,
.pcr-textarea:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.dark-mode .pcr-input:focus,
.dark-mode .pcr-textarea:focus {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

.pcr-textarea {
    resize: vertical;
    min-height: 100px;
}

.pcr-hint {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pcr-section {
    padding: 1.5rem;
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}

.pcr-section-title {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 1rem;
}

.pcr-checkbox-item {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    background-color: var(--bg-card);
    border-radius: 0.375rem;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 0.5rem;
    gap: 0.75rem;
    min-height: 44px;
}

.pcr-checkbox-item:hover {
    background-color: var(--border-color);
}

.dark-mode .pcr-checkbox-item:hover {
    background-color: rgba(59, 130, 246, 0.1);
}

.pcr-checkbox-item input[type="checkbox"],
.pcr-checkbox-item input[type="radio"] {
    min-height: 44px;
    min-width: 44px;
    accent-color: var(--ibc-blue);
    flex-shrink: 0;
}

.pcr-checkbox-label {
    color: var(--text-main);
    flex: 1;
}

.pcr-visibility-box {
    padding: 1rem;
    background-color: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}

.pcr-visibility-box:hover {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.dark-mode .pcr-visibility-box {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.pcr-visibility-box input[type="checkbox"] {
    min-height: 44px;
    min-width: 44px;
    accent-color: var(--ibc-blue);
    margin-top: 0.25rem;
    flex-shrink: 0;
}

.pcr-visibility-title {
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.pcr-visibility-desc {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.pcr-info-box {
    padding: 1rem;
    background-color: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}

.dark-mode .pcr-info-box {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.pcr-info-title {
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pcr-info-text {
    font-size: 0.875rem;
    color: var(--text-muted);
    line-height: 1.5;
}

.pcr-button-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

@media (min-width: 768px) {
    .pcr-button-group {
        flex-direction: row;
        justify-content: flex-end;
    }
}

.pcr-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    min-height: 44px;
    width: 100%;
}

@media (min-width: 768px) {
    .pcr-btn {
        width: auto;
    }
}

.pcr-btn-primary {
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    color: white;
    box-shadow: var(--shadow-card);
}

.pcr-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-card-hover);
}

.pcr-btn-secondary {
    background-color: var(--border-color);
    color: var(--text-main);
}

.pcr-btn-secondary:hover {
    background-color: var(--text-muted);
}

.dark-mode .pcr-btn-secondary {
    background-color: var(--text-muted);
    color: var(--bg-body);
}

.dark-mode .pcr-btn-secondary:hover {
    background-color: var(--text-main);
}

.role-checkbox-label.opacity-50 {
    opacity: 0.5;
    pointer-events: none;
}
</style>

<div class="pcr-container">
    <!-- Header -->
    <div class="pcr-header">
        <h1 class="pcr-title">
            <i class="fas fa-poll"></i>
            Umfrage erstellen
        </h1>
        <p class="pcr-subtitle">Erstellen Sie eine neue Umfrage für Ihre Mitglieder</p>
    </div>

    <!-- Error Message -->
    <?php if ($errorMessage): ?>
    <div class="pcr-error-box">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($errorMessage); ?></span>
    </div>
    <?php endif; ?>

    <!-- Create Poll Form -->
    <div class="pcr-card">
        <form method="POST" id="pollForm">
            <!-- Title -->
            <div class="pcr-form-group">
                <label for="title" class="pcr-label">
                    Titel <span class="pcr-required">*</span>
                </label>
                <input
                    type="text"
                    id="title"
                    name="title"
                    required
                    maxlength="255"
                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                    class="pcr-input"
                    placeholder="Z.B. Wahl des Veranstaltungsortes"
                >
            </div>

            <!-- Description -->
            <div class="pcr-form-group">
                <label for="description" class="pcr-label">
                    Beschreibung (optional)
                </label>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="pcr-textarea"
                    placeholder="Zusätzliche Informationen zur Umfrage..."
                ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <!-- Microsoft Forms URL -->
            <div class="pcr-form-group">
                <label for="microsoft_forms_url" class="pcr-label">
                    Microsoft Forms URL <span class="pcr-required">*</span>
                </label>
                <input
                    type="url"
                    id="microsoft_forms_url"
                    name="microsoft_forms_url"
                    required
                    value="<?php echo htmlspecialchars($_POST['microsoft_forms_url'] ?? ''); ?>"
                    class="pcr-input"
                    placeholder="https://forms.office.com/Pages/ResponsePage.aspx?id=..."
                >
                <p class="pcr-hint">
                    <i class="fas fa-info-circle"></i>
                    <span>Fügen Sie die Embed-URL oder die direkte URL zu Ihrem Microsoft Forms ein.</span>
                </p>
                <p class="pcr-hint" style="color: var(--ibc-blue); margin-top: 0.75rem;">
                    <i class="fas fa-lightbulb"></i>
                    <span><strong>Hinweis:</strong> Microsoft bietet keine API zum automatischen erstellen. Bitte erstellen Sie das Formular manuell auf forms.office.com und fügen Sie hier den Einbettungs-Code ein.</span>
                </p>
            </div>

            <!-- Zielgruppen / Sichtbarkeit -->
            <div class="pcr-section">
                <label class="pcr-section-title">
                    Zielgruppen / Sichtbarkeit <span class="pcr-required">*</span>
                </label>

                <!-- Für alle sichtbar -->
                <label class="pcr-visibility-box">
                    <input
                        type="checkbox"
                        name="visible_to_all"
                        id="visible_to_all"
                        value="1"
                        <?php echo (isset($_POST['visible_to_all'])) ? 'checked' : ''; ?>
                    >
                    <div style="flex: 1;">
                        <div class="pcr-visibility-title">Für alle sichtbar</div>
                        <div class="pcr-visibility-desc">
                            Wenn aktiviert, wird die Umfrage für alle Benutzer angezeigt, unabhängig von ihren Rollen.
                        </div>
                    </div>
                </label>

                <!-- Entra Role Checkboxes -->
                <div id="role_checkboxes">
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
                    <label class="role-checkbox-label pcr-checkbox-item">
                        <input
                            type="checkbox"
                            name="target_roles[]"
                            value="<?php echo htmlspecialchars($opt['value']); ?>"
                            <?php echo (in_array($opt['value'], $selectedTargetRoles)) ? 'checked' : ''; ?>
                            class="role-checkbox"
                        >
                        <span class="pcr-checkbox-label"><?php echo htmlspecialchars($opt['label']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Is Internal Option -->
            <div class="pcr-section">
                <label class="pcr-checkbox-item">
                    <input
                        type="checkbox"
                        name="is_internal"
                        value="1"
                        <?php echo (!isset($_POST['create_poll']) || (isset($_POST['create_poll']) && isset($_POST['is_internal']))) ? 'checked' : ''; ?>
                    >
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: var(--text-main);">Interne Umfrage</div>
                        <div style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.5rem;">
                            Wenn aktiviert, wird die Umfrage automatisch ausgeblendet, nachdem der Benutzer abgestimmt hat.
                            Deaktivieren Sie diese Option für externe Microsoft Forms-Umfragen, um den "Erledigt / Ausblenden"-Button anzuzeigen.
                        </div>
                    </div>
                </label>
            </div>

            <!-- Info Box -->
            <div class="pcr-info-box">
                <div class="pcr-info-title">
                    <i class="fas fa-info-circle"></i>
                    <span>Hinweis</span>
                </div>
                <div class="pcr-info-text">
                    Die Umfrage wird automatisch aktiv und die ausgewählten Zielgruppen können über Microsoft Forms teilnehmen. Die Umfrage läuft standardmäßig 30 Tage.
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="pcr-button-group">
                <button
                    type="submit"
                    name="create_poll"
                    class="pcr-btn pcr-btn-primary"
                >
                    <i class="fas fa-check"></i>
                    Umfrage erstellen
                </button>
                <a
                    href="<?php echo asset('pages/polls/index.php'); ?>"
                    class="pcr-btn pcr-btn-secondary"
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
