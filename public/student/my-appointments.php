<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Services/GoogleSyncService.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);

// Handle self-cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    if (Csrf::validate($_POST['csrf_token'] ?? null)) {
        $appt = Appointment::findById((int)$_POST['cancel_id']);
        if ($appt && (int)$appt['student_id'] === (int)$user['id'] && in_array($appt['status'], ['pending', 'approved'])) {
            $reason = trim($_POST['cancel_reason'] ?? '');
            Appointment::updateStatus((int)$appt['id'], STATUS_CANCELLED, $user['id'], 'Cancelled by student. Reason: ' . ($reason ?: 'not given'), $reason ?: null);
            // The student is the one cancelling, so it's the counselor who needs to be told —
            // NotificationService::statusChanged() always targets the student, so it's the
            // wrong tool for a student-initiated action.
            require_once __DIR__ . '/../../src/Models/Notification.php';
            Notification::create(
                (int)$appt['counselor_id'],
                "{$user['first_name']} {$user['last_name']} cancelled their appointment on {$appt['appointment_date']} at " . date('g:i A', strtotime($appt['appointment_time'])) . '.' . ($reason ? " Reason: {$reason}" : ''),
                (int)$appt['id']
            );
            GoogleSyncService::pushDelete($appt);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment cancelled.'];
        }
    }
    header('Location: ' . BASE_URL . '/student/my-appointments.php?tab=appointments');
    exit;
}

// Handle confirming or declining a counselor-proposed reschedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['confirm_reschedule_id']) || isset($_POST['decline_reschedule_id']))) {
    require_once __DIR__ . '/../../src/Models/Notification.php';
    if (Csrf::validate($_POST['csrf_token'] ?? null)) {
        try {
            if (isset($_POST['confirm_reschedule_id'])) {
                $updated = Appointment::confirmReschedule((int)$_POST['confirm_reschedule_id'], (int)$user['id']);
                Notification::create(
                    (int)$updated['counselor_id'],
                    "{$user['first_name']} {$user['last_name']} confirmed the new appointment time: {$updated['appointment_date']} at " . date('g:i A', strtotime($updated['appointment_time'])) . '.',
                    (int)$updated['id']
                );
                GoogleSyncService::pushUpdate($updated);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'New time confirmed.'];
            } else {
                $updated = Appointment::rejectReschedule((int)$_POST['decline_reschedule_id'], (int)$user['id']);
                Notification::create(
                    (int)$updated['counselor_id'],
                    "{$user['first_name']} {$user['last_name']} did not confirm the proposed new time — the appointment has been cancelled.",
                    (int)$updated['id']
                );
                GoogleSyncService::pushDelete($updated);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment cancelled.'];
            }
        } catch (RuntimeException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
        }
    }
    header('Location: ' . BASE_URL . '/student/my-appointments.php?tab=appointments');
    exit;
}

$tab = ($_GET['tab'] ?? 'appointments') === 'referrals' ? 'referrals' : 'appointments';

$appointments = $tab === 'appointments' ? Appointment::forStudent($user['id']) : [];
$sharedNotes = $tab === 'appointments' ? Appointment::sharedNotesForStudent($user['id']) : [];
$referrals = $tab === 'referrals' ? Referral::forStudent($user['id']) : [];

$statusLabels = [
    'pending' => 'Pending Review',
    'accepted' => 'Accepted',
    'cancelled' => 'Cancelled',
    'for_clarification' => 'For Clarification',
    'referred_back' => 'Referred Back',
];
$statusBadge = [
    'pending' => 'bg-warning text-dark',
    'accepted' => 'bg-success',
    'cancelled' => 'bg-danger',
    'for_clarification' => 'bg-info text-dark',
    'referred_back' => 'bg-secondary',
];

$pageTitle = 'My Appointments';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">My Appointments & Requests</h3>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'appointments' ? 'active' : '' ?>" href="?tab=appointments">Appointments</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'referrals' ? 'active' : '' ?>" href="?tab=referrals">My Requests</a>
  </li>
</ul>

