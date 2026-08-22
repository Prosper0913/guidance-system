<?php
// ============================================================
//  guidance-system/includes/push_to_cms.php
//  Push-side helper for Guidance -> CMS referral flag sync.
//  PDO version (matches Guidance's Database::getConnection()).
//
//  CRITICAL: this helper NEVER throws. Every Guidance action
//  that calls it must complete successfully even if CMS is down.
// ============================================================

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cms_sync.php';

function _cms_post($path, $payload) {
    $url = CMS_API_BASE . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Sync-Key: ' . CMS_SYNC_KEY,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("[push_to_cms] curl error POST $url: $err");
        return ['ok' => false, 'http' => 0, 'body' => $err, 'json' => null];
    }
    $decoded = json_decode($raw, true);
    $ok = ($http === 200) && is_array($decoded) && !empty($decoded['success']);
    if (!$ok) {
        error_log(sprintf("[push_to_cms] non-200 from %s (HTTP %d): %s", $url, $http, substr($raw, 0, 500)));
    }
    return ['ok' => $ok, 'http' => $http, 'body' => $raw, 'json' => $decoded];
}

// ── Public: push a student's referral flag to CMS ────────────
// Takes the student's id_number (varchar, maps to CMS students.student_id).
// Re-computes the active referral count and pushes the flag.
function push_referral_flag_to_cms(string $student_id_number): bool {
    $student_id_number = trim($student_id_number);
    if ($student_id_number === '') return false;

    $db = Database::getConnection();
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
             FROM referrals
             WHERE student_id_number = ?
               AND status IN ('pending', 'accepted', 'for_clarification')"
        );
        $stmt->execute([$student_id_number]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $count = (int)($row['cnt'] ?? 0);
        $ids = $row['ids'] ? array_map('intval', explode(',', $row['ids'])) : [];
    } catch (\Throwable $e) {
        error_log("[push_to_cms] error counting referrals for '$student_id_number': " . $e->getMessage());
        return false;
    }

    $r = _cms_post('receive_referral_flag.php', [
        'student_id'          => $student_id_number,
        'has_active_referral' => $count > 0,
        'referral_count'      => $count,
        'referral_ids'        => $ids,
    ]);
    return $r['ok'];
}

// ── Convenience: push by Guidance users.id (int) ──────────────
// Looks up the id_number first, then calls push_referral_flag_to_cms().
function push_referral_flag_to_cms_by_user_id(int $user_id): bool {
    if ($user_id <= 0) return false;
    $db = Database::getConnection();
    try {
        $stmt = $db->prepare("SELECT id_number FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['id_number'])) return false;
        return push_referral_flag_to_cms($row['id_number']);
    } catch (\Throwable $e) {
        error_log("[push_to_cms] error looking up user_id=$user_id: " . $e->getMessage());
        return false;
    }
}