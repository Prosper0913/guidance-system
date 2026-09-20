<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Models/AuditLog.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$db = Database::getConnection();

$tab = ($_GET['tab'] ?? 'logins') === 'appointments' ? 'appointments' : 'logins';

$logs = [];
$loginLogs = [];
if ($tab === 'appointments') {
    // Appointment status-change history
    $stmt = $db->query(
        "SELECT al.*, u.first_name, u.last_name, a.appointment_date, a.appointment_time
         FROM appointment_logs al
         JOIN users u ON u.id = al.changed_by
         JOIN appointments a ON a.id = al.appointment_id
         ORDER BY al.changed_at DESC LIMIT 200"
    );
    $logs = $stmt->fetchAll();
} else {
    $loginLogs = AuditLog::recent(300, true);
}

$pageTitle = 'Audit Logs';
include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-1">Audit Logs</h3>
<p class="text-muted">Security and activity trail for the system.</p>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" href="?tab=logins">Login Activity</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'appointments' ? 'active' : '' ?>" href="?tab=appointments">Appointment Status Changes</a></li>
</ul>

<div class="mb-3">
  <a class="btn btn-sm btn-outline-primary" href="export-audit-logs.php?tab=<?= $tab ?>">⬇ Export <?= $tab === 'logins' ? 'Login Activity' : 'Appointment Logs' ?> (CSV)</a>
</div>

<?php if ($tab === 'logins'): ?>
<div class="card">
  <div class="card-body">
    <p class="text-muted small">Every login attempt — successful or failed — with the account, role, and IP address it came from. Showing the most recent 300.</p>
    <?php if (!$loginLogs): ?>
      <p class="text-muted mb-0">No login activity recorded yet.</p>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>When</th><th>Account</th><th>Role</th><th>Result</th><th>IP Address</th><th>Device / Browser</th></tr></thead>
        <tbody>
        <?php foreach ($loginLogs as $l): ?>
          <tr>
            <td class="text-nowrap"><?= date('M j, Y g:i A', strtotime($l['created_at'])) ?></td>
            <td>
              <?php if ($l['first_name']): ?>
                <?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?>
              <?php else: ?>
                <span class="text-muted">Unknown / unregistered email</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($l['account_role'] ?? '—') ?></td>
            <td>
              <?php if ($l['action'] === 'login_success'): ?>
                <span class="badge bg-success">Success</span>
              <?php else: ?>
                <span class="badge bg-danger">Failed</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
            <td class="small text-muted" style="max-width:280px;"><?= htmlspecialchars($l['user_agent'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-body">
    <?php if (!$logs): ?>
      <p class="text-muted mb-0">No log entries yet.</p>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>When</th><th>Appointment</th><th>Changed By</th><th>Old Status</th><th>New Status</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= date('M j, Y g:i A', strtotime($l['changed_at'])) ?></td>
            <td><?= htmlspecialchars($l['appointment_date'] . ' ' . date('g:i A', strtotime($l['appointment_time']))) ?></td>
            <td><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></td>
            <td><?= htmlspecialchars($l['old_status'] ?? '—') ?></td>
            <td><?= htmlspecialchars($l['new_status']) ?></td>
            <td><?= htmlspecialchars($l['remarks'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
