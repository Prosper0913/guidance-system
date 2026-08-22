<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Referral.php';
require_once __DIR__ . '/../../src/Models/User.php';
require_once __DIR__ . '/../../src/Services/ReferralService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Helpers/Validator.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);
$id = (int)($_GET['id'] ?? 0);
$referral = Referral::findById($id);
if (!$referral) {
    http_response_code(404);
    die('Referral not found.');
}

$errors = [];
$studentResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::validate($_POST['csrf_token'] ?? null)) {
    if (isset($_POST['link_student_id'])) {
        Referral::linkStudent($id, (int)$_POST['link_student_id']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Referral linked to student account.'];
        header('Location: referral-view.php?id=' . $id);
        exit;
    }

    if (isset($_POST['process_referral'])) {
        // Counselor is auto-assigned based on student's education level — not changeable here
        $currentReferral = Referral::findById($id);
        Referral::process($id, [
            'status' => $_POST['status'],
            'assigned_counselor_id' => $currentReferral['assigned_counselor_id'],
            'office_remarks' => Validator::clean($_POST['office_remarks'] ?? ''),
        ], $user['id']);

        $updated = Referral::findById($id);
        ReferralService::notifyProcessed($updated);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Referral updated.'];
        header('Location: referral-view.php?id=' . $id);
        exit;
    }

    if (isset($_POST['schedule_appointment'])) {
        $apptDate = $_POST['appointment_date'] ?? '';
        $apptTime = $_POST['appointment_time'] ?? '';
        if (!$apptDate || !$apptTime) {
            $errors[] = 'Please select a date and time slot.';
        } else {
            try {
                ReferralService::convertToAppointment(
                    Referral::findById($id),
                    $apptDate,
                    $apptTime,
                    $user['id']
                );
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment scheduled from this referral.'];
                header('Location: appointments.php');
                exit;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    require_once __DIR__ . '/../../src/Services/NotificationService.php';

    if (isset($_POST['cancel_referral'])) {
        $currentReferral = Referral::findById($id);
        Referral::process($id, [
            'status' => 'cancelled',
            'assigned_counselor_id' => $currentReferral['assigned_counselor_id'],
            'office_remarks' => Validator::clean($_POST['office_remarks'] ?? ''),
        ], $user['id']);

        // Notify the student that their referral was cancelled
        $updated = Referral::findById($id);
        NotificationService::referralCancelled($updated);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Referral cancelled. The student has been notified.'];
        header('Location: appointments.php?tab=referrals');
        exit;
    }
}

if (!empty($_GET['search'])) {
    $studentResults = Referral::searchStudents(trim($_GET['search']));
}

$referral = Referral::findById($id); // reload fresh after any POST
// Counselor is auto-determined by student education level
//$initialActionOptions = Referral::initialActionOptions();
$statusLabels = [
    'pending' => 'Pending Review',
    'accepted' => 'Accepted',
    /*'for_clarification' => 'For Clarification',
    'referred_back' => 'Referred Back',*/
];

$pageTitle = 'Referral ' . ($referral['referral_no'] ?? '');
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="mb-0">Referral <?= htmlspecialchars($referral['referral_no'] ?? '') ?></h3>
  <?php if ($referral['urgency_level'] === 'urgent'): ?><span class="badge bg-danger">Urgent</span><?php endif; ?>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($referral['risk_self_harm'] || $referral['risk_harm_others'] || $referral['severe_emotional_distress'] || $referral['crisis_situation']): ?>
<div class="alert alert-danger">
  <strong>⚠ Flagged concerns:</strong>
  <?php
    $flags = [];
    if ($referral['risk_self_harm']) $flags[] = 'Risk of self-harm';
    if ($referral['risk_harm_others']) $flags[] = 'Risk of harm to others';
    if ($referral['severe_emotional_distress']) $flags[] = 'Severe emotional distress';
    if ($referral['crisis_situation']) $flags[] = 'Crisis situation';
    echo htmlspecialchars(implode(', ', $flags));
  ?>
</div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-header">Student Information</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th style="width:40%">Name</th><td><?= htmlspecialchars($referral['student_name']) ?></td></tr>
          <tr><th>Student ID</th><td><?= htmlspecialchars($referral['student_id_number'] ?? '—') ?></td></tr>
          <tr><th>Grade/Year Level</th><td><?= htmlspecialchars($referral['grade_year_level'] ?? '—') ?></td></tr>
          <tr><th>Section/Course/Program</th><td><?= htmlspecialchars($referral['section_course_program'] ?? '—') ?></td></tr>
          <tr><th>Sex</th><td><?= $referral['sex'] ? htmlspecialchars(ucfirst($referral['sex'])) : '—' ?></td></tr>
          <tr><th>Contact</th><td><?= htmlspecialchars($referral['student_contact'] ?? '—') ?></td></tr>
          <tr><th>System Account</th><td>
            <?php if ($referral['student_id']): ?>
              <span class="badge bg-success">Linked (ID <?= $referral['student_id'] ?>)</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Not linked</span>
            <?php endif; ?>
          </td></tr>
        </table>

        <?php if (!$referral['student_id']): ?>
          <hr>
          <p class="small text-muted mb-2">Link this referral to a registered student account to enable appointment scheduling.</p>
          <form method="get" class="d-flex gap-2 mb-2">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or ID number" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
          </form>
          <?php if ($studentResults): ?>
            <ul class="list-group">
              <?php foreach ($studentResults as $s): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?> (<?= htmlspecialchars($s['id_number']) ?>)</span>
                  <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="link_student_id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Link</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif (!empty($_GET['search'])): ?>
            <p class="text-muted small">No matching student accounts found.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($referral['preferred_type'] || $referral['preferred_counselor_id'] || $referral['preferred_date']): ?>
    <?php $conflictCount = Referral::countConflictingPreferences((int)$referral['id'], $referral['preferred_date'] ?? null, $referral['preferred_time'] ?? null, (int)($referral['assigned_counselor_id'] ?? 0) ?: null); ?>
    <div class="card mb-4">
      <div class="card-header">Student's Preferences</div>
      <div class="card-body">
        <p class="text-muted small mb-2">Submitted by the student as a soft preference — not a confirmed booking.</p>
        <?php if ($conflictCount > 0): ?>
          <div class="alert alert-warning py-2 small">
            ⚠ <?= $conflictCount ?> other student<?= $conflictCount > 1 ? 's' : '' ?> also prefer<?= $conflictCount > 1 ? '' : 's' ?> this exact date/time. Only one appointment can be scheduled for that slot.
            <a href="resolve-conflict.php?date=<?= urlencode($referral['preferred_date']) ?>&time=<?= urlencode($referral['preferred_time']) ?>" class="btn btn-sm btn-warning ms-2">Resolve Conflict</a>
          </div>
        <?php endif; ?>
        <table class="table table-sm mb-0">
          <?php if ($referral['preferred_type']): ?>
            <tr><th style="width:40%">Preferred Method</th><td><?= ucfirst($referral['preferred_type']) ?></td></tr>
          <?php endif; ?>
          <?php if ($referral['preferred_counselor_id']): ?>
            <tr><th>Assigned Counselor</th><td><?= htmlspecialchars(($referral['preferred_counselor_first'] ?? '') . ' ' . ($referral['preferred_counselor_last'] ?? '')) ?> <span class="text-muted small">(auto-assigned)</span></td></tr>
          <?php endif; ?>
          <?php if ($referral['preferred_date']): ?>
            <tr><th>Preferred Date</th><td><?= htmlspecialchars($referral['preferred_date']) ?></td></tr>
          <?php endif; ?>
          <?php if ($referral['preferred_time']): ?>
            <tr><th>Preferred Time</th><td><?= htmlspecialchars(date('g:i A', strtotime($referral['preferred_time']))) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
      <div class="card-header">Referring Party</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th style="width:40%">Name</th><td><?= htmlspecialchars($referral['referring_party_name']) ?></td></tr>
          <tr><th>Position/Relationship</th><td><?= htmlspecialchars($referral['referring_party_position'] ?? '—') ?></td></tr>
          <tr><th>Department/Office</th><td><?= htmlspecialchars($referral['referring_party_department'] ?? '—') ?></td></tr>
          <tr><th>Contact</th><td><?= htmlspecialchars($referral['referring_party_contact'] ?? '—') ?></td></tr>
          <tr><th>Date Submitted</th><td><?= htmlspecialchars($referral['referral_date']) ?></td></tr>
        </table>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Referral Concerns</div>
      <div class="card-body">
        <?php
        $catDefs = Referral::concernCategories();
        foreach ($catDefs as $key => $cat):
            $selected = $referral['concerns'][$key] ?? [];
            $others = $referral['concerns'][$key . '_others'] ?? '';
            if (!$selected && !$others) continue;
        ?>
          <div class="mb-2">
            <strong><?= htmlspecialchars($cat['label']) ?>:</strong>
            <?= htmlspecialchars(implode(', ', $selected)) ?>
            <?php if ($others): ?><br><em>Others: <?= htmlspecialchars($others) ?></em><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($referral['concerns']['other_concern'])): ?>
          <div class="mb-2"><strong>Other Concern:</strong> <?= htmlspecialchars($referral['concerns']['other_concern']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Description of Behavior/Incident</div>
      <div class="card-body"><?= nl2br(htmlspecialchars($referral['description_of_incident'] ?? '—')) ?></div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Actions Taken Prior to Referral</div>
      <div class="card-body">
        <?php
        $actions = $referral['actions_taken']['items'] ?? [];
        $actionsOthers = $referral['actions_taken']['others'] ?? '';
        ?>
        <?= $actions ? htmlspecialchars(implode(', ', $actions)) : '<span class="text-muted">None recorded</span>' ?>
        <?php if ($actionsOthers): ?><br><em>Others: <?= htmlspecialchars($actionsOthers) ?></em><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header">Guidance Office Processing</div>
      <div class="card-body">
        <form method="post">
          <?= Csrf::field() ?>
          <div class="mb-3">
            <label class="form-label">Referral Status</label>
            <?php if ($referral['status'] === 'accepted'): ?>
              <input type="text" class="form-control" readonly value="Accepted">
              <input type="hidden" name="status" value="accepted">
              <div class="form-text">This referral has been accepted and can no longer be reverted to pending.</div>
            <?php elseif ($referral['status'] === 'cancelled'): ?>
              <input type="text" class="form-control" readonly value="Cancelled">
              <input type="hidden" name="status" value="cancelled">
              <div class="form-text">This referral has been cancelled and can't be reopened, accepted, or scheduled. The student was notified.</div>
            <?php else: ?>
              <select name="status" class="form-select">
                <?php foreach ($statusLabels as $key => $label): ?>
                  <option value="<?= $key ?>" <?= $referral['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <!-- <div class="mb-3"> 
            <label class="form-label">Initial Action</label>
            <?php foreach ($initialActionOptions as $key => $label): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="initial_action[]" value="<?= $key ?>" id="ia_<?= $key ?>"
                  <?= in_array($key, $referral['initial_action'] ?? [], true) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="ia_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
              </div>
            <?php endforeach; ?>
          </div>-->
          <div class="mb-3">
            <label class="form-label">Assigned Guidance Advocate</label>
            <?php if ($referral['assigned_counselor_id']): ?>
              <input type="text" class="form-control" readonly
                value="<?= htmlspecialchars(($referral['counselor_first'] ?? '') . ' ' . ($referral['counselor_last'] ?? '')) ?>">
              <div class="form-text">Auto-assigned based on student's education level.</div>
            <?php else: ?>
              <input type="text" class="form-control" readonly value="Unassigned">
            <?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label">Remarks / Notes</label>
            <textarea name="office_remarks" class="form-control" rows="3"><?= htmlspecialchars($referral['office_remarks'] ?? '') ?></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" name="process_referral" class="btn btn-primary flex-fill">Save</button>
            <?php if ($referral['status'] === 'pending'): ?>
              <button type="submit" name="cancel_referral" class="btn btn-outline-danger" onclick="return confirm('Cancel this referral? This action cannot be undone.')">Cancel Referral</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <?php if ($referral['appointment_id']): ?>
      <div class="card mb-4">
        <div class="card-header">Linked Appointment</div>
        <div class="card-body">
          <p class="mb-0">An appointment has already been scheduled from this referral (Appointment #<?= $referral['appointment_id'] ?>).</p>
        </div>
      </div>
    <?php elseif ($referral['status'] === 'cancelled'): ?>
      <div class="card mb-4">
        <div class="card-body text-muted small">
          This referral was cancelled and can no longer be scheduled. The student has been notified and can submit a new referral, or pick a new time using the same details, from their My Requests page.
        </div>
      </div>
    <?php elseif ($referral['student_id'] && $referral['assigned_counselor_id'] && $referral['status'] === 'accepted'): ?>
      <div class="card mb-4">
        <div class="card-header">Schedule Appointment from Referral</div>
        <div class="card-body">
          <?php if ($referral['preferred_date'] && $referral['preferred_time']): ?>
            <p class="text-muted small mb-3">This is the date and time the student requested when submitting the referral. It is fixed and cannot be changed here.</p>
            <table class="table table-sm mb-3">
              <tr><th style="width:40%">Date</th><td><?= htmlspecialchars(date('F j, Y (l)', strtotime($referral['preferred_date']))) ?></td></tr>
              <tr><th>Time</th><td><?= htmlspecialchars(date('g:i A', strtotime($referral['preferred_time']))) ?></td></tr>
            </table>
            <form method="post" id="scheduleForm">
              <?= Csrf::field() ?>
              <input type="hidden" name="appointment_date" value="<?= htmlspecialchars($referral['preferred_date']) ?>">
              <input type="hidden" name="appointment_time" value="<?= htmlspecialchars($referral['preferred_time']) ?>">
              <button type="submit" name="schedule_appointment" class="btn btn-primary btn-sm w-100">Confirm &amp; Schedule Appointment</button>
            </form>
          <?php else: ?>
            <p class="text-muted small mb-0">The student did not specify a preferred date and time in their referral, so an appointment can't be scheduled from this page. Please coordinate a schedule with the student directly.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card mb-4">
        <div class="card-body text-muted small">
          Link this referral to a student account and assign a guidance advocate to enable appointment scheduling.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>