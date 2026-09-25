-- =====================================================
-- Migration: surface a cancellation reason to the other party.
--
-- Previously a cancellation reason (if given at all) only lived as free text
-- inside appointment_logs.remarks, which nothing displayed back to the
-- student or counselor. This adds a dedicated column so it can be shown
-- directly wherever the appointment itself is shown, for either side that
-- didn't do the cancelling.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE appointments
    ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL AFTER notes;
