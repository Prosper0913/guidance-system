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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid session token. Please try again.';
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
                header('Location: ' . BASE_URL . '/' . $user['role'] . '/dashboard.php');
                exit;
            }
        }
    }
}

$pageTitle = 'Login';
include __DIR__ . '/partials/header.php';
?>
<div class="card auth-card">
  <div class="card-header text-center"><h4 class="mb-0"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></h4></div>
  <div class="card-body p-4">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Email</label>
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
    <!--<p class="text-center mt-2 mb-0 small text-muted">
      Faculty/Staff referring a student? <a href="<?= BASE_URL ?>/referral-form.php">Submit a Referral</a> — no account needed.
    </p>-->
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
