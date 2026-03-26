<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/services/MicrosoftGraphService.php';

// Only board, alumni_vorstand, resortleiter, and those with manage_projects permission can access
if (!Auth::check() || !(Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand']))) {
    header('Location: ../auth/login.php');
    exit;
}

// Try to fetch groups from Microsoft Entra for allowed roles
$entraGroups = [];
try {
    $graphService = new MicrosoftGraphService();
    $entraGroups = $graphService->getAllGroups();
} catch (Exception $e) {
    // If Graph API is unavailable, we'll fall back to hardcoded roles
    error_log("Could not fetch groups from Microsoft Graph: " . $e->getMessage());
}

// Check if we're creating a new event or editing an existing one
$eventId = intval($_GET['id'] ?? 0);
$isNew = isset($_GET['new']) && $_GET['new'] === '1';
$isEdit = $eventId > 0 && !$isNew;
$readOnly = false;
$lockWarning = '';
$event = null;
$history = [];

// If editing, try to acquire lock
if ($isEdit) {
    $event = Event::getById($eventId);
    if (!$event) {
        header('Location: manage.php');
        exit;
    }
    
    // Try to acquire lock
    $lockResult = Event::acquireLock($eventId, $_SESSION['user_id']);
    
    if (!$lockResult['success']) {
        $readOnly = true;
        $lockedUser = User::getById($lockResult['locked_by']);
        $lockWarning = 'Dieses Event wird gerade von ' . htmlspecialchars(($lockedUser['first_name'] ?? '') . ' ' . ($lockedUser['last_name'] ?? '')) . ' bearbeitet. Du befindest Dich im Nur-Lesen-Modus.';
    }
    
    // Get history
    $history = Event::getHistory($eventId, 10);
}

$message = '';
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    try {
        CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
    
    if (empty($errors)) {
        // Validate times
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        
        if (empty($startTime) || empty($endTime)) {
            $errors[] = 'Start- und Endzeit sind erforderlich';
        }
        
        if (empty($errors) && strtotime($startTime) >= strtotime($endTime)) {
            $errors[] = 'Die Startzeit muss vor der Endzeit liegen';
        }
        
        // Prepare event data
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'maps_link' => trim($_POST['maps_link'] ?? ''),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'registration_start' => !empty($_POST['registration_start']) ? $_POST['registration_start'] : null,
            'registration_end' => !empty($_POST['registration_end']) ? $_POST['registration_end'] : null,
            'is_external' => isset($_POST['is_external']) ? 1 : 0,
            'external_link' => trim($_POST['external_link'] ?? ''),
            'registration_link' => trim($_POST['registration_link'] ?? ''),
            'needs_helpers' => isset($_POST['needs_helpers']) ? 1 : 0,
            'is_internal_project' => isset($_POST['is_internal_project']) ? 1 : 0,
            'requires_application' => isset($_POST['requires_application']) ? 1 : 0,
            'allowed_roles' => $_POST['allowed_roles'] ?? []
        ];
        
        // Expand board_roles shortcut to individual Entra roles
        if (in_array('board_roles', $data['allowed_roles'])) {
            $data['allowed_roles'] = array_merge(
                array_diff($data['allowed_roles'], ['board_roles']),
                ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern']
            );
        }
        
        // Determine status based on which button was clicked
        $status = isset($_POST['save_draft']) ? 'draft' : 'published';
        $data['status'] = $status;
        
        // Handle image deletion
        if (isset($_POST['delete_image']) && $_POST['delete_image'] === '1') {
            $data['delete_image'] = true;
        }
        
        // Add helper types if needs_helpers is enabled
        if ($data['needs_helpers']) {
            $data['helper_types'] = json_decode($_POST['helper_types_json'] ?? '[]', true);
        }
        
        if (empty($data['title'])) {
            $errors[] = 'Titel ist erforderlich';
        }
        
        // If no errors, proceed with save
        if (empty($errors)) {
            try {
                if ($isEdit) {
                    // Update existing event (model handles helper types and slots in transaction)
                    Event::update($eventId, $data, $_SESSION['user_id'], $_FILES);
                    
                    $message = 'Event erfolgreich aktualisiert';
                    
                    // Release lock and reload
                    Event::releaseLock($eventId, $_SESSION['user_id']);
                    header('Location: edit.php?id=' . $eventId . '&success=1');
                    exit;
                } else {
                    // Create new event (model handles helper types and slots in transaction)
                    $newEventId = Event::create($data, $_SESSION['user_id'], $_FILES);
                    
                    $message = 'Event erfolgreich erstellt';
                    header('Location: edit.php?id=' . $newEventId . '&success=1');
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Check for success message
if (isset($_GET['success'])) {
    $message = $isEdit ? 'Event erfolgreich aktualisiert' : 'Event erfolgreich erstellt';
    // Reload event data
    if ($isEdit) {
        $event = Event::getById($eventId);
        $history = Event::getHistory($eventId, 10);
    }
}

// Release lock when leaving the page (via beforeunload in JS)
if ($isEdit && !$readOnly) {
    // Lock will be released via JavaScript on page unload
}

$title = $isEdit ? 'Event bearbeiten - ' . htmlspecialchars($event['title'] ?? '') : 'Neues Event erstellen';
ob_start();
?>

<!-- Flatpickr CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<style>
/* IBC branded colors for this form */
/* Note: Flatpickr styling is now handled by /assets/css/theme.css with IBC colors */

/* Tab styling improvements */
.tab-button {
    transition: all 0.25s ease;
    position: relative;
}

.tab-button:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.25);
}

.tab-button.active {
    box-shadow: 0 2px 8px rgba(0, 102, 179, 0.25);
}

/* Helper card styling */
.helper-card {
    transition: all 0.3s ease;
    border: 2px solid #e5e7eb;
}

.helper-card:hover {
    border-color: #9ca3af;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
}

/* Slot styling */
.slot-item {
    transition: all 0.2s ease;
}

.slot-item:hover {
    background-color: #f9fafb;
}

/* Accordion animation */
.accordion-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.accordion-content.active {
    max-height: 2000px;
}
</style>

<div class="mb-6">
    <a href="manage.php" class="text-purple-600 hover:text-purple-700 inline-flex items-center mb-4">
        <i class="fas fa-arrow-left mr-2"></i>Zurück zur Übersicht
    </a>
</div>

<?php if ($lockWarning): ?>
<div class="mb-6 p-4 bg-yellow-100 border border-yellow-400 text-yellow-800 rounded-lg">
    <i class="fas fa-lock mr-2"></i><?php echo $lockWarning; ?>
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-xl shadow-premium">
    <div class="flex items-start">
        <i class="fas fa-exclamation-circle text-xl mr-3"></i>
        <div class="flex-1">
            <h3 class="font-bold mb-2">Fehler beim Speichern:</h3>
            <ul class="list-disc list-inside space-y-1">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="glass-card shadow-premium rounded-xl p-6">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus'; ?> text-ibc-blue mr-2"></i>
        <?php echo $isEdit ? 'Event bearbeiten' : 'Neues Event erstellen'; ?>
    </h1>

    <!-- Modern Tab Navigation -->
    <div class="mb-6">
        <nav class="flex flex-wrap gap-2 p-1 bg-gray-100 dark:bg-gray-800 rounded-xl" aria-label="Tabs">
            <button 
                class="tab-button active bg-ibc-blue text-white rounded-lg px-5 py-2.5 font-semibold text-sm shadow-md whitespace-nowrap"
                data-tab="basic"
                type="button"
            >
                <i class="fas fa-info-circle mr-2"></i>
                Basisdaten
            </button>
            <button 
                class="tab-button text-gray-600 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-700 hover:text-ibc-blue dark:hover:text-ibc-blue-light rounded-lg px-5 py-2.5 font-semibold text-sm whitespace-nowrap"
                data-tab="time"
                type="button"
            >
                <i class="fas fa-clock mr-2"></i>
                Zeit &amp; Einstellungen
            </button>
            <button 
                id="helper-tab-button"
                class="tab-button text-gray-600 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-700 hover:text-ibc-blue dark:hover:text-ibc-blue-light rounded-lg px-5 py-2.5 font-semibold text-sm whitespace-nowrap <?php echo (!$isEdit || !$event['needs_helpers']) ? 'hidden' : ''; ?>"
                data-tab="helpers"
                type="button"
            >
                <i class="fas fa-hands-helping mr-2"></i>
                Helfer-Planung
            </button>
        </nav>
    </div>

    <form method="POST" enctype="multipart/form-data" id="eventForm" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="helper_types_json" id="helper_types_json" value="">

        <!-- Tab 1: Basisdaten -->
        <div id="tab-basic" class="tab-content">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-info-circle text-purple-600 mr-2"></i>
                Basisdaten
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Title -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Titel <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="title" 
                        value="<?php echo htmlspecialchars($_POST['title'] ?? $event['title'] ?? ''); ?>"
                        required 
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Event-Titel"
                    >
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung</label>
                    <textarea 
                        name="description" 
                        rows="4"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Event-Beschreibung..."
                    ><?php echo htmlspecialchars($_POST['description'] ?? $event['description'] ?? ''); ?></textarea>
                </div>

                <!-- Location / Room -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Veranstaltungsort / Raum</label>
                    <input 
                        type="text" 
                        name="location"
                        value="<?php echo htmlspecialchars($_POST['location'] ?? $event['location'] ?? ''); ?>"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="z.B. H-1.88 Aula"
                    >
                </div>

                <!-- Google Maps Link -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Google Maps Link
                        <span class="text-xs text-gray-500 ml-2">(Optional)</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-map-marked-alt text-gray-400"></i>
                        </div>
                        <input 
                            type="url" 
                            name="maps_link"
                            value="<?php echo htmlspecialchars($_POST['maps_link'] ?? $event['maps_link'] ?? ''); ?>"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                            placeholder="https://maps.google.com/..."
                        >
                    </div>
                </div>

                <!-- Event Image Upload -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Event-Bild
                        <span class="text-xs text-gray-500 ml-2">(Optional)</span>
                    </label>
                    
                    <?php if ($isEdit && !empty($event['image_path'])): ?>
                    <div class="mb-3">
                        <p class="text-sm text-gray-600 mb-2">Aktuelles Bild:</p>
                        <img 
                            src="<?php echo htmlspecialchars(rtrim(BASE_URL, '/') . '/' . ltrim($event['image_path'], '/')); ?>" 
                            alt="Event Bild"
                            class="max-w-xs rounded-xl border border-gray-300 shadow-sm"
                        >
                        <div class="mt-2 flex items-center min-h-[44px]">
                            <input 
                                type="checkbox" 
                                name="delete_image" 
                                id="delete_image" 
                                value="1" 
                                <?php echo $readOnly ? 'disabled' : ''; ?>
                                class="form-checkbox h-4 w-4 text-red-600 transition duration-150 ease-in-out"
                            >
                            <label for="delete_image" class="ml-2 text-sm text-gray-700">Bild löschen</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <input 
                        type="file" 
                        name="event_image"
                        accept="image/*"
                        <?php echo $readOnly ? 'disabled' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                    >
                    <p class="text-xs text-gray-500 mt-1">Unterstützte Formate: JPG, PNG, GIF. Max. 5MB.</p>
                </div>
            </div>
        </div>

        <!-- Tab 2: Zeit & Einstellungen -->
        <div id="tab-time" class="tab-content hidden">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-clock text-ibc-blue mr-2"></i>
                Zeit & Einstellungen
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Start Time with Flatpickr -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Startzeit <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="start_time"
                        id="start_time"
                        value="<?php 
                            if (!empty($_POST['start_time'])) {
                                echo htmlspecialchars($_POST['start_time']);
                            } elseif ($isEdit && !empty($event['start_time'])) {
                                echo date('Y-m-d H:i', strtotime($event['start_time']));
                            }
                        ?>"
                        required
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="flatpickr-input w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Datum und Uhrzeit wählen"
                    >
                </div>

                <!-- End Time with Flatpickr -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Endzeit <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="end_time"
                        id="end_time"
                        value="<?php 
                            if (!empty($_POST['end_time'])) {
                                echo htmlspecialchars($_POST['end_time']);
                            } elseif ($isEdit && !empty($event['end_time'])) {
                                echo date('Y-m-d H:i', strtotime($event['end_time']));
                            }
                        ?>"
                        required
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="flatpickr-input w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Datum und Uhrzeit wählen"
                    >
                </div>

                <!-- Registration Start Time -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Anmeldung Start
                        <span class="text-xs text-gray-500 ml-2">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        name="registration_start"
                        id="registration_start"
                        value="<?php 
                            if (!empty($_POST['registration_start'])) {
                                echo htmlspecialchars($_POST['registration_start']);
                            } elseif ($isEdit && !empty($event['registration_start'])) {
                                echo date('Y-m-d H:i', strtotime($event['registration_start']));
                            }
                        ?>"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="flatpickr-input w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Anmeldebeginn wählen"
                    >
                </div>

                <!-- Registration End Time -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Anmeldung Ende
                        <span class="text-xs text-gray-500 ml-2">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        name="registration_end"
                        id="registration_end"
                        value="<?php 
                            if (!empty($_POST['registration_end'])) {
                                echo htmlspecialchars($_POST['registration_end']);
                            } elseif ($isEdit && !empty($event['registration_end'])) {
                                echo date('Y-m-d H:i', strtotime($event['registration_end']));
                            }
                        ?>"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="flatpickr-input w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="Anmeldeende wählen"
                    >
                </div>

                <!-- Status Info Badge -->
                <div class="md:col-span-2">
                    <div class="p-4 bg-ibc-blue/10 border border-ibc-blue/20 rounded-xl">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-ibc-blue text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <h4 class="text-sm font-semibold text-ibc-blue">Automatischer Status</h4>
                                <p class="text-sm text-ibc-blue mt-1">
                                    Der Status wird automatisch basierend auf dem Datum gesetzt.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- External Link -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Externer Link</label>
                    <input 
                        type="url" 
                        name="external_link"
                        value="<?php echo htmlspecialchars($_POST['external_link'] ?? $event['external_link'] ?? ''); ?>"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="https://..."
                    >
                </div>

                <!-- Registration Link (External Forms) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Externe Anmeldung (Microsoft Forms Link)
                    </label>
                    <input 
                        type="url" 
                        name="registration_link"
                        value="<?php echo htmlspecialchars($_POST['registration_link'] ?? $event['registration_link'] ?? ''); ?>"
                        <?php echo $readOnly ? 'readonly' : ''; ?>
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 <?php echo $readOnly ? 'bg-gray-100' : ''; ?>"
                        placeholder="https://forms.office.com/..."
                    >
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        <i class="fas fa-info-circle mr-1"></i>
                        Wenn gesetzt, öffnet der "Anmelden" Button diesen Link statt der internen Anmeldung.
                    </p>
                </div>

                <!-- Checkboxes -->
                <div class="md:col-span-2 space-y-4">
                    <label class="flex items-center space-x-2 min-h-[44px]">
                        <input 
                            type="checkbox" 
                            name="is_external"
                            <?php 
                            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                echo isset($_POST['is_external']) ? 'checked' : '';
                            } else {
                                echo ($event['is_external'] ?? false) ? 'checked' : '';
                            }
                            ?>
                            <?php echo $readOnly ? 'disabled' : ''; ?>
                            class="w-5 h-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                        >
                        <span class="text-sm font-medium text-gray-700">Externes Event</span>
                    </label>

                    <label class="flex items-center space-x-2 min-h-[44px]">
                        <input 
                            type="checkbox" 
                            name="needs_helpers"
                            id="needs_helpers"
                            <?php 
                            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                echo isset($_POST['needs_helpers']) ? 'checked' : '';
                            } else {
                                echo ($event['needs_helpers'] ?? false) ? 'checked' : '';
                            }
                            ?>
                            <?php echo $readOnly ? 'disabled' : ''; ?>
                            class="w-5 h-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                        >
                        <span class="text-sm font-medium text-gray-700">Helfer benötigt</span>
                    </label>

                    <label class="flex items-center space-x-2 min-h-[44px]">
                        <input 
                            type="checkbox" 
                            name="is_internal_project"
                            id="is_internal_project"
                            <?php 
                            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                echo isset($_POST['is_internal_project']) ? 'checked' : '';
                            } else {
                                echo ($event['is_internal_project'] ?? false) ? 'checked' : '';
                            }
                            ?>
                            <?php echo $readOnly ? 'disabled' : ''; ?>
                            class="w-5 h-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                        >
                        <span class="text-sm font-medium text-gray-700">Internes Projekt</span>
                    </label>

                    <label id="requires_application_wrapper" class="flex items-center space-x-2 min-h-[44px] <?php
                        $showRequiresApplication = ($_SERVER['REQUEST_METHOD'] === 'POST')
                            ? isset($_POST['is_internal_project'])
                            : ($event['is_internal_project'] ?? false);
                        echo $showRequiresApplication ? '' : 'hidden';
                    ?>">
                        <input 
                            type="checkbox" 
                            name="requires_application"
                            id="requires_application"
                            <?php 
                            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                                echo isset($_POST['requires_application']) ? 'checked' : '';
                            } else {
                                echo ($event['requires_application'] ?? false) ? 'checked' : '';
                            }
                            ?>
                            <?php echo $readOnly ? 'disabled' : ''; ?>
                            class="w-5 h-5 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                        >
                        <span class="text-sm font-medium text-gray-700">Bewerbung erforderlich</span>
                    </label>

                </div>

                <!-- Visibility: Role Checkboxes -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-3">
                        Sichtbarkeit (Rollen)
                        <span class="text-xs text-gray-500 ml-2">Wenn keine Rolle ausgewählt ist, ist das Event für alle sichtbar</span>
                    </label>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <?php 
                        // Use Microsoft Entra groups if available, otherwise fall back to hardcoded roles
                        if (!empty($entraGroups)) {
                            // Display groups from Microsoft Entra
                            $allowedRoles = $_POST['allowed_roles'] ?? $event['allowed_roles'] ?? [];
                            foreach ($entraGroups as $groupIndex => $group): 
                            $groupIdSafe = 'group_' . $groupIndex;
                            ?>
                            <label for="<?php echo $groupIdSafe; ?>" class="flex items-center space-x-2 min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="<?php echo $groupIdSafe; ?>"
                                    name="allowed_roles[]"
                                    value="<?php echo htmlspecialchars($group['id']); ?>"
                                    <?php echo in_array($group['id'], $allowedRoles) ? 'checked' : ''; ?>
                                    <?php echo $readOnly ? 'disabled' : ''; ?>
                                    class="w-4 h-4 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700"><?php echo htmlspecialchars($group['displayName']); ?></span>
                            </label>
                            <?php 
                            endforeach;
                        } else {
                            // Fallback: Use AuthHandler mapping keys as role options
                            $roles = [
                                'Anwaerter' => 'Anwärter',
                                'Mitglied' => 'Mitglied',
                                'Ehrenmitglied' => 'Ehrenmitglied',
                                'Ressortleiter' => 'Ressortleiter',
                                'Alumni' => 'Alumni',
                                'Alumni_Vorstand' => 'Alumni-Vorstand',
                                'Alumni_Finanz' => 'Alumni-Finanzprüfer',
                                'board_roles' => 'Vorstand'
                            ];
                            $allowedRoles = $_POST['allowed_roles'] ?? $event['allowed_roles'] ?? [];
                            $boardEntraRoles = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'];
                            foreach ($roles as $roleValue => $roleLabel): 
                            $roleIdSafe = 'role_' . $roleValue;
                            $isChecked = ($roleValue === 'board_roles')
                                ? (!empty(array_intersect($boardEntraRoles, $allowedRoles)) || in_array('board_roles', $allowedRoles))
                                : in_array($roleValue, $allowedRoles);
                            ?>
                            <label for="<?php echo $roleIdSafe; ?>" class="flex items-center space-x-2 min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="<?php echo $roleIdSafe; ?>"
                                    name="allowed_roles[]"
                                    value="<?php echo $roleValue; ?>"
                                    <?php echo $isChecked ? 'checked' : ''; ?>
                                    <?php echo $readOnly ? 'disabled' : ''; ?>
                                    class="w-4 h-4 text-purple-600 bg-white border-gray-300 rounded focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700"><?php echo $roleLabel; ?></span>
                            </label>
                            <?php 
                            endforeach;
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Helfer-Planung -->
        <div id="tab-helpers" class="tab-content hidden">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-hands-helping text-purple-600 mr-2"></i>
                Helfer-Planung
            </h2>
            
            <div class="mb-4">
                <p class="text-sm text-gray-600">
                    Definiere die verschiedenen Helfer-Rollen und deren Zeitslots für dieses Event.
                    Jede Rolle kann mehrere Zeitslots haben, und für jeden Slot kannst Du die benötigte Anzahl an Helfern festlegen.
                </p>
                <div class="mt-3 p-3 bg-ibc-blue/5 border border-ibc-blue/15 rounded-xl flex items-start gap-2">
                    <i class="fas fa-info-circle text-ibc-blue mt-0.5 flex-shrink-0"></i>
                    <p class="text-sm text-ibc-blue">
                        <strong>Aufbau &amp; Abbau:</strong> Zeitslots dürfen auch <em>vor</em> dem Event-Start (Aufbau) oder <em>nach</em> dem Event-Ende (Abbau) liegen. Das Datum wird in diesem Fall automatisch angezeigt.
                    </p>
                </div>
            </div>

            <div id="helper-types-container" class="space-y-6">
                <!-- Helper types will be added here dynamically -->
            </div>

            <?php if (!$readOnly): ?>
            <button 
                type="button" 
                id="addHelperTypeBtn"
                class="mt-4 px-4 py-2 bg-ibc-green text-white rounded-xl hover:shadow-glow-green ease-premium inline-flex items-center"
            >
                <i class="fas fa-plus mr-2"></i>Helfer-Rolle hinzufügen
            </button>
            <?php endif; ?>
        </div>

        <!-- Form Actions -->
        <?php if (!$readOnly): ?>
        <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t">
            <a href="manage.php" class="w-full sm:flex-1 px-6 py-3 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-all duration-200 text-center no-underline font-medium inline-flex items-center justify-center">
                <i class="fas fa-times mr-2"></i>Abbrechen
            </a>
            <button type="submit" name="save_draft" class="w-full sm:flex-1 px-6 py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 text-amber-700 dark:text-amber-400 rounded-xl hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-all duration-200 font-medium inline-flex items-center justify-center">
                <i class="fas fa-file-alt mr-2"></i>Als Entwurf speichern
            </button>
            <button type="submit" name="publish_event" class="w-full sm:flex-1 btn-primary inline-flex items-center justify-center">
                <i class="fas fa-paper-plane mr-2"></i>Veröffentlichen
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- History Section -->
<?php if ($isEdit && !empty($history)): ?>
<div class="glass-card shadow-soft rounded-xl p-6 mt-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-history text-ibc-blue mr-2"></i>
        Änderungshistorie (letzte 10 Einträge)
    </h2>
    <div class="space-y-3">
        <?php foreach ($history as $entry): 
            $user = User::getById($entry['user_id']);
            $details = json_decode($entry['change_details'], true);
        ?>
        <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-xl">
            <div class="flex-shrink-0">
                <i class="fas fa-circle text-ibc-blue text-xs mt-1"></i>
            </div>
            <div class="flex-1">
                <div class="flex items-center justify-between mb-1">
                    <span class="font-medium text-gray-800">
                        <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                    </span>
                    <span class="text-xs text-gray-500">
                        <?php echo date('d.m.Y H:i', strtotime($entry['created_at'])); ?>
                    </span>
                </div>
                <div class="text-sm text-gray-600">
                    <span class="font-semibold"><?php echo htmlspecialchars($entry['change_type']); ?>:</span>
                    <?php echo htmlspecialchars($details['action'] ?? 'Änderung durchgeführt'); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Flatpickr JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/de.js"></script>

<script>
// Initialize Flatpickr for datetime inputs
document.addEventListener('DOMContentLoaded', function() {
    const flatpickrOptions = {
        enableTime: true,
        time_24hr: true,
        dateFormat: "Y-m-d H:i",
        locale: "de",
        minuteIncrement: 15,
        <?php if ($readOnly): ?>
        clickOpens: false,
        <?php endif; ?>
    };

    // Initialize start time picker
    const startTimePicker = flatpickr("#start_time", {
        ...flatpickrOptions,
        onChange: function(selectedDates, dateStr, instance) {
            // Update end time picker minDate
            if (endTimePicker) {
                endTimePicker.set('minDate', dateStr);
            }
        }
    });

    // Initialize end time picker
    const endTimePicker = flatpickr("#end_time", {
        ...flatpickrOptions,
        minDate: document.getElementById('start_time').value || 'today'
    });

    // Initialize registration start time picker
    const registrationStartPicker = flatpickr("#registration_start", {
        ...flatpickrOptions,
        onChange: function(selectedDates, dateStr, instance) {
            // Update registration end picker minDate
            if (registrationEndPicker) {
                registrationEndPicker.set('minDate', dateStr);
            }
        }
    });

    // Initialize registration end time picker
    const registrationEndPicker = flatpickr("#registration_end", {
        ...flatpickrOptions,
        minDate: document.getElementById('registration_start').value || null
    });
});

// Tab switching
document.querySelectorAll('.tab-button').forEach(button => {
    button.addEventListener('click', function() {
        const targetTab = this.getAttribute('data-tab');
        
        // Update buttons
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active', 'bg-ibc-blue', 'text-white', 'shadow-md');
            btn.classList.add('text-gray-600', 'dark:text-gray-300');
        });
        this.classList.add('active', 'bg-ibc-blue', 'text-white', 'shadow-md');
        this.classList.remove('text-gray-600', 'dark:text-gray-300');
        
        // Update content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });
        document.getElementById('tab-' + targetTab).classList.remove('hidden');
    });
});

