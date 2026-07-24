<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Notification.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireLogin();

$notifications = Notification::forUser($user['id'], 20);
echo json_encode(['success' => true, 'notifications' => $notifications, 'unread' => Notification::unreadCount($user['id'])]);
