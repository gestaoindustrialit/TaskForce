<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$currentUser = current_user($pdo);
$profile = (string) ($currentUser['access_profile'] ?? 'Utilizador');
$canViewAllResults = $isAdmin || $profile === 'RH';
$canValidateResults = $canViewAllResults;

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

$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canValidateResults) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $validateDate = trim((string) ($_POST['validate_date'] ?? ''));
    $validateUserId = (int) ($_POST['validate_user_id'] ?? 0);

    if (!DateTimeImmutable::createFromFormat('Y-m-d', $validateDate)) {
        $flashError = 'Data inválida para validação.';
    } elseif ($action === 'validate_row' && $validateUserId > 0) {
        $stmt = $pdo->prepare('UPDATE shopfloor_time_entries SET validated_by = ?, validated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND date(occurred_at) = ?');
        $stmt->execute([$userId, $validateUserId, $validateDate]);
        $flashSuccess = 'Picagens validadas com sucesso.';
    } elseif ($action === 'validate_day') {
        $whereParts = ['date(occurred_at) = ?'];
        $params = [$validateDate];

        if ($teamId > 0) {
            $whereParts[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
            $params[] = $teamId;
        }
        if ($selectedUsers) {
            $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
            $whereParts[] = 'user_id IN (' . $placeholders . ')';
            foreach ($selectedUsers as $selUserId) {
                $params[] = $selUserId;
            }
        }

        $sqlValidate = 'UPDATE shopfloor_time_entries
            SET validated_by = ?, validated_at = CURRENT_TIMESTAMP
            WHERE ' . implode(' AND ', $whereParts);
        array_unshift($params, $userId);
        $pdo->prepare($sqlValidate)->execute($params);
        $flashSuccess = 'Picagens do dia validadas.';
    }
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
if ($canViewAllResults) {
    $users = $pdo->query('SELECT id, name, user_number FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtUsers = $pdo->prepare('SELECT id, name, user_number FROM users WHERE id = ? LIMIT 1');
    $stmtUsers->execute([$userId]);
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
}

$params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$where = ['te.occurred_at BETWEEN ? AND ?'];
if (!$canViewAllResults) {
    $where[] = 'te.user_id = ?';
    $params[] = $userId;
}
if ($teamId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = user_id)';
    $params[] = $teamId;
}
if ($selectedUsers) {
    $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
    $where[] = 'te.user_id IN (' . $placeholders . ')';
    foreach ($selectedUsers as $selUserId) {
        $params[] = $selUserId;
    }
}

$sql = 'SELECT te.user_id, te.entry_type, te.occurred_at, te.validated_at, u.name AS user_name, u.user_number
        FROM shopfloor_time_entries te
        INNER JOIN users u ON u.id = te.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY te.user_id ASC, te.occurred_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$daily = [];
foreach ($entries as $entry) {
    $uid = (int) $entry['user_id'];
    $date = date('Y-m-d', strtotime((string) $entry['occurred_at']));
    $key = $uid . '|' . $date;

    if (!isset($daily[$key])) {
        $daily[$key] = [
            'user_id' => $uid,
            'user_name' => (string) $entry['user_name'],
            'user_number' => (string) ($entry['user_number'] ?? ''),
            'date' => $date,
            'entries' => [],
            'validated_at' => null,
            'seconds' => 0,
        ];
    }

    $daily[$key]['entries'][] = [
        'type' => (string) $entry['entry_type'],
        'time' => date('H:i', strtotime((string) $entry['occurred_at'])),
        'timestamp' => strtotime((string) $entry['occurred_at']),
    ];

    if (!empty($entry['validated_at'])) {
        $daily[$key]['validated_at'] = (string) $entry['validated_at'];
    }
}

foreach ($daily as &$row) {
    $openIn = null;
    foreach ($row['entries'] as $point) {
        if ($point['type'] === 'entrada') {
            $openIn = (int) $point['timestamp'];
        } elseif ($point['type'] === 'saida' && $openIn !== null && $point['timestamp'] > $openIn) {
            $row['seconds'] += ((int) $point['timestamp'] - $openIn);
            $openIn = null;
        }
    }

    $row['status'] = $row['validated_at'] ? 'Validado' : 'Em curso';
    $row['type_label'] = count($row['entries']) >= 4 ? 'Normal' : 'Parcial';
    $row['effective'] = sprintf('%02d:%02d', intdiv($row['seconds'], 3600), intdiv($row['seconds'] % 3600, 60));
    $row['target'] = '08:15';

    $times = [];
    foreach ($row['entries'] as $point) {
        $times[] = $point['time'];
    }
    $row['e1'] = $times[0] ?? '';
    $row['s1'] = $times[1] ?? '';
    $row['e2'] = $times[2] ?? '';
    $row['s2'] = $times[3] ?? '';
}
unset($row);

usort(
    $daily,
    static function (array $a, array $b): int {
        if ($a['date'] === $b['date']) {
            return strcmp($a['user_name'], $b['user_name']);
        }
        return strcmp($b['date'], $a['date']);
    }
);

$pageTitle = 'Resultados';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Resultados de picagens</h1>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" value="<?= h($startDate) ?>"></div>
        <div class="col-md-2"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" value="<?= h($endDate) ?>"></div>
        <div class="col-md-3"><label class="form-label">Equipa</label><select class="form-select" name="team_id"><option value="0">Todas</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === $teamId ? 'selected' : '' ?>><?= h((string) $team['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Utilizadores</label><select class="form-select" name="user_ids[]" multiple size="4" <?= $canViewAllResults ? '' : 'disabled' ?>><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'selected' : '' ?>><?= h((string) (($u['user_number'] ?: $u['id']) . ' - ' . $u['name'])) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-grid"><button class="btn btn-dark">Filtrar</button></div>
    </div>
</form>

<?php if ($canValidateResults && $startDate === $endDate): ?>
    <form method="post" class="mb-3">
        <input type="hidden" name="action" value="validate_day">
        <input type="hidden" name="validate_date" value="<?= h($startDate) ?>">
        <button class="btn btn-success btn-sm"><i class="bi bi-check2-square me-1"></i>Validar rapidamente todas as picagens do dia</button>
    </form>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Estado</th><th>Tipo</th><th>Data</th><th>Número</th><th>Nome</th>
                        <th>E1</th><th>S1</th><th>E2</th><th>S2</th><th>Objectivo</th><th>Efectivo</th>
                        <?php if ($canValidateResults): ?><th class="text-end">Validação</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$daily): ?>
                    <tr><td colspan="<?= $canValidateResults ? '12' : '11' ?>" class="text-muted">Sem registos no intervalo.</td></tr>
                <?php endif; ?>
                <?php foreach ($daily as $row): ?>
                    <tr>
                        <td><span class="badge <?= $row['status'] === 'Validado' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= h($row['status']) ?></span></td>
                        <td><?= h($row['type_label']) ?></td>
                        <td><?= h(date('d-m-Y (D)', strtotime($row['date']))) ?></td>
                        <td><?= h($row['user_number'] !== '' ? $row['user_number'] : (string) $row['user_id']) ?></td>
                        <td><?= h($row['user_name']) ?></td>
                        <td><?= h($row['e1']) ?></td>
                        <td><?= h($row['s1']) ?></td>
                        <td><?= h($row['e2']) ?></td>
                        <td><?= h($row['s2']) ?></td>
                        <td><?= h($row['target']) ?></td>
                        <td><?= h($row['effective']) ?></td>
                        <?php if ($canValidateResults): ?>
                            <td class="text-end">
                                <?php if ($row['status'] === 'Validado'): ?>
                                    <span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>Validado</span>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="validate_row">
                                        <input type="hidden" name="validate_date" value="<?= h($row['date']) ?>">
                                        <input type="hidden" name="validate_user_id" value="<?= (int) $row['user_id'] ?>">
                                        <button class="btn btn-outline-success btn-sm">Validar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
