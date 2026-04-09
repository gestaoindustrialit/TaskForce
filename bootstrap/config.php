<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_CONFIG_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_CONFIG_LOADED', true);

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        static $config;

        if (!is_array($config)) {
            $root = dirname(__DIR__);
            $isProduction = (string) getenv('APP_ENV') === 'production';
            $config = [
                'app_name' => getenv('APP_NAME') ?: 'TaskForce',
                'env' => getenv('APP_ENV') ?: 'production',
                'debug' => (bool) ((int) (getenv('APP_DEBUG') ?: 0)),
                'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Lisbon',
                'db_path' => $root . '/database.sqlite',
                'session' => [
                    'name' => 'taskforce_session',
                    'lifetime' => 60 * 60 * 8,
                    'inactivity_timeout' => 60 * 30,
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
                'security' => [
                    'install_enabled' => !$isProduction,
                    'csp' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'",
                ],
                'paths' => [
                    'root' => $root,
                    'storage' => $root . '/storage',
                    'logs' => $root . '/storage/logs',
                    'uploads' => $root . '/storage/uploads',
                    'cache' => $root . '/storage/cache',
                    'backups' => $root . '/storage/backups',
                ],
            ];

            date_default_timezone_set((string) $config['timezone']);
        }

        if ($key === null) {
            return $config;
        }

        $segments = explode('.', $key);
        $value = $config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
