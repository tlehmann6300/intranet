<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/models/User.php';

// Only board, alumni_vorstand, resortleiter, and those with manage_projects permission can access
if (!Auth::check() || !(Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand']))) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $eventId = intval($_POST['event_id'] ?? 0);
    
    try {
        Event::delete($eventId, $_SESSION['user_id']);
        $message = 'Event erfolgreich gelöscht';
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Get filters
$filters = [];
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] !== '') {
    $filters['needs_helpers'] = $_GET['needs_helpers'] == '1';
}
if (!empty($_GET['start_date'])) {
    $filters['start_date'] = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    $filters['end_date'] = $_GET['end_date'];
}
$filters['include_helpers'] = true;

// Get events
$userRole = $_SESSION['user_role'] ?? 'mitglied';
$events = Event::getEvents($filters, $userRole);

// Check if user has permission to add financial statistics
$canAddStats = in_array($userRole, array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']));

$title = 'Event-Verwaltung - IBC Intranet';
ob_start();
?>

<style>
@keyframes emg-fadeIn {
    from {
        opacity: 0;
        transform: translateY(16px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.emg-header-section {
    margin-bottom: 2rem;
    animation: emg-fadeIn 0.5s ease;
}

.emg-header-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 640px) {
    .emg-header-wrapper {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}

.emg-header-content h1 {
    font-size: clamp(1.5rem, 4vw, 2rem);
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.emg-header-content h1 i {
    color: #9333ea;
}

.emg-header-content p {
    color: var(--text-muted);
    font-size: 0.95rem;
}

.emg-button-new {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    background-color: #9333ea;
    color: white;
    border: none;
    border-radius: 0.5rem;
    text-decoration: none;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    min-height: 44px;
    font-size: 0.95rem;
}

.emg-button-new:hover {
    background-color: #a855f7;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(147, 51, 234, 0.3);
}

.emg-button-new i {
    margin-right: 0.5rem;
}

.emg-alert {
    margin-bottom: 1.5rem;
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    animation: emg-fadeIn 0.3s ease;
}

.emg-alert i {
    margin-top: 0.125rem;
    flex-shrink: 0;
}

.emg-alert-success {
    background-color: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

.dark-mode .emg-alert-success {
    background-color: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.3);
    color: #86efac;
}

.emg-alert-error {
    background-color: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.dark-mode .emg-alert-error {
    background-color: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
    color: #fca5a5;
}

.emg-filter-card {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    animation: emg-fadeIn 0.5s ease;
}

.emg-filter-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.emg-filter-title i {
    color: #9333ea;
}

.emg-filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

@media (max-width: 900px) {
    .emg-filter-form {
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    }
}

@media (max-width: 640px) {
    .emg-filter-form {
        grid-template-columns: 1fr;
    }
}

.emg-filter-group {
    display: flex;
    flex-direction: column;
}

.emg-filter-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.emg-filter-input,
.emg-filter-select {
    padding: 0.75rem;
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    color: var(--text-main);
    font-size: 0.9rem;
    transition: all 0.3s ease;
    min-height: 44px;
}

.emg-filter-input:focus,
.emg-filter-select:focus {
    outline: none;
    border-color: #9333ea;
    box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1);
}

.emg-filter-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    flex-wrap: wrap;
}

@media (max-width: 900px) {
    .emg-filter-actions {
        grid-column: 1 / -1;
        justify-content: stretch;
    }
}

.emg-button-secondary {
    padding: 0.75rem 1.5rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s ease;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    gap: 0.5rem;
}

.emg-button-secondary:hover {
    background-color: var(--border-color);
    border-color: var(--text-muted);
}

.emg-empty-state {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 3rem;
    text-align: center;
    animation: emg-fadeIn 0.5s ease;
}

.emg-empty-state i {
    font-size: 3.75rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
    opacity: 0.5;
}

.emg-empty-state h3 {
    font-size: clamp(1rem, 3vw, 1.25rem);
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.emg-empty-state p {
    color: var(--text-muted);
    margin-bottom: 1.5rem;
}

.emg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: clamp(0.75rem, 2vw, 1.5rem);
}

@media (max-width: 900px) {
    .emg-grid {
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }
}

@media (max-width: 640px) {
    .emg-grid {
        grid-template-columns: 1fr;
    }
}

.emg-event-card {
    background-color: var(--bg-card);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    transition: all 0.3s ease;
    animation: emg-fadeIn 0.5s ease;
}

.emg-event-card:hover {
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-4px);
    border-color: #9333ea;
}

.emg-event-badges {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.emg-status-badge {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: inline-block;
}

.emg-status-planned {
    background-color: #dbeafe;
    color: #1e3a8a;
}

.dark-mode .emg-status-planned {
    background-color: rgba(59, 130, 246, 0.1);
    color: #93c5fd;
}

.emg-status-open {
    background-color: #dcfce7;
    color: #166534;
}

.dark-mode .emg-status-open {
    background-color: rgba(34, 197, 94, 0.1);
    color: #86efac;
}

.emg-status-running {
    background-color: #fef3c7;
    color: #92400e;
}

.dark-mode .emg-status-running {
    background-color: rgba(250, 204, 21, 0.1);
    color: #fcd34d;
}

.emg-status-closed {
    background-color: #f3f4f6;
    color: #374151;
}

.dark-mode .emg-status-closed {
    background-color: rgba(107, 114, 128, 0.1);
    color: #d1d5db;
}

.emg-status-past {
    background-color: #fee2e2;
    color: #991b1b;
}

.dark-mode .emg-status-past {
    background-color: rgba(239, 68, 68, 0.1);
    color: #fca5a5;
}

.emg-helpers-badge {
    background-color: #f3e8ff;
    color: #6b21a8;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.dark-mode .emg-helpers-badge {
    background-color: rgba(168, 85, 247, 0.1);
    color: #e9d5ff;
}

.emg-event-title {
    font-size: clamp(1rem, 3vw, 1.25rem);
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1rem;
    line-height: 1.3;
}

.emg-event-meta {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.875rem;
    color: var(--text-muted);
}

.emg-event-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.emg-event-meta-item i {
    width: 1.25rem;
    color: #9333ea;
    text-align: center;
}

.emg-event-helpers {
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 0.75rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: var(--text-main);
}

.emg-event-helpers strong {
    color: #9333ea;
}

.emg-event-lock {
    background-color: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 0.5rem;
    padding: 0.75rem;
    margin-bottom: 1rem;
    font-size: 0.85rem;
    color: #92400e;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.dark-mode .emg-event-lock {
    background-color: rgba(250, 204, 21, 0.1);
    border-color: rgba(250, 204, 21, 0.3);
    color: #fcd34d;
}

.emg-event-lock i {
    flex-shrink: 0;
}

.emg-event-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: auto;
    flex-wrap: wrap;
}

.emg-button-action {
    flex: 1;
    min-width: 100px;
    padding: 0.75rem;
    border: none;
    border-radius: 0.5rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    font-size: 0.8rem;
    min-height: 44px;
}

.emg-button-edit {
    background-color: #9333ea;
    color: white;
}

.emg-button-edit:hover {
    background-color: #a855f7;
}

.emg-button-stats {
    background-color: #4f46e5;
    color: white;
}

.emg-button-stats:hover {
    background-color: #6366f1;
}

.emg-button-delete {
    background-color: #ef4444;
    color: white;
}

.emg-button-delete:hover {
    background-color: #f87171;
}

.emg-button-action i {
    font-size: 0.9rem;
}

@media (max-width: 640px) {
    .emg-filter-actions {
        grid-column: 1 / -1;
    }

    .emg-event-card {
        padding: 1rem;
    }

    .emg-event-title {
        font-size: 1rem;
    }

    .emg-event-actions {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }
}
</style>

<div class="emg-header-section">
    <div class="emg-header-wrapper">
        <div class="emg-header-content">
            <h1>
                <i class="fas fa-calendar-alt"></i>
                Event-Verwaltung
            </h1>
            <p><?php echo count($events); ?> Event(s) gefunden</p>
        </div>
        <a href="edit.php?new=1" class="emg-button-new">
            <i class="fas fa-plus"></i>Neues Event
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="emg-alert emg-alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="emg-alert emg-alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="emg-filter-card">
    <h2 class="emg-filter-title">
        <i class="fas fa-filter"></i>Filter
    </h2>
    <form method="GET" class="emg-filter-form">
        <div class="emg-filter-group">
            <label class="emg-filter-label">Status</label>
            <select name="status" class="emg-filter-select">
                <option value="">Alle</option>
                <option value="planned" <?php echo (isset($_GET['status']) && $_GET['status'] === 'planned') ? 'selected' : ''; ?>>Geplant</option>
                <option value="open" <?php echo (isset($_GET['status']) && $_GET['status'] === 'open') ? 'selected' : ''; ?>>Offen</option>
                <option value="running" <?php echo (isset($_GET['status']) && $_GET['status'] === 'running') ? 'selected' : ''; ?>>Laufend</option>
                <option value="closed" <?php echo (isset($_GET['status']) && $_GET['status'] === 'closed') ? 'selected' : ''; ?>>Geschlossen</option>
                <option value="past" <?php echo (isset($_GET['status']) && $_GET['status'] === 'past') ? 'selected' : ''; ?>>Vergangen</option>
            </select>
        </div>
        <div class="emg-filter-group">
            <label class="emg-filter-label">Helfer benötigt</label>
            <select name="needs_helpers" class="emg-filter-select">
                <option value="">Alle</option>
                <option value="1" <?php echo (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] === '1') ? 'selected' : ''; ?>>Ja</option>
                <option value="0" <?php echo (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] === '0') ? 'selected' : ''; ?>>Nein</option>
            </select>
        </div>
        <div class="emg-filter-group">
            <label class="emg-filter-label">Von Datum</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>" class="emg-filter-input">
        </div>
        <div class="emg-filter-group">
            <label class="emg-filter-label">Bis Datum</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>" class="emg-filter-input">
        </div>
        <div class="emg-filter-group" style="grid-column: 1 / -1;">
            <div class="emg-filter-actions">
                <a href="manage.php" class="emg-button-secondary">
                    <i class="fas fa-times"></i>Zurücksetzen
                </a>
                <button type="submit" class="emg-button-new">
                    <i class="fas fa-search"></i>Filtern
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Events Grid -->
<?php if (empty($events)): ?>
<div class="emg-empty-state">
    <i class="fas fa-calendar-times"></i>
    <h3>Keine Events gefunden</h3>
    <p>Es wurden keine Events mit den ausgewählten Filtern gefunden.</p>
    <a href="edit.php?new=1" class="emg-button-new">
        <i class="fas fa-plus"></i>Erstes Event erstellen
    </a>
</div>
<?php else: ?>
<div class="emg-grid">
    <?php foreach ($events as $event): ?>
    <div class="emg-event-card">
        <!-- Status Badges -->
        <div class="emg-event-badges">
            <span class="emg-status-badge emg-status-<?php echo htmlspecialchars($event['status']); ?>">
                <?php
                switch($event['status']) {
                    case 'planned': echo 'Geplant'; break;
                    case 'open': echo 'Offen'; break;
                    case 'running': echo 'Laufend'; break;
                    case 'closed': echo 'Geschlossen'; break;
                    case 'past': echo 'Vergangen'; break;
                }
                ?>
            </span>
            <?php if ($event['needs_helpers']): ?>
            <span class="emg-helpers-badge">
                <i class="fas fa-hands-helping"></i> Helfer
            </span>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <h3 class="emg-event-title">
            <?php echo htmlspecialchars($event['title']); ?>
        </h3>

        <!-- Location and Time -->
        <div class="emg-event-meta">
            <?php if ($event['location']): ?>
            <div class="emg-event-meta-item">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php echo htmlspecialchars($event['location']); ?></span>
            </div>
            <?php endif; ?>
            <div class="emg-event-meta-item">
                <i class="fas fa-clock"></i>
                <span><?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?></span>
            </div>
            <?php if ($event['is_external']): ?>
            <div class="emg-event-meta-item">
                <i class="fas fa-external-link-alt"></i>
                <span>Externes Event</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Helper Info -->
        <?php if ($event['needs_helpers'] && !empty($event['helper_types'])): ?>
        <div class="emg-event-helpers">
            <strong><?php echo count($event['helper_types']); ?></strong> Helfer-Typ(en)
        </div>
        <?php endif; ?>

        <!-- Lock Status -->
        <?php
        $lockInfo = Event::checkLock($event['id'], $_SESSION['user_id']);
        if ($lockInfo['is_locked']):
            $lockedUser = User::getById($lockInfo['locked_by']);
        ?>
        <div class="emg-event-lock">
            <i class="fas fa-lock"></i>
            <span>Gesperrt von <?php echo htmlspecialchars($lockedUser['first_name'] ?? 'Benutzer'); ?></span>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="emg-event-actions">
            <a href="edit.php?id=<?php echo $event['id']; ?>" class="emg-button-action emg-button-edit">
                <i class="fas fa-edit"></i><span>Bearbeiten</span>
            </a>
            <?php if ($canAddStats && in_array($event['status'], ['closed', 'past'])): ?>
            <button
                class="add-stats-btn emg-button-action emg-button-stats"
                data-event-id="<?php echo $event['id']; ?>"
                data-event-year="<?php echo date('Y', strtotime($event['start_time'])); ?>"
                title="Statistiken nachtragen"
            >
                <i class="fas fa-chart-bar"></i>
            </button>
            <?php endif; ?>
            <button
                class="delete-event-btn emg-button-action emg-button-delete"
                data-event-id="<?php echo $event['id']; ?>"
                data-event-name="<?php echo htmlspecialchars($event['title']); ?>"
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
                Event löschen
            </h3>
            <p class="text-gray-600 dark:text-gray-300">
                Möchtest Du das Event "<span id="deleteEventName" class="font-semibold"></span>" wirklich löschen? 
                Diese Aktion kann nicht rückgängig gemacht werden.
            </p>
        </div>
        <form method="POST" id="deleteForm" class="px-6 pb-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="event_id" id="deleteEventId" value="">
            <input type="hidden" name="delete_event" value="1">
            <div class="flex flex-col md:flex-row gap-4">
                <button type="button" id="closeDeleteModalBtn" class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-trash mr-2"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($canAddStats): ?>
<!-- Add Financial Stats Modal -->
<div id="addStatsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-chart-bar text-purple-600 mr-2"></i>
                Statistiken nachtragen
            </h3>

            <div class="space-y-4">
                <!-- Category -->
                <div>
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Kategorie</label>
                    <select id="statsCategory" onchange="onStatsCategoryChange()"
                            class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="Verkauf">Verkauf</option>
                        <option value="Kalkulation">Kalkulation</option>
                        <option value="Spenden">Spenden</option>
                    </select>
                </div>

                <!-- Item-based fields (Verkauf / Kalkulation) -->
                <div id="statsItemFields" class="space-y-4">
                    <div>
                        <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Artikelname</label>
                        <input type="text" id="statsItemName" maxlength="255"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                               placeholder="z.B. Bratwurst">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                        <div>
                            <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Menge</label>
                            <input type="number" id="statsQuantity" min="0" step="1" value="0"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        </div>
                        <div>
                            <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Umsatz (€)</label>
                            <input type="number" id="statsRevenue" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                   placeholder="Optional">
                        </div>
                    </div>
                    <div>
                        <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Jahr</label>
                        <input type="number" id="statsYear" min="2000" max="<?php echo date('Y') + 10; ?>"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    </div>
                </div>

                <!-- Donations field (Spenden) -->
                <div id="statsDonationsField" class="hidden">
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Spendenbetrag (€)</label>
                    <input type="number" id="statsDonationsTotal" min="0" step="0.01" value="0"
                           class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>

                <div id="statsError" class="hidden p-3 bg-red-100 border border-red-300 text-red-700 rounded-lg text-sm"></div>
                <div id="statsSuccess" class="hidden p-3 bg-green-100 border border-green-300 text-green-700 rounded-lg text-sm"></div>
            </div>
        </div>

        <div class="px-6 pb-6 flex flex-col md:flex-row gap-4">
            <button type="button" id="closeAddStatsModalBtn"
                    class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                Abbrechen
            </button>
            <button type="button" onclick="submitAddStats()"
                    class="flex-1 px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                <i class="fas fa-save mr-2"></i>Speichern
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

// Delete button event listeners using data attributes
document.querySelectorAll('.delete-event-btn').forEach(button => {
    button.addEventListener('click', function() {
        const eventId = this.getAttribute('data-event-id');
        const eventName = this.getAttribute('data-event-name');
        confirmDelete(eventId, eventName);
    });
});

function confirmDelete(eventId, eventName) {
    const deleteEventId = document.getElementById('deleteEventId');
    const deleteEventName = document.getElementById('deleteEventName');
    const deleteModal = document.getElementById('deleteModal');
    
    if (deleteEventId) deleteEventId.value = eventId;
    if (deleteEventName) deleteEventName.textContent = eventName;
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
        closeAddStatsModal();
    }
});

