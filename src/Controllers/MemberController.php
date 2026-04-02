<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class MemberController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        if (!\Auth::canAccessPage('members')) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $viewerRole    = $user['role'] ?? '';
        $canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);
        $searchKeyword = $_GET['search'] ?? '';
        $roleFilter    = $_GET['role'] ?? '';

        $members = \Member::getAllActive(
            !empty($searchKeyword) ? $searchKeyword : null,
            !empty($roleFilter) ? $roleFilter : null
        );

        $this->render('members/index.twig', [
            'user'           => $user,
            'members'        => $members,
            'searchKeyword'  => $searchKeyword,
            'roleFilter'     => $roleFilter,
            'canViewPrivate' => $canViewPrivate,
        ]);
    }

    public function view(array $vars = []): void
    {
        $this->requireAuth();
        $user = \Auth::user();

        if (!\Auth::canAccessPage('members')) {
            $this->redirect(\BASE_URL . '/dashboard');
        }

        $profileId = $_GET['id'] ?? null;
        if (!$profileId) {
            $this->redirect(\BASE_URL . '/members');
        }

        $profile = \Alumni::getProfileById((int)$profileId);
        if (!$profile) {
            $_SESSION['error_message'] = 'Profil nicht gefunden';
            $this->redirect(\BASE_URL . '/members');
        }

        $profileUser = \User::findById($profile['user_id']);
        if (!$profileUser) {
            $_SESSION['error_message'] = 'Benutzer nicht gefunden';
            $this->redirect(\BASE_URL . '/members');
        }

        $profileUserRole       = $profileUser['role'];
        $profileUserEntraRoles = $profileUser['entra_roles'] ?? null;
        $resolvedDisplayRole   = \resolveDisplayRole($profileUserRole, $profileUserEntraRoles);

        $viewerRole     = $user['role'] ?? '';
        $canViewPrivate = in_array($viewerRole, ['alumni', 'vorstand_intern', 'vorstand_extern', 'vorstand_finanzen']);

        $profileCompletenessPercent = 0;
        $isAlumniProfile            = \isAlumniRole($profileUserRole);
        if ($isAlumniProfile) {
            $completenessFields = [
                'first_name'   => $profileUser['first_name'] ?? null,
                'last_name'    => $profileUser['last_name'] ?? null,
                'email'        => $profileUser['email'] ?? null,
                'mobile_phone' => $profile['mobile_phone'] ?? null,
                'gender'       => $profileUser['gender'] ?? null,
                'birthday'     => $profileUser['birthday'] ?? null,
                'skills'       => $profile['skills'] ?? null,
                'about_me'     => $profileUser['about_me'] ?? null,
            ];
            $filledCount = 0;
            foreach ($completenessFields as $value) {
                if (!empty($value)) {
                    $filledCount++;
                }
            }
            $profileCompletenessPercent = (int)round(($filledCount / count($completenessFields)) * 100);
        }

        $this->render('members/view.twig', [
            'user'                      => $user,
            'profile'                   => $profile,
            'profileUser'               => $profileUser,
            'profileUserRole'           => $profileUserRole,
            'resolvedDisplayRole'       => $resolvedDisplayRole,
            'canViewPrivate'            => $canViewPrivate,
            'isAlumniProfile'           => $isAlumniProfile,
            'profileCompletenessPercent' => $profileCompletenessPercent,
        ]);
    }
}
