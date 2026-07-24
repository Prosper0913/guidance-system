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
        Availability::addException($user['id'], $_POST['exception_date'], false, Validator::clean($_POST['reason'] ?? ''));
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Date blocked.'];
    }
    header('Location: ' . BASE_URL . '/counselor/availability.php');
    exit;
}

$weekly = Availability::getWeekly($user['id']);
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
      <div class="card-header">Weekly Schedule</div>
      <div class="card-body">
        <table class="table table-sm">
          <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Slot (min)</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($weekly as $w): ?>
            <tr>
              <td><?= $days[$w['day_of_week']] ?></td>
              <td><?= date('g:i A', strtotime($w['start_time'])) ?></td>
              <td><?= date('g:i A', strtotime($w['end_time'])) ?></td>
              <td><?= $w['slot_minutes'] ?></td>
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
      <div class="card-header">Block a Specific Date</div>
      <div class="card-body">
        <p class="text-muted small">Use this for leave, holidays, or seminars — the whole day becomes unavailable for booking.</p>
        <form method="post">
          <?= Csrf::field() ?>
          <div class="mb-2">
            <label class="form-label">Date</label>
            <input type="date" name="exception_date" class="form-control" required min="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Reason (optional)</label>
            <input type="text" name="reason" class="form-control">
          </div>
          <button type="submit" name="add_exception" class="btn btn-primary btn-sm">Block Date</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
