<?php
// This page used to be a direct counselor+slot picker. Per the school's actual referral
// policy ("No Referral, No Appointment, except in emergency cases"), booking now works by
// submitting a Guidance Referral — the same intake used for teacher/staff referrals — which
// the Guidance Office then reviews and schedules (see counselor/referral-view.php).
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
$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please resubmit the form.';
    } else {
        // Build concerns JSON from checkbox groups
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
        if (!$hasAnyConcern) $errors[] = 'Please select or specify at least one concern.';
        if (empty($_POST['consent_certified'])) $errors[] = 'You must certify the information and accept the confidentiality terms.';

        if (!$errors) {
            $referralId = Referral::create([
                'department' => $profile['course'] ?? null,
                'referral_date' => date('Y-m-d'),
                'student_id' => $user['id'],
                'student_name' => $user['first_name'] . ' ' . $user['last_name'],
                'student_id_number' => $user['id_number'] ?? null,
                'grade_year_level' => $profile['year_level'] ?? null,
                'section_course_program' => $profile['course'] ?? null,
                'sex' => null,
                'student_contact' => $user['contact_number'] ?? $user['email'],
                'preferred_type' => in_array($_POST['preferred_type'] ?? '', ['walk-in', 'online'], true) ? $_POST['preferred_type'] : null,
                'preferred_counselor_id' => (int)($_POST['preferred_counselor_id'] ?? 0) ?: null,
                'preferred_date' => !empty($_POST['preferred_date']) ? $_POST['preferred_date'] : null,
                'referring_party_name' => $user['first_name'] . ' ' . $user['last_name'],
                'referring_party_position' => 'Self-Referral (Student)',
                'referring_party_department' => $profile['course'] ?? null,
                'referring_party_contact' => $user['contact_number'] ?? $user['email'],
                'concerns' => $concerns,
                'description_of_incident' => trim($_POST['description_of_incident'] ?? ''),
                'actions_taken' => ['items' => [], 'others' => ''],
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

$pageTitle = 'Request Guidance Appointment';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<h3 class="mb-1">Request a Guidance Appointment</h3>
<p class="text-muted">Tell us what's going on and the Guidance Office will review and schedule your appointment.</p>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" novalidate>
      <?= Csrf::field() ?>

      <h5>What's this about?</h5>
      <p class="text-muted small">Check all that apply and add any details.</p>
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
        <strong>Something else not listed above?</strong>
        <input type="text" name="other_concern" class="form-control mt-2" value="<?= htmlspecialchars($old['other_concern'] ?? '') ?>">
      </div>

      <h5 class="mt-4">Tell us more (optional)</h5>
      <div class="mb-4">
        <textarea name="description_of_incident" class="form-control" rows="4" placeholder="Anything you'd like the counselor to know beforehand"><?= htmlspecialchars($old['description_of_incident'] ?? '') ?></textarea>
      </div>

      <h5 class="mt-4">Your Preferences (optional)</h5>
      <p class="text-muted small">These help the Guidance Office schedule you — the final date/time will be confirmed once your request is reviewed.</p>
      <div class="row g-3 mb-4">
        <!--<div class="col-md-4">
          <label class="form-label">Preferred Method</label>
          <select name="preferred_type" class="form-select">
            <option value="">No preference</option>
            <option value="online" <?= ($old['preferred_type'] ?? '') === 'online' ? 'selected' : '' ?>>Online</option>
            <option value="walk-in" <?= ($old['preferred_type'] ?? '') === 'walk-in' ? 'selected' : '' ?>>Walk-in</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Preferred Counselor</label>
          <select name="preferred_counselor_id" class="form-select">
            <option value="">No preference</option>
            <?php foreach ($counselors as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)($old['preferred_counselor_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>-->
        <div class="col-md-4">
          <label class="form-label">Preferred Date</label>
          <input type="date" name="preferred_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['preferred_date'] ?? '') ?>">
        </div>
      </div>

      <h5 class="mt-4">Urgency</h5>
      <div class="mb-4">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="urgency_level" value="routine" id="urgency_routine" <?= ($old['urgency_level'] ?? 'routine') !== 'urgent' ? 'checked' : '' ?>>
          <label class="form-check-label" for="urgency_routine">Routine</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="urgency_level" value="urgent" id="urgency_urgent" <?= ($old['urgency_level'] ?? '') === 'urgent' ? 'checked' : '' ?>>
          <label class="form-check-label" for="urgency_urgent">Urgent — I need this addressed soon</label>
        </div>

        <label class="form-label d-block mt-3">Does any of this apply right now? <span class="text-muted small">(this helps us prioritize your request — it's okay to leave unchecked)</span></label>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="risk_self_harm" value="1" id="risk_self_harm" <?= !empty($old['risk_self_harm']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="risk_self_harm">Thoughts of self-harm</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="severe_emotional_distress" value="1" id="severe_emotional_distress" <?= !empty($old['severe_emotional_distress']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="severe_emotional_distress">Severe emotional distress</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="crisis_situation" value="1" id="crisis_situation" <?= !empty($old['crisis_situation']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="crisis_situation">This feels like a crisis situation</label>
        </div>

        <div id="crisisNotice" class="alert alert-warning mt-3" style="display:none;">
          <strong>If you need to talk to someone right now, please don't wait for this form to be reviewed:</strong>
          <ul class="mb-0 mt-1">
            <li>Walk in directly to the Guidance Office during office hours — no appointment needed for urgent concerns</li>
            <li>National Center for Mental Health Crisis Hotline: <strong>1553</strong> (toll-free landline) or <strong>0966-351-4518 / 0917-899-8727</strong></li>
            <li>If this is a medical emergency, call <strong>911</strong> or go to the nearest emergency room</li>
          </ul>
        </div>
      </div>

      <div class="mb-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent_certified" value="1" id="consent_certified" required <?= !empty($old['consent_certified']) ? 'checked' : '' ?>>
          <label class="form-check-label small">
            I certify that the information provided above is accurate to the best of my knowledge, and I understand the Guidance Office will handle this in accordance with confidentiality and ethical standards.
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">Submit Request</button>
    </form>
  </div>
</div>

<script>
function toggleCrisisNotice() {
  const anyChecked = ['risk_self_harm', 'crisis_situation'].some(id => document.getElementById(id).checked);
  document.getElementById('crisisNotice').style.display = anyChecked ? 'block' : 'none';
}
['risk_self_harm', 'crisis_situation'].forEach(id => {
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
