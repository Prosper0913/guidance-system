<?php
// ============================================================
//  guidance/receive_student_deletion.php
//  Called by CMS when an admin deletes a student outright.
// ============================================================
require_once __DIR__ . '/_receive_common.php';
authenticate_request();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'Only POST is supported.');
}

 $body = read_json_body();
 $student_id = trim((string)($body['student_id'] ?? ''));
if ($student_id === '') {
    json_fail(400, 'student_id is required.');
}

 $conn->begin_transaction();
try {
    $look = $conn->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");
    $look->bind_param('s', $student_id);
    $look->execute();
    $row = $look->get_result()->fetch_assoc();
    if (!$row) {
        $conn->commit();
        json_out([
            'success' => true,
            'note'    => "No user with id_number='$student_id' in Guidance — nothing to deactivate.",
            'deactivated_user' => false,
            'deactivated_enrollments' => 0,
        ]);
    }
    $user_id = (int)$row['id'];

    $upU = $conn->prepare(
        "UPDATE users SET is_active = 0, status = 'disabled', last_synced_at = NOW()
         WHERE id = ?"
    );
    $upU->bind_param('i', $user_id);
    $upU->execute();

    $upE = $conn->prepare(
        "UPDATE student_enrollments SET is_active = 0, synced_at = NOW()
         WHERE user_id = ?"
    );
    $upE->bind_param('i', $user_id);
    $upE->execute();
    $deactivated_enrollments = $upE->affected_rows;

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_fail(500, 'Database error: ' . $e->getMessage());
}

json_out([
    'success' => true,
    'deactivated_user' => true,
    'user_id' => $user_id,
    'deactivated_enrollments' => $deactivated_enrollments,
]);