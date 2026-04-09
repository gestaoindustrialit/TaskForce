<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_SECURITY_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_SECURITY_LOADED', true);

if (!function_exists('taskforce_apply_security_headers')) {
    function taskforce_apply_security_headers(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Content-Security-Policy: ' . app_config('security.csp'));
    }
}

if (!function_exists('taskforce_security_boot')) {
    function taskforce_security_boot(): void
    {
        ini_set('display_errors', app_config('debug') ? '1' : '0');
        ini_set('log_errors', '1');

        taskforce_apply_security_headers();

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'install.php' && app_config('security.install_enabled') !== true) {
            http_response_code(403);
            echo 'Instalação desativada em produção.';
            exit;
        }
    }
}

taskforce_security_boot();
