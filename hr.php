<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

function format_seconds_hhmmss(int $seconds): string
{
    $safe = max(0, $seconds);
    $hours = intdiv($safe, 3600);
    $minutes = intdiv($safe % 3600, 60);
    $remainingSeconds = $safe % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
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

function parse_hhmm_to_minutes(string $value): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    if ($minutes > 59) {
        return null;
    }

    return ($hours * 60) + $minutes;
}

function format_minutes_hhmmss(int $minutes): string
{
    return format_seconds_hhmmss(max(0, $minutes) * 60);
}

function calculate_schedule_target_minutes(array $row): int
{
    $startMinutes = parse_hhmm_to_minutes((string) ($row['schedule_start_time'] ?? ''));
    $endMinutes = parse_hhmm_to_minutes((string) ($row['schedule_end_time'] ?? ''));
    $secondStartMinutes = parse_hhmm_to_minutes((string) ($row['schedule_second_start_time'] ?? ''));
    $secondEndMinutes = parse_hhmm_to_minutes((string) ($row['schedule_second_end_time'] ?? ''));
    $breakMinutes = max(0, (int) ($row['schedule_break_minutes'] ?? 0));

    $total = 0;
    if ($startMinutes !== null && $endMinutes !== null && $endMinutes > $startMinutes) {
        $total += ($endMinutes - $startMinutes);
    }
    if ($secondStartMinutes !== null && $secondEndMinutes !== null && $secondEndMinutes > $secondStartMinutes) {
        $total += ($secondEndMinutes - $secondStartMinutes);
    }
    if ($total <= 0) {
        return 0;
    }

    return max(0, $total - $breakMinutes);
}

function extract_schedule_gap(string $scheduleName): string
{
    if (preg_match('/\bGAP\s*([1-3])\b/i', $scheduleName, $matches) !== 1) {
        return '';
    }

    return 'gap' . (string) ($matches[1] ?? '');
}

$todayDate = date('Y-m-d');
$todayWeekday = (int) date('N');

$todayCalendarBlocks = [];
$hasCalendarTable = table_exists($pdo, 'hr_calendar_events');
if ($hasCalendarTable) {
    $calendarStmt = $pdo->prepare('SELECT event_type, title FROM hr_calendar_events WHERE date(?) BETWEEN date(start_date) AND date(end_date)');
    $calendarStmt->execute([$todayDate]);
    $todayCalendarBlocks = $calendarStmt->fetchAll(PDO::FETCH_ASSOC);
}

$is_company_day_blocked = static function (string $scheduleName) use ($todayCalendarBlocks): bool {
    if ($todayCalendarBlocks === []) {
        return false;
    }

    $scheduleGap = extract_schedule_gap($scheduleName);
    foreach ($todayCalendarBlocks as $calendarEvent) {
        $eventType = trim((string) ($calendarEvent['event_type'] ?? ''));
        $eventTitle = mb_strtolower(trim((string) ($calendarEvent['title'] ?? '')));
        if (in_array($eventType, ['Feriado', 'Ponte'], true)) {
            return true;
        }

        if ($eventType === 'Férias') {
            if ($scheduleGap === '' || strpos($eventTitle, $scheduleGap) !== false || strpos($eventTitle, 'gerais') !== false) {
                return true;
            }
        }
    }

    return false;
};

$hasUserActiveColumn = column_exists($pdo, 'users', 'is_active');
$activeUsersSql = $hasUserActiveColumn
    ? 'SELECT COUNT(*) FROM users WHERE is_active = 1'
    : 'SELECT COUNT(*) FROM users';
$activeUsersConditionSql = $hasUserActiveColumn ? 'u.is_active = 1' : '1 = 1';
$pinOnlyConditionSql = column_exists($pdo, 'users', 'pin_only_login') ? 'u.pin_only_login = 0' : '1 = 1';

$stats = [
    'users' => (int) $pdo->query($activeUsersSql)->fetchColumn(),
    'departments' => (int) $pdo->query('SELECT COUNT(*) FROM hr_departments')->fetchColumn(),
    'schedules' => (int) $pdo->query('SELECT COUNT(*) FROM hr_schedules')->fetchColumn(),
    'vacations' => (int) $pdo->query('SELECT COUNT(*) FROM hr_vacation_events')->fetchColumn(),
    'alerts' => (int) $pdo->query('SELECT COUNT(*) FROM hr_alerts WHERE is_active = 1')->fetchColumn(),
];

$approvedLeavesToday = [];
$hasVacationEventsTable = table_exists($pdo, 'hr_vacation_events');
$hasAbsenceRequestsTable = table_exists($pdo, 'shopfloor_absence_requests');
$scheduleStartExpr = column_exists($pdo, 'hr_schedules', 'start_time') ? 's.start_time' : 'NULL';
$scheduleEndExpr = column_exists($pdo, 'hr_schedules', 'end_time') ? 's.end_time' : 'NULL';
$scheduleSecondStartExpr = column_exists($pdo, 'hr_schedules', 'second_start_time') ? 's.second_start_time' : 'NULL';
$scheduleSecondEndExpr = column_exists($pdo, 'hr_schedules', 'second_end_time') ? 's.second_end_time' : 'NULL';
$scheduleBreakExpr = column_exists($pdo, 'hr_schedules', 'break_minutes') ? 's.break_minutes' : '0';
$absenceDurationTypeExpr = column_exists($pdo, 'shopfloor_absence_requests', 'duration_type') ? 'a.duration_type' : 'NULL';
$absenceDurationHoursExpr = column_exists($pdo, 'shopfloor_absence_requests', 'duration_hours') ? 'a.duration_hours' : 'NULL';

