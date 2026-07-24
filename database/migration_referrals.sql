-- =====================================================
-- Migration: Guidance Office Student Referral Form
-- Digitizes the College of Maasin's paper referral form.
-- Run this against your existing guidance_appointment_system database.
-- =====================================================

USE guidance_appointment_system;

CREATE TABLE IF NOT EXISTS referrals (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    referral_no                 VARCHAR(30) UNIQUE,       -- assigned after insert, e.g. REF-2026-0001
    department                  VARCHAR(150),             -- "Department:" field at top of form
    referral_date               DATE NOT NULL,

    -- I. Student Information
    student_id                  INT NULL,                 -- linked once matched to a registered account
    student_name                VARCHAR(200) NOT NULL,
    student_id_number           VARCHAR(50),
    grade_year_level            VARCHAR(50),
    section_course_program      VARCHAR(150),
    sex                         VARCHAR(20),
    student_contact             VARCHAR(150),

    -- II. Referring Party Information
    referring_party_name        VARCHAR(200) NOT NULL,
    referring_party_position    VARCHAR(150),
    referring_party_department  VARCHAR(150),
    referring_party_contact     VARCHAR(150),

    -- III. Referral Information (checkbox groups + "Others" text, per category)
    concerns                    JSON NOT NULL,
    -- IV. Description of Behavior/Incident
    description_of_incident     TEXT,
    -- V. Actions Taken Prior to Referral
    actions_taken                JSON,

    -- VI. Urgency Assessment
    urgency_level                ENUM('routine','urgent') NOT NULL DEFAULT 'routine',
    risk_self_harm                TINYINT(1) NOT NULL DEFAULT 0,
    risk_harm_others              TINYINT(1) NOT NULL DEFAULT 0,
    severe_emotional_distress     TINYINT(1) NOT NULL DEFAULT 0,
    crisis_situation               TINYINT(1) NOT NULL DEFAULT 0,

    -- VII. Consent and Acknowledgement (typed e-signature, since this is a web form)
    consent_certified            TINYINT(1) NOT NULL DEFAULT 0,
    submitted_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- VIII. Guidance Office Use Only
    status                        ENUM('pending','accepted','for_clarification','referred_back') NOT NULL DEFAULT 'pending',
    received_by                   INT NULL,
    received_at                   DATETIME NULL,
    initial_action                 JSON NULL,
    assigned_counselor_id          INT NULL,
    appointment_id                  INT NULL,   -- linked if guidance schedules a formal appointment from this referral
    office_remarks                  TEXT,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_counselor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_student (student_id)
) ENGINE=InnoDB;

-- Let notifications deep-link to a referral, same way they already do for appointments
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS referral_id INT NULL AFTER appointment_id;
ALTER TABLE notifications ADD CONSTRAINT fk_notifications_referral
    FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE;
