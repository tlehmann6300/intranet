<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Link.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? '';
if (!Link::canManage($userRole)) {
    header('Location: index.php');
    exit;
}

$linkId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$link = null;
$isEdit = false;

if ($linkId) {
    $link = Link::getById($linkId);
    if (!$link) {
        $_SESSION['error_message'] = 'Link nicht gefunden.';
        header('Location: index.php');
        exit;
    }
    $isEdit = true;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $title      = trim($_POST['title'] ?? '');
    $url        = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon       = trim($_POST['icon'] ?? 'fas fa-link');
    $sortOrder  = 0;

    if (empty($title)) {
        $errors[] = 'Bitte geben Sie einen Titel ein.';
    }
    if (empty($url)) {
        $errors[] = 'Bitte geben Sie eine URL ein.';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Bitte geben Sie eine gültige URL ein (z.B. https://beispiel.de).';
    } else {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'])) {
            $errors[] = 'Nur http:// und https:// URLs sind erlaubt.';
        }
    }
    if (empty($icon)) {
        $icon = 'fas fa-link';
    }

    if (empty($errors)) {
        $data = [
            'title'       => $title,
            'url'         => $url,
            'description' => $description ?: null,
            'icon'        => $icon,
            'sort_order'  => $sortOrder,
        ];

        try {
            if ($isEdit) {
                Link::update($linkId, $data);
                $_SESSION['success_message'] = 'Link erfolgreich aktualisiert!';
            } else {
                $data['created_by'] = $_SESSION['user_id'];
                Link::create($data);
                $_SESSION['success_message'] = 'Link erfolgreich erstellt!';
            }
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Pre-fill form values
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $url         = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon        = trim($_POST['icon'] ?? 'fas fa-link');
    $sortOrder   = 0;
} else {
    $title       = $link['title'] ?? '';
    $url         = $link['url'] ?? '';
    $description = $link['description'] ?? '';
    $icon        = $link['icon'] ?? 'fas fa-link';
    $sortOrder   = 0;
}

$title_page = $isEdit ? 'Link bearbeiten - IBC Intranet' : 'Neuen Link erstellen - IBC Intranet';
ob_start();
?>

<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <a href="index.php" class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-ibc-green dark:hover:text-ibc-green transition-colors mb-4 no-underline">
            <i class="fas fa-arrow-left text-xs"></i>Zurück zu Nützliche Links
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 flex flex-col gap-1.5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-xl text-sm">
        <?php foreach ($errors as $error): ?>
            <div class="flex items-center gap-2"><i class="fas fa-exclamation-circle flex-shrink-0"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm p-6 sm:p-8">
        <div class="mb-6 flex items-center gap-3">
            <div class="w-11 h-11 rounded-2xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center shadow-sm flex-shrink-0">
                <i class="fas fa-<?php echo $isEdit ? 'edit' : 'plus-circle'; ?> text-ibc-green text-xl"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">
                <?php echo $isEdit ? 'Link bearbeiten' : 'Neuen Link erstellen'; ?>
            </h1>
        </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <!-- Title -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Titel *</label>
                <input
                    type="text"
                    name="title"
                    required
                    value="<?php echo htmlspecialchars($title); ?>"
                    placeholder="z.B. IBC Website"
                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-green focus:border-ibc-green dark:bg-gray-800 dark:text-gray-100"
                >
            </div>

            <!-- URL -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">URL *</label>
                <input
                    type="url"
                    name="url"
                    required
                    value="<?php echo htmlspecialchars($url); ?>"
                    placeholder="https://beispiel.de"
                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-green focus:border-ibc-green dark:bg-gray-800 dark:text-gray-100"
                >
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung <span class="text-gray-400 font-normal">(optional)</span></label>
                <input
                    type="text"
                    name="description"
                    value="<?php echo htmlspecialchars($description); ?>"
                    placeholder="Kurze Beschreibung des Links"
                    maxlength="500"
                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-ibc-green focus:border-ibc-green dark:bg-gray-800 dark:text-gray-100"
                >
            </div>

            <!-- Icon -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Icon</label>
                <input
                    type="hidden"
                    name="icon"
                    id="icon_input"
                    value="<?php echo htmlspecialchars($icon); ?>"
                >
                <!-- Icon Picker -->
                <div class="grid grid-cols-6 sm:grid-cols-8 gap-3 p-4 border border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50 dark:bg-gray-800" id="icon_picker">
                    <?php
                    $iconList = [
                        'fas fa-globe', 'fas fa-envelope', 'fas fa-file', 'fas fa-chart-bar',
                        'fas fa-users', 'fas fa-video', 'fas fa-folder', 'fas fa-book',
                        'fas fa-link', 'fas fa-home', 'fas fa-cog', 'fas fa-search',
                        'fas fa-calendar', 'fas fa-clock', 'fas fa-phone', 'fas fa-map-marker-alt',
                        'fas fa-download', 'fas fa-upload', 'fas fa-print', 'fas fa-edit',
                        'fas fa-trash', 'fas fa-star', 'fas fa-heart', 'fas fa-bell',
                        'fas fa-lock', 'fas fa-key', 'fas fa-shield-alt', 'fas fa-info-circle',
                        'fas fa-question-circle', 'fas fa-laptop', 'fas fa-database', 'fas fa-server',
                    ];
                    foreach ($iconList as $ic):
                        $isSelected = ($ic === $icon);
                    ?>
                    <button
                        type="button"
                        data-icon="<?php echo htmlspecialchars($ic); ?>"
                        title="<?php echo htmlspecialchars($ic); ?>"
                        aria-label="<?php echo htmlspecialchars($ic); ?>"
                        class="icon-picker-btn flex items-center justify-center w-10 h-10 rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:border-ibc-green hover:text-ibc-green transition <?php echo $isSelected ? 'ring-2 ring-ibc-green text-ibc-green border-ibc-green' : ''; ?>"
                    ><i class="<?php echo htmlspecialchars($ic); ?>" aria-hidden="true"></i></button>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                    Aktuell gewählt: <span id="icon_label" class="font-mono"><?php echo htmlspecialchars($icon); ?></span>
                </p>
            </div>

            <!-- Submit Buttons -->
            <div class="flex flex-col sm:flex-row justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-800">
                <a href="index.php" class="inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 rounded-xl font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition text-sm w-full sm:w-auto no-underline">
                    Abbrechen
                </a>
                <button type="submit" class="btn-primary w-full sm:w-auto justify-center">
                    <i class="fas fa-save"></i><?php echo $isEdit ? 'Änderungen speichern' : 'Link erstellen'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Icon Picker
document.querySelectorAll('.icon-picker-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var icon = this.dataset.icon;
        document.getElementById('icon_input').value = icon;
        document.getElementById('icon_label').textContent = icon;
        document.querySelectorAll('.icon-picker-btn').forEach(function(b) {
            b.classList.remove('ring-2', 'ring-ibc-green', 'text-ibc-green', 'border-ibc-green');
        });
        this.classList.add('ring-2', 'ring-ibc-green', 'text-ibc-green', 'border-ibc-green');
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
