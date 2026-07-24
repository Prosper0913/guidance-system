<?php
// This page used to be a direct counselor+slot picker. Per the school's actual referral
// policy ("No Referral, No Appointment, except in emergency cases"), booking now works by
// submitting a Guidance Referral — the same intake used for teacher/staff referrals, laid
// out to mirror the official "Guidance Office - Student Referral Form" (Sections I-VII) —
// which the Guidance Office then reviews and schedules (see counselor/referral-view.php).
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Services/ReferralService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_STUDENT]);
$profile = User::studentProfile($user['id']);
$counselors = User::allCounselors();
$categories = Referral::concernCategories();
$actionsOptions = Referral::actionsTakenOptions();
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        // Section III — build concerns JSON from checkbox groups (A-F) + G. Other Concern
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
        if (!$hasAnyConcern) $errors[] = 'Please select or specify at least one concern in Section III.';
        if (empty($_POST['sex'])) $errors[] = 'Please select your sex in Section I.';
        if (empty($_POST['consent_certified'])) $errors[] = 'You must certify the information and accept the confidentiality terms in Section VII.';

        // Section V — actions taken prior to referral
        $selectedActions = $_POST['actions_taken'] ?? [];
        $actionsTaken = [
            'items' => is_array($selectedActions) ? array_values(array_intersect($selectedActions, $actionsOptions)) : [],
            'others' => Validator::clean($_POST['actions_taken_others'] ?? ''),
        ];

        if (!$errors) {
            $referralId = Referral::create([
                'department' => $profile['course'] ?? null,
                'referral_date' => date('Y-m-d'),
                'student_id' => $user['id'],
                'student_name' => $user['first_name'] . ' ' . $user['last_name'],
                'student_id_number' => $user['id_number'] ?? null,
                'grade_year_level' => $profile['year_level'] ?? null,
                'section_course_program' => $profile['course'] ?? null,
                'sex' => in_array($_POST['sex'] ?? '', ['male', 'female'], true) ? $_POST['sex'] : null,
                'student_contact' => $user['contact_number'] ?? $user['email'],
                'preferred_type' => in_array($_POST['preferred_type'] ?? '', ['walk-in', 'online'], true) ? $_POST['preferred_type'] : null,
                'preferred_counselor_id' => (int)($_POST['preferred_counselor_id'] ?? 0) ?: null,
                'preferred_date' => !empty($_POST['preferred_date']) ? $_POST['preferred_date'] : null,
                'preferred_time' => !empty($_POST['preferred_time']) ? $_POST['preferred_time'] : null,
                'referring_party_name' => $user['first_name'] . ' ' . $user['last_name'],
                'referring_party_position' => 'Self-Referral (Student)',
                'referring_party_department' => $profile['course'] ?? null,
                'referring_party_contact' => $user['contact_number'] ?? $user['email'],
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

            $referral = Referral::findById($referralId);
            ReferralService::notifyNewReferral($referralId, $referral['student_name'], $referral['urgency_level'] === 'urgent');

            header('Location: ' . BASE_URL . '/referral-thankyou.php?ref=' . urlencode($referral['referral_no']));
            exit;
        }
    }
}

$pageTitle = 'Guidance Office Student Referral Form';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';

