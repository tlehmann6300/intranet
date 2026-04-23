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

// Role visibility uses the canonical IBC-Unternehmensapp role list (ROLE_MAPPING
// from config.php), NOT the full Microsoft Entra group catalogue. This keeps the
// event-visibility selector in sync with the app's built-in role system.
// NOTE: $entraGroups is kept as an empty array so the fallback-branch further
// down renders without extra changes.
$entraGroups = [];

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
            // requires_application has been removed from the Event editor.
            // Bewerbung-Workflows leben jetzt ausschliesslich im Projekt-Modul.
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
/* ============================================================================
   EVENT EDIT FORM - SCOPED CSS WITH DESIGN SYSTEM VARIABLES
   Prefix: .eved-*
   ============================================================================ */

:root {
    --eved-spacing-xs: 0.25rem;
    --eved-spacing-sm: 0.5rem;
    --eved-spacing-md: 1rem;
    --eved-spacing-lg: 1.5rem;
    --eved-spacing-xl: 2rem;
    --eved-border-radius: 0.75rem;
    --eved-transition: all 0.25s ease;
    --eved-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
    --eved-shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.dark-mode {
    --eved-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
    --eved-shadow-md: 0 4px 6px rgba(0, 0, 0, 0.3);
}

/* ========== Container & Layout ========== */
.eved-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--eved-spacing-xl);
}

.eved-header {
    margin-bottom: var(--eved-spacing-xl);
}

.eved-back-link {
    display: inline-flex;
    align-items: center;
    gap: var(--eved-spacing-sm);
    color: var(--ibc-blue);
    text-decoration: none;
    font-weight: 500;
    transition: var(--eved-transition);
    margin-bottom: var(--eved-spacing-lg);
}

.eved-back-link:hover {
    color: var(--ibc-green);
    transform: translateX(-2px);
}

/* ========== Alert Messages ========== */
.eved-alert {
    padding: var(--eved-spacing-md);
    margin-bottom: var(--eved-spacing-lg);
    border-radius: var(--eved-border-radius);
    border-left: 4px solid;
    display: flex;
    gap: var(--eved-spacing-md);
    background-color: var(--bg-card);
    color: var(--text-main);
    box-shadow: var(--eved-shadow-sm);
}

.eved-alert-icon {
    flex-shrink: 0;
    margin-top: 2px;
}

.eved-alert-content {
    flex-grow: 1;
}

.eved-alert-title {
    font-weight: 600;
    margin-bottom: var(--eved-spacing-sm);
}

.eved-alert-list {
    list-style: disc;
    list-style-position: inside;
    space-y: var(--eved-spacing-sm);
}

.eved-alert-list li {
    margin-bottom: 0.25rem;
}

.eved-alert--success {
    border-color: var(--ibc-green);
    background-color: rgba(34, 197, 94, 0.08);
}

.dark-mode .eved-alert--success {
    background-color: rgba(34, 197, 94, 0.12);
}

.eved-alert--error {
    border-color: #ef4444;
    background-color: rgba(239, 68, 68, 0.08);
}

.dark-mode .eved-alert--error {
    background-color: rgba(239, 68, 68, 0.12);
}

.eved-alert--warning {
    border-color: #f59e0b;
    background-color: rgba(245, 158, 11, 0.08);
}

.dark-mode .eved-alert--warning {
    background-color: rgba(245, 158, 11, 0.12);
}

.eved-alert--info {
    border-color: var(--ibc-blue);
    background-color: rgba(0, 102, 179, 0.08);
}

.dark-mode .eved-alert--info {
    background-color: rgba(0, 102, 179, 0.12);
}

/* ========== Card Container ========== */
.eved-card {
    background-color: var(--bg-card);
    border-radius: var(--eved-border-radius);
    padding: var(--eved-spacing-xl);
    box-shadow: var(--shadow-card);
    color: var(--text-main);
    transition: var(--eved-transition);
    border: 1px solid var(--border-color);
}

.eved-card:hover {
    box-shadow: var(--shadow-card-hover);
}

/* ========== Page Title ========== */
.eved-page-title {
    display: flex;
    align-items: center;
    gap: var(--eved-spacing-md);
    font-size: clamp(1.5rem, 5vw, 2rem);
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: var(--eved-spacing-lg);
}

