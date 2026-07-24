<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Models/Referral.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$db = Database::getConnection();

$totalStudents = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$totalCounselors = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='counselor'")->fetchColumn();
$totalAppointments = (int)$db->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetchColumn();
$pendingReferrals = Referral::countByStatus('pending');
$urgentReferrals = (int)$db->query("SELECT COUNT(*) FROM referrals WHERE urgency_level='urgent' AND status='pending'")->fetchColumn();

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Admin Dashboard</h3>
<div class="row">
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Students</h6><h2><?= $totalStudents ?></h2></div></div></div>
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Counselors</h6><h2><?= $totalCounselors ?></h2></div></div></div>
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Total Appointments</h6><h2><?= $totalAppointments ?></h2></div></div></div>
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Pending Requests</h6><h2><?= $pendingCount ?></h2></div></div></div>
</div>
<div class="row">
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Pending Referrals</h6><h2><?= $pendingReferrals ?></h2></div></div></div>
  <div class="col-md-3 mb-3"><div class="card"><div class="card-body"><h6 class="text-muted">Urgent Referrals</h6><h2 class="<?= $urgentReferrals > 0 ? 'text-danger' : '' ?>"><?= $urgentReferrals ?></h2></div></div></div>
</div>
<div class="row mt-2">
  <div class="col-md-3 mb-3"><a href="../counselor/appointments.php?tab=referrals" class="btn btn-primary w-100 py-3">Referrals</a></div>
  <div class="col-md-4 mb-3"><a href="manage-users.php" class="btn btn-primary w-100 py-3">Manage Users</a></div>
  <div class="col-md-4 mb-3"><a href="reports.php" class="btn btn-primary w-100 py-3">View Reports</a></div>
  <div class="col-md-4 mb-3"><a href="audit-logs.php" class="btn btn-primary w-100 py-3">Audit Logs</a></div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
