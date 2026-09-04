<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Services/ReportService.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);

$statusSummary = ReportService::statusSummary();
$categoryBreakdown = ReportService::concernCategoryBreakdown();
$attendance = ReportService::attendanceRate();
$caseload = ReportService::counselorCaseload();
$busiest = ReportService::busiestSlots();

$pageTitle = 'Reports';
include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-4">Reports & Analytics</h3>
<p class="text-muted">Use your browser's Print (Ctrl/Cmd+P) to export this page as a PDF.</p>

<div class="row">
  <div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Appointments by Status</div><div class="card-body">
      <div class="table-responsive">
      <table class="table table-sm"><tbody>
      <?php foreach ($statusSummary as $r): ?>
        <tr><td><?= ucfirst($r['status']) ?></td><td class="text-end"><?= $r['total'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      </div>
    </div></div>
  </div>

  <!--<div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Concern Category Breakdown</div><div class="card-body">
      <table class="table table-sm"><tbody>
      <?php foreach ($categoryBreakdown as $r): ?>
        <tr><td><?= htmlspecialchars($r['name']) ?></td><td class="text-end"><?= $r['total'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div></div>
  </div>

  <div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Attendance (logged sessions)</div><div class="card-body">
      <table class="table table-sm"><tbody>
      <?php foreach ($attendance as $r): ?>
        <tr><td><?= ucfirst($r['status']) ?></td><td class="text-end"><?= $r['total'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$attendance): ?><tr><td class="text-muted">No attendance logs yet.</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>-->

  <div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Counselor Caseload</div><div class="card-body">
      <div class="table-responsive">
      <table class="table table-sm"><tbody>
      <?php foreach ($caseload as $r): ?>
        <tr><td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td><td class="text-end"><?= $r['total_appointments'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      </div>
    </div></div>
  </div>

  <div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Busiest Time Slots</div><div class="card-body">
      <div class="table-responsive">
      <table class="table table-sm"><tbody>
      <?php foreach ($busiest as $r): ?>
        <tr><td><?= date('g:i A', strtotime($r['appointment_time'])) ?></td><td class="text-end"><?= $r['total'] ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      </div>
    </div></div>
  </div>

  <!--<div class="col-md-6 mb-4">
    <div class="card"><div class="card-header">Special Needs — Condition Types</div><div class="card-body">
      <table class="table table-sm"><tbody>
      <?php foreach ($specialNeeds as $r): ?>
        <tr><td><?= htmlspecialchars($r['condition_type']) ?></td><td class="text-end"><?= $r['total'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$specialNeeds): ?><tr><td class="text-muted">No records yet.</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>
</div>-->
<?php include __DIR__ . '/../partials/footer.php'; ?>