<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
AuthMiddleware::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
