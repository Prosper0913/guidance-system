<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Services/NotificationService.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
    exit;
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$message = Validator::clean($_POST['message'] ?? '');

if (!$appointmentId || !Validator::required($message)) {
    echo json_encode(['success' => false, 'message' => 'A message is required.']);
    exit;
}
if (mb_strlen($message) > 500) {
    echo json_encode(['success' => false, 'message' => 'Message is too long (max 500 characters).']);
    exit;
}

$appointment = Appointment::findById($appointmentId);
if (!$appointment) {
    echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    exit;
}
if ($user['role'] === ROLE_COUNSELOR && (int)$appointment['counselor_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized for this appointment.']);
    exit;
}

try {
    NotificationService::customMessage($appointment, $message);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send message.']);
}
