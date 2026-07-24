<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/SpecialNeeds.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    if (isset($_POST['add_record'])) {
        SpecialNeeds::create([
            'student_id' => (int)$_POST['student_id'],
            'assigned_counselor_id' => $user['id'],
            'condition_type' => Validator::clean($_POST['condition_type']),
            'accommodations' => Validator::clean($_POST['accommodations'] ?? ''),
            'monitoring_frequency' => Validator::clean($_POST['monitoring_frequency'] ?? ''),
            'last_check_in' => date('Y-m-d'),
            'next_check_in' => $_POST['next_check_in'] ?: date('Y-m-d', strtotime('+1 month')),
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Student added to monitoring list.'];
    } elseif (isset($_POST['log_checkin'])) {
        SpecialNeeds::updateCheckIn((int)$_POST['record_id'], date('Y-m-d'), $_POST['next_check_in']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Check-in logged.'];
    }
    header('Location: ' . BASE_URL . '/counselor/special-needs.php');
    exit;
}

$records = SpecialNeeds::forCounselor($user['id']);

// Students this counselor has interacted with (for the dropdown)
$stmt = $db->prepare(
    "SELECT DISTINCT u.id, u.first_name, u.last_name FROM users u
     JOIN appointments a ON a.student_id = u.id
     WHERE a.counselor_id = ? ORDER BY u.last_name"
);
$stmt->execute([$user['id']]);
$students = $stmt->fetchAll();

$pageTitle = 'Special Needs Monitoring';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Special Needs Monitoring</h3>

<div class="card mb-4">
  <div class="card-header">Add Student to Monitoring List</div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <?= Csrf::field() ?>
      <div class="col-md-4">
        <label class="form-label">Student</label>
        <select name="student_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Condition / Need</label>
        <input type="text" name="condition_type" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Accommodations</label>
        <input type="text" name="accommodations" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Frequency</label>
        <input type="text" name="monitoring_frequency" class="form-control" placeholder="e.g. monthly">
      </div>
      <div class="col-md-3">
        <label class="form-label">Next Check-in Date</label>
        <input type="date" name="next_check_in" class="form-control">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" name="add_record" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Monitoring List</div>
  <div class="card-body">
    <?php if (!$records): ?>
      <p class="text-muted mb-0">No students currently being monitored.</p>
    <?php else: ?>
      <table class="table align-middle">
        <thead><tr><th>Student</th><th>Condition</th><th>Accommodations</th><th>Frequency</th><th>Last Check-in</th><th>Next Check-in</th><th>Log Check-in</th></tr></thead>
        <tbody>
        <?php foreach ($records as $r): ?>
          <tr class="<?= $r['next_check_in'] <= date('Y-m-d') ? 'table-warning' : '' ?>">
            <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
            <td><?= htmlspecialchars($r['condition_type']) ?></td>
            <td><?= htmlspecialchars($r['accommodations'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['monitoring_frequency'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['last_check_in'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['next_check_in'] ?? '—') ?></td>
            <td>
              <form method="post" class="d-flex gap-1">
                <?= Csrf::field() ?>
                <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                <input type="date" name="next_check_in" class="form-control form-control-sm" required>
                <button type="submit" name="log_checkin" class="btn btn-sm btn-outline-primary">Log</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
