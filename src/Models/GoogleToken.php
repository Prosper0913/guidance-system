<?php
require_once __DIR__ . '/../../config/database.php';

class GoogleToken
{
    public static function get(int $counselorId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM counselor_google_tokens WHERE counselor_id = ?');
        $stmt->execute([$counselorId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isConnected(int $counselorId): bool
    {
        return self::get($counselorId) !== null;
    }

    public static function save(int $counselorId, string $accessToken, string $refreshToken, int $expiresIn, string $calendarId = 'primary'): void
    {
        $db = Database::getConnection();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60); // 60s safety margin
        $stmt = $db->prepare(
            'INSERT INTO counselor_google_tokens (counselor_id, access_token, refresh_token, token_expires_at, google_calendar_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               access_token = VALUES(access_token),
               refresh_token = VALUES(refresh_token),
               token_expires_at = VALUES(token_expires_at),
               google_calendar_id = VALUES(google_calendar_id)'
        );
        $stmt->execute([$counselorId, $accessToken, $refreshToken, $expiresAt, $calendarId]);
    }

    public static function updateAccessToken(int $counselorId, string $accessToken, int $expiresIn): void
    {
        $db = Database::getConnection();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
        $stmt = $db->prepare('UPDATE counselor_google_tokens SET access_token = ?, token_expires_at = ? WHERE counselor_id = ?');
        $stmt->execute([$accessToken, $expiresAt, $counselorId]);
    }

    public static function delete(int $counselorId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM counselor_google_tokens WHERE counselor_id = ?');
        $stmt->execute([$counselorId]);
    }
}
