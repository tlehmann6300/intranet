<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/services/EasyVereinInventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Parse checkout date and quantity from the EasyVerein note field.
// assignItem writes: "⏳ [dd.mm.YYYY HH:mm] AUSGELIEHEN: Nx an <memberId>. <purpose>"
function parseCheckoutNote(string $note): array {
    $info = ['quantity' => 1, 'date' => null];
    if (preg_match('/⏳ \[(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2})\] AUSGELIEHEN: (\d+)x/', $note, $m)) {
        $info['date']     = $m[1];
        $info['quantity'] = (int)$m[2];
    }
    return $info;
}

try {
    $evi            = new EasyVereinInventory();
    $activeCheckouts = $evi->getMyAssignedItems(Auth::getUserId());
} catch (Exception $e) {
    error_log('EasyVereinInventory::getMyAssignedItems failed: ' . $e->getMessage());
    $activeCheckouts = [];
}

// Check for success messages
$successMessage = $_SESSION['rental_success'] ?? $_SESSION['checkin_success'] ?? null;
unset($_SESSION['rental_success'], $_SESSION['checkin_success']);

$errorMessage = $_SESSION['rental_error'] ?? null;
unset($_SESSION['rental_error']);

$title = 'Meine Ausleihen - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-clipboard-list text-purple-600 mr-3"></i>
                Meine Ausleihen
            </h1>
            <p class="text-slate-600 dark:text-slate-400"><?php echo count($activeCheckouts); ?> aktive Ausleihen</p>
        </div>
        <div class="flex-shrink-0">
            <a href="index.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-5 py-3 rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
                <i class="fas fa-box"></i>
                Zum Inventar
            </a>
        </div>
    </div>
</div>

<?php if ($successMessage): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-800 dark:text-green-300 rounded-2xl shadow-sm">
    <i class="fas fa-check-circle text-green-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($successMessage); ?></span>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-2xl shadow-sm">
    <i class="fas fa-exclamation-circle text-red-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($errorMessage); ?></span>
</div>
<?php endif; ?>

