<?php
require_once __DIR__ . '/../Models/Notification.php';

class NotificationService
{
    public static function appointmentRequested(array $appointment, int $counselorId): void
    {
        Notification::create(
            $counselorId,
            "New {$appointment['type']} appointment request for {$appointment['appointment_date']} {$appointment['appointment_time']}.",
            $appointment['id']
        );
    }

    public static function statusChanged(array $appointment, string $newStatus, ?string $remarks = null): void
    {
        $messages = [
            'approved'    => "Your appointment on {$appointment['appointment_date']} at {$appointment['appointment_time']} was approved.",
            'declined'    => "Your appointment request on {$appointment['appointment_date']} was declined.",
            'cancelled'   => "Your appointment on {$appointment['appointment_date']} was cancelled.",
            'rescheduled' => "Your appointment has been rescheduled. Please check your dashboard for the new date.",
            'completed'   => "Your appointment on {$appointment['appointment_date']} has been marked completed.",
            'no-show'     => "You were marked as a no-show for your appointment on {$appointment['appointment_date']}.",
        ];
        $message = $messages[$newStatus] ?? "Your appointment status changed to {$newStatus}.";
        if ($remarks) {
            $message .= ' Note: ' . $remarks;
        }
        Notification::create((int)$appointment['student_id'], $message, (int)$appointment['id']);
    }

    // Free-form message from a counselor/admin to a student about a specific appointment,
    // independent of any status change (e.g. "Can you come 15 minutes earlier?").
    public static function customMessage(array $appointment, string $message): void
    {
        Notification::create((int)$appointment['student_id'], $message, (int)$appointment['id']);
    }

    public static function referralCancelled(array $referral): void
    {
        $message = "Your referral {$referral['referral_no']} has been cancelled by the Guidance Office. If you still need assistance, you can submit a new referral or pick a new time using the same details from your My Requests page.";
        if ($referral['student_id']) {
            Notification::create((int)$referral['student_id'], $message, null, 'in-app', (int)$referral['id']);
        }
    }

    public static function reminder(array $appointment): void
    {
        Notification::create(
            (int)$appointment['student_id'],
            "Reminder: you have a guidance appointment on {$appointment['appointment_date']} at {$appointment['appointment_time']}.",
            (int)$appointment['id']
        );
    }
}