<?php

declare(strict_types=1);

namespace App\Controllers;

use Twig\Environment;

/**
 * NotificationController
 *
 * Manages in-app notifications.
 *
 * Endpoints:
 *   GET  /api/notifications          → JSON list of unread notifications (polling)
 *   GET  /api/notifications/stream   → Server-Sent Events stream (real-time)
 *   POST /api/notifications/read     → Mark notification(s) as read
 *   POST /api/notifications/read-all → Mark all as read
 */
class NotificationController extends BaseController
{
    public function __construct(Environment $twig)
    {
        parent::__construct($twig);
    }

    /**
     * Server-Sent Events (SSE) stream for real-time notifications.
     *
     * Opens a persistent HTTP connection and pushes new notifications to
     * the client as `text/event-stream` events.  The connection is kept
     * alive for up to 60 seconds; the browser automatically reconnects.
     *
     * GET /api/notifications/stream
     */
    public function stream(array $vars = []): void
    {
        $this->requireAuth();
        $userId = (int)\Auth::getUserId();

        // Disable output buffering and time limits for a long-lived connection
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // Disable nginx proxy buffering
        header('Connection: keep-alive');

        // Send the last notification ID seen by the client as the retry hint
        $lastEventId = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);

        $maxLoops  = 20;  // 20 × 3 s = 60 s per connection (browser will reconnect)
        $sleepSecs = 3;

        for ($i = 0; $i < $maxLoops; $i++) {
            // Abort if the client disconnected
            if (connection_aborted()) {
                break;
            }

            try {
                $db   = \Database::getContentDB();
                $stmt = $db->prepare(
                    'SELECT id, type, title, message, url, created_at
                     FROM notifications
                     WHERE user_id = ? AND read_at IS NULL AND id > ?
                     ORDER BY created_at ASC LIMIT 20'
                );
                $stmt->execute([$userId, $lastEventId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $id          = (int)$row['id'];
                    $lastEventId = max($lastEventId, $id);
                    $data        = json_encode($row, JSON_UNESCAPED_UNICODE);
                    echo "id: {$id}\n";
                    echo "event: notification\n";
                    echo "data: {$data}\n\n";
                }

                // Also send a heartbeat every cycle to keep the connection alive
                echo ": heartbeat\n\n";
            } catch (\Exception $e) {
                error_log('NotificationController::stream failed: ' . $e->getMessage());
                echo "event: error\ndata: {\"message\":\"stream error\"}\n\n";
                break;
            }

            flush();
            sleep($sleepSecs);
        }
    }


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
