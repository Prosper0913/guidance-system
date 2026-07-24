<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Services/NotificationService.php';
require_once __DIR__ . '/../../src/Services/GoogleSyncService.php';

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
$newStatus = $_POST['status'] ?? '';
$remarks = trim($_POST['remarks'] ?? '');

$allowed = [STATUS_APPROVED, STATUS_DECLINED, STATUS_CANCELLED, STATUS_COMPLETED, STATUS_NOSHOW];
if (!$appointmentId || !in_array($newStatus, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$appointment = Appointment::findById($appointmentId);
if (!$appointment) {
    echo json_encode(['success' => false, 'message' => 'Appointment not found.']);
    exit;
}
// Counselors may only act on their own appointments
if ($user['role'] === ROLE_COUNSELOR && (int)$appointment['counselor_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized for this appointment.']);
    exit;
}

try {
    $declinedSiblings = [];
    if ($newStatus === STATUS_APPROVED) {
        // Approves this one, auto-declines any other pending requests for the same slot
        $declinedSiblings = Appointment::approveAndResolveConflicts($appointmentId, $user['id'], $remarks ?: null);

        NotificationService::statusChanged($appointment, STATUS_APPROVED, $remarks ?: null);
        GoogleSyncService::pushCreate($appointment);

        foreach ($declinedSiblings as $sibling) {
            NotificationService::statusChanged($sibling, STATUS_DECLINED, 'This time slot has been taken by another student. Please choose another available time.');
            // Siblings were never approved, so they never had a synced Google event — nothing to delete.
        }
    } else {
        Appointment::updateStatus($appointmentId, $newStatus, $user['id'], $remarks ?: null);
        NotificationService::statusChanged($appointment, $newStatus, $remarks ?: null);

        if (in_array($newStatus, [STATUS_DECLINED, STATUS_CANCELLED, STATUS_NOSHOW], true)) {
            GoogleSyncService::pushDelete($appointment);
        }
    }

    echo json_encode(['success' => true, 'declined_count' => count($declinedSiblings ?? [])]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to update status.']);
}