.eved-page-title i {
    color: var(--ibc-blue);
}

/* ========== Tab Navigation ========== */
.eved-tab-nav {
    display: flex;
    flex-wrap: wrap;
    gap: var(--eved-spacing-sm);
    padding: var(--eved-spacing-sm);
    background-color: var(--bg-body);
    border-radius: var(--eved-border-radius);
    margin-bottom: var(--eved-spacing-lg);
    border: 1px solid var(--border-color);
}

.eved-tab-button {
    display: inline-flex;
    align-items: center;
    gap: var(--eved-spacing-sm);
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    background-color: transparent;
    color: var(--text-muted);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: var(--eved-transition);
    min-height: 44px;
    user-select: none;
}

.eved-tab-button:hover {
    background-color: var(--border-color);
    color: var(--ibc-blue);
}

.eved-tab-button:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.25);
}

.eved-tab-button.active {
    background-color: var(--ibc-blue);
    color: white;
    box-shadow: 0 2px 8px rgba(0, 102, 179, 0.25);
}

.dark-mode .eved-tab-button {
    color: var(--text-muted);
}

.dark-mode .eved-tab-button:hover {
    background-color: var(--border-color);
}

/* ========== Form Controls ========== */
.eved-form-group {
    margin-bottom: var(--eved-spacing-lg);
}

.eved-form-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--eved-spacing-md);
}

@media (min-width: 640px) {
    .eved-form-row {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 900px) {
    .eved-form-row {
        grid-template-columns: repeat(2, 1fr);
    }
}

.eved-form-row.full {
    grid-column: 1 / -1;
}

.eved-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: var(--eved-spacing-sm);
    position: relative;
}

.eved-required {
    color: #ef4444;
    margin-left: 2px;
}

.eved-label-hint {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--text-muted);
    margin-left: auto;
}

.eved-input,
.eved-textarea,
.eved-select {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 0.875rem;
    transition: var(--eved-transition);
    min-height: 44px;
    font-family: inherit;
}

.eved-input:focus,
.eved-textarea:focus,
.eved-select:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.1);
}

.eved-input:disabled,
.eved-textarea:disabled,
.eved-select:disabled {
    background-color: var(--bg-card);
    color: var(--text-muted);
    cursor: not-allowed;
    opacity: 0.65;
}

.eved-textarea {
    resize: vertical;
    min-height: 120px;
    padding-top: 0.75rem;
}

.eved-input::placeholder,
.eved-textarea::placeholder {
    color: var(--text-muted);
    opacity: 0.7;
}

.eved-help-text {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: var(--eved-spacing-sm);
    display: flex;
    align-items: center;
    gap: var(--eved-spacing-xs);
}

/* ========== Checkbox & Radio ========== */
.eved-checkbox-group {
    display: flex;
    align-items: center;
    gap: var(--eved-spacing-md);
    min-height: 44px;
    cursor: pointer;
}

.eved-checkbox-group input {
    width: 1.25rem;
    height: 1.25rem;
    min-width: 1.25rem;
    cursor: pointer;
    accent-color: var(--ibc-blue);
    border: 1px solid var(--border-color);
    border-radius: 0.25rem;
}

.eved-checkbox-group label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-main);
    cursor: pointer;
    user-select: none;
}

.eved-checkbox-group input:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.1);
}

/* ========== Image Preview ========== */
.eved-image-preview {
    margin-bottom: var(--eved-spacing-lg);
}

.eved-image-preview-container {
    max-width: 300px;
    margin-bottom: var(--eved-spacing-md);
}

.eved-image-preview-img {
    width: 100%;
    border-radius: var(--eved-border-radius);
    border: 1px solid var(--border-color);
    box-shadow: var(--eved-shadow-sm);
}

/* ========== Info Box ========== */
.eved-info-box {
    padding: var(--eved-spacing-md);
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--ibc-blue);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    display: flex;
    gap: var(--eved-spacing-md);
}

.eved-info-box-icon {
    color: var(--ibc-blue);
    flex-shrink: 0;
    margin-top: 2px;
}

.eved-info-box-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: var(--eved-spacing-xs);
}

