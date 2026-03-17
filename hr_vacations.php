<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

function business_days_between(string $startDate, string $endDate): float
{
    if ($startDate === '' || $endDate === '' || $startDate > $endDate) {
        return 0.0;
    }

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $current = $start;
    $total = 0;

    while ($current <= $end) {
        $weekDay = (int) $current->format('N');
        if ($weekDay <= 5) {
            $total++;
        }
        $current = $current->modify('+1 day');
    }

    return (float) $total;
}

$flashSuccess = null;
$flashError = null;
$statusOptions = ['Planeado', 'Aprovado', 'Pendente', 'Rejeitado'];

$selectedUserId = (int) ($_GET['user_id'] ?? 0);
$selectedYear = (int) ($_GET['year'] ?? (int) date('Y'));
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = (int) date('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_balance') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $assignedDays = max(0, (float) ($_POST['assigned_days'] ?? 0));
        $shiftTime = '08:00';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($targetUserId <= 0) {
            $flashError = 'Selecione um colaborador válido para guardar saldo anual.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO hr_vacation_balances(user_id, year, assigned_days, shift_time, is_active, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(user_id, year)
                 DO UPDATE SET assigned_days = excluded.assigned_days,
                               shift_time = excluded.shift_time,
                               is_active = excluded.is_active,
                               updated_by = excluded.updated_by,
                               updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$targetUserId, $year, $assignedDays, $shiftTime, $isActive, $userId]);
            $flashSuccess = 'Saldo anual atualizado com sucesso.';
            $selectedUserId = $targetUserId;
            $selectedYear = $year;
        }
    }

    if ($action === 'create_vacation') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'Pendente'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $totalDays = business_days_between($startDate, $endDate);

        if ($targetUserId <= 0 || $startDate === '' || $endDate === '' || $startDate > $endDate) {
            $flashError = 'Dados inválidos para registar férias.';
        } elseif ($year !== (int) date('Y', strtotime($startDate))) {
            $flashError = 'O ano deve corresponder à data de início.';
        } else {
            if (!in_array($status, $statusOptions, true)) {
                $status = 'Pendente';
            }
            $stmt = $pdo->prepare('INSERT INTO hr_vacation_events(user_id, start_date, end_date, status, notes, created_by, total_days) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$targetUserId, $startDate, $endDate, $status, $notes, $userId, $totalDays]);
            $flashSuccess = 'Pedido de férias submetido com sucesso.';
            $selectedUserId = $targetUserId;
            $selectedYear = $year;
        }
    }

    if ($action === 'update_status') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? 'Pendente'));
        if ($eventId <= 0 || !in_array($newStatus, $statusOptions, true)) {
            $flashError = 'Não foi possível atualizar o estado do pedido.';
        } else {
            $stmt = $pdo->prepare('UPDATE hr_vacation_events SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $eventId]);
            $flashSuccess = 'Estado do pedido atualizado.';
        }
    }

    if ($action === 'delete_request') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            $flashError = 'Pedido inválido para eliminar.';
        } else {
            $stmt = $pdo->prepare('DELETE FROM hr_vacation_events WHERE id = ?');
            $stmt->execute([$eventId]);
            $flashSuccess = 'Pedido eliminado.';
        }
    }
}

$users = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
if ($selectedUserId <= 0 && $users) {
    $selectedUserId = (int) $users[0]['id'];
}

$balanceStmt = $pdo->prepare('SELECT assigned_days, shift_time, is_active FROM hr_vacation_balances WHERE user_id = ? AND year = ?');
$balanceStmt->execute([$selectedUserId, $selectedYear]);
$balance = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'assigned_days' => 0,
    'shift_time' => '08:00',
    'is_active' => 1,
];

$requestsStmt = $pdo->prepare(
    'SELECT v.id, v.user_id, v.start_date, v.end_date, v.status, v.notes, v.total_days, v.created_at, u.name AS user_name
     FROM hr_vacation_events v
     INNER JOIN users u ON u.id = v.user_id
     WHERE (? = 0 OR v.user_id = ?)
       AND strftime("%Y", v.start_date) = ?
     ORDER BY v.start_date ASC, v.id ASC'
);
$requestsStmt->execute([$selectedUserId, $selectedUserId, (string) $selectedYear]);
$events = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

$usedDays = 0.0;
foreach ($events as $event) {
    if (($event['status'] ?? '') === 'Aprovado') {
        $usedDays += (float) ($event['total_days'] ?? business_days_between((string) $event['start_date'], (string) $event['end_date']));
    }
}
$totalDays = (float) ($balance['assigned_days'] ?? 0);
$availableDays = $totalDays - $usedDays;

