<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../../src/Database.php';

// Access control: Only board, resortleiter, alumni_vorstand, and those with manage_projects permission can access
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$canManageProjects = Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand']);
if (!$canManageProjects) {
    header('Location: ../dashboard/index.php');
    exit;
}

$message = '';
$error = '';
$showForm = isset($_GET['new']) || isset($_GET['edit']);
$project = null;

// Handle POST request for creating/updating project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    try {
        $projectId = intval($_POST['project_id'] ?? 0);
        
        // Determine status based on context
        if ($projectId > 0) {
            // Edit mode: use status from dropdown
            $status = $_POST['status'] ?? 'draft';
        } else {
            // Create mode: determine from button pressed
            $isDraft = isset($_POST['save_draft']);
            $status = $isDraft ? 'draft' : 'open';
        }
        
        $isInternal = isset($_POST['is_internal']);
        $projectData = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'client_name' => trim($_POST['client_name'] ?? ''),
            'client_contact_details' => trim($_POST['client_contact_details'] ?? ''),
            'priority' => $_POST['priority'] ?? 'medium',
            'type' => $isInternal ? 'internal' : 'external',
            'status' => $status,
            'max_consultants' => $isInternal ? null : max(1, intval($_POST['max_consultants'] ?? 1)),
            'requires_application' => $isInternal ? intval($_POST['requires_application'] ?? 1) : 1,
            'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
            'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
            'created_by' => Auth::user()['id'] ?? null,
        ];
        
        // Validate required fields based on action
        if (empty($projectData['title'])) {
            throw new Exception('Titel ist erforderlich');
        }
        
        // If creating new project and publishing (not draft), validate all required fields
        if ($projectId === 0 && $status !== 'draft') {
            $requiredFields = [
                'description' => 'Beschreibung',
                'client_name' => 'Kundenname',
                'client_contact_details' => 'Kontaktdaten',
                'start_date' => 'Startdatum',
                'end_date' => 'Enddatum'
            ];
            
            foreach ($requiredFields as $field => $label) {
                if (empty($projectData[$field])) {
                    throw new Exception($label . ' ist erforderlich für die Veröffentlichung');
                }
            }
        }
        
        // Handle image upload
        if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = SecureImageUpload::uploadImage($_FILES['project_image']);
            
            if (!$uploadResult['success']) {
                throw new Exception($uploadResult['error']);
            }
            
            $projectData['image_path'] = $uploadResult['path'];
        }
        
        // Handle PDF file upload
        if (isset($_FILES['project_file']) && $_FILES['project_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = Project::handleDocumentationUpload($_FILES['project_file']);
            
            if (!$uploadResult['success']) {
                throw new Exception($uploadResult['error']);
            }
            
            $projectData['documentation'] = $uploadResult['path'];
        }
        
        if ($projectId > 0) {
            // Update existing project
            // If no new image uploaded, keep the old one
            if (!isset($projectData['image_path'])) {
                unset($projectData['image_path']);
            }
            // Do not overwrite the original creator on updates
            unset($projectData['created_by']);
            
            Project::update($projectId, $projectData);
            $message = 'Projekt erfolgreich aktualisiert';
        } else {
            // Create new project
            $projectId = Project::create($projectData);
            $message = 'Projekt erfolgreich erstellt';
        }
        
        // Redirect to manage page after successful save
        header('Location: manage.php?success=1&msg=' . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $error = 'Fehler beim Speichern: ' . $e->getMessage();
        $showForm = true;
    }
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $projectId = intval($_POST['project_id'] ?? 0);
    
    try {
        $db = Database::getContentDB();
        $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $message = 'Projekt erfolgreich gelöscht';
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Get success message from redirect
if (isset($_GET['success']) && isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Get project for editing
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $project = Project::getById($editId);
    if (!$project) {
        $error = 'Projekt nicht gefunden';
        $showForm = false;
    }
}

// Get all projects with application counts
$db = Database::getContentDB();
$stmt = $db->query("
    SELECT 
        p.*,
        COUNT(pa.id) as application_count
    FROM projects p
    LEFT JOIN project_applications pa ON p.id = pa.project_id
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$projects = $stmt->fetchAll();

$title = 'Projekt-Verwaltung - IBC Intranet';
ob_start();
?>

<?php if (!$showForm): ?>
<!-- Project List View -->
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-briefcase text-purple-600 mr-2"></i>
                Projekt-Verwaltung
            </h1>
            <p class="text-gray-600 dark:text-gray-300"><?php echo count($projects); ?> Projekt(e) gefunden</p>
        </div>
        <a href="manage.php?new=1" class="btn-primary w-full sm:w-auto">
            <i class="fas fa-plus mr-2"></i>Neues Projekt
        </a>
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

<!-- Projects Grid -->
<?php if (empty($projects)): ?>
<div class="card p-12 text-center">
    <i class="fas fa-briefcase text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
    <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Projekte gefunden</h3>
    <p class="text-gray-500 dark:text-gray-400 mb-6">Es wurden noch keine Projekte erstellt.</p>
    <a href="manage.php?new=1" class="btn-primary w-full sm:w-auto">
        <i class="fas fa-plus mr-2"></i>Erstes Projekt erstellen
    </a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($projects as $project): ?>
    <div class="card p-6 hover:shadow-lg transition dark:hover:shadow-purple-900/20">
        <!-- Image -->
        <?php if (!empty($project['image_path'])): ?>
        <div class="mb-4 rounded-lg overflow-hidden">
            <img src="/<?php echo htmlspecialchars($project['image_path']); ?>" 
                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                 class="w-full h-48 object-cover">
        </div>
        <?php endif; ?>
        
        <!-- Status and Priority Badges -->
        <div class="flex items-start justify-between mb-4">
            <span class="px-3 py-1 text-xs font-semibold rounded-full
                <?php 
                switch($project['status']) {
                    case 'draft': echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; break;
                    case 'open': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300'; break;
                    case 'applying': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'; break;
                    case 'assigned': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'; break;
                    case 'running': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300'; break;
                    case 'completed': echo 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-300'; break;
                    case 'archived': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'; break;
                }
                ?>">
                <?php 
                switch($project['status']) {
                    case 'draft': echo 'Entwurf'; break;
                    case 'open': echo 'Offen'; break;
                    case 'applying': echo 'Bewerbungsphase'; break;
                    case 'assigned': echo 'Vergeben'; break;
                    case 'running': echo 'Laufend'; break;
                    case 'completed': echo 'Abgeschlossen'; break;
                    case 'archived': echo 'Archiviert'; break;
                }
                ?>
            </span>
            <span class="px-2 py-1 text-xs font-semibold rounded-full
                <?php 
                switch($project['priority']) {
                    case 'low': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300'; break;
                    case 'medium': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'; break;
                    case 'high': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'; break;
                }
                ?>">
                <?php 
                switch($project['priority']) {
                    case 'low': echo '<i class="fas fa-arrow-down"></i> Niedrig'; break;
                    case 'medium': echo '<i class="fas fa-minus"></i> Mittel'; break;
                    case 'high': echo '<i class="fas fa-arrow-up"></i> Hoch'; break;
                }
                ?>
            </span>
        </div>

        <!-- Project Type Badge -->
        <div class="mb-4">
            <span class="px-3 py-1 text-xs font-semibold rounded-full
                <?php 
                $projectType = $project['type'] ?? 'internal';
                echo $projectType === 'internal' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
                ?>">
                <i class="fas fa-tag mr-1"></i>
                <?php echo $projectType === 'internal' ? 'Intern' : 'Extern'; ?>
            </span>
        </div>

        <!-- Title -->
        <h3 class="text-base sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2 break-words hyphens-auto">
            <?php echo htmlspecialchars($project['title']); ?>
        </h3>

        <!-- Description -->
        <?php if (!empty($project['description'])): ?>
        <p class="text-sm text-gray-600 dark:text-gray-300 mb-4 line-clamp-3">
            <?php echo htmlspecialchars(substr($project['description'], 0, 150)) . (strlen($project['description']) > 150 ? '...' : ''); ?>
        </p>
        <?php endif; ?>

        <!-- Project Info -->
        <div class="space-y-2 mb-4 text-sm text-gray-600 dark:text-gray-300">
            <?php if (!empty($project['client_name'])): ?>
            <div class="flex items-center">
                <i class="fas fa-user-tie w-5 text-purple-600"></i>
                <span><?php echo htmlspecialchars($project['client_name']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($project['start_date'])): ?>
            <div class="flex items-center">
                <i class="fas fa-calendar-start w-5 text-purple-600"></i>
                <span>Start: <?php echo date('d.m.Y', strtotime($project['start_date'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($project['end_date'])): ?>
            <div class="flex items-center">
                <i class="fas fa-calendar-check w-5 text-purple-600"></i>
                <span>Ende: <?php echo date('d.m.Y', strtotime($project['end_date'])); ?></span>
            </div>
            <?php endif; ?>
            <div class="flex items-center">
                <i class="fas fa-users-cog w-5 text-purple-600"></i>
                <span>Benötigt: <?php echo intval($project['max_consultants'] ?? 1); ?> Berater</span>
            </div>
        </div>

        <!-- Application Count -->
        <div class="mb-4 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
            <a href="applications.php?project_id=<?php echo $project['id']; ?>" class="text-sm text-gray-700 dark:text-gray-300 hover:text-purple-600 dark:hover:text-purple-400 transition">
                <i class="fas fa-users mr-1"></i>
                <strong><?php echo $project['application_count']; ?></strong> Bewerbung(en)
            </a>
        </div>

        <!-- Actions -->
        <div class="flex space-x-2">
            <a href="manage.php?edit=<?php echo $project['id']; ?>" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-center text-sm no-underline">
                <i class="fas fa-edit mr-1"></i>Bearbeiten
            </a>
            <button 
                class="delete-project-btn px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm"
                data-project-id="<?php echo $project['id']; ?>"
                data-project-name="<?php echo htmlspecialchars($project['title']); ?>"
                title="Löschen"
            >
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                Projekt löschen
            </h3>
            <p class="text-gray-600 dark:text-gray-300">
                Möchtest Du das Projekt "<span id="deleteProjectName" class="font-semibold"></span>" wirklich löschen? 
                Diese Aktion kann nicht rückgängig gemacht werden.
            </p>
        </div>
        <form method="POST" id="deleteForm" class="px-6 pb-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="project_id" id="deleteProjectId" value="">
            <input type="hidden" name="delete_project" value="1">
            <div class="flex space-x-4">
                <button type="button" id="closeDeleteModalBtn" class="flex-1 px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-trash mr-2"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Delete button event listeners
document.querySelectorAll('.delete-project-btn').forEach(button => {
    button.addEventListener('click', function() {
        const projectId = this.getAttribute('data-project-id');
        const projectName = this.getAttribute('data-project-name');
        confirmDelete(projectId, projectName);
    });
});

function confirmDelete(projectId, projectName) {
    const deleteProjectId = document.getElementById('deleteProjectId');
    const deleteProjectName = document.getElementById('deleteProjectName');
    const deleteModal = document.getElementById('deleteModal');
    
    if (deleteProjectId) deleteProjectId.value = projectId;
    if (deleteProjectName) deleteProjectName.textContent = projectName;
    if (deleteModal) deleteModal.classList.remove('hidden');
}

function closeDeleteModal() {
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) deleteModal.classList.add('hidden');
}

// Close modal button
document.getElementById('closeDeleteModalBtn')?.addEventListener('click', closeDeleteModal);

// Close modal on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});

// Close modal when clicking outside
document.getElementById('deleteModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'deleteModal') {
        closeDeleteModal();
    }
});
</script>

<?php else: ?>
<!-- Project Form View -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
            <i class="fas fa-briefcase text-purple-600 mr-2"></i>
            <?php echo $project ? 'Projekt bearbeiten' : 'Neues Projekt'; ?>
        </h1>
        <a href="manage.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition no-underline">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zur Übersicht
        </a>
    </div>
</div>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Project Form -->
<div class="card p-8">
    <form method="POST" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="save_project" value="1">
        <?php if ($project): ?>
        <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
        <?php endif; ?>
        
        <!-- Title -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Titel <span class="text-red-500">*</span>
            </label>
            <input 
                type="text" 
                name="title" 
                value="<?php echo htmlspecialchars($_POST['title'] ?? $project['title'] ?? ''); ?>"
                required
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                placeholder="Projekt-Titel eingeben"
            >
        </div>

        <!-- Description -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Beschreibung
            </label>
            <textarea 
                name="description" 
                rows="5"
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                placeholder="Projekt-Beschreibung eingeben"
            ><?php echo htmlspecialchars($_POST['description'] ?? $project['description'] ?? ''); ?></textarea>
        </div>

        <!-- Client Information -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Kundenname
                </label>
                <input 
                    type="text" 
                    name="client_name" 
                    value="<?php echo htmlspecialchars($_POST['client_name'] ?? $project['client_name'] ?? ''); ?>"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                    placeholder="Name des Kunden"
                >
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Kontaktdaten
                </label>
                <input 
                    type="text" 
                    name="client_contact_details" 
                    value="<?php echo htmlspecialchars($_POST['client_contact_details'] ?? $project['client_contact_details'] ?? ''); ?>"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                    placeholder="E-Mail, Telefon, etc."
                >
            </div>
        </div>

        <!-- Priority and Type -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Priorität
                </label>
                <select 
                    name="priority" 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                >
                    <option value="low" <?php echo (($_POST['priority'] ?? $project['priority'] ?? 'medium') === 'low') ? 'selected' : ''; ?>>Niedrig</option>
                    <option value="medium" <?php echo (($_POST['priority'] ?? $project['priority'] ?? 'medium') === 'medium') ? 'selected' : ''; ?>>Mittel</option>
                    <option value="high" <?php echo (($_POST['priority'] ?? $project['priority'] ?? 'medium') === 'high') ? 'selected' : ''; ?>>Hoch</option>
                </select>
            </div>
            <div class="flex items-center min-h-[44px] pt-6">
                <input
                    type="checkbox"
                    id="is_internal_checkbox"
                    name="is_internal"
                    value="1"
                    <?php
                    if (isset($_POST['save_project'])) {
                        // POST: reflect checkbox state
                        echo isset($_POST['is_internal']) ? 'checked' : '';
                    } else {
                        // GET: use project type or default to internal for new projects
                        echo (($project['type'] ?? 'internal') === 'internal') ? 'checked' : '';
                    }
                    ?>
                    class="h-5 w-5 text-purple-600 border-gray-300 rounded focus:ring-purple-500"
                >
                <label for="is_internal_checkbox" class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                    <i class="fas fa-building text-indigo-500 mr-1"></i>
                    Internes Projekt
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Internes Vereinsprojekt</span>
                </label>
            </div>
        </div>

        <!-- Status -->
        <?php if ($project): ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Status
            </label>
            <select 
                name="status" 
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
            >
                <option value="draft" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Entwurf</option>
                <option value="open" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'open') ? 'selected' : ''; ?>>Offen</option>
                <option value="applying" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'applying') ? 'selected' : ''; ?>>Bewerbungsphase</option>
                <option value="assigned" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'assigned') ? 'selected' : ''; ?>>Vergeben</option>
                <option value="running" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'running') ? 'selected' : ''; ?>>Laufend</option>
                <option value="completed" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'completed') ? 'selected' : ''; ?>>Abgeschlossen</option>
                <option value="archived" <?php echo (($_POST['status'] ?? $project['status'] ?? 'draft') === 'archived') ? 'selected' : ''; ?>>Archiviert</option>
            </select>
        </div>
        <?php endif; ?>

        <!-- Date Range -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Startdatum
                </label>
                <input 
                    type="date" 
                    name="start_date" 
                    value="<?php echo htmlspecialchars($_POST['start_date'] ?? $project['start_date'] ?? ''); ?>"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                >
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Enddatum
                </label>
                <input 
                    type="date" 
                    name="end_date" 
                    value="<?php echo htmlspecialchars($_POST['end_date'] ?? $project['end_date'] ?? ''); ?>"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                >
            </div>
        </div>

        <!-- Required Consultants -->
        <div id="max_consultants_row">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Anzahl Berater <span class="text-red-500" id="max_consultants_required_star">*</span>
            </label>
            <input 
                type="number" 
                name="max_consultants" 
                id="max_consultants_input"
                value="<?php echo htmlspecialchars($_POST['max_consultants'] ?? $project['max_consultants'] ?? '1'); ?>"
                min="1"
                required
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                placeholder="Anzahl benötigter Berater"
            >
        </div>

        <!-- Bewerbung erforderlich (nur für interne Projekte) -->
        <?php
        $requiresAppValue = isset($_POST['save_project'])
            ? intval($_POST['requires_application'] ?? 1)
            : intval($project['requires_application'] ?? 1);
        ?>
        <div id="requires_application_section">
            <div id="requires_application_row" class="flex items-center min-h-[44px] pt-2">
                <input
                    type="checkbox"
                    id="requires_application_checkbox"
                    value="1"
                    <?php echo $requiresAppValue === 1 ? 'checked' : ''; ?>
                    class="h-5 w-5 text-purple-600 border-gray-300 rounded focus:ring-purple-500"
                >
                <label for="requires_application_checkbox" class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                    <i class="fas fa-file-signature text-orange-500 mr-1"></i>
                    Bewerbung erforderlich
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Bewerber müssen eine Bewerbung einreichen</span>
                </label>
            </div>
            <input type="hidden" id="requires_application_hidden" name="requires_application" value="<?php echo $requiresAppValue; ?>">
        </div>

        <!-- Image Upload -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Projekt-Bild
            </label>
            <?php if ($project && !empty($project['image_path'])): ?>
            <div class="mb-4">
                <img src="/<?php echo htmlspecialchars($project['image_path']); ?>" 
                     alt="Aktuelles Bild"
                     class="w-64 h-48 object-cover rounded-lg border border-gray-300 dark:border-gray-600">
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Aktuelles Bild (wird ersetzt, wenn Sie ein neues hochladen)</p>
            </div>
            <?php endif; ?>
            <input 
                type="file" 
                name="project_image" 
                accept="image/jpeg,image/png,image/webp,image/gif"
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
            >
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Erlaubte Formate: JPG, PNG, WebP, GIF. Maximale Größe: 5MB
            </p>
        </div>

        <!-- Documentation Upload -->
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Projekt-Dokumentation (PDF)
            </label>
            <?php if ($project && !empty($project['documentation'])): ?>
            <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-300 dark:border-gray-600">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    <i class="fas fa-file-pdf text-red-500 mr-2"></i>
                    <a href="/<?php echo htmlspecialchars($project['documentation']); ?>" 
                       target="_blank" 
                       class="text-purple-600 hover:underline">
                        Aktuelle Dokumentation anzeigen
                    </a>
                </p>
            </div>
            <?php endif; ?>
            <input 
                type="file" 
                name="project_file" 
                accept=".pdf"
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
            >
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                Erlaubte Formate: PDF. Maximale Größe: 10MB
            </p>
        </div>

        <!-- Form Actions -->
        <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200 dark:border-gray-700">
            <a href="manage.php" class="btn-cancel sm:w-auto">
                <i class="fas fa-times mr-2"></i>
                Abbrechen
            </a>
            <?php if ($project): ?>
                <button type="submit" class="flex-1 btn-primary">
                    <i class="fas fa-save mr-2"></i>
                    Änderungen speichern
                </button>
            <?php else: ?>
                <button type="submit" name="save_draft" value="1" class="flex-1 btn-secondary">
                    <i class="fas fa-file mr-2"></i>
                    Als Entwurf speichern
                </button>
                <button type="submit" class="flex-1 btn-primary">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Veröffentlichen
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
// Internal project checkbox logic
(function () {
    const checkbox = document.getElementById('is_internal_checkbox');
    const consultantsRow = document.getElementById('max_consultants_row');
    const consultantsInput = document.getElementById('max_consultants_input');
    const requiresAppSection = document.getElementById('requires_application_section');
    const requiresAppCheckbox = document.getElementById('requires_application_checkbox');
    const requiresAppHidden = document.getElementById('requires_application_hidden');

    if (!checkbox) return;

    // Keep hidden input in sync with the visible checkbox
    if (requiresAppCheckbox && requiresAppHidden) {
        requiresAppCheckbox.addEventListener('change', function () {
            requiresAppHidden.value = this.checked ? '1' : '0';
        });
    }

    function applyInternalState(isInternal) {
        if (consultantsRow) consultantsRow.style.display = isInternal ? 'none' : '';
        if (consultantsInput) consultantsInput.required = !isInternal;
        // Show "Bewerbung erforderlich" only for internal projects
        if (requiresAppSection) requiresAppSection.style.display = isInternal ? '' : 'none';
    }

    // Apply on page load
    applyInternalState(checkbox.checked);

    // Apply on change
    checkbox.addEventListener('change', function () {
        applyInternalState(this.checked);
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