.eved-info-box-text {
    font-size: 0.875rem;
    color: var(--text-muted);
    line-height: 1.4;
}

/* ========== Helper Card ========== */
.eved-helper-card {
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: var(--eved-border-radius);
    padding: var(--eved-spacing-lg);
    transition: var(--eved-transition);
}

.eved-helper-card:hover {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.05);
}

.eved-helper-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--eved-spacing-lg);
    padding-bottom: var(--eved-spacing-lg);
    border-bottom: 1px solid var(--border-color);
}

.eved-helper-card-title {
    display: flex;
    align-items: center;
    gap: var(--eved-spacing-md);
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
}

.eved-helper-card-title i {
    color: var(--ibc-blue);
}

.eved-remove-btn {
    padding: var(--eved-spacing-sm) var(--eved-spacing-md);
    background-color: transparent;
    border: 1px solid #ef4444;
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    color: #ef4444;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--eved-transition);
    display: inline-flex;
    align-items: center;
    gap: var(--eved-spacing-sm);
    white-space: nowrap;
    min-height: 44px;
}

.eved-remove-btn:hover {
    background-color: #ef4444;
    color: white;
}

/* ========== Slot Container ========== */
.eved-slots-container {
    display: flex;
    flex-direction: column;
    gap: var(--eved-spacing-md);
    margin-top: var(--eved-spacing-md);
}

.eved-slot-item {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--eved-spacing-md);
    padding: var(--eved-spacing-md);
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    transition: var(--eved-transition);
}

@media (min-width: 900px) {
    .eved-slot-item {
        grid-template-columns: repeat(4, 1fr);
    }
}

.eved-slot-item:hover {
    background-color: var(--bg-body);
    border-color: var(--ibc-blue);
}

.eved-slot-field {
    display: flex;
    flex-direction: column;
}

.eved-slot-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: var(--eved-spacing-xs);
}

.eved-slot-input {
    padding: 0.625rem 0.75rem;
    font-size: 0.875rem;
    border: 1px solid var(--border-color);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    background-color: var(--bg-body);
    color: var(--text-main);
    transition: var(--eved-transition);
    min-height: 40px;
}

.eved-slot-input:focus {
    outline: none;
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 2px rgba(0, 102, 179, 0.1);
}

/* ========== Action Buttons ========== */
.eved-button-group {
    display: flex;
    flex-direction: column;
    gap: var(--eved-spacing-md);
    margin-top: var(--eved-spacing-xl);
    padding-top: var(--eved-spacing-xl);
    border-top: 1px solid var(--border-color);
}

@media (min-width: 900px) {
    .eved-button-group {
        flex-direction: row;
    }
}

.eved-button {
    flex: 1;
    padding: 0.875rem 1.5rem;
    border: 1px solid var(--border-color);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--eved-transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--eved-spacing-sm);
    white-space: nowrap;
    min-height: 44px;
    text-decoration: none;
}

.eved-button:hover {
    background-color: var(--border-color);
}

.eved-button:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 102, 179, 0.25);
}

.eved-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.eved-button--primary {
    background-color: var(--ibc-blue);
    color: white;
    border-color: var(--ibc-blue);
}

.eved-button--primary:hover {
    background-color: #004c99;
    border-color: #004c99;
}

.eved-button--secondary {
    background-color: transparent;
    color: var(--text-muted);
    border-color: var(--border-color);
}

.eved-button--secondary:hover {
    background-color: var(--bg-body);
    color: var(--text-main);
}

.eved-button--draft {
    background-color: var(--bg-body);
    border-color: #f59e0b;
    color: #f59e0b;
}

.eved-button--draft:hover {
    background-color: rgba(245, 158, 11, 0.1);
}

.eved-button--danger {
    background-color: transparent;
    border-color: #ef4444;
    color: #ef4444;
}

.eved-button--danger:hover {
    background-color: #ef4444;
    color: white;
}

.eved-button--sm {
    padding: 0.5rem 1rem;
    font-size: 0.8125rem;
}

.eved-button--success {
    background-color: var(--ibc-green);
    border-color: var(--ibc-green);
    color: white;
}

.eved-button--success:hover {
    background-color: #1a9d4e;
    border-color: #1a9d4e;
}