if ($hasVacationEventsTable && $hasAbsenceRequestsTable) {
    $approvedLeavesStmt = $pdo->prepare(
        'SELECT DISTINCT
        u.id,
        u.name AS employee_name,
        u.user_number,
        d.name AS department_name,
        "Férias" AS leave_type,
        v.start_date,
        v.end_date,
        NULL AS duration_type,
        NULL AS duration_hours,
        ' . $scheduleStartExpr . ' AS schedule_start_time,
        ' . $scheduleEndExpr . ' AS schedule_end_time,
        ' . $scheduleSecondStartExpr . ' AS schedule_second_start_time,
        ' . $scheduleSecondEndExpr . ' AS schedule_second_end_time,
        ' . $scheduleBreakExpr . ' AS schedule_break_minutes
     FROM hr_vacation_events v
     INNER JOIN users u ON u.id = v.user_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     LEFT JOIN hr_schedules s ON s.id = u.schedule_id
     WHERE ' . $activeUsersConditionSql . '
       AND v.status = "Aprovado"
       AND date(?) BETWEEN date(v.start_date) AND date(v.end_date)

     UNION ALL

     SELECT DISTINCT
        u.id,
        u.name AS employee_name,
        u.user_number,
        d.name AS department_name,
        "Ausência" AS leave_type,
        a.start_date,
        a.end_date,
        ' . $absenceDurationTypeExpr . ' AS duration_type,
        ' . $absenceDurationHoursExpr . ' AS duration_hours,
        ' . $scheduleStartExpr . ' AS schedule_start_time,
        ' . $scheduleEndExpr . ' AS schedule_end_time,
        ' . $scheduleSecondStartExpr . ' AS schedule_second_start_time,
        ' . $scheduleSecondEndExpr . ' AS schedule_second_end_time,
        ' . $scheduleBreakExpr . ' AS schedule_break_minutes
     FROM shopfloor_absence_requests a
     INNER JOIN users u ON u.id = a.user_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     LEFT JOIN hr_schedules s ON s.id = u.schedule_id
     WHERE ' . $activeUsersConditionSql . '
       AND a.status LIKE "Aprovado%"
       AND date(?) BETWEEN date(a.start_date) AND date(a.end_date)

     ORDER BY employee_name COLLATE NOCASE ASC'
    );
    $approvedLeavesStmt->execute([$todayDate, $todayDate]);
    $approvedLeavesToday = $approvedLeavesStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($approvedLeavesToday as &$leaveRow) {
        $plannedMinutes = calculate_schedule_target_minutes($leaveRow);
        $durationType = trim((string) ($leaveRow['duration_type'] ?? ''));
        $durationHours = trim((string) ($leaveRow['duration_hours'] ?? ''));
        if ((string) ($leaveRow['leave_type'] ?? '') === 'Ausência') {
            if ($durationType === 'Horas') {
                $parsedHours = parse_hhmm_to_minutes($durationHours);
                if ($parsedHours !== null) {
                    $plannedMinutes = $parsedHours;
                }
            } elseif ($durationType === 'Parcial') {
                $plannedMinutes = (int) floor($plannedMinutes / 2);
            }
        }

        $leaveRow['planned_minutes'] = $plannedMinutes;
        $leaveRow['planned_seconds'] = max(0, $plannedMinutes * 60);
    }
    unset($leaveRow);
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
        s.end_time,
        s.second_start_time,
        first_entry.first_entry_at,
        second_entry.second_entry_at
     FROM users u
     INNER JOIN hr_schedules s ON s.id = u.schedule_id
     LEFT JOIN hr_departments d ON d.id = u.department_id
     LEFT JOIN (
        SELECT user_id, MIN(datetime(occurred_at, "localtime")) AS first_entry_at
        FROM shopfloor_time_entries
        WHERE entry_type = "entrada"
          AND date(occurred_at, "localtime") = date(?)
        GROUP BY user_id
     ) AS first_entry ON first_entry.user_id = u.id
     LEFT JOIN (
        SELECT te.user_id, MIN(datetime(te.occurred_at, "localtime")) AS second_entry_at
        FROM shopfloor_time_entries te
        INNER JOIN users su ON su.id = te.user_id
        INNER JOIN hr_schedules ss ON ss.id = su.schedule_id
        WHERE te.entry_type = "entrada"
          AND date(te.occurred_at, "localtime") = date(?)
          AND ss.second_start_time IS NOT NULL
          AND trim(ss.second_start_time) <> ""
          AND datetime(te.occurred_at, "localtime") >= datetime(date(?) || " " || substr(ss.end_time, 1, 5) || ":00")
        GROUP BY te.user_id
     ) AS second_entry ON second_entry.user_id = u.id
     WHERE ' . $activeUsersConditionSql . '
       AND ' . $pinOnlyConditionSql . '
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
    $attendanceStmt->execute([$todayDate, $todayDate, $todayDate, (string) $todayWeekday, $todayDate, $todayDate]);
    $todayAttendanceRows = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
}

