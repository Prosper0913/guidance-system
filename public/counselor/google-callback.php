<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Services/GoogleCalendarService.php';
require_once __DIR__ . '/../../src/Models/GoogleToken.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

$error = $_GET['error'] ?? null;
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;

if ($error) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Google Calendar connection was cancelled.'];
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}

if (!$code || !$state || empty($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid or expired connection request. Please try again.'];
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}
unset($_SESSION['google_oauth_state']);

$tokenData = GoogleCalendarService::exchangeCode($code);

if (!$tokenData || empty($tokenData['access_token'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Could not connect to Google Calendar. Please try again.'];
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}

// Google only returns refresh_token on first consent; if missing (re-connect), keep the old one.
$refreshToken = $tokenData['refresh_token'] ?? null;
if (!$refreshToken) {
    $existing = GoogleToken::get($user['id']);
    $refreshToken = $existing['refresh_token'] ?? null;
}

if (!$refreshToken) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Google did not provide offline access. Please disconnect any prior authorization for this app in your Google Account and try connecting again.'];
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}

GoogleToken::save(
    $user['id'],
    $tokenData['access_token'],
    $refreshToken,
    $tokenData['expires_in'] ?? 3600
);

$_SESSION['flash'] = ['type' => 'success', 'message' => 'Google Calendar connected! Your busy times will now be excluded from booking, and approved appointments will sync automatically.'];
header('Location: ' . BASE_URL . '/counselor/availability.php');
exit;
