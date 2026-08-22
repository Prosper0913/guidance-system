<?php
// ============================================================
//  guidance/receive_student.php
//  Inbound endpoint — CMS POSTs a student's full current state.
// ============================================================
require_once __DIR__ . '/_receive_common.php';
authenticate_request();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'Only POST is supported.');
}

 $body = read_json_body();

if (!isset($body['student']) || !is_array($body['student'])) {
    json_fail(400, 'Missing or invalid field: student');
}
 $s = $body['student'];

foreach (['student_id', 'username', 'password_hash', 'first_name', 'last_name'] as $f) {
    if (!isset($s[$f]) || $s[$f] === '') {
        json_fail(400, "student.$f is required.");
    }
}

 $student_id    = trim((string)$s['student_id']);
 $username      = trim((string)$s['username']);
 $password_hash = (string)$s['password_hash'];
 $first_name    = trim((string)$s['first_name']);
 $last_name     = trim((string)$s['last_name']);
 $middle_initial= trim((string)($s['middle_initial'] ?? ''));
 $email         = trim((string)($s['email'] ?? ''));
 $email_val = $email !== '' ? $email : null;

 $profile = $body['profile'] ?? [];
 $course          = trim((string)($profile['course'] ?? ''));
 $year_level      = trim((string)($profile['year_level'] ?? ''));
 $section         = trim((string)($profile['section'] ?? ''));
 $education_level = trim((string)($profile['education_level'] ?? ''));

 $enrollments = $body['enrollments'] ?? [];
if (!is_array($enrollments)) {
    json_fail(400, 'enrollments must be an array (empty array is allowed).');
}

 $inserted = 0;
 $updated  = 0;
 $unchanged = 0;
 $enrollments_upserted = 0;
 $enrollments_deactivated = 0;

 $conn->begin_transaction();
try {
    // 1. Upsert user
    $upU = $conn->prepare(
        "INSERT INTO users
            (id_number, username, password_hash, first_name, last_name,
             email, role, status, is_active, source, last_synced_at)
         VALUES (?, ?, ?, ?, ?, ?, 'student', 'active', 1, 'cms_push', NOW())
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            password_hash = VALUES(password_hash),
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            email = VALUES(email),
            role = 'student',
            status = 'active',
            is_active = 1,
            source = 'cms_push',
            last_synced_at = NOW()"
    );
    $upU->bind_param('ssssss',
        $student_id, $username, $password_hash, $first_name, $last_name, $email_val
    );
    $upU->execute();
    $aff = $upU->affected_rows;
    if ($aff === 1) $inserted++;
    elseif ($aff === 2) $updated++;
    else $unchanged++;

    // Look up user_id
    $look = $conn->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");
    $look->bind_param('s', $student_id);
    $look->execute();
    $user_id = (int)($look->get_result()->fetch_assoc()['id'] ?? 0);
    if ($user_id === 0) {
        throw new Exception("Failed to find user_id after upsert for id_number=$student_id");
    }

    // 2. Upsert student_profiles
    $upP = $conn->prepare(
        "INSERT INTO student_profiles
            (user_id, course, year_level, section, education_level, guardian_contact)
         VALUES (?, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            course = VALUES(course),
            year_level = VALUES(year_level),
            section = VALUES(section),
            education_level = VALUES(education_level)"
    );
    // All CMS students are college-level — default to 'college' if not provided
$education_level_val = (!empty($education_level) && $education_level !== '') ? $education_level : 'college';
    $upP->bind_param('isssss', $user_id, $course, $year_level, $section, $education_level_val);
    $upP->execute();

    // 3. Upsert enrollments
    $payload_enrollment_keys = [];
    foreach ($enrollments as $enr) {
        $cms_section_id = (int)($enr['cms_section_id'] ?? 0);
        $cms_subject_id = (int)($enr['cms_subject_id'] ?? 0);
        if ($cms_section_id <= 0 || $cms_subject_id <= 0) continue;

        $enr_course       = trim((string)($enr['course'] ?? ''));
        $enr_section_name = trim((string)($enr['section_name'] ?? ''));
        $enr_subject_code = trim((string)($enr['subject_code'] ?? ''));
        $enr_subject_name = trim((string)($enr['subject_name'] ?? ''));

        $upE = $conn->prepare(
            "INSERT INTO student_enrollments
                (user_id, cms_section_id, cms_subject_id, course, section_name,
                 subject_code, subject_name, is_active, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                course = VALUES(course),
                section_name = VALUES(section_name),
                subject_code = VALUES(subject_code),
                subject_name = VALUES(subject_name),
                is_active = 1,
                synced_at = NOW()"
        );
        $upE->bind_param('iisssss',
            $user_id, $cms_section_id, $cms_subject_id,
            $enr_course, $enr_section_name, $enr_subject_code, $enr_subject_name
        );
        $upE->execute();
        $enrollments_upserted++;
        $payload_enrollment_keys[] = $cms_section_id . ':' . $cms_subject_id;
    }

    // 4. Deactivate enrollments not in payload
    if (empty($payload_enrollment_keys)) {
        $deact = $conn->prepare(
            "UPDATE student_enrollments
             SET is_active = 0, synced_at = NOW()
             WHERE user_id = ? AND is_active = 1"
        );
        $deact->bind_param('i', $user_id);
        $deact->execute();
        $enrollments_deactivated = $deact->affected_rows;
    } else {
        $fetch = $conn->prepare(
            "SELECT id, cms_section_id, cms_subject_id
             FROM student_enrollments
             WHERE user_id = ? AND is_active = 1"
        );
        $fetch->bind_param('i', $user_id);
        $fetch->execute();
        $rows = $fetch->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $r) {
            $key = $r['cms_section_id'] . ':' . $r['cms_subject_id'];
            if (!in_array($key, $payload_enrollment_keys, true)) {
                $deact = $conn->prepare(
                    "UPDATE student_enrollments
                     SET is_active = 0, synced_at = NOW()
                     WHERE id = ?"
                );
                $deact->bind_param('i', $r['id']);
                $deact->execute();
                $enrollments_deactivated++;
            }
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    json_fail(500, 'Database error: ' . $e->getMessage());
}

json_out([
    'success' => true,
    'student_id' => $student_id,
    'user_id' => $user_id,
    'user_inserted' => $inserted,
    'user_updated' => $updated,
    'user_unchanged' => $unchanged,
    'enrollments_upserted' => $enrollments_upserted,
    'enrollments_deactivated' => $enrollments_deactivated,
]);