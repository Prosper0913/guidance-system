<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$db = Database::getConnection();

$tab = ($_GET['tab'] ?? 'logins') === 'appointments' ? 'appointments' : 'logins';
$filename = ($tab === 'appointments' ? 'appointment-status-logs_' : 'login-activity_') . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

if ($tab === 'appointments') {
    $stmt = $db->query(
        "SELECT al.changed_at, u.first_name, u.last_name, a.appointment_date, a.appointment_time,
                al.old_status, al.new_status, al.remarks
         FROM appointment_logs al
         JOIN users u ON u.id = al.changed_by
         JOIN appointments a ON a.id = al.appointment_id
         ORDER BY al.changed_at DESC"
    );
    fputcsv($out, ['When', 'Appointment Date', 'Appointment Time', 'Changed By', 'Old Status', 'New Status', 'Remarks']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['changed_at'],
            $row['appointment_date'],
            $row['appointment_time'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['old_status'] ?? '',
            $row['new_status'],
            $row['remarks'] ?? '',
        ]);
    }
} else {
    $stmt = $db->query(
        "SELECT al.created_at, u.first_name, u.last_name, u.role AS account_role, al.action, al.details, al.ip_address, al.user_agent
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action IN ('login_success', 'login_failed')
         ORDER BY al.created_at DESC"
    );
    fputcsv($out, ['When', 'Account', 'Role', 'Result', 'Details', 'IP Address', 'User Agent']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['created_at'],
            $row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'Unknown / unregistered email',
            $row['account_role'] ?? '',
            $row['action'] === 'login_success' ? 'Success' : 'Failed',
            $row['details'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? '',
        ]);
    }
}

fclose($out);
