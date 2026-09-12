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

    // Counselor-initiated reschedule: moves an existing appointment to a new date/time
    // without resetting its status (an approved appointment stays approved — the counselor
    // is the one making the change, so it doesn't need re-approval). Returns the appointment
    // row (with the new date/time already applied) so the caller can notify the student and
    // push the update to Google Calendar without a second lookup.
    public static function reschedule(int $id, string $newDate, string $newTime, int $changedBy, ?string $remarks = null): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM appointments WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $appt = $stmt->fetch();
            if (!$appt) {
                throw new RuntimeException('Appointment not found.');
            }

            $check = $db->prepare(
                "SELECT id FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' AND id != ? FOR UPDATE"
            );
            $check->execute([$appt['counselor_id'], $newDate, $newTime, $id]);
            if ($check->fetch()) {
                throw new RuntimeException('That time is already booked by another approved appointment.');
            }

            $upd = $db->prepare('UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE id = ?');
            $upd->execute([$newDate, $newTime, $id]);

            $note = $remarks ?: "Rescheduled from {$appt['appointment_date']} {$appt['appointment_time']} to {$newDate} {$newTime}";
            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$id, $appt['status'], $appt['status'], $changedBy, $note]);

            $db->commit();

            $appt['appointment_date'] = $newDate;
            $appt['appointment_time'] = $newTime;
            return $appt;
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

    public static function approvedSlotTaken(int $counselorId, string $date, string $time)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id, student_id FROM appointments
             WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'approved'
             LIMIT 1"
        );
        $stmt->execute([$counselorId, $date, $time]);
        $row = $stmt->fetch();
        return $row ?: false;
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

    /**
     * Counselor-recorded walk-in: the student came to the office in person (or filled out
     * the paper form) rather than booking through the site, so the counselor logs it here
     * directly. Always stored with type = 'walk-in' regardless of the status chosen, so it's
     * clearly distinguishable from appointments the student booked online themselves.
     * Returns the new appointment ID.
     */
    public static function recordWalkIn(array $data): int
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Only guard against double-booking when this walk-in is itself taking an
            // "approved" (i.e. upcoming, calendar-holding) slot — a completed/no-show/pending
            // record doesn't hold a slot, so it can't conflict with anything.
            if ($data['status'] === 'approved') {
                $lock = $db->prepare(
                    "SELECT id FROM appointments
                     WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                     AND status = 'approved' FOR UPDATE"
                );
                $lock->execute([$data['counselor_id'], $data['appointment_date'], $data['appointment_time']]);
                if ($lock->fetch()) {
                    throw new RuntimeException('This slot is already taken by another approved appointment.');
                }
            }

            $stmt = $db->prepare(
                "INSERT INTO appointments
                 (student_id, counselor_id, concern_category_id, type, appointment_date, appointment_time, status, is_confidential, notes)
                 VALUES (:student_id, :counselor_id, :concern_category_id, 'walk-in', :appointment_date, :appointment_time, :status, :is_confidential, :notes)"
            );
            $stmt->execute([
                'student_id' => $data['student_id'],
                'counselor_id' => $data['counselor_id'],
                'concern_category_id' => $data['concern_category_id'] ?: null,
                'appointment_date' => $data['appointment_date'],
                'appointment_time' => $data['appointment_time'],
                'status' => $data['status'],
                'is_confidential' => !empty($data['is_confidential']) ? 1 : 0,
                'notes' => $data['notes'] ?: null,
            ]);
            $appointmentId = (int)$db->lastInsertId();

            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, NULL, ?, ?, ?)'
            );
            $log->execute([$appointmentId, $data['status'], $data['counselor_id'], 'Recorded as a walk-in appointment.']);

            $db->commit();
            return $appointmentId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Session notes a counselor has explicitly chosen to share with this student,
    // grouped by appointment_id (an appointment can have more than one shared note).
    public static function sharedNotesForStudent(int $studentId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT sn.appointment_id, sn.notes, sn.created_at,
                    u.first_name AS counselor_first, u.last_name AS counselor_last
             FROM session_notes sn
             JOIN appointments a ON a.id = sn.appointment_id
             JOIN users u ON u.id = sn.counselor_id
             WHERE a.student_id = ? AND sn.visible_to_student = 1
             ORDER BY sn.created_at DESC"
        );
        $stmt->execute([$studentId]);
        $byAppointment = [];
        foreach ($stmt->fetchAll() as $row) {
            $byAppointment[$row['appointment_id']][] = $row;
        }
        return $byAppointment;
    }

    // Categories dropdown
    public static function categories(): array
    {
        $db = Database::getConnection();
        return $db->query('SELECT id, name FROM concern_categories ORDER BY name')->fetchAll();
    }
}