<!-- Active Checkouts -->
<div class="card p-6 mb-6">
    <h2 class="text-lg sm:text-xl font-bold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center flex-shrink-0">
            <i class="fas fa-hourglass-half text-blue-600 dark:text-blue-400 text-sm"></i>
        </div>
        Aktive Ausleihen
    </h2>

    <?php if (empty($activeCheckouts)): ?>
    <div class="text-center py-12">
        <div class="w-20 h-20 bg-purple-50 dark:bg-purple-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-inbox text-4xl text-purple-300 dark:text-purple-600"></i>
        </div>
        <p class="text-slate-500 dark:text-slate-400 text-lg font-medium mb-4">Keine aktiven Ausleihen</p>
        <a href="index.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
            <i class="fas fa-search"></i>Artikel ausleihen
        </a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($activeCheckouts as $item): ?>
        <?php
        $noteInfo = parseCheckoutNote($item['note'] ?? $item['description'] ?? '');
        $quantity = $noteInfo['quantity'];
        $rentedAt = $noteInfo['date'];
        $unit     = $item['unit'] ?? 'Stück';
        ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 border-t-4 border-t-green-400 flex flex-col overflow-hidden hover:shadow-md transition-shadow">
            <!-- Card Header -->
            <div class="px-5 pt-5 pb-4 flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <a href="view.php?id=<?php echo (int)$item['id']; ?>"
                       class="font-bold text-slate-900 dark:text-white text-base leading-snug line-clamp-2 hover:text-purple-600 dark:hover:text-purple-400 transition-colors"
                       title="<?php echo htmlspecialchars($item['name'] ?? ''); ?>">
                        <?php echo htmlspecialchars($item['name'] ?? ''); ?>
                    </a>
                </div>
                <span class="flex-shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full border bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border-green-200 dark:border-green-700">
                    <i class="fas fa-check-circle"></i>Aktiv
                </span>
            </div>

            <!-- Card Body -->
            <div class="px-5 pb-4 flex-1 space-y-3">
                <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                    <div class="w-7 h-7 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-cubes text-purple-500 text-xs"></i>
                    </div>
                    <span>Menge: <strong class="text-slate-900 dark:text-white"><?php echo $quantity; ?> <?php echo htmlspecialchars($unit); ?></strong></span>
                </div>
                <?php if ($rentedAt): ?>
                <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                    <div class="w-7 h-7 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-calendar-alt text-blue-500 text-xs"></i>
                    </div>
                    <span>Ausgeliehen: <strong class="text-slate-900 dark:text-white"><?php echo htmlspecialchars($rentedAt); ?></strong></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Card Footer -->
            <div class="px-5 pb-5">
                <button onclick="openReturnModal(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['name'] ?? '', ENT_QUOTES); ?>', <?php echo $quantity; ?>, '<?php echo htmlspecialchars($unit, ENT_QUOTES); ?>')"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-xl font-semibold text-sm transition-all shadow hover:shadow-md transform hover:scale-[1.02]">
                    <i class="fas fa-undo-alt"></i>Zurückgeben
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- History note -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 p-5">
    <div class="flex items-start gap-3">
        <div class="w-9 h-9 bg-gray-100 dark:bg-slate-700 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fas fa-history text-gray-500 dark:text-gray-400"></i>
        </div>
        <div>
            <h2 class="font-semibold text-slate-700 dark:text-slate-300 mb-1">Verlauf</h2>
            <p class="text-slate-500 dark:text-slate-400 text-sm">
                Der Verlauf zurückgegebener Artikel wird im EasyVerein-Logbuch der jeweiligen Artikel gespeichert.
            </p>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="bg-white dark:bg-slate-800 rounded-t-3xl sm:rounded-2xl shadow-2xl w-full sm:max-w-lg max-h-[92vh] sm:max-h-[85vh] flex flex-col overflow-hidden">
        <form method="POST" action="rental.php" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="rental_id" id="return_rental_id" value="">
            <input type="hidden" name="return_rental" value="1">
            <input type="hidden" name="return_quantity" id="return_quantity" value="">

            <!-- Modal Header -->
            <div class="bg-gradient-to-r from-green-500 to-emerald-600 px-6 py-5 flex items-center justify-between flex-shrink-0">
                <!-- Drag handle (mobile) -->
                <div class="absolute top-3 left-1/2 -translate-x-1/2 w-10 h-1.5 bg-white/40 rounded-full sm:hidden"></div>
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fas fa-undo-alt"></i>Artikel zurückgeben
                </h2>
                <button type="button" onclick="closeReturnModal()"
                        class="min-w-[44px] min-h-[44px] bg-white/20 hover:bg-white/30 rounded-xl flex items-center justify-center text-white transition-colors"
                        aria-label="Schließen">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto flex-1 min-h-0 space-y-5">

                <!-- Item Info Banner -->
                <div class="flex items-center gap-3 bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800 rounded-xl px-4 py-3">
                    <div class="w-9 h-9 bg-green-100 dark:bg-green-800/50 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-box-open text-green-600 dark:text-green-400 text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="font-bold text-slate-900 dark:text-white text-sm leading-snug" id="return_item_name"></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Menge: <span class="font-semibold text-slate-700 dark:text-slate-300" id="return_amount"></span>
                            <span id="return_unit"></span>
                        </p>
                    </div>
                </div>

                <!-- Form Fields -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2" for="return_location">
                        <i class="fas fa-map-marker-alt text-green-500 mr-1.5"></i>Ort der Rückgabe <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="text"
                        name="return_location"
                        id="return_location"
                        required
                        class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 text-slate-900 dark:text-slate-100 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                        placeholder="z.B. Lager, Büro, Konferenzraum…"
                    >
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2" for="return_comment">
                        <i class="fas fa-comment text-slate-400 mr-1.5"></i>Kommentar
                        <span class="text-xs font-normal text-slate-400 ml-1">(optional)</span>
                    </label>
                    <textarea
                        name="return_comment"
                        id="return_comment"
                        rows="3"
                        class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 text-slate-900 dark:text-slate-100 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow resize-none"
                        placeholder="Optionale Anmerkungen zur Rückgabe…"
                    ></textarea>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex gap-3 px-6 pb-6 pt-3 flex-shrink-0 border-t border-gray-100 dark:border-slate-700">
                <button type="button" onclick="closeReturnModal()"
                        class="flex-1 px-4 py-3 bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 rounded-xl font-semibold transition-colors">
                    Abbrechen
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-xl font-bold shadow-lg hover:shadow-xl transition-all transform hover:scale-[1.02] flex items-center justify-center gap-2">
                    <i class="fas fa-check"></i>Zurückgeben
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openReturnModal(itemId, itemName, quantity, unit) {
    document.getElementById('return_rental_id').value = itemId;
    document.getElementById('return_quantity').value = quantity;
    document.getElementById('return_item_name').textContent = itemName;
    document.getElementById('return_amount').textContent = quantity;
    document.getElementById('return_unit').textContent = unit;
    document.getElementById('returnModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('return_location').value = '';
    document.getElementById('return_comment').value = '';
    document.getElementById('return_quantity').value = '';
}

document.getElementById('returnModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeReturnModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReturnModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
