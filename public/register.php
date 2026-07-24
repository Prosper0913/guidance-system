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
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid session token. Please try again.';
    } else {
        $idNumber = Validator::clean($_POST['id_number'] ?? '');
        $firstName = Validator::clean($_POST['first_name'] ?? '');
        $lastName = Validator::clean($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = Validator::clean($_POST['contact_number'] ?? '');
        $course = Validator::clean($_POST['course'] ?? '');
        $yearLevel = Validator::clean($_POST['year_level'] ?? '');
        $section = Validator::clean($_POST['section'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!Validator::required($idNumber)) $errors[] = 'Student ID number is required.';
        if (!Validator::required($firstName)) $errors[] = 'First name is required.';
        if (!Validator::required($lastName)) $errors[] = 'Last name is required.';
        if (!Validator::email($email)) $errors[] = 'A valid email is required.';
        if (!Validator::minLength($password, 8)) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        if (Validator::required($idNumber) && User::idNumberExists($idNumber)) $errors[] = 'That ID number is already registered.';
        if (Validator::email($email) && User::findByEmail($email)) $errors[] = 'That email is already registered.';

        if (!$errors) {
            $userId = User::createStudent([
                'id_number' => $idNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'contact_number' => $contact,
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section,
                'password' => $password,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Account created! You may now log in.'];
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
}

$pageTitle = 'Register';
include __DIR__ . '/partials/header.php';
?>
<div class="card auth-card" style="max-width: 560px;">
  <div class="card-header text-center"><h4 class="mb-0">Create Student Account</h4></div>
  <div class="card-body p-4">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate>
      <?= Csrf::field() ?>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Student ID Number</label>
          <input type="text" name="id_number" class="form-control" required value="<?= htmlspecialchars($old['id_number'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">First Name</label>
          <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($old['first_name'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Last Name</label>
          <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($old['last_name'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($old['contact_number'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Course</label>
          <input type="text" name="course" class="form-control" value="<?= htmlspecialchars($old['course'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Year Level</label>
          <input type="text" name="year_level" class="form-control" value="<?= htmlspecialchars($old['year_level'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Section</label>
          <input type="text" name="section" class="form-control" value="<?= htmlspecialchars($old['section'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required minlength="8">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="8">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100">Create Account</button>
    </form>
    <p class="text-center mt-3 mb-0">Already have an account? <a href="<?= BASE_URL ?>/login.php">Log in</a></p>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