if (isset($_GET['action']) && $_GET['action'] === 'export_payroll') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payroll_ferias_' . $selectedYear . '.csv"');

    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Colaborador', 'Período', 'Dias', 'Estado', 'Notas'], ';');
    foreach ($events as $event) {
        fputcsv($output, [
            (string) $event['user_name'],
            date('d/m/Y', strtotime((string) $event['start_date'])) . ' - ' . date('d/m/Y', strtotime((string) $event['end_date'])),
            number_format((float) ($event['total_days'] ?? 0), 1, '.', ''),
            (string) $event['status'],
            (string) $event['notes'],
        ], ';');
    }
    fclose($output);
    exit;
}

$pageTitle = 'Mapa de Férias';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-1">Mapa de férias <?= (int) $selectedYear ?></h1>
<p class="text-muted mb-4">Defina o saldo anual, aprove pedidos e acompanhe dias consumidos.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<form method="get" class="row g-3 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label">Colaborador</label>
        <select class="form-select" name="user_id">
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= (int) $u['id'] ?> - <?= h($u['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Ano</label>
        <input class="form-control" type="number" min="2000" max="2100" name="year" value="<?= (int) $selectedYear ?>">
    </div>
    <div class="col-md-2 d-grid">
        <button class="btn btn-outline-primary">Aplicar</button>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted">Saldo total</div><div class="display-6"><?= h(number_format($totalDays, 1, '.', '')) ?> dias</div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted">Dias usados</div><div class="display-6 text-warning"><?= h(number_format($usedDays, 1, '.', '')) ?> dias</div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted">Dias disponíveis</div><div class="display-6 text-success"><?= h(number_format($availableDays, 1, '.', '')) ?> dias</div></div></div></div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Saldo anual</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save_balance">
            <div class="col-md-4">
                <label class="form-label">Colaborador</label>
                <select class="form-select" name="user_id" required>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= (int) $u['id'] ?> - <?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Ano</label><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?= (int) $selectedYear ?>" required></div>
            <div class="col-md-3"><label class="form-label">Dias atribuídos</label><input class="form-control" type="number" step="0.5" min="0" name="assigned_days" value="<?= h((string) $balance['assigned_days']) ?>" required></div>
                        <div class="col-md-2 form-check mt-4 ms-2"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= (int) ($balance['is_active'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Ativo</label></div>
            <div class="col-md-3 d-grid"><button class="btn btn-dark">Guardar saldo</button></div>
            <div class="col-md-2 d-grid">
                <a class="btn btn-outline-secondary" href="?user_id=<?= (int) $selectedUserId ?>&year=<?= (int) $selectedYear ?>&action=export_payroll">Export payroll pronto</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Novo pedido de férias</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="create_vacation">
            <div class="col-md-4">
                <label class="form-label">Colaborador</label>
                <select class="form-select" name="user_id" required>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= (int) $u['id'] ?> - <?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Ano</label><input class="form-control" type="number" name="year" min="2000" max="2100" value="<?= (int) $selectedYear ?>" required></div>
            <div class="col-md-3"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" required></div>
            <div class="col-md-3"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" required></div>
            <div class="col-md-2"><label class="form-label">Estado</label><select class="form-select" name="status"><?php foreach ($statusOptions as $status): ?><option value="<?= h($status) ?>" <?= $status === 'Pendente' ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Justificação</label><input class="form-control" type="text" name="notes" placeholder="Notas adicionais"></div>
            <div class="col-md-3 d-grid align-self-end"><button class="btn btn-dark">Submeter pedido</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Pedidos de férias <?= (int) $selectedYear ?></h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Período</th><th>Dias</th><th>Estado</th><th>Hash</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php if (!$events): ?>
                        <tr><td colspan="5" class="text-muted">Sem pedidos de férias registados para este colaborador/ano.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($events as $event): ?>
                        <?php $eventHash = substr(hash('sha256', (string) $event['id'] . '|' . (string) $event['user_id'] . '|' . (string) $event['start_date'] . '|' . (string) $event['end_date'] . '|' . (string) $event['status'] . '|' . (string) $event['created_at']), 0, 12); ?>
                        <tr>
                            <td><?= h(date('d/m/Y', strtotime((string) $event['start_date']))) ?> - <?= h(date('d/m/Y', strtotime((string) $event['end_date']))) ?></td>
                            <td><?= h(number_format((float) ($event['total_days'] ?? 0), 1, '.', '')) ?></td>
                            <td><span class="badge text-bg-secondary"><?= h((string) $event['status']) ?></span></td>
                            <td><code><?= h($eventHash) ?></code></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                        <input type="hidden" name="new_status" value="Aprovado">
                                        <button class="btn btn-sm btn-outline-success">Aprovar</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                        <input type="hidden" name="new_status" value="Rejeitado">
                                        <button class="btn btn-sm btn-outline-warning">Rejeitar</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Eliminar pedido?');">
                                        <input type="hidden" name="action" value="delete_request">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
