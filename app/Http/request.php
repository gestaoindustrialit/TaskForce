<?php
declare(strict_types=1);

if (!function_exists('post')) {
    function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('get')) {
    function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }
}
