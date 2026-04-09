<?php
declare(strict_types=1);

if (defined('TASKFORCE_ERROR_HANDLERS_REGISTERED')) {
    return;
}
define('TASKFORCE_ERROR_HANDLERS_REGISTERED', true);

if (!function_exists('taskforce_error_id')) {
    function taskforce_error_id(): string
    {
        try {
            return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        } catch (Throwable $exception) {
            return strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 12));
        }
    }
}

if (!function_exists('safe_log')) {
    function safe_log(string $message, array $context = []): void
    {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            }
        }

        error_log($line);
        @file_put_contents($logDir . '/app.log', $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('taskforce_render_internal_error')) {
    function taskforce_render_internal_error(string $errorId): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<h1>Erro interno (500)</h1>';
        echo '<p>Ocorreu um erro inesperado.</p>';
        echo '<p>Referência: <code>' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</code></p>';
        exit;
    }
}

set_exception_handler(static function (Throwable $exception): void {
    $errorId = taskforce_error_id();
    safe_log('Uncaught exception', [
        'error_id' => $errorId,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    taskforce_render_internal_error($errorId);
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $errorId = taskforce_error_id();
    safe_log('Fatal error', [
        'error_id' => $errorId,
        'message' => (string) ($lastError['message'] ?? 'unknown'),
        'file' => (string) ($lastError['file'] ?? 'unknown'),
        'line' => (int) ($lastError['line'] ?? 0),
    ]);
    taskforce_render_internal_error($errorId);
});
