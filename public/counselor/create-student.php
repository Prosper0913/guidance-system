<?php
// Public self-registration was removed — counselors are now responsible for creating
// student accounts. Course/strand input adapts to the selected education level:
// Junior High has neither, Senior High gets a strand dropdown, College gets a course dropdown.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

$strands = ['ABM', 'HUMSS', 'STEM', 'TechVoc'];
$courses = ['BSN', 'BSBA', 'BSA', 'BSIT', 'BEED', 'BSED', 'FPST'];

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please resubmit the form.';
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

        if (!Validator::required($idNumber)) $errors[] = 'Student ID number is required.';
        if (!Validator::required($firstName)) $errors[] = 'First name is required.';
        if (!Validator::required($lastName)) $errors[] = 'Last name is required.';
        if (!in_array($educationLevel, ['junior_highschool', 'senior_highschool', 'college'], true)) $errors[] = 'Please select the education level.';
        if ($educationLevel === 'senior_highschool' && !$course) $errors[] = 'Please select a strand.';
        if ($educationLevel === 'college' && !$course) $errors[] = 'Please select a course.';
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
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Student account created for {$firstName} {$lastName}."];
            header('Location: create-student.php');
            exit;
        }
    }
}

$pageTitle = 'Create Student Account';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<div class="card" style="max-width: 640px;">
  <div class="card-header"><h4 class="mb-0">Create Student Account</h4></div>
  <div class="card-body p-4">
    <p class="text-muted small">Self-registration has been removed — the Guidance Office creates student accounts directly.</p>
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <form method="post" novalidate id="createStudentForm">
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
          <label class="form-label">Year Level</label>
          <input type="text" name="year_level" class="form-control" placeholder="e.g. Grade 8, Grade 11, 2nd Year" value="<?= htmlspecialchars($old['year_level'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Section</label>
          <input type="text" name="section" class="form-control" value="<?= htmlspecialchars($old['section'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Temporary Password</label>
          <input type="password" name="password" class="form-control" required minlength="8">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="8">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100">Create Student Account</button>
    </form>
  </div>
</div>
<script>
  const eduLevel = document.getElementById('educationLevel');
  const strandField = document.getElementById('strandField');
  const courseField = document.getElementById('courseField');

  function toggleCourseFields() {
    const level = eduLevel.value;
    strandField.style.display = level === 'senior_highschool' ? '' : 'none';
    courseField.style.display = level === 'college' ? '' : 'none';
    strandField.querySelector('select').disabled = level !== 'senior_highschool';
    courseField.querySelector('select').disabled = level !== 'college';
  }

  eduLevel.addEventListener('change', toggleCourseFields);
  toggleCourseFields();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
