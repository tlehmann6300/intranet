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

<style>
.pv-container {
    max-width: 56rem;
    margin-left: auto;
    margin-right: auto;
    animation: springFadeIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes springFadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.pv-back-link {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    margin-bottom: 1.5rem;
    transition: color 0.2s;
}

.pv-back-link:hover {
    color: var(--ibc-green);
}

.pv-header {
    margin-bottom: 2rem;
}

.pv-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    word-break: break-word;
    line-height: 1.3;
}

@media (min-width: 640px) {
    .pv-title {
        font-size: 2.25rem;
    }
}

.pv-description {
    color: var(--text-muted);
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.5;
    margin-top: 1rem;
    font-size: 1rem;
}

@media (min-width: 640px) {
    .pv-description {
        font-size: 1.125rem;
    }
}

.pv-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-top: 1.5rem;
    font-size: 0.875rem;
    color: var(--text-muted);
}

.pv-meta-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pv-success-message {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background-color: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 0.5rem;
    color: #16a34a;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.dark-mode .pv-success-message {
    background-color: rgba(34, 197, 94, 0.15);
    border-color: rgba(34, 197, 94, 0.4);
    color: #86efac;
}

.pv-error-message {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 0.5rem;
    color: #dc2626;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.dark-mode .pv-error-message {
    background-color: rgba(127, 29, 29, 0.2);
    border-color: rgba(239, 68, 68, 0.4);
    color: #fca5a5;
}

.pv-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    padding: 2rem;
    box-shadow: var(--shadow-card);
    transition: box-shadow 0.2s;
}

.pv-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.dark-mode .pv-card {
    border: 1px solid var(--border-color);
}

.pv-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.pv-info-box {
    padding: 1rem;
    background-color: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}

.dark-mode .pv-info-box {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.pv-info-text {
    font-size: 0.875rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.pv-option-item {
    padding: 1rem;
    background-color: var(--bg-body);
    border: 2px solid transparent;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 0.75rem;
    min-height: 44px;
    display: flex;
    align-items: center;
}

.pv-option-item:hover {
    border-color: var(--ibc-blue);
    background-color: var(--bg-card);
}

.pv-option-input {
    min-height: 44px;
    min-width: 44px;
    accent-color: var(--ibc-blue);
    flex-shrink: 0;
    margin-right: 1rem;
}

.pv-option-label {
    color: var(--text-main);
    flex: 1;
}

.pv-option-badge {
    display: inline-block;
    margin-left: 0.5rem;
    padding: 0.25rem 0.5rem;
    background-color: rgba(59, 130, 246, 0.15);
    color: var(--ibc-blue);
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.pv-result-item {
    padding: 1rem;
    background-color: var(--bg-body);
    border: 2px solid transparent;
    border-radius: 0.5rem;
    margin-bottom: 0.75rem;
}

.pv-result-item.user-voted {
    border-color: var(--ibc-blue);
}

.pv-result-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.pv-result-label {
    color: var(--text-main);
    font-weight: 500;
    flex: 1;
}

.pv-result-percentage {
    color: var(--text-muted);
    font-weight: 600;
    margin-left: 1rem;
}

.pv-progress-bar {
    width: 100%;
    height: 0.75rem;
    background-color: var(--border-color);
    border-radius: 9999px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.pv-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--ibc-blue), var(--ibc-green));
    border-radius: 9999px;
    transition: width 0.5s ease-out;
}

.pv-result-count {
    font-size: 0.875rem;
    color: var(--text-muted);
}

.pv-button-submit {
    width: 100%;
    padding: 0.75rem 1.5rem;
    margin-top: 1.5rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    color: white;
    border: none;
    border-radius: 0.5rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 44px;
    box-shadow: var(--shadow-card);
}

.pv-button-submit:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-card-hover);
}

.pv-thank-you {
    padding: 1rem;
    background-color: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
}

.dark-mode .pv-thank-you {
    background-color: rgba(34, 197, 94, 0.15);
    border-color: rgba(34, 197, 94, 0.4);
}

.pv-thank-you-text {
    color: #16a34a;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.dark-mode .pv-thank-you-text {
    color: #86efac;
}

.pv-summary-box {
    padding: 1rem;
    background-color: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 0.5rem;
    margin-top: 1.5rem;
}

.dark-mode .pv-summary-box {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: rgba(59, 130, 246, 0.3);
}

.pv-summary-text {
    font-size: 0.875rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.pv-iframe-wrapper {
    position: relative;
    width: 100%;
    padding-bottom: 56.25%;
    height: 0;
    overflow: hidden;
    border-radius: 0.5rem;
    margin-top: 1rem;
}

.pv-iframe-wrapper iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    min-height: 600px;
    border-radius: 0.5rem;
}
</style>

