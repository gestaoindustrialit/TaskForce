<?php
require_once __DIR__ . '/helpers.php';
$logoutUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($logoutUserId) {
    log_app_event($pdo, $logoutUserId, 'auth.logout', 'Sessão terminada pelo utilizador.');
}
session_destroy();
redirect('login.php');