// Show/hide helper tab based on checkbox
const needsHelpersCheckbox = document.getElementById('needs_helpers');
const helperTabButton = document.getElementById('helper-tab-button');

needsHelpersCheckbox?.addEventListener('change', function() {
    if (this.checked) {
        helperTabButton.classList.remove('hidden');
    } else {
        helperTabButton.classList.add('hidden');
        // Switch to time tab if currently on helpers tab
        if (!document.getElementById('tab-helpers').classList.contains('hidden')) {
            document.querySelector('[data-tab="time"]').click();
        }
    }
});

// Show/hide "Bewerbung erforderlich" based on "Internes Projekt"
const isInternalProjectCheckbox = document.getElementById('is_internal_project');
const requiresApplicationWrapper = document.getElementById('requires_application_wrapper');
const requiresApplicationCheckbox = document.getElementById('requires_application');

isInternalProjectCheckbox?.addEventListener('change', function() {
    if (this.checked) {
        requiresApplicationWrapper.classList.remove('hidden');
    } else {
        requiresApplicationWrapper.classList.add('hidden');
        if (requiresApplicationCheckbox) {
            requiresApplicationCheckbox.checked = false;
        }
    }
});

// ============================================================================
// HELPER SLOTS MANAGEMENT - Robust JavaScript Logic
// ============================================================================

