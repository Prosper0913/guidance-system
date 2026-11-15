<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Availability.php';
require_once __DIR__ . '/../../src/Models/GoogleToken.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    if (isset($_POST['add_weekly'])) {
        Availability::addWeekly(
            $user['id'],
            (int)$_POST['day_of_week'],
            $_POST['start_time'],
            $_POST['end_time'],
            (int)($_POST['slot_minutes'] ?: 30)
        );
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Weekly availability added.'];
    } elseif (isset($_POST['remove_weekly'])) {
        Availability::removeWeekly((int)$_POST['id'], $user['id']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Removed.'];
    } elseif (isset($_POST['add_exception'])) {
        // "From" is exception_date; "To" is optional and defaults to the same day (single-day block).
        $from = $_POST['exception_date'] ?? '';
        $to = ($_POST['exception_end'] ?? '') !== '' ? $_POST['exception_end'] : $from;
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255); // plain text; escaped on output
        $isDate = fn($v) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) && strtotime($v) !== false;

        if (!$isDate($from) || !$isDate($to)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Please pick a valid date.'];
        } elseif ($from < date('Y-m-d')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You can only block today or future dates.'];
        } elseif ($to < $from) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'The end date can\'t be before the start date.'];
        } elseif ((strtotime($to) - strtotime($from)) / 86400 > 365) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'A block can cover at most one year. Please use a shorter range.'];
        } else {
            $added = Availability::addExceptionRange($user['id'], $from, $to, $reason);
            if ($added === 0) {
                $_SESSION['flash'] = ['type' => 'info', 'message' => 'Nothing to block — those dates are weekends or already blocked.'];
            } else {
                $msg = $added === 1 ? '1 date blocked.' : "{$added} dates blocked.";
                $booked = Availability::countActiveAppointmentsInRange($user['id'], $from, $to);
                if ($booked > 0) {
                    $msg .= " Note: you still have {$booked} appointment(s) in this range — blocking dates doesn't cancel them, so reschedule or cancel them from Appointments.";
                }
                $_SESSION['flash'] = ['type' => $booked > 0 ? 'warning' : 'success', 'message' => $msg];
            }
        }
    } elseif (isset($_POST['remove_block'])) {
        $from = $_POST['block_from'] ?? '';
        $to = $_POST['block_to'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to) {
            Availability::removeExceptionRange($user['id'], $from, $to);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Blocked date(s) removed.'];
        }
    }
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}

$weekly = Availability::getWeekly($user['id']);

// Group upcoming blocked dates into runs: consecutive weekdays with the same reason show as
// one range (e.g. Oct 12 – Oct 16) so a range block can be reviewed and removed in one click.
$blockedRuns = [];
foreach (Availability::getUpcomingBlocked($user['id']) as $b) {
    $last = count($blockedRuns) - 1;
    if ($last >= 0
        && $blockedRuns[$last]['reason'] === (string)$b['reason']
        && date('Y-m-d', strtotime($blockedRuns[$last]['to'] . ' +1 weekday')) === $b['exception_date']) {
        $blockedRuns[$last]['to'] = $b['exception_date'];
    } else {
        $blockedRuns[] = ['from' => $b['exception_date'], 'to' => $b['exception_date'], 'reason' => (string)$b['reason']];
    }
}
$googleConnected = GoogleToken::isConnected($user['id']);
$pageTitle = 'My Availability';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">My Availability</h3>