// Close modal when clicking outside
document.getElementById('deleteModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'deleteModal') {
        closeDeleteModal();
    }
});

<?php if ($canAddStats): ?>
// ── Add Financial Stats Modal ──────────────────────────────────────────────────

let currentStatsEventId = null;

document.querySelectorAll('.add-stats-btn').forEach(button => {
    button.addEventListener('click', function() {
        const eventId = this.getAttribute('data-event-id');
        const eventYear = this.getAttribute('data-event-year');
        openAddStatsModal(eventId, eventYear);
    });
});

function openAddStatsModal(eventId, eventYear) {
    currentStatsEventId = parseInt(eventId);
    const yearInput = document.getElementById('statsYear');
    if (yearInput) yearInput.value = eventYear || new Date().getFullYear();
    document.getElementById('statsCategory').value = 'Verkauf';
    onStatsCategoryChange();
    document.getElementById('statsItemName').value = '';
    document.getElementById('statsQuantity').value = '0';
    document.getElementById('statsRevenue').value = '';
    document.getElementById('statsDonationsTotal').value = '0';
    document.getElementById('statsError').classList.add('hidden');
    document.getElementById('statsSuccess').classList.add('hidden');
    document.getElementById('addStatsModal').classList.remove('hidden');
}

function closeAddStatsModal() {
    document.getElementById('addStatsModal').classList.add('hidden');
    currentStatsEventId = null;
}

