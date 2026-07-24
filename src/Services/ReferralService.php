<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Models/Notification.php';
require_once __DIR__ . '/../Models/Referral.php';
require_once __DIR__ . '/GoogleSyncService.php';

class ReferralService
{
    // Notify all active counselors + admins that a new referral needs triage
    public static function notifyNewReferral(int $referralId, string $studentName, bool $isUrgent): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT id FROM users WHERE role IN ('counselor','admin') AND status = 'active'");
        $staff = $stmt->fetchAll();

        $prefix = $isUrgent ? '🔴 URGENT referral' : 'New referral';
        $message = "{$prefix} submitted for {$studentName}. Please review and process.";

        foreach ($staff as $s) {
            Notification::create((int)$s['id'], $message, null, 'in-app', $referralId);
        }
    }

    public static function notifyProcessed(array $referral): void
    {
        $statusLabels = [
            'accepted' => 'accepted',
            'for_clarification' => 'marked for clarification',
            'referred_back' => 'referred back',
        ];
        $label = $statusLabels[$referral['status']] ?? $referral['status'];

        if (!empty($referral['assigned_counselor_id'])) {
            Notification::create(
                (int)$referral['assigned_counselor_id'],
                "Referral {$referral['referral_no']} for {$referral['student_name']} was {$label} and assigned to you.",
                null,
                'in-app',
                (int)$referral['id']
            );
        }

        // Notify the student too, if this referral is linked to their account (e.g. self-submitted)
        if (!empty($referral['student_id'])) {
            $studentMessages = [
                'accepted' => "Your guidance request ({$referral['referral_no']}) has been accepted. A counselor will reach out to schedule your appointment.",
                'for_clarification' => "Your guidance request ({$referral['referral_no']}) needs clarification. The Guidance Office may reach out for more details.",
                'referred_back' => "Your guidance request ({$referral['referral_no']}) was referred back. Please check with the Guidance Office for details.",
            ];
            if (isset($studentMessages[$referral['status']])) {
                Notification::create((int)$referral['student_id'], $studentMessages[$referral['status']], null, 'in-app', (int)$referral['id']);
            }
        }
    }

    /**
     * Converts an accepted, student-linked referral into a scheduled (already-approved)
     * appointment with the assigned counselor. Requires the referral to already be linked
     * to a registered student account.
     */
    public static function convertToAppointment(array $referral, string $date, string $time, int $scheduledBy): int
    {
        if (empty($referral['student_id'])) {
            throw new RuntimeException('Link this referral to a registered student account before scheduling an appointment.');
        }
        if (empty($referral['assigned_counselor_id'])) {
            throw new RuntimeException('Assign a guidance counselor to this referral before scheduling an appointment.');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Guard against double-booking the counselor for this slot (approved-only rule)
            $lock = $db->prepare(
                "SELECT id FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' FOR UPDATE"
            );
            $lock->execute([$referral['assigned_counselor_id'], $date, $time]);
            if ($lock->fetch()) {
                throw new RuntimeException('The counselor already has an approved appointment at that time. Choose another slot.');
            }

            $notes = 'Scheduled from Guidance Referral ' . $referral['referral_no'] . '.';
            $stmt = $db->prepare(
                "INSERT INTO appointments
                 (student_id, counselor_id, type, appointment_date, appointment_time, status, is_confidential, notes)
                 VALUES (?, ?, 'walk-in', ?, ?, 'approved', 1, ?)"
            );
            $stmt->execute([$referral['student_id'], $referral['assigned_counselor_id'], $date, $time, $notes]);
            $appointmentId = (int)$db->lastInsertId();

            $log = $db->prepare(
                "INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, NULL, 'approved', ?, ?)"
            );
            $log->execute([$appointmentId, $scheduledBy, 'Created from referral ' . $referral['referral_no']]);

            Referral::linkAppointment((int)$referral['id'], $appointmentId);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        // Everything below runs only after the appointment was successfully committed.
        // Failures here (e.g. a notification insert or a Google API hiccup) must not be
        // treated as a failure to schedule the appointment itself, so they're isolated
        // from the transaction above and simply logged if something goes wrong.
        try {
            Notification::create(
                (int)$referral['student_id'],
                "A guidance appointment was scheduled for you on {$date} at " . date('g:i A', strtotime($time)) . ' following a referral.',
                $appointmentId
            );

            $fullAppointment = [
                'id' => $appointmentId,
                'student_id' => $referral['student_id'],
                'counselor_id' => $referral['assigned_counselor_id'],
                'appointment_date' => $date,
                'appointment_time' => $time,
            ];
            GoogleSyncService::pushCreate($fullAppointment);

            // Other students who preferred this exact same date/time in their own referral
            // couldn't get it — let them know so they're not left guessing. Their referral
            // itself is untouched; the Guidance Office still needs to pick another slot for them.
            $siblings = $db->prepare(
                "SELECT id, student_id, referral_no FROM referrals
                 WHERE id != ? AND appointment_id IS NULL AND student_id IS NOT NULL
                   AND preferred_date = ? AND preferred_time = ?"
            );
            $siblings->execute([(int)$referral['id'], $date, $time]);
            foreach ($siblings->fetchAll() as $sibling) {
                Notification::create(
                    (int)$sibling['student_id'],
                    "Your preferred time ({$date} at " . date('g:i A', strtotime($time)) . ") was taken by another student's request. The Guidance Office will follow up with an alternate schedule for your request ({$sibling['referral_no']}).",
                    null,
                    'in-app',
                    (int)$sibling['id']
                );
            }
        } catch (Exception $e) {
            error_log('convertToAppointment post-commit notifications failed: ' . $e->getMessage());
        }

        return $appointmentId;
    }
}
