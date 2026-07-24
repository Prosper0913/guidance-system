-- =====================================================
-- Migration: Student-submitted referrals as the booking entry point
-- Run this against your existing guidance_appointment_system database.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE referrals
    ADD COLUMN IF NOT EXISTS preferred_type ENUM('walk-in','online') NULL AFTER student_contact,
    ADD COLUMN IF NOT EXISTS preferred_counselor_id INT NULL AFTER preferred_type,
    ADD COLUMN IF NOT EXISTS preferred_date DATE NULL AFTER preferred_counselor_id,
    ADD COLUMN IF NOT EXISTS preferred_time TIME NULL AFTER preferred_date;

ALTER TABLE referrals
    ADD CONSTRAINT fk_referrals_preferred_counselor
    FOREIGN KEY (preferred_counselor_id) REFERENCES users(id) ON DELETE SET NULL;
