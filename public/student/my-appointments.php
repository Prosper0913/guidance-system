<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
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
    header('Location: ' . BASE_URL . '/student/my-appointments.php');
    exit;
}

$appointments = Appointment::forStudent($user['id']);
$pageTitle = 'My Appointments';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">My Appointments</h3>
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
<?php include __DIR__ . '/../partials/footer.php'; ?>
