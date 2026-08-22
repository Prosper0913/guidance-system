<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Models/GoogleToken.php';
require_once __DIR__ . '/../Services/GoogleCalendarService.php';

class Availability
{
    // Check whether a slot [start,end) overlaps any busy interval
    private static function overlapsBusy(int $slotStart, int $slotEnd, array $busyIntervals): bool
    {
        foreach ($busyIntervals as $b) {
            if ($slotStart < $b['end'] && $slotEnd > $b['start']) {
                return true;
            }
        }
        return false;
    }

    // Get weekly recurring availability rows for a counselor
    public static function getWeekly(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM counselor_availability WHERE counselor_id = ? AND is_active = 1');
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    public static function addWeekly(int $counselorId, int $dayOfWeek, string $start, string $end, int $slotMinutes = 30): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO counselor_availability (counselor_id, day_of_week, start_time, end_time, slot_minutes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$counselorId, $dayOfWeek, $start, $end, $slotMinutes]);
        return (int)$db->lastInsertId();
    }

    public static function removeWeekly(int $id, int $counselorId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM counselor_availability WHERE id = ? AND counselor_id = ?');
        $stmt->execute([$id, $counselorId]);
    }

    public static function getExceptionForDate(int $counselorId, string $date): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM counselor_availability_exceptions WHERE counselor_id = ? AND exception_date = ? LIMIT 1');
        $stmt->execute([$counselorId, $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function addException(int $counselorId, string $date, bool $isAvailable, ?string $reason): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO counselor_availability_exceptions (counselor_id, exception_date, is_available, reason)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$counselorId, $date, $isAvailable ? 1 : 0, $reason]);
        return (int)$db->lastInsertId();
    }

    // Slots locked by an approved appointment for a counselor on a given date.
    // Pending requests do NOT block the slot — multiple students may request the
    // same time; the counselor picks which one to approve.
    public static function getBookedTimes(int $counselorId, string $date): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT appointment_time FROM appointments
             WHERE counselor_id = ? AND appointment_date = ? AND status = 'approved'"
        );
        $stmt->execute([$counselorId, $date]);
        return array_column($stmt->fetchAll(), 'appointment_time');
    }

    // Generate open slots for a counselor on a given date
    public static function getAvailableSlots(int $counselorId, string $date): array
    {
        $dayOfWeek = (int)date('w', strtotime($date));

        $exception = self::getExceptionForDate($counselorId, $date);
        if ($exception && !$exception['is_available']) {
            return []; // counselor blocked this date entirely
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM counselor_availability WHERE counselor_id = ? AND day_of_week = ? AND is_active = 1'
        );
        $stmt->execute([$counselorId, $dayOfWeek]);
        $blocks = $stmt->fetchAll();

        if (!$blocks) return [];

        $booked = self::getBookedTimes($counselorId, $date);

        // Two-way sync: exclude times the counselor is busy on their connected Google Calendar.
        // Fails open (empty array) if not connected or the API call errors out, so Google
        // downtime never blocks the internal booking system.
        $googleBusy = GoogleToken::isConnected($counselorId)
            ? GoogleCalendarService::getBusyIntervals($counselorId, $date)
            : [];

        $slots = [];

        foreach ($blocks as $block) {
            $start = strtotime($date . ' ' . $block['start_time']);
            $end   = strtotime($date . ' ' . $block['end_time']);
            $step  = (int)$block['slot_minutes'] * 60;

            for ($t = $start; $t < $end; $t += $step) {
                $timeStr = date('H:i:s', $t);
                if (in_array($timeStr, $booked, true)) continue;
                // skip past slots if date is today
                if ($date === date('Y-m-d') && $t <= time()) continue;
                if (self::overlapsBusy($t, $t + $step, $googleBusy)) continue;
                // Exclude lunch break: 12:00 PM - 1:00 PM
                $lunchStart = strtotime($date . ' 12:00:00');
                $lunchEnd   = strtotime($date . ' 13:00:00');
                if ($t < $lunchEnd && $t + $step > $lunchStart) continue;
                $slots[] = $timeStr;
            }
        }
        return $slots;
    }
}
