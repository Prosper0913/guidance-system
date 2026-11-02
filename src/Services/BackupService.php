<?php
require_once __DIR__ . '/../Helpers/XlsxWriter.php';

/**
 * Manual backups, generated in PHP (no mysqldump needed, so it works on XAMPP and the droplet alike).
 *
 *  - Admin:      whole database (restorable) or a date range (safe partial export).
 *  - Counselor:  only their OWN appointments, referrals, notes and status history, as .sql or .xlsx.
 *                The counselor is always taken from the login session, never from form input.
 *
 * Safety rules baked in:
 *  - Whole-database SQL uses DROP + CREATE + INSERT (a true restore point).
 *  - Date-range / counselor SQL never DROPs anything and uses INSERT IGNORE, so importing such a file into a
 *    live database can only ADD missing rows; it can never delete or overwrite existing data.
 *  - Sign-in credentials stored for Google Calendar are left out unless an admin explicitly asks for them.
 */
class BackupService
{
    /** Tables holding sign-in credentials. Structure is always exported; rows only on explicit request. */
    private const SECRET_TABLES = ['counselor_google_tokens'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    //  Input helpers
    // ------------------------------------------------------------------

    /** Validates a date range (YYYY-MM-DD, start <= end). Returns [from, to] or throws InvalidArgumentException. */
    public static function parseRange(?string $from, ?string $to): array
    {
        $f = self::parseDate($from);
        $t = self::parseDate($to);
        if ($f === null || $t === null) {
            throw new InvalidArgumentException('Please choose both a start date and an end date.');
        }
        if ($f > $t) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }
        return [$f, $t];
    }

