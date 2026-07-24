<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Models/Appointment.php';
require_once __DIR__ . '/../Models/Referral.php';
require_once __DIR__ . '/../Models/Notification.php';

/**
 * Lightweight stand-in for a scheduled reminder job. XAMPP deployments on a school
 * PC usually don't have a real cron/Task Scheduler entry wired up, so instead of
 * relying on one, this runs a cheap "have I already reminded this person today?"
 * check on page load for logged-in students and counselors:
 *   - Students get a reminder the day before an approved appointment.
 *   - Counselors get a once-a-day digest of today's approved appointments, plus
 *     how many referrals are still waiting to be triaged.
 * Every insert is guarded by a SELECT for an existing matching notification first,
 * so calling this on every request never produces duplicate spam.
 */
class ReminderService
{
    public static function run(array $user): void
    {
        try {
            if ($user['role'] === ROLE_STUDENT) {
                self::remindStudentTomorrow($user['id']);
            } elseif ($user['role'] === ROLE_COUNSELOR) {
                self::remindCounselorToday($user['id']);
            }
        } catch (Exception $e) {
            // Never let a reminder failure break page rendering.
            error_log('ReminderService failed: ' . $e->getMessage());
        }
    }

    private static function alreadyNotifiedToday(int $userId, string $messageLike): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id FROM notifications
             WHERE user_id = ? AND message LIKE ? AND DATE(sent_at) = CURDATE() LIMIT 1"
        );
        $stmt->execute([$userId, $messageLike]);
        return (bool)$stmt->fetch();
    }

    private static function remindStudentTomorrow(int $studentId): void
    {
        $appointments = Appointment::forStudentTomorrow($studentId);
        foreach ($appointments as $a) {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT id FROM notifications WHERE user_id = ? AND appointment_id = ? AND message LIKE 'Reminder:%' LIMIT 1"
            );
            $stmt->execute([$studentId, $a['id']]);
            if ($stmt->fetch()) continue; // already reminded for this specific appointment

            Notification::create(
                $studentId,
                "Reminder: you have a guidance appointment tomorrow, {$a['appointment_date']} at " . date('g:i A', strtotime($a['appointment_time'])) . " with {$a['counselor_first']} {$a['counselor_last']}.",
                (int)$a['id']
            );
        }
    }

    private static function remindCounselorToday(int $counselorId): void
    {
        if (self::alreadyNotifiedToday($counselorId, 'Today\'s schedule:%')) {
            return;
        }

        $today = Appointment::forCounselorToday($counselorId);
        $pendingReferrals = Referral::countByStatus('pending');

        if (!$today && !$pendingReferrals) {
            return; // nothing to say today, don't send an empty digest
        }

        if ($today) {
            $times = array_map(fn($a) => date('g:i A', strtotime($a['appointment_time'])) . ' (' . $a['student_first'] . ' ' . $a['student_last'] . ')', $today);
            $message = "Today's schedule: " . count($today) . ' appointment' . (count($today) > 1 ? 's' : '') . ' — ' . implode(', ', $times) . '.';
        } else {
            $message = "Today's schedule: no appointments booked yet.";
        }

        if ($pendingReferrals > 0) {
            $message .= " {$pendingReferrals} referral" . ($pendingReferrals > 1 ? 's are' : ' is') . ' still awaiting triage.';
        }

        Notification::create($counselorId, $message);
    }
}