<div class="pv-container">
    <!-- Header -->
    <div class="pv-header">
        <a href="<?php echo asset('pages/polls/index.php'); ?>" class="pv-back-link">
            <i class="fas fa-arrow-left"></i>Zurück zu Umfragen
        </a>

        <h1 class="pv-title">
            <?php echo htmlspecialchars($poll['title']); ?>
        </h1>

        <?php if (!empty($poll['description'])): ?>
        <p class="pv-description">
            <?php echo nl2br(htmlspecialchars($poll['description'])); ?>
        </p>
        <?php endif; ?>

        <div class="pv-meta">
            <div class="pv-meta-item">
                <i class="fas fa-calendar-alt"></i>
                <span>
                    <?php if (!empty($poll['end_date'])): ?>
                        Endet am <?php echo formatDateTime($poll['end_date'], 'd.m.Y H:i'); ?> Uhr
                    <?php else: ?>
                        Dauerhaft verfügbar
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!$hasMicrosoftFormsUrl): ?>
            <div class="pv-meta-item">
                <i class="fas fa-users"></i>
                <span><?php echo $totalVotes; ?> Stimme(n)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
    <div class="pv-success-message">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($successMessage); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
    <div class="pv-error-message">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($errorMessage); ?></span>
    </div>
    <?php endif; ?>

    <!-- Poll Content -->
    <div class="pv-card">
        <?php if ($hasMicrosoftFormsUrl): ?>
        <!-- Microsoft Forms Iframe -->
        <h2 class="pv-section-title">
            <i class="fas fa-poll"></i>
            Umfrage
        </h2>

        <div class="pv-info-box">
            <div class="pv-info-text">
                <i class="fas fa-info-circle"></i>
                <span>Diese Umfrage wird über Microsoft Forms durchgeführt. Bitte füllen Sie das Formular unten aus.</span>
            </div>
        </div>

        <div class="pv-iframe-wrapper">
            <iframe
                src="<?php echo htmlspecialchars($poll['microsoft_forms_url']); ?>"
                frameborder="0"
                marginwidth="0"
                marginheight="0"
                allowfullscreen
                webkitallowfullscreen
                mozallowfullscreen
                msallowfullscreen
            ></iframe>
        </div>

        <?php elseif (!$userVote): ?>
        <!-- Voting Form -->
        <h2 class="pv-section-title">
            <i class="fas fa-vote-yea"></i>
            Ihre Stimme abgeben
        </h2>

        <form method="POST">
            <?php foreach ($options as $option): ?>
            <label class="pv-option-item">
                <input
                    type="radio"
                    name="option_id"
                    value="<?php echo $option['id']; ?>"
                    required
                    class="pv-option-input"
                >
                <span class="pv-option-label">
                    <?php echo htmlspecialchars($option['option_text']); ?>
                </span>
            </label>
            <?php endforeach; ?>

            <button
                type="submit"
                name="submit_vote"
                class="pv-button-submit"
            >
                <i class="fas fa-check-circle"></i>
                Stimme abgeben
            </button>
        </form>

        <?php else: ?>
        <!-- Results Display -->
        <h2 class="pv-section-title">
            <i class="fas fa-chart-bar"></i>
            Ergebnisse
        </h2>

        <div class="pv-thank-you">
            <div class="pv-thank-you-text">
                <i class="fas fa-check-circle"></i>
                <span>Sie haben bereits abgestimmt. Vielen Dank für Ihre Teilnahme!</span>
            </div>
        </div>

        <div>
            <?php foreach ($options as $option): ?>
            <?php
                $percentage = $totalVotes > 0 ? round(($option['vote_count'] / $totalVotes) * 100, 1) : 0;
                $isUserVote = ($userVote && $userVote['option_id'] == $option['id']);
            ?>
            <div class="pv-result-item <?php echo $isUserVote ? 'user-voted' : ''; ?>">
                <div class="pv-result-header">
                    <span class="pv-result-label">
                        <?php echo htmlspecialchars($option['option_text']); ?>
                        <?php if ($isUserVote): ?>
                        <span class="pv-option-badge">Ihre Wahl</span>
                        <?php endif; ?>
                    </span>
                    <span class="pv-result-percentage"><?php echo $percentage; ?>%</span>
                </div>

                <div class="pv-progress-bar">
                    <div class="pv-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                </div>

                <div class="pv-result-count">
                    <?php echo $option['vote_count']; ?> Stimme(n)
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pv-summary-box">
            <div class="pv-summary-text">
                <i class="fas fa-info-circle"></i>
                <span>Insgesamt haben <strong><?php echo $totalVotes; ?></strong> Person(en) an dieser Umfrage teilgenommen.</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
