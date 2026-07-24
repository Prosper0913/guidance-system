<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$db = Database::getConnection();

// Show appointment status-change history (acts as the primary audit trail for this system)
$stmt = $db->query(
    "SELECT al.*, u.first_name, u.last_name, a.appointment_date, a.appointment_time
     FROM appointment_logs al
     JOIN users u ON u.id = al.changed_by
     JOIN appointments a ON a.id = al.appointment_id
     ORDER BY al.changed_at DESC LIMIT 200"
);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-4">Audit Logs — Appointment Status Changes</h3>
<div class="card">
  <div class="card-body">
    <?php if (!$logs): ?>
      <p class="text-muted mb-0">No log entries yet.</p>
    <?php else: ?>
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
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
