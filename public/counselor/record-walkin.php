<?php
// Lets a counselor transcribe a physical "Guidance Office - Student Referral Form" (the
// paper form a walk-in student filled out at the office) into the system, using the exact
// same Sections I-VII layout as the student's own online form (see student/book-appointment.php).
// Unlike the online form, this one goes straight from referral -> scheduled/recorded
// appointment in one step, tagged submitted_via = 'walk-in' so the resulting appointment is
// always labeled "Walk-in" rather than "Online".
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Services/ReferralService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);

$errors = [];
$studentResults = [];
$selectedStudent = null;
$profile = null;

if (!empty($_GET['search'])) {
    $studentResults = Referral::searchStudents(trim($_GET['search']));
}

$studentId = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
if ($studentId) {
    $candidate = User::findById($studentId);
    if ($candidate && $candidate['role'] === ROLE_STUDENT) {
        $selectedStudent = $candidate;
        $profile = User::studentProfile($studentId);
    }
}

$categories = Referral::concernCategories();
$actionsOptions = Referral::actionsTakenOptions();
$old = [];
$createdReferralId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedStudent) {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $concerns = [];
        foreach ($categories as $key => $cat) {
            $selected = $_POST['concerns'][$key] ?? [];
            $concerns[$key] = is_array($selected) ? array_map([Validator::class, 'clean'], $selected) : [];
            $concerns[$key . '_others'] = Validator::clean($_POST['concerns_others'][$key] ?? '');
        }
        $concerns['other_concern'] = Validator::clean($_POST['other_concern'] ?? '');

        $hasAnyConcern = false;
        foreach ($concerns as $v) {
            if (is_array($v) && !empty($v)) $hasAnyConcern = true;
            if (is_string($v) && trim($v) !== '') $hasAnyConcern = true;
        }
        $isSelfReferral = !empty($_POST['is_self_referral']);
        $allowedStatuses = [STATUS_PENDING, STATUS_APPROVED, STATUS_COMPLETED, STATUS_NOSHOW];

        if (!$hasAnyConcern) $errors[] = 'Please select or specify at least one concern in Section III.';
        if (empty($_POST['sex'])) $errors[] = 'Please select the student\'s sex in Section I.';
        if (!$isSelfReferral && trim($_POST['referring_party_name'] ?? '') === '') {
            $errors[] = 'Please provide the referring party\'s name in Section II, or mark this as a self-referral.';
        }
        if (empty($_POST['appointment_date'])) $errors[] = 'Please provide the appointment date.';
        if (empty($_POST['appointment_time'])) $errors[] = 'Please provide the appointment time.';
        if (!in_array($_POST['status'] ?? '', $allowedStatuses, true)) $errors[] = 'Please choose a valid status.';
        if (empty($_POST['consent_certified'])) $errors[] = 'Please confirm Section VII before submitting.';

        $selectedActions = $_POST['actions_taken'] ?? [];
        $actionsTaken = [
            'items' => is_array($selectedActions) ? array_values(array_intersect($selectedActions, $actionsOptions)) : [],
            'others' => Validator::clean($_POST['actions_taken_others'] ?? ''),
        ];

        if (!$errors) {
            $fullName = $selectedStudent['first_name'] . ' ' . $selectedStudent['last_name'];
            $contact = $selectedStudent['contact_number'] ?? $selectedStudent['email'];

            $createdReferralId = Referral::create([
                'department' => $profile['course'] ?? null,
                'referral_date' => date('Y-m-d'),
                'student_id' => $selectedStudent['id'],
                'student_name' => $fullName,
                'student_id_number' => $selectedStudent['id_number'] ?? null,
                'grade_year_level' => $profile['year_level'] ?? null,
                'section_course_program' => $profile['course'] ?? null,
                'sex' => in_array($_POST['sex'], ['male', 'female'], true) ? $_POST['sex'] : null,
                'student_contact' => $contact,
                'submitted_via' => 'walk-in',
                'assigned_counselor_id' => $user['id'],
                'referring_party_name' => $isSelfReferral ? $fullName : Validator::clean($_POST['referring_party_name']),
                'referring_party_position' => $isSelfReferral ? 'Self-Referral (Student)' : Validator::clean($_POST['referring_party_position'] ?? ''),
                'referring_party_department' => $isSelfReferral ? ($profile['course'] ?? null) : Validator::clean($_POST['referring_party_department'] ?? ''),
                'referring_party_contact' => $isSelfReferral ? $contact : Validator::clean($_POST['referring_party_contact'] ?? ''),
                'concerns' => $concerns,
                'description_of_incident' => trim($_POST['description_of_incident'] ?? ''),
                'actions_taken' => $actionsTaken,
                'urgency_level' => ($_POST['urgency_level'] ?? '') === 'urgent' ? 'urgent' : 'routine',
                'risk_self_harm' => !empty($_POST['risk_self_harm']),
                'risk_harm_others' => !empty($_POST['risk_harm_others']),
                'severe_emotional_distress' => !empty($_POST['severe_emotional_distress']),
                'crisis_situation' => !empty($_POST['crisis_situation']),
                'consent_certified' => !empty($_POST['consent_certified']),
            ]);

            Referral::process($createdReferralId, [
                'status' => 'accepted',
                'assigned_counselor_id' => $user['id'],
                'office_remarks' => 'Recorded as a walk-in by ' . $user['first_name'] . ' ' . $user['last_name'] . '.',
            ], $user['id']);

            try {
                ReferralService::convertToAppointment(
                    Referral::findById($createdReferralId),
                    $_POST['appointment_date'],
                    $_POST['appointment_time'],
                    $user['id'],
                    'walk-in',
                    $_POST['status']
                );
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Walk-in appointment recorded.'];
                header('Location: appointments.php');
                exit;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage() . ' The referral was saved — open it from the Referrals tab to pick another time.';
            }
        }
    }
}