let helperTypeIndex = 0;
let slotCounters = {}; // Track slot counters for each helper type

/**
 * Add a new helper type card
 */
function addHelperType() {
    const container = document.getElementById('helper-types-container');
    const currentIndex = helperTypeIndex++;
    slotCounters[currentIndex] = 0;
    
    const helperTypeHtml = `
        <div id="helper-type-${currentIndex}" class="helper-card p-6 rounded-xl bg-white shadow-soft" data-index="${currentIndex}">
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-lg font-bold text-gray-800">
                    <i class="fas fa-users mr-2 text-ibc-blue"></i>
                    Helfer-Rolle #${currentIndex + 1}
                </h4>
                <button 
                    type="button" 
                    class="remove-helper-type-btn text-red-600 hover:text-red-700 ease-premium px-3 py-1 rounded-xl hover:bg-red-50"
                    data-index="${currentIndex}"
                    title="Rolle entfernen"
                >
                    <i class="fas fa-trash mr-1"></i> Entfernen
                </button>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Titel der Rolle <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        class="helper-type-title w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue"
                        placeholder="z.B. Aufbau-Team, Bar-Service, Technik"
                        data-index="${currentIndex}"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung (optional)</label>
                    <input 
                        type="text" 
                        class="helper-type-description w-full px-4 py-2 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue"
                        placeholder="Kurze Beschreibung der Aufgaben"
                        data-index="${currentIndex}"
                    >
                </div>
            </div>
            
            <div class="border-t pt-4">
                <div class="flex items-center justify-between mb-3">
                    <h5 class="text-sm font-bold text-gray-700">
                        <i class="fas fa-clock mr-2 text-ibc-blue"></i>
                        Zeitslots
                    </h5>
                </div>
                <div class="slots-container space-y-3" data-type-index="${currentIndex}">
                    <!-- Slots will be added here -->
                </div>
                <button 
                    type="button" 
                    class="add-slot-btn mt-3 px-3 py-2 bg-ibc-blue text-white text-sm rounded-xl hover:bg-ibc-blue-dark ease-premium inline-flex items-center"
                    data-type-index="${currentIndex}"
                >
                    <i class="fas fa-plus mr-1"></i>Zeitslot hinzufügen
                </button>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', helperTypeHtml);
}

/**
 * Remove a helper type
 */
function removeHelperType(typeIndex) {
    if (confirm('Möchtest Du diese Helfer-Rolle wirklich entfernen? Alle Zeitslots werden ebenfalls gelöscht.')) {
        const element = document.getElementById(`helper-type-${typeIndex}`);
        if (element) {
            element.remove();
            delete slotCounters[typeIndex];
        }
    }
}

/**
 * Add a new slot to a helper type
 */
function addSlot(typeIndex) {
    const slotsContainer = document.querySelector(`[data-type-index="${typeIndex}"]`);
    if (!slotsContainer) return;
    
    const slotIndex = slotCounters[typeIndex]++;
    
    const slotHtml = `
        <div id="slot-${typeIndex}-${slotIndex}" class="slot-item grid grid-cols-1 md:grid-cols-4 gap-3 p-4 bg-gray-50 rounded-xl border border-gray-200" data-type-index="${typeIndex}" data-slot-index="${slotIndex}">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Startzeit <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    class="slot-start flatpickr-slot w-full px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue"
                    placeholder="Wählen..."
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Endzeit <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    class="slot-end flatpickr-slot w-full px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue"
                    placeholder="Wählen..."
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Anzahl Helfer <span class="text-red-500">*</span>
                </label>
                <input 
                    type="number" 
                    class="slot-quantity w-full px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-blue"
                    min="1"
                    value="1"
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div class="flex items-end">
                <button 
                    type="button" 
                    class="remove-slot-btn w-full px-3 py-2 bg-red-600 text-white text-sm rounded-xl hover:bg-red-700 ease-premium"
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                    title="Slot entfernen"
                >
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    
    slotsContainer.insertAdjacentHTML('beforeend', slotHtml);
    
    // Initialize Flatpickr for the new slot inputs
    initializeSlotFlatpickr(typeIndex, slotIndex);
}

