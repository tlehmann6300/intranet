<?php
/**
 * View Poll - View poll details and vote or see results
 * Access: All authenticated users (filtered by target_groups)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/poll_helpers.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? '';
// Prefer entra_roles (human-readable Graph group names); fall back to azure_roles for legacy accounts
$userAzureRoles = [];
if (!empty($user['entra_roles'])) {
    $decoded = json_decode($user['entra_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}
if (empty($userAzureRoles) && !empty($user['azure_roles'])) {
    $decoded = json_decode($user['azure_roles'], true);
    if (is_array($decoded)) {
        $userAzureRoles = $decoded;
    }
}

// Get poll ID
$pollId = $_GET['id'] ?? null;

if (!$pollId) {
    header('Location: ' . asset('pages/polls/index.php'));
    exit;
}

$db = Database::getContentDB();

// Fetch poll details
$stmt = $db->prepare("
    SELECT * FROM polls WHERE id = ? AND is_active = 1
");
$stmt->execute([$pollId]);
$poll = $stmt->fetch();

if (!$poll) {
    header('Location: ' . asset('pages/polls/index.php'));
    exit;
}

// Check if user is allowed to view this poll using the new visibility rules
if (!isPollVisibleForUser($poll, $userAzureRoles, $user['id'])) {
    header('Location: ' . asset('pages/polls/index.php?error=no_permission'));
    exit;
}

// Check if this poll uses Microsoft Forms
$hasMicrosoftFormsUrl = !empty($poll['microsoft_forms_url']);

// For backward compatibility, check if user has already voted (old system)
$userVote = null;
if (!$hasMicrosoftFormsUrl) {
    $stmt = $db->prepare("SELECT * FROM poll_votes WHERE poll_id = ? AND user_id = ?");
    $stmt->execute([$pollId, $user['id']]);
    $userVote = $stmt->fetch();
}

$successMessage = '';
$errorMessage = '';

// Handle vote submission (backward compatibility for old polls)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote']) && !$userVote && !$hasMicrosoftFormsUrl) {
    $optionId = $_POST['option_id'] ?? null;
    
    if (!$optionId) {
        $errorMessage = 'Bitte wählen Sie eine Option aus.';
    } else {
        try {
            // Verify option belongs to this poll
            $stmt = $db->prepare("SELECT * FROM poll_options WHERE id = ? AND poll_id = ?");
            $stmt->execute([$optionId, $pollId]);
            $option = $stmt->fetch();
            
            if (!$option) {
                $errorMessage = 'Ungültige Option ausgewählt.';
            } else {
                // Insert vote
                $stmt = $db->prepare("INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
                $stmt->execute([$pollId, $optionId, $user['id']]);
                
                $successMessage = 'Ihre Stimme wurde erfolgreich gespeichert!';
                
                // Refresh user vote status
                $stmt = $db->prepare("SELECT * FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $stmt->execute([$pollId, $user['id']]);
                $userVote = $stmt->fetch();
            }
        } catch (Exception $e) {
            error_log('Error submitting vote: ' . $e->getMessage());
            $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}

// Fetch poll options with vote counts (for backward compatibility)
$options = [];
$totalVotes = 0;
if (!$hasMicrosoftFormsUrl) {
    $stmt = $db->prepare("
        SELECT po.*, COUNT(pv.id) as vote_count
        FROM poll_options po
        LEFT JOIN poll_votes pv ON po.id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.id
        ORDER BY po.id ASC
    ");
    $stmt->execute([$pollId]);
    $options = $stmt->fetchAll();
    
    // Calculate total votes
    $totalVotes = array_sum(array_column($options, 'vote_count'));
}

$title = htmlspecialchars($poll['title']) . ' - Umfragen - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <a 
            href="<?php echo asset('pages/polls/index.php'); ?>"
            class="inline-block mb-4 text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300"
        >
            <i class="fas fa-arrow-left mr-2"></i>Zurück zu Umfragen
        </a>
        
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2 break-words hyphens-auto">
            <?php echo htmlspecialchars($poll['title']); ?>
        </h1>
        
        <?php if (!empty($poll['description'])): ?>
        <p class="text-gray-600 dark:text-gray-300 text-base sm:text-lg mb-4 break-words hyphens-auto">
            <?php echo nl2br(htmlspecialchars($poll['description'])); ?>
        </p>
        <?php endif; ?>
        
        <div class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-400">
            <div>
                <i class="fas fa-calendar-alt mr-1"></i>
                <?php if (!empty($poll['end_date'])): ?>
                    Endet am <?php echo formatDateTime($poll['end_date'], 'd.m.Y H:i'); ?> Uhr
                <?php else: ?>
                    Dauerhaft verfügbar
                <?php endif; ?>
            </div>
            <?php if (!$hasMicrosoftFormsUrl): ?>
            <div>
                <i class="fas fa-users mr-1"></i>
                <?php echo $totalVotes; ?> Stimme(n)
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
    <div class="mb-6 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($successMessage); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
    <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Poll Content -->
    <div class="card p-8">
        <?php if ($hasMicrosoftFormsUrl): ?>
        <!-- Microsoft Forms Iframe -->
        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-poll mr-2 text-blue-500"></i>
            Umfrage
        </h2>
        
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
            <div class="flex items-center text-blue-800 dark:text-blue-300">
                <i class="fas fa-info-circle mr-2"></i>
                <span>
                    Diese Umfrage wird über Microsoft Forms durchgeführt. Bitte füllen Sie das Formular unten aus.
                </span>
            </div>
        </div>
        
        <div class="w-full" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
            <iframe 
                src="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>" 
                frameborder="0" 
                marginwidth="0" 
                marginheight="0" 
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; min-height: 600px;"
                allowfullscreen
                webkitallowfullscreen
                mozallowfullscreen
                msallowfullscreen
            ></iframe>
        </div>
        
        <?php elseif (!$userVote): ?>
        <!-- Voting Form -->
        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-vote-yea mr-2 text-blue-500"></i>
            Ihre Stimme abgeben
        </h2>
        
        <form method="POST" class="space-y-4">
            <?php foreach ($options as $option): ?>
            <label class="flex items-start p-4 bg-gray-50 dark:bg-gray-800 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer transition-colors border-2 border-transparent hover:border-blue-500">
                <input 
                    type="radio" 
                    name="option_id" 
                    value="<?php echo $option['id']; ?>"
                    required
                    class="w-5 h-5 text-blue-600 bg-gray-100 border-gray-300 focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 mt-1"
                >
                <span class="ml-3 text-gray-700 dark:text-gray-300 flex-1">
                    <?php echo htmlspecialchars($option['option_text']); ?>
                </span>
            </label>
            <?php endforeach; ?>
            
            <div class="pt-4">
                <button 
                    type="submit"
                    name="submit_vote"
                    class="w-full px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg"
                >
                    <i class="fas fa-check-circle mr-2"></i>
                    Stimme abgeben
                </button>
            </div>
        </form>
        
        <?php else: ?>
        <!-- Results Display -->
        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
            <i class="fas fa-chart-bar mr-2 text-blue-500"></i>
            Ergebnisse
        </h2>
        
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
            <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2"></i>
            <span class="text-green-800 dark:text-green-300 font-semibold">
                Sie haben bereits abgestimmt. Vielen Dank für Ihre Teilnahme!
            </span>
        </div>
        
        <div class="space-y-4">
            <?php foreach ($options as $option): ?>
            <?php 
                $percentage = $totalVotes > 0 ? round(($option['vote_count'] / $totalVotes) * 100, 1) : 0;
                $isUserVote = ($userVote && $userVote['option_id'] == $option['id']);
            ?>
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg <?php echo $isUserVote ? 'border-2 border-blue-500' : ''; ?>">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-gray-700 dark:text-gray-300 font-medium">
                        <?php echo htmlspecialchars($option['option_text']); ?>
                        <?php if ($isUserVote): ?>
                        <span class="ml-2 px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded text-xs font-semibold">
                            Ihre Wahl
                        </span>
                        <?php endif; ?>
                    </span>
                    <span class="text-gray-600 dark:text-gray-400 font-semibold">
                        <?php echo $percentage; ?>%
                    </span>
                </div>
                
                <!-- Progress Bar -->
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
                    <div 
                        class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-500"
                        style="width: <?php echo $percentage; ?>%"
                    ></div>
                </div>
                
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    <?php echo $option['vote_count']; ?> Stimme(n)
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <div class="flex items-center text-blue-800 dark:text-blue-300">
                <i class="fas fa-info-circle mr-2"></i>
                <span>
                    Insgesamt haben <strong><?php echo $totalVotes; ?></strong> Person(en) an dieser Umfrage teilgenommen.
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
