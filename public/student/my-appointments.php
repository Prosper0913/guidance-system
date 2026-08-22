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
            Appointment::updateStatus((int)$appt['id'], STATUS_CANCELLED, $user['id'], 'Cancelled by student');
            require_once __DIR__ . '/../../src/Services/NotificationService.php';
            NotificationService::statusChanged($appt, STATUS_CANCELLED);
            GoogleSyncService::pushDelete($appt);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment cancelled.'];
        }
    }
    header('Location: ' . BASE_URL . '/student/my-appointments.php?tab=appointments');
    exit;
}

$tab = ($_GET['tab'] ?? 'appointments') === 'referrals' ? 'referrals' : 'appointments';

$appointments = $tab === 'appointments' ? Appointment::forStudent($user['id']) : [];
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
        <table class="table align-middle">
          <thead><tr><th>Date</th><th>Time</th><th>Counselor</th><th>Category</th><th>Type</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['appointment_date']) ?></td>
              <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
              <td><?= htmlspecialchars($a['counselor_first'] . ' ' . $a['counselor_last']) ?></td>
              <td><?= htmlspecialchars($a['category_name'] ?? '—') ?></td>
              <td><?= ucfirst($a['type']) ?></td>
              <td><span class="badge badge-status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
              <td>
                <?php if (in_array($a['status'], ['pending', 'approved'])): ?>
                  <form method="post" onsubmit="return confirm('Cancel this appointment?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="cancel_id" value="<?= $a['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <div class="card-body">
      <?php if (!$referrals): ?>
        <p class="text-muted mb-0">You haven't submitted any requests yet. <a href="<?= BASE_URL ?>/student/book-appointment.php">Submit one now</a>.</p>
      <?php else: ?>
        <table class="table align-middle">
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
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>