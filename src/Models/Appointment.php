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
        $sql .= " ORDER BY a.updated_at DESC, a.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $newStatus, int $changedBy, ?string $remarks = null, ?string $cancellationReason = null): void
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT status FROM appointments WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetchColumn();

            if ($newStatus === STATUS_CANCELLED && $cancellationReason) {
                $upd = $db->prepare('UPDATE appointments SET status = ?, cancellation_reason = ? WHERE id = ?');
                $upd->execute([$newStatus, $cancellationReason, $id]);
            } else {
                $upd = $db->prepare('UPDATE appointments SET status = ? WHERE id = ?');
                $upd->execute([$newStatus, $id]);
            }

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
    /**
     * A counselor proposing a new time no longer applies immediately — it parks the
     * appointment in a 'rescheduled' (awaiting confirmation) state with the proposed date/time
     * held separately, so the original slot is preserved until the student responds via
     * confirmReschedule() or rejectReschedule(). Can be called again while still pending, so a
     * counselor can correct their own proposal before the student acts on it.
     */
    public static function proposeReschedule(int $id, string $newDate, string $newTime, int $changedBy, ?string $remarks = null): array
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
            if (!in_array($appt['status'], ['approved', 'rescheduled'], true)) {
                throw new RuntimeException('Only approved appointments can be rescheduled.');
            }

            $check = $db->prepare(
                "SELECT id FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' AND id != ? FOR UPDATE"
            );
            $check->execute([$appt['counselor_id'], $newDate, $newTime, $id]);
            if ($check->fetch()) {
                throw new RuntimeException('That time is already booked by another approved appointment.');
            }

            $upd = $db->prepare(
                "UPDATE appointments SET status = 'rescheduled', proposed_date = ?, proposed_time = ?, rescheduled_at = NOW() WHERE id = ?"
            );
            $upd->execute([$newDate, $newTime, $id]);

            $note = $remarks ?: "Proposed moving from {$appt['appointment_date']} {$appt['appointment_time']} to {$newDate} {$newTime}; awaiting student confirmation.";
            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$id, $appt['status'], 'rescheduled', $changedBy, $note]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return self::findById($id);
    }

    /**
     * Student accepts the counselor's proposed new time: the proposal becomes the real
     * appointment_date/time, status returns to 'approved', and the proposal fields are cleared.
     */
    public static function confirmReschedule(int $id, int $studentId): array
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
            if ((int)$appt['student_id'] !== $studentId) {
                throw new RuntimeException('Not authorized for this appointment.');
            }
            if ($appt['status'] !== 'rescheduled' || !$appt['proposed_date'] || !$appt['proposed_time']) {
                throw new RuntimeException('There is no pending reschedule to confirm.');
            }

            // Re-check the slot is still free — another appointment could have taken it
            // in the time between the counselor's proposal and the student's confirmation.
            $check = $db->prepare(
                "SELECT id FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' AND id != ? FOR UPDATE"
            );
            $check->execute([$appt['counselor_id'], $appt['proposed_date'], $appt['proposed_time'], $id]);
            if ($check->fetch()) {
                throw new RuntimeException('That time slot was taken in the meantime. Please contact the Guidance Office to arrange a new time.');
            }

            $upd = $db->prepare(
                "UPDATE appointments SET appointment_date = proposed_date, appointment_time = proposed_time,
                 status = 'approved', proposed_date = NULL, proposed_time = NULL WHERE id = ?"
            );
            $upd->execute([$id]);

            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks) VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$id, 'rescheduled', 'approved', $studentId, 'Student confirmed the new time.']);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return self::findById($id);
    }

    /**
     * Student declines the proposed new time (or simply doesn't confirm it) — since the
     * original slot can no longer be assumed to still be free/intended, the appointment is
     * cancelled outright rather than silently reverting to the old time.
     */
    public static function rejectReschedule(int $id, int $studentId): array
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
            if ((int)$appt['student_id'] !== $studentId) {
                throw new RuntimeException('Not authorized for this appointment.');
            }
            if ($appt['status'] !== 'rescheduled') {
                throw new RuntimeException('There is no pending reschedule to respond to.');
            }

            $upd = $db->prepare(
                "UPDATE appointments SET status = 'cancelled', proposed_date = NULL, proposed_time = NULL WHERE id = ?"
            );
            $upd->execute([$id]);

            $log = $db->prepare(
                'INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks) VALUES (?, ?, ?, ?, ?)'
            );
            $log->execute([$id, 'rescheduled', 'cancelled', $studentId, 'Student did not confirm the proposed new time; appointment cancelled.']);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return self::findById($id);
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