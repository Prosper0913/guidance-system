-- =====================================================
-- Migration: Add 'cancelled' as a valid referral status
-- Run this against your existing guidance_appointment_system database.
--
-- Without this, referral-view.php's cancel action tries to save
-- status = 'cancelled' into a column whose ENUM doesn't allow it.
-- On a non-strict MySQL/MariaDB config this fails SILENTLY — the
-- invalid value is truncated to an empty string '' instead of
-- raising an error, which is what happened to at least one referral
-- already (id 24 in the current export has status = '').
-- On a strict config it would instead throw an uncaught PDOException
-- (fatal error) when a counselor clicks "Cancel Referral".
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE referrals
    MODIFY COLUMN status ENUM('pending','accepted','for_clarification','referred_back','cancelled')
    NOT NULL DEFAULT 'pending';

-- Repair rows already corrupted by the missing enum value. This assumes any
-- referral that ended up with an empty/NULL status got there via a failed
-- cancel attempt (the only code path that writes an invalid status right
-- now) — double check referral id 24 specifically after running this.
UPDATE referrals SET status = 'cancelled' WHERE status = '' OR status IS NULL;
