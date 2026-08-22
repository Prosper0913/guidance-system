<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Notification.php';
require_once __DIR__ . '/../../src/Services/GoogleSyncService.php';

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
    $updated = Appointment::reschedule($id, $newDate, $newTime, $user['id']);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// Merge in the display fields findById() already had (student/counselor names, category)
// since reschedule() only returns the raw appointments row.
$full = array_merge($existing, $updated);

Notification::create(
    (int)$full['student_id'],
    "Your guidance appointment was rescheduled to {$newDate} at " . date('g:i A', strtotime($newTime)) . '.',
    $id
);

if (!empty($full['google_event_id'])) {
    GoogleSyncService::pushUpdate($full);
} else {
    // No prior synced event (e.g. was approved before Google was connected) — create one now.
    GoogleSyncService::pushCreate($full);
}

echo json_encode(['success' => true, 'appointment' => $full]);