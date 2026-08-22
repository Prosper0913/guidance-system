<?php
require_once __DIR__ . '/../../config/database.php';

class SpecialNeeds
{
    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO special_needs_monitoring
             (student_id, assigned_counselor_id, condition_type, accommodations, monitoring_frequency, last_check_in, next_check_in)
             VALUES (:student_id, :assigned_counselor_id, :condition_type, :accommodations, :monitoring_frequency, :last_check_in, :next_check_in)'
        );
        $stmt->execute($data);

        // Flag the student profile too
        $flag = $db->prepare('UPDATE student_profiles SET has_special_needs = 1 WHERE user_id = ?');
        $flag->execute([$data['student_id']]);

        return (int)$db->lastInsertId();
    }

    public static function forCounselor(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT sn.*, u.first_name, u.last_name
             FROM special_needs_monitoring sn
             JOIN users u ON u.id = sn.student_id
             WHERE sn.assigned_counselor_id = ?
             ORDER BY sn.next_check_in ASC"
        );
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    public static function dueForCheckIn(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT sn.*, u.first_name, u.last_name
             FROM special_needs_monitoring sn
             JOIN users u ON u.id = sn.student_id
             WHERE sn.assigned_counselor_id = ? AND sn.next_check_in <= CURDATE()
             ORDER BY sn.next_check_in ASC"
        );
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    public static function updateCheckIn(int $id, string $lastCheckIn, string $nextCheckIn): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE special_needs_monitoring SET last_check_in = ?, next_check_in = ? WHERE id = ?');
        $stmt->execute([$lastCheckIn, $nextCheckIn, $id]);
    }
}
