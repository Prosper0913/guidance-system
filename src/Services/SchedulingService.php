<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Models/Availability.php';
require_once __DIR__ . '/NotificationService.php';

class SchedulingService
{
    /**
     * Book an appointment with a transactional slot-lock to prevent double booking.
     * Returns the new appointment ID.
     * Throws RuntimeException if the slot is unavailable.
     */
    public static function book(array $data): int
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // ADJUSTED: Block any booking attempt during lunch time (12:00 PM - 1:00 PM)
            $appointmentTime = date('H:i', strtotime($data['appointment_time']));
            if ($appointmentTime >= '12:00' && $appointmentTime < '13:00') {
                throw new RuntimeException('Selected time falls during lunch break (12:00 PM - 1:00 PM). Please select a different time.');
            }

            // Lock any existing APPROVED row for this exact slot (pending requests don't
            // block — multiple students may be pending for the same slot at once).
            $lock = $db->prepare(
                "SELECT id FROM appointments
                 WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
                 AND status = 'approved' FOR UPDATE"
            );
            $lock->execute([$data['counselor_id'], $data['appointment_date'], $data['appointment_time']]);
            if ($lock->fetch()) {
                throw new RuntimeException('This slot has already been approved for another student. Please pick another time.');
            }

            // Re-validate against counselor's declared availability
            $validSlots = Availability::getAvailableSlots($data['counselor_id'], $data['appointment_date']);

            // ADJUSTED: Filter out 12:00 PM - 1:00 PM slots from available slots
            $validSlots = array_values(array_filter($validSlots, function ($slot) {
                $time = date('H:i', strtotime($slot));
                return $time < '12:00' || $time >= '13:00';
            }));

            if ($data['type'] === 'online' && !in_array($data['appointment_time'], $validSlots, true)) {
                throw new RuntimeException('Selected time is not within counselor availability.');
            }

            $stmt = $db->prepare(
                'INSERT INTO appointments
                 (student_id, counselor_id, concern_category_id, type, appointment_date, appointment_time, is_confidential, notes)
                 VALUES (:student_id, :counselor_id, :concern_category_id, :type, :appointment_date, :appointment_time, :is_confidential, :notes)'
            );
            $stmt->execute([
                'student_id' => $data['student_id'],
                'counselor_id' => $data['counselor_id'],
                'concern_category_id' => $data['concern_category_id'] ?: null,
                'type' => $data['type'],
                'appointment_date' => $data['appointment_date'],
                'appointment_time' => $data['appointment_time'],
                'is_confidential' => !empty($data['is_confidential']) ? 1 : 0,
                'notes' => $data['notes'] ?? null,
            ]);
            $appointmentId = (int)$db->lastInsertId();

            $log = $db->prepare(
                "INSERT INTO appointment_logs (appointment_id, old_status, new_status, changed_by, remarks)
                 VALUES (?, NULL, 'pending', ?, 'Appointment requested')"
            );
            $log->execute([$appointmentId, $data['student_id']]);

            $db->commit();

            NotificationService::appointmentRequested(
                array_merge($data, ['id' => $appointmentId]),
                (int)$data['counselor_id']
            );

            return $appointmentId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}