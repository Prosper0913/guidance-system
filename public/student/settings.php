<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Models/AuditLog.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);

$strands = ['ABM', 'HUMSS', 'STEM', 'TechVoc'];
$courses = ['BSN', 'BSBA', 'BSA', 'BSIT', 'BEED', 'BSED', 'FPST'];

$profile = User::studentProfile((int)$user['id']);
$level = $profile['education_level'] ?? null;
$currentCourse = $profile['course'] ?? '';

$pwErrors = [];
$courseErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $pwErrors[] = $courseErrors[] = 'Your session expired. Please try again.';
    } elseif (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $pwErrors[] = 'Please fill in all password fields.';
        } elseif (strlen($new) < 8) {
            $pwErrors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $pwErrors[] = 'New password and confirmation do not match.';
        } elseif ($new === $current) {
            $pwErrors[] = 'Your new password must be different from your current one.';
        } elseif (!User::changePassword((int)$user['id'], $current, $new)) {
            $pwErrors[] = 'Your current password is incorrect.';
        } else {
            session_regenerate_id(true);
            AuditLog::record((int)$user['id'], 'password_changed', 'users', (int)$user['id'], 'Student changed their own password');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password changed successfully.'];
            header('Location: settings.php');
            exit;
        }
    } elseif (isset($_POST['change_course'])) {
        $options = $level === 'senior_highschool' ? $strands : ($level === 'college' ? $courses : []);
        $newCourse = $_POST['course'] ?? '';
        if (!$options) {
            $courseErrors[] = 'Junior Highschool students do not have a course or strand.';
        } elseif (!in_array($newCourse, $options, true)) {
            $courseErrors[] = 'Please choose a valid option from the list.';
        } elseif ($newCourse === $currentCourse) {
            $courseErrors[] = 'That is already your current ' . ($level === 'college' ? 'course' : 'strand') . '.';
        } else {
            User::saveCourse((int)$user['id'], $newCourse);
            AuditLog::record((int)$user['id'], 'course_changed', 'student_profiles', (int)$user['id'],
                ($currentCourse ?: 'none') . ' -> ' . $newCourse);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Your ' . ($level === 'college' ? 'course' : 'strand') . ' was updated to ' . $newCourse . '.'];
            header('Location: settings.php');
            exit;
        }
    }
}

$levelLabels = ['junior_highschool' => 'Junior Highschool', 'senior_highschool' => 'Senior Highschool', 'college' => 'College'];
$pageTitle = 'Settings';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-3">Settings</h3>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Change Password</div>
      <div class="card-body">
        <?php foreach ($pwErrors as $e): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        <form method="post" novalidate id="pwForm">
          <?= Csrf::field() ?>
          <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
          </div>
          <div class="mb-3">
            <label class="form-label">New Password <span class="text-muted small">(at least 8 characters)</span></label>
            <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
            <div class="invalid-feedback">Passwords do not match.</div>
          </div>
          <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Course / Strand</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Education level: <strong><?= htmlspecialchars($levelLabels[$level] ?? 'Not set') ?></strong>
          <?php if ($currentCourse): ?> &middot; Current <?= $level === 'college' ? 'course' : 'strand' ?>: <strong><?= htmlspecialchars($currentCourse) ?></strong><?php endif; ?>
        </p>
        <?php foreach ($courseErrors as $e): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

        <?php if ($level === 'junior_highschool'): ?>
          <p class="mb-0">Junior Highschool students don't have a course or strand, so there's nothing to change here.</p>
        <?php elseif ($level === 'senior_highschool' || $level === 'college'): ?>
          <form method="post" novalidate>
            <?= Csrf::field() ?>
            <div class="mb-3">
              <label class="form-label"><?= $level === 'college' ? 'New Course' : 'New Strand' ?></label>
              <select name="course" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach (($level === 'college' ? $courses : $strands) as $c): ?>
                  <option value="<?= $c ?>" <?= $c === $currentCourse ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" name="change_course" class="btn btn-primary">Update <?= $level === 'college' ? 'Course' : 'Strand' ?></button>
          </form>
        <?php else: ?>
          <p class="mb-0">Your education level isn't set yet. Log out and back in to choose it first.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  // Live mismatch check: highlight both fields red without reloading the page
  (function () {
    var f = document.getElementById('pwForm');
    var a = f.querySelector('input[name="new_password"]');
    var b = f.querySelector('input[name="confirm_password"]');
    function check() {
      var bad = a.value && b.value && a.value !== b.value;
      a.classList.toggle('is-invalid', !!bad); b.classList.toggle('is-invalid', !!bad);
      return !bad;
    }
    a.addEventListener('input', check); b.addEventListener('input', check);
    f.addEventListener('submit', function (e) { if (!check()) { e.preventDefault(); b.focus(); } });
  })();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
