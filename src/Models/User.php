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
                'INSERT INTO student_profiles (user_id, course, year_level, section)
                 VALUES (:user_id, :course, :year_level, :section)'
            );
            $stmt2->execute([
                'user_id' => $userId,
                'course' => $data['course'] ?? null,
                'year_level' => $data['year_level'] ?? null,
                'section' => $data['section'] ?? null,
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

    public static function authenticate(string $email, string $password): ?array
    {
        $user = self::findByEmail($email);
        if (!$user || $user['status'] !== 'active') {
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
        $stmt = $db->prepare('SELECT id, id_number, first_name, last_name, email, status, created_at FROM users WHERE role = ? ORDER BY last_name');
        $stmt->execute([$role]);
        return $stmt->fetchAll();
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
