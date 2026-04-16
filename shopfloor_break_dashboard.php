<?php
require_once __DIR__ . '/helpers.php';
require_login();

$user = current_user($pdo);
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
$isRh = (string) ($user['access_profile'] ?? '') === 'RH';

if (!$isAdmin && !$isRh) {
    http_response_code(403);
    exit('Acesso reservado a administradores e RH.');
}

function format_seconds_hhmm(int $seconds): string
{
    $safe = max(0, $seconds);
    $hours = intdiv($safe, 3600);
    $minutes = intdiv($safe % 3600, 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

$teamsStmt = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC');
$teams = $teamsStmt ? $teamsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

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

$pageTitle = 'Dashboard pausas/paragens';
$bodyClass = 'bg-light';
require __DIR__ . '/partials/header.php';
?>

<section class="shopfloor-shell d-flex flex-column gap-3">
    <div class="shopfloor-panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">Dashboard de pausas e paragens</h1>
            <a href="shopfloor_break_reasons.php" class="btn btn-outline-secondary btn-sm">Voltar aos registos</a>
        </div>

        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Colaborador</label><select name="user_id" class="form-select"><option value="0">Todos</option><?php foreach ($users as $listUser): ?><option value="<?= (int) $listUser['id'] ?>" <?= $filters['user_id'] === (int) $listUser['id'] ? 'selected' : '' ?>><?= h((string) $listUser['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Equipa</label><select name="team_id" class="form-select"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= $filters['team_id'] === (int) $team['id'] ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Data início</label><input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Data fim</label><input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>"></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary w-100">Aplicar</button><a href="shopfloor_break_dashboard.php" class="btn btn-outline-secondary w-100">Limpar</a></div>
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

<?php require __DIR__ . '/partials/footer.php';
