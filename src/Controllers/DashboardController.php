<?php

namespace App\Controllers;

use Twig\Environment;

/**
 * DashboardController
 *
 * Powers the GET /dashboard route.
 *
 * The existing pages/dashboard/index.php file continues to work via direct
 * access.  This controller delivers the same page via the clean /dashboard URL.
 */
class DashboardController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(): void
    {
        // Authentication guard
        if (!\Auth::check()) {
            $this->redirect(BASE_URL . '/login');
        }

        $currentUser = \Auth::user();

        if ($currentUser === null) {
            \Auth::logout();
            $this->redirect(BASE_URL . '/login');
        }

        // Profile completeness guard
        $rolesRequiringProfile = [
            'vorstand_finanzen', 'vorstand_intern', 'vorstand_extern',
            'alumni_vorstand', 'alumni_finanz', 'alumni',
            'mitglied', 'ressortleiter', 'anwaerter', 'ehrenmitglied',
        ];

        if (
            in_array($currentUser['role'] ?? '', $rolesRequiringProfile, true)
            && isset($currentUser['profile_complete'])
            && (int) $currentUser['profile_complete'] === 0
        ) {
            $this->redirect(BASE_URL . '/pages/alumni/edit.php');
        }

        $this->render('dashboard/index.html.twig', [
            'user'       => $currentUser,
            'csrf_token' => \CSRFHandler::getToken(),
        ]);
    }
}
