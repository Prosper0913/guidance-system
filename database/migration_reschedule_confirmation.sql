-- =====================================================
-- Migration: a counselor's reschedule now requires student confirmation.
--
-- Previously Appointment::reschedule() applied the new date/time immediately.
-- Now a reschedule proposal is held in proposed_date/proposed_time while the
-- appointment's status becomes 'rescheduled' (an enum value that already
-- existed but was never used). The real appointment_date/appointment_time
-- are only overwritten once the student confirms; if the student declines
-- (or never responds), the appointment is cancelled instead of silently
-- keeping the old time.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE appointments
    ADD COLUMN proposed_date DATE NULL DEFAULT NULL AFTER rescheduled_at,
    ADD COLUMN proposed_time TIME NULL DEFAULT NULL AFTER proposed_date;
