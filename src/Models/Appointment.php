<?php
require_once __DIR__ . '/../../config/database.php';

class Appointment
{
    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT a.*, cc.name AS category_name,
                    s.first_name AS student_first, s.last_name AS student_last,
                    c.first_name AS counselor_first, c.last_name AS counselor_last
             FROM appointments a
             LEFT JOIN concern_categories cc ON cc.id = a.concern_category_id
             JOIN users s ON s.id = a.student_id
             JOIN users c ON c.id = a.counselor_id
             WHERE a.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function forStudent(int $studentId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT a.*, cc.name AS category_name, c.first_name AS counselor_first, c.last_name AS counselor_last
             FROM appointments a
             LEFT JOIN concern_categories cc ON cc.id = a.concern_category_id
             JOIN users c ON c.id = a.counselor_id
             WHERE a.student_id = ?
             ORDER BY a.appointment_date DESC, a.appointment_time DESC"
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    public static function forCounselor(int $counselorId, ?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT a.*, cc.name AS category_name, s.first_name AS student_first, s.last_name AS student_last,
                       (SELECT COUNT(*) FROM appointments a2
                        WHERE a2.counselor_id = a.counselor_id
                          AND a2.appointment_date = a.appointment_date
                          AND a2.appointment_time = a.appointment_time
                          AND a2.status = 'pending' AND a2.id != a.id) AS other_pending_count
                FROM appointments a
                LEFT JOIN concern_categories cc ON cc.id = a.concern_category_id
                JOIN users s ON s.id = a.student_id
                WHERE a.counselor_id = ?";
        $params = [$counselorId];
        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $newStatus, int $changedBy, ?string $remarks = null): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT status FROM appointments WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetchColumn();

            $upd = $db->prepare('UPDATE appointments SET status = ? WHERE id = ?');
            $upd->execute([$newStatus, $id]);

            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$id, $current, $newStatus, $changedBy, $remarks]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function reschedule(int $id, string $newDate, string $newTime, int $changedBy): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Lock target slot check
            $check = $db->prepare(
                "SELECT id FROM appointments WHERE counselor_id = (SELECT counselor_id FROM appointments WHERE id = ?)
                 AND appointment_date = ? AND appointment_time = ? AND status IN ('pending','approved') FOR UPDATE"
            );
            $check->execute([$id, $newDate, $newTime]);
            if ($check->fetch()) {
                throw new RuntimeException('Selected slot is no longer available.');
            }

            $upd = $db->prepare(
                "UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'pending' WHERE id = ?"
            );
            $upd->execute([$newDate, $newTime, $id]);

            $log = $db->prepare(
                "INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, 'rescheduled', 'pending', ?, ?)"
            );
            $log->execute([$id, $changedBy, "Rescheduled to $newDate $newTime"]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Approve a pending appointment and automatically decline any other PENDING
     * requests for the same counselor/date/time slot, since only one can hold it.
     * Returns the list of sibling appointments that were auto-declined, so the
     * caller can notify those students.
     */
    public static function approveAndResolveConflicts(int $id, int $changedBy, ?string $remarks = null): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('Appointment not found.');
            }
            if ($target['status'] !== 'pending') {
                throw new RuntimeException('This request is no longer pending.');
            }

            // Race-safety: make sure no other appointment for this exact slot got approved
            // in between the counselor loading the page and clicking Approve.
            $check = $db->prepare(
                "SELECT id FROM appointments
                 WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' AND id != ? FOR UPDATE"
            );
            $check->execute([$target['counselor_id'], $target['appointment_date'], $target['appointment_time'], $id]);
            if ($check->fetch()) {
                throw new RuntimeException('This time slot was already approved for another student.');
            }

            $upd = $db->prepare("UPDATE appointments SET status = 'approved' WHERE id = ?");
            $upd->execute([$id]);

            $log = $db->prepare(
                "INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, 'pending', 'approved', ?, ?)"
            );
            $log->execute([$id, $changedBy, $remarks]);

            // Find and auto-decline sibling pending requests for the same slot
            $siblingsStmt = $db->prepare(
                "SELECT * FROM appointments
                 WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'pending' AND id != ?"
            );
            $siblingsStmt->execute([$target['counselor_id'], $target['appointment_date'], $target['appointment_time'], $id]);
            $siblings = $siblingsStmt->fetchAll();

            $autoDeclineRemark = 'This time slot has been taken by another student.';
            foreach ($siblings as $s) {
                $upd2 = $db->prepare("UPDATE appointments SET status = 'declined' WHERE id = ?");
                $upd2->execute([$s['id']]);

                $log2 = $db->prepare(
                    "INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                     VALUES (?, 'pending', 'declined', ?, ?)"
                );
                $log2->execute([$s['id'], $changedBy, $autoDeclineRemark]);
            }

            $db->commit();
            return $siblings;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function forStudentTomorrow(int $studentId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT a.*, c.first_name AS counselor_first, c.last_name AS counselor_last
             FROM appointments a
             JOIN users c ON c.id = a.counselor_id
             WHERE a.student_id = ? AND a.status = 'approved'
               AND a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    public static function forCounselorToday(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT a.*, s.first_name AS student_first, s.last_name AS student_last
             FROM appointments a
             JOIN users s ON s.id = a.student_id
             WHERE a.counselor_id = ? AND a.status = 'approved' AND a.appointment_date = CURDATE()
             ORDER BY a.appointment_time ASC"
        );
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    // Categories dropdown
    public static function categories(): array
    {
        $db = Database::getConnection();
        return $db->query('SELECT id, name FROM concern_categories ORDER BY name')->fetchAll();
    }
}