$pageTitle = 'Record Walk-in Appointment';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<div class="card mb-4">
  <div class="card-body text-center">
    <h5 class="mb-0">THE COLLEGE OF MAASIN</h5>
    <p class="mb-0 fst-italic text-muted">"Nisi Dominus Frustra"</p>
    <p class="mb-2 text-muted small">Tunga-Tunga, Maasin City, Southern Leyte</p>
    <p class="fw-semibold mb-1">OFFICE OF THE GUIDANCE, COUNSELING, AND TESTING SERVICES</p>
    <h4 class="mb-1">Guidance Office — Student Referral Form <span class="badge bg-secondary align-middle">Walk-in</span></h4>
    <p class="text-muted small mb-0">Use this to transcribe a physical form a student filled out at the office. It will always be recorded as a Walk-in appointment.</p>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-header">Find the Student</div>
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
      <p class="text-muted small mb-0">No matching student accounts found. The student needs a registered account before you can record this here.</p>
    <?php endif; ?>
    <?php if ($selectedStudent): ?>
      <p class="mt-2 mb-0 small">Recording for: <strong><?= htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) ?></strong> (<?= htmlspecialchars($selectedStudent['id_number'] ?? '—') ?>) — <a href="record-walkin.php">change student</a></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedStudent): ?>
