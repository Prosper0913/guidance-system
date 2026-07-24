<?php
date_default_timezone_set('Asia/Manila');

// ===== App-wide constants =====

define('APP_NAME', 'Guidance Management System');
define('BASE_URL', '/guidance-system/public'); // adjust to your XAMPP htdocs path

// Roles
define('ROLE_STUDENT', 'student');
define('ROLE_COUNSELOR', 'counselor');
define('ROLE_ADMIN', 'admin');

// Appointment statuses
define('STATUS_PENDING', 'pending');
define('STATUS_APPROVED', 'approved');
define('STATUS_DECLINED', 'declined');
define('STATUS_COMPLETED', 'completed');
define('STATUS_CANCELLED', 'cancelled');
define('STATUS_RESCHEDULED', 'rescheduled');
define('STATUS_NOSHOW', 'no-show');

// Session lifetime (seconds)
define('SESSION_LIFETIME', 3600 * 4);
