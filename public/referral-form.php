<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Models/Referral.php';
require_once __DIR__ . '/../src/Services/ReferralService.php';
require_once __DIR__ . '/../src/Helpers/Csrf.php';
require_once __DIR__ . '/../src/Helpers/Validator.php';

AuthMiddleware::start(); // no login required — referring parties are usually staff without accounts

$categories = Referral::concernCategories();
$actionsOptions = Referral::actionsTakenOptions();
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        $studentName = Validator::clean($_POST['student_name'] ?? '');
        $referringName = Validator::clean($_POST['referring_party_name'] ?? '');
        $referralDate = $_POST['referral_date'] ?? date('Y-m-d');

        if (!Validator::required($studentName)) $errors[] = "Student's name is required.";
        if (!Validator::required($referringName)) $errors[] = 'Referring party name is required.';
        if (empty($_POST['consent_certified'])) $errors[] = 'You must certify the information and accept the confidentiality terms.';

        // Build concerns JSON from checkbox groups
        $concerns = [];
        foreach ($categories as $key => $cat) {
            $selected = $_POST['concerns'][$key] ?? [];
            $concerns[$key] = is_array($selected) ? array_map([Validator::class, 'clean'], $selected) : [];
            $othersKey = $key . '_others';
            $concerns[$othersKey] = Validator::clean($_POST['concerns_others'][$key] ?? '');
        }
        $concerns['other_concern'] = Validator::clean($_POST['other_concern'] ?? '');

        $hasAnyConcern = false;
        foreach ($concerns as $k => $v) {
            if (is_array($v) && !empty($v)) $hasAnyConcern = true;
            if (is_string($v) && trim($v) !== '') $hasAnyConcern = true;
        }
        if (!$hasAnyConcern) $errors[] = 'Please select or specify at least one concern in Section III.';

        $actionsTaken = [
            'items' => array_values(array_intersect($_POST['actions_taken'] ?? [], $actionsOptions)),
            'others' => Validator::clean($_POST['actions_taken_others'] ?? ''),
        ];

        if (!$errors) {
            $referralId = Referral::create([
                'department' => Validator::clean($_POST['department'] ?? ''),
                'referral_date' => $referralDate,
                'student_name' => $studentName,
                'student_id_number' => Validator::clean($_POST['student_id_number'] ?? ''),
                'grade_year_level' => Validator::clean($_POST['grade_year_level'] ?? ''),
                'section_course_program' => Validator::clean($_POST['section_course_program'] ?? ''),
                'sex' => Validator::clean($_POST['sex'] ?? ''),
                'student_contact' => Validator::clean($_POST['student_contact'] ?? ''),
                'referring_party_name' => $referringName,
                'referring_party_position' => Validator::clean($_POST['referring_party_position'] ?? ''),
                'referring_party_department' => Validator::clean($_POST['referring_party_department'] ?? ''),
                'referring_party_contact' => Validator::clean($_POST['referring_party_contact'] ?? ''),
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
            ReferralService::notifyNewReferral($referralId, $studentName, $referral['urgency_level'] === 'urgent');

            header('Location: ' . BASE_URL . '/referral-thankyou.php?ref=' . urlencode($referral['referral_no']));
            exit;
        }
    }
}

$pageTitle = 'Guidance Office Student Referral Form';
include __DIR__ . '/partials/header.php';
?>
<div class="card mb-4">
  <div class="card-header text-center">
    <h4 class="mb-0">The College of Maasin — Office of Guidance, Counseling, and Testing Services</h4>
    <div class="small mt-1">Guidance Office — Student Referral Form</div>
  </div>
  <div class="card-body">
    <p class="text-muted small">All counseling and consultation services require this completed form. No Referral, No Appointment, except in emergency cases.</p>

    <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <form method="post" novalidate>
      <?= Csrf::field() ?>

      <div class="row mb-4">
        <div class="col-md-4"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= htmlspecialchars($old['department'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="referral_date" class="form-control" value="<?= htmlspecialchars($old['referral_date'] ?? date('Y-m-d')) ?>" required></div>
      </div>

      <h5 class="mt-4">I. Student Information</h5>
      <div class="row g-3 mb-4">
        <div class="col-md-6"><label class="form-label">Student's Name *</label><input type="text" name="student_name" class="form-control" required value="<?= htmlspecialchars($old['student_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Student ID Number</label><input type="text" name="student_id_number" class="form-control" value="<?= htmlspecialchars($old['student_id_number'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Grade / Year Level</label><input type="text" name="grade_year_level" class="form-control" value="<?= htmlspecialchars($old['grade_year_level'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Section / Course / Program</label><input type="text" name="section_course_program" class="form-control" value="<?= htmlspecialchars($old['section_course_program'] ?? '') ?>"></div>
        <div class="col-md-4">
          <label class="form-label">Sex</label>
          <select name="sex" class="form-select">
            <option value="">-- Select --</option>
            <option value="Male" <?= ($old['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= ($old['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Contact Number / Email</label><input type="text" name="student_contact" class="form-control" value="<?= htmlspecialchars($old['student_contact'] ?? '') ?>"></div>
      </div>

      <h5 class="mt-4">II. Referring Party Information</h5>
      <div class="row g-3 mb-4">
        <div class="col-md-6"><label class="form-label">Name of Referring Party *</label><input type="text" name="referring_party_name" class="form-control" required value="<?= htmlspecialchars($old['referring_party_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Position / Relationship to Student</label><input type="text" name="referring_party_position" class="form-control" value="<?= htmlspecialchars($old['referring_party_position'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Department / Office (if applicable)</label><input type="text" name="referring_party_department" class="form-control" value="<?= htmlspecialchars($old['referring_party_department'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Contact Number / Email</label><input type="text" name="referring_party_contact" class="form-control" value="<?= htmlspecialchars($old['referring_party_contact'] ?? '') ?>"></div>
      </div>

      <h5 class="mt-4">III. Referral Information</h5>
      <p class="text-muted small">Please check all that apply and specify observed concerns.</p>
      <?php foreach ($categories as $key => $cat): ?>
        <div class="border rounded p-3 mb-3">
          <strong><?= htmlspecialchars($cat['label']) ?></strong>
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
        <strong>Other Concern (please specify)</strong>
        <p class="text-muted small mb-1">e.g., health-related issues, adjustment difficulties, or concerns not listed above</p>
        <input type="text" name="other_concern" class="form-control" value="<?= htmlspecialchars($old['other_concern'] ?? '') ?>">
      </div>

      <h5 class="mt-4">IV. Description of Behavior/Incident</h5>
      <div class="mb-4">
        <textarea name="description_of_incident" class="form-control" rows="4" placeholder="Please describe the observed behavior, situation, or incident(s) that prompted the referral"><?= htmlspecialchars($old['description_of_incident'] ?? '') ?></textarea>
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
          <label class="form-check-label" for="urgency_urgent">Urgent (requires immediate attention)</label>
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
          <label class="form-check-label" for="crisis_situation">Crisis situation</label>
        </div>
        <p class="text-muted small mt-2 mb-0">Cases marked urgent will be prioritized by the Guidance Office.</p>
      </div>

      <h5 class="mt-4">VII. Consent and Acknowledgement</h5>
      <div class="mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent_certified" value="1" id="consent_certified" required <?= !empty($old['consent_certified']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="consent_certified">
            I certify that the information provided above is accurate to the best of my knowledge. I understand that the Guidance Office will handle this referral in accordance with confidentiality and ethical standards.
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">Submit Referral</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
