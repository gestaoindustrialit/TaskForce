<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Lisbon');

if (!function_exists('taskforce_error_id')) {
    function taskforce_error_id(): string
    {
        try {
            if (function_exists('random_bytes')) {
                return strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
            }
        } catch (Throwable $exception) {
            // Fallback below.
        }

        return strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 12));
    }
}

if (!function_exists('taskforce_log_bootstrap_error')) {
    function taskforce_log_bootstrap_error(string $message): void
    {
        error_log($message);

        $logLine = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents(__DIR__ . '/bootstrap_errors.log', $logLine, FILE_APPEND);
    }
}

if (!function_exists('taskforce_render_internal_error')) {
    function taskforce_render_internal_error(string $errorId): void
    {
        $forceVisibleError = isset($_GET['tf_debug_error']) && $_GET['tf_debug_error'] === '1';

        if (!headers_sent()) {
            http_response_code($forceVisibleError ? 200 : 500);
            header('Content-Type: text/html; charset=UTF-8');
            if ($forceVisibleError) {
                header('X-TaskForce-Debug-Error: ' . $errorId);
            }
        }

        echo '<h1>Erro interno (500)</h1>';
        echo '<p>Ocorreu um erro inesperado ao processar o pedido.</p>';
        if ($forceVisibleError) {
            echo '<p><strong>Modo diagnóstico ativo:</strong> status HTTP devolvido como 200 para evitar página genérica do servidor.</p>';
        }
        echo '<p>Referência do erro: <code>' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</code></p>';
        exit;
    }
}

if (!function_exists('taskforce_sqlite_index_exists')) {
    function taskforce_sqlite_index_exists(PDO $pdo, string $indexName): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ? LIMIT 1");
        $statement->execute([$indexName]);

        return (bool) $statement->fetchColumn();
    }
}

if (!defined('TASKFORCE_ERROR_HANDLERS_REGISTERED')) {
    define('TASKFORCE_ERROR_HANDLERS_REGISTERED', true);

    set_exception_handler(static function (Throwable $exception): void {
        try {
            $errorId = taskforce_error_id();
            taskforce_log_bootstrap_error('[TaskForce][' . $errorId . '] Exceção não tratada: ' . $exception->getMessage() . ' em ' . $exception->getFile() . ':' . $exception->getLine());
            taskforce_render_internal_error($errorId);
        } catch (Throwable $handlerException) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo 'Erro interno (500).';
            exit;
        }
    });

    register_shutdown_function(static function (): void {
        try {
            $lastError = error_get_last();
            if ($lastError === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
                return;
            }

            $errorId = taskforce_error_id();
            $message = $lastError['message'] ?? 'Erro fatal desconhecido';
            $file = $lastError['file'] ?? 'ficheiro desconhecido';
            $line = (int) ($lastError['line'] ?? 0);

            taskforce_log_bootstrap_error('[TaskForce][' . $errorId . '] Erro fatal: ' . $message . ' em ' . $file . ':' . $line);
            taskforce_render_internal_error($errorId);
        } catch (Throwable $handlerException) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo 'Erro interno (500).';
            exit;
        }
    });
}

