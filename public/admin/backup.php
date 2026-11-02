<?php
// Manual database backup for admins: whole database (restore point) or a date range.
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/AuditLog.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
require_once __DIR__ . '/../../src/Services/BackupService.php';

$user = AuthMiddleware::requireRole([ROLE_ADMIN]);
$db = Database::getConnection();

$errors = [];
$form = ['mode' => 'whole', 'from' => date('Y-m-01'), 'to' => date('Y-m-t'), 'include_secrets' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['mode'] = ($_POST['mode'] ?? '') === 'range' ? 'range' : 'whole';
    $form['from'] = (string)($_POST['from'] ?? $form['from']);
    $form['to'] = (string)($_POST['to'] ?? $form['to']);
    $form['include_secrets'] = !empty($_POST['include_secrets']);

    if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $range = null;
        if ($form['mode'] === 'range') {
            try {
                $range = BackupService::parseRange($form['from'], $form['to']);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            $svc = new BackupService($db);
            $label = $range ? $range[0] . '_to_' . $range[1] : 'full_' . date('Y-m-d_His');
            $scope = $range ? "dates {$range[0]} to {$range[1]}" : 'whole database';
            $by = trim($user['first_name'] . ' ' . $user['last_name']);

            BackupService::startDownload('guidance-backup_' . $label . '.sql', 'application/sql; charset=utf-8');
            try {
                $stats = $svc->writeAdminSql(function ($s) { echo $s; }, $form['mode'], $range, $form['include_secrets'], $by);
                try {
                    AuditLog::record((int)$user['id'], 'backup_exported', null, null,
                        'Admin SQL backup: ' . $scope . ($form['include_secrets'] ? ' (incl. Google tokens)' : '') . ', ' . array_sum($stats) . ' rows');
                } catch (Throwable $e) { /* the file is already delivered; never fail on logging */ }
            } catch (Throwable $e) {
                error_log('Backup failed: ' . $e->getMessage());
                echo "\n-- BACKUP FAILED: this file is incomplete. Check the server error log.\n";
            }
            exit;
        }
    }
}

$history = $db->query(
    "SELECT al.created_at, al.details, u.first_name, u.last_name
     FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
     WHERE al.action = 'backup_exported' ORDER BY al.id DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Backup';
include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-1">Database Backup</h3>
<p class="text-muted">Download a copy of the system's data as a <code>.sql</code> file. Nothing is scheduled; a backup is made only when you press the button.</p>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Create a backup</div>
      <div class="card-body">
        <form method="post" id="backupForm" novalidate>
          <?= Csrf::field() ?>

          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="mode" id="modeWhole" value="whole" <?= $form['mode'] === 'whole' ? 'checked' : '' ?>>
            <label class="form-check-label" for="modeWhole"><strong>Whole database</strong><br>
              <span class="small text-muted">Everything: accounts, appointments, referrals, notes, availability, logs. This is a full restore point.</span></label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="mode" id="modeRange" value="range" <?= $form['mode'] === 'range' ? 'checked' : '' ?>>
            <label class="form-check-label" for="modeRange"><strong>Date range</strong><br>
              <span class="small text-muted">Only appointments, referrals, notes, notifications and audit logs inside the dates below. Accounts, availability and categories are always included.</span></label>
          </div>

          <div class="row g-2 mb-3" id="rangeFields">
            <div class="col-sm-6">
              <label class="form-label small mb-0">From</label>
              <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($form['from']) ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-0">To</label>
              <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($form['to']) ?>">
            </div>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="include_secrets" id="inclSecrets" value="1" <?= $form['include_secrets'] ? 'checked' : '' ?>>
            <label class="form-check-label small" for="inclSecrets">Also include counselors' Google Calendar sign-in tokens
              <span class="text-danger">(sensitive; leave unchecked unless you need them)</span></label>
          </div>

          <button type="submit" class="btn btn-primary">Download backup (.sql)</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Good to know</div>
      <div class="card-body small">
        <ul class="mb-2 ps-3">
          <li>The file contains <strong>personal data and password hashes</strong>. Keep it somewhere private and never email it or put it on a shared drive.</li>
          <li><strong>Whole database</strong> backups replace everything when imported (phpMyAdmin &rarr; Import). Take a fresh one right before importing any file.</li>
          <li><strong>Date range</strong> backups are safe to import into the live system: nothing is dropped and existing rows are skipped.</li>
          <li>Without the tokens, counselors simply reconnect Google Calendar after a restore.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header">Recent backups</div>
  <div class="card-body">
    <?php if (!$history): ?>
      <p class="text-muted mb-0">No backups have been made yet.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>When</th><th>By</th><th>What</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($h['created_at']))) ?></td>
            <td><?= htmlspecialchars(trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? '')) ?: 'Unknown') ?></td>
            <td><?= htmlspecialchars($h['details'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<script>
  (function () {
    var whole = document.getElementById('modeWhole'), range = document.getElementById('modeRange');
    var fields = document.getElementById('rangeFields');
    function sync() {
      var on = range.checked;
      fields.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
      fields.style.opacity = on ? 1 : .45;
    }
    whole.addEventListener('change', sync); range.addEventListener('change', sync); sync();
    document.getElementById('backupForm').addEventListener('submit', function (e) {
      if (document.getElementById('inclSecrets').checked &&
          !confirm('This file will contain Google sign-in tokens that give access to counselors\' calendars. Keep it very private. Continue?')) {
        e.preventDefault();
      }
    });
  })();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