$missingToday = [];
$lateToday = [];

foreach ($todayAttendanceRows as $row) {
    if ($is_company_day_blocked((string) ($row['schedule_name'] ?? ''))) {
        continue;
    }

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

    $delaySeconds = $firstEntryTimestamp - $scheduleTimestamp;
    $row['delay_seconds'] = max(0, $delaySeconds);
    $row['delay_period'] = 'Manhã';
    $lateToday[] = $row;

    $secondScheduleStart = trim((string) ($row['second_start_time'] ?? ''));
    $secondEntry = (string) ($row['second_entry_at'] ?? '');
    if ($secondScheduleStart !== '' && preg_match('/^\d{2}:\d{2}/', $secondScheduleStart) && $secondEntry !== '') {
        $secondEntryTimestamp = strtotime($secondEntry);
        $secondScheduleTimestamp = strtotime($todayDate . ' ' . substr($secondScheduleStart, 0, 5) . ':00');
        if ($secondEntryTimestamp !== false && $secondScheduleTimestamp !== false && $secondEntryTimestamp > $secondScheduleTimestamp) {
            $secondRow = $row;
            $secondRow['delay_seconds'] = max(0, $secondEntryTimestamp - $secondScheduleTimestamp);
            $secondRow['delay_period'] = 'Tarde';
            $lateToday[] = $secondRow;
        }
    }
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

$approvedLeavesTotalSeconds = 0;
foreach ($approvedLeavesToday as $leave) {
    $approvedLeavesTotalSeconds += max(0, (int) ($leave['planned_seconds'] ?? 0));
}
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
                <a class="btn btn-outline-secondary" href="hr_raffle.php">Sorteio</a>
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
                    <span class="badge hr-count-badge hr-count-badge-danger"><?= count($missingToday) ?></span>
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
                    <span class="badge hr-count-badge hr-count-badge-warning"><?= count($lateToday) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Entrada</th><th>Período</th><th>Atraso</th></tr></thead>
                        <tbody>
                        <?php if (!$lateToday): ?>
                            <tr><td colspan="4" class="text-muted">Sem atrasos detetados hoje.</td></tr>
                        <?php else: foreach ($lateToday as $person): ?>
                            <tr>
                                <td><?= h(trim(((string) ($person['user_number'] ?? '')) . ' · ' . ((string) ($person['name'] ?? '')), ' ·')) ?></td>
                                <td><?= h((string) ($person['first_entry_at'] ?? '')) ?> (<?= h((string) ($person['start_time'] ?? '-')) ?>)</td>
                                <td><?= h((string) ($person['delay_period'] ?? 'Manhã')) ?></td>
                                <td><span class="hr-time-pill"><?= h(format_seconds_hhmmss((int) ($person['delay_seconds'] ?? 0))) ?></span></td>
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
                    <span class="badge hr-count-badge hr-count-badge-info"><?= count($approvedLeavesToday) ?> · <?= h(format_seconds_hhmmss($approvedLeavesTotalSeconds)) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Colaborador</th><th>Tipo</th><th>Período</th><th>Tempo previsto</th></tr></thead>
                        <tbody>
                        <?php if (!$approvedLeavesToday): ?>
                            <tr><td colspan="4" class="text-muted">Sem ausências/férias aprovadas para hoje.</td></tr>
                        <?php else: foreach ($approvedLeavesToday as $leave): ?>
                            <tr>
                                <td><?= h(trim(((string) ($leave['user_number'] ?? '')) . ' · ' . ((string) ($leave['employee_name'] ?? '')), ' ·')) ?></td>
                                <td><?= h((string) ($leave['leave_type'] ?? '')) ?></td>
                                <td><?= h((string) ($leave['start_date'] ?? '')) ?> → <?= h((string) ($leave['end_date'] ?? '')) ?></td>
                                <td><span class="hr-time-pill"><?= h(format_seconds_hhmmss((int) ($leave['planned_seconds'] ?? 0))) ?></span></td>
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
        <div class="col-md-3"><div class="shopfloor-panel h-100"><div class="small text-secondary">Duração acumulada (hh:mm:ss)</div><div class="h3 mb-0"><?= h(format_seconds_hhmmss((int) ($globalStats['total_seconds'] ?? 0))) ?></div></div></div>
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
                                <td><?= h(format_seconds_hhmmss((int) ($reason['total_seconds'] ?? 0))) ?></td>
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
                                <td><?= h(format_seconds_hhmmss((int) ($summary['total_seconds'] ?? 0))) ?></td>
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
