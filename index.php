<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    $loggedUser = current_user($pdo);
    if ($loggedUser && (int) ($loggedUser['pin_only_login'] ?? 0) === 1) {
        redirect('shopfloor.php');
    }

    redirect('dashboard.php');
}

redirect('login.php');
