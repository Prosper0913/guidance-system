<?php
// Expects $pageTitle to be set by the including page. $user available if logged in.
require_once __DIR__ . '/../../src/Models/Notification.php';
require_once __DIR__ . '/../../src/Services/ReminderService.php';
require_once __DIR__ . '/../../src/Helpers/Csrf.php';
$user = AuthMiddleware::currentUser();
if ($user) {
    ReminderService::run($user);
}
$unread = $user ? Notification::unreadCount($user['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> · <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <script>
      window.BASE_URL = '<?= BASE_URL ?>';
      <?php if ($user): ?>
      window.CSRF_TOKEN = '<?= Csrf::token() ?>';
      window.CURRENT_USER_ROLE = '<?= $user['role'] ?>';
      <?php endif; ?>
    </script>
    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= BASE_URL ?>/<?= $user['role'] ?>/dashboard.php"> <img src="<?= BASE_URL ?>/assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <?php if ($user['role'] === ROLE_STUDENT): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/student/dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/student/book-appointment.php">Request Appointment</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/student/my-appointments.php">My Appointments</a></li>
        <?php elseif ($user['role'] === ROLE_COUNSELOR): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/counselor/dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/counselor/appointments.php">Appointments</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/counselor/availability.php">Availability</a></li>
        <?php elseif ($user['role'] === ROLE_ADMIN): ?>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/counselor/appointments.php?tab=referrals">Referrals</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/manage-users.php">Users</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/reports.php">Reports</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/admin/audit-logs.php">Audit Logs</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item">
          <button type="button" class="nav-link position-relative border-0 bg-transparent" data-bs-toggle="offcanvas" data-bs-target="#notificationsSidebar" id="notifBellBtn">
            🔔 <span id="notifBadge" class="badge bg-danger" style="<?= $unread > 0 ? '' : 'display:none;' ?>"><?= $unread ?></span>
          </button>
        </li>
        <li class="nav-item"><span class="nav-link disabled"><?= htmlspecialchars($user['first_name']) ?> (<?= ucfirst($user['role']) ?>)</span></li>
        <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/logout.php">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- Notifications sidebar: slides in from the right, scrollable, loads more as you scroll
     down (infinite scroll) instead of dumping everything on a full page. -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="notificationsSidebar" aria-labelledby="notificationsSidebarLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="notificationsSidebarLabel">Notifications</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-0">
    <div class="px-3 pb-2 d-flex justify-content-end">
      <button type="button" class="btn btn-sm btn-link p-0" id="notifMarkAllReadBtn">Mark all as read</button>
    </div>
    <div id="notifList" class="flex-grow-1 overflow-auto px-3 pb-3"></div>
  </div>
</div>
<?php else: ?>
<nav class="navbar navbar-expand navbar-dark app-navbar">
  <div class="container-fluid">
    <span class="navbar-brand"><img src="assets/images/TCM logo (2).png" alt="TCM Logo" style="height: 50px;"> <?= APP_NAME ?></span>
    <ul class="navbar-nav ms-auto">
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/login.php">Login</a></li>
    </ul>
  </div>
</nav>
<?php endif; ?>
<main class="container py-4">