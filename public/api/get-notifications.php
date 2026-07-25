<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Notification.php';

header('Content-Type: application/json');
$user = AuthMiddleware::requireLogin();

$limit = max(1, min(50, (int)($_GET['limit'] ?? 15)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$notifications = Notification::forUser($user['id'], $limit, $offset);
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread' => Notification::unreadCount($user['id']),
    'has_more' => count($notifications) === $limit,
]);