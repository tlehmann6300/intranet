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

<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-calendar-alt text-purple-600 mr-2"></i>
                Event-Verwaltung
            </h1>
            <p class="text-gray-600 dark:text-gray-300"><?php echo count($events); ?> Event(s) gefunden</p>
        </div>
        <a href="edit.php?new=1" class="btn-primary w-full sm:w-auto">
            <i class="fas fa-plus mr-2"></i>Neues Event
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

<!-- Filter Section -->
<div class="card dark:bg-gray-800 p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-filter text-purple-600 mr-2"></i>Filter
    </h2>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                <option value="">Alle</option>
                <option value="planned" <?php echo (isset($_GET['status']) && $_GET['status'] === 'planned') ? 'selected' : ''; ?>>Geplant</option>
                <option value="open" <?php echo (isset($_GET['status']) && $_GET['status'] === 'open') ? 'selected' : ''; ?>>Offen</option>
                <option value="running" <?php echo (isset($_GET['status']) && $_GET['status'] === 'running') ? 'selected' : ''; ?>>Laufend</option>
                <option value="closed" <?php echo (isset($_GET['status']) && $_GET['status'] === 'closed') ? 'selected' : ''; ?>>Geschlossen</option>
                <option value="past" <?php echo (isset($_GET['status']) && $_GET['status'] === 'past') ? 'selected' : ''; ?>>Vergangen</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Helfer benötigt</label>
            <select name="needs_helpers" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                <option value="">Alle</option>
                <option value="1" <?php echo (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] === '1') ? 'selected' : ''; ?>>Ja</option>
                <option value="0" <?php echo (isset($_GET['needs_helpers']) && $_GET['needs_helpers'] === '0') ? 'selected' : ''; ?>>Nein</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Von Datum</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bis Datum</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>" class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
        </div>
        <div class="md:col-span-2 lg:col-span-4 flex flex-col sm:flex-row justify-end gap-2">
            <a href="manage.php" class="w-full sm:w-auto px-6 py-2 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition text-center">
                <i class="fas fa-times mr-2"></i>Zurücksetzen
            </a>
            <button type="submit" class="btn-primary w-full sm:w-auto">
                <i class="fas fa-search mr-2"></i>Filtern
            </button>
        </div>
    </form>
</div>

<!-- Events Grid -->
<?php if (empty($events)): ?>
<div class="card dark:bg-gray-800 p-12 text-center">
    <i class="fas fa-calendar-times text-gray-400 text-6xl mb-4"></i>
    <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Events gefunden</h3>
    <p class="text-gray-500 dark:text-gray-400 mb-6">Es wurden keine Events mit den ausgewählten Filtern gefunden.</p>
    <a href="edit.php?new=1" class="btn-primary w-full sm:w-auto">
        <i class="fas fa-plus mr-2"></i>Erstes Event erstellen
    </a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($events as $event): ?>
    <div class="card dark:bg-gray-800 p-6 hover:shadow-lg transition">
        <!-- Status Badge -->
        <div class="flex items-start justify-between mb-4">
            <span class="px-3 py-1 text-xs font-semibold rounded-full
                <?php 
                switch($event['status']) {
                    case 'planned': echo 'bg-blue-100 text-blue-800'; break;
                    case 'open': echo 'bg-green-100 text-green-800'; break;
                    case 'running': echo 'bg-yellow-100 text-yellow-800'; break;
                    case 'closed': echo 'bg-gray-100 text-gray-800'; break;
                    case 'past': echo 'bg-red-100 text-red-800'; break;
                }
                ?>">
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
            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                <i class="fas fa-hands-helping"></i> Helfer
            </span>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <h3 class="text-base sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <?php echo htmlspecialchars($event['title']); ?>
        </h3>

        <!-- Location and Time -->
        <div class="space-y-2 mb-4 text-sm text-gray-600 dark:text-gray-300">
            <?php if ($event['location']): ?>
            <div class="flex items-center">
                <i class="fas fa-map-marker-alt w-5 text-purple-600"></i>
                <span><?php echo htmlspecialchars($event['location']); ?></span>
            </div>
            <?php endif; ?>
            <div class="flex items-center">
                <i class="fas fa-clock w-5 text-purple-600"></i>
                <span><?php echo date('d.m.Y H:i', strtotime($event['start_time'])); ?></span>
            </div>
            <?php if ($event['is_external']): ?>
            <div class="flex items-center">
                <i class="fas fa-external-link-alt w-5 text-purple-600"></i>
                <span>Externes Event</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Helper Info -->
        <?php if ($event['needs_helpers'] && !empty($event['helper_types'])): ?>
        <div class="mb-4 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
            <div class="text-sm text-gray-700 dark:text-gray-300">
                <strong><?php echo count($event['helper_types']); ?></strong> Helfer-Typ(en)
            </div>
        </div>
        <?php endif; ?>

        <!-- Lock Status -->
        <?php 
        $lockInfo = Event::checkLock($event['id'], $_SESSION['user_id']);
        if ($lockInfo['is_locked']): 
            $lockedUser = User::getById($lockInfo['locked_by']);
        ?>
        <div class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
            <div class="flex items-center text-sm text-yellow-800 dark:text-yellow-300">
                <i class="fas fa-lock mr-2"></i>
                <span>Gesperrt von <?php echo htmlspecialchars($lockedUser['first_name'] ?? 'Benutzer'); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex space-x-2">
            <a href="edit.php?id=<?php echo $event['id']; ?>" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-center text-sm">
                <i class="fas fa-edit mr-1"></i>Bearbeiten
            </a>
            <?php if ($canAddStats && in_array($event['status'], ['closed', 'past'])): ?>
            <button
                class="add-stats-btn px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-sm"
                data-event-id="<?php echo $event['id']; ?>"
                data-event-year="<?php echo date('Y', strtotime($event['start_time'])); ?>"
                title="Statistiken nachtragen"
            >
                <i class="fas fa-chart-bar"></i>
            </button>
            <?php endif; ?>
            <button 
                class="delete-event-btn px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition text-sm"
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
            <div class="flex space-x-4">
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Kategorie</label>
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Artikelname</label>
                        <input type="text" id="statsItemName" maxlength="255"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                               placeholder="z.B. Bratwurst">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Menge</label>
                            <input type="number" id="statsQuantity" min="0" step="1" value="0"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Umsatz (€)</label>
                            <input type="number" id="statsRevenue" min="0" step="0.01"
                                   class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                   placeholder="Optional">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Jahr</label>
                        <input type="number" id="statsYear" min="2000" max="<?php echo date('Y') + 10; ?>"
                               class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    </div>
                </div>

                <!-- Donations field (Spenden) -->
                <div id="statsDonationsField" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Spendenbetrag (€)</label>
                    <input type="number" id="statsDonationsTotal" min="0" step="0.01" value="0"
                           class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-purple-500 focus:border-purple-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>

                <div id="statsError" class="hidden p-3 bg-red-100 border border-red-300 text-red-700 rounded-lg text-sm"></div>
                <div id="statsSuccess" class="hidden p-3 bg-green-100 border border-green-300 text-green-700 rounded-lg text-sm"></div>
            </div>
        </div>

        <div class="px-6 pb-6 flex space-x-4">
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
