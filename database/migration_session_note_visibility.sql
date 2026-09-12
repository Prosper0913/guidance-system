-- =====================================================
-- Migration: Let counselors share individual session notes with the student
--
-- Session notes are confidential by default (is_confidential = 1) and were
-- never shown to students at all. This adds a separate per-note flag,
-- visible_to_student, so a counselor can opt in on a note-by-note basis.
-- Confidentiality of existing notes is untouched — this defaults to 0.
-- Run this against your existing guidance_appointment_system database.
-- =====================================================

USE guidance_appointment_system;

ALTER TABLE session_notes
    ADD COLUMN visible_to_student TINYINT(1) NOT NULL DEFAULT 0 AFTER is_confidential;