/**
 * Initialize Flatpickr for slot time inputs
 */
function initializeSlotFlatpickr(typeIndex, slotIndex) {
    const startInput = document.querySelector(`.slot-start[data-type-index="${typeIndex}"][data-slot-index="${slotIndex}"]`);
    const endInput = document.querySelector(`.slot-end[data-type-index="${typeIndex}"][data-slot-index="${slotIndex}"]`);
    
    if (startInput && endInput) {
        const slotFlatpickrOptions = {
            enableTime: true,
            time_24hr: true,
            dateFormat: "Y-m-d H:i",
            locale: "de",
            minuteIncrement: 15,
        };
        
        const startPicker = flatpickr(startInput, {
            ...slotFlatpickrOptions,
            onChange: function(selectedDates, dateStr) {
                // Update end picker minDate
                if (endPicker) {
                    endPicker.set('minDate', dateStr);
                }
            }
        });
        
        const endPicker = flatpickr(endInput, slotFlatpickrOptions);
    }
}

/**
 * Remove a slot
 */
function removeSlot(typeIndex, slotIndex) {
    const element = document.getElementById(`slot-${typeIndex}-${slotIndex}`);
    if (element) {
        element.remove();
    }
}

/**
 * Collect and validate helper types data before form submission
 */
document.getElementById('eventForm')?.addEventListener('submit', function(e) {
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
    
    // Helper function to format dates for user-friendly display
    const formatDateTime = (dateStr) => {
        const date = new Date(dateStr);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${day}.${month}.${year} ${hours}:${minutes}`;
    };
    
    // Validate main event times
    if (startTime && endTime) {
        const startDate = new Date(startTime);
        const endDate = new Date(endTime);
        
        if (startDate >= endDate) {
            e.preventDefault();
            alert('Die Startzeit muss vor der Endzeit liegen!');
            return false;
        }
    }
    
    // Collect helper types data and validate
    const helperTypes = [];
    const helperTypeElements = document.querySelectorAll('#helper-types-container > .helper-card');
    let validationFailed = false;
    
    for (let typeDiv of helperTypeElements) {
        const typeIndex = typeDiv.getAttribute('data-index');
        const titleInput = typeDiv.querySelector(`.helper-type-title[data-index="${typeIndex}"]`);
        const descriptionInput = typeDiv.querySelector(`.helper-type-description[data-index="${typeIndex}"]`);
        
        const title = titleInput?.value.trim();
        const description = descriptionInput?.value.trim();
        
        if (!title) {
            e.preventDefault();
            alert('Bitte gib einen Titel für alle Helfer-Rollen ein!');
            titleInput?.focus();
            validationFailed = true;
            break;
        }
        
        // Collect slots for this helper type
        const slots = [];
        const slotElements = typeDiv.querySelectorAll(`.slot-item[data-type-index="${typeIndex}"]`);
        
        for (let slotDiv of slotElements) {
            const slotIndex = slotDiv.getAttribute('data-slot-index');
            const startInput = slotDiv.querySelector(`.slot-start[data-slot-index="${slotIndex}"]`);
            const endInput = slotDiv.querySelector(`.slot-end[data-slot-index="${slotIndex}"]`);
            const quantityInput = slotDiv.querySelector(`.slot-quantity[data-slot-index="${slotIndex}"]`);
            
            const slotStart = startInput?.value;
            const slotEnd = endInput?.value;
            const quantity = parseInt(quantityInput?.value) || 1;
            
            if (slotStart && slotEnd) {
                const slotStartDate = new Date(slotStart);
                const slotEndDate = new Date(slotEnd);
                
                // Validate that slot start is before slot end.
                // Slots may be before the event start (Aufbau) or after the event end (Abbau).
                if (slotStartDate >= slotEndDate) {
                    e.preventDefault();
                    alert('Slot-Startzeit muss vor der Endzeit liegen!');
                    validationFailed = true;
                    break;
                }
                
                slots.push({
                    start_time: slotStart,
                    end_time: slotEnd,
                    quantity: quantity
                });
            }
        }
        
        if (validationFailed) break;
        
        helperTypes.push({
            title: title,
            description: description,
            slots: slots
        });
    }
    
    if (validationFailed) {
        return false;
    }
    
    // Set the JSON data
    document.getElementById('helper_types_json').value = JSON.stringify(helperTypes);
    
    // Validate that helpers are configured if checkbox is checked
    if (document.getElementById('needs_helpers')?.checked) {
        if (helperTypes.length === 0) {
            e.preventDefault();
            alert('Bitte füge mindestens eine Helfer-Rolle hinzu oder deaktiviere die "Helfer benötigt" Option!');
            return false;
        }
    }
});

// Load existing helper types if editing
<?php if ($isEdit && $event['needs_helpers'] && !empty($event['helper_types'])): ?>
const existingHelperTypes = <?php echo json_encode($event['helper_types']); ?>;

window.addEventListener('DOMContentLoaded', function() {
    // Wait a bit to ensure everything is loaded
    setTimeout(function() {
        existingHelperTypes.forEach(helperType => {
            addHelperType();
            const lastType = document.querySelector('#helper-types-container > .helper-card:last-child');
            const typeIndex = lastType.getAttribute('data-index');
            
            // Set title and description
            const titleInput = lastType.querySelector(`.helper-type-title[data-index="${typeIndex}"]`);
            const descriptionInput = lastType.querySelector(`.helper-type-description[data-index="${typeIndex}"]`);
            
            if (titleInput) titleInput.value = helperType.title || '';
            if (descriptionInput) descriptionInput.value = helperType.description || '';
            
            // Add slots
            if (helperType.slots && helperType.slots.length > 0) {
                helperType.slots.forEach(slot => {
                    addSlot(typeIndex);
                    const lastSlot = lastType.querySelector(`.slot-item[data-type-index="${typeIndex}"]:last-child`);
                    const slotIndex = lastSlot.getAttribute('data-slot-index');
                    
                    // Set slot values using local time formatting
                    const startInput = lastSlot.querySelector(`.slot-start[data-slot-index="${slotIndex}"]`);
                    const endInput = lastSlot.querySelector(`.slot-end[data-slot-index="${slotIndex}"]`);
                    const quantityInput = lastSlot.querySelector(`.slot-quantity[data-slot-index="${slotIndex}"]`);
                    
                    if (startInput) {
                        const slotStart = new Date(slot.start_time);
                        // Format as local time: YYYY-MM-DD HH:mm
                        const year = slotStart.getFullYear();
                        const month = String(slotStart.getMonth() + 1).padStart(2, '0');
                        const day = String(slotStart.getDate()).padStart(2, '0');
                        const hours = String(slotStart.getHours()).padStart(2, '0');
                        const minutes = String(slotStart.getMinutes()).padStart(2, '0');
                        startInput.value = `${year}-${month}-${day} ${hours}:${minutes}`;
                        // Update flatpickr instance if it exists
                        if (startInput._flatpickr) {
                            startInput._flatpickr.setDate(slotStart);
                        }
                    }
                    
                    if (endInput) {
                        const slotEnd = new Date(slot.end_time);
                        // Format as local time: YYYY-MM-DD HH:mm
                        const year = slotEnd.getFullYear();
                        const month = String(slotEnd.getMonth() + 1).padStart(2, '0');
                        const day = String(slotEnd.getDate()).padStart(2, '0');
                        const hours = String(slotEnd.getHours()).padStart(2, '0');
                        const minutes = String(slotEnd.getMinutes()).padStart(2, '0');
                        endInput.value = `${year}-${month}-${day} ${hours}:${minutes}`;
                        // Update flatpickr instance if it exists
                        if (endInput._flatpickr) {
                            endInput._flatpickr.setDate(slotEnd);
                        }
                    }
                    
                    if (quantityInput) {
                        quantityInput.value = slot.quantity_needed || 1;
                    }
                });
            }
        });
    }, 100);
});
<?php endif; ?>

// Event delegation for dynamically added buttons
document.addEventListener('click', function(e) {
    // Add helper type button
    if (e.target.closest('#addHelperTypeBtn')) {
        e.preventDefault();
        addHelperType();
    }
    
    // Remove helper type button
    if (e.target.closest('.remove-helper-type-btn')) {
        e.preventDefault();
        const btn = e.target.closest('.remove-helper-type-btn');
        const typeIndex = btn.getAttribute('data-index');
        removeHelperType(typeIndex);
    }
    
    // Add slot button
    if (e.target.closest('.add-slot-btn')) {
        e.preventDefault();
        const btn = e.target.closest('.add-slot-btn');
        const typeIndex = btn.getAttribute('data-type-index');
        addSlot(typeIndex);
    }
    
    // Remove slot button
    if (e.target.closest('.remove-slot-btn')) {
        e.preventDefault();
        const btn = e.target.closest('.remove-slot-btn');
        const typeIndex = btn.getAttribute('data-type-index');
        const slotIndex = btn.getAttribute('data-slot-index');
        removeSlot(typeIndex, slotIndex);
    }
});

// Release lock on page unload (only if we have the lock)
<?php if ($isEdit && !$readOnly): ?>
window.addEventListener('beforeunload', function() {
    // Use sendBeacon for reliable request on page unload
    const formData = new FormData();
    formData.append('event_id', <?php echo $eventId; ?>);
    formData.append('user_id', <?php echo $_SESSION['user_id']; ?>);
    navigator.sendBeacon('/pages/events/release_lock.php', formData);
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
