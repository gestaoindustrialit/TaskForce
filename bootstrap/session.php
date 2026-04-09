<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_SESSION_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_SESSION_LOADED', true);

if (!function_exists('taskforce_start_session')) {
    function taskforce_start_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $session = app_config('session', []);
        session_name((string) ($session['name'] ?? 'taskforce_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($session['lifetime'] ?? 28800),
            'path' => '/',
            'domain' => '',
            'secure' => (bool) ($session['secure'] ?? false),
            'httponly' => (bool) ($session['httponly'] ?? true),
            'samesite' => (string) ($session['samesite'] ?? 'Lax'),
        ]);

        session_start();

        $now = time();
        $timeout = (int) ($session['inactivity_timeout'] ?? 1800);
        $lastSeen = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if ($now - $lastSeen > $timeout) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['_last_seen_at'] = $now;

        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = time();
        }
    }
}

taskforce_start_session();
