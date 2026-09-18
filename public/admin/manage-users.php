<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    if (isset($_POST['create_staff'])) {
        $role = $_POST['role'] === ROLE_ADMIN ? ROLE_ADMIN : ROLE_COUNSELOR;
        $idNumber = Validator::clean($_POST['id_number']);
        $email = trim($_POST['email']);
        if (!Validator::email($email)) {
            $errors[] = 'Valid email required.';
        } elseif (User::findByEmail($email)) {
            $errors[] = 'Email already registered.';
        } elseif (User::idNumberExists($idNumber)) {
            $errors[] = 'ID number already registered.';
        } else {
            User::createStaff([
                'role' => $role,
                'id_number' => $idNumber,
                'first_name' => Validator::clean($_POST['first_name']),
                'last_name' => Validator::clean($_POST['last_name']),
                'email' => $email,
                'contact_number' => Validator::clean($_POST['contact_number'] ?? ''),
                'specialization' => Validator::clean($_POST['specialization'] ?? ''),
                'office_location' => Validator::clean($_POST['office_location'] ?? ''),
                'password' => $_POST['password'],
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => ucfirst($role) . ' account created.'];
            header('Location: ' . BASE_URL . '/admin/manage-users.php');
            exit;
        }
    } elseif (isset($_POST['toggle_status'])) {
        $targetId = (int)$_POST['user_id'];
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'disabled';
        User::setStatus($targetId, $newStatus);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Account status updated.'];
        header('Location: ' . BASE_URL . '/admin/manage-users.php');
        exit;
    }
}

$students = User::allByRole(ROLE_STUDENT);
$counselors = User::allByRole(ROLE_COUNSELOR);
$admins = User::allByRole(ROLE_ADMIN);

$pageTitle = 'Manage Users';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-4">Manage Users</h3>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-header">Create Counselor / Admin Account</div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <?= Csrf::field() ?>
      <div class="col-md-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
          <option value="counselor">Counselor</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">ID Number</label><input type="text" name="id_number" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
      <div class="col-md-2"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required minlength="8"></div>
      <div class="col-md-3"><label class="form-label">Specialization (counselor)</label><input type="text" name="specialization" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">Office Location (counselor)</label><input type="text" name="office_location" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">Contact Number</label><input type="text" name="contact_number" class="form-control"></div>
      <div class="col-md-3 d-flex align-items-end"><button type="submit" name="create_staff" class="btn btn-primary w-100">Create Account</button></div>
    </form>
  </div>
</div>


<?php
// Added $type parameter to handle different table layouts easily
function renderUserTable($title, $rows, $type = 'admin') {
  echo '<div class="card mb-4"><div class="card-header">' . htmlspecialchars($title) . '</div><div class="card-body">';
  if (!$rows) {
    echo '<p class="text-muted mb-0">None yet.</p>';
  } else {
    echo '<p class="swipe-hint d-md-none">⟷ Swipe left/right to see more</p><div class="table-responsive"><table class="table align-middle table-compact"><thead><tr><th>Name</th><th>ID No.</th><th>Email</th>';
    
    // Dynamic Headers
    if ($type === 'student') {
      echo '<th>Course</th><th>Year Level</th><th>Section</th>';
    } elseif ($type === 'counselor') {
      echo '<th>Specialization</th>';
    }

    echo '<th>Status</th><th>Action</th></tr></thead><tbody>';
    
    foreach ($rows as $u) {
      $newStatus = $u['status'] === 'active' ? 'disabled' : 'active';
      $btnClass = $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success';
      $btnLabel = $u['status'] === 'active' ? 'Disable' : 'Activate';
      
      echo '<tr><td>' . htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) . '</td>';
      echo '<td>' . htmlspecialchars($u['id_number']) . '</td>';
      echo '<td>' . htmlspecialchars($u['email']) . '</td>';
      
      // Dynamic Data Rows
      if ($type === 'student') {
        echo '<td>' . htmlspecialchars($u['course'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($u['year_level'] ?? $u['year'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($u['section'] ?? 'N/A') . '</td>';
      } elseif ($type === 'counselor') {
        echo '<td>' . htmlspecialchars($u['specialization'] ?? 'N/A') . '</td>';
      }

      echo '<td><span class="badge bg-' . ($u['status'] === 'active' ? 'success' : 'secondary') . '">' . htmlspecialchars($u['status']) . '</span></td>';
      echo '<td><form method="post" onsubmit="return confirm(\'Are you sure?\');">' . Csrf::field()
         . '<input type="hidden" name="user_id" value="' . $u['id'] . '">'
         . '<input type="hidden" name="new_status" value="' . $newStatus . '">'
         . '<button type="submit" name="toggle_status" class="btn btn-sm ' . $btnClass . '">' . $btnLabel . '</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
  }
  echo '</div></div>';
}

// Pass the table type string to activate the correct columns
renderUserTable('Counselors', $counselors, 'counselor');
renderUserTable('Administrators', $admins, 'admin');
renderUserTable('Students', $students, 'student');
?>
<?php include __DIR__ . '/../partials/footer.php'; ?>