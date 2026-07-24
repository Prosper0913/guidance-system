<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Models/Appointment.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$appointmentId = (int)($_GET['appointment_id'] ?? 0);
$appointment = Appointment::findById($appointmentId);

if (!$appointment || (int)$appointment['counselor_id'] !== (int)$user['id']) {
    http_response_code(404);
    die('Appointment not found.');
}

$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    $notes = trim($_POST['notes'] ?? '');
    if ($notes !== '') {
        $stmt = $db->prepare(
            'INSERT INTO session_notes (appointment_id, counselor_id, notes, is_confidential) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$appointmentId, $user['id'], $notes]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Session note saved.'];
    }
    header('Location: ' . BASE_URL . '/counselor/session-notes.php?appointment_id=' . $appointmentId);
    exit;
}

$stmt = $db->prepare('SELECT * FROM session_notes WHERE appointment_id = ? ORDER BY created_at DESC');
$stmt->execute([$appointmentId]);
$notes = $stmt->fetchAll();

$pageTitle = 'Session Notes';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-1">Session Notes</h3>
<p class="text-muted">
  <?= htmlspecialchars($appointment['student_first'] . ' ' . $appointment['student_last']) ?>
  — <?= htmlspecialchars($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?>
</p>

<div class="card mb-4">
  <div class="card-header">Add Note (confidential — visible to you and admin only)</div>
  <div class="card-body">
    <form method="post">
      <?= Csrf::field() ?>
      <textarea name="notes" class="form-control mb-2" rows="4" required></textarea>
      <button type="submit" class="btn btn-primary">Save Note</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Note History</div>
  <div class="card-body">
    <?php if (!$notes): ?>
      <p class="text-muted mb-0">No notes recorded yet.</p>
    <?php else: ?>
      <?php foreach ($notes as $n): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="small text-muted"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></div>
          <div><?= nl2br(htmlspecialchars($n['notes'])) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
