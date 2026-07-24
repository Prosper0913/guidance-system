-- =====================================================
-- Migration: Google Calendar Integration
-- Run this against your existing guidance_appointment_system database
-- =====================================================

USE guidance_appointment_system;

CREATE TABLE IF NOT EXISTS counselor_google_tokens (
    counselor_id       INT PRIMARY KEY,
    access_token        TEXT NOT NULL,
    refresh_token       TEXT NOT NULL,
    token_expires_at    DATETIME NOT NULL,
    google_calendar_id  VARCHAR(255) NOT NULL DEFAULT 'primary',
    connected_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255) NULL AFTER notes;
