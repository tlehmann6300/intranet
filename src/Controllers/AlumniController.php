<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class AlumniController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user        = \Auth::user();
        $viewerRole  = $user['role'] ?? '';
        $canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);

        $searchKeyword  = $_GET['search'] ?? '';
        $industryFilter = $_GET['industry'] ?? '';
        $filters        = [];
        if (!empty($searchKeyword)) {
            $filters['search'] = $searchKeyword;
        }
        if (!empty($industryFilter)) {
            $filters['industry'] = $industryFilter;
        }

        $profiles   = \Alumni::searchProfiles($filters);
        $industries = \Alumni::getAllIndustries();

        $this->render('alumni/index.twig', [
            'user'           => $user,
            'profiles'       => $profiles,
            'industries'     => $industries,
            'searchKeyword'  => $searchKeyword,
            'industryFilter' => $industryFilter,
            'canViewPrivate' => $canViewPrivate,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        $profileId = $_GET['id'] ?? null;
        $returnTo  = 'alumni';

        if (isset($_GET['return_to'])) {
            $returnTo = ($_GET['return_to'] === 'members') ? 'members' : 'alumni';
        } elseif (isset($_SERVER['HTTP_REFERER'])) {
            $referer   = $_SERVER['HTTP_REFERER'];
            $parsedUrl = parse_url($referer);
            if ($parsedUrl !== false && isset($parsedUrl['path']) && strpos($parsedUrl['path'], '/pages/members/') !== false) {
                $returnTo = 'members';
            }
        }

        if (!$profileId) {
            $this->redirect(\BASE_URL . '/alumni');
        }

        $profile = \Alumni::getProfileById((int)$profileId);
        if (!$profile) {
            $_SESSION['error_message'] = 'Profil nicht gefunden';
            $this->redirect(\BASE_URL . '/alumni');
        }

        $this->render('alumni/view.twig', [
            'user'     => $user,
            'profile'  => $profile,
            'returnTo' => $returnTo,
        ]);
    }

    public function edit(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userId   = $_SESSION['user_id'];
        $userRole = $user['role'] ?? '';

        $allowedRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'ressortleiter', 'anwaerter', 'mitglied', 'ehrenmitglied'];
        if (!in_array($userRole, $allowedRoles)) {
            $_SESSION['error_message'] = 'Du hast keine Berechtigung, Profile zu bearbeiten.';
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $profile          = \Alumni::getProfileByUserId($userId);
        $isFirstTimeSetup = isset($user['profile_complete']) && $user['profile_complete'] == 0;
        $message          = '';
        $errors           = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

            $firstName   = trim($_POST['first_name'] ?? '');
            $lastName    = trim($_POST['last_name'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $mobilePhone = trim($_POST['mobile_phone'] ?? '');
            $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
            $xingUrl     = trim($_POST['xing_url'] ?? '');
            $industry    = trim($_POST['industry'] ?? '');
            $company     = trim($_POST['company'] ?? '');
            $position    = trim($_POST['position'] ?? '');

            if ($isFirstTimeSetup) {
                if (empty($firstName) || empty($lastName)) {
                    $errors[] = 'Bitte geben Sie Ihren Vornamen und Nachnamen ein, um fortzufahren.';
                }
            } else {
                if (empty($firstName) || empty($lastName) || empty($email)) {
                    $errors[] = 'Bitte füllen Sie alle Pflichtfelder aus (Vorname, Nachname, E-Mail)';
                }
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein';
            }
            if (!empty($linkedinUrl) && !filter_var($linkedinUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Bitte geben Sie eine gültige LinkedIn-URL ein';
            }
            if (!empty($xingUrl) && !filter_var($xingUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Bitte geben Sie eine gültige Xing-URL ein';
            }

            if (empty($errors)) {
                $profileData = [
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'email'        => $email,
                    'mobile_phone' => $mobilePhone,
                    'linkedin_url' => $linkedinUrl,
                    'xing_url'     => $xingUrl,
                    'industry'     => $industry,
                    'company'      => $company,
                    'position'     => $position,
                ];

                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = \SecureImageUpload::uploadImage($_FILES['profile_image']);
                    if ($uploadResult['success']) {
                        if (!empty($profile['image_path'])) {
                            \SecureImageUpload::deleteImage($profile['image_path']);
                        }
                        $profileData['image_path'] = $uploadResult['path'];
                    }
                }

                \Alumni::updateOrCreateProfile($userId, $profileData);
                $_SESSION['success_message'] = 'Profil erfolgreich gespeichert';
                $this->redirect(\BASE_URL . '/alumni/edit');
            }
        }

        $this->render('alumni/edit.twig', [
            'user'             => $user,
            'profile'          => $profile,
            'isFirstTimeSetup' => $isFirstTimeSetup,
            'message'          => $message,
            'errors'           => $errors,
            'csrfToken'        => \CSRFHandler::getToken(),
        ]);
    }

    public function requests(array $vars = []): void
    {
        $this->requireAuth();
        $user     = \Auth::user();
        $userRole = $user['role'] ?? '';

        $allowedRoles = ['alumni', 'alumni_vorstand'];
        if (!in_array($userRole, $allowedRoles)) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $profile    = \Alumni::getProfileByUserId($user['id']);
        $alumniName = '';
        if ($profile && !empty($profile['first_name']) && !empty($profile['last_name'])) {
            $alumniName = $profile['first_name'] . ' ' . $profile['last_name'];
        } else {
            $alumniName = explode('@', $user['email'])[0];
        }

        $successMessage = '';
        $errorMessage   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
            $thema        = trim($_POST['thema'] ?? '');
            $ort          = trim($_POST['ort'] ?? '');
            $beschreibung = trim($_POST['beschreibung'] ?? '');
            $zeitraeume   = trim($_POST['zeitraeume'] ?? '');

            if (empty($thema) || empty($ort) || empty($beschreibung) || empty($zeitraeume)) {
                $errorMessage = 'Bitte füllen Sie alle Felder aus.';
            } else {
                try {
                    $emailBody  = '<h2>Neue Schulungsanfrage von Alumni</h2>';
                    $emailBody .= '<table class="info-table">';
                    $emailBody .= '<tr><td class="info-label">Von:</td><td class="info-value">' . htmlspecialchars($alumniName) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>';
                    $emailBody .= '<tr><td class="info-label">Thema:</td><td class="info-value">' . htmlspecialchars($thema) . '</td></tr>';
                    $emailBody .= '<tr><td class="info-label">Gewünschter Ort:</td><td class="info-value">' . htmlspecialchars($ort) . '</td></tr>';
                    $emailBody .= '<tr><td class="info-label">Beschreibung:</td><td class="info-value">' . nl2br(htmlspecialchars($beschreibung)) . '</td></tr>';
                    $emailBody .= '<tr><td class="info-label">Mögliche Termine/Zeiträume:</td><td class="info-value">' . nl2br(htmlspecialchars($zeitraeume)) . '</td></tr>';
                    $emailBody .= '<tr><td class="info-label">Datum:</td><td class="info-value">' . date('d.m.Y H:i') . ' Uhr</td></tr>';
                    $emailBody .= '</table>';

                    $emailSent = \MailService::send(
                        defined('SMTP_FROM') && \SMTP_FROM !== '' ? \SMTP_FROM : 'vorstand@business-consulting.de',
                        'Schulungsanfrage von ' . $alumniName,
                        $emailBody
                    );

                    if ($emailSent) {
                        $successMessage = 'Ihre Schulungsanfrage wurde erfolgreich eingereicht!';
                        $_POST = [];
                    } else {
                        $errorMessage = 'Fehler beim Senden der E-Mail. Bitte versuchen Sie es später erneut.';
                    }
                } catch (\Exception $e) {
                    error_log('Error in alumni/requests: ' . $e->getMessage());
                    $errorMessage = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
                }
            }
        }

        $this->render('alumni/requests.twig', [
            'user'           => $user,
            'alumniName'     => $alumniName,
            'successMessage' => $successMessage,
            'errorMessage'   => $errorMessage,
        ]);
    }
}
