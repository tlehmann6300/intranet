<?php

namespace App\Controllers;

use Twig\Environment;

/**
 * AuthController
 *
 * Handles authentication-related routes:
 *  GET  /login    – Show login form
 *  POST /login    – Process login credentials
 *  GET  /logout   – Destroy session and redirect to login
 *  GET  /register – Show registration form (if applicable)
 *  GET  /         – Redirect to dashboard or login depending on auth state
 *
 * The existing pages/auth/login.php path continues to work via direct
 * file access – this controller only powers the clean /login URL.
 */
class AuthController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function redirectRoot(): void
    {
        if (\Auth::check()) {
            $this->redirect(BASE_URL . '/dashboard');
        }
        $this->redirect(BASE_URL . '/login');
    }

    public function showLogin(): void
    {
        // Already authenticated → redirect to dashboard
        if (\Auth::check()) {
            $this->redirect(BASE_URL . '/dashboard');
        }

        $flash = $this->popFlash();

        $this->render('auth/login.html.twig', [
            'csrf_token'    => \CSRFHandler::getToken(),
            'flash'         => $flash,
            'error'         => $_GET['error'] ?? null,
        ]);
    }

    public function handleLogin(): void
    {
        // Verify CSRF token first
        \CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Basic input validation
        $validator = \Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email'    => 'required|email|max:255',
                'password' => 'required|min:1',
            ]
        );

        if ($validator->fails()) {
            $this->setFlash('error', $validator->firstError() ?? 'Ungültige Eingaben.');
            $this->redirect(BASE_URL . '/login');
        }

        // Rate limiting: max 10 login attempts per 15 minutes
        $limiter = new \RateLimiter('login_' . md5($email), maxAttempts: 10, decaySeconds: 900);

        if ($limiter->tooManyAttempts()) {
            $wait = $limiter->availableIn();
            $this->setFlash(
                'error',
                "Zu viele Anmeldeversuche. Bitte warte $wait Sekunden und versuche es erneut."
            );
            $this->redirect(BASE_URL . '/login');
        }

        // Attempt login via existing Auth class
        $result = \Auth::login($email, $password);

        if (!empty($result['success'])) {
            $limiter->clear();

            // Check for incomplete profile
            if (isset($_SESSION['profile_incomplete']) && $_SESSION['profile_incomplete'] === true) {
                $this->redirect(BASE_URL . '/pages/auth/profile.php');
            }

            $this->redirect(BASE_URL . '/dashboard');
        }

        $limiter->hit();

        $error = $result['message'] ?? 'Ungültige Anmeldedaten.';
        $this->setFlash('error', $error);
        $this->redirect(BASE_URL . '/login');
    }

    public function logout(): void
    {
        \Auth::logout();
        $this->redirect(BASE_URL . '/login');
    }

    public function showRegister(): void
    {
        if (\Auth::check()) {
            $this->redirect(BASE_URL . '/dashboard');
        }

        $this->render('auth/register.html.twig', [
            'csrf_token' => \CSRFHandler::getToken(),
            'flash'      => $this->popFlash(),
        ]);
    }
}
