<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
AuthMiddleware::start();

$refNo = $_GET['ref'] ?? '';
$pageTitle = 'Referral Submitted';
include __DIR__ . '/partials/header.php';
?>
<div class="card auth-card" style="max-width: 520px;">
  <div class="card-body text-center p-4">
    <div style="font-size: 3rem;">✅</div>
    <h4 class="mt-2">Referral Submitted</h4>
    <p class="text-muted">The Guidance Office has been notified and will review this referral.</p>
    <?php if ($refNo): ?>
      <p>Reference Number:</p>
      <h3 class="text-success"><?= htmlspecialchars($refNo) ?></h3>
      <p class="text-muted small">Please keep this number for your records.</p>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/referral-form.php" class="btn btn-outline-primary mt-3">Submit Another Referral</a>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