/* ========== History Section ========== */
.eved-history {
    margin-top: var(--eved-spacing-xl);
}

.eved-history-title {
    display: flex;
    align-items: center;
    gap: var(--eved-spacing-md);
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: var(--eved-spacing-lg);
}

.eved-history-title i {
    color: var(--ibc-blue);
}

.eved-history-list {
    display: flex;
    flex-direction: column;
    gap: var(--eved-spacing-md);
}

.eved-history-item {
    display: flex;
    gap: var(--eved-spacing-md);
    padding: var(--eved-spacing-md);
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: calc(var(--eved-border-radius) - 0.125rem);
}

.eved-history-item-icon {
    flex-shrink: 0;
    color: var(--ibc-blue);
    margin-top: 2px;
}

.eved-history-item-content {
    flex-grow: 1;
}

.eved-history-item-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--eved-spacing-xs);
}

.eved-history-item-user {
    font-weight: 600;
    color: var(--text-main);
}

.eved-history-item-date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.eved-history-item-text {
    font-size: 0.875rem;
    color: var(--text-muted);
    line-height: 1.4;
}

.eved-history-item-type {
    font-weight: 600;
    color: var(--text-main);
}

/* ========== Responsive Adjustments ========== */
@media (max-width: 640px) {
    .eved-container {
        padding: var(--eved-spacing-lg);
    }

    .eved-card {
        padding: var(--eved-spacing-lg);
    }

    .eved-tab-nav {
        gap: var(--eved-spacing-xs);
    }

    .eved-tab-button {
        padding: 0.625rem 1rem;
        font-size: 0.8125rem;
    }

    .eved-slot-item {
        grid-template-columns: 1fr;
    }

    .eved-button-group {
        flex-direction: column;
    }
}

/* ========== Dark Mode Overrides ========== */
.dark-mode .eved-input,
.dark-mode .eved-textarea,
.dark-mode .eved-select {
    background-color: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-main);
}

.dark-mode .eved-slot-input {
    background-color: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-main);
}

.dark-mode .eved-button {
    background-color: var(--bg-body);
    border-color: var(--border-color);
    color: var(--text-main);
}

.dark-mode .eved-button:hover {
    background-color: var(--border-color);
}

</style>

