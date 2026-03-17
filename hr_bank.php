<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$flashSuccess = null;
$flashError = null;

function parse_hms_to_seconds(string $value): ?int
{
    if (!preg_match('/^(\d{1,3}):(\d{2}):(\d{2})$/', $value, $matches)) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    $seconds = (int) $matches[3];
    if ($minutes > 59 || $seconds > 59) {
        return null;
    }

    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

function format_seconds_hms(int $seconds): string
{
    $abs = abs($seconds);
    $hours = intdiv($abs, 3600);
    $minutes = intdiv($abs % 3600, 60);
    $secs = $abs % 60;
    $prefix = $seconds < 0 ? '-' : '';
    return sprintf('%s%02d:%02d:%02d', $prefix, $hours, $minutes, $secs);
}

$users = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$selectedUserId = (int) ($_GET['user_id'] ?? ($users ? (int) $users[0]['id'] : 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'adjust_balance') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? ''));
        $deltaHms = trim((string) ($_POST['delta_hms'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $actionDate = trim((string) ($_POST['action_date'] ?? ''));
        $durationSeconds = parse_hms_to_seconds($deltaHms);

        if ($targetUserId <= 0 || $reason === '') {
            $flashError = 'Selecione colaborador e motivo.';
        } elseif (!in_array($adjustmentType, ['credito', 'debito'], true)) {
            $flashError = 'Selecione se o ajuste é crédito ou débito.';
        } elseif ($durationSeconds === null || $durationSeconds <= 0) {
            $flashError = 'Indique o ajuste no formato hh:mm:ss.';
        } elseif ($actionDate === '' || !DateTimeImmutable::createFromFormat('Y-m-d', $actionDate)) {
            $flashError = 'Indique uma data válida para o ajuste.';
        } else {
            $signedSeconds = $adjustmentType === 'debito' ? -$durationSeconds : $durationSeconds;
            $deltaMinutes = (int) round($signedSeconds / 60);
            $deltaHours = $deltaMinutes / 60;
            $stmt = $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(user_id) DO UPDATE SET balance_hours = COALESCE(shopfloor_hour_banks.balance_hours, 0) + excluded.balance_hours, notes = excluded.notes, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$targetUserId, $deltaHours, $reason, $userId]);

            $logStmt = $pdo->prepare('INSERT INTO hr_hour_bank_logs(user_id, delta_minutes, reason, created_by, action_type, action_date) VALUES (?, ?, ?, ?, ?, ?)');
            $logStmt->execute([$targetUserId, $deltaMinutes, $reason, $userId, $adjustmentType, $actionDate]);
            log_app_event($pdo, $userId, 'hr.hour_bank.adjust', 'Ajuste de banco de horas realizado.', ['target_user_id' => $targetUserId, 'adjustment_type' => $adjustmentType, 'delta_hms' => format_seconds_hms($signedSeconds), 'action_date' => $actionDate]);

            $flashSuccess = 'Saldo ajustado com sucesso.';
            $selectedUserId = $targetUserId;
        }
    }
}

$balanceStmt = $pdo->prepare('SELECT balance_hours FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
$balanceStmt->execute([$selectedUserId]);
$balanceHours = (float) ($balanceStmt->fetchColumn() ?: 0);
$balanceMinutes = (int) round($balanceHours * 60);

$historyStmt = $pdo->prepare('SELECT l.created_at, l.action_date, l.action_type, l.delta_minutes, l.reason, u.name AS admin_name FROM hr_hour_bank_logs l LEFT JOIN users u ON u.id = l.created_by WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 30');
$historyStmt->execute([$selectedUserId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Banco de horas';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Banco de horas</h1>
        <p class="text-muted mb-0">Ajustes manuais com histórico e saldo em hh:mm:ss.</p>
    </div>
</div>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100"><div class="card-body">
            <p class="text-muted mb-1">Saldo atual</p>
            <p class="display-5 mb-0"><?= h(format_seconds_hms($balanceMinutes * 60)) ?></p>
        </div></div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">Ajustar saldo</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="adjust_balance">
            <div class="col-md-3"><label class="form-label">Colaborador</label><select class="form-select" name="user_id"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= h((string) $u['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select" name="adjustment_type" required><option value="">Selecione</option><option value="credito">Crédito</option><option value="debito">Débito</option></select></div>
            <div class="col-md-2"><label class="form-label">Ajuste (hh:mm:ss)</label><input class="form-control" name="delta_hms" placeholder="01:30:00" required></div>
            <div class="col-md-2"><label class="form-label">Data da ação</label><input class="form-control" type="date" name="action_date" value="<?= h(date('Y-m-d')) ?>" required></div>
            <div class="col-md-2"><label class="form-label">Motivo</label><input class="form-control" name="reason" placeholder="Ex: Ajuste payroll" required></div>
            <div class="col-md-1 d-grid"><button class="btn btn-dark">Aplicar</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Histórico</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0"><thead><tr><th>Quando</th><th>Data da ação</th><th>Tipo</th><th>Delta</th><th>Motivo</th><th>Admin</th></tr></thead><tbody>
            <?php if (!$history): ?><tr><td colspan="6" class="text-muted">Sem ajustes registados.</td></tr><?php endif; ?>
            <?php foreach ($history as $row): ?><tr><td><?= h((string) $row['created_at']) ?></td><td><?= h((string) ($row['action_date'] ?? '—')) ?></td><td><?= h((string) ($row['action_type'] ?? '—')) ?></td><td><?= h(format_seconds_hms(((int) $row['delta_minutes']) * 60)) ?></td><td><?= h((string) $row['reason']) ?></td><td><?= h((string) ($row['admin_name'] ?? '—')) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
