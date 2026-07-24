<?php
require_once __DIR__ . '/../../config/database.php';

class Notification
{
    public static function create(int $userId, string $message, ?int $appointmentId = null, string $channel = 'in-app', ?int $referralId = null): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_id, appointment_id, referral_id, message, channel) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $appointmentId, $referralId, $message, $channel]);
    }

    public static function forUser(int $userId, int $limit = 20): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY sent_at DESC LIMIT ?');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function markRead(int $id, int $userId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
