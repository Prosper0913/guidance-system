-- =====================================================
-- Migration: Allow multiple pending requests per slot
-- Only an APPROVED appointment locks a time slot now.
-- Run this against your existing guidance_appointment_system database.
-- =====================================================

USE guidance_appointment_system;

-- Add the replacement index FIRST — MySQL needs an index covering
-- counselor_id at all times to support the counselor_id foreign key,
-- so we can't drop the old one until this new one exists.
ALTER TABLE appointments ADD INDEX idx_counselor_slot (counselor_id, appointment_date, appointment_time);

-- Now it's safe to drop the old uniqueness constraint — multiple students
-- can be pending for the same counselor/date/time simultaneously.
ALTER TABLE appointments DROP INDEX unique_slot;
