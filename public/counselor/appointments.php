<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);

$tab = ($_GET['tab'] ?? 'appointments') === 'referrals' ? 'referrals' : 'appointments';
$filter = $_GET['status'] ?? '';

$appointments = [];
if ($tab === 'appointments' && $user['role'] === ROLE_COUNSELOR) {
    $appointments = Appointment::forCounselor($user['id'], $filter ?: null);
}

$referrals = [];
if ($tab === 'referrals') {
    if ($user['role'] === ROLE_ADMIN) {
        // Admins see all referrals across all levels
        $referrals = Referral::withConflictCounts(Referral::all($filter ?: null));
    } else {
        // Counselors only see referrals assigned to them (auto-determined by education level)
        $referrals = Referral::withConflictCounts(Referral::forCounselor($user['id']));
    }
}

$statusLabels = [
    'pending' => 'Pending Review',
    'accepted' => 'Accepted',
    'cancelled' => 'Cancelled',
    'for_clarification' => 'For Clarification',
    'referred_back' => 'Referred Back',
];

$pageTitle = 'Appointments & Referrals';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Appointments & Referrals</h3>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'appointments' ? 'active' : '' ?>" href="?tab=appointments">Appointments</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'referrals' ? 'active' : '' ?>" href="?tab=referrals">Referrals</a>
  </li>
</ul>

<?php if ($tab === 'appointments'): ?>

  <?php if ($user['role'] !== ROLE_COUNSELOR): ?>
    <p class="text-muted">Appointments are managed per-counselor. Switch to the Referrals tab to triage incoming requests.</p>
  <?php else: ?>
    <div class="mb-3">
      <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=appointments">All</a>
      <?php foreach (['pending','approved','completed','declined','cancelled','no-show'] as $s): ?>
        <a class="btn btn-sm <?= $filter === $s ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=appointments&status=<?= $s ?>"><?= ucfirst($s) ?></a>
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
                    <button class="btn btn-sm btn-outline-dark" onclick="openRescheduleModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['appointment_date']) ?>')">Reschedule</button>
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
  <?php endif; ?>

<?php else: ?>

  <div class="mb-3">
    <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=referrals">All</a>
    <?php foreach ($statusLabels as $key => $label): ?>
      <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=referrals&status=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-body">
      <?php if (!$referrals): ?>
        <p class="text-muted mb-0">No referrals found.</p>
      <?php else: ?>
        <table class="table align-middle">
          <thead><tr><th>Ref No.</th><th>Date</th><th>Student</th><th>Referred By</th><th>Urgency</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($referrals as $r): ?>
            <tr class="<?= $r['urgency_level'] === 'urgent' ? 'table-danger' : '' ?>">
              <td><?= htmlspecialchars($r['referral_no'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['referral_date']) ?></td>
              <td><?= htmlspecialchars($r['student_name']) ?><?= $r['student_id'] ? ' <span class="badge bg-success">Linked</span>' : ' <span class="badge bg-secondary">Unlinked</span>' ?></td>
              <td><?= htmlspecialchars($r['referring_party_name']) ?></td>
              <td><?= $r['urgency_level'] === 'urgent' ? '<span class="badge bg-danger">Urgent</span>' : '<span class="badge bg-secondary">Routine</span>' ?></td>
              <?php
                $badgeClass = [
                    'pending' => 'bg-warning text-dark',
                    'accepted' => 'bg-success',
                    'cancelled' => 'bg-danger',
                    'for_clarification' => 'bg-info text-dark',
                    'referred_back' => 'bg-secondary',
                ][$r['status']] ?? 'bg-info text-dark';
              ?>
              <td><span class="badge <?= $badgeClass ?>"><?= $statusLabels[$r['status']] ?? ucfirst($r['status']) ?></span>
                <?php if (($r['conflicting_preference_count'] ?? 0) > 0): ?>
                  <span class="badge bg-warning text-dark ms-1" title="Other students prefer this exact date/time">⚠ +<?= $r['conflicting_preference_count'] ?> same-slot request<?= $r['conflicting_preference_count'] > 1 ? 's' : '' ?></span>
                  <a href="resolve-conflict.php?date=<?= urlencode($r['preferred_date']) ?>&time=<?= urlencode($r['preferred_time']) ?>" class="btn btn-sm btn-outline-warning ms-1 py-0">Resolve</a>
                <?php endif; ?>
              </td>
              <td><?= $r['counselor_first'] ? htmlspecialchars($r['counselor_first'] . ' ' . $r['counselor_last']) : '—' ?></td>
              <td><a href="referral-view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<!-- Reschedule modal: shared across rows, populated per-appointment when opened. -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reschedule Appointment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">New Date</label>
          <input type="date" id="rescheduleDate" class="form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Available Time Slots</label>
          <div id="rescheduleSlots" class="p-2 border rounded small">Pick a date first.</div>
          <input type="hidden" id="rescheduleTime">
        </div>
        <div id="rescheduleError" class="text-danger small"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmRescheduleBtn">Confirm New Time</button>
      </div>
    </div>
  </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
const CSRF = '<?= Csrf::token() ?>';
const CURRENT_COUNSELOR_ID = <?= (int)$user['id'] ?>;
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

let rescheduleTargetId = null;
const rescheduleDateInput = document.getElementById('rescheduleDate');
const rescheduleModalEl = document.getElementById('rescheduleModal');

function openRescheduleModal(id, currentDate) {
  rescheduleTargetId = id;
  document.getElementById('rescheduleError').textContent = '';
  document.getElementById('rescheduleSlots').innerHTML = 'Pick a date first.';
  document.getElementById('rescheduleTime').value = '';
  rescheduleDateInput.value = currentDate || '';
  bootstrap.Modal.getOrCreateInstance(rescheduleModalEl).show();
  if (currentDate) {
    loadAvailableSlots(CURRENT_COUNSELOR_ID, currentDate, 'rescheduleSlots', 'rescheduleTime');
  }
}

if (rescheduleDateInput) {
  rescheduleDateInput.addEventListener('change', function () {
    loadAvailableSlots(CURRENT_COUNSELOR_ID, this.value, 'rescheduleSlots', 'rescheduleTime');
  });
}

const confirmRescheduleBtn = document.getElementById('confirmRescheduleBtn');
if (confirmRescheduleBtn) {
  confirmRescheduleBtn.addEventListener('click', function () {
    const newTime = document.getElementById('rescheduleTime').value;
    const errorEl = document.getElementById('rescheduleError');
    if (!newTime) {
      errorEl.textContent = 'Please select a time slot.';
      return;
    }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('appointment_id', rescheduleTargetId);
    fd.append('new_date', rescheduleDateInput.value);
    fd.append('new_time', newTime);
    fetch(window.BASE_URL + '/api/reschedule-appointment.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          location.reload();
        } else {
          errorEl.textContent = data.message || 'Unable to reschedule.';
        }
      })
      .catch(() => { errorEl.textContent = 'Something went wrong. Please try again.'; });
  });
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>