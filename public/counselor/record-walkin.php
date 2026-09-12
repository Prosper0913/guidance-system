<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Services/NotificationService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

$errors = [];
$studentResults = [];
$selectedStudent = null;

if (!empty($_GET['search'])) {
    $studentResults = Referral::searchStudents(trim($_GET['search']));
}

if (!empty($_GET['student_id'])) {
    $stmt = Database::getConnection()->prepare(
        "SELECT id, id_number, first_name, last_name, email FROM users WHERE id = ? AND role = 'student'"
    );
    $stmt->execute([(int)$_GET['student_id']]);
    $selectedStudent = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $status = $_POST['status'] ?? '';
    $allowedStatuses = [STATUS_PENDING, STATUS_APPROVED, STATUS_COMPLETED, STATUS_NOSHOW];

    if (!$studentId) {
        $errors[] = 'Please select a student.';
    }
    if (!$date || !$time) {
        $errors[] = 'Please provide the appointment date and time.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Please choose a valid status.';
    }

    if (!$errors) {
        try {
            $appointmentId = Appointment::recordWalkIn([
                'student_id' => $studentId,
                'counselor_id' => $user['id'],
                'concern_category_id' => $_POST['concern_category_id'] ?: null,
                'appointment_date' => $date,
                'appointment_time' => $time,
                'status' => $status,
                'is_confidential' => !empty($_POST['is_confidential']),
                'notes' => Validator::clean($_POST['notes'] ?? ''),
            ]);

            NotificationService::customMessage(
                ['student_id' => $studentId, 'id' => $appointmentId],
                "A walk-in guidance appointment was recorded for you on {$date} at " . date('g:i A', strtotime($time)) . '.'
            );

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Walk-in appointment recorded.'];
            header('Location: appointments.php');
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$categories = Appointment::categories();
$pageTitle = 'Record Walk-in Appointment';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-1">Record Walk-in Appointment</h3>
<p class="text-muted">Use this when a student came to the Guidance Office in person or filled out the physical form, instead of booking online. It will always be labeled <strong>Walk-in</strong> in the appointments list.</p>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-header">1. Find the student</div>
  <div class="card-body">
    <form method="get" class="d-flex gap-2 mb-2">
      <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or ID number" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
      <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
    </form>
    <?php if ($studentResults): ?>
      <ul class="list-group">
        <?php foreach ($studentResults as $s): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?> (<?= htmlspecialchars($s['id_number']) ?>)</span>
            <a class="btn btn-sm btn-primary" href="record-walkin.php?student_id=<?= $s['id'] ?>">Select</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif (!empty($_GET['search'])): ?>
      <p class="text-muted small mb-0">No matching student accounts found. The student needs a registered account before you can record an appointment for them.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedStudent): ?>
<div class="card">
  <div class="card-header">2. Appointment details for <?= htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) ?></div>
  <div class="card-body">
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="student_id" value="<?= $selectedStudent['id'] ?>">

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Date</label>
          <input type="date" name="appointment_date" class="form-control" value="<?= htmlspecialchars($_POST['appointment_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Time</label>
          <input type="time" name="appointment_time" class="form-control" value="<?= htmlspecialchars($_POST['appointment_time'] ?? date('H:i')) ?>" required>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Concern Category</label>
        <select name="concern_category_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select" required>
          <option value="completed">Completed — session already happened</option>
          <option value="approved">Approved — scheduled, hasn't happened yet</option>
          <option value="pending">Pending — still needs review</option>
          <option value="no-show">No-show</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Notes (optional)</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="e.g. what the physical form said, or context for this visit"></textarea>
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="is_confidential" value="1" id="is_confidential" checked>
        <label class="form-check-label" for="is_confidential">Mark as confidential</label>
      </div>

      <button type="submit" class="btn btn-primary">Record Walk-in Appointment</button>
      <a href="appointments.php" class="btn btn-outline-secondary">Cancel</a>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