<div class="card mb-4">
  <div class="card-body">
    <form method="post" novalidate id="walkinForm">
      <?= Csrf::field() ?>
      <input type="hidden" name="student_id" value="<?= $selectedStudent['id'] ?>">

      <h5>I. Student Information</h5>
      <div class="table-responsive">
      <table class="table table-bordered table-sm w-auto">
        <tbody>
          <tr><th class="table-light" style="width:260px;">Student's Name</th><td><?= htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) ?></td></tr>
          <tr><th class="table-light">Student ID Number</th><td><?= htmlspecialchars($selectedStudent['id_number'] ?? '—') ?></td></tr>
          <tr><th class="table-light">Grade / Year Level</th><td><?= htmlspecialchars($profile['year_level'] ?? '—') ?></td></tr>
          <tr><th class="table-light">Section / Course / Program</th><td><?= htmlspecialchars($profile['course'] ?? '—') ?></td></tr>
          <tr>
            <th class="table-light">Sex</th>
            <td>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="sex" value="male" id="sex_male" <?= ($old['sex'] ?? '') === 'male' ? 'checked' : '' ?> required>
                <label class="form-check-label" for="sex_male">Male</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="sex" value="female" id="sex_female" <?= ($old['sex'] ?? '') === 'female' ? 'checked' : '' ?> required>
                <label class="form-check-label" for="sex_female">Female</label>
              </div>
            </td>
          </tr>
          <tr><th class="table-light">Contact Number / Email</th><td><?= htmlspecialchars($selectedStudent['contact_number'] ?? $selectedStudent['email']) ?></td></tr>
        </tbody>
      </table>
      </div>
      <p class="text-muted small">Pulled from the student's account. As written on the physical form, this should match — flag the Registrar's/Guidance Office if it doesn't.</p>

      <h5 class="mt-4">II. Referring Party Information</h5>
      <?php $isSelfReferralChecked = $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($old['is_self_referral']); ?>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="is_self_referral" value="1" id="is_self_referral" <?= $isSelfReferralChecked ? 'checked' : '' ?> onchange="document.getElementById('referringPartyFields').classList.toggle('d-none', this.checked)">
        <label class="form-check-label" for="is_self_referral">This is a self-referral (the student is both the student and the referring party)</label>
      </div>
      <div id="referringPartyFields" class="row g-3 mb-2 <?= $isSelfReferralChecked ? 'd-none' : '' ?>">
        <div class="col-md-6">
          <label class="form-label">Referring Party's Name</label>
          <input type="text" name="referring_party_name" class="form-control" value="<?= htmlspecialchars($old['referring_party_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Position (e.g. Teacher, Parent, Adviser)</label>
          <input type="text" name="referring_party_position" class="form-control" value="<?= htmlspecialchars($old['referring_party_position'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Department/Office</label>
          <input type="text" name="referring_party_department" class="form-control" value="<?= htmlspecialchars($old['referring_party_department'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Contact Number/Email</label>
          <input type="text" name="referring_party_contact" class="form-control" value="<?= htmlspecialchars($old['referring_party_contact'] ?? '') ?>">
        </div>
      </div>

      <h5 class="mt-4">III. Referral Information</h5>
      <p class="text-muted small">Check all that apply, as marked on the physical form.</p>
      <?php $letters = ['A', 'B', 'C', 'D', 'E', 'F']; $li = 0; ?>
      <?php foreach ($categories as $key => $cat): ?>
        <?php $letter = $letters[$li++]; ?>
        <div class="border rounded p-3 mb-3">
          <strong><?= $letter ?>. <?= htmlspecialchars($cat['label']) ?></strong>
          <div class="row mt-2">
            <?php foreach ($cat['items'] as $i => $item): ?>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="concerns[<?= $key ?>][]" value="<?= htmlspecialchars($item) ?>" id="<?= $key ?>_<?= $i ?>"
                    <?= in_array($item, $old['concerns'][$key] ?? [], true) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="<?= $key ?>_<?= $i ?>"><?= htmlspecialchars($item) ?></label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-2">
            <label class="form-label small">Others (specify):</label>
            <input type="text" name="concerns_others[<?= $key ?>]" class="form-control form-control-sm" value="<?= htmlspecialchars($old['concerns_others'][$key] ?? '') ?>">
          </div>
        </div>
      <?php endforeach; ?>

      <div class="border rounded p-3 mb-4">
        <strong>G. Other Concern (please specify)</strong>
        <input type="text" name="other_concern" class="form-control mt-1" value="<?= htmlspecialchars($old['other_concern'] ?? '') ?>">
      </div>

      <h5 class="mt-4">IV. Description of Behavior / Incident</h5>
      <div class="mb-4">
        <textarea name="description_of_incident" class="form-control" rows="4"><?= htmlspecialchars($old['description_of_incident'] ?? '') ?></textarea>
      </div>

      <h5 class="mt-4">V. Actions Taken Prior to Referral</h5>
      <div class="mb-4">
        <?php foreach ($actionsOptions as $i => $action): ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="actions_taken[]" value="<?= htmlspecialchars($action) ?>" id="action_<?= $i ?>"
              <?= in_array($action, $old['actions_taken'] ?? [], true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="action_<?= $i ?>"><?= htmlspecialchars($action) ?></label>
          </div>
        <?php endforeach; ?>
        <label class="form-label small mt-2">Others (specify):</label>
        <input type="text" name="actions_taken_others" class="form-control form-control-sm" value="<?= htmlspecialchars($old['actions_taken_others'] ?? '') ?>">
      </div>

      <h5 class="mt-4">VI. Urgency Assessment</h5>
      <div class="mb-4">
        <label class="form-label d-block">Level of Urgency</label>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="urgency_level" value="routine" id="urgency_routine" <?= ($old['urgency_level'] ?? 'routine') !== 'urgent' ? 'checked' : '' ?>>
          <label class="form-check-label" for="urgency_routine">Routine</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="urgency_level" value="urgent" id="urgency_urgent" <?= ($old['urgency_level'] ?? '') === 'urgent' ? 'checked' : '' ?>>
          <label class="form-check-label" for="urgency_urgent">Urgent — requires immediate attention</label>
        </div>

        <label class="form-label d-block mt-3">Does this case involve any of the following?</label>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="risk_self_harm" value="1" id="risk_self_harm" <?= !empty($old['risk_self_harm']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="risk_self_harm">Risk of self-harm</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="risk_harm_others" value="1" id="risk_harm_others" <?= !empty($old['risk_harm_others']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="risk_harm_others">Risk of harm to others</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="severe_emotional_distress" value="1" id="severe_emotional_distress" <?= !empty($old['severe_emotional_distress']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="severe_emotional_distress">Severe emotional distress</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="crisis_situation" value="1" id="crisis_situation" <?= !empty($old['crisis_situation']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="crisis_situation">This feels like a crisis situation</label>
        </div>
      </div>

      <h5 class="mt-4">Appointment Details</h5>
      <p class="text-muted small">Since this is being recorded directly by the Guidance Office, set the actual date, time, and status rather than a preference.</p>
      <div class="row g-3 mb-4">
        <div class="col-sm-4">
          <label class="form-label">Date <span class="text-danger">*</span></label>
          <input type="date" name="appointment_date" class="form-control" value="<?= htmlspecialchars($old['appointment_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label">Time <span class="text-danger">*</span></label>
          <input type="time" name="appointment_time" class="form-control" value="<?= htmlspecialchars($old['appointment_time'] ?? date('H:i')) ?>" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label">Status <span class="text-danger">*</span></label>
          <select name="status" class="form-select" required>
            <option value="completed" <?= ($old['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed — session already happened</option>
            <option value="approved" <?= ($old['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved — scheduled, hasn't happened yet</option>
            <option value="pending" <?= ($old['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending — still needs review</option>
            <option value="no-show" <?= ($old['status'] ?? '') === 'no-show' ? 'selected' : '' ?>>No-show</option>
          </select>
        </div>
      </div>

      <h5 class="mt-4">VII. Consent and Acknowledgement</h5>
      <div class="mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent_certified" value="1" id="consent_certified" required <?= !empty($old['consent_certified']) ? 'checked' : '' ?>>
          <label class="form-check-label small">
            I certify that this information was transcribed from a physical Guidance Referral Form (or verbal intake) signed/consented to by the student, in accordance with confidentiality and ethical standards.
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">Save Walk-in Referral & Appointment</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
