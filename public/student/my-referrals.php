<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Referral.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);
$referrals = Referral::forStudent($user['id']);

$statusLabels = [
    'pending' => 'Pending Review',
    'accepted' => 'Accepted',
    'for_clarification' => 'For Clarification',
    'referred_back' => 'Referred Back',
];
$statusBadge = [
    'pending' => 'bg-warning text-dark',
    'accepted' => 'bg-success',
    'for_clarification' => 'bg-info text-dark',
    'referred_back' => 'bg-secondary',
];

$pageTitle = 'My Requests';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">My Guidance Requests</h3>
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
                <a href="my-appointments.php" class="btn btn-sm btn-outline-primary">View in My Appointments</a>
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
<?php include __DIR__ . '/../partials/footer.php'; ?>