<?php if ($tab === 'appointments'): ?>

  <div class="card">
    <div class="card-body">
      <?php if (!$appointments): ?>
        <p class="text-muted mb-0">No appointments scheduled yet. <a href="<?= BASE_URL ?>/student/book-appointment.php">Submit a request</a> and the Guidance Office will schedule one for you.</p>
      <?php else: ?>
        <p class="swipe-hint d-md-none">⟷ Swipe left/right to see more</p>
        <div class="table-responsive">
        <table class="table align-middle table-compact">
          <thead><tr><th>Date</th><th>Time</th><th>Counselor</th><th>Category</th><th>Type</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($appointments as $a): ?>
            <?php $notesForThis = $sharedNotes[$a['id']] ?? []; ?>
            <tr>
              <td><?= htmlspecialchars($a['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><?= htmlspecialchars($a['counselor_first'] . ' ' . $a['counselor_last']) ?></td>
              <td><?= htmlspecialchars($a['category_name'] ?? '—') ?></td>
              <td><?= ucfirst($a['type']) ?></td>
              <td>
                <?php if ($a['status'] === 'rescheduled'): ?>
                  <span class="badge bg-warning text-dark">Awaiting Your Confirmation</span>
                <?php else: ?>
                  <span class="badge badge-status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
                  <?php if (!empty($a['rescheduled_at'])): ?>
                    <span class="badge bg-warning text-dark" title="Your counselor moved this appointment on <?= date('M j, Y g:i A', strtotime($a['rescheduled_at'])) ?>">Rescheduled</span>
                  <?php endif; ?>
                  <?php if ($a['status'] === 'cancelled' && !empty($a['cancellation_reason'])): ?>
                    <div class="small text-muted mt-1">Reason: <?= htmlspecialchars($a['cancellation_reason']) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if (in_array($a['status'], ['pending', 'approved'])): ?>
                  <form method="post" onsubmit="return promptCancelReason(this);">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="cancel_id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="cancel_reason">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>
                  </form>
                <?php endif; ?>
                <?php if ($notesForThis): ?>
                  <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#notes-<?= $a['id'] ?>">
                    Counselor's Note<?= count($notesForThis) > 1 ? 's' : '' ?>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php if ($a['status'] === 'rescheduled'): ?>
              <tr>
                <td colspan="7" class="bg-warning-subtle">
                  <strong>Your counselor proposed a new time:</strong>
                  <?= htmlspecialchars($a['proposed_date']) ?> at <?= date('g:i A', strtotime($a['proposed_time'])) ?>.
                  Your original slot (<?= htmlspecialchars($a['appointment_date']) ?> at <?= date('g:i A', strtotime($a['appointment_time'])) ?>) no longer holds — please confirm or decline below.
                  If you don't confirm, this appointment will be cancelled.
                  <div class="mt-2 d-flex gap-2">
                    <form method="post" onsubmit="return confirm('Confirm this new appointment time?');">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="confirm_reschedule_id" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-success" type="submit">Confirm New Time</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Decline this new time? Your appointment will be cancelled.');">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="decline_reschedule_id" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">Decline & Cancel</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
            <?php if ($notesForThis): ?>
              <tr class="collapse" id="notes-<?= $a['id'] ?>">
                <td colspan="7" class="bg-light">
                  <?php foreach ($notesForThis as $n): ?>
                    <div class="border-bottom pb-2 mb-2">
                      <div class="small text-muted">
                        <?= htmlspecialchars($n['counselor_first'] . ' ' . $n['counselor_last']) ?>
                        — <?= date('M j, Y g:i A', strtotime($n['created_at'])) ?>
                      </div>
                      <div><?= nl2br(htmlspecialchars($n['notes'])) ?></div>
                    </div>
                  <?php endforeach; ?>
                </td>
              </tr>
            <?php endif; ?>
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
      <?php if (!$referrals): ?>
        <p class="text-muted mb-0">You haven't submitted any requests yet. <a href="<?= BASE_URL ?>/student/book-appointment.php">Submit one now</a>.</p>
      <?php else: ?>
        <p class="swipe-hint d-md-none">⟷ Swipe left/right to see more</p>
        <div class="table-responsive">
        <table class="table align-middle table-compact">
          <thead><tr><th>Ref No.</th><th>Submitted</th><th>Status</th><th>Assigned Counselor</th><th>Scheduled Appointment</th></tr></thead>
          <tbody>
          <?php foreach ($referrals as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['referral_no'] ?? '—') ?></td>
              <td><?= date('M j, Y', strtotime($r['submitted_at'])) ?></td>
              <td><span class="badge <?= $statusBadge[$r['status']] ?? 'bg-secondary' ?>"><?= $statusLabels[$r['status']] ?? ucfirst($r['status']) ?></span></td>
              <td><?= $r['counselor_first'] ? htmlspecialchars($r['counselor_first'] . ' ' . $r['counselor_last']) : '—' ?></td>
              <td>
                <?php if ($r['appointment_id']): ?>
                  <a href="?tab=appointments" class="btn btn-sm btn-outline-primary">View in My Appointments</a>
                <?php elseif ($r['status'] === 'cancelled'): ?>
                  <div class="d-flex flex-column gap-1">
                    <a href="<?= BASE_URL ?>/student/book-appointment.php?resubmit_from=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Pick New Time (Same Details)</a>
                    <a href="<?= BASE_URL ?>/student/book-appointment.php" class="btn btn-sm btn-outline-secondary">Submit New Referral</a>
                  </div>
                <?php else: ?>
                  <span class="text-muted small">Not yet scheduled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

<script>
  function promptCancelReason(form) {
    const reason = prompt('Reason for cancelling this appointment (your counselor will see this):');
    if (reason === null) return false; // user hit Cancel on the prompt itself
    if (!reason.trim()) {
      alert('Please provide a reason for the cancellation.');
      return false;
    }
    form.querySelector('input[name="cancel_reason"]').value = reason.trim();
    return true;
  }
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>