-- =====================================================
-- Migration: distinguish walk-in-recorded referrals from online self-referrals
--
-- Previously every referral converted into an appointment was hardcoded to
-- appointments.type = 'online', because the only submission path was the
-- student's own online form. Now that counselors can record a referral on
-- behalf of a student who came to the office in person (see
-- counselor/record-walkin.php), we need to remember which channel a given
-- referral came in on so the resulting appointment is labeled correctly.
--
-- Note: this is distinct from the existing (currently unused) `preferred_type`
-- column, which describes the student's preferred counseling format, not how
-- the referral itself was submitted.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE referrals
    ADD COLUMN submitted_via ENUM('online','walk-in') NOT NULL DEFAULT 'online' AFTER student_contact;