<div class="card mb-4">
  <div class="card-header">Google Calendar Sync</div>
  <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
    <?php if ($googleConnected): ?>
      <div>
        <span class="badge bg-success mb-1">Connected</span>
        <p class="mb-0 text-muted small">Your Google Calendar busy times are excluded from booking, and approved appointments sync automatically.</p>
      </div>
      <form method="post" action="google-disconnect.php" onsubmit="return confirm('Disconnect Google Calendar? Busy-time checking and event sync will stop.');">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-outline-danger btn-sm">Disconnect</button>
      </form>
    <?php else: ?>
      <div>
        <span class="badge bg-secondary mb-1">Not Connected</span>
        <p class="mb-0 text-muted small">Connect your Google Calendar so existing personal events block booking, and approved appointments appear on your calendar.</p>
      </div>
      <a href="google-connect.php" class="btn btn-primary btn-sm">Connect Google Calendar</a>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-md-6 mb-4">
    <div class="card">
      <div class="card-header">Weekly Schedule <small style="color: var(--brand-green-light);">(12:00–1:00 PM lunch break is automatically excluded)</small></div>
      <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm">
          <thead><tr><th>Day</th><th>Start</th><th>End</th><th>&emsp;&emsp;&emsp;Minutes per session</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($weekly as $w): ?>
            <tr>
              <td><?= $days[$w['day_of_week']] ?></td>
              <td><?= date('g:i A', strtotime($w['start_time'])) ?></td>
              <td><?= date('g:i A', strtotime($w['end_time'])) ?></td>
              <td style="text-align: center;"><?= $w['slot_minutes'] ?></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this schedule block?');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="id" value="<?= $w['id'] ?>">
                  <button type="submit" name="remove_weekly" class="btn btn-sm btn-outline-danger">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <hr>
        <form method="post">
          <?= Csrf::field() ?>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label">Day</label>
              <select name="day_of_week" class="form-select form-select-sm" required>
                <?php foreach ($days as $i => $d): ?><option value="<?= $i ?>"><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-3"><label class="form-label">Start</label><input type="time" name="start_time" class="form-control form-control-sm" required></div>
            <div class="col-3"><label class="form-label">End</label><input type="time" name="end_time" class="form-control form-control-sm" required></div>
            <div class="col-2"><label class="form-label">Slot</label><input type="number" name="slot_minutes" class="form-control form-control-sm" value="30" min="10"></div>
          </div>
          <button type="submit" name="add_weekly" class="btn btn-primary btn-sm mt-2">Add Block</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6 mb-4">
    <div class="card">
      <div class="card-header">Block Date(s)</div>
      <div class="card-body">
        <p class="text-muted small">Use this for leave, holidays, or seminars — whole days become unavailable for booking. Pick one day, or set an end date to block a range (e.g. Oct 11 – Oct 15). Saturdays and Sundays are skipped since the office is already closed.</p>
        <form method="post">
          <?= Csrf::field() ?>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">From</label>
              <input type="date" name="exception_date" id="blockFrom" class="form-control" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label">To <span class="text-muted small">(optional)</span></label>
              <input type="date" name="exception_end" id="blockTo" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Reason (optional)</label>
            <input type="text" name="reason" class="form-control">
          </div>
          <button type="submit" name="add_exception" class="btn btn-primary btn-sm">Block Date(s)</button>
        </form>

        <?php if ($blockedRuns): ?>
          <hr>
          <div class="fw-semibold small mb-2">Upcoming blocked dates</div>
          <ul class="list-group list-group-flush">
            <?php foreach ($blockedRuns as $run): ?>
              <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                <div class="small">
                  <?php if ($run['from'] === $run['to']): ?>
                    <?= date('M j, Y', strtotime($run['from'])) ?>
                  <?php else: ?>
                    <?= date('M j, Y', strtotime($run['from'])) ?> – <?= date('M j, Y', strtotime($run['to'])) ?>
                  <?php endif; ?>
                  <?php if ($run['reason'] !== ''): ?>
                    <div class="text-muted"><?= htmlspecialchars(html_entity_decode($run['reason'], ENT_QUOTES, 'UTF-8')) ?></div>
                  <?php endif; ?>
                </div>
                <form method="post" onsubmit="return confirm('Remove this block? These dates will open up for booking again.');">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="block_from" value="<?= htmlspecialchars($run['from']) ?>">
                  <input type="hidden" name="block_to" value="<?= htmlspecialchars($run['to']) ?>">
                  <button type="submit" name="remove_block" class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const from = document.getElementById('blockFrom');
  const to = document.getElementById('blockTo');
  if (!from || !to) return;
  from.addEventListener('change', function () {
    to.min = from.value || to.min;
    if (to.value && to.value < from.value) to.value = '';
  });
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>