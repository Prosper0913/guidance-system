<?php
require_once __DIR__ . '/../../config/database.php';

class AuditLog
{
    // Generic entry point — any action worth tracking later (user created,
    // account disabled, etc.) can reuse this without a new table.
    public static function record(?int $userId, string $action, ?string $tableAffected = null, ?int $recordId = null, ?string $details = null): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $tableAffected,
            $recordId,
            $details,
            self::clientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    public static function recordLogin(?int $userId, string $attemptedEmail, ?string $role, bool $success): void
    {
        self::record(
            $userId,
            $success ? 'login_success' : 'login_failed',
            'users',
            $userId,
            $success ? "Logged in as {$role}" : "Failed login attempt for {$attemptedEmail}"
        );
    }

    // Most recent entries first, optionally filtered to just login activity.
    public static function recent(int $limit = 200, bool $loginsOnly = false): array
    {
        $db = Database::getConnection();
        $sql = "SELECT al.*, u.first_name, u.last_name, u.role AS account_role
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id";
        if ($loginsOnly) {
            $sql .= " WHERE al.action IN ('login_success', 'login_failed')";
        }
        $sql .= " ORDER BY al.created_at DESC LIMIT " . (int)$limit;
        return $db->query($sql)->fetchAll();
    }

    // Best-effort real client IP. Falls back through common proxy headers before
    // REMOTE_ADDR, since a droplet behind Cloudflare/nginx otherwise only ever
    // sees the proxy's own IP. Only the first (client) hop of X-Forwarded-For is
    // trusted for display purposes — this is a visibility feature, not an
    // access-control decision, so header spoofing risk here is low-stakes.
    private static function clientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if ($ip !== '') return $ip;
            }
        }
        return null;
    }
}