$dbFile = __DIR__ . '/database.sqlite';
if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Erro de configuração</h1>';
    echo '<p>A extensão <code>pdo_sqlite</code> não está ativa neste servidor.</p>';
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
} catch (Throwable $exception) {
    error_log('[TaskForce] Falha ao iniciar base de dados SQLite: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Erro de configuração</h1>';
    echo '<p>Não foi possível iniciar a base de dados SQLite.</p>';
    echo '<p>Verifique permissões de escrita para o ficheiro <code>database.sqlite</code> e para a pasta da aplicação.</p>';
    exit;
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        is_admin INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);

$userColumns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('is_admin', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0');
}
if (!in_array('username', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN username TEXT');
}
if (!in_array('access_profile', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN access_profile TEXT DEFAULT "Utilizador"');
}
if (!in_array('is_active', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1');
}
if (!in_array('must_change_password', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INTEGER DEFAULT 0');
}
if (!in_array('user_type', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT "Funcionário"');
}
if (!in_array('user_number', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN user_number TEXT');
}
if (!in_array('title', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN title TEXT');
}
if (!in_array('short_name', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN short_name TEXT');
}
if (!in_array('initials', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN initials TEXT');
}
if (!in_array('email_notifications_active', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN email_notifications_active INTEGER DEFAULT 1');
}
if (!in_array('sms_notifications_active', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN sms_notifications_active INTEGER DEFAULT 0');
}
if (!in_array('profession', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN profession TEXT');
}
if (!in_array('category', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN category TEXT');
}
if (!in_array('manager_name', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN manager_name TEXT');
}
if (!in_array('department', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN department TEXT');
}
if (!in_array('hire_date', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN hire_date TEXT');
}
if (!in_array('termination_date', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN termination_date TEXT');
}
if (!in_array('timezone', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN timezone TEXT DEFAULT "Europe/Lisbon"');
}
if (!in_array('phone', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN phone TEXT');
}
if (!in_array('mobile', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN mobile TEXT');
}
if (!in_array('notes', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN notes TEXT');
}
if (!in_array('send_access_email', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN send_access_email INTEGER DEFAULT 0');
}
if (!in_array('department_id', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN department_id INTEGER');
}
if (!in_array('schedule_id', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN schedule_id INTEGER');
}
if (!in_array('pin_code_hash', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN pin_code_hash TEXT');
}
if (!in_array('pin_code', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN pin_code TEXT');
}
if (!in_array('pin_only_login', $userColumns, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN pin_only_login INTEGER DEFAULT 0');
}

$pdo->exec('UPDATE users SET username = email WHERE username IS NULL OR TRIM(username) = ""');
$pdo->exec('UPDATE users SET access_profile = "Utilizador" WHERE access_profile IS NULL OR TRIM(access_profile) = ""');
$pdo->exec('UPDATE users SET is_active = 1 WHERE is_active IS NULL');
$pdo->exec('UPDATE users SET must_change_password = 0 WHERE must_change_password IS NULL');
$pdo->exec('UPDATE users SET user_type = "Funcionário" WHERE user_type IS NULL OR TRIM(user_type) = ""');
$pdo->exec('UPDATE users SET email_notifications_active = 1 WHERE email_notifications_active IS NULL');
$pdo->exec('UPDATE users SET sms_notifications_active = 0 WHERE sms_notifications_active IS NULL');
$pdo->exec('UPDATE users SET timezone = "Europe/Lisbon" WHERE timezone IS NULL OR TRIM(timezone) = ""');
$pdo->exec('UPDATE users SET send_access_email = 0 WHERE send_access_email IS NULL');
$pdo->exec('UPDATE users SET pin_only_login = 0 WHERE pin_only_login IS NULL');

$shopfloorEmail = 'shopfloor@calcadacorp.ch';
$shopfloorLookupStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) OR LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1');
$shopfloorLookupStmt->execute([$shopfloorEmail, $shopfloorEmail]);
$shopfloorUserId = (int) $shopfloorLookupStmt->fetchColumn();
if ($shopfloorUserId <= 0) {
    $shopfloorInsert = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, pin_code_hash, pin_only_login) VALUES (?, ?, ?, ?, 0, "Utilizador", 1, ?, 1)');
    $shopfloorInsert->execute([
        'Shopfloor',
        $shopfloorEmail,
        $shopfloorEmail,
        password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
        password_hash('123456', PASSWORD_DEFAULT),
    ]);
} else {
    $shopfloorUpdate = $pdo->prepare('UPDATE users SET name = COALESCE(NULLIF(TRIM(name), ""), ?), username = ?, email = ?, access_profile = "Utilizador", is_active = 1, pin_only_login = 1, pin_code_hash = COALESCE(pin_code_hash, ?) WHERE id = ?');
    $shopfloorUpdate->execute([
        'Shopfloor',
        $shopfloorEmail,
        $shopfloorEmail,
        password_hash('123456', PASSWORD_DEFAULT),
        $shopfloorUserId,
    ]);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_department_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        group_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(group_id) REFERENCES hr_department_groups(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_schedules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        start_time TEXT NOT NULL,
        end_time TEXT NOT NULL,
        second_start_time TEXT,
        second_end_time TEXT,
        break_minutes INTEGER NOT NULL DEFAULT 0,
        weekdays_mask TEXT NOT NULL DEFAULT "1,2,3,4,5",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);

$hrSchedulesColumnsStmt = $pdo->query('PRAGMA table_info(hr_schedules)');
$hrSchedulesColumns = array_column($hrSchedulesColumnsStmt->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('second_start_time', $hrSchedulesColumns, true)) {
    $pdo->exec('ALTER TABLE hr_schedules ADD COLUMN second_start_time TEXT');
}
if (!in_array('second_end_time', $hrSchedulesColumns, true)) {
    $pdo->exec('ALTER TABLE hr_schedules ADD COLUMN second_end_time TEXT');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_vacation_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "Aprovado",
        notes TEXT,
        created_by INTEGER,
        total_days REAL NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$vacationEventColumns = $pdo->query('PRAGMA table_info(hr_vacation_events)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('total_days', $vacationEventColumns, true)) {
    $pdo->exec('ALTER TABLE hr_vacation_events ADD COLUMN total_days REAL NOT NULL DEFAULT 0');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_vacation_balances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        year INTEGER NOT NULL,
        assigned_days REAL NOT NULL DEFAULT 0,
        employee_number TEXT,
        shift_time TEXT NOT NULL DEFAULT "08:00",
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_by INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, year),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        alert_type TEXT NOT NULL,
        recipient_email TEXT NOT NULL,
        send_time TEXT NOT NULL,
        weekdays_mask TEXT NOT NULL DEFAULT "1,2,3,4,5",
        schedule_frequency TEXT NOT NULL DEFAULT "weekly",
        monthly_day INTEGER NOT NULL DEFAULT 1,
        selected_user_ids TEXT NOT NULL DEFAULT "",
        last_sent_at DATETIME,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$hrAlertColumns = $pdo->query('PRAGMA table_info(hr_alerts)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('schedule_frequency', $hrAlertColumns, true)) {
    $pdo->exec('ALTER TABLE hr_alerts ADD COLUMN schedule_frequency TEXT NOT NULL DEFAULT "weekly"');
}
if (!in_array('monthly_day', $hrAlertColumns, true)) {
    $pdo->exec('ALTER TABLE hr_alerts ADD COLUMN monthly_day INTEGER NOT NULL DEFAULT 1');
}
if (!in_array('selected_user_ids', $hrAlertColumns, true)) {
    $pdo->exec('ALTER TABLE hr_alerts ADD COLUMN selected_user_ids TEXT NOT NULL DEFAULT ""');
}
if (!in_array('last_sent_at', $hrAlertColumns, true)) {
    $pdo->exec('ALTER TABLE hr_alerts ADD COLUMN last_sent_at DATETIME');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_hour_banks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        balance_hours REAL NOT NULL DEFAULT 0,
        notes TEXT,
        updated_by INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);


$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_bh_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        work_date TEXT NOT NULL,
        bh_minutes INTEGER NOT NULL,
        reason TEXT,
        updated_by INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, work_date),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_bh_override_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        work_date TEXT NOT NULL,
        previous_bh_minutes INTEGER,
        new_bh_minutes INTEGER NOT NULL,
        reason TEXT,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_time_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        entry_type TEXT NOT NULL,
        note TEXT,
        validated_by INTEGER,
        validated_at DATETIME,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(validated_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$timeEntryColumns = $pdo->query('PRAGMA table_info(shopfloor_time_entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('validated_by', $timeEntryColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_time_entries ADD COLUMN validated_by INTEGER');
}
if (!in_array('validated_at', $timeEntryColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_time_entries ADD COLUMN validated_at DATETIME');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_calendar_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        event_type TEXT NOT NULL DEFAULT "Feriado",
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        color TEXT NOT NULL DEFAULT "#d63384",
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hr_hour_bank_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        delta_minutes INTEGER NOT NULL,
        reason TEXT NOT NULL,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$hourBankLogColumnsStmt = $pdo->query('PRAGMA table_info(hr_hour_bank_logs)');
$hourBankLogColumns = $hourBankLogColumnsStmt ? $hourBankLogColumnsStmt->fetchAll(PDO::FETCH_COLUMN, 1) : [];
if (!in_array('action_type', $hourBankLogColumns, true)) {
    try {
        $pdo->exec("ALTER TABLE hr_hour_bank_logs ADD COLUMN action_type TEXT NOT NULL DEFAULT 'credito'");
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}
if (!in_array('action_date', $hourBankLogColumns, true)) {
    try {
        $pdo->exec('ALTER TABLE hr_hour_bank_logs ADD COLUMN action_date TEXT');
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_absence_reasons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reason_type TEXT NOT NULL DEFAULT "Ausência",
        code TEXT,
        reason_code TEXT,
        sage_code TEXT,
        label TEXT NOT NULL UNIQUE,
        color TEXT NOT NULL DEFAULT "#2563eb",
        show_in_shopfloor INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$absenceReasonColumns = $pdo->query('PRAGMA table_info(shopfloor_absence_reasons)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('code', $absenceReasonColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_absence_reasons ADD COLUMN code TEXT');
}
if (!in_array('reason_code', $absenceReasonColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_absence_reasons ADD COLUMN reason_code TEXT');
}
if (!in_array('sage_code', $absenceReasonColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_absence_reasons ADD COLUMN sage_code TEXT');
}
if (!in_array('color', $absenceReasonColumns, true)) {
    $pdo->exec("ALTER TABLE shopfloor_absence_reasons ADD COLUMN color TEXT NOT NULL DEFAULT '#2563eb'");
}
if (!in_array('reason_type', $absenceReasonColumns, true)) {
    $pdo->exec("ALTER TABLE shopfloor_absence_reasons ADD COLUMN reason_type TEXT NOT NULL DEFAULT 'Ausência'");
}
if (!in_array('show_in_shopfloor', $absenceReasonColumns, true)) {
    $pdo->exec('ALTER TABLE shopfloor_absence_reasons ADD COLUMN show_in_shopfloor INTEGER NOT NULL DEFAULT 1');
}

$pdo->exec("UPDATE shopfloor_absence_reasons SET color = '#2563eb' WHERE color IS NULL OR TRIM(color) = ''");
$pdo->exec("UPDATE shopfloor_absence_reasons SET reason_type = 'Ausência' WHERE reason_type IS NULL OR TRIM(reason_type) = ''");
$pdo->exec('UPDATE shopfloor_absence_reasons SET reason_code = COALESCE(NULLIF(TRIM(reason_code), ""), NULLIF(TRIM(code), ""))');
$pdo->exec('UPDATE shopfloor_absence_reasons SET sage_code = COALESCE(NULLIF(TRIM(sage_code), ""), NULLIF(TRIM(code), ""), NULLIF(TRIM(reason_code), ""))');
$pdo->exec('UPDATE shopfloor_absence_reasons SET show_in_shopfloor = 1 WHERE show_in_shopfloor IS NULL');
$missingAbsenceReasonCodes = $pdo->query("SELECT id FROM shopfloor_absence_reasons WHERE reason_code IS NULL OR TRIM(reason_code) = '' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if ($missingAbsenceReasonCodes) {
    $absenceReasonCodeUpdateStmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET reason_code = ? WHERE id = ?');
    foreach ($missingAbsenceReasonCodes as $absenceReasonId) {
        $absenceReasonCodeUpdateStmt->execute([sprintf('MOT-%03d', (int) $absenceReasonId), (int) $absenceReasonId]);
    }
}
$pdo->exec('UPDATE shopfloor_absence_reasons SET sage_code = reason_code WHERE sage_code IS NULL OR TRIM(sage_code) = ""');
$absenceReasonCodeIndexExists = taskforce_sqlite_index_exists($pdo, 'idx_shopfloor_absence_reasons_reason_code');
if (!$absenceReasonCodeIndexExists) {
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_shopfloor_absence_reasons_reason_code ON shopfloor_absence_reasons(reason_code)');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);
$absenceReasonSeedKey = 'shopfloor_absence_reasons_seeded';
$absenceReasonSeedStmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
$absenceReasonSeedStmt->execute([$absenceReasonSeedKey]);
$absenceReasonSeedState = $absenceReasonSeedStmt->fetchColumn();
$absenceReasonCountStmt = $pdo->query('SELECT COUNT(*) FROM shopfloor_absence_reasons');
$absenceReasonCount = (int) ($absenceReasonCountStmt ? $absenceReasonCountStmt->fetchColumn() : 0);

if ($absenceReasonSeedState === false && $absenceReasonCount === 0) {
    $defaultAbsenceReasonStmt = $pdo->prepare('INSERT OR IGNORE INTO shopfloor_absence_reasons(reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, 1, NULL)');
    foreach ([
        ['Ausência', 'MOT-001', '001', 'Falta sem perda de remuneração', '#0d6efd'],
        ['Ausência', 'MOT-002', '002', 'Consulta médica', '#198754'],
        ['Ausência', 'MOT-003', '003', 'Acompanhamento familiar', '#fd7e14'],
        ['Ausência', 'MOT-004', '004', 'Formação externa', '#6f42c1'],
        ['Ausência', 'MOT-005', '005', 'Outro motivo justificado', '#6c757d']
    ] as $defaultAbsenceReason) {
        $defaultAbsenceReasonStmt->execute($defaultAbsenceReason);
    }
}

if ($absenceReasonSeedState === false) {
    $absenceReasonSeedInsertStmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES (?, ?)');
    $absenceReasonSeedInsertStmt->execute([$absenceReasonSeedKey, '1']);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_absence_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        request_type TEXT NOT NULL DEFAULT "Dias inteiros",
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        start_time TEXT,
        end_time TEXT,
        reason TEXT NOT NULL,
        details TEXT,
        status TEXT NOT NULL DEFAULT "Pendente",
        reviewed_by INTEGER,
        reviewed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$absenceRequestColumnsStmt = $pdo->query('PRAGMA table_info(shopfloor_absence_requests)');
$absenceRequestColumns = $absenceRequestColumnsStmt ? $absenceRequestColumnsStmt->fetchAll(PDO::FETCH_COLUMN, 1) : [];
if (!in_array('request_type', $absenceRequestColumns, true)) {
    try {
        $pdo->exec("ALTER TABLE shopfloor_absence_requests ADD COLUMN request_type TEXT NOT NULL DEFAULT 'Dias inteiros'");
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}
if (!in_array('start_time', $absenceRequestColumns, true)) {
    try {
        $pdo->exec('ALTER TABLE shopfloor_absence_requests ADD COLUMN start_time TEXT');
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}
if (!in_array('end_time', $absenceRequestColumns, true)) {
    try {
        $pdo->exec('ALTER TABLE shopfloor_absence_requests ADD COLUMN end_time TEXT');
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}
if (!in_array('duration_type', $absenceRequestColumns, true)) {
    try {
        $pdo->exec("ALTER TABLE shopfloor_absence_requests ADD COLUMN duration_type TEXT NOT NULL DEFAULT 'Completa'");
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}
if (!in_array('duration_hours', $absenceRequestColumns, true)) {
    try {
        $pdo->exec('ALTER TABLE shopfloor_absence_requests ADD COLUMN duration_hours TEXT');
    } catch (PDOException $e) {
        // Mantém compatibilidade com instalações que já tenham migração parcial.
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_justifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        absence_request_id INTEGER,
        event_date TEXT NOT NULL,
        description TEXT NOT NULL,
        attachment_path TEXT,
        status TEXT NOT NULL DEFAULT "Submetida",
        reviewed_by INTEGER,
        reviewed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(absence_request_id) REFERENCES shopfloor_absence_requests(id) ON DELETE SET NULL,
        FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_vacation_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        total_days REAL,
        notes TEXT,
        status TEXT NOT NULL DEFAULT "Pendente",
        reviewed_by INTEGER,
        reviewed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        audience TEXT NOT NULL DEFAULT "shopfloor",
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS shopfloor_announcement_targets (
        announcement_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(announcement_id, user_id),
        FOREIGN KEY(announcement_id) REFERENCES shopfloor_announcements(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$defaultGroupStmt = $pdo->prepare('INSERT OR IGNORE INTO hr_department_groups(name) VALUES (?)');
foreach (['Produção', 'Controlo', 'Administrativos'] as $defaultGroupName) {
    $defaultGroupStmt->execute([$defaultGroupName]);
}

$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username_unique ON users(username)');

$hasAdmin = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
if ($hasAdmin === 0) {
    $firstUserId = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($firstUserId) {
        $stmt = $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = ?');
        $stmt->execute([(int) $firstUserId]);
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        created_by INTEGER NOT NULL,
        estimated_minutes INTEGER,
        actual_minutes INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_members (
        team_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        role TEXT DEFAULT "member",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(team_id, user_id),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        created_by INTEGER NOT NULL,
        leader_user_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(leader_user_id) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$projectColumns = $pdo->query('PRAGMA table_info(projects)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('leader_user_id', $projectColumns, true)) {
    $pdo->exec('ALTER TABLE projects ADD COLUMN leader_user_id INTEGER');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        parent_task_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        status TEXT NOT NULL DEFAULT "todo",
        priority TEXT NOT NULL DEFAULT "normal",
        due_date DATE,
        position INTEGER DEFAULT 0,
        created_by INTEGER NOT NULL,
        estimated_minutes INTEGER,
        actual_minutes INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);


$taskColumns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('estimated_minutes', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN estimated_minutes INTEGER');
}
if (!in_array('actual_minutes', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN actual_minutes INTEGER');
}
if (!in_array('updated_at', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN updated_at DATETIME');
    $pdo->exec('UPDATE tasks SET updated_at = created_at WHERE updated_at IS NULL');
}
if (!in_array('updated_by', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN updated_by INTEGER');
    $pdo->exec('UPDATE tasks SET updated_by = created_by WHERE updated_by IS NULL');
}


$taskColumns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('assignee_user_id', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN assignee_user_id INTEGER');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS daily_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        report_date DATE NOT NULL,
        summary TEXT,
        html_content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS app_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS checklist_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        is_done INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS checklist_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS checklist_template_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(template_id) REFERENCES checklist_templates(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        department TEXT NOT NULL,
        fields_json TEXT,
        created_by INTEGER NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$teamFormColumns = $pdo->query('PRAGMA table_info(team_forms)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('fields_json', $teamFormColumns, true)) {
    $pdo->exec('ALTER TABLE team_forms ADD COLUMN fields_json TEXT');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_form_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        payload_json TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        assignee_user_id INTEGER,
        status TEXT NOT NULL DEFAULT "open",
        completed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(form_id) REFERENCES team_forms(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(assignee_user_id) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$entryColumns = $pdo->query('PRAGMA table_info(team_form_entries)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('assignee_user_id', $entryColumns, true)) {
    $pdo->exec('ALTER TABLE team_form_entries ADD COLUMN assignee_user_id INTEGER');
}
if (!in_array('status', $entryColumns, true)) {
    $pdo->exec('ALTER TABLE team_form_entries ADD COLUMN status TEXT NOT NULL DEFAULT "open"');
}
if (!in_array('completed_at', $entryColumns, true)) {
    $pdo->exec('ALTER TABLE team_form_entries ADD COLUMN completed_at DATETIME');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_code TEXT NOT NULL UNIQUE,
        team_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        urgency TEXT NOT NULL DEFAULT "Média",
        due_date DATE,
        created_by INTEGER NOT NULL,
        assignee_user_id INTEGER,
        status TEXT NOT NULL DEFAULT "open",
        completed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(assignee_user_id) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_ticket_status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        from_status TEXT,
        to_status TEXT NOT NULL,
        changed_by INTEGER,
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(ticket_id) REFERENCES team_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY(changed_by) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$ticketColumns = $pdo->query('PRAGMA table_info(team_tickets)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('assignee_user_id', $ticketColumns, true)) {
    $pdo->exec('ALTER TABLE team_tickets ADD COLUMN assignee_user_id INTEGER');
}
if (!in_array('status', $ticketColumns, true)) {
    $pdo->exec('ALTER TABLE team_tickets ADD COLUMN status TEXT NOT NULL DEFAULT "open"');
}
if (!in_array('completed_at', $ticketColumns, true)) {
    $pdo->exec('ALTER TABLE team_tickets ADD COLUMN completed_at DATETIME');
}
if (!in_array('estimated_minutes', $ticketColumns, true)) {
    $pdo->exec('ALTER TABLE team_tickets ADD COLUMN estimated_minutes INTEGER');
}
if (!in_array('actual_minutes', $ticketColumns, true)) {
    $pdo->exec('ALTER TABLE team_tickets ADD COLUMN actual_minutes INTEGER');
}

if (!in_array('estimated_minutes', $entryColumns, true)) {
    $pdo->exec('ALTER TABLE team_form_entries ADD COLUMN estimated_minutes INTEGER');
}
if (!in_array('actual_minutes', $entryColumns, true)) {
    $pdo->exec('ALTER TABLE team_form_entries ADD COLUMN actual_minutes INTEGER');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS task_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS task_creation_field_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        project_id INTEGER,
        field_key TEXT NOT NULL,
        is_visible INTEGER NOT NULL DEFAULT 1,
        is_required INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(team_id, project_id, field_key),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )'
);


$pdo->exec(
    'CREATE TABLE IF NOT EXISTS task_custom_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        field_key TEXT NOT NULL,
        label TEXT NOT NULL,
        field_type TEXT NOT NULL DEFAULT "text",
        options_json TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(team_id, field_key),
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$taskColumns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('custom_fields_json', $taskColumns, true)) {
    $pdo->exec('ALTER TABLE tasks ADD COLUMN custom_fields_json TEXT');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS app_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        event_type TEXT NOT NULL,
        description TEXT NOT NULL,
        context_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS task_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_path TEXT NOT NULL,
        uploaded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_ticket_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(ticket_id) REFERENCES team_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_ticket_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_path TEXT NOT NULL,
        uploaded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(ticket_id) REFERENCES team_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_recurring_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        project_id INTEGER,
        assignee_user_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        weekday INTEGER,
        recurrence_type TEXT NOT NULL DEFAULT "weekly",
        start_date DATE,
        time_of_day TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL,
        FOREIGN KEY(assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_recurring_task_completions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recurring_task_id INTEGER NOT NULL,
        occurrence_date DATE NOT NULL,
        completed_by INTEGER NOT NULL,
        completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(recurring_task_id) REFERENCES team_recurring_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(completed_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(recurring_task_id, occurrence_date)
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_recurring_task_statuses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recurring_task_id INTEGER NOT NULL,
        occurrence_date DATE NOT NULL,
        status TEXT NOT NULL DEFAULT "todo",
        updated_by INTEGER NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(recurring_task_id) REFERENCES team_recurring_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(recurring_task_id, occurrence_date)
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_recurring_task_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recurring_task_id INTEGER NOT NULL,
        occurrence_date DATE NOT NULL,
        project_id INTEGER,
        assignee_user_id INTEGER,
        title TEXT NOT NULL,
        description TEXT,
        time_of_day TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(recurring_task_id) REFERENCES team_recurring_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL,
        FOREIGN KEY(assignee_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(recurring_task_id, occurrence_date)
    )'
);

$recurringColumns = $pdo->query('PRAGMA table_info(team_recurring_tasks)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('recurrence_type', $recurringColumns, true)) {
    $pdo->exec('ALTER TABLE team_recurring_tasks ADD COLUMN recurrence_type TEXT NOT NULL DEFAULT "weekly"');
}
if (!in_array('start_date', $recurringColumns, true)) {
    $pdo->exec('ALTER TABLE team_recurring_tasks ADD COLUMN start_date DATE');
}
if (!in_array('project_id', $recurringColumns, true)) {
    $pdo->exec('ALTER TABLE team_recurring_tasks ADD COLUMN project_id INTEGER');
}
if (!in_array('assignee_user_id', $recurringColumns, true)) {
    $pdo->exec('ALTER TABLE team_recurring_tasks ADD COLUMN assignee_user_id INTEGER');
}
if (!in_array('checklist_template_id', $recurringColumns, true)) {
    $pdo->exec('ALTER TABLE team_recurring_tasks ADD COLUMN checklist_template_id INTEGER');
}
if (in_array('weekday', $recurringColumns, true)) {
    $pdo->exec('UPDATE team_recurring_tasks SET weekday = NULL WHERE weekday NOT BETWEEN 1 AND 7');
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_recurring_task_checklist_states (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recurring_task_id INTEGER NOT NULL,
        occurrence_date DATE NOT NULL,
        checklist_state_json TEXT NOT NULL,
        updated_by INTEGER NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(recurring_task_id) REFERENCES team_recurring_tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(recurring_task_id, occurrence_date)
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_form_entry_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(entry_id) REFERENCES team_form_entries(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_form_entry_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_path TEXT NOT NULL,
        uploaded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(entry_id) REFERENCES team_form_entries(id) ON DELETE CASCADE,
        FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);


$pdo->exec(
    'CREATE TABLE IF NOT EXISTS project_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        file_path TEXT NOT NULL,
        uploaded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS project_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS project_note_replies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_note_id INTEGER NOT NULL,
        reply TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_note_id) REFERENCES project_notes(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        note TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);


$timeStorageVersion = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
$timeStorageVersion->execute(['time_storage_unit_version']);
$currentTimeStorageVersion = $timeStorageVersion->fetchColumn();

if ($currentTimeStorageVersion === 'minutes') {
    $pdo->exec('UPDATE tasks SET estimated_minutes = estimated_minutes * 60 WHERE estimated_minutes IS NOT NULL');
    $pdo->exec('UPDATE tasks SET actual_minutes = actual_minutes * 60 WHERE actual_minutes IS NOT NULL');
    $pdo->exec('UPDATE team_tickets SET estimated_minutes = estimated_minutes * 60 WHERE estimated_minutes IS NOT NULL');
    $pdo->exec('UPDATE team_tickets SET actual_minutes = actual_minutes * 60 WHERE actual_minutes IS NOT NULL');
    $pdo->exec('UPDATE team_form_entries SET estimated_minutes = estimated_minutes * 60 WHERE estimated_minutes IS NOT NULL');
    $pdo->exec('UPDATE team_form_entries SET actual_minutes = actual_minutes * 60 WHERE actual_minutes IS NOT NULL');

    $timeStorageUpdateStmt = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
    $timeStorageUpdateStmt->execute(['seconds', 'time_storage_unit_version']);

    if ($timeStorageUpdateStmt->rowCount() === 0) {
        $timeStorageInsertStmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES (?, ?)');

        try {
            $timeStorageInsertStmt->execute(['time_storage_unit_version', 'seconds']);
        } catch (PDOException $exception) {
            $timeStorageUpdateStmt->execute(['seconds', 'time_storage_unit_version']);
        }
    }
} elseif ($currentTimeStorageVersion === false || $currentTimeStorageVersion === null) {
    $timeStorageUpdateStmt = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
    $timeStorageUpdateStmt->execute(['seconds', 'time_storage_unit_version']);

    if ($timeStorageUpdateStmt->rowCount() === 0) {
        $timeStorageInsertStmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES (?, ?)');

        try {
            $timeStorageInsertStmt->execute(['time_storage_unit_version', 'seconds']);
        } catch (PDOException $exception) {
            $timeStorageUpdateStmt->execute(['seconds', 'time_storage_unit_version']);
        }
    }
}
