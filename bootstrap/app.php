<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_APP_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_APP_LOADED', true);

define('TASKFORCE_LEGACY_MODE', true);

require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/config.php';

$paths = (array) app_config('paths', []);
foreach ($paths as $path) {
    if (is_string($path) && $path !== '' && !is_dir($path)) {
        @mkdir($path, 0750, true);
    }
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

require_once dirname(__DIR__) . '/app/Services/RateLimiter.php';
require_once dirname(__DIR__) . '/app/Services/AuditLog.php';
require_once dirname(__DIR__) . '/app/Services/UploadService.php';
