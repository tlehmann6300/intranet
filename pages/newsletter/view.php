<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Newsletter.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use ZBateson\MailMimeParser\Message;

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$currentUser = Auth::user();

$newsletterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($newsletterId <= 0) {
    $_SESSION['error_message'] = 'Ungültige Newsletter-ID.';
    header('Location: index.php');
    exit;
}

$newsletter = Newsletter::getById($newsletterId);

if (!$newsletter) {
    $_SESSION['error_message'] = 'Newsletter nicht gefunden.';
    header('Location: index.php');
    exit;
}

$createdAt = isset($newsletter['created_at'])
    ? date('d.m.Y', strtotime($newsletter['created_at']))
    : '';

$uploaderName = '';
if (!empty($newsletter['first_name']) || !empty($newsletter['last_name'])) {
    $uploaderName = trim(
        htmlspecialchars($newsletter['first_name'] ?? '', ENT_QUOTES, 'UTF-8')
        . ' '
        . htmlspecialchars($newsletter['last_name'] ?? '', ENT_QUOTES, 'UTF-8')
    );
}

$fileExtension = strtoupper(pathinfo($newsletter['file_path'] ?? '', PATHINFO_EXTENSION));

// ── Parse email attachments ──────────────────────────────────────────────────
$emailAttachments = [];
$newsletterDir = realpath(__DIR__ . '/../../uploads/newsletters');
if ($newsletterDir !== false) {
    $safeBase = basename($newsletter['file_path'] ?? '');
    $fullPath = $safeBase !== '' ? realpath($newsletterDir . DIRECTORY_SEPARATOR . $safeBase) : false;
    if ($fullPath !== false && str_starts_with($fullPath, $newsletterDir . DIRECTORY_SEPARATOR)) {
        $fh = fopen($fullPath, 'r');
        if ($fh !== false) {
            try {
                $msg = Message::from($fh, true);
                $count = $msg->getAttachmentCount();
                for ($i = 0; $i < $count; $i++) {
                    $part = $msg->getAttachmentPart($i);
                    // Skip inline images that are embedded via CID references
                    $cid = $part->getContentId();
                    if ($cid !== null && $cid !== '') {
                        continue;
                    }
                    $filename = $part->getFilename();
                    if ($filename === null || $filename === '') {
                        $filename = 'Anhang_' . ($i + 1);
                    }
                    $emailAttachments[] = [
                        'index'    => $i,
                        'filename' => $filename,
                        'mime'     => $part->getContentType('application/octet-stream'),
                    ];
                }
            } catch (\Throwable $e) {
                // Non-fatal: attachment list will simply be empty
                error_log('Newsletter attachments: ' . $e->getMessage());
            }
        }
    }
}

$title = htmlspecialchars($newsletter['title'] ?? '', ENT_QUOTES, 'UTF-8') . ' – IBC Intranet';
ob_start();
?>

