<?php
require_once __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../Models/GoogleToken.php';

class GoogleCalendarService
{
    private static function httpRequest(string $method, string $url, ?array $body = null, ?string $accessToken = null): array
    {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($accessToken) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'code' => 0, 'error' => $err];
        }
        $decoded = json_decode($response, true);
        return ['ok' => $httpCode >= 200 && $httpCode < 300, 'code' => $httpCode, 'data' => $decoded];
    }

    // ===== OAuth =====

    public static function getAuthUrl(string $state): string
    {
        $params = [
            'client_id' => GOOGLE_CLIENT_ID,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => GOOGLE_CALENDAR_SCOPE,
            'access_type' => 'offline',   // required to receive a refresh_token
            'prompt' => 'consent',        // force refresh_token on every connect
            'state' => $state,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public static function exchangeCode(string $code): ?array
    {
        $res = self::httpRequest('POST', 'https://oauth2.googleapis.com/token', [
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => GOOGLE_REDIRECT_URI,
        ]);
        if (!$res['ok']) {
            error_log('Google token exchange failed: ' . json_encode($res));
            return null;
        }
        return $res['data']; // contains access_token, refresh_token, expires_in
    }

    // Returns a valid access token for the counselor, refreshing if needed. Null if not connected / refresh failed.
    public static function ensureValidToken(int $counselorId): ?string
    {
        $token = GoogleToken::get($counselorId);
        if (!$token) return null;

        if (strtotime($token['token_expires_at']) > time()) {
            return $token['access_token'];
        }

        // Refresh
        $res = self::httpRequest('POST', 'https://oauth2.googleapis.com/token', [
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        if (!$res['ok'] || empty($res['data']['access_token'])) {
            error_log('Google token refresh failed for counselor ' . $counselorId . ': ' . json_encode($res));
            return null;
        }
        $newAccessToken = $res['data']['access_token'];
        $expiresIn = $res['data']['expires_in'] ?? 3600;
        GoogleToken::updateAccessToken($counselorId, $newAccessToken, $expiresIn);
        return $newAccessToken;
    }

    // ===== Free/Busy (pull) =====

    /**
     * Returns busy intervals as [['start' => unixtimestamp, 'end' => unixtimestamp], ...]
     * for the counselor's connected Google Calendar on the given date (local school timezone).
     * Returns [] if not connected or on API failure (fails open so Google downtime never blocks booking).
     */
    public static function getBusyIntervals(int $counselorId, string $date): array
    {
        $accessToken = self::ensureValidToken($counselorId);
        if (!$accessToken) return [];

        $token = GoogleToken::get($counselorId);
        $calendarId = $token['google_calendar_id'] ?? 'primary';

        $tz = new DateTimeZone(APP_TIMEZONE);
        $dayStart = new DateTime($date . ' 00:00:00', $tz);
        $dayEnd = new DateTime($date . ' 23:59:59', $tz);

        $res = self::httpRequest('POST', 'https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $dayStart->format(DateTime::RFC3339),
            'timeMax' => $dayEnd->format(DateTime::RFC3339),
            'timeZone' => APP_TIMEZONE,
            'items' => [['id' => $calendarId]],
        ], $accessToken);

        if (!$res['ok']) {
            error_log('Google freeBusy failed for counselor ' . $counselorId . ': ' . json_encode($res));
            return [];
        }

        $busy = $res['data']['calendars'][$calendarId]['busy'] ?? [];
        $intervals = [];
        foreach ($busy as $b) {
            $intervals[] = [
                'start' => strtotime($b['start']),
                'end' => strtotime($b['end']),
            ];
        }
        return $intervals;
    }

    // ===== Events (pull, for the dashboard mini-calendar) =====

    /**
     * Returns actual events (with titles) between two RFC3339 timestamps — used to render
     * the dashboard's calendar widget, as opposed to getBusyIntervals() which only returns
     * anonymous busy blocks for the booking-availability check.
     */
    public static function listEvents(int $counselorId, string $timeMinISO, string $timeMaxISO): array
    {
        $accessToken = self::ensureValidToken($counselorId);
        if (!$accessToken) return [];

        $token = GoogleToken::get($counselorId);
        $calendarId = $token['google_calendar_id'] ?? 'primary';

        $params = http_build_query([
            'timeMin' => $timeMinISO,
            'timeMax' => $timeMaxISO,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 100,
        ]);

        $res = self::httpRequest(
            'GET',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?{$params}",
            null,
            $accessToken
        );
        if (!$res['ok']) {
            error_log('Google listEvents failed for counselor ' . $counselorId . ': ' . json_encode($res));
            return [];
        }

        $events = [];
        foreach (($res['data']['items'] ?? []) as $item) {
            if (($item['status'] ?? '') === 'cancelled') continue;
            $isAllDay = isset($item['start']['date']);
            $events[] = [
                'id' => $item['id'],
                'title' => $item['summary'] ?? '(No title)',
                'start' => $isAllDay ? $item['start']['date'] : $item['start']['dateTime'],
                'end' => $isAllDay ? ($item['end']['date'] ?? null) : ($item['end']['dateTime'] ?? null),
                'all_day' => $isAllDay,
                'location' => $item['location'] ?? null,
                'html_link' => $item['htmlLink'] ?? null,
            ];
        }
        return $events;
    }

    // ===== Events (push) =====

    public static function createEvent(int $counselorId, array $eventData): ?string
    {
        $accessToken = self::ensureValidToken($counselorId);
        if (!$accessToken) return null;

        $token = GoogleToken::get($counselorId);
        $calendarId = $token['google_calendar_id'] ?? 'primary';

        $res = self::httpRequest(
            'POST',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events",
            $eventData,
            $accessToken
        );
        if (!$res['ok']) {
            error_log('Google createEvent failed for counselor ' . $counselorId . ': ' . json_encode($res));
            return null;
        }
        return $res['data']['id'] ?? null;
    }

    public static function updateEvent(int $counselorId, string $eventId, array $eventData): bool
    {
        $accessToken = self::ensureValidToken($counselorId);
        if (!$accessToken) return false;

        $token = GoogleToken::get($counselorId);
        $calendarId = $token['google_calendar_id'] ?? 'primary';

        $res = self::httpRequest(
            'PATCH',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}",
            $eventData,
            $accessToken
        );
        if (!$res['ok']) {
            error_log('Google updateEvent failed for counselor ' . $counselorId . ': ' . json_encode($res));
        }
        return $res['ok'];
    }

    public static function deleteEvent(int $counselorId, string $eventId): bool
    {
        $accessToken = self::ensureValidToken($counselorId);
        if (!$accessToken) return false;

        $token = GoogleToken::get($counselorId);
        $calendarId = $token['google_calendar_id'] ?? 'primary';

        $res = self::httpRequest(
            'DELETE',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$eventId}",
            null,
            $accessToken
        );
        // Google returns 410 Gone if already deleted — treat as success either way
        return $res['code'] === 204 || $res['code'] === 410 || $res['ok'];
    }
}