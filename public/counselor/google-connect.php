<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Services/GoogleCalendarService.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

// CSRF-style state param, also used to make sure the callback belongs to this session
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . GoogleCalendarService::getAuthUrl($state));
exit;
