<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/VCard.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Only Vorstand (vorstand_finanzen, vorstand_intern, vorstand_extern) and Ressortleiter (ressortleiter) may access this page
if (!Auth::check() || !Auth::canCreateBasicContent()) {
    header('Location: ../auth/login.php');
    exit;
}

$vcards   = VCard::getAll();
$csrfToken = CSRFHandler::getToken();

$title = 'vCards verwalten - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-address-card text-blue-600 mr-2"></i>
                vCards verwalten
            </h1>
            <p class="text-gray-600 dark:text-gray-400"><?php echo count($vcards); ?> Kontakte</p>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div id="toast" class="hidden fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-xl text-white text-sm font-medium transition-all duration-300">
    <i id="toast-icon" class="fas fa-check-circle text-lg"></i>
    <span id="toast-msg"></span>
</div>

<!-- vCards table -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto w-full">
        <table class="w-full text-sm text-left card-table">
            <thead>
                <tr class="bg-gray-50 dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Name</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden sm:table-cell">Funktion</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden md:table-cell">E-Mail</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden lg:table-cell">Telefon</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 text-right">Aktionen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                <?php if (empty($vcards)): ?>
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-inbox text-4xl mb-2 block"></i>
                        Keine vCards vorhanden
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vcards as $card): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100 whitespace-nowrap"
                        data-vcard-id="<?php echo (int)$card['id']; ?>" data-col="name">
                        <?php echo htmlspecialchars($card['vorname'] . ' ' . $card['nachname']); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden sm:table-cell"
                        data-vcard-id="<?php echo (int)$card['id']; ?>" data-col="funktion">
                        <?php echo htmlspecialchars($card['funktion'] ?? '—'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden md:table-cell"
                        data-vcard-id="<?php echo (int)$card['id']; ?>" data-col="email">
                        <?php echo htmlspecialchars(!empty($card['email']) ? $card['email'] : '—'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden lg:table-cell whitespace-nowrap"
                        data-vcard-id="<?php echo (int)$card['id']; ?>" data-col="telefon">
                        <?php echo htmlspecialchars($card['telefon'] ?? '—'); ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <button
                            type="button"
                            onclick="openEditModal(<?php echo (int)$card['id']; ?>,
                                <?php echo json_encode($card['vorname'] ?? ''); ?>,
                                <?php echo json_encode($card['nachname'] ?? ''); ?>,
                                <?php echo json_encode($card['funktion'] ?? ''); ?>,
                                <?php echo json_encode($card['email'] ?? ''); ?>,
                                <?php echo json_encode($card['telefon'] ?? ''); ?>,
                                <?php echo json_encode($card['linkedin'] ?? ''); ?>)"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-pen"></i>
                            <span>Bearbeiten</span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl w-full max-w-lg shadow-2xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-address-card text-blue-600 mr-2"></i>
                vCard bearbeiten
            </h2>
            <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="editForm" class="flex flex-col flex-1 min-h-0" novalidate>
            <input type="hidden" id="editId" name="id" value="">
            <input type="hidden" id="editCsrf" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="p-6 overflow-y-auto flex-1 space-y-4">
                <!-- Name row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="editVorname" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Vorname <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="editVorname"
                            name="vorname"
                            required
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                    </div>
                    <div>
                        <label for="editNachname" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Nachname <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="editNachname"
                            name="nachname"
                            required
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                    </div>
                </div>

                <!-- Funktion (read-only – not editable via API) -->
                <div>
                    <label for="editFunktion" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Funktion
                    </label>
                    <input
                        type="text"
                        id="editFunktion"
                        readonly
                        class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-900 border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 rounded-lg cursor-not-allowed"
                    >
                </div>

                <!-- Email -->
                <div>
                    <label for="editEmail" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        E-Mail
                    </label>
                    <input
                        type="email"
                        id="editEmail"
                        name="email"
                        class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="name@example.com"
                    >
                </div>

                <!-- Telefon -->
                <div>
                    <label for="editTelefon" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Telefon
                    </label>
                    <input
                        type="tel"
                        id="editTelefon"
                        name="telefon"
                        class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="+49 123 456789"
                    >
                </div>

                <!-- LinkedIn -->
                <div>
                    <label for="editLinkedin" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        LinkedIn-URL
                    </label>
                    <input
                        type="url"
                        id="editLinkedin"
                        name="linkedin"
                        class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="https://linkedin.com/in/..."
                    >
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-3">
                <button
                    type="button"
                    onclick="closeEditModal()"
                    class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition-colors font-medium">
                    Abbrechen
                </button>
                <button
                    type="submit"
                    id="editSubmitBtn"
                    class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors font-medium flex items-center justify-center gap-2">
                    <i class="fas fa-save"></i>
                    Speichern
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const VCARD_API_URL = <?php echo json_encode(asset('api/admin/update_vcard.php')); ?>;

function openEditModal(id, vorname, nachname, funktion, email, telefon, linkedin) {
    document.getElementById('editId').value       = id;
    document.getElementById('editVorname').value  = vorname;
    document.getElementById('editNachname').value = nachname;
    document.getElementById('editFunktion').value = funktion;
    document.getElementById('editEmail').value    = email;
    document.getElementById('editTelefon').value  = telefon;
    document.getElementById('editLinkedin').value = linkedin;

    const modal = document.getElementById('editModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Close modal when clicking the backdrop
document.getElementById('editModal').addEventListener('click', function (e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// Form submit via Fetch API
document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const submitBtn = document.getElementById('editSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-60', 'cursor-not-allowed');

    const formData = new FormData(this);

    try {
        const resp = await fetch(VCARD_API_URL, { method: 'POST', body: formData });
        const data = await resp.json();

        if (data.success) {
            closeEditModal();
            showToast(data.message || 'vCard erfolgreich aktualisiert', 'success');
            // Update the displayed name in the table row without a full reload
            const id    = parseInt(document.getElementById('editId').value, 10);
            const cells = document.querySelectorAll('[data-vcard-id="' + id + '"]');
            cells.forEach(function (cell) {
                const col = cell.dataset.col;
                if (col === 'name')    cell.textContent = formData.get('vorname') + ' ' + formData.get('nachname');
                if (col === 'email')   cell.textContent = formData.get('email') || '—';
                if (col === 'telefon') cell.textContent = formData.get('telefon') || '—';
            });
        } else {
            showToast(data.message || 'Fehler beim Speichern', 'error');
        }
    } catch (err) {
        showToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-60', 'cursor-not-allowed');
    }
});

function showToast(message, type = 'success') {
    const toast    = document.getElementById('toast');
    const toastMsg  = document.getElementById('toast-msg');
    const toastIcon = document.getElementById('toast-icon');

    toastMsg.textContent = message;
    toast.classList.remove('bg-green-600', 'bg-red-600');

    if (type === 'success') {
        toast.classList.add('bg-green-600');
        toastIcon.className = 'fas fa-check-circle text-lg';
    } else {
        toast.classList.add('bg-red-600');
        toastIcon.className = 'fas fa-exclamation-circle text-lg';
    }

    toast.classList.remove('hidden');
    toast.classList.add('flex');

    setTimeout(function () {
        toast.classList.add('hidden');
        toast.classList.remove('flex');
    }, 4000);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
