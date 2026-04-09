<?php
require_once __DIR__ . '/helpers.php';
$logoutUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if ($logoutUserId) {
    $sessionLoginAt = trim((string) ($_SESSION['login_at'] ?? ''));
    $pendingAnnouncement = fetch_pending_shopfloor_announcement_ack($pdo, $logoutUserId, $sessionLoginAt);
    if ($pendingAnnouncement !== null) {
        redirect('shopfloor.php?announcement_ack_required=1');
    }
    log_app_event($pdo, $logoutUserId, 'auth.logout', 'Sessão terminada pelo utilizador.');
    AuditLog::write($pdo, $logoutUserId, 'auth.logout');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
redirect('login.php');
