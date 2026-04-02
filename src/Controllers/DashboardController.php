<?php
declare(strict_types=1);
namespace App\Controllers;

use Twig\Environment;

class DashboardController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    public function index(array $vars = []): void
    {
        $this->requireAuth();
        $currentUser = \Auth::user();

        if (!$currentUser) {
            \Auth::logout();
            $this->redirect(\BASE_URL . '/login');
        }

        $rolesRequiringProfile = ['vorstand_finanzen', 'vorstand_intern', 'vorstand_extern', 'alumni_vorstand', 'alumni_finanz', 'alumni', 'mitglied', 'ressortleiter', 'anwaerter', 'ehrenmitglied'];
        if (in_array($currentUser['role'], $rolesRequiringProfile) && isset($currentUser['profile_complete']) && $currentUser['profile_complete'] == 0) {
            $_SESSION['profile_incomplete_message'] = 'Bitte vervollständige dein Profil (Vorname und Nachname) um fortzufahren.';
            $this->redirect(\BASE_URL . '/alumni/edit');
        }

        $user     = $currentUser;
        $userRole = $user['role'] ?? '';

        $displayName = 'Benutzer';
        if (!empty($user['firstname']) && !empty($user['lastname'])) {
            $displayName = $user['firstname'] . ' ' . $user['lastname'];
        } elseif (!empty($user['firstname'])) {
            $displayName = $user['firstname'];
        } elseif (!empty($user['email']) && strpos($user['email'], '@') !== false) {
            $emailParts  = explode('@', $user['email']);
            $displayName = $emailParts[0];
        }
        if ($displayName !== 'Benutzer') {
            $displayName = ucwords(str_replace('.', ' ', $displayName));
        }

        $timezone = new \DateTimeZone('Europe/Berlin');
        $now      = new \DateTime('now', $timezone);
        $hour     = (int)$now->format('H');
        if ($hour >= 5 && $hour < 12) {
            $greeting = 'Guten Morgen';
        } elseif ($hour >= 12 && $hour < 18) {
            $greeting = 'Guten Tag';
        } else {
            $greeting = 'Guten Abend';
        }

        $nextEvents    = [];
        $events        = [];
        $currentUserId = (int)\Auth::getUserId();
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT DISTINCT e.id, e.title, e.start_time, e.end_time, e.location, e.status, e.image_path, e.is_external
                 FROM events e
                 INNER JOIN event_signups es ON es.event_id = e.id
                 WHERE e.status IN ('planned', 'open', 'closed') AND DATE(e.start_time) >= CURDATE()
                   AND es.user_id = ? AND es.status = 'confirmed'
                 ORDER BY e.start_time ASC LIMIT 5"
            );
            $stmt->execute([$currentUserId]);
            $events     = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $nextEvents = array_slice($events, 0, 3);
        } catch (\Exception $e) {
            error_log('dashboard: upcoming events query failed: ' . $e->getMessage());
        }

        $openTasksCount = 0;
        $userId         = (int)\Auth::getUserId();
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT COUNT(*) FROM inventory_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')"
            );
            $stmt->execute([$userId]);
            $openTasksCount += (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log('dashboard: open tasks count (requests) failed: ' . $e->getMessage());
        }

        $this->render('dashboard/index.twig', [
            'user'           => $user,
            'userRole'       => $userRole,
            'displayName'    => $displayName,
            'greeting'       => $greeting,
            'nextEvents'     => $nextEvents,
            'events'         => $events,
            'openTasksCount' => $openTasksCount,
        ]);
    }
}
