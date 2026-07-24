<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Availability.php';

header('Content-Type: application/json');
AuthMiddleware::start();
$user = AuthMiddleware::currentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$counselorId = (int)($_GET['counselor_id'] ?? 0);
$date = $_GET['date'] ?? '';

if (!$counselorId || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid counselor_id/date.']);
    exit;
}

try {
    $slots = Availability::getAvailableSlots($counselorId, $date);
    $formatted = array_map(function ($t) {
        return ['value' => $t, 'label' => date('g:i A', strtotime($t))];
    }, $slots);
    echo json_encode(['success' => true, 'slots' => $formatted]);
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while checking availability.']);
}
