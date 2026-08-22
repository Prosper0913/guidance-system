<?php
require_once __DIR__ . '/../../config/database.php';

class ReportService
{
    // Appointments by status within a date range (optionally scoped to one counselor)
    public static function statusSummary(?int $counselorId = null, ?string $from = null, ?string $to = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT status, COUNT(*) AS total FROM appointments WHERE 1=1";
        $params = [];
        if ($counselorId) { $sql .= " AND counselor_id = ?"; $params[] = $counselorId; }
        if ($from)        { $sql .= " AND appointment_date >= ?"; $params[] = $from; }
        if ($to)          { $sql .= " AND appointment_date <= ?"; $params[] = $to; }
        $sql .= " GROUP BY status";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function concernCategoryBreakdown(?int $counselorId = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT cc.name, COUNT(*) AS total
                FROM appointments a
                JOIN concern_categories cc ON cc.id = a.concern_category_id
                WHERE 1=1";
        $params = [];
        if ($counselorId) { $sql .= " AND a.counselor_id = ?"; $params[] = $counselorId; }
        $sql .= " GROUP BY cc.name ORDER BY total DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function attendanceRate(?int $counselorId = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT al.status, COUNT(*) AS total
                FROM attendance_logs al
                JOIN appointments a ON a.id = al.appointment_id
                WHERE 1=1";
        $params = [];
        if ($counselorId) { $sql .= " AND a.counselor_id = ?"; $params[] = $counselorId; }
        $sql .= " GROUP BY al.status";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function counselorCaseload(): array
    {
        $db = Database::getConnection();
        $sql = "SELECT u.first_name, u.last_name, COUNT(a.id) AS total_appointments
                FROM users u
                LEFT JOIN appointments a ON a.counselor_id = u.id
                WHERE u.role = 'counselor'
                GROUP BY u.id ORDER BY total_appointments DESC";
        return $db->query($sql)->fetchAll();
    }

    public static function busiestSlots(?int $counselorId = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT appointment_time, COUNT(*) AS total FROM appointments WHERE 1=1";
        $params = [];
        if ($counselorId) { $sql .= " AND counselor_id = ?"; $params[] = $counselorId; }
        $sql .= " GROUP BY appointment_time ORDER BY total DESC LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

}
