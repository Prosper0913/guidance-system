<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$pending = Appointment::forCounselor($user['id'], STATUS_PENDING);
$approved = Appointment::forCounselor($user['id'], STATUS_APPROVED);
$myReferrals = Referral::forCounselor($user['id']);
$pendingReferralsGlobal = Referral::countByStatus('pending');

$pageTitle = 'Counselor Dashboard';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Welcome, <?= htmlspecialchars($user['first_name']) ?> 👋</h3>

<div class="row mb-4">
  <div class="col-md-6 mb-3"><div class="card"><div class="card-body">
    <h6 class="text-muted">Pending Requests</h6><h2><?= count($pending) ?></h2>
  </div></div></div>
  <div class="col-md-6 mb-3"><div class="card"><div class="card-body">
    <h6 class="text-muted">Approved / Upcoming</h6><h2><?= count($approved) ?></h2>
  </div></div></div>
</div>

<div class="row mb-4">
  <div class="col-md-4 mb-3"><div class="card"><div class="card-body">
    <h6 class="text-muted">My Assigned Referrals</h6><h2><?= count($myReferrals) ?></h2>
  </div></div></div>
  <div class="col-md-4 mb-3"><div class="card"><div class="card-body">
    <h6 class="text-muted">Referrals Awaiting Triage (all)</h6><h2><?= $pendingReferralsGlobal ?></h2>
    <a href="appointments.php?tab=referrals" class="btn btn-sm btn-outline-primary mt-2">View Referrals</a>
  </div></div></div>
</div>

<div class="card mb-4">
  <div class="card-header">Pending Requests — Needs Your Action</div>
  <div class="card-body">
    <?php if (!$pending): ?>
      <p class="text-muted mb-0">No pending requests.</p>
    <?php else: ?>
      <table class="table" id="pendingTable">
        <thead><tr><th>Date</th><th>Time</th><th>Student</th><th>Type</th><th>Category</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $a): ?>
          <tr data-id="<?= $a['id'] ?>">
            <td><?= htmlspecialchars($a['appointment_date']) ?></td>
            <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
            <td>
              <?= htmlspecialchars($a['student_first'] . ' ' . $a['student_last']) ?>
              <?php if ((int)$a['other_pending_count'] > 0): ?>
                <br><span class="badge bg-warning text-dark" title="Other students are also pending for this exact time">⚠ +<?= (int)$a['other_pending_count'] ?> other request<?= $a['other_pending_count'] > 1 ? 's' : '' ?> for this slot</span>
              <?php endif; ?>
            </td>
            <td><?= ucfirst($a['type']) ?></td>
            <td><?= htmlspecialchars($a['category_name'] ?? '—') ?></td>
            <td>
              <button class="btn btn-sm btn-success" onclick="setStatus(<?= $a['id'] ?>,'approved')">Approve</button>
              <button class="btn btn-sm btn-danger" onclick="setStatus(<?= $a['id'] ?>,'declined')">Decline</button>
              <button class="btn btn-sm btn-outline-secondary" onclick="sendMessage(<?= $a['id'] ?>)">Message</button>
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
  const remarks = status === 'declined' ? prompt('Optional reason for declining:') || '' : '';
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