function onStatsCategoryChange() {
    const category = document.getElementById('statsCategory').value;
    const itemFields = document.getElementById('statsItemFields');
    const donationsField = document.getElementById('statsDonationsField');
    if (category === 'Spenden') {
        itemFields.classList.add('hidden');
        donationsField.classList.remove('hidden');
    } else {
        itemFields.classList.remove('hidden');
        donationsField.classList.add('hidden');
    }
}

function submitAddStats() {
    const category = document.getElementById('statsCategory').value;
    const errorDiv = document.getElementById('statsError');
    const successDiv = document.getElementById('statsSuccess');
    errorDiv.classList.add('hidden');
    successDiv.classList.add('hidden');

    let payload = {
        event_id: currentStatsEventId,
        csrf_token: csrfToken
    };

    if (category === 'Spenden') {
        const donationsTotalRaw = document.getElementById('statsDonationsTotal').value;
        const donationsTotal = parseFloat(donationsTotalRaw);
        if (donationsTotalRaw === '' || isNaN(donationsTotal) || donationsTotal < 0) {
            errorDiv.textContent = 'Bitte einen gültigen Spendenbetrag (>= 0) eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }
        payload.donations_total = donationsTotal;
    } else {
        const itemName = document.getElementById('statsItemName').value.trim();
        const quantityRaw = document.getElementById('statsQuantity').value;
        const quantity = parseInt(quantityRaw);
        const revenue = document.getElementById('statsRevenue').value;
        const year = document.getElementById('statsYear').value;

        if (!itemName) {
            errorDiv.textContent = 'Bitte einen Artikelnamen eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }
        if (quantityRaw === '' || isNaN(quantity) || quantity < 0) {
            errorDiv.textContent = 'Bitte eine gültige Menge (>= 0) eingeben.';
            errorDiv.classList.remove('hidden');
            return;
        }

        payload.category = category;
        payload.item_name = itemName;
        payload.quantity = quantity;
        const revenueNum = parseFloat(revenue);
        payload.revenue = revenue !== '' && !isNaN(revenueNum) ? revenueNum : null;
        payload.record_year = parseInt(year);
    }

    fetch('../../api/save_financial_stats.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = data.message || 'Statistik erfolgreich gespeichert';
            successDiv.classList.remove('hidden');
            setTimeout(() => closeAddStatsModal(), 1500);
        } else {
            errorDiv.textContent = data.message || 'Fehler beim Speichern';
            errorDiv.classList.remove('hidden');
        }
    })
    .catch(() => {
        errorDiv.textContent = 'Netzwerkfehler';
        errorDiv.classList.remove('hidden');
    });
}

document.getElementById('closeAddStatsModalBtn')?.addEventListener('click', closeAddStatsModal);

document.getElementById('addStatsModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'addStatsModal') closeAddStatsModal();
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
