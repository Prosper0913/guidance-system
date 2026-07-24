<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../src/Models/Referral.php';

$user = AuthMiddleware::requireRole([ROLE_COUNSELOR, ROLE_ADMIN]);
$filter = $_GET['status'] ?? '';

// Counselors see everything unassigned + their own; admins see everything (simpler: both see all, since triage is shared)
$referrals = Referral::all($filter ?: null);

$pageTitle = 'Referrals';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/flash.php';

$statusLabels = [
    'pending' => 'Pending Review',
    'accepted' => 'Accepted',
    'for_clarification' => 'For Clarification',
    'referred_back' => 'Referred Back',
];
?>
<h3 class="mb-4">Guidance Referrals</h3>

<div class="mb-3">
  <a class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?">All</a>
  <?php foreach ($statusLabels as $key => $label): ?>
    <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?status=<?= $key ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$referrals): ?>
      <p class="text-muted mb-0">No referrals found.</p>
    <?php else: ?>
      <table class="table align-middle">
        <thead><tr><th>Ref No.</th><th>Date</th><th>Student</th><th>Referred By</th><th>Urgency</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($referrals as $r): ?>
          <tr class="<?= $r['urgency_level'] === 'urgent' ? 'table-danger' : '' ?>">
            <td><?= htmlspecialchars($r['referral_no'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['referral_date']) ?></td>
            <td><?= htmlspecialchars($r['student_name']) ?><?= $r['student_id'] ? ' <span class="badge bg-success">Linked</span>' : ' <span class="badge bg-secondary">Unlinked</span>' ?></td>
            <td><?= htmlspecialchars($r['referring_party_name']) ?></td>
            <td><?= $r['urgency_level'] === 'urgent' ? '<span class="badge bg-danger">Urgent</span>' : '<span class="badge bg-secondary">Routine</span>' ?></td>
            <td><span class="badge bg-info text-dark"><?= $statusLabels[$r['status']] ?? ucfirst($r['status']) ?></span></td>
            <td><?= $r['counselor_first'] ? htmlspecialchars($r['counselor_first'] . ' ' . $r['counselor_last']) : '—' ?></td>
            <td><a href="referral-view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