<div class="eved-container">
    <div class="eved-header">
        <a href="manage.php" class="eved-back-link">
            <i class="fas fa-arrow-left"></i>Zurück zur Übersicht
        </a>
    </div>

    <?php if ($lockWarning): ?>
    <div class="eved-alert eved-alert--warning">
        <div class="eved-alert-icon">
            <i class="fas fa-lock"></i>
        </div>
        <div class="eved-alert-content">
            <div><?php echo $lockWarning; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($message): ?>
    <div class="eved-alert eved-alert--success">
        <div class="eved-alert-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="eved-alert-content">
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="eved-alert eved-alert--error">
        <div class="eved-alert-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="eved-alert-content">
            <div class="eved-alert-title">Fehler beim Speichern:</div>
            <ul class="eved-alert-list">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="eved-card">
        <div class="eved-page-title">
            <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus'; ?>"></i>
            <?php echo $isEdit ? 'Event bearbeiten' : 'Neues Event erstellen'; ?>
        </div>

        <!-- Tab Navigation -->
        <nav class="eved-tab-nav" aria-label="Event Tabs">
            <button
                class="eved-tab-button active"
                data-tab="basic"
                type="button"
            >
                <i class="fas fa-info-circle"></i>
                Basisdaten
            </button>
            <button
                class="eved-tab-button"
                data-tab="time"
                type="button"
            >
                <i class="fas fa-clock"></i>
                Zeit &amp; Einstellungen
            </button>
            <button
                id="helper-tab-button"
                class="eved-tab-button <?php echo (!$isEdit || !$event['needs_helpers']) ? 'hidden' : ''; ?>"
                data-tab="helpers"
                type="button"
            >
                <i class="fas fa-hands-helping"></i>
                Helfer-Planung
            </button>
        </nav>

        <form method="POST" enctype="multipart/form-data" id="eventForm" class="eved-form-group">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="helper_types_json" id="helper_types_json" value="">

            <!-- Tab 1: Basisdaten -->
            <div id="tab-basic" class="eved-tab-content">
                <h2 class="eved-page-title" style="font-size: 1.25rem; margin-bottom: var(--eved-spacing-lg);">
                    <i class="fas fa-info-circle"></i>
                    Basisdaten
                </h2>

                <div class="eved-form-row full">
                    <div class="eved-form-group">
                        <label class="eved-label">
                            Titel
                            <span class="eved-required">*</span>
                        </label>
                        <input
                            type="text"
                            name="title"
                            value="<?php echo htmlspecialchars($_POST['title'] ?? $event['title'] ?? ''); ?>"
                            required
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-input"
                            placeholder="Event-Titel"
                        >
                    </div>
                </div>

                <div class="eved-form-row full">
                    <div class="eved-form-group">
                        <label class="eved-label">Beschreibung</label>
                        <textarea
                            name="description"
                            rows="4"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-textarea"
                            placeholder="Event-Beschreibung..."
                        ><?php echo htmlspecialchars($_POST['description'] ?? $event['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="eved-form-row">
                    <div class="eved-form-group">
                        <label class="eved-label">Veranstaltungsort / Raum</label>
                        <input
                            type="text"
                            name="location"
                            value="<?php echo htmlspecialchars($_POST['location'] ?? $event['location'] ?? ''); ?>"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-input"
                            placeholder="z.B. H-1.88 Aula"
                        >
                    </div>

                    <div class="eved-form-group">
                        <label class="eved-label">
                            Google Maps Link
                            <span class="eved-label-hint">(Optional)</span>
                        </label>
                        <input
                            type="url"
                            name="maps_link"
                            value="<?php echo htmlspecialchars($_POST['maps_link'] ?? $event['maps_link'] ?? ''); ?>"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-input"
                            placeholder="https://maps.google.com/..."
                        >
                    </div>
                </div>

                <div class="eved-form-row full">
                    <div class="eved-form-group">
                        <label class="eved-label">
                            Event-Bild
                            <span class="eved-label-hint">(Optional)</span>
                        </label>

                        <?php if ($isEdit && !empty($event['image_path'])): ?>
                        <div class="eved-image-preview">
                            <div class="eved-image-preview-container">
                                <img
                                    src="<?php echo htmlspecialchars(rtrim(BASE_URL, '/') . '/' . ltrim($event['image_path'], '/')); ?>"
                                    alt="Event Bild"
                                    class="eved-image-preview-img"
                                >
                            </div>
                            <label class="eved-checkbox-group">
                                <input
                                    type="checkbox"
                                    name="delete_image"
                                    id="delete_image"
                                    value="1"
                                    <?php echo $readOnly ? 'disabled' : ''; ?>
                                >
                                <span>Bild löschen</span>
                            </label>
                        </div>
                        <?php endif; ?>

                        <input
                            type="file"
                            name="event_image"
                            accept="image/*"
                            <?php echo $readOnly ? 'disabled' : ''; ?>
                            class="eved-input"
                        >
                        <div class="eved-help-text">
                            <i class="fas fa-info-circle"></i>
                            Unterstützte Formate: JPG, PNG, GIF. Max. 5MB.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Zeit & Einstellungen -->
            <div id="tab-time" class="eved-tab-content hidden">
                <h2 class="eved-page-title" style="font-size: 1.25rem; margin-bottom: var(--eved-spacing-lg);">
                    <i class="fas fa-clock"></i>
                    Zeit & Einstellungen
                </h2>

                <div class="eved-form-row">
                    <div class="eved-form-group">
                        <label class="eved-label">
                            Startzeit
                            <span class="eved-required">*</span>
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
                            class="eved-input flatpickr-input"
                            placeholder="Datum und Uhrzeit wählen"
                        >
                    </div>

                    <div class="eved-form-group">
                        <label class="eved-label">
                            Endzeit
                            <span class="eved-required">*</span>
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
                            class="eved-input flatpickr-input"
                            placeholder="Datum und Uhrzeit wählen"
                        >
                    </div>
                </div>

                <div class="eved-form-row">
                    <div class="eved-form-group">
                        <label class="eved-label">
                            Anmeldung Start
                            <span class="eved-label-hint">(Optional)</span>
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
                            class="eved-input flatpickr-input"
                            placeholder="Anmeldebeginn wählen"
                        >
                    </div>

                    <div class="eved-form-group">
                        <label class="eved-label">
                            Anmeldung Ende
                            <span class="eved-label-hint">(Optional)</span>
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
                            class="eved-input flatpickr-input"
                            placeholder="Anmeldeende wählen"
                        >
                    </div>
                </div>

                <!-- Status Info Box -->
                <div class="eved-form-row full">
                    <div class="eved-info-box">
                        <div class="eved-info-box-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <div class="eved-info-box-title">Automatischer Status</div>
                            <div class="eved-info-box-text">
                                Der Status wird automatisch basierend auf dem Datum gesetzt.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="eved-form-row">
                    <div class="eved-form-group">
                        <label class="eved-label">Externer Link</label>
                        <input
                            type="url"
                            name="external_link"
                            value="<?php echo htmlspecialchars($_POST['external_link'] ?? $event['external_link'] ?? ''); ?>"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-input"
                            placeholder="https://..."
                        >
                    </div>

                    <div class="eved-form-group">
                        <label class="eved-label">
                            Externe Anmeldung (Microsoft Forms Link)
                        </label>
                        <input
                            type="url"
                            name="registration_link"
                            value="<?php echo htmlspecialchars($_POST['registration_link'] ?? $event['registration_link'] ?? ''); ?>"
                            <?php echo $readOnly ? 'readonly' : ''; ?>
                            class="eved-input"
                            placeholder="https://forms.office.com/..."
                        >
                        <div class="eved-help-text">
                            <i class="fas fa-info-circle"></i>
                            Wenn gesetzt, öffnet der "Anmelden" Button diesen Link statt der internen Anmeldung.
                        </div>
                    </div>
                </div>

                <!-- Checkboxes -->
                <div class="eved-form-row full">
                    <div class="eved-form-group">
                        <label class="eved-checkbox-group">
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
                            >
                            <span>Externes Event</span>
                        </label>

                        <label class="eved-checkbox-group">
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
                            >
                            <span>Helfer benötigt</span>
                        </label>

                        <label class="eved-checkbox-group">
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
                            >
                            <span>Internes Projekt</span>
                        </label>
                    </div>
                </div>

                <!-- Visibility: Role Checkboxes (IBC-Unternehmensapp roles only) -->
                <div class="eved-form-row full">
                    <div class="eved-form-group">
                        <label class="eved-label">
                            Sichtbarkeit (Rollen)
                            <span class="eved-label-hint">Wird keine Rolle ausgewählt, ist das Event für <strong>alle</strong> Mitglieder sichtbar.</span>
                        </label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--eved-spacing-md);">
                            <?php
                            // Canonical IBC-Unternehmensapp roles (subset of ROLE_MAPPING in config.php).
                            // These are the SAME roles the rest of the application uses, so the
                            // selector is guaranteed to match what users actually have.
                            // "board_roles" is a virtual shortcut that fans out to all three vorstand_* keys.
                            $ibcRoles = [
                                'anwaerter'         => 'Anwärter',
                                'mitglied'          => 'Mitglied',
                                'ressortleiter'     => 'Ressortleiter',
                                'board_roles'       => 'Vorstand (alle drei)',
                                'ehrenmitglied'     => 'Ehrenmitglied',
                                'alumni'            => 'Alumni',
                                'alumni_vorstand'   => 'Alumni-Vorstand',
                                'alumni_finanz'     => 'Alumni-Finanzprüfer',
                            ];
                            $allowedRoles = $_POST['allowed_roles'] ?? $event['allowed_roles'] ?? [];
                            $boardEntraRoles = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern'];
                            foreach ($ibcRoles as $roleValue => $roleLabel):
                                $roleIdSafe = 'role_' . $roleValue;
                                $isChecked = ($roleValue === 'board_roles')
                                    ? (!empty(array_intersect($boardEntraRoles, $allowedRoles)) || in_array('board_roles', $allowedRoles))
                                    : in_array($roleValue, $allowedRoles);
                            ?>
                                <label for="<?php echo $roleIdSafe; ?>" class="eved-checkbox-group">
                                    <input
                                        type="checkbox"
                                        id="<?php echo $roleIdSafe; ?>"
                                        name="allowed_roles[]"
                                        value="<?php echo htmlspecialchars($roleValue); ?>"
                                        <?php echo $isChecked ? 'checked' : ''; ?>
                                        <?php echo $readOnly ? 'disabled' : ''; ?>
                                    >
                                    <span><?php echo htmlspecialchars($roleLabel); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Helfer-Planung -->
            <div id="tab-helpers" class="eved-tab-content hidden">
                <h2 class="eved-page-title" style="font-size: 1.25rem; margin-bottom: var(--eved-spacing-lg);">
                    <i class="fas fa-hands-helping"></i>
                    Helfer-Planung
                </h2>

                <div class="eved-form-group">
                    <p style="color: var(--text-muted); margin-bottom: var(--eved-spacing-md); font-size: 0.875rem;">
                        Definiere die verschiedenen Helfer-Rollen und deren Zeitslots für dieses Event.
                        Jede Rolle kann mehrere Zeitslots haben, und für jeden Slot kannst Du die benötigte Anzahl an Helfern festlegen.
                    </p>
                    <div class="eved-info-box">
                        <div class="eved-info-box-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <div class="eved-info-box-title">Aufbau & Abbau</div>
                            <div class="eved-info-box-text">
                                Zeitslots dürfen auch <em>vor</em> dem Event-Start (Aufbau) oder <em>nach</em> dem Event-Ende (Abbau) liegen. Das Datum wird in diesem Fall automatisch angezeigt.
                            </div>
                        </div>
                    </div>
                </div>

                <div id="helper-types-container" style="display: flex; flex-direction: column; gap: var(--eved-spacing-lg);">
                    <!-- Helper types will be added here dynamically -->
                </div>

                <?php if (!$readOnly): ?>
                <button
                    type="button"
                    id="addHelperTypeBtn"
                    class="eved-button eved-button--success eved-button--sm"
                    style="margin-top: var(--eved-spacing-lg); align-self: flex-start;"
                >
                    <i class="fas fa-plus"></i>Helfer-Rolle hinzufügen
                </button>
                <?php endif; ?>
            </div>

            <!-- Form Actions -->
            <?php if (!$readOnly): ?>
            <div class="eved-button-group">
                <a href="manage.php" class="eved-button eved-button--secondary">
                    <i class="fas fa-times"></i>Abbrechen
                </a>
                <button type="submit" name="save_draft" class="eved-button eved-button--draft">
                    <i class="fas fa-file-alt"></i>Als Entwurf speichern
                </button>
                <button type="submit" name="publish_event" class="eved-button eved-button--primary">
                    <i class="fas fa-paper-plane"></i>Veröffentlichen
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- History Section -->
    <?php if ($isEdit && !empty($history)): ?>
    <div class="eved-card eved-history">
        <h2 class="eved-history-title">
            <i class="fas fa-history"></i>
            Änderungshistorie (letzte 10 Einträge)
        </h2>
        <div class="eved-history-list">
            <?php foreach ($history as $entry):
                $user = User::getById($entry['user_id']);
                $details = json_decode($entry['change_details'], true);
            ?>
            <div class="eved-history-item">
                <div class="eved-history-item-icon">
                    <i class="fas fa-circle"></i>
                </div>
                <div class="eved-history-item-content">
                    <div class="eved-history-item-header">
                        <span class="eved-history-item-user">
                            <?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?>
                        </span>
                        <span class="eved-history-item-date">
                            <?php echo date('d.m.Y H:i', strtotime($entry['created_at'])); ?>
                        </span>
                    </div>
                    <div class="eved-history-item-text">
                        <span class="eved-history-item-type"><?php echo htmlspecialchars($entry['change_type']); ?>:</span>
                        <?php echo htmlspecialchars($details['action'] ?? 'Änderung durchgeführt'); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

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
document.querySelectorAll('.eved-tab-button').forEach(button => {
    button.addEventListener('click', function() {
        const targetTab = this.getAttribute('data-tab');

        // Update buttons
        document.querySelectorAll('.eved-tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        this.classList.add('active');

        // Update content
        document.querySelectorAll('.eved-tab-content').forEach(content => {
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

// "Bewerbung erforderlich" was removed from the Event editor on request —
// Events use simple role-based visibility only. The Bewerbungs-Workflow
// lives now exclusively in the Projekt-Modul.

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
        <div id="helper-type-${currentIndex}" class="eved-helper-card" data-index="${currentIndex}">
            <div class="eved-helper-card-header">
                <h4 class="eved-helper-card-title">
                    <i class="fas fa-users"></i>
                    Helfer-Rolle #${currentIndex + 1}
                </h4>
                <button
                    type="button"
                    class="remove-helper-type-btn eved-remove-btn"
                    data-index="${currentIndex}"
                    title="Rolle entfernen"
                >
                    <i class="fas fa-trash"></i> Entfernen
                </button>
            </div>

            <div class="eved-form-row">
                <div class="eved-form-group">
                    <label class="eved-label">
                        Titel der Rolle
                        <span class="eved-required">*</span>
                    </label>
                    <input
                        type="text"
                        class="helper-type-title eved-input"
                        placeholder="z.B. Aufbau-Team, Bar-Service, Technik"
                        data-index="${currentIndex}"
                    >
                </div>
                <div class="eved-form-group">
                    <label class="eved-label">Beschreibung (optional)</label>
                    <input
                        type="text"
                        class="helper-type-description eved-input"
                        placeholder="Kurze Beschreibung der Aufgaben"
                        data-index="${currentIndex}"
                    >
                </div>
            </div>

            <div style="border-top: 1px solid var(--border-color); margin-top: var(--eved-spacing-lg); padding-top: var(--eved-spacing-lg);">
                <h5 style="font-size: 0.875rem; font-weight: 700; color: var(--text-main); margin-bottom: var(--eved-spacing-md); display: flex; align-items: center; gap: var(--eved-spacing-sm);">
                    <i class="fas fa-clock" style="color: var(--ibc-blue);"></i>
                    Zeitslots
                </h5>
                <div class="eved-slots-container" data-type-index="${currentIndex}">
                    <!-- Slots will be added here -->
                </div>
                <button
                    type="button"
                    class="add-slot-btn eved-button eved-button--success eved-button--sm"
                    data-type-index="${currentIndex}"
                    style="margin-top: var(--eved-spacing-md);"
                >
                    <i class="fas fa-plus"></i>Zeitslot hinzufügen
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
        <div id="slot-${typeIndex}-${slotIndex}" class="eved-slot-item" data-type-index="${typeIndex}" data-slot-index="${slotIndex}">
            <div class="eved-slot-field">
                <label class="eved-slot-label">
                    Startzeit <span class="eved-required">*</span>
                </label>
                <input
                    type="text"
                    class="slot-start flatpickr-slot eved-slot-input"
                    placeholder="Wählen..."
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div class="eved-slot-field">
                <label class="eved-slot-label">
                    Endzeit <span class="eved-required">*</span>
                </label>
                <input
                    type="text"
                    class="slot-end flatpickr-slot eved-slot-input"
                    placeholder="Wählen..."
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div class="eved-slot-field">
                <label class="eved-slot-label">
                    Anzahl Helfer <span class="eved-required">*</span>
                </label>
                <input
                    type="number"
                    class="slot-quantity eved-slot-input"
                    min="1"
                    value="1"
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                >
            </div>
            <div class="eved-slot-field" style="display: flex; align-items: flex-end;">
                <button
                    type="button"
                    class="remove-slot-btn eved-button eved-button--danger"
                    data-type-index="${typeIndex}"
                    data-slot-index="${slotIndex}"
                    title="Slot entfernen"
                    style="width: 100%; margin: 0;"
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
    const helperTypeElements = document.querySelectorAll('#helper-types-container > .eved-helper-card');
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
        const slotElements = typeDiv.querySelectorAll(`.eved-slot-item[data-type-index="${typeIndex}"]`);

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
            const lastType = document.querySelector('#helper-types-container > .eved-helper-card:last-child');
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
                    const lastSlot = lastType.querySelector(`.eved-slot-item[data-type-index="${typeIndex}"]:last-child`);
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
