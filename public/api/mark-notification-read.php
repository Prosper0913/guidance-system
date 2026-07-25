<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Notification.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireLogin();

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid session token.']);
    exit;
}

if (!empty($_POST['all'])) {
    Notification::markAllRead($user['id']);
    echo json_encode(['success' => true, 'unread' => 0]);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    Notification::markRead($id, $user['id']);
}

echo json_encode(['success' => true, 'unread' => Notification::unreadCount($user['id'])]);