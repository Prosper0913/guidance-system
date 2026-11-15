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

    /**
     * Block every WEEKDAY from $from through $to (inclusive, 'Y-m-d'). Saturdays and Sundays
     * are skipped because the office is already closed then. Dates the counselor has already
     * blocked are left alone, so re-submitting an overlapping range doesn't create duplicates.
     * One row per date is stored, so the existing per-date checks keep working unchanged.
     * Returns the number of dates newly blocked.
     */
    public static function addExceptionRange(int $counselorId, string $from, string $to, ?string $reason): int
    {
        $db = Database::getConnection();
        $existing = $db->prepare(
            'SELECT exception_date FROM counselor_availability_exceptions
             WHERE counselor_id = ? AND is_available = 0 AND exception_date BETWEEN ? AND ?'
        );
        $existing->execute([$counselorId, $from, $to]);
        $already = array_flip(array_column($existing->fetchAll(), 'exception_date'));

        $insert = $db->prepare(
            'INSERT INTO counselor_availability_exceptions (counselor_id, exception_date, is_available, reason)
             VALUES (?, ?, 0, ?)'
        );
        $added = 0;
        $db->beginTransaction();
        try {
            for ($d = new DateTime($from); $d <= new DateTime($to); $d->modify('+1 day')) {
                if (in_array((int)$d->format('w'), [0, 6], true)) continue;
                $date = $d->format('Y-m-d');
                if (isset($already[$date])) continue;
                $insert->execute([$counselorId, $date, $reason]);
                $added++;
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return $added;
    }

    // Upcoming blocked dates for the availability page, oldest first.
    public static function getUpcomingBlocked(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT exception_date, reason FROM counselor_availability_exceptions
             WHERE counselor_id = ? AND is_available = 0 AND exception_date >= CURDATE()
             ORDER BY exception_date ASC'
        );
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    // Un-block every blocked date from $from through $to (inclusive) for this counselor.
    public static function removeExceptionRange(int $counselorId, string $from, string $to): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM counselor_availability_exceptions
             WHERE counselor_id = ? AND is_available = 0 AND exception_date BETWEEN ? AND ?'
        );
        $stmt->execute([$counselorId, $from, $to]);
    }

    // Pending/approved/awaiting-confirmation appointments inside a date range. Blocking dates
    // does not cancel these, so the counselor is warned about them.
    public static function countActiveAppointmentsInRange(int $counselorId, string $from, string $to): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM appointments
             WHERE counselor_id = ? AND appointment_date BETWEEN ? AND ?
               AND status IN ('pending','approved','rescheduled')"
        );
        $stmt->execute([$counselorId, $from, $to]);
        return (int)$stmt->fetchColumn();
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

        // The Guidance Office is permanently closed Saturdays (6) and Sundays (0), regardless
        // of what a counselor has configured in their weekly availability — this is a fixed
        // policy, not a per-counselor setting.
        if (in_array($dayOfWeek, [0, 6], true)) {
            return [];
        }

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
