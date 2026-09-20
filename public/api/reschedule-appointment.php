<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Notification.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
    exit;
}

$id = (int)($_POST['appointment_id'] ?? 0);
$newDate = $_POST['new_date'] ?? '';
$newTime = $_POST['new_time'] ?? '';

if (!$id || !$newDate || !$newTime) {
    echo json_encode(['success' => false, 'message' => 'Please pick a date and time.']);
    exit;
}

$existing = Appointment::findById($id);
if (!$existing) {
    echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    exit;
}
if ($user['role'] === ROLE_COUNSELOR && (int)$existing['counselor_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You can only reschedule your own appointments.']);
    exit;
}

try {
    // This only PROPOSES the new time — it does not move the appointment yet.
    // The student must confirm it from their My Appointments page before it
    // takes effect; declining (or not responding) cancels the appointment
    // instead of quietly keeping the old time.
    $updated = Appointment::proposeReschedule($id, $newDate, $newTime, $user['id']);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

Notification::create(
    (int)$updated['student_id'],
    "Your counselor proposed moving your appointment to {$newDate} at " . date('g:i A', strtotime($newTime)) . '. Please confirm or decline it in My Appointments — if you don\'t respond, this appointment will be cancelled.',
    $id
);

// No Google Calendar sync yet — the appointment hasn't actually moved until the
// student confirms. GoogleSyncService::pushUpdate() only runs once that happens.

echo json_encode(['success' => true, 'appointment' => $updated]);
