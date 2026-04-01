<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class PublicController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function alumniRecovery(array $vars = []): void
    {
        $this->render('public/alumni_recovery.twig', []);
    }

    public function neueAlumni(array $vars = []): void
    {
        $this->render('public/neue_alumni.twig', []);
    }

    public function impressum(array $vars = []): void
    {
        $this->render('impressum.twig', []);
    }

    public function confirmEmail(array $vars = []): void
    {
        $error   = '';
        $success = '';

        if (!isset($_GET['token']) || empty($_GET['token'])) {
            $error = 'Ungültiger Bestätigungslink';
        } else {
            $token = $_GET['token'];
            try {
                if (\User::confirmEmailChange($token)) {
                    if (\Auth::check()) {
                        $user = \Auth::user();
                        $updatedUser = \User::getById($user['id']);
                        if ($updatedUser) {
                            $_SESSION['user_email'] = $updatedUser['email'];
                        }
                    }
                    $success = 'E-Mail-Adresse erfolgreich aktualisiert';
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        if (!empty($success)) {
            $_SESSION['success_message'] = $success;
        } elseif (!empty($error)) {
            $_SESSION['error_message'] = $error;
        }

        $this->redirect(\BASE_URL . '/settings');
    }

    public function submitAlumniRecovery(array $vars = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        // reCAPTCHA validation
        $recaptchaToken = $input['recaptcha_token'] ?? '';
        if (defined('RECAPTCHA_SECRET_KEY') && \RECAPTCHA_SECRET_KEY !== '') {
            $response = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode(\RECAPTCHA_SECRET_KEY) . '&response=' . urlencode($recaptchaToken) . '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? ''));
            $result   = json_decode($response, true);
            if (!($result['success'] ?? false)) {
                http_response_code(400);
                $this->json(['success' => false, 'message' => 'reCAPTCHA-Prüfung fehlgeschlagen']);
            }
        }

        $firstName         = htmlspecialchars(trim($input['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName          = htmlspecialchars(trim($input['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $currentEmail      = filter_var(trim($input['current_email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $newEmail          = filter_var(trim($input['new_email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $graduationSemester = htmlspecialchars(trim($input['graduation_semester'] ?? ''), ENT_QUOTES, 'UTF-8');
        $studyProgram      = htmlspecialchars(trim($input['study_program'] ?? ''), ENT_QUOTES, 'UTF-8');

        if (empty($firstName) || empty($lastName) || empty($currentEmail) || empty($newEmail)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Pflichtfelder fehlen']);
        }

        try {
            \AlumniAccessRequest::create([
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'old_email'           => $currentEmail,
                'new_email'           => $newEmail,
                'graduation_semester' => $graduationSemester,
                'study_program'       => $studyProgram,
                'status'              => 'pending',
            ]);
        } catch (\Exception $e) {
            error_log('submitAlumniRecovery: ' . $e->getMessage());
        }

        $this->json([
            'success' => true,
            'message' => 'Deine Anfrage wird geprüft. Wir melden uns in Kürze bei dir.',
        ]);
    }

    public function submitNeueAlumni(array $vars = []): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->json(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        // reCAPTCHA validation
        $recaptchaToken = $input['recaptcha_token'] ?? '';
        if (defined('RECAPTCHA_SECRET_KEY') && \RECAPTCHA_SECRET_KEY !== '') {
            $response = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode(\RECAPTCHA_SECRET_KEY) . '&response=' . urlencode($recaptchaToken) . '&remoteip=' . urlencode($_SERVER['REMOTE_ADDR'] ?? ''));
            $result   = json_decode($response, true);
            if (!($result['success'] ?? false)) {
                http_response_code(400);
                $this->json(['success' => false, 'message' => 'reCAPTCHA-Prüfung fehlgeschlagen']);
            }
        }

        $firstName          = htmlspecialchars(trim($input['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName           = htmlspecialchars(trim($input['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email              = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $graduationSemester = htmlspecialchars(trim($input['graduation_semester'] ?? ''), ENT_QUOTES, 'UTF-8');
        $studyProgram       = htmlspecialchars(trim($input['study_program'] ?? ''), ENT_QUOTES, 'UTF-8');
        $hasAlumniContract  = isset($input['has_alumni_contract']) ? (int)(bool)$input['has_alumni_contract'] : 0;

        if (empty($firstName) || empty($lastName) || empty($email)) {
            http_response_code(400);
            $this->json(['success' => false, 'message' => 'Pflichtfelder fehlen']);
        }

        try {
            \NewAlumniRequest::create([
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'email'               => $email,
                'graduation_semester' => $graduationSemester,
                'study_program'       => $studyProgram,
                'has_alumni_contract' => $hasAlumniContract,
                'status'              => 'pending',
            ]);
        } catch (\Exception $e) {
            error_log('submitNeueAlumni: ' . $e->getMessage());
        }

        $this->json([
            'success' => true,
            'message' => 'Deine Anfrage wird geprüft. Wir melden uns in Kürze bei dir.',
        ]);
    }
}
