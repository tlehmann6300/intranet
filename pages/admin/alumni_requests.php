<?php
/**
 * Admin: Alumni-Anfragen Verwaltung
 * Displays all alumni access requests and allows approving/rejecting pending ones.
 * Access: alumni_finanz, alumni_vorstand, vorstand_finanzen, vorstand_extern, vorstand_intern
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/AlumniAccessRequest.php';
require_once __DIR__ . '/../../includes/helpers.php';

// --- Strict role check ---
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$allowedRoles = ['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    include __DIR__ . '/../../includes/templates/403.php';
    exit;
}

// --- Load data ---
$requests = AlumniAccessRequest::getAll();
$counts   = AlumniAccessRequest::countByStatus();

$csrfToken = CSRFHandler::getToken();

ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-4 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-user-graduate text-blue-600 mr-2"></i>
                Alumni-Anfragen
            </h1>
            <p class="text-gray-600 dark:text-gray-400">Verwaltung der eingehenden Alumni-Zugangsanfragen</p>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4 mt-4">
        <div class="card p-5 border-l-4 border-yellow-400">
            <p class="text-sm text-gray-500 dark:text-gray-400">Ausstehend</p>
            <p class="text-2xl sm:text-3xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo $counts['pending']; ?></p>
        </div>
        <div class="card p-5 border-l-4 border-green-500">
            <p class="text-sm text-gray-500 dark:text-gray-400">Akzeptiert</p>
            <p class="text-2xl sm:text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $counts['approved']; ?></p>
        </div>
        <div class="card p-5 border-l-4 border-red-500">
            <p class="text-sm text-gray-500 dark:text-gray-400">Abgelehnt</p>
            <p class="text-2xl sm:text-3xl font-bold text-red-600 dark:text-red-400"><?php echo $counts['rejected']; ?></p>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div id="toast" class="hidden fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-xl text-white text-sm font-medium transition-all duration-300">
    <i id="toast-icon" class="fas fa-check-circle text-lg"></i>
    <span id="toast-msg"></span>
</div>

<!-- Requests table -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto w-full has-action-dropdown">
        <table class="w-full text-sm text-left card-table">
            <thead>
                <tr class="bg-gray-50 dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Name</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">E-Mail (neu)</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden md:table-cell">E-Mail (alt)</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden lg:table-cell">Studiengang</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden lg:table-cell">Semester</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300 hidden sm:table-cell">Eingereicht</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Status</th>
                    <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Aktionen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-slate-700" id="requests-table-body">
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-inbox text-3xl mb-2 block"></i>
                        Keine Anfragen vorhanden
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($requests as $req): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/60 transition-colors" id="row-<?php echo (int)$req['id']; ?>">
                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100" data-label="Name">
                        <?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 break-all" data-label="E-Mail (neu)">
                        <?php echo htmlspecialchars($req['new_email'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-500 hidden md:table-cell break-all" data-label="E-Mail (alt)">
                        <?php if ($req['old_email']): ?>
                            <?php echo htmlspecialchars($req['old_email'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <span class="italic text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden lg:table-cell" data-label="Studiengang">
                        <?php echo htmlspecialchars($req['study_program'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden lg:table-cell" data-label="Semester">
                        <?php echo htmlspecialchars($req['graduation_semester'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-500 hidden sm:table-cell whitespace-nowrap" data-label="Eingereicht">
                        <?php echo date('d.m.Y H:i', strtotime($req['created_at'])); ?>
                    </td>
                    <td class="px-4 py-3" data-label="Status">
                        <?php
                        $statusMap = [
                            'pending'  => ['label' => 'Ausstehend', 'class' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300'],
                            'approved' => ['label' => 'Akzeptiert', 'class' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'],
                            'rejected' => ['label' => 'Abgelehnt',  'class' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'],
                        ];
                        $s = $statusMap[$req['status']] ?? ['label' => htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8'), 'class' => 'bg-gray-100 text-gray-700'];
                        ?>
                        <span class="status-badge-<?php echo (int)$req['id']; ?> px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $s['class']; ?>">
                            <?php echo $s['label']; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap" data-label="Aktionen">
                        <?php if ($req['status'] === 'pending'): ?>
                        <div class="flex gap-4 flex-wrap action-buttons-<?php echo (int)$req['id']; ?>">
                            <button
                                onclick="handleAction(<?php echo (int)$req['id']; ?>, 'approve')"
                                class="px-3 py-2 min-h-[44px] text-xs font-semibold bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white rounded-lg shadow transition-all duration-200 flex items-center gap-1"
                                title="Akzeptieren & in Entra anlegen">
                                <i class="fas fa-user-check"></i>
                                <span class="hidden sm:inline">Akzeptieren &amp; Entra</span>
                            </button>
                            <button
                                onclick="handleAction(<?php echo (int)$req['id']; ?>, 'reject')"
                                class="px-3 py-2 min-h-[44px] text-xs font-semibold bg-gradient-to-r from-red-600 to-rose-600 hover:from-red-700 hover:to-rose-700 text-white rounded-lg shadow transition-all duration-200 flex items-center gap-1"
                                title="Ablehnen">
                                <i class="fas fa-user-times"></i>
                                <span class="hidden sm:inline">Ablehnen</span>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400 dark:text-gray-600 text-xs italic">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const API_URL    = <?php echo json_encode(asset('api/process_alumni_request.php')); ?>;

const STATUS_LABELS = {
    approved: { label: 'Akzeptiert', cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
    rejected: { label: 'Abgelehnt',  cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' }
};

function showToast(message, type = 'success') {
    const toast   = document.getElementById('toast');
    const toastMsg  = document.getElementById('toast-msg');
    const toastIcon = document.getElementById('toast-icon');

    toastMsg.textContent = message;
    toast.classList.remove('bg-green-600', 'bg-yellow-500', 'bg-red-600');

    if (type === 'success') {
        toast.classList.add('bg-green-600');
        toastIcon.className = 'fas fa-check-circle text-lg';
    } else if (type === 'warning') {
        toast.classList.add('bg-yellow-500');
        toastIcon.className = 'fas fa-exclamation-triangle text-lg';
    } else {
        toast.classList.add('bg-red-600');
        toastIcon.className = 'fas fa-times-circle text-lg';
    }

    toast.classList.remove('hidden');
    toast.classList.add('flex');

    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => {
        toast.classList.add('hidden');
        toast.classList.remove('flex');
    }, 4500);
}

async function handleAction(requestId, action) {
    const label = action === 'approve' ? 'akzeptieren' : 'ablehnen';
    if (!confirm(`Anfrage wirklich ${label}?`)) return;

    // Disable buttons while processing
    const btnsEl = document.querySelector(`.action-buttons-${requestId}`);
    if (btnsEl) {
        btnsEl.querySelectorAll('button').forEach(b => {
            b.disabled = true;
            b.classList.add('opacity-60', 'cursor-not-allowed');
        });
    }

    const formData = new FormData();
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('request_id', requestId);
    formData.append('action', action);

    try {
        const resp = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await resp.json();

        if (data.success) {
            // Update status badge
            const badge = document.querySelector(`.status-badge-${requestId}`);
            if (badge) {
                const info = STATUS_LABELS[action === 'approve' ? 'approved' : 'rejected'];
                badge.textContent = info.label;
                badge.className   = `status-badge-${requestId} px-2.5 py-0.5 rounded-full text-xs font-semibold ${info.cls}`;
            }
            // Remove action buttons
            if (btnsEl) {
                btnsEl.innerHTML = '<span class="text-gray-400 dark:text-gray-600 text-xs italic">—</span>';
            }

            const toastType = data.warning ? 'warning' : 'success';
            const toastMsg  = data.warning
                ? `Gespeichert – Entra-Warnung: ${data.warning}`
                : data.message;
            showToast(toastMsg, toastType);
        } else {
            showToast(data.message || 'Fehler beim Verarbeiten der Anfrage', 'error');
            // Re-enable buttons
            if (btnsEl) {
                btnsEl.querySelectorAll('button').forEach(b => {
                    b.disabled = false;
                    b.classList.remove('opacity-60', 'cursor-not-allowed');
                });
            }
        }
    } catch (err) {
        showToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
        if (btnsEl) {
            btnsEl.querySelectorAll('button').forEach(b => {
                b.disabled = false;
                b.classList.remove('opacity-60', 'cursor-not-allowed');
            });
        }
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
