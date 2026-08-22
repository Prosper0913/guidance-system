<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/GoogleToken.php';
require_once __DIR__ . '/../../src/Services/GoogleCalendarService.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

if (!GoogleToken::isConnected($user['id'])) {
    echo json_encode(['success' => false, 'connected' => false, 'message' => 'Google Calendar is not connected.']);
    exit;
}

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range.']);
    exit;
}

$tz = new DateTimeZone(APP_TIMEZONE);
$timeMin = (new DateTime($start . ' 00:00:00', $tz))->format(DateTime::RFC3339);
$timeMax = (new DateTime($end . ' 23:59:59', $tz))->format(DateTime::RFC3339);

$events = GoogleCalendarService::listEvents($user['id'], $timeMin, $timeMax);

echo json_encode(['success' => true, 'connected' => true, 'events' => $events]);