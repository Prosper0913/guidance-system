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
        // Match by email OR username — CMS-pushed students have
        // username set but email NULL, so they log in with username.
        // Legacy admin/counselor accounts have email set but username
        // NULL, so they continue to log in with email.
        $stmt = $db->prepare(
            'SELECT * FROM users 
             WHERE email = ? OR username = ? 
             LIMIT 1'
        );
        $stmt->execute([$login, $login]);
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
     * Get the counselor assigned to handle a student's education level.
     * Junior Highschool -> counselor with education_level_group = 'junior_highschool'
     * Senior Highschool / College -> counselor with education_level_group = 'senior_college'
     */
    public static function getCounselorForStudent(int $studentId): ?array
    {
        $profile = self::studentProfile($studentId);
        if (!$profile || empty($profile['education_level'])) {
            return null;
        }

        $db = Database::getConnection();

        if ($profile['education_level'] === 'junior_highschool') {
            $group = 'junior_highschool';
        } else {
            // Both senior_highschool and college go to the same counselor
            $group = 'senior_college';
        }

        $stmt = $db->prepare(
            "SELECT u.id, u.first_name, u.last_name, cp.specialization, cp.office_location
             FROM users u
             JOIN counselor_profiles cp ON cp.user_id = u.id
             WHERE u.role = 'counselor' AND u.status = 'active'
               AND cp.education_level_group = ?
             LIMIT 1"
        );
        $stmt->execute([$group]);
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
            $stmt2 = $db->prepare(
                'INSERT INTO counselor_profiles (user_id, specialization, office_location) VALUES (?, ?, ?)'
            );
            $stmt2->execute([$userId, $data['specialization'] ?? null, $data['office_location'] ?? null]);
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
                       cp.specialization
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
