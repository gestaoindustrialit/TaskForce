<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_HELPERS_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_HELPERS_LOADED', true);

require_once dirname(__DIR__) . '/app/Http/request.php';
require_once dirname(__DIR__) . '/app/Http/response.php';
require_once dirname(__DIR__) . '/app/Http/csrf.php';

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return e($value);
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }

        if (!isset($_SESSION['_flash'][$key])) {
            return null;
        }

        $value = (string) $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        return (string) ($_POST[$key] ?? $default);
    }
}
