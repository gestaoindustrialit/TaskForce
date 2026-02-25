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
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<h1>Erro interno (500)</h1>';
        echo '<p>Ocorreu um erro inesperado ao processar o pedido.</p>';
        echo '<p>Referência do erro: <code>' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</code></p>';
        exit;
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
    $pdo->exec('PRAGMA foreign_keys = ON');
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
