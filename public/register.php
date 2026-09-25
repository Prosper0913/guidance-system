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

$strands = ['ABM', 'HUMSS', 'STEM', 'TechVoc'];
$courses = ['BSN', 'BSBA', 'BSA', 'BSIT', 'BEED', 'BSED', 'FPST'];

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
        $educationLevel = Validator::clean($_POST['education_level'] ?? '');
        $yearLevel = Validator::clean($_POST['year_level'] ?? '');
        $section = Validator::clean($_POST['section'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Course/strand only applies to Senior High (strand) and College (course) —
        // Junior High students don't have either, so it's forced to null regardless
        // of anything submitted for that field.
        $course = null;
        if ($educationLevel === 'senior_highschool') {
            $course = in_array($_POST['strand'] ?? '', $strands, true) ? $_POST['strand'] : '';
        } elseif ($educationLevel === 'college') {
            $course = in_array($_POST['course'] ?? '', $courses, true) ? $_POST['course'] : '';
        }

        // Year level options depend on education level too — validated server-side in
        // case someone bypasses the JS-driven dropdown swap.
        $yearLevelOptions = [
            'junior_highschool' => ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'],
            'senior_highschool' => ['Grade 11', 'Grade 12'],
            'college' => ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        ];
        $allowedYearLevels = $yearLevelOptions[$educationLevel] ?? [];
        if (!in_array($yearLevel, $allowedYearLevels, true)) {
            $yearLevel = '';
        }

        if (!Validator::required($idNumber)) $errors[] = 'Student ID number is required.';
        if (!Validator::required($firstName)) $errors[] = 'First name is required.';
        if (!Validator::required($lastName)) $errors[] = 'Last name is required.';
        if (!in_array($educationLevel, ['junior_highschool', 'senior_highschool', 'college'], true)) $errors[] = 'Please select your education level.';
        if ($educationLevel === 'senior_highschool' && !$course) $errors[] = 'Please select your strand.';
        if ($educationLevel === 'college' && !$course) $errors[] = 'Please select your course.';
        if (!Validator::required($yearLevel)) $errors[] = 'Please select your grade/year level.';
        if (!Validator::email($email)) $errors[] = 'A valid email is required.';
        if (!Validator::minLength($password, 8)) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        if (Validator::required($idNumber) && User::idNumberExists($idNumber)) $errors[] = 'That ID number is already registered.';
        if (Validator::email($email) && User::findByEmail($email)) $errors[] = 'That email is already registered.';

        if (!$errors) {
            User::createStudent([
                'id_number' => $idNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'contact_number' => $contact,
                'education_level' => $educationLevel,
                'course' => $course,
                'year_level' => $yearLevel,
                'section' => $section,
                'password' => $password,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Account created! You can now log in.'];
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }
}

$pageTitle = 'Create Account';
include __DIR__ . '/partials/header.php';
?>
<div class="card auth-card" style="max-width: 560px;">
  <div class="card-header text-center"><h4 class="mb-0"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></h4></div>
  <div class="card-body p-4">
    <p class="text-center text-muted small mb-3">Student Account Registration</p>
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate id="registerForm">
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
          <label class="form-label">Education Level <span class="text-danger">*</span></label>
          <select name="education_level" id="educationLevel" class="form-select" required>
            <option value="" <?= empty($old['education_level']) ? 'selected' : '' ?>>-- Select Level --</option>
            <option value="junior_highschool" <?= ($old['education_level'] ?? '') === 'junior_highschool' ? 'selected' : '' ?>>Junior Highschool</option>
            <option value="senior_highschool" <?= ($old['education_level'] ?? '') === 'senior_highschool' ? 'selected' : '' ?>>Senior Highschool</option>
            <option value="college" <?= ($old['education_level'] ?? '') === 'college' ? 'selected' : '' ?>>College</option>
          </select>
        </div>

        <div class="col-md-6 mb-3" id="strandField" style="display:none;">
          <label class="form-label">Strand <span class="text-danger">*</span></label>
          <select name="strand" class="form-select">
            <option value="">-- Select Strand --</option>
            <?php foreach ($strands as $s): ?>
              <option value="<?= $s ?>" <?= ($old['strand'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6 mb-3" id="courseField" style="display:none;">
          <label class="form-label">Course <span class="text-danger">*</span></label>
          <select name="course" class="form-select">
            <option value="">-- Select Course --</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?= $c ?>" <?= ($old['course'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6 mb-3">
          <label class="form-label">Year Level <span class="text-danger">*</span></label>
          <select name="year_level" id="yearLevelJHS" class="form-select year-level-select" style="display:none;">
            <option value="">-- Select Grade --</option>
            <?php foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'] as $g): ?>
              <option value="<?= $g ?>" <?= ($old['year_level'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
          <select name="year_level" id="yearLevelSHS" class="form-select year-level-select" style="display:none;">
            <option value="">-- Select Grade --</option>
            <?php foreach (['Grade 11', 'Grade 12'] as $g): ?>
              <option value="<?= $g ?>" <?= ($old['year_level'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
          <select name="year_level" id="yearLevelCollege" class="form-select year-level-select" style="display:none;">
            <option value="">-- Select Year --</option>
            <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $y): ?>
              <option value="<?= $y ?>" <?= ($old['year_level'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
          <select class="form-select" disabled id="yearLevelPlaceholder">
            <option>-- Select education level first --</option>
          </select>
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
    <p class="text-center mt-3 mb-0">
      Already have an account? <a href="<?= BASE_URL ?>/login.php">Log in</a>
    </p>
  </div>
</div>
<script>
  const eduLevel = document.getElementById('educationLevel');
  const strandField = document.getElementById('strandField');
  const courseField = document.getElementById('courseField');
  const yearLevelJHS = document.getElementById('yearLevelJHS');
  const yearLevelSHS = document.getElementById('yearLevelSHS');
  const yearLevelCollege = document.getElementById('yearLevelCollege');
  const yearLevelPlaceholder = document.getElementById('yearLevelPlaceholder');

  function toggleCourseFields() {
    const level = eduLevel.value;
    strandField.style.display = level === 'senior_highschool' ? '' : 'none';
    courseField.style.display = level === 'college' ? '' : 'none';
    strandField.querySelector('select').disabled = level !== 'senior_highschool';
    courseField.querySelector('select').disabled = level !== 'college';

    const yearLevelMap = {
      junior_highschool: yearLevelJHS,
      senior_highschool: yearLevelSHS,
      college: yearLevelCollege,
    };
    [yearLevelJHS, yearLevelSHS, yearLevelCollege].forEach(sel => {
      const isActive = yearLevelMap[level] === sel;
      sel.style.display = isActive ? '' : 'none';
      sel.disabled = !isActive;
    });
    yearLevelPlaceholder.style.display = level ? 'none' : '';
  }

  eduLevel.addEventListener('change', toggleCourseFields);
  toggleCourseFields();

  // Live password-match validation — highlights the fields and shows a
  // dismissible notice instead of round-tripping to the server to say the
  // same thing.
  const pwField = document.querySelector('input[name="password"]');
  const confirmField = document.querySelector('input[name="confirm_password"]');
  const registerForm = document.getElementById('registerForm');
  let pwMismatchAlert = null;

  function clearPwMismatch() {
    pwField.classList.remove('is-invalid');
    confirmField.classList.remove('is-invalid');
    if (pwMismatchAlert) {
      pwMismatchAlert.remove();
      pwMismatchAlert = null;
    }
  }

  function showPwMismatch() {
    pwField.classList.add('is-invalid');
    confirmField.classList.add('is-invalid');
    if (!pwMismatchAlert) {
      pwMismatchAlert = document.createElement('div');
      pwMismatchAlert.className = 'alert alert-danger py-2';
      pwMismatchAlert.textContent = 'Passwords do not match.';
      registerForm.prepend(pwMismatchAlert);
    }
  }

  function checkPasswordsMatch() {
    if (!pwField.value || !confirmField.value) {
      clearPwMismatch();
      return true;
    }
    if (pwField.value !== confirmField.value) {
      showPwMismatch();
      return false;
    }
    clearPwMismatch();
    return true;
  }

  pwField.addEventListener('input', checkPasswordsMatch);
  confirmField.addEventListener('input', checkPasswordsMatch);

  registerForm.addEventListener('submit', function (e) {
    if (!checkPasswordsMatch()) {
      e.preventDefault();
      confirmField.focus();
    }
  });
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
