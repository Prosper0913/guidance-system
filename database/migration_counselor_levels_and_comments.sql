-- =====================================================
-- Migration: counselor education levels (multi-select), referral cancel
-- comment, and reschedule comment.
-- Run once against your existing guidance_appointment_system database.
--
-- 1. counselor_profiles.education_level_group used to hold ONE value
--    ('junior_highschool' or 'senior_college'). It now holds a comma-separated
--    list of the levels a counselor covers, e.g.
--      'junior_highschool'
--      'junior_highschool,senior_highschool'
--      'junior_highschool,senior_highschool,college'
--    so the column has to be wider than varchar(30).
-- 2. referrals.cancellation_reason: optional comment a counselor leaves when
--    cancelling a referral; shown to the student.
-- 3. appointments.reschedule_comment: optional comment a counselor leaves when
--    proposing a new time; shown to the student.
-- 4. notifications.message widened from varchar(255) to varchar(1000): the
--    student notification for a cancelled referral / proposed reschedule now
--    carries the counselor's comment (up to 500 characters) and would
--    otherwise be rejected as too long.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE counselor_profiles
    MODIFY COLUMN education_level_group VARCHAR(100) NULL DEFAULT NULL;

-- Convert the old two-bucket value into the explicit level list.
UPDATE counselor_profiles
   SET education_level_group = 'senior_highschool,college'
 WHERE education_level_group = 'senior_college';

ALTER TABLE referrals
    ADD COLUMN cancellation_reason VARCHAR(500) NULL DEFAULT NULL AFTER office_remarks;

ALTER TABLE appointments
    ADD COLUMN reschedule_comment VARCHAR(500) NULL DEFAULT NULL AFTER cancellation_reason;

ALTER TABLE notifications
    MODIFY COLUMN message VARCHAR(1000) NOT NULL;
