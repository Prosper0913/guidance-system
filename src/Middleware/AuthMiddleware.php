<?php
require_once __DIR__ . '/../../config/constants.php';

class AuthMiddleware
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function currentUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): array
    {
        self::start();
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        return $_SESSION['user'];
    }

    public static function requireRole(array $roles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            die('403 - You do not have permission to access this page.');
        }
        return $user;
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