$fullName = $user['first_name'] . ' ' . $user['last_name'];
$contact = $user['contact_number'] ?? $user['email'];
?>
<div class="card mb-4">
  <div class="card-body text-center">
    <h5 class="mb-0">THE COLLEGE OF MAASIN</h5>
    <p class="mb-0 fst-italic text-muted">"Nisi Dominus Frustra"</p>
    <p class="mb-2 text-muted small">Tunga-Tunga, Maasin City, Southern Leyte</p>
    <p class="fw-semibold mb-1">OFFICE OF THE GUIDANCE, COUNSELING, AND TESTING SERVICES</p>
    <h4 class="mb-1">Guidance Office — Student Referral Form</h4>
    <p class="text-muted small mb-0">All counseling and consultation services require this completed form. No Referral, No Appointment, except in emergency cases.</p>
  </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" novalidate>
      <?= Csrf::field() ?>

      <h5>I. Student Information</h5>
      <table class="table table-bordered table-sm w-auto">
        <tbody>
          <tr><th class="table-light" style="width:260px;">Student's Name</th><td><?= htmlspecialchars($fullName) ?></td></tr>
          <tr><th class="table-light">Student ID Number</th><td><?= htmlspecialchars($user['id_number'] ?? '—') ?></td></tr>
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
          <tr><th class="table-light">Contact Number / Email</th><td><?= htmlspecialchars($contact) ?></td></tr>
        </tbody>
      </table>
      <p class="text-muted small">Pulled from your account profile. Contact the Registrar's/Guidance Office to correct any of these details.</p>

      <h5 class="mt-4">II. Referring Party Information</h5>
      <div class="alert alert-secondary py-2 mb-4">
        You are submitting this as a <strong>self-referral</strong> — you are both the student and the referring party, same as walking into the Guidance Office yourself.
      </div>

      <h5 class="mt-4">III. Referral Information</h5>
      <p class="text-muted small">Please check all that apply and specify observed concerns.</p>
      <?php $letters = ['A', 'B', 'C', 'D', 'E', 'F']; $li = 0; ?>
      <?php foreach ($categories as $key => $cat): ?>
        <div class="border rounded p-3 mb-3">
          <strong><?= $letters[$li++] ?>. <?= htmlspecialchars($cat['label']) ?></strong>
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
        <p class="text-muted small mb-1">e.g., health-related issues, adjustment difficulties, or concerns not listed above</p>
        <input type="text" name="other_concern" class="form-control mt-1" value="<?= htmlspecialchars($old['other_concern'] ?? '') ?>">
      </div>

      <h5 class="mt-4">IV. Description of Behavior / Incident</h5>
      <p class="text-muted small">Please describe the observed behavior, situation, or incident(s) that prompted this referral.</p>
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

        <label class="form-label d-block mt-3">Does this case involve any of the following? <span class="text-muted small">(check if applicable — it's okay to leave unchecked)</span></label>
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
        <p class="text-muted small mt-2 mb-0">Cases marked urgent will be prioritized by the Guidance Office.</p>

        <div id="crisisNotice" class="alert alert-warning mt-3" style="display:none;">
          <strong>If you need to talk to someone right now, please don't wait for this form to be reviewed:</strong>
          <ul class="mb-0 mt-1">
            <li>Walk in directly to the Guidance Office during office hours — no appointment needed for urgent concerns</li>
            <li>National Center for Mental Health Crisis Hotline: <strong>1553</strong> (toll-free landline) or <strong>0966-351-4518 / 0917-899-8727</strong></li>
            <li>If this is a medical emergency, call <strong>911</strong> or go to the nearest emergency room</li>
          </ul>
        </div>
      </div>

      <h5 class="mt-4">Additional Scheduling Preference <span class="text-muted small">(optional, not part of the official form)</span></h5>
      <p class="text-muted small">This helps the Guidance Office schedule you — the final date/time will be confirmed once your request is reviewed, and may differ if your preferred slot is already taken by another student.</p>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label">Preferred Date</label>
          <input type="date" name="preferred_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['preferred_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Preferred Time</label>
          <input type="time" name="preferred_time" class="form-control" value="<?= htmlspecialchars($old['preferred_time'] ?? '') ?>">
        </div>
      </div>

      <h5 class="mt-4">VII. Consent and Acknowledgement</h5>
      <div class="mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent_certified" value="1" id="consent_certified" required <?= !empty($old['consent_certified']) ? 'checked' : '' ?>>
          <label class="form-check-label small">
            I certify that the information provided above is accurate to the best of my knowledge. I understand that the Guidance Office will handle this referral in accordance with confidentiality and ethical standards.
          </label>
        </div>
        <p class="text-muted small mt-2 mb-0">By submitting this form while logged in, you are electronically signing as the referring party in place of a handwritten signature.</p>
      </div>

      <button type="submit" class="btn btn-primary w-100">Submit Referral Form</button>
    </form>
  </div>
</div>

<script>
function toggleCrisisNotice() {
  const anyChecked = ['risk_self_harm', 'risk_harm_others', 'crisis_situation'].some(id => document.getElementById(id).checked);
  document.getElementById('crisisNotice').style.display = anyChecked ? 'block' : 'none';
}
['risk_self_harm', 'risk_harm_others', 'crisis_situation'].forEach(id => {
  document.getElementById(id).addEventListener('change', toggleCrisisNotice);
});
toggleCrisisNotice();
</script>

<div class="card">
  <div class="card-body">
    <p class="mb-0 text-muted small">Prefer to talk in person right away? You may also walk in directly to the Guidance Office during office hours.</p>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
