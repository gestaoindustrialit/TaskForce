<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);

$startDate = trim((string) ($_GET['start_date'] ?? date('Y-m-d')));
$endDate = trim((string) ($_GET['end_date'] ?? date('Y-m-d')));
$teamId = (int) ($_GET['team_id'] ?? 0);
$selectedUsers = array_map('intval', (array) ($_GET['user_ids'] ?? []));

if ($startDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $startDate)) {
    $startDate = date('Y-m-d');
}
if ($endDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $endDate) || $endDate < $startDate) {
    $endDate = $startDate;
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$where = ['te.occurred_at BETWEEN ? AND ?'];
if (!$isAdmin) {
    $where[] = 'te.user_id = ?';
    $params[] = $userId;
}
if ($teamId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = te.user_id)';
    $params[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $where[] = 'te.user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $params[] = $selUserId;
    }
}

$sql = 'SELECT te.user_id, te.entry_type, te.occurred_at, u.name AS user_name
        FROM shopfloor_time_entries te
        INNER JOIN users u ON u.id = te.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY te.user_id ASC, te.occurred_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = [];
foreach ($entries as $entry) {
    $uid = (int) $entry['user_id'];
    if (!isset($totals[$uid])) {
        $totals[$uid] = ['user_name' => (string) $entry['user_name'], 'seconds' => 0, 'open_in' => null, 'points' => 0];
    }

    if ((string) $entry['entry_type'] === 'entrada') {
        $totals[$uid]['open_in'] = strtotime((string) $entry['occurred_at']);
    }

    if ((string) $entry['entry_type'] === 'saida' && $totals[$uid]['open_in'] !== null) {
        $out = strtotime((string) $entry['occurred_at']);
        if ($out > $totals[$uid]['open_in']) {
            $totals[$uid]['seconds'] += ($out - $totals[$uid]['open_in']);
            $totals[$uid]['points'] += (int) floor(($out - $totals[$uid]['open_in']) / 3600);
        }
        $totals[$uid]['open_in'] = null;
    }
}

function fmt_hms(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

$pageTitle = 'Resultados';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Resultados</h1>
<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" value="<?= h($startDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" value="<?= h($endDate) ?>"></div>
        <div class="col-md-3"><label class="form-label">Equipa</label><select class="form-select" name="team_id"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === $teamId ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Utilizadores</label><select class="form-select" name="user_ids[]" multiple size="4"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'selected' : '' ?>><?= (int) $u['id'] ?> - <?= h((string) $u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Pontos e horas por utilizador</h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Utilizador</th><th>Pontos</th><th>Horas</th></tr></thead>
                <tbody>
                <?php if (!$totals): ?><tr><td colspan="3" class="text-muted">Sem registos no intervalo.</td></tr><?php endif; ?>
                <?php foreach ($totals as $row): ?>
                    <tr>
                        <td><?= h($row['user_name']) ?></td>
                        <td><?= (int) $row['points'] ?></td>
                        <td><?= h(fmt_hms((int) $row['seconds'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
