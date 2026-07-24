<?php
// Displays and clears a one-time flash message stored in the session.
if (!empty($_SESSION['flash'])):
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
  <?= htmlspecialchars($flash['message']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
