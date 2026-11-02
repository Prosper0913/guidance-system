<?php
// Manual backup for a counselor: ONLY their own appointments, referrals, notes and history, as Excel or SQL.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/AuditLog.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Services/BackupService.php';

// Counselors only. The counselor is always the logged-in user; nothing in the form can change whose data is exported.
$user = AuthMiddleware::requireRole([ROLE_COUNSELOR]);
$db = Database::getConnection();

$errors = [];
$form = ['from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'format' => 'xlsx'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['from'] = (string)($_POST['from'] ?? $form['from']);
    $form['to'] = (string)($_POST['to'] ?? $form['to']);
    $form['format'] = ($_POST['format'] ?? '') === 'sql' ? 'sql' : 'xlsx';

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $range = BackupService::parseRange($form['from'], $form['to']);
            $svc = new BackupService($db);
            $counts = $svc->counselorCounts((int)$user['id'], $range);
            if ($counts['appointments'] + $counts['referrals'] === 0) {
                $errors[] = 'You have no appointments or referrals between ' . $range[0] . ' and ' . $range[1] . '. Try a wider date range.';
            }
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        if (!$errors) {
            $base = 'my-records_' . BackupService::safeName((string)$user['last_name']) . '_' . $range[0] . '_to_' . $range[1];
            $summary = $counts['appointments'] . ' appointments, ' . $counts['referrals'] . ' referrals';
            $counselor = ['id' => (int)$user['id'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name']];

            if ($form['format'] === 'xlsx') {
                $file = XlsxWriter::build($svc->counselorSheets($counselor, $range));
                BackupService::startDownload($base . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Length: ' . strlen($file));
                echo $file;
            } else {
                BackupService::startDownload($base . '.sql', 'application/sql; charset=utf-8');
                try {
                    $svc->writeCounselorSql(function ($s) { echo $s; }, $counselor, $range);
                } catch (Throwable $e) {
                    error_log('Counselor backup failed: ' . $e->getMessage());
                    echo "\n-- BACKUP FAILED: this file is incomplete. Check the server error log.\n";
                }
            }
            try {
                AuditLog::record((int)$user['id'], 'backup_exported', null, null,
                    'Own records (' . $form['format'] . ') ' . $range[0] . ' to ' . $range[1] . ': ' . $summary);
            } catch (Throwable $e) { /* never fail on logging */ }
            exit;
        }
    }
}

$stmt = $db->prepare("SELECT created_at, details FROM audit_logs WHERE action = 'backup_exported' AND user_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([(int)$user['id']]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Backup';
include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-1">Backup My Records</h3>
<p class="text-muted">Download your own appointments, referrals and notes for a date range. A file is created only when you press the button.</p>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Create a backup</div>
      <div class="card-body">
        <form method="post" novalidate>
          <?= Csrf::field() ?>
          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label class="form-label small mb-0">From</label>
              <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($form['from']) ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-0">To</label>
              <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($form['to']) ?>" required>
            </div>
          </div>
          <div class="mb-1 small text-muted">Format</div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="radio" name="format" id="fmtX" value="xlsx" <?= $form['format'] === 'xlsx' ? 'checked' : '' ?>>
            <label class="form-check-label" for="fmtX"><strong>Excel (.xlsx)</strong> <span class="small text-muted">&ndash; easy to read, filter and print</span></label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="format" id="fmtS" value="sql" <?= $form['format'] === 'sql' ? 'checked' : '' ?>>
            <label class="form-check-label" for="fmtS"><strong>SQL (.sql)</strong> <span class="small text-muted">&ndash; a data file that can be imported back into the system</span></label>
          </div>
          <button type="submit" class="btn btn-primary">Download backup</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">What's included</div>
      <div class="card-body small">
        <ul class="ps-3 mb-2">
          <li>Appointments assigned to <strong>you</strong>, with each student's ID, name and course</li>
          <li>The status history of those appointments</li>
          <li>Your own session notes</li>
          <li>Referrals assigned to you</li>
        </ul>
        <p class="mb-0 text-muted">Dates are matched on the appointment date (and the referral date for referrals). Nothing belonging to other counselors is ever included. The file holds confidential student information, so keep it private.</p>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header">My recent backups</div>
  <div class="card-body">
    <?php if (!$history): ?>
      <p class="text-muted mb-0">You haven't made a backup yet.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>When</th><th>What</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr><td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td><td><?= htmlspecialchars($h['details'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
