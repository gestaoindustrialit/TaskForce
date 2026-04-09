<?php
declare(strict_types=1);

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || $_SESSION['_csrf_token'] === '') {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('validate_csrf_or_abort')) {
    function validate_csrf_or_abort(bool $strict = true): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return true;
        }

        $submitted = (string) ($_POST['_token'] ?? '');
        $expected = (string) ($_SESSION['_csrf_token'] ?? '');
        $valid = $submitted !== '' && $expected !== '' && hash_equals($expected, $submitted);

        if ($valid) {
            return true;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $stmt = $GLOBALS['pdo']->prepare('INSERT INTO csrf_failures(user_id, endpoint, ip_address) VALUES (?, ?, ?)');
            $stmt->execute([
                isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                (string) ($_SERVER['REQUEST_URI'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
        }

        if ($strict) {
            http_response_code(419);
            exit('Pedido inválido (CSRF).');
        }

        return false;
    }
}
