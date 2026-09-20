-- =====================================================
-- Migration: make a counselor's reschedule visible to the student, not just a
-- notification that can go unread. A reschedule currently just silently
-- changes appointment_date/appointment_time (status stays 'approved'), so on
-- my-appointments.php it looked identical to any other approved appointment.
-- This adds a timestamp we can use to show a "Rescheduled" badge on the row.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE appointments
    ADD COLUMN rescheduled_at DATETIME NULL DEFAULT NULL AFTER appointment_time;
