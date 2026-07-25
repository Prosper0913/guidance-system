<?php
require_once __DIR__ . '/../../config/database.php';

class Referral
{
    /**
     * Concern categories mirroring Section III of the paper form.
     * Keyed by the field name used to store selections in the `concerns` JSON column.
     */
    public static function concernCategories(): array
    {
        return [
            'academic' => [
                'label' => 'Academic Concern',
                'items' => [
                    'Declining academic performance',
                    'Difficulty concentrating in class',
                    'Frequent absences or tardiness',
                    'Test anxiety or academic stress',
                    'Poor study habits or lack of motivation',
                    'Risk of academic failure',
                ],
            ],
            'behavioral' => [
                'label' => 'Behavioral Concern',
                'items' => [
                    'Disruptive or aggressive behavior',
                    'Classroom misconduct',
                    'Defiance or non-compliance with school rules',
                    'Withdrawal or oppositional behavior',
                    'Sudden changes in behavior',
                ],
            ],
            'emotional' => [
                'label' => 'Emotional / Psychological Concern',
                'items' => [
                    'Anxiety, excessive worry, or panic symptoms',
                    'Low self-esteem or feelings of hopelessness',
                    'Signs of depression or mood changes',
                    'Stress-related symptoms',
                    'Emotional distress or frequent crying',
                    'Expressions of self-harm or suicidal thoughts',
                ],
            ],
            'social' => [
                'label' => 'Social / Peer Concern',
                'items' => [
                    'Difficulty making or maintaining friendships',
                    'Peer communication or interpersonal skills',
                    'Peer conflicts or bullying (victim or perpetrator)',
                    'Peer pressure concerns',
                    'Social withdrawal or isolation',
                ],
            ],
            'family' => [
                'label' => 'Family-Related Concern',
                'items' => [
                    'Family conflict or separation',
                    'Loss, grief, or major family changes',
                    'Financial difficulties affecting the student',
                    'Lack of family support',
                    'Parental or caregiver issues',
                ],
            ],
            'career' => [
                'label' => 'Career / Personal Development Concern',
                'items' => [
                    'Career indecisions or lack of goals',
                    'Poor self-awareness of strengths and interests',
                    'Course or program uncertainty',
                    'Adjustment issues',
                    'Difficulty with decision-making',
                ],
            ],
        ];
    }

    public static function actionsTakenOptions(): array
    {
        return [
            'Talked to student about the concern',
            'Notified parent/guardian',
            'Given warning/advice',
            'Implemented classroom intervention',
        ];
    }

    public static function initialActionOptions(): array
    {
        return [
            'for_assessment_evaluation'    => 'For assessment/evaluation',
            'for_advising_consulting'      => 'For advising/consulting',
            'for_referral_to_psychologist' => 'For referral to psychologist/mental health professional',
            'for_parent_teacher_meeting'   => 'For parent-teacher meeting/consultation',
            'for_case_consultation'        => 'For case consultation',
            'for_behavioral_monitoring'    => 'For behavioral monitoring/follow-up',
        ];
    }

