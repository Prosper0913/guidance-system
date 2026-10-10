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

// Guidance Office locator card (map + route preview; click opens the walkthrough)
$walk = json_decode(file_get_contents(__DIR__ . '/../assets/guidance-walk/route.json'), true);
$routePoints = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $walk['route']));
$routeStart = $walk['route'][0];
$routeEnd = end($walk['route']);
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
  <div class="card-header">Where is the Guidance Office?</div>
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-md-4 col-sm-5">
        <a href="<?= BASE_URL ?>/student/locate-guidance.php" class="d-block position-relative text-decoration-none" title="Open the walkthrough" style="line-height:0;">
          <img src="<?= BASE_URL ?>/assets/guidance-walk/<?= htmlspecialchars($walk['map']) ?>" alt="Campus map with route to the Guidance Office" class="img-fluid rounded border">
          <svg viewBox="0 0 <?= (int)$walk['mapW'] ?> <?= (int)$walk['mapH'] ?>" style="position:absolute;inset:0;width:100%;height:100%;">
            <polyline points="<?= $routePoints ?>" fill="none" stroke="#000" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" opacity=".55"/>
            <polyline points="<?= $routePoints ?>" fill="none" stroke="#ffd400" stroke-width="3.6" stroke-linejoin="round" stroke-linecap="round"/>
            <circle cx="<?= $routeStart[0] ?>" cy="<?= $routeStart[1] ?>" r="8" fill="#1b1b1b" stroke="#fff" stroke-width="2"/>
            <circle cx="<?= $routeEnd[0] ?>" cy="<?= $routeEnd[1] ?>" r="10" fill="#2f6b3c" stroke="#fff" stroke-width="2.5"/>
          </svg>
        </a>
      </div>
      <div class="col-md-8 col-sm-7">
        <p class="mb-2">Not sure how to get to the Guidance Office? Follow the yellow route from the school entrance (dark dot) to the office (green dot).</p>
        <p class="text-muted small mb-3">Tap the map to take a photo walkthrough of the whole way, step by step.</p>
        <a href="<?= BASE_URL ?>/student/locate-guidance.php" class="btn btn-primary">Start walkthrough</a>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Upcoming Appointments</div>
  <div class="card-body">
    <?php if (!$upcoming): ?>
      <p class="text-muted mb-0">No upcoming appointments yet. <a href="<?= BASE_URL ?>/student/book-appointment.php">Submit a request</a> and the Guidance Office will schedule one for you.</p>
    <?php else: ?>
      <div class="table-responsive">
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
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($pendingReferrals): ?>
<div class="card">
  <div class="card-header">Requests Awaiting Guidance Office Review</div>
  <div class="card-body">
    <div class="table-responsive">
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
    </div>
    <a href="<?= BASE_URL ?>/student/my-appointments.php?tab=referrals" class="btn btn-sm btn-outline-primary">View All Requests</a>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../partials/footer.php'; ?>