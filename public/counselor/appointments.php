<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$filter = $_GET['status'] ?? '';
$appointments = Appointment::forCounselor($user['id'], $filter ?: null);

$pageTitle = 'My Appointments';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Appointments</h3>

<div class="mb-3">
  <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?">All</a>
  <?php foreach (['pending','approved','completed','declined','cancelled','no-show'] as $s): ?>
    <a class="btn btn-sm <?= $filter === $s ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= $s ?>"><?= ucfirst($s) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$appointments): ?>
      <p class="text-muted mb-0">No appointments found.</p>
    <?php else: ?>
      <table class="table align-middle" id="apptTable">
        <thead><tr><th>Date</th><th>Time</th><th>Student</th><th>Type</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($appointments as $a): ?>
          <tr data-id="<?= $a['id'] ?>">
            <td><?= htmlspecialchars($a['appointment_date']) ?></td>
            <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
            <td><?= htmlspecialchars($a['student_first'] . ' ' . $a['student_last']) ?></td>
            <td><?= ucfirst($a['type']) ?></td>
            <td><?= htmlspecialchars($a['category_name'] ?? '—') ?><?= $a['is_confidential'] ? ' 🔒' : '' ?></td>
            <td>
              <span class="badge badge-status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
              <?php if ($a['status'] === 'pending' && (int)$a['other_pending_count'] > 0): ?>
                <span class="badge bg-warning text-dark ms-1" title="Other students are also pending for this exact time">⚠ +<?= (int)$a['other_pending_count'] ?> other request<?= $a['other_pending_count'] > 1 ? 's' : '' ?> for this slot</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($a['status'] === 'pending'): ?>
                <button class="btn btn-sm btn-success" onclick="setStatus(<?= $a['id'] ?>,'approved')">Approve</button>
                <button class="btn btn-sm btn-danger" onclick="setStatus(<?= $a['id'] ?>,'declined')">Decline</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="sendMessage(<?= $a['id'] ?>)">Message</button>
              <?php elseif ($a['status'] === 'approved'): ?>
                <button class="btn btn-sm btn-primary" onclick="setStatus(<?= $a['id'] ?>,'completed')">Mark Completed</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="setStatus(<?= $a['id'] ?>,'no-show')">No-show</button>
                <button class="btn btn-sm btn-outline-danger" onclick="setStatus(<?= $a['id'] ?>,'cancelled')">Cancel</button>
              <?php else: ?>
                <a href="session-notes.php?appointment_id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-dark">Notes</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
const CSRF = '<?= Csrf::token() ?>';
function setStatus(id, status) {
  const remarks = (status === 'declined' || status === 'cancelled') ? (prompt('Optional reason:') || '') : '';
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('appointment_id', id);
  fd.append('status', status);
  fd.append('remarks', remarks);
  fetch(window.BASE_URL + '/api/update-status.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        if (data.declined_count > 0) {
          alert(`Approved. ${data.declined_count} other pending request(s) for this same time slot were automatically declined and notified.`);
        }
        location.reload();
      } else {
        alert(data.message || 'Unable to update.');
      }
    });
}

function sendMessage(id) {
  const message = prompt('Message to send to this student:');
  if (!message) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('appointment_id', id);
  fd.append('message', message);
  fetch(window.BASE_URL + '/api/send-message.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => { alert(data.success ? 'Message sent.' : (data.message || 'Unable to send message.')); });
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
