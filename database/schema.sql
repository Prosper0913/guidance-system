-- =====================================================
-- Guidance Appointment System - Database Schema
-- Engine: MySQL / MariaDB
-- =====================================================

CREATE DATABASE IF NOT EXISTS guidance_appointment_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE guidance_appointment_system;

-- ---------------------------------------------------
-- USERS & ROLES
-- ---------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('student','counselor','admin') NOT NULL,
    id_number       VARCHAR(50)  NOT NULL UNIQUE,      -- student no. / employee no.
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    contact_number  VARCHAR(30),
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE student_profiles (
    user_id             INT PRIMARY KEY,
    course              VARCHAR(150),
    year_level          VARCHAR(20),
    section             VARCHAR(50),
    has_special_needs   BOOLEAN NOT NULL DEFAULT FALSE,
    guardian_contact     VARCHAR(30),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE counselor_profiles (
    user_id          INT PRIMARY KEY,
    specialization   VARCHAR(150),
    office_location  VARCHAR(150),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- SCHEDULING / AVAILABILITY
-- ---------------------------------------------------
CREATE TABLE counselor_availability (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    counselor_id   INT NOT NULL,
    day_of_week    TINYINT NOT NULL,        -- 0=Sunday .. 6=Saturday
    start_time     TIME NOT NULL,
    end_time       TIME NOT NULL,
    slot_minutes   INT NOT NULL DEFAULT 30, -- appointment slot length
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE counselor_availability_exceptions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    counselor_id   INT NOT NULL,
    exception_date DATE NOT NULL,
    is_available   BOOLEAN NOT NULL DEFAULT FALSE, -- FALSE = blocked/unavailable that day
    reason         VARCHAR(255),
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- CONCERN CATEGORIES
-- ---------------------------------------------------
CREATE TABLE concern_categories (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    description  VARCHAR(255)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- APPOINTMENTS
-- ---------------------------------------------------
CREATE TABLE appointments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    student_id          INT NOT NULL,
    counselor_id        INT NOT NULL,
    concern_category_id INT,
    type                ENUM('walk-in','online') NOT NULL,
    appointment_date    DATE NOT NULL,
    appointment_time    TIME NOT NULL,
    status              ENUM('pending','approved','declined','completed','cancelled','rescheduled','no-show')
                         NOT NULL DEFAULT 'pending',
    is_confidential     BOOLEAN NOT NULL DEFAULT FALSE,
    notes               TEXT,               -- student's initial description, optional
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (concern_category_id) REFERENCES concern_categories(id) ON DELETE SET NULL,
    -- Multiple students may be PENDING for the same slot at once (counselor picks one to approve).
    -- Only an 'approved' appointment actually locks a time slot — enforced at the application
    -- level (SchedulingService/Appointment models), not by a DB uniqueness constraint.
    INDEX idx_counselor_slot (counselor_id, appointment_date, appointment_time)
) ENGINE=InnoDB;

CREATE TABLE appointment_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT NOT NULL,
    old_status      VARCHAR(20),
    new_status      VARCHAR(20) NOT NULL,
    changed_by      INT NOT NULL,          -- user id who made the change
    remarks         VARCHAR(255),
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE attendance_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT NOT NULL UNIQUE,
    check_in_time   DATETIME,
    check_out_time  DATETIME,
    status          ENUM('present','no-show') NOT NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE session_notes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT NOT NULL,
    counselor_id    INT NOT NULL,
    notes           TEXT NOT NULL,
    is_confidential BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------
CREATE TABLE notifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,          -- recipient
    appointment_id  INT,
    message         VARCHAR(255) NOT NULL,
    channel         ENUM('in-app','email') NOT NULL DEFAULT 'in-app',
    is_read         BOOLEAN NOT NULL DEFAULT FALSE,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- SPECIAL NEEDS MONITORING
-- ---------------------------------------------------
CREATE TABLE special_needs_monitoring (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    student_id           INT NOT NULL,
    assigned_counselor_id INT NOT NULL,
    condition_type       VARCHAR(150) NOT NULL,
    accommodations       TEXT,
    monitoring_frequency VARCHAR(50),        -- e.g. 'weekly', 'monthly'
    last_check_in        DATE,
    next_check_in        DATE,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_counselor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- AUDIT LOG (system-wide, for admin oversight)
-- ---------------------------------------------------
CREATE TABLE audit_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,
    action          VARCHAR(100) NOT NULL,
    table_affected  VARCHAR(100),
    record_id       INT,
    details         VARCHAR(255),
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------
-- SEED DATA (example concern categories)
-- ---------------------------------------------------
INSERT INTO concern_categories (name, description) VALUES
('Academic', 'Study habits, grades, academic difficulties'),
('Personal', 'Personal or family concerns'),
('Behavioral', 'Behavioral or disciplinary concerns'),
('Career', 'Career guidance and planning'),
('Peer Relationship', 'Issues with classmates or friends'),
('Mental Health', 'Emotional wellbeing, stress, anxiety'),
('Other', 'Concerns not covered above');
