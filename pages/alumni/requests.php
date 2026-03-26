<?php
/**
 * Alumni Schulungsanfrage - Training Request Form
 * Access: alumni, alumni_board
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/MailService.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/models/Alumni.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? '';

// Check if user has one of the allowed roles
$allowedRoles = ['alumni', 'alumni_vorstand'];
if (!in_array($userRole, $allowedRoles)) {
    header('Location: ../dashboard/index.php');
    exit;
}

$successMessage = '';
$errorMessage = '';

// Get alumni profile for name
$profile = Alumni::getProfileByUserId($user['id']);
$alumniName = '';
if ($profile && !empty($profile['first_name']) && !empty($profile['last_name'])) {
    $alumniName = $profile['first_name'] . ' ' . $profile['last_name'];
} else {
    $alumniName = explode('@', $user['email'])[0];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $thema = trim($_POST['thema'] ?? '');
    $ort = trim($_POST['ort'] ?? '');
    $beschreibung = trim($_POST['beschreibung'] ?? '');
    $zeitraeume = trim($_POST['zeitraeume'] ?? '');
    
    if (empty($thema) || empty($ort) || empty($beschreibung) || empty($zeitraeume)) {
        $errorMessage = 'Bitte füllen Sie alle Felder aus.';
    } else {
        try {
            // Prepare email content
            $emailBody = '<h2>Neue Schulungsanfrage von Alumni</h2>';
            $emailBody .= '<table class="info-table">';
            $emailBody .= '<tr><td class="info-label">Von:</td><td class="info-value">' . htmlspecialchars($alumniName) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>';
            $emailBody .= '<tr><td class="info-label">Thema:</td><td class="info-value">' . htmlspecialchars($thema) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Gewünschter Ort:</td><td class="info-value">' . htmlspecialchars($ort) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Beschreibung:</td><td class="info-value">' . nl2br(htmlspecialchars($beschreibung)) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Mögliche Termine/Zeiträume:</td><td class="info-value">' . nl2br(htmlspecialchars($zeitraeume)) . '</td></tr>';
            $emailBody .= '<tr><td class="info-label">Datum:</td><td class="info-value">' . date('d.m.Y H:i') . ' Uhr</td></tr>';
            $emailBody .= '</table>';
            
            // Send email
            // NOTE: Email address is hardcoded as per requirements
            // For production, consider moving to config file
            $emailSent = MailService::send(
                defined('SMTP_FROM') && SMTP_FROM !== '' ? SMTP_FROM : 'vorstand@business-consulting.de',
                'Schulungsanfrage von ' . $alumniName,
                $emailBody
            );
            
            if ($emailSent) {
                $successMessage = 'Ihre Schulungsanfrage wurde erfolgreich eingereicht!';
                // Clear form
                $_POST = [];
            } else {
                $errorMessage = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
            }
        } catch (Exception $e) {
            error_log('Error in alumni/requests.php: ' . $e->getMessage());
            $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
        }
    }
}

$title = 'Schulungsanfrage - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <i class="fas fa-chalkboard-teacher mr-3 text-blue-600"></i>
            Schulungsanfrage
        </h1>
        <p class="text-gray-600 dark:text-gray-300">Fordern Sie eine Schulung oder ein Training an</p>
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

    <!-- Training Request Form -->
    <div class="card p-4 sm:p-8">
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-chalkboard-teacher text-blue-600 text-xl"></i>
                </div>
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100">Ihre Schulungsanfrage</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Füllen Sie das Formular aus, um eine Schulung anzufordern</p>
                </div>
            </div>
        </div>
        
        <form method="POST" class="space-y-6">
            <!-- Thema -->
            <div>
                <label for="thema" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Thema <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="text" 
                    id="thema" 
                    name="thema" 
                    required
                    maxlength="200"
                    value="<?php echo htmlspecialchars($_POST['thema'] ?? ''); ?>"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="z.B. Leadership Training, Projektmanagement, etc."
                >
            </div>

            <!-- Ort -->
            <div>
                <label for="ort" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Gewünschter Ort <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <input 
                    type="text" 
                    id="ort" 
                    name="ort" 
                    required
                    maxlength="200"
                    value="<?php echo htmlspecialchars($_POST['ort'] ?? ''); ?>"
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="z.B. München, Online, Berlin, etc."
                >
            </div>

            <!-- Beschreibung -->
            <div>
                <label for="beschreibung" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Beschreibung <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <textarea 
                    id="beschreibung" 
                    name="beschreibung" 
                    rows="6"
                    required
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="Beschreiben Sie den Inhalt und die Ziele der gewünschten Schulung..."
                ><?php echo htmlspecialchars($_POST['beschreibung'] ?? ''); ?></textarea>
            </div>

            <!-- Zeiträume -->
            <div>
                <label for="zeitraeume" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Mögliche Termine/Zeiträume <span class="text-red-500 dark:text-red-400">*</span>
                </label>
                <textarea 
                    id="zeitraeume" 
                    name="zeitraeume" 
                    rows="4"
                    required
                    class="w-full px-4 py-3 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                    placeholder="z.B. KW 25-30 2024, Wochenenden im Juli, etc."
                ><?php echo htmlspecialchars($_POST['zeitraeume'] ?? ''); ?></textarea>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle mr-1"></i>
                    Geben Sie mehrere Zeiträume an, um die Planbarkeit zu erhöhen.
                </p>
            </div>

            <!-- Info Box -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                    <div class="text-sm text-blue-800 dark:text-blue-300">
                        <p class="font-semibold mb-1">Hinweis:</p>
                        <p>Ihre Schulungsanfrage wird direkt an das IBC-Team gesendet. Wir prüfen die Anfrage und melden uns zeitnah bei Ihnen, um die Details zu besprechen und einen Termin zu koordinieren.</p>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex flex-col sm:flex-row gap-2 sm:gap-4">
                <button 
                    type="submit"
                    name="submit_request"
                    class="w-full sm:flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg flex items-center justify-center"
                >
                    <i class="fas fa-paper-plane mr-2"></i>
                    Anfrage senden
                </button>
                <a 
                    href="<?php echo asset('pages/dashboard/index.php'); ?>"
                    class="w-full sm:w-auto px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all text-center"
                >
                    Abbrechen
                </a>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
