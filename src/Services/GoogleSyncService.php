<?php
require_once __DIR__ . '/GoogleCalendarService.php';
require_once __DIR__ . '/../../config/database.php';

class GoogleSyncService
{
    private static function buildEventPayload(array $appointment): array
    {
        $start = $appointment['appointment_date'] . 'T' . $appointment['appointment_time'];
        $endTs = strtotime($start) + 30 * 60; // default 30-min block; adjust if you store duration per appointment
        $studentName = trim(($appointment['student_first'] ?? '') . ' ' . ($appointment['student_last'] ?? ''));

        return [
            'summary' => 'Guidance Appointment' . ($studentName ? ' — ' . $studentName : ''),
            'description' => 'Booked via TCM Guidance Appointment System.'
                . (!empty($appointment['category_name']) ? ' Concern: ' . $appointment['category_name'] : ''),
            'start' => ['dateTime' => date(DateTime::RFC3339, strtotime($start)), 'timeZone' => APP_TIMEZONE],
            'end'   => ['dateTime' => date(DateTime::RFC3339, $endTs), 'timeZone' => APP_TIMEZONE],
        ];
    }

    // Call when an appointment is approved
    public static function pushCreate(array $appointment): void
    {
        if (empty($appointment['counselor_id'])) return;
        $eventId = GoogleCalendarService::createEvent(
            (int)$appointment['counselor_id'],
            self::buildEventPayload($appointment)
        );
        if ($eventId) {
            $db = Database::getConnection();
            $stmt = $db->prepare('UPDATE appointments SET google_event_id = ? WHERE id = ?');
            $stmt->execute([$eventId, $appointment['id']]);
        }
    }

    // Call when a previously-approved appointment is rescheduled
    public static function pushUpdate(array $appointment): void
    {
        if (empty($appointment['google_event_id']) || empty($appointment['counselor_id'])) return;
        GoogleCalendarService::updateEvent(
            (int)$appointment['counselor_id'],
            $appointment['google_event_id'],
            self::buildEventPayload($appointment)
        );
    }

    // Call when an appointment is cancelled/declined/no-show and had a synced event
    public static function pushDelete(array $appointment): void
    {
        if (empty($appointment['google_event_id']) || empty($appointment['counselor_id'])) return;
        $ok = GoogleCalendarService::deleteEvent((int)$appointment['counselor_id'], $appointment['google_event_id']);
        if ($ok) {
            $db = Database::getConnection();
            $stmt = $db->prepare('UPDATE appointments SET google_event_id = NULL WHERE id = ?');
            $stmt->execute([$appointment['id']]);
        }
    }
}