    private static function parseDate(?string $s): ?string
    {
        $s = (string)$s;
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /** A filename-safe fragment (letters, digits, dash, underscore). */
    public static function safeName(string $s): string
    {
        $s = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $s), '-');
        return $s === '' ? 'user' : substr($s, 0, 40);
    }

    /** Clears any output buffering and sends download headers. Call before streaming a file. */
    public static function startDownload(string $filename, string $mime): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (function_exists('session_write_close')) { session_write_close(); }   // don't hold the session lock while streaming
        @set_time_limit(0);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    // ------------------------------------------------------------------
    //  ADMIN: SQL
    // ------------------------------------------------------------------

    /**
     * Streams an admin SQL backup through $out(string).
     * $mode 'whole' = everything (restore point); 'range' = date-filtered partial export.
     * Returns [table => rows written].
     */
    public function writeAdminSql(callable $out, string $mode, ?array $range, bool $includeSecrets, string $generatedBy): array
    {
        $whole = ($mode !== 'range');
        if (!$whole && !$range) {
            throw new InvalidArgumentException('A date range is required for a range backup.');
        }

        $scope = $whole ? 'WHOLE DATABASE (restore point)' : "PARTIAL: dates {$range[0]} to {$range[1]}";
        $out("-- Guidance Management System backup\n");
        $out('-- Created: ' . date('Y-m-d H:i:s') . " (server time) by {$this->oneLine($generatedBy)}\n");
        $out("-- Scope:   {$scope}\n");
        if ($whole) {
            $out("-- Importing this file REPLACES every table it contains (DROP + CREATE + INSERT).\n");
        } else {
            $out("-- Appointments, referrals, notes, notifications and audit logs are limited to the dates above;\n");
            $out("-- accounts, profiles, availability and categories are included in full.\n");
            $out("-- Safe to import into a live database: nothing is dropped and existing rows are skipped (INSERT IGNORE).\n");
        }
        $out("-- WARNING: contains personal data and password hashes. Store it securely.\n\n");
        $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $stats = [];
        foreach ($this->listTables() as $table) {
            $create = $this->createTableSql($table);
            $out("-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n");
            if ($whole) {
                $out('DROP TABLE IF EXISTS ' . $this->ident($table) . ";\n" . $create . ";\n\n");
            } else {
                $out(preg_replace('/^CREATE TABLE\s/', 'CREATE TABLE IF NOT EXISTS ', $create) . ";\n\n");
            }

            if (in_array($table, self::SECRET_TABLES, true) && !$includeSecrets) {
                $out("-- Rows intentionally NOT exported (sign-in credentials). Counselors reconnect Google Calendar after a restore.\n\n");
                $stats[$table] = 0;
                continue;
            }

            [$where, $params] = $whole ? ['', []] : $this->rangeFilter($table, $range);
            $stmt = $this->db->prepare('SELECT * FROM ' . $this->ident($table) . ($where !== '' ? ' WHERE ' . $where : ''));
            $stmt->execute($params);
            $stats[$table] = $this->streamInserts($out, $table, $stmt, !$whole);
            $out("\n");
        }

        $out("SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n-- Backup completed " . date('Y-m-d H:i:s') . "\n");
        return $stats;
    }

    /** Date filter for one table in an admin range backup: [where-sql, params]. */
    private function rangeFilter(string $table, array $range): array
    {
        [$f, $t] = $range;
        $viaAppointment = 'appointment_id IN (SELECT id FROM appointments WHERE appointment_date BETWEEN ? AND ?)';
        switch ($table) {
            case 'appointments':     return ['appointment_date BETWEEN ? AND ?', [$f, $t]];
            case 'appointment_logs':
            case 'attendance_logs':
            case 'session_notes':    return [$viaAppointment, [$f, $t]];
            case 'referrals':        return ['referral_date BETWEEN ? AND ?', [$f, $t]];
            case 'notifications':    return ['DATE(sent_at) BETWEEN ? AND ?', [$f, $t]];
            case 'audit_logs':       return ['DATE(created_at) BETWEEN ? AND ?', [$f, $t]];
            default:                 return ['', []];      // accounts, profiles, availability, categories: in full
        }
    }

    // ------------------------------------------------------------------
    //  COUNSELOR: SQL (own data only)
    // ------------------------------------------------------------------

    /** Row counts a counselor would get for a range (used to refuse empty exports). */
    public function counselorCounts(int $counselorId, array $range): array
    {
        [$f, $t] = $range;
        $c = function (string $sql, array $p) { $s = $this->db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
        return [
            'appointments' => $c('SELECT COUNT(*) FROM appointments WHERE counselor_id = ? AND appointment_date BETWEEN ? AND ?', [$counselorId, $f, $t]),
            'referrals' => $c('SELECT COUNT(*) FROM referrals WHERE assigned_counselor_id = ? AND referral_date BETWEEN ? AND ?', [$counselorId, $f, $t]),
        ];
    }

    /**
     * Streams a counselor's own records as SQL. $counselor must come from the session (id + names).
     * Returns [table => rows written].
     */
    public function writeCounselorSql(callable $out, array $counselor, array $range): array
    {
        $cid = (int)$counselor['id'];
        [$f, $t] = $range;
        $mine = 'SELECT id FROM appointments WHERE counselor_id = ? AND appointment_date BETWEEN ? AND ?';
        $name = trim(($counselor['first_name'] ?? '') . ' ' . ($counselor['last_name'] ?? ''));

        $out("-- Guidance Management System: personal records backup\n");
        $out("-- Counselor: {$this->oneLine($name)} (user id {$cid})\n");
        $out("-- Dates:     {$f} to {$t}\n");
        $out('-- Created:   ' . date('Y-m-d H:i:s') . " (server time)\n");
        $out("-- Contains only this counselor's own appointments, status history, session notes and referrals.\n");
        $out("-- No tables are dropped; INSERT IGNORE means importing only ADDS rows that are missing.\n");
        $out("-- student_id values match backup_student_directory below. This file contains confidential student information.\n\n");
        $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $sets = [
            'appointments'     => ['counselor_id = ? AND appointment_date BETWEEN ? AND ?', [$cid, $f, $t], null],
            'appointment_logs' => ["appointment_id IN ({$mine})", [$cid, $f, $t], null],
            'attendance_logs'  => ["appointment_id IN ({$mine})", [$cid, $f, $t], null],
            'session_notes'    => ["counselor_id = ? AND appointment_id IN ({$mine})", [$cid, $cid, $f, $t], null],
            'referrals'        => ['assigned_counselor_id = ? AND referral_date BETWEEN ? AND ?', [$cid, $f, $t],
                // another counselor's id (a student's "preferred counselor") is not this counselor's data
                function (array $row) use ($cid) {
                    if (array_key_exists('preferred_counselor_id', $row) && (int)$row['preferred_counselor_id'] !== $cid) {
                        $row['preferred_counselor_id'] = null;
                    }
                    return $row;
                }],
        ];

        $stats = [];
        foreach ($sets as $table => [$where, $params, $transform]) {
            $out("-- Table: {$table}\n");
            $stmt = $this->db->prepare('SELECT * FROM ' . $this->ident($table) . ' WHERE ' . $where);
            $stmt->execute($params);
            $stats[$table] = $this->streamInserts($out, $table, $stmt, true, $transform);
            $out("\n");
        }

        // Who the student ids refer to: a small standalone table, never touches the real users table.
        $out("-- Table: backup_student_directory (student names for the ids above; no passwords)\n");
        $out("CREATE TABLE IF NOT EXISTS `backup_student_directory` (\n"
            . "  `user_id` int(11) NOT NULL,\n  `id_number` varchar(50) DEFAULT NULL,\n  `first_name` varchar(100) DEFAULT NULL,\n"
            . "  `last_name` varchar(100) DEFAULT NULL,\n  `email` varchar(150) DEFAULT NULL,\n  `contact_number` varchar(50) DEFAULT NULL,\n"
            . "  `education_level` varchar(30) DEFAULT NULL,\n  `course` varchar(150) DEFAULT NULL,\n  `year_level` varchar(50) DEFAULT NULL,\n"
            . "  `section` varchar(50) DEFAULT NULL,\n  PRIMARY KEY (`user_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n");
        $stmt = $this->db->prepare(
            'SELECT u.id AS user_id, u.id_number, u.first_name, u.last_name, u.email, u.contact_number,
                    sp.education_level, sp.course, sp.year_level, sp.section
             FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id
             WHERE u.id IN (SELECT student_id FROM appointments WHERE counselor_id = ? AND appointment_date BETWEEN ? AND ?)
                OR u.id IN (SELECT student_id FROM referrals WHERE assigned_counselor_id = ? AND referral_date BETWEEN ? AND ? AND student_id IS NOT NULL)'
        );
        $stmt->execute([$cid, $f, $t, $cid, $f, $t]);
        $stats['backup_student_directory'] = $this->streamInserts($out, 'backup_student_directory', $stmt, true);

        $out("\nSET FOREIGN_KEY_CHECKS=1;\n-- Backup completed " . date('Y-m-d H:i:s') . "\n");
        return $stats;
    }

    // ------------------------------------------------------------------
    //  COUNSELOR: Excel (own data only)
    // ------------------------------------------------------------------

    /** Sheets for XlsxWriter::build(). Everything is filtered by the counselor id. */
    public function counselorSheets(array $counselor, array $range): array
    {
        $cid = (int)$counselor['id'];
        [$f, $t] = $range;
        $name = trim(($counselor['first_name'] ?? '') . ' ' . ($counselor['last_name'] ?? ''));
        $fetch = function (string $sql, array $p) { $s = $this->db->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };
        $full = fn($r, $a = 'first_name', $b = 'last_name') => trim(($r[$a] ?? '') . ' ' . ($r[$b] ?? ''));
        $yn = fn($v) => ((int)$v) ? 'Yes' : 'No';

        // --- appointments
        $appts = $fetch(
            'SELECT a.*, s.id_number AS s_idnum, s.first_name AS s_first, s.last_name AS s_last,
                    sp.course AS s_course, sp.year_level AS s_year, cc.name AS category_name
             FROM appointments a
             JOIN users s ON s.id = a.student_id
             LEFT JOIN student_profiles sp ON sp.user_id = s.id
             LEFT JOIN concern_categories cc ON cc.id = a.concern_category_id
             WHERE a.counselor_id = ? AND a.appointment_date BETWEEN ? AND ?
             ORDER BY a.appointment_date, a.appointment_time', [$cid, $f, $t]);
        $apptRows = [['Date', 'Time', 'Student ID', 'Student', 'Course / Strand', 'Year level', 'Type', 'Status', 'Concern category', 'Confidential', 'Cancellation reason', 'Notes', 'Booked on']];
        $byType = ['walk-in' => 0, 'online' => 0];
        $byStatus = [];
        foreach ($appts as $a) {
            $apptRows[] = [$a['appointment_date'], substr((string)$a['appointment_time'], 0, 5), $a['s_idnum'], $full($a, 's_first', 's_last'),
                $a['s_course'], $a['s_year'], $a['type'] === 'walk-in' ? 'Walk-in' : 'Online', ucfirst((string)$a['status']),
                $a['category_name'], $yn($a['is_confidential'] ?? 0), $a['cancellation_reason'] ?? '', $a['notes'] ?? '', $a['created_at'] ?? ''];
            $byType[$a['type']] = ($byType[$a['type']] ?? 0) + 1;
            $byStatus[$a['status']] = ($byStatus[$a['status']] ?? 0) + 1;
        }

        // --- status history
        $hist = $fetch(
            'SELECT l.*, a.appointment_date, a.appointment_time, s.id_number AS s_idnum, s.first_name AS s_first, s.last_name AS s_last,
                    u.first_name AS by_first, u.last_name AS by_last
             FROM appointment_logs l
             JOIN appointments a ON a.id = l.appointment_id
             JOIN users s ON s.id = a.student_id
             LEFT JOIN users u ON u.id = l.changed_by
             WHERE a.counselor_id = ? AND a.appointment_date BETWEEN ? AND ?
             ORDER BY l.changed_at', [$cid, $f, $t]);
        $histRows = [['Changed on', 'Appointment date', 'Appointment time', 'Student ID', 'Student', 'From status', 'To status', 'Changed by', 'Remarks']];
        foreach ($hist as $h) {
            $histRows[] = [$h['changed_at'], $h['appointment_date'], substr((string)$h['appointment_time'], 0, 5), $h['s_idnum'], $full($h, 's_first', 's_last'),
                $h['old_status'] !== null ? ucfirst((string)$h['old_status']) : '', ucfirst((string)$h['new_status']), $full($h, 'by_first', 'by_last'), $h['remarks'] ?? ''];
        }

        // --- session notes (only the notes this counselor wrote)
        $notes = $fetch(
            'SELECT n.*, a.appointment_date, s.id_number AS s_idnum, s.first_name AS s_first, s.last_name AS s_last
             FROM session_notes n
             JOIN appointments a ON a.id = n.appointment_id
             JOIN users s ON s.id = a.student_id
             WHERE a.counselor_id = ? AND n.counselor_id = ? AND a.appointment_date BETWEEN ? AND ?
             ORDER BY a.appointment_date, n.created_at', [$cid, $cid, $f, $t]);
        $noteRows = [['Appointment date', 'Student ID', 'Student', 'Note', 'Shared with student', 'Written on']];
        foreach ($notes as $n) {
            $noteRows[] = [$n['appointment_date'], $n['s_idnum'], $full($n, 's_first', 's_last'), $n['notes'], $yn($n['visible_to_student'] ?? 0), $n['created_at']];
        }

        // --- referrals assigned to this counselor
        $refs = $fetch(
            'SELECT r.*, ap.appointment_date AS linked_date
             FROM referrals r
             LEFT JOIN appointments ap ON ap.id = r.appointment_id AND ap.counselor_id = r.assigned_counselor_id
             WHERE r.assigned_counselor_id = ? AND r.referral_date BETWEEN ? AND ?
             ORDER BY r.referral_date, r.id', [$cid, $f, $t]);
        $refRows = [['Referral no.', 'Referral date', 'Student ID', 'Student', 'Grade / Year', 'Course / Program', 'Referred by', 'Position', 'Concerns',
            'Description', 'Actions taken', 'Urgency', 'Risk of self-harm', 'Risk to others', 'Severe distress', 'Crisis', 'Status', 'Submitted via', 'Office remarks', 'Appointment date']];
        foreach ($refs as $r) {
            $refRows[] = [$r['referral_no'] ?? '', $r['referral_date'], $r['student_id_number'] ?? '', $r['student_name'], $r['grade_year_level'] ?? '',
                $r['section_course_program'] ?? '', $r['referring_party_name'] ?? '', $r['referring_party_position'] ?? '', $this->flatten(json_decode((string)($r['concerns'] ?? ''), true)),
                $r['description_of_incident'] ?? '', $this->flatten(json_decode((string)($r['actions_taken'] ?? ''), true)), ucfirst((string)$r['urgency_level']),
                $yn($r['risk_self_harm'] ?? 0), $yn($r['risk_harm_others'] ?? 0), $yn($r['severe_emotional_distress'] ?? 0), $yn($r['crisis_situation'] ?? 0),
                ucfirst((string)$r['status']), ($r['submitted_via'] ?? '') === 'walk-in' ? 'Walk-in' : 'Online', $r['office_remarks'] ?? '', $r['linked_date'] ?? ''];
        }

        // --- summary
        $sum = [['Item', 'Value'],
            ['Counselor', $name], ['Date range', "{$f} to {$t}"], ['Created', date('Y-m-d H:i:s')],
            ['Appointments (total)', count($appts)], ['  Walk-in', $byType['walk-in']], ['  Online', $byType['online']]];
        foreach ($byStatus as $st => $n) { $sum[] = ['  ' . ucfirst((string)$st), $n]; }
        $sum[] = ['Referrals', count($refs)];
        $sum[] = ['Session notes', count($notes)];
        $sum[] = ['Note', 'Only your own records are included. This file contains confidential student information.'];

        return ['Summary' => $sum, 'Appointments' => $apptRows, 'Referrals' => $refRows, 'Session Notes' => $noteRows, 'Status History' => $histRows];
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /** Base tables of the current database, alphabetical. */
    protected function listTables(): array
    {
        $rows = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $names = array_map(fn($r) => (string)$r[0], $rows);
        sort($names);
        return $names;
    }

    /** The CREATE TABLE statement for a table (no trailing semicolon). */
    protected function createTableSql(string $table): string
    {
        $row = $this->db->query('SHOW CREATE TABLE ' . $this->ident($table))->fetch(PDO::FETCH_NUM);
        return rtrim((string)$row[1], ";\n ");
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function oneLine(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function literal($v): string
    {
        if ($v === null) { return 'NULL'; }
        if (is_bool($v)) { return $v ? '1' : '0'; }
        if (is_int($v) || is_float($v)) { return (string)$v; }
        return $this->db->quote((string)$v);
    }

    /** Writes the statement's rows as batched INSERTs. Returns the number of rows written. */
    private function streamInserts(callable $out, string $table, PDOStatement $stmt, bool $ignore, ?callable $transform = null): int
    {
        $count = 0;
        $batch = [];
        $bytes = 0;
        $cols = null;
        $head = '';
        $flush = function () use (&$batch, &$bytes, &$head, $out) {
            if ($batch) { $out($head . implode(",\n", $batch) . ";\n"); }
            $batch = [];
            $bytes = 0;
        };

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($transform) { $row = $transform($row); }
            if ($cols === null) {
                $cols = array_keys($row);
                $head = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO ' . $this->ident($table)
                    . ' (' . implode(', ', array_map([$this, 'ident'], $cols)) . ") VALUES\n";
            }
            $line = '(' . implode(', ', array_map([$this, 'literal'], array_values($row))) . ')';
            $batch[] = $line;
            $bytes += strlen($line);
            $count++;
            if (count($batch) >= 200 || $bytes > 262144) { $flush(); }
        }
        $stmt->closeCursor();
        $flush();
        if ($count === 0) { $out("-- (no rows)\n"); }
        return $count;
    }

    /** Turns decoded JSON (referral concerns / actions) into readable text. */
    private function flatten($v): string
    {
        if (!is_array($v)) { return is_scalar($v) ? trim((string)$v) : ''; }
        $parts = [];
        foreach ($v as $k => $x) {
            $s = $this->flatten($x);
            if ($s === '') { continue; }
            $parts[] = is_string($k) ? ucfirst(str_replace('_', ' ', $k)) . ': ' . $s : $s;
        }
        return implode('; ', $parts);
    }
}
