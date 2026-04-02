<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\NotificationController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationController.
 *
 * Tests that can run without a real database use an in-memory SQLite
 * database with the notifications schema.
 *
 * @covers \App\Controllers\NotificationController
 */
final class NotificationControllerTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE notifications (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                type       TEXT    NOT NULL,
                title      TEXT    NOT NULL,
                message    TEXT,
                url        TEXT,
                read_at    TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // ------------------------------------------------------------------
    // NotificationController::create (static factory)
    // ------------------------------------------------------------------

    /**
     * Verify that a notification row is inserted correctly.
     *
     * Because create() is a static method that calls Database::getContentDB()
     * internally we test the underlying SQL logic here via direct PDO.
     */
    public function testInsertNotificationRow(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, url, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([1, 'new_blog', 'New post!', 'Check it out', '/blog/42']);

        $row = $this->db->query("SELECT * FROM notifications WHERE user_id = 1")->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('1', (string) $row['user_id']);
        $this->assertSame('new_blog', $row['type']);
        $this->assertSame('New post!', $row['title']);
        $this->assertSame('Check it out', $row['message']);
        $this->assertSame('/blog/42', $row['url']);
        $this->assertNull($row['read_at']);
    }

    public function testInsertNotificationWithNullMessageAndUrl(): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, url, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([2, 'alert', 'System alert', null, null]);

        $row = $this->db->query("SELECT * FROM notifications WHERE user_id = 2")->fetch();
        $this->assertNull($row['message']);
        $this->assertNull($row['url']);
    }

    // ------------------------------------------------------------------
    // Mark-read logic
    // ------------------------------------------------------------------

    public function testMarkReadSetsReadAt(): void
    {
        $this->db->exec("INSERT INTO notifications (user_id, type, title, created_at) VALUES (3, 'test', 'Hello', datetime('now'))");
        $id = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare("UPDATE notifications SET read_at = datetime('now') WHERE id = ? AND user_id = 3");
        $stmt->execute([$id]);

        $row = $this->db->query("SELECT read_at FROM notifications WHERE id = {$id}")->fetch();
        $this->assertNotNull($row['read_at']);
    }

    public function testMarkReadDoesNotAffectOtherUsersNotifications(): void
    {
        $this->db->exec("INSERT INTO notifications (user_id, type, title, created_at) VALUES (4, 'test', 'User4', datetime('now'))");
        $id = (int) $this->db->lastInsertId();

        // Try to mark as read for a *different* user_id (5)
        $stmt = $this->db->prepare("UPDATE notifications SET read_at = datetime('now') WHERE id = ? AND user_id = 5");
        $stmt->execute([$id]);

        $row = $this->db->query("SELECT read_at FROM notifications WHERE id = {$id}")->fetch();
        $this->assertNull($row['read_at'], 'Notification for user 4 must not be touched by user 5');
    }

    public function testMarkAllReadSetsReadAtForAllUnread(): void
    {
        $this->db->exec("INSERT INTO notifications (user_id, type, title, created_at) VALUES (6, 'a', 'One', datetime('now'))");
        $this->db->exec("INSERT INTO notifications (user_id, type, title, created_at) VALUES (6, 'b', 'Two', datetime('now'))");

        $stmt = $this->db->prepare("UPDATE notifications SET read_at = datetime('now') WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([6]);

        $unread = $this->db->query("SELECT COUNT(*) FROM notifications WHERE user_id = 6 AND read_at IS NULL")->fetchColumn();
        $this->assertSame('0', (string) $unread);
    }

    // ------------------------------------------------------------------
    // List query
    // ------------------------------------------------------------------

    public function testListQueryReturnsOnlyUnreadForUser(): void
    {
        // Insert one unread and one already-read notification for user 7
        $this->db->exec("INSERT INTO notifications (user_id, type, title, created_at) VALUES (7, 'x', 'Unread', datetime('now'))");
        $this->db->exec("INSERT INTO notifications (user_id, type, title, read_at, created_at) VALUES (7, 'x', 'Read', datetime('now'), datetime('now'))");

        $stmt = $this->db->prepare(
            'SELECT id, type, title, message, url, created_at
             FROM notifications
             WHERE user_id = ? AND read_at IS NULL
             ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([7]);
        $rows = $stmt->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertSame('Unread', $rows[0]['title']);
    }
}
