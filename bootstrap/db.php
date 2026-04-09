<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_DB_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_DB_LOADED', true);

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            taskforce_ensure_security_tables($pdo);
            return $pdo;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('A extensão pdo_sqlite não está ativa.');
        }

        $pdo = new PDO('sqlite:' . app_config('db_path'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $GLOBALS['pdo'] = $pdo;
        taskforce_ensure_security_tables($pdo);

        return $pdo;
    }
}

if (!function_exists('taskforce_ensure_security_tables')) {
    function taskforce_ensure_security_tables(PDO $pdo): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            ip_address TEXT,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            was_success INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier_time ON login_attempts(identifier, attempted_at)');

        $pdo->exec('CREATE TABLE IF NOT EXISTS csrf_failures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            endpoint TEXT,
            ip_address TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details_json TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
