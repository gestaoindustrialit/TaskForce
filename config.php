<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Lisbon');

$dbFile = __DIR__ . '/database.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
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
    'CREATE TABLE IF NOT EXISTS forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        department TEXT NOT NULL,
        visibility TEXT NOT NULL DEFAULT "team",
        created_by INTEGER NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        requester_name TEXT NOT NULL,
        responsible TEXT NOT NULL,
        department_requested TEXT NOT NULL,
        product TEXT NOT NULL,
        operation TEXT NOT NULL,
        quantity TEXT NOT NULL,
        urgency TEXT NOT NULL,
        due_date DATE,
        notes TEXT,
        attachment_ref TEXT,
        status TEXT DEFAULT "open",
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_forms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        department TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS team_form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_id INTEGER NOT NULL,
        requester_name TEXT NOT NULL,
        responsible TEXT NOT NULL,
        department_requested TEXT NOT NULL,
        product TEXT NOT NULL,
        operation TEXT NOT NULL,
        quantity TEXT NOT NULL,
        urgency TEXT NOT NULL,
        due_date DATE,
        notes TEXT,
        attachment_ref TEXT,
        status TEXT DEFAULT "open",
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(form_id) REFERENCES team_forms(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
    )'
);
