<?php
// Two or more students can prefer the exact same date/time in their referral. This page
// lets the counselor see everyone competing for that slot, pick who gets it, and — without
// anyone refilling the referral form — immediately give the rest a different time.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Services/ReferralService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);

$date = $_GET['date'] ?? '';
$time = $_GET['time'] ?? '';
if (!$date || !$time) {
    header('Location: ' . BASE_URL . '/counselor/appointments.php?tab=referrals');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    $referralId = (int)($_POST['referral_id'] ?? 0);
    $newDate = $_POST['new_date'] ?? $date;
    $newTime = $_POST['new_time'] ?? $time;
    $referral = Referral::findById($referralId);

    if (!$referral) {
        $errors[] = 'Referral not found.';
    } else {
        try {
            ReferralService::convertToAppointment($referral, $newDate, $newTime, $user['id']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Confirmed {$referral['student_name']} for {$newDate} at " . date('g:i A', strtotime($newTime)) . '.'];
            header('Location: ' . BASE_URL . '/counselor/resolve-conflict.php?date=' . urlencode($date) . '&time=' . urlencode($time));
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$group = Referral::findConflictGroup($date, $time);
// The counselor assigned to this slot's original request — used to check if it's already taken
// and to power the "pick a new time" slot loader for whoever's left.
$counselorId = null;
foreach ($group as $r) {
    if ($r['assigned_counselor_id']) { $counselorId = (int)$r['assigned_counselor_id']; break; }
}
$slotTaken = $counselorId ? Appointment::approvedSlotTaken($counselorId, $date, $time) : false;

$pageTitle = 'Resolve Scheduling Conflict';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">Resolve Scheduling Conflict</h3>
  <a href="appointments.php?tab=referrals" class="btn btn-sm btn-outline-secondary">Back to Referrals</a>
</div>

<p class="text-muted">
  <?= count($group) ?> student<?= count($group) === 1 ? '' : 's' ?> requested
  <strong><?= htmlspecialchars($date) ?> at <?= date('g:i A', strtotime($time)) ?></strong>.
  <?= $slotTaken ? 'That slot has already been confirmed for one student — pick a new time for the rest below.' : 'Pick which student gets this slot; everyone else will be notified automatically and can be given a new time right here.' ?>
</p>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if (!$group): ?>
  <div class="alert alert-secondary">No unresolved requests remain for this slot.</div>
<?php endif; ?>

<?php foreach ($group as $r):
    $isWinner = $slotTaken && (int)$slotTaken['student_id'] === (int)$r['student_id'];
?>
  <div class="card mb-3 <?= $isWinner ? 'border-success' : '' ?>">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h5 class="mb-1"><?= htmlspecialchars($r['student_name']) ?>
            <?= $r['urgency_level'] === 'urgent' ? '<span class="badge bg-danger ms-1">Urgent</span>' : '' ?>
            <?= $isWinner ? '<span class="badge bg-success ms-1">Confirmed for this slot</span>' : '' ?>
          </h5>
          <p class="text-muted small mb-1">Referral <?= htmlspecialchars($r['referral_no']) ?> · Submitted <?= date('M j, Y g:i A', strtotime($r['submitted_at'])) ?></p>
          <a href="referral-view.php?id=<?= $r['id'] ?>" class="small">View full referral</a>
        </div>
      </div>

      <?php if ($isWinner): ?>
        <div class="alert alert-success mt-3 mb-0 py-2 small">This student's appointment is confirmed for <?= htmlspecialchars($date) ?> at <?= date('g:i A', strtotime($time)) ?>.</div>

      <?php elseif (!$r['assigned_counselor_id']): ?>
        <div class="alert alert-secondary mt-3 mb-0 py-2 small">Not yet processed — <a href="referral-view.php?id=<?= $r['id'] ?>">assign a guidance advocate</a> first before scheduling.</div>

      <?php elseif (!$slotTaken): ?>
        <form method="post" class="mt-3">
          <?= Csrf::field() ?>
          <input type="hidden" name="referral_id" value="<?= $r['id'] ?>">
          <input type="hidden" name="new_date" value="<?= htmlspecialchars($date) ?>">
          <input type="hidden" name="new_time" value="<?= htmlspecialchars($time) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Confirm this student for <?= htmlspecialchars($date) ?> at <?= date('g:i A', strtotime($time)) ?></button>
        </form>

      <?php else: ?>
        <div class="mt-3 p-3 border rounded bg-light">
          <p class="small fw-semibold mb-2">This slot is taken — pick a new time for <?= htmlspecialchars($r['student_name']) ?>:</p>
          <form method="post" class="conflict-reschedule-form" onsubmit="return validateReschedule(this);">
            <?= Csrf::field() ?>
            <input type="hidden" name="referral_id" value="<?= $r['id'] ?>">
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label small mb-1">Date</label>
                <input type="date" class="form-control form-control-sm alt-date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($date) ?>" data-counselor="<?= (int)$r['assigned_counselor_id'] ?>" data-target="slots-<?= $r['id'] ?>">
              </div>
              <div class="col-md-8">
                <label class="form-label small mb-1">Available Time Slots</label>
                <div id="slots-<?= $r['id'] ?>" class="p-2 border rounded small bg-white">Pick a date.</div>
                <input type="hidden" name="new_time" class="alt-time">
                <input type="hidden" name="new_date" class="alt-date-hidden">
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-2">Schedule This New Time</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
document.querySelectorAll('.alt-date').forEach(function (input) {
  const targetId = input.dataset.target;
  const counselorId = input.dataset.counselor;
  const form = input.closest('form');
  const timeInput = form.querySelector('.alt-time');
  const dateHidden = form.querySelector('.alt-date-hidden');

  function reload() {
    dateHidden.value = input.value;
    loadAvailableSlots(counselorId, input.value, targetId, timeInput.id || (timeInput.id = targetId + '-time'), null);
  }
  input.addEventListener('change', reload);
  if (input.value) reload();
});

function validateReschedule(form) {
  const time = form.querySelector('.alt-time').value;
  if (!time) {
    alert('Please select a time slot first.');
    return false;
  }
  return true;
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
