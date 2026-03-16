<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
$isRh = (string) ($user['access_profile'] ?? '') === 'RH';

if (!$isAdmin && !$isRh) {
    http_response_code(403);
    exit('Acesso reservado a administradores e RH.');
}

$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_reason') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $defaultColor = '#2563eb';

        if ($label === '') {
            $flashError = 'Indique a descrição do motivo.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_reasons(code, label, color, is_active, created_by) VALUES (NULL, ?, ?, 1, ?)');
                $stmt->execute([$label, $defaultColor, $userId]);

                $reasonId = (int) $pdo->lastInsertId();
                $generatedCode = sprintf('MOT-%03d', $reasonId);
                $updateStmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET code = ? WHERE id = ?');
                $updateStmt->execute([$generatedCode, $reasonId]);

                $pdo->commit();

                log_app_event($pdo, $userId, 'shopfloor.absence_reason.create', 'Motivo de ausência criado.', ['code' => $generatedCode, 'label' => $label, 'color' => $defaultColor]);
                $flashSuccess = 'Motivo criado com sucesso.';
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível criar o motivo (descrição já existe).';
            }
        }
    }

    if ($action === 'toggle_reason') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $newState = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido.';
        } else {
            $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET is_active = ? WHERE id = ?');
            $stmt->execute([$newState, $reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.toggle', 'Estado do motivo de ausência alterado.', ['reason_id' => $reasonId, 'is_active' => $newState]);
            $flashSuccess = 'Estado do motivo atualizado.';
        }
    }
}

$reasons = $pdo->query('SELECT id, code, label, color, is_active, created_at FROM shopfloor_absence_reasons ORDER BY is_active DESC, label COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Motivos de ausência';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Motivos de ausência</h1>
    <a class="btn btn-outline-secondary" href="shopfloor.php">Voltar ao Shopfloor</a>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h4 mb-3">Novo motivo</h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="create_reason">
            <div class="col-md-9">
                <label class="form-label">Descrição do motivo</label>
                <input type="text" name="label" class="form-control" placeholder="Ex.: Falta sem perda de remuneração" required>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn btn-primary">Adicionar motivo</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h4 mb-3">Lista de motivos</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Motivo</th>
                    <th>Criado em</th>
                    <th>Estado</th>
                    <th class="text-end">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($reasons): foreach ($reasons as $reason): ?>
                    <tr>
                        <td class="fw-semibold"><?= h((string) $reason['label']) ?></td>
                        <td><?= h((string) $reason['created_at']) ?></td>
                        <td>
                            <?php if ((int) $reason['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_reason">
                                <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= (int) $reason['is_active'] === 1 ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-sm <?= (int) $reason['is_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-primary' ?>">
                                    <?= (int) $reason['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-muted">Sem motivos registados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
