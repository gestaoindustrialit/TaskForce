<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_AUTH_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_AUTH_LOADED', true);

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
    }
}

if (!function_exists('current_user')) {
    function current_user(?PDO $pdo = null): ?array
    {
        if (!is_logged_in()) {
            return null;
        }

        $pdo = $pdo ?? db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!is_logged_in()) {
            redirect('login.php');
        }

        $user = current_user(db());
        if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
            session_unset();
            session_destroy();
            redirect('login.php');
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_login();
        $user = current_user(db());
        if ((int) ($user['is_admin'] ?? 0) !== 1) {
            http_response_code(403);
            exit('Acesso negado.');
        }
    }
}
