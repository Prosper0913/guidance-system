<?php
require_once __DIR__ . '/../../config/database.php';

class User
{
    public static function findByEmail(string $email): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByLogin(string $login): ?array
    {
        $db = Database::getConnection();
        // Match by ID number, email, or username — students log in with their
        // Student ID (id_number); legacy admin/counselor accounts still use
        // email; CMS-pushed students may have username set instead.
        $stmt = $db->prepare(
            'SELECT * FROM users 
             WHERE id_number = ? OR email = ? OR username = ? 
             LIMIT 1'
        );
        $stmt->execute([$login, $login, $login]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function studentProfile(int $userId): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * The education levels a counselor can be assigned to. Keys are the values stored in
     * student_profiles.education_level and counselor_profiles.education_level_group;
     * the order here is the canonical order used when saving and displaying.
     */
    public static function educationLevels(): array
    {
        return [
            'junior_highschool' => 'Junior High School',
            'senior_highschool' => 'Senior High School',
            'college'           => 'College',
        ];
    }

    /**
     * Human-readable list ("Junior High School, College") for a stored
     * education_level_group value like 'junior_highschool,college'.
     */
    public static function educationLevelLabels(?string $group): string
    {
        $all = self::educationLevels();
        $labels = [];
        foreach (array_filter(explode(',', (string)$group)) as $code) {
            if (isset($all[$code])) $labels[] = $all[$code];
        }
        return implode(', ', $labels);
    }

    /**
     * Get the counselor assigned to handle a student's education level.
     * A counselor covers one or more levels (counselor_profiles.education_level_group is a
     * comma-separated list, e.g. 'junior_highschool,senior_highschool'). If more than one
     * active counselor covers the level, the one covering the FEWEST levels wins (the most
     * dedicated counselor), then the earliest-created account, so the result is stable.
     */
    public static function getCounselorForStudent(int $studentId): ?array
    {
        $profile = self::studentProfile($studentId);
        if (!$profile || empty($profile['education_level'])) {
            return null;
        }

        $db = Database::getConnection();

        $stmt = $db->prepare(
            "SELECT u.id, u.first_name, u.last_name, cp.specialization, cp.office_location
             FROM users u
             JOIN counselor_profiles cp ON cp.user_id = u.id
             WHERE u.role = 'counselor' AND u.status = 'active'
               AND FIND_IN_SET(?, cp.education_level_group) > 0
             ORDER BY (LENGTH(cp.education_level_group) - LENGTH(REPLACE(cp.education_level_group, ',', ''))) ASC,
                      u.id ASC
             LIMIT 1"
        );
        $stmt->execute([$profile['education_level']]);
        $counselor = $stmt->fetch();
        return $counselor ?: null;
    }

    /**
     * Save/update a student's education level.
     */
    public static function saveEducationLevel(int $userId, string $level): void
    {
        $db = Database::getConnection();
        // Use UPSERT — old CMS-synced students may not have a student_profiles row yet
        $stmt = $db->prepare(
            'INSERT INTO student_profiles (user_id, education_level)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE education_level = VALUES(education_level)'
        );
        $stmt->execute([$userId, $level]);
    }

    /**
     * Change a user's password after verifying the current one.
     * Returns false if the current password is wrong.
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            return false;
        }
        $upd = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        return true;
    }

    // Change a student's course/strand (e.g. after transferring). UPSERT because
    // CMS-synced students may not have a student_profiles row yet.
    public static function saveCourse(int $userId, string $course): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO student_profiles (user_id, course) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE course = VALUES(course)'
        );
        $stmt->execute([$userId, $course]);
    }

    public static function idNumberExists(string $idNumber): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id FROM users WHERE id_number = ? LIMIT 1');
        $stmt->execute([$idNumber]);
        return (bool)$stmt->fetch();
    }

    public static function createStudent(array $data): int
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO users (role, id_number, first_name, last_name, email, contact_number, password_hash)
                 VALUES (:role, :id_number, :first_name, :last_name, :email, :contact_number, :password_hash)'
            );
            $stmt->execute([
                'role' => ROLE_STUDENT,
                'id_number' => $data['id_number'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'contact_number' => $data['contact_number'] ?? null,
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ]);
            $userId = (int)$db->lastInsertId();

            $stmt2 = $db->prepare(
                'INSERT INTO student_profiles (user_id, course, year_level, section, education_level)
                 VALUES (:user_id, :course, :year_level, :section, :education_level)'
            );
            $stmt2->execute([
                'user_id' => $userId,
                'course' => $data['course'] ?? null,
                'year_level' => $data['year_level'] ?? null,
                'section' => $data['section'] ?? null,
                'education_level' => $data['education_level'] ?? null,
            ]);

            $db->commit();
            return $userId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Admin-only: create counselor or admin account
    public static function createStaff(array $data): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO users (role, id_number, first_name, last_name, email, contact_number, password_hash)
             VALUES (:role, :id_number, :first_name, :last_name, :email, :contact_number, :password_hash)'
        );
        $stmt->execute([
            'role' => $data['role'],
            'id_number' => $data['id_number'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);
        $userId = (int)$db->lastInsertId();

        if ($data['role'] === ROLE_COUNSELOR) {
            // Education levels the counselor covers (validated + in canonical order by the
            // caller). Stored as a comma-separated list for routing, and as readable labels
            // in `specialization` so existing screens that show it keep working.
            $levels = array_values(array_intersect(array_keys(self::educationLevels()), (array)($data['education_levels'] ?? [])));
            $group = $levels ? implode(',', $levels) : null;
            $specialization = $levels ? self::educationLevelLabels($group) : ($data['specialization'] ?? null);

            $stmt2 = $db->prepare(
                'INSERT INTO counselor_profiles (user_id, specialization, education_level_group, office_location) VALUES (?, ?, ?, ?)'
            );
            $stmt2->execute([$userId, $specialization, $group, $data['office_location'] ?? null]);
        }
        return $userId;
    }

    public static function authenticate(string $login, string $password): ?array
    {
        // Accept either email or username — CMS-pushed students have
        // username but no email; legacy accounts have email but no username.
        $user = self::findByLogin($login);
        if (!$user || $user['status'] !== 'active') {
            return null;
        }
        // Also respect the new is_active flag (set to 0 by CMS sync
        // when a student is deleted from classroom_db2).
        if (isset($user['is_active']) && (int)$user['is_active'] !== 1) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

public static function allByRole(string $role): array
    {
        $db = Database::getConnection();
        
        if ($role === ROLE_STUDENT) {
            // Join student profiles
            $stmt = $db->prepare('
                SELECT u.id, u.id_number, u.first_name, u.last_name, u.email, u.status, u.created_at,
                       sp.course, sp.year_level, sp.section
                FROM users u
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                WHERE u.role = ? 
                ORDER BY u.last_name
            ');
        } elseif ($role === ROLE_COUNSELOR) {
            // Join counselor profiles
            $stmt = $db->prepare('
                SELECT u.id, u.id_number, u.first_name, u.last_name, u.email, u.status, u.created_at,
                       cp.specialization, cp.education_level_group
                FROM users u
                LEFT JOIN counselor_profiles cp ON u.id = cp.user_id
                WHERE u.role = ? 
                ORDER BY u.last_name
            ');
        } else {
            // Standard query for Admins
            $stmt = $db->prepare('
                SELECT id, id_number, first_name, last_name, email, status, created_at 
                FROM users 
                WHERE role = ? 
                ORDER BY last_name
            ');
        }
        
        $stmt->execute([$role]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public static function setStatus(int $userId, string $status): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $userId]);
    }

    public static function allCounselors(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT u.id, u.first_name, u.last_name, cp.specialization, cp.office_location
             FROM users u JOIN counselor_profiles cp ON cp.user_id = u.id
             WHERE u.role = 'counselor' AND u.status = 'active'
             ORDER BY u.last_name"
        );
        return $stmt->fetchAll();
    }
}
