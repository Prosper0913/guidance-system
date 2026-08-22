<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Helpers/Csrf.php';
require_once __DIR__ . '/../src/Helpers/Validator.php';

AuthMiddleware::start();
if (AuthMiddleware::currentUser()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$errors = [];
$needsEducationLevel = false;
$authenticatedUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid session token. Please try again.';
    } elseif (isset($_POST['save_education_level'])) {
        // Student is setting their education level after login
        $level = $_POST['education_level'] ?? '';
        if (!in_array($level, ['junior_highschool', 'senior_highschool', 'college'], true)) {
            $errors[] = 'Please select your education level.';
            $needsEducationLevel = true;
        } else {
            User::saveEducationLevel((int)$_SESSION['user']['id'], $level);
            $user = $_SESSION['user'];
            header('Location: ' . BASE_URL . '/' . $user['role'] . '/dashboard.php');
            exit;
        }
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!Validator::required($email) || !Validator::required($password)) {
            $errors[] = 'Email and password are required.';
        } else {
            $user = User::authenticate($email, $password);
            if (!$user) {
                $errors[] = 'Invalid credentials, or your account is disabled.';
            } else {
                AuthMiddleware::login($user);

                // Check if student needs to set education level
                if ($user['role'] === 'student') {
                    $profile = User::studentProfile($user['id']);
                    if (!$profile || empty($profile['education_level'])) {
                        $needsEducationLevel = true;
                        $authenticatedUser = $user;
                    } else {
                        header('Location: ' . BASE_URL . '/' . $user['role'] . '/dashboard.php');
                        exit;
                    }
                } else {
                    header('Location: ' . BASE_URL . '/' . $user['role'] . '/dashboard.php');
                    exit;
                }
            }
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/partials/header.php';
?>
<?php if ($needsEducationLevel && $authenticatedUser): ?>
<div class="card auth-card" style="max-width: 440px;">
  <div class="card-header text-center"><h4 class="mb-0"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></h4></div>
  <div class="card-body p-4">
    <p class="text-center mb-3">Welcome, <strong><?= htmlspecialchars($authenticatedUser['first_name'] . ' ' . $authenticatedUser['last_name']) ?></strong>!</p>
    <p class="text-center text-muted small mb-3">Please select your education level to continue. This will assign you to the correct guidance counselor.</p>
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Education Level <span class="text-danger">*</span></label>
        <select name="education_level" class="form-select" required>
          <option value="">-- Select Level --</option>
          <option value="junior_highschool">Junior Highschool</option>
          <option value="senior_highschool">Senior Highschool</option>
          <option value="college">College</option>
        </select>
      </div>
      <button type="submit" name="save_education_level" class="btn btn-primary w-100">Continue</button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card auth-card">
  <div class="card-header text-center"><h4 class="mb-0"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></h4></div>
  <div class="card-body p-4">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Email or Username</label>
        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Log In</button>
    </form>
    <p class="text-center mt-3 mb-0">
      New student? <a href="<?= BASE_URL ?>/register.php">Create an account</a>
    </p>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