<div class="flex flex-col min-h-screen -mx-4 sm:-mx-6 lg:-mx-8 -my-6">

    <div class="px-4 sm:px-6 lg:px-8 py-4 border-b border-gray-100 dark:border-gray-800">
        <a href="index.php"
           class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-ibc-blue dark:hover:text-ibc-blue transition-colors">
            <i class="fas fa-arrow-left"></i>
            Zurück zum Archiv
        </a>
    </div>

    <div class="px-4 sm:px-6 lg:px-8 py-8 flex flex-col gap-6 items-center">

        <div class="w-full max-w-2xl">
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">

                <div class="bg-blue-50 dark:bg-blue-900/20 px-6 py-8 flex flex-col items-center text-center gap-3">
                    <div class="w-16 h-16 rounded-2xl bg-white dark:bg-gray-900 shadow-sm flex items-center justify-center">
                        <i class="fas fa-envelope-open-text text-ibc-blue text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-50 leading-snug break-words hyphens-auto">
                            <?php echo htmlspecialchars($newsletter['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <?php if (!empty($newsletter['month_year'])): ?>
                        <p class="mt-1 text-sm text-ibc-blue font-medium">
                            <?php echo htmlspecialchars($newsletter['month_year'], ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="px-6 py-5 flex flex-col gap-3 text-sm text-gray-600 dark:text-gray-400">

                    <?php if ($createdAt): ?>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-calendar-alt text-gray-400 text-xs"></i>
                        </div>
                        <span>Hochgeladen am <strong class="text-gray-800 dark:text-gray-200"><?php echo $createdAt; ?></strong></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($uploaderName !== ''): ?>
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user text-gray-400 text-xs"></i>
                        </div>
                        <span>Von <strong class="text-gray-800 dark:text-gray-200"><?php echo $uploaderName; ?></strong></span>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-file text-gray-400 text-xs"></i>
                        </div>
                        <span>Format: <strong class="text-gray-800 dark:text-gray-200 uppercase"><?php echo htmlspecialchars($fileExtension, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                    </div>

                </div>

                <div class="px-6 pb-6">
                    <a href="<?php echo htmlspecialchars('/api/download_newsletter.php?id=' . $newsletterId, ENT_QUOTES, 'UTF-8'); ?>"
                       class="btn-primary w-full justify-center">
                        <i class="fas fa-download"></i>
                        Newsletter herunterladen
                    </a>
                </div>

            </div>
        </div>

        <!-- ── Attachments ─────────────────────────────────────────────────── -->
        <?php if (!empty($emailAttachments)): ?>
        <div class="w-full max-w-2xl">
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">

                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2">
                    <i class="fas fa-paperclip text-ibc-blue"></i>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Anhänge</h2>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500"><?php echo count($emailAttachments); ?> <?php echo count($emailAttachments) === 1 ? 'Datei' : 'Dateien'; ?></span>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <?php foreach ($emailAttachments as $att): ?>
                    <li class="px-6 py-3 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-file text-gray-400 text-xs"></i>
                        </div>
                        <span class="flex-1 text-sm text-gray-700 dark:text-gray-300 break-all">
                            <?php echo htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <a href="<?php echo htmlspecialchars('/api/download_newsletter_attachment.php?id=' . $newsletterId . '&index=' . $att['index'], ENT_QUOTES, 'UTF-8'); ?>"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-ibc-blue hover:text-white transition-colors flex-shrink-0">
                            <i class="fas fa-download"></i>
                            Herunterladen
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── Email preview ───────────────────────────────────────────────── -->
        <div class="w-full max-w-2xl">
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm overflow-hidden">

                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-eye text-ibc-blue"></i>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Vorschau</h2>
                    </div>
                    <button id="toggle-preview"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                            aria-expanded="true">
                        <i class="fas fa-chevron-up" id="toggle-icon"></i>
                        <span id="toggle-label">Ausblenden</span>
                    </button>
                </div>

                <div id="preview-container" class="p-4">
                    <iframe
                        id="newsletter-preview"
                        src="render.php?id=<?php echo $newsletterId; ?>"
                        class="w-full border-0 block rounded-lg"
                        style="min-height:500px;"
                        sandbox="allow-same-origin"
                        title="Newsletter Vorschau">
                    </iframe>
                </div>

            </div>
        </div>

    </div>

</div>

<script>
(function () {
    var iframe    = document.getElementById('newsletter-preview');
    var container = document.getElementById('preview-container');
    var btn       = document.getElementById('toggle-preview');
    var icon      = document.getElementById('toggle-icon');
    var label     = document.getElementById('toggle-label');

    // Auto-resize the iframe to fit its content once it has loaded.
    if (iframe) {
        iframe.addEventListener('load', function () {
            try {
                var h = this.contentDocument.documentElement.scrollHeight;
                if (h > 0) {
                    this.style.height = h + 'px';
                }
            } catch (e) {
                // Same-origin restriction not met – keep the default min-height.
            }
        });
    }

    // Toggle preview visibility.
    if (btn && container) {
        btn.addEventListener('click', function () {
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                container.style.display = 'none';
                btn.setAttribute('aria-expanded', 'false');
                icon.className  = 'fas fa-chevron-down';
                label.textContent = 'Anzeigen';
            } else {
                container.style.display = '';
                btn.setAttribute('aria-expanded', 'true');
                icon.className  = 'fas fa-chevron-up';
                label.textContent = 'Ausblenden';
            }
        });
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
