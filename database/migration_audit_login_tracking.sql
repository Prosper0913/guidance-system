-- =====================================================
-- Migration: activate audit_logs for the admin security trail
--
-- `audit_logs` already existed in the schema but was never written to by any
-- code. We're now using it to record every login attempt (success and
-- failure) so an admin can see who's accessing the system and from where.
-- It just needs IP/user-agent columns, since the original design didn't
-- anticipate tracking network origin.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE audit_logs
    ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER details,
    ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER ip_address;

ALTER TABLE audit_logs
    ADD KEY idx_audit_logs_created (created_at),
    ADD KEY idx_audit_logs_user (user_id);
