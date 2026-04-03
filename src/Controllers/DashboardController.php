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
        if (!empty($user['first_name']) && !empty($user['last_name'])) {
            $displayName = $user['first_name'] . ' ' . $user['last_name'];
        } elseif (!empty($user['first_name'])) {
            $displayName = $user['first_name'];
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

        $currentUserId = (int)\Auth::getUserId();

        // ── Upcoming events (registered + confirmed) ──────────────────────
        $nextEvents = [];
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT DISTINCT e.id, e.title, e.start_time, e.end_time, e.location, e.status, e.image_path, e.is_external
                 FROM events e
                 INNER JOIN event_signups es ON es.event_id = e.id
                 WHERE e.status IN ('planned', 'open', 'closed') AND DATE(e.start_time) >= CURDATE()
                   AND es.user_id = ? AND es.status = 'confirmed'
                 ORDER BY e.start_time ASC LIMIT 3"
            );
            $stmt->execute([$currentUserId]);
            $nextEvents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('dashboard: upcoming events query failed: ' . $e->getMessage());
        }

        // ── Next event (with days-until countdown) ────────────────────────
        $nextEventCountdown = null;
        if (!empty($nextEvents)) {
            try {
                $eventDate          = new \DateTime($nextEvents[0]['start_time'], $timezone);
                $diff               = $now->diff($eventDate);
                $nextEventCountdown = [
                    'event'   => $nextEvents[0],
                    'days'    => $diff->days,
                    'past'    => $diff->invert === 1,
                ];
            } catch (\Exception $e) {
                error_log('dashboard: countdown calc failed: ' . $e->getMessage());
            }
        }

        // ── Open inventory tasks ───────────────────────────────────────────
        $openTasksCount = 0;
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT COUNT(*) FROM inventory_requests WHERE user_id = ? AND status IN ('pending', 'approved', 'pending_return')"
            );
            $stmt->execute([$currentUserId]);
            $openTasksCount = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log('dashboard: open tasks count failed: ' . $e->getMessage());
        }

        // ── Overdue inventory items ────────────────────────────────────────
        $overdueItems = [];
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT ir.id, io.name, ir.return_date, ir.quantity
                 FROM inventory_requests ir
                 JOIN inventory_objects io ON io.id = ir.inventory_id
                 WHERE ir.user_id = ? AND ir.status = 'approved' AND ir.return_date < CURDATE()
                 ORDER BY ir.return_date ASC LIMIT 5"
            );
            $stmt->execute([$currentUserId]);
            $overdueItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('dashboard: overdue items query failed: ' . $e->getMessage());
        }

        // ── Pending polls (not yet voted by current user) ─────────────────
        $pendingPolls = [];
        try {
            $contentDb = \Database::getContentDB();
            $stmt      = $contentDb->prepare(
                "SELECT p.id, p.question, p.created_at
                 FROM polls p
                 WHERE p.is_hidden = 0
                   AND p.id NOT IN (
                       SELECT pv.poll_id FROM poll_votes pv WHERE pv.user_id = ?
                   )
                 ORDER BY p.created_at DESC LIMIT 3"
            );
            $stmt->execute([$currentUserId]);
            $pendingPolls = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('dashboard: pending polls query failed: ' . $e->getMessage());
        }

        // ── Upcoming birthdays (next 7 days, board role only) ─────────────
        $upcomingBirthdays = [];
        if (\Auth::isBoard()) {
            try {
                $userDb = \Database::getUserDB();
                $stmt   = $userDb->prepare(
                    "SELECT id, first_name, last_name, birthday
                     FROM users
                     WHERE deleted_at IS NULL AND is_active = 1
                       AND birthday IS NOT NULL
                       AND DATE_FORMAT(birthday, '%m-%d') BETWEEN DATE_FORMAT(CURDATE(), '%m-%d')
                                                               AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d')
                     ORDER BY DATE_FORMAT(birthday, '%m-%d') ASC LIMIT 5"
                );
                $stmt->execute();
                $upcomingBirthdays = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log('dashboard: birthday query failed: ' . $e->getMessage());
            }
        }

        // ── Admin: pending rental returns ──────────────────────────────────
        $pendingRentalReturns = 0;
        if (\Auth::isBoard() || \Auth::hasRole(['alumni_vorstand', 'alumni_finanz', 'manager'])) {
            try {
                $contentDb = \Database::getContentDB();
                $stmt      = $contentDb->query(
                    "SELECT COUNT(*) FROM inventory_requests WHERE status = 'pending_return'"
                );
                $pendingRentalReturns = (int)$stmt->fetchColumn();
            } catch (\Exception $e) {
                error_log('dashboard: pending rental returns query failed: ' . $e->getMessage());
            }
        }

        $this->render('dashboard/index.twig', [
            'user'                 => $user,
            'userRole'             => $userRole,
            'displayName'          => $displayName,
            'greeting'             => $greeting,
            'nextEvents'           => $nextEvents,
            'nextEventCountdown'   => $nextEventCountdown,
            'openTasksCount'       => $openTasksCount,
            'overdueItems'         => $overdueItems,
            'pendingPolls'         => $pendingPolls,
            'upcomingBirthdays'    => $upcomingBirthdays,
            'pendingRentalReturns' => $pendingRentalReturns,
            'isBoard'              => \Auth::isBoard(),
        ]);
    }
}
