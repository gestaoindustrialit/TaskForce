<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

function format_seconds_hhmm(int $seconds): string
{
    $safe = max(0, $seconds);
    $hours = intdiv($safe, 3600);
    $minutes = intdiv($safe % 3600, 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    if (!table_exists($pdo, $tableName)) {
        return false;
    }

    $columnsStmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($columns as $column) {
        if ((string) ($column['name'] ?? '') === $columnName) {
            return true;
        }
    }

    return false;
}

$hasUserActiveColumn = column_exists($pdo, 'users', 'is_active');
$activeUsersSql = $hasUserActiveColumn
    ? 'SELECT COUNT(*) FROM users WHERE is_active = 1'
    : 'SELECT COUNT(*) FROM users';

$stats = [
    'users' => (int) $pdo->query($activeUsersSql)->fetchColumn(),
    'departments' => (int) $pdo->query('SELECT COUNT(*) FROM hr_departments')->fetchColumn(),
    'schedules' => (int) $pdo->query('SELECT COUNT(*) FROM hr_schedules')->fetchColumn(),
    'vacations' => (int) $pdo->query('SELECT COUNT(*) FROM hr_vacation_events')->fetchColumn(),
    'alerts' => (int) $pdo->query('SELECT COUNT(*) FROM hr_alerts WHERE is_active = 1')->fetchColumn(),
];

$todayDate = date('Y-m-d');
$todayWeekday = (int) date('N');

$approvedLeavesToday = [];
$hasVacationEventsTable = table_exists($pdo, 'hr_vacation_events');
$hasAbsenceRequestsTable = table_exists($pdo, 'shopfloor_absence_requests');

if ($hasVacationEventsTable && $hasAbsenceRequestsTable) {
    $approvedLeavesStmt = $pdo->prepare(
        'SELECT DISTINCT
        u.id,
        u.name,
        u.user_number,
        d.name AS department_name,
        "Férias" AS leave_type,
        v.start_date,
        v.end_date
     FROM hr_vacation_events v
     INNER JOIN users u ON u.id = v.user_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     WHERE u.is_active = 1
       AND v.status = "Aprovado"
       AND date(?) BETWEEN date(v.start_date) AND date(v.end_date)

     UNION ALL

     SELECT DISTINCT
        u.id,
        u.name,
        u.user_number,
        d.name AS department_name,
        "Ausência" AS leave_type,
        a.start_date,
        a.end_date
     FROM shopfloor_absence_requests a
     INNER JOIN users u ON u.id = a.user_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     WHERE u.is_active = 1
       AND a.status LIKE "Aprovado%"
       AND date(?) BETWEEN date(a.start_date) AND date(a.end_date)

     ORDER BY name COLLATE NOCASE ASC'
    );
    $approvedLeavesStmt->execute([$todayDate, $todayDate]);
    $approvedLeavesToday = $approvedLeavesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$todayAttendanceRows = [];
$canComputeAttendance = table_exists($pdo, 'shopfloor_time_entries')
    && table_exists($pdo, 'hr_schedules')
    && column_exists($pdo, 'users', 'schedule_id')
    && $hasVacationEventsTable
    && $hasAbsenceRequestsTable;

if ($canComputeAttendance) {
    $attendanceStmt = $pdo->prepare(
        'SELECT
        u.id,
        u.name,
        u.user_number,
        d.name AS department_name,
        s.name AS schedule_name,
        s.start_time,
        first_entry.first_entry_at
     FROM users u
     INNER JOIN hr_schedules s ON s.id = u.schedule_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     LEFT JOIN (
        SELECT user_id, MIN(occurred_at) AS first_entry_at
        FROM shopfloor_time_entries
        WHERE entry_type = "entrada"
          AND date(occurred_at, "localtime") = date(?)
        GROUP BY user_id
     ) AS first_entry ON first_entry.user_id = u.id
     WHERE u.is_active = 1
       AND u.pin_only_login = 0
       AND instr("," || replace(COALESCE(s.weekdays_mask, ""), " ", "") || ",", "," || ? || ",") > 0
       AND NOT EXISTS (
            SELECT 1
            FROM hr_vacation_events v
            WHERE v.user_id = u.id
              AND v.status = "Aprovado"
              AND date(?) BETWEEN date(v.start_date) AND date(v.end_date)
       )
       AND NOT EXISTS (
            SELECT 1
            FROM shopfloor_absence_requests a
            WHERE a.user_id = u.id
              AND a.status LIKE "Aprovado%"
              AND date(?) BETWEEN date(a.start_date) AND date(a.end_date)
       )
     ORDER BY s.start_time ASC, u.name COLLATE NOCASE ASC'
    );
    $attendanceStmt->execute([$todayDate, (string) $todayWeekday, $todayDate, $todayDate]);
    $todayAttendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

$missingToday = [];
$lateToday = [];

foreach ($todayAttendanceRows as $row) {
    $firstEntry = (string) ($row['first_entry_at'] ?? '');
    $scheduleStart = trim((string) ($row['start_time'] ?? ''));

    if ($firstEntry === '') {
        $missingToday[] = $row;
        continue;
    }

    if ($scheduleStart === '' || !preg_match('/^\d{2}:\d{2}/', $scheduleStart)) {
        continue;
    }

    $firstEntryTimestamp = strtotime($firstEntry);
    $scheduleTimestamp = strtotime($todayDate . ' ' . substr($scheduleStart, 0, 5) . ':00');
    if ($firstEntryTimestamp === false || $scheduleTimestamp === false || $firstEntryTimestamp <= $scheduleTimestamp) {
        continue;
    }

    $delayMinutes = (int) floor(($firstEntryTimestamp - $scheduleTimestamp) / 60);
    $row['delay_minutes'] = $delayMinutes;
    $lateToday[] = $row;
}

$hasBreakDashboardTables = table_exists($pdo, 'shopfloor_break_entries')
    && table_exists($pdo, 'shopfloor_break_reasons')
    && table_exists($pdo, 'team_members')
    && table_exists($pdo, 'teams');

$teams = [];
if (table_exists($pdo, 'teams')) {
    $teamsStmt = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC');
    $teams = $teamsStmt ? $teamsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$usersStmt = $pdo->query('SELECT id, name FROM users ORDER BY name COLLATE NOCASE ASC');
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$filters = [
    'user_id' => (int) ($_GET['user_id'] ?? 0),
    'team_id' => (int) ($_GET['team_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-01'))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-d'))),
];

$where = [];
$params = [];
if ($filters['user_id'] > 0) {
    $where[] = 'b.user_id = ?';
    $params[] = $filters['user_id'];
}
if ($filters['team_id'] > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.user_id = b.user_id AND tm.team_id = ?)';
    $params[] = $filters['team_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'date(b.started_at) >= date(?)';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'date(b.started_at) <= date(?)';
    $params[] = $filters['date_to'];
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$globalStats = [];
$topReasons = [];
$userSummaries = [];
if ($hasBreakDashboardTables) {
    $globalStmt = $pdo->prepare(
        'SELECT
        COUNT(*) AS total_entries,
        SUM(CASE WHEN b.break_type = "Pausa" THEN 1 ELSE 0 END) AS total_pauses,
        SUM(CASE WHEN b.break_type = "Paragem" THEN 1 ELSE 0 END) AS total_stops,
        SUM(CASE WHEN b.ended_at IS NULL
            THEN CAST((julianday(CURRENT_TIMESTAMP) - julianday(b.started_at)) * 86400 AS INTEGER)
            ELSE CAST((julianday(b.ended_at) - julianday(b.started_at)) * 86400 AS INTEGER)
        END) AS total_seconds
     FROM shopfloor_break_entries b' . $whereSql
    );
    $globalStmt->execute($params);
    $globalStats = $globalStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $reasonStmt = $pdo->prepare(
        'SELECT
        r.code,
        r.label,
        b.break_type,
        COUNT(*) AS total_entries,
        SUM(CASE WHEN b.ended_at IS NULL
            THEN CAST((julianday(CURRENT_TIMESTAMP) - julianday(b.started_at)) * 86400 AS INTEGER)
            ELSE CAST((julianday(b.ended_at) - julianday(b.started_at)) * 86400 AS INTEGER)
        END) AS total_seconds
     FROM shopfloor_break_entries b
     INNER JOIN shopfloor_break_reasons r ON r.id = b.break_reason_id'
     . $whereSql .
     ' GROUP BY r.id, r.code, r.label, b.break_type
       ORDER BY total_seconds DESC, total_entries DESC
       LIMIT 10'
    );
    $reasonStmt->execute($params);
    $topReasons = $reasonStmt->fetchAll(PDO::FETCH_ASSOC);

    $userSummaryStmt = $pdo->prepare(
        'SELECT
        u.name AS user_name,
        COUNT(*) AS total_entries,
        SUM(CASE WHEN b.break_type = "Pausa" THEN 1 ELSE 0 END) AS total_pauses,
        SUM(CASE WHEN b.break_type = "Paragem" THEN 1 ELSE 0 END) AS total_stops,
        SUM(CASE WHEN b.ended_at IS NULL
            THEN CAST((julianday(CURRENT_TIMESTAMP) - julianday(b.started_at)) * 86400 AS INTEGER)
            ELSE CAST((julianday(b.ended_at) - julianday(b.started_at)) * 86400 AS INTEGER)
        END) AS total_seconds
     FROM shopfloor_break_entries b
     INNER JOIN users u ON u.id = b.user_id'
     . $whereSql .
     ' GROUP BY u.id, u.name
       ORDER BY total_seconds DESC, total_entries DESC
       LIMIT 20'
    );
    $userSummaryStmt->execute($params);
    $userSummaries = $userSummaryStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Módulo RH';
$bodyClass = 'bg-light';
require __DIR__ . '/partials/header.php';
?>
<a href="dashboard.php" class="btn btn-link px-0 mb-2">&larr; Voltar à dashboard</a>

<section class="hr-shell d-flex flex-column gap-3">
    <div class="soft-card p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div>
                <h1 class="h2 mb-1">Painel RH central</h1>
                <p class="text-muted mb-0">Resumo diário de assiduidade e pausas/paragens, com atalhos para toda a operação RH.</p>
            </div>
            <div class="hr-quick-tabs" role="navigation" aria-label="Navegação RH">
                <a class="btn btn-dark" href="hr.php"><i class="bi bi-speedometer2 me-1"></i>Painel RH</a>
                <a class="btn btn-outline-secondary" href="hr_departments.php">Departamentos</a>
                <a class="btn btn-outline-secondary" href="hr_schedules.php">Horários</a>
                <a class="btn btn-outline-secondary" href="hr_calendar.php">Calendário</a>
                <a class="btn btn-outline-secondary" href="hr_bank.php">Banco de horas</a>
                <a class="btn btn-outline-secondary" href="hr_absences.php">Ausências</a>
                <a class="btn btn-outline-secondary" href="hr_vacations.php">Férias</a>
                <a class="btn btn-outline-secondary" href="resultados.php">Picagens</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 hr-stat-card"><p class="text-muted mb-1 small">Utilizadores ativos</p><p class="h3 mb-0"><?= $stats['users'] ?></p></article></div>
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 hr-stat-card"><p class="text-muted mb-1 small">Departamentos</p><p class="h3 mb-0"><?= $stats['departments'] ?></p></article></div>
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 hr-stat-card"><p class="text-muted mb-1 small">Horários</p><p class="h3 mb-0"><?= $stats['schedules'] ?></p></article></div>
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 hr-stat-card"><p class="text-muted mb-1 small">Alertas ativos</p><p class="h3 mb-0"><?= $stats['alerts'] ?></p></article></div>
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 border border-danger-subtle"><p class="text-muted mb-1 small">A faltar hoje</p><p class="h3 mb-0 text-danger"><?= count($missingToday) ?></p></article></div>
            <div class="col-6 col-lg-2"><article class="soft-card h-100 p-3 border border-warning-subtle"><p class="text-muted mb-1 small">Atrasos hoje</p><p class="h3 mb-0 text-warning-emphasis"><?= count($lateToday) ?></p></article></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="soft-card p-3 p-lg-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Pessoas a faltar (<?= h($todayDate) ?>)</h2>
                    <span class="badge text-bg-danger-subtle border border-danger-subtle"><?= count($missingToday) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Dept.</th><th>Horário</th></tr></thead>
                        <tbody>
                        <?php if (!$missingToday): ?>
                            <tr><td colspan="3" class="text-muted">Sem faltas detetadas hoje.</td></tr>
                        <?php else: foreach ($missingToday as $person): ?>
                            <tr>
                                <td><?= h(trim(((string) ($person['user_number'] ?? '')) . ' · ' . ((string) ($person['name'] ?? '')), ' ·')) ?></td>
                                <td><?= h((string) ($person['department_name'] ?? '-')) ?></td>
                                <td><?= h((string) ($person['start_time'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="soft-card p-3 p-lg-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Pessoas atrasadas (<?= h($todayDate) ?>)</h2>
                    <span class="badge text-bg-warning-subtle border border-warning-subtle"><?= count($lateToday) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Entrada</th><th>Atraso</th></tr></thead>
                        <tbody>
                        <?php if (!$lateToday): ?>
                            <tr><td colspan="3" class="text-muted">Sem atrasos detetados hoje.</td></tr>
                        <?php else: foreach ($lateToday as $person): ?>
                            <tr>
                                <td><?= h(trim(((string) ($person['user_number'] ?? '')) . ' · ' . ((string) ($person['name'] ?? '')), ' ·')) ?></td>
                                <td><?= h((string) ($person['first_entry_at'] ?? '')) ?> (<?= h((string) ($person['start_time'] ?? '-')) ?>)</td>
                                <td><?= (int) ($person['delay_minutes'] ?? 0) ?> min</td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="soft-card p-3 p-lg-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h5 mb-0">Ausências/Férias aprovadas hoje</h2>
                    <span class="badge text-bg-info-subtle border border-info-subtle"><?= count($approvedLeavesToday) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Tipo</th><th>Período</th></tr></thead>
                        <tbody>
                        <?php if (!$approvedLeavesToday): ?>
                            <tr><td colspan="3" class="text-muted">Sem ausências/férias aprovadas para hoje.</td></tr>
                        <?php else: foreach ($approvedLeavesToday as $leave): ?>
                            <tr>
                                <td><?= h(trim(((string) ($leave['user_number'] ?? '')) . ' · ' . ((string) ($leave['name'] ?? '')), ' ·')) ?></td>
                                <td><?= h((string) ($leave['leave_type'] ?? '')) ?></td>
                                <td><?= h((string) ($leave['start_date'] ?? '')) ?> → <?= h((string) ($leave['end_date'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="shopfloor-panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h4 mb-0">Dashboard de pausas e paragens (integrado)</h2>
            <a href="shopfloor_break_reasons.php" class="btn btn-outline-secondary btn-sm">Voltar aos registos</a>
        </div>

        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Colaborador</label><select name="user_id" class="form-select"><option value="0">Todos</option><?php foreach ($users as $listUser): ?><option value="<?= (int) $listUser['id'] ?>" <?= $filters['user_id'] === (int) $listUser['id'] ? 'selected' : '' ?>><?= h((string) $listUser['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Equipa</label><select name="team_id" class="form-select"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= $filters['team_id'] === (int) $team['id'] ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Data início</label><input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Data fim</label><input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary w-100">Aplicar</button><a href="hr.php" class="btn btn-outline-secondary w-100">Limpar</a></div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-md-3"><div class="shopfloor-panel h-100"><div class="small text-secondary">Total de registos</div><div class="h3 mb-0"><?= (int) ($globalStats['total_entries'] ?? 0) ?></div></div></div>
        <div class="col-md-3"><div class="shopfloor-panel h-100"><div class="small text-secondary">Total pausas</div><div class="h3 mb-0"><?= (int) ($globalStats['total_pauses'] ?? 0) ?></div></div></div>
        <div class="col-md-3"><div class="shopfloor-panel h-100"><div class="small text-secondary">Total paragens</div><div class="h3 mb-0"><?= (int) ($globalStats['total_stops'] ?? 0) ?></div></div></div>
        <div class="col-md-3"><div class="shopfloor-panel h-100"><div class="small text-secondary">Duração acumulada (hh:mm)</div><div class="h3 mb-0"><?= h(format_seconds_hhmm((int) ($globalStats['total_seconds'] ?? 0))) ?></div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="shopfloor-panel h-100">
                <h2 class="h5">Top motivos</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Motivo</th><th>Tipo</th><th>Registos</th><th>Duração</th></tr></thead>
                        <tbody>
                        <?php if (!$topReasons): ?><tr><td colspan="4" class="text-secondary">Sem dados para os filtros selecionados.</td></tr><?php endif; ?>
                        <?php foreach ($topReasons as $reason): ?>
                            <tr>
                                <td><?= h((string) $reason['code']) ?> | <?= h((string) $reason['label']) ?></td>
                                <td><?= h((string) $reason['break_type']) ?></td>
                                <td><?= (int) ($reason['total_entries'] ?? 0) ?></td>
                                <td><?= h(format_seconds_hhmm((int) ($reason['total_seconds'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="shopfloor-panel h-100">
                <h2 class="h5">Resumo por colaborador</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Pausas</th><th>Paragens</th><th>Total</th><th>Duração</th></tr></thead>
                        <tbody>
                        <?php if (!$userSummaries): ?><tr><td colspan="5" class="text-secondary">Sem dados para os filtros selecionados.</td></tr><?php endif; ?>
                        <?php foreach ($userSummaries as $summary): ?>
                            <tr>
                                <td><?= h((string) $summary['user_name']) ?></td>
                                <td><?= (int) ($summary['total_pauses'] ?? 0) ?></td>
                                <td><?= (int) ($summary['total_stops'] ?? 0) ?></td>
                                <td><?= (int) ($summary['total_entries'] ?? 0) ?></td>
                                <td><?= h(format_seconds_hhmm((int) ($summary['total_seconds'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
