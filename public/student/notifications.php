<?php
// Kept for backward-compatible links; notifications now live at a role-agnostic page.
require_once __DIR__ . '/../../config/constants.php';
header('Location: ' . BASE_URL . '/notifications.php');
exit;
