<?php
// ===== CMS <-> Guidance sync configuration (TEMPLATE) =====
// Copy this file to config/cms_sync.php and fill in the real values.
// config/cms_sync.php is gitignored and must never be committed.

define('CMS_API_BASE', 'http://localhost/classroomv2/api');

// Generate a long random value, e.g.: bin2hex(random_bytes(32))
// It must match exactly what the CMS side is configured to send/expect.
define('CMS_SYNC_KEY', 'REPLACE_WITH_A_LONG_RANDOM_SHARED_SECRET');

define('CMS_RECEIVE_DB_HOST', 'localhost');
define('CMS_RECEIVE_DB_NAME', 'guidance_appointment_system');
define('CMS_RECEIVE_DB_USER', 'root');
define('CMS_RECEIVE_DB_PASS', '');
