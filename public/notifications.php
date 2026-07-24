<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Models/Notification.php';

$user = AuthMiddleware::requireLogin();
Notification::markAllRead($user['id']);
$notifications = Notification::forUser($user['id'], 50);
$pageTitle = 'Notifications';
include __DIR__ . '/partials/header.php';
?>
<h3 class="mb-4">Notifications</h3>
<div class="card">
  <div class="card-body">
    <?php if (!$notifications): ?>
      <p class="text-muted mb-0">No notifications yet.</p>
    <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
          <li class="list-group-item d-flex justify-content-between">
            <span><?= htmlspecialchars($n['message']) ?></span>
            <span class="text-muted small"><?= date('M j, g:i A', strtotime($n['sent_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>
