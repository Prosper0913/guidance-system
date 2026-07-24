<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Referral.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);
$appointments = Appointment::forStudent($user['id']);
$referrals = Referral::forStudent($user['id']);
$pendingReferrals = array_filter($referrals, fn($r) => $r['status'] === 'pending');

$upcoming = array_filter($appointments, fn($a) => in_array($a['status'], ['pending', 'approved']));
$pageTitle = 'Student Dashboard';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Welcome, <?= htmlspecialchars($user['first_name']) ?> 👋</h3>

<div class="row mb-4">
  <div class="col-md-3 mb-3">
    <div class="card"><div class="card-body">
      <h6 class="text-muted">Upcoming Appointments</h6>
      <h2><?= count($upcoming) ?></h2>
    </div></div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card"><div class="card-body">
      <h6 class="text-muted">Requests Awaiting Review</h6>
      <h2><?= count($pendingReferrals) ?></h2>
    </div></div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card"><div class="card-body">
      <h6 class="text-muted">Total Appointments</h6>
      <h2><?= count($appointments) ?></h2>
    </div></div>
  </div>
  <div class="col-md-3 mb-3">
    <div class="card"><div class="card-body">
      <a href="<?= BASE_URL ?>/student/book-appointment.php" class="btn btn-primary w-100 mt-2">+ Request Appointment</a>
    </div></div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Upcoming Appointments</div>
  <div class="card-body">
    <?php if (!$upcoming): ?>
      <p class="text-muted mb-0">No upcoming appointments yet. <a href="<?= BASE_URL ?>/student/book-appointment.php">Submit a request</a> and the Guidance Office will schedule one for you.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Date</th><th>Time</th><th>Counselor</th><th>Type</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $a): ?>
          <tr>
            <td><?= htmlspecialchars($a['appointment_date']) ?></td>
            <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
            <td><?= htmlspecialchars($a['counselor_first'] . ' ' . $a['counselor_last']) ?></td>
            <td><?= ucfirst($a['type']) ?></td>
            <td><span class="badge badge-status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($pendingReferrals): ?>
<div class="card">
  <div class="card-header">Requests Awaiting Guidance Office Review</div>
  <div class="card-body">
    <table class="table">
      <thead><tr><th>Ref No.</th><th>Submitted</th></tr></thead>
      <tbody>
      <?php foreach ($pendingReferrals as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['referral_no'] ?? '—') ?></td>
          <td><?= date('M j, Y', strtotime($r['submitted_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <a href="<?= BASE_URL ?>/student/my-appointments.php?tab=referrals" class="btn btn-sm btn-outline-primary">View All Requests</a>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>
