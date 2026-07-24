<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/GoogleToken.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    GoogleToken::delete($user['id']);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Google Calendar disconnected.'];
}
header('Location: ' . BASE_URL . '/counselor/availability.php');
exit;