    public static function create(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO referrals
             (department, referral_date, student_id, student_name, student_id_number, grade_year_level,
              section_course_program, sex, student_contact, preferred_type, preferred_counselor_id,
              preferred_date, preferred_time, referring_party_name, referring_party_position,
              referring_party_department, referring_party_contact, concerns, description_of_incident,
              actions_taken, urgency_level, risk_self_harm, risk_harm_others, severe_emotional_distress,
              crisis_situation, consent_certified)
             VALUES
             (:department, :referral_date, :student_id, :student_name, :student_id_number, :grade_year_level,
              :section_course_program, :sex, :student_contact, :preferred_type, :preferred_counselor_id,
              :preferred_date, :preferred_time, :referring_party_name, :referring_party_position,
              :referring_party_department, :referring_party_contact, :concerns, :description_of_incident,
              :actions_taken, :urgency_level, :risk_self_harm, :risk_harm_others, :severe_emotional_distress,
              :crisis_situation, :consent_certified)'
        );
        $stmt->execute([
            'department' => $data['department'] ?? null,
            'referral_date' => $data['referral_date'],
            'student_id' => $data['student_id'] ?? null,
            'student_name' => $data['student_name'],
            'student_id_number' => $data['student_id_number'] ?? null,
            'grade_year_level' => $data['grade_year_level'] ?? null,
            'section_course_program' => $data['section_course_program'] ?? null,
            'sex' => $data['sex'] ?? null,
            'student_contact' => $data['student_contact'] ?? null,
            'preferred_type' => $data['preferred_type'] ?? null,
            'preferred_counselor_id' => $data['preferred_counselor_id'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
            'referring_party_name' => $data['referring_party_name'],
            'referring_party_position' => $data['referring_party_position'] ?? null,
            'referring_party_department' => $data['referring_party_department'] ?? null,
            'referring_party_contact' => $data['referring_party_contact'] ?? null,
            'concerns' => json_encode($data['concerns'] ?? []),
            'description_of_incident' => $data['description_of_incident'] ?? null,
            'actions_taken' => json_encode($data['actions_taken'] ?? []),
            'urgency_level' => $data['urgency_level'] ?? 'routine',
            'risk_self_harm' => !empty($data['risk_self_harm']) ? 1 : 0,
            'risk_harm_others' => !empty($data['risk_harm_others']) ? 1 : 0,
            'severe_emotional_distress' => !empty($data['severe_emotional_distress']) ? 1 : 0,
            'crisis_situation' => !empty($data['crisis_situation']) ? 1 : 0,
            'consent_certified' => !empty($data['consent_certified']) ? 1 : 0,
        ]);
        $id = (int)$db->lastInsertId();

        // Assign a human-readable referral number now that we have the auto-increment id
        $referralNo = 'REF-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $upd = $db->prepare('UPDATE referrals SET referral_no = ? WHERE id = ?');
        $upd->execute([$referralNo, $id]);

        return $id;
    }

    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT r.*, c.first_name AS counselor_first, c.last_name AS counselor_last,
                    rb.first_name AS received_by_first, rb.last_name AS received_by_last,
                    pc.first_name AS preferred_counselor_first, pc.last_name AS preferred_counselor_last
             FROM referrals r
             LEFT JOIN users c ON c.id = r.assigned_counselor_id
             LEFT JOIN users rb ON rb.id = r.received_by
             LEFT JOIN users pc ON pc.id = r.preferred_counselor_id
             WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['concerns'] = json_decode($row['concerns'], true) ?: [];
        $row['actions_taken'] = json_decode($row['actions_taken'], true) ?: [];
        $row['initial_action'] = json_decode($row['initial_action'] ?? '[]', true) ?: [];
        return $row;
    }

    // Referrals a student submitted about themselves ("My Referrals" page)
    public static function forStudent(int $studentId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT r.*, c.first_name AS counselor_first, c.last_name AS counselor_last
             FROM referrals r
             LEFT JOIN users c ON c.id = r.assigned_counselor_id
             WHERE r.student_id = ?
             ORDER BY r.submitted_at DESC"
        );
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }

    public static function all(?string $status = null): array
    {
        $db = Database::getConnection();
        $sql = "SELECT r.*, c.first_name AS counselor_first, c.last_name AS counselor_last
                FROM referrals r
                LEFT JOIN users c ON c.id = r.assigned_counselor_id
                WHERE 1=1";
        $params = [];
        if ($status) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY
                    CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END,
                    CASE WHEN r.urgency_level = 'urgent' THEN 0 ELSE 1 END,
                    r.submitted_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function forCounselor(int $counselorId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT r.* FROM referrals r WHERE r.assigned_counselor_id = ?
             ORDER BY CASE WHEN r.urgency_level = 'urgent' THEN 0 ELSE 1 END, r.submitted_at DESC"
        );
        $stmt->execute([$counselorId]);
        return $stmt->fetchAll();
    }

    public static function countByStatus(string $status): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM referrals WHERE status = ?');
        $stmt->execute([$status]);
        return (int)$stmt->fetchColumn();
    }

    // Other unscheduled referrals (no appointment linked yet) requesting the exact same
    // preferred date/time — multiple students can prefer the same slot, but only one
    // appointment can ultimately be scheduled for it. Used to warn staff during triage.
    // All not-yet-scheduled referrals still requesting this exact date/time — the group a
    // counselor needs to pick a winner from when two or more students want the same slot.
    public static function findConflictGroup(string $date, string $time): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT r.*, c.first_name AS counselor_first, c.last_name AS counselor_last
             FROM referrals r
             LEFT JOIN users c ON c.id = r.assigned_counselor_id
             WHERE r.appointment_id IS NULL AND r.preferred_date = ? AND r.preferred_time = ?
             ORDER BY CASE WHEN r.urgency_level = 'urgent' THEN 0 ELSE 1 END, r.submitted_at ASC"
        );
        $stmt->execute([$date, $time]);
        return $stmt->fetchAll();
    }

    public static function countConflictingPreferences(int $referralId, ?string $preferredDate, ?string $preferredTime): int
    {
        if (!$preferredDate || !$preferredTime) {
            return 0;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM referrals
             WHERE id != ? AND appointment_id IS NULL
               AND preferred_date = ? AND preferred_time = ?"
        );
        $stmt->execute([$referralId, $preferredDate, $preferredTime]);
        return (int)$stmt->fetchColumn();
    }

    // Bulk version for list views (referral triage table) — annotates each row with a
    // conflict count without an N+1 query per row.
    public static function withConflictCounts(array $referrals): array
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT preferred_date, preferred_time, COUNT(*) AS cnt
             FROM referrals
             WHERE appointment_id IS NULL AND preferred_date IS NOT NULL AND preferred_time IS NOT NULL
             GROUP BY preferred_date, preferred_time
             HAVING COUNT(*) > 1"
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['preferred_date'] . '|' . $row['preferred_time']] = (int)$row['cnt'];
        }
        foreach ($referrals as &$r) {
            $key = ($r['preferred_date'] ?? '') . '|' . ($r['preferred_time'] ?? '');
            $r['conflicting_preference_count'] = ($r['preferred_date'] && $r['preferred_time'] && isset($counts[$key]))
                ? $counts[$key] - 1
                : 0;
        }
        unset($r);
        return $referrals;
    }

    // Link a referral to a registered student account (found via ID number or name search)
    public static function linkStudent(int $referralId, int $studentId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE referrals SET student_id = ? WHERE id = ?');
        $stmt->execute([$studentId, $referralId]);
    }

    // Section VIII — Guidance Office processing
    public static function process(int $referralId, array $data, int $processedBy): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE referrals SET
                status = :status,
                received_by = :received_by,
                received_at = COALESCE(received_at, NOW()),
                initial_action = :initial_action,
                assigned_counselor_id = :assigned_counselor_id,
                office_remarks = :office_remarks
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $data['status'],
            'received_by' => $processedBy,
            'initial_action' => json_encode($data['initial_action'] ?? []),
            'assigned_counselor_id' => $data['assigned_counselor_id'] ?: null,
            'office_remarks' => $data['office_remarks'] ?? null,
            'id' => $referralId,
        ]);
    }

    public static function linkAppointment(int $referralId, int $appointmentId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE referrals SET appointment_id = ? WHERE id = ?');
        $stmt->execute([$appointmentId, $referralId]);
    }

    // Simple search to help guidance staff match a referral to an existing student account
    public static function searchStudents(string $query): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id, id_number, first_name, last_name, email FROM users
             WHERE role = 'student' AND (id_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
             ORDER BY last_name LIMIT 15"
        );
        $like = '%' . $query . '%';
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll();
    }
}
