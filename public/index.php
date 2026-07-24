<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';

AuthMiddleware::start();
$user = AuthMiddleware::currentUser();

if ($user) {
    header('Location: ' . BASE_URL . '/' . $user['role'] . '/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
