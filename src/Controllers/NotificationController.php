<?php

declare(strict_types=1);

namespace App\Controllers;

use Twig\Environment;

/**
 * NotificationController
 *
 * Manages in-app notifications via a polling mechanism.
 *
 * Endpoints:
 *   GET  /api/notifications        → JSON list of unread notifications
 *   POST /api/notifications/read   → Mark notification(s) as read
 *   POST /api/notifications/read-all → Mark all as read
 */
class NotificationController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    /**
     * Return unread notifications for the current user as JSON.
     * Called by the frontend every 60 seconds.
     */
    public function list(array $vars = []): void
    {
        $this->requireAuth();
        $userId = (int)\Auth::getUserId();

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                'SELECT id, type, title, message, url, created_at
                 FROM notifications
                 WHERE user_id = ? AND read_at IS NULL
                 ORDER BY created_at DESC LIMIT 20'
            );
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json(['success' => true, 'data' => $notifications, 'count' => count($notifications)]);
        } catch (\Exception $e) {
            error_log('NotificationController::list failed: ' . $e->getMessage());
            $this->json(['success' => true, 'data' => [], 'count' => 0]);
        }
    }

    /**
     * Mark a single notification as read.
     * POST /api/notifications/read  { id: int }
     */
    public function markRead(array $vars = []): void
    {
        $this->requireAuth();
        $userId = (int)\Auth::getUserId();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->json(['success' => false, 'message' => 'Ungültige ID']);
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$id, $userId]);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('NotificationController::markRead failed: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Datenbankfehler']);
        }
    }

    /**
     * Mark all notifications of the current user as read.
     * POST /api/notifications/read-all
     */
    public function markAllRead(array $vars = []): void
    {
        $this->requireAuth();
        $userId = (int)\Auth::getUserId();

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL"
            );
            $stmt->execute([$userId]);
            $this->json(['success' => true]);
        } catch (\Exception $e) {
            error_log('NotificationController::markAllRead failed: ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Datenbankfehler']);
        }
    }

    // -------------------------------------------------------------------------
    // Static factory – create a notification for a user
    // -------------------------------------------------------------------------

    /**
     * Create a new notification for a specific user.
     *
     * @param int    $userId  Recipient user ID
     * @param string $type    Notification type, e.g. 'new_blog', 'new_poll'
     * @param string $title   Short title
     * @param string $message Optional longer message
     * @param string $url     Optional deep-link URL
     */
    public static function create(
        int    $userId,
        string $type,
        string $title,
        string $message = '',
        string $url     = ''
    ): void {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                'INSERT INTO notifications (user_id, type, title, message, url, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$userId, $type, $title, $message ?: null, $url ?: null]);
        } catch (\Exception $e) {
            error_log('NotificationController::create failed: ' . $e->getMessage());
        }
    }
}
