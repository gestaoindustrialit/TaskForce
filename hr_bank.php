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

$users = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$selectedUserId = (int) ($_GET['user_id'] ?? ($users ? (int) $users[0]['id'] : 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'adjust_balance') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $deltaMinutes = (int) ($_POST['delta_minutes'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($targetUserId <= 0 || $reason === '') {
            $flashError = 'Selecione colaborador e motivo.';
        } else {
            $deltaHours = $deltaMinutes / 60;
            $stmt = $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(user_id) DO UPDATE SET balance_hours = COALESCE(shopfloor_hour_banks.balance_hours, 0) + excluded.balance_hours, notes = excluded.notes, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
            $stmt->execute([$targetUserId, $deltaHours, $reason, $userId]);

            $logStmt = $pdo->prepare('INSERT INTO hr_hour_bank_logs(user_id, delta_minutes, reason, created_by) VALUES (?, ?, ?, ?)');
            $logStmt->execute([$targetUserId, $deltaMinutes, $reason, $userId]);

            $flashSuccess = 'Saldo ajustado com sucesso.';
            $selectedUserId = $targetUserId;
        }
    }
}

$balanceStmt = $pdo->prepare('SELECT balance_hours FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
$balanceStmt->execute([$selectedUserId]);
$balanceHours = (float) ($balanceStmt->fetchColumn() ?: 0);
$balanceMinutes = (int) round($balanceHours * 60);

$historyStmt = $pdo->prepare('SELECT l.created_at, l.delta_minutes, l.reason, u.name AS admin_name FROM hr_hour_bank_logs l LEFT JOIN users u ON u.id = l.created_by WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 30');
$historyStmt->execute([$selectedUserId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Banco de horas';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Banco de horas</h1>
        <p class="text-muted mb-0">Ajustes manuais com histórico e saldo em minutos.</p>
    </div>
</div>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100"><div class="card-body">
            <p class="text-muted mb-1">Saldo atual</p>
            <p class="display-5 mb-0"><?= (int) $balanceMinutes ?> min</p>
        </div></div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">Ajustar saldo</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="adjust_balance">
            <div class="col-md-4"><label class="form-label">Colaborador</label><select class="form-select" name="user_id"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $selectedUserId ? 'selected' : '' ?>><?= h((string) $u['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Variação (min)</label><input class="form-control" type="number" name="delta_minutes" value="0" required></div>
            <div class="col-md-4"><label class="form-label">Motivo</label><input class="form-control" name="reason" placeholder="Ex: Ajuste payroll, compensação" required></div>
            <div class="col-md-2 d-grid"><button class="btn btn-dark">Aplicar</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Histórico</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0"><thead><tr><th>Quando</th><th>Delta (min)</th><th>Motivo</th><th>Admin</th></tr></thead><tbody>
            <?php if (!$history): ?><tr><td colspan="4" class="text-muted">Sem ajustes registados.</td></tr><?php endif; ?>
            <?php foreach ($history as $row): ?><tr><td><?= h((string) $row['created_at']) ?></td><td><?= (int) $row['delta_minutes'] ?></td><td><?= h((string) $row['reason']) ?></td><td><?= h((string) ($row['admin_name'] ?? '—')) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
