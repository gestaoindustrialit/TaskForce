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
$allowedReasonTypes = ['Ausência', 'Atraso', 'Outro'];

$editingReasonId = (int) ($_GET['edit_id'] ?? 0);
$editingReason = null;
if ($editingReasonId > 0) {
    $editStmt = $pdo->prepare('SELECT id, reason_type, reason_code, sage_code, label, color, show_in_shopfloor FROM shopfloor_absence_reasons WHERE id = ? LIMIT 1');
    $editStmt->execute([$editingReasonId]);
    $editingReason = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editingReason) {
        $editingReasonId = 0;
    }
}

if (!function_exists('reason_sort_link')) {
    function reason_sort_link(string $column, string $label, string $currentSort, string $currentDirection): string
    {
        $isCurrent = $column === $currentSort;
        $nextDirection = $isCurrent && $currentDirection === 'ASC' ? 'desc' : 'asc';
        $arrow = $isCurrent ? ($currentDirection === 'ASC' ? '↑' : '↓') : '↕';

        return '<a class="text-decoration-none text-reset d-inline-flex align-items-center gap-1" href="?sort=' . urlencode($column) . '&direction=' . urlencode($nextDirection) . '">' . h($label) . '<span class="small text-muted">' . $arrow . '</span></a>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $reasonType = trim((string) ($_POST['reason_type'] ?? 'Ausência'));
    if (!in_array($reasonType, $allowedReasonTypes, true)) {
        $reasonType = 'Ausência';
    }
    $reasonCode = strtoupper(trim((string) ($_POST['reason_code'] ?? '')));
    $sageCode = strtoupper(trim((string) ($_POST['sage_code'] ?? '')));
    $label = trim((string) ($_POST['label'] ?? ''));
    $color = trim((string) ($_POST['color'] ?? '#2563eb'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#2563eb';
    }

    if ($action === 'create_reason' || $action === 'update_reason') {
        if ($label === '') {
            $flashError = 'Indique a descrição do motivo.';
        } elseif ($reasonCode === '') {
            $flashError = 'Indique o código do motivo.';
        } elseif ($sageCode === '') {
            $flashError = 'Indique o código SAGE do motivo.';
        } else {
            try {
                if ($action === 'create_reason') {
                    $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_reasons(reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, 1, ?)');
                    $stmt->execute([$reasonType, $reasonCode, $sageCode, $label, $color, $userId]);
                    log_app_event($pdo, $userId, 'shopfloor.absence_reason.create', 'Motivo de ausência criado.', ['reason_type' => $reasonType, 'reason_code' => $reasonCode, 'sage_code' => $sageCode, 'label' => $label, 'color' => $color]);
                    $flashSuccess = 'Motivo criado com sucesso.';
                } else {
                    $reasonId = (int) ($_POST['reason_id'] ?? 0);
                    if ($reasonId <= 0) {
                        $flashError = 'Motivo inválido para edição.';
                    } else {
                        $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET reason_type = ?, reason_code = ?, sage_code = ?, label = ?, color = ? WHERE id = ?');
                        $stmt->execute([$reasonType, $reasonCode, $sageCode, $label, $color, $reasonId]);
                        log_app_event($pdo, $userId, 'shopfloor.absence_reason.update', 'Motivo de ausência editado.', ['reason_id' => $reasonId, 'reason_type' => $reasonType, 'reason_code' => $reasonCode, 'sage_code' => $sageCode, 'label' => $label, 'color' => $color]);
                        $flashSuccess = 'Motivo atualizado com sucesso.';
                        $editingReasonId = 0;
                        $editingReason = null;
                    }
                }
            } catch (PDOException $exception) {
                $flashError = 'Não foi possível guardar o motivo (o código do motivo ou a descrição já existem).';
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

    if ($action === 'toggle_shopfloor_visibility') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $showInShopfloor = (int) ($_POST['show_in_shopfloor'] ?? 0) === 1 ? 1 : 0;
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido.';
        } else {
            $stmt = $pdo->prepare('UPDATE shopfloor_absence_reasons SET show_in_shopfloor = ? WHERE id = ?');
            $stmt->execute([$showInShopfloor, $reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.shopfloor_visibility', 'Visibilidade do motivo no Shopfloor alterada.', ['reason_id' => $reasonId, 'show_in_shopfloor' => $showInShopfloor]);
            $flashSuccess = 'Visibilidade do motivo no Shopfloor atualizada.';
        }
    }

    if ($action === 'delete_reason') {
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        if ($reasonId <= 0) {
            $flashError = 'Motivo inválido para eliminar.';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM shopfloor_absence_reasons WHERE id = ?');
            $deleteStmt->execute([$reasonId]);
            log_app_event($pdo, $userId, 'shopfloor.absence_reason.delete', 'Motivo de ausência eliminado.', ['reason_id' => $reasonId]);
            $flashSuccess = 'Motivo eliminado com sucesso.';
            if ($editingReasonId === $reasonId) {
                $editingReasonId = 0;
                $editingReason = null;
            }
        }
    }
}

$allowedSortFields = [
    'reason_type' => 'reason_type',
    'reason_code' => 'reason_code',
    'sage_code' => 'sage_code',
    'label' => 'label',
    'created_at' => 'created_at',
    'is_active' => 'is_active'
];
$sort = (string) ($_GET['sort'] ?? 'label');
if (!array_key_exists($sort, $allowedSortFields)) {
    $sort = 'label';
}
$direction = strtolower((string) ($_GET['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$orderBy = $allowedSortFields[$sort];
$reasons = $pdo->query('SELECT id, reason_type, reason_code, sage_code, label, color, show_in_shopfloor, is_active, created_at FROM shopfloor_absence_reasons ORDER BY is_active DESC, ' . $orderBy . ' COLLATE NOCASE ' . $direction . ', label COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

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
        <h2 class="h4 mb-3"><?= $editingReason ? 'Editar motivo' : 'Novo motivo' ?></h2>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="<?= $editingReason ? 'update_reason' : 'create_reason' ?>">
            <?php if ($editingReason): ?>
                <input type="hidden" name="reason_id" value="<?= (int) $editingReason['id'] ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <select name="reason_type" class="form-select" required>
                    <?php foreach ($allowedReasonTypes as $type): ?>
                        <option value="<?= h($type) ?>" <?= ($editingReason && (string) $editingReason['reason_type'] === $type) ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Código do motivo</label>
                <input type="text" name="reason_code" class="form-control" placeholder="Ex: MOT-010" value="<?= h((string) ($editingReason['reason_code'] ?? '')) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Código SAGE</label>
                <input type="text" name="sage_code" class="form-control" placeholder="Ex: 100" value="<?= h((string) ($editingReason['sage_code'] ?? '')) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Descrição</label>
                <input type="text" name="label" class="form-control" placeholder="Ex: Falta sem perda de remuneração" value="<?= h((string) ($editingReason['label'] ?? '')) ?>" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Cor</label>
                <input type="color" name="color" class="form-control form-control-color" value="<?= h((string) ($editingReason['color'] ?? '#2563eb')) ?>" title="Escolher cor">
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-primary" title="Guardar motivo" aria-label="Guardar motivo">
                    <i class="bi bi-floppy"></i>
                </button>
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
                    <th><?= reason_sort_link('reason_type', 'Tipo', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('reason_code', 'Cód. motivo', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('sage_code', 'Cód. SAGE', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('label', 'Motivo', $sort, $direction) ?></th>
                    <th>Cor</th>
                    <th>Shopfloor</th>
                    <th><?= reason_sort_link('created_at', 'Criado em', $sort, $direction) ?></th>
                    <th><?= reason_sort_link('is_active', 'Estado', $sort, $direction) ?></th>
                    <th class="text-end">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($reasons): foreach ($reasons as $reason): ?>
                    <tr>
                        <td><?= h((string) ($reason['reason_type'] ?? 'Ausência')) ?></td>
                        <td><span class="badge text-bg-light border"><?= h((string) $reason['reason_code']) ?></span></td>
                        <td><span class="badge text-bg-light border"><?= h((string) $reason['sage_code']) ?></span></td>
                        <td class="fw-semibold"><?= h((string) $reason['label']) ?></td>
                        <td>
                            <span class="d-inline-block rounded border" style="width:20px;height:20px;background:<?= h((string) $reason['color']) ?>;"></span>
                            <span class="small text-muted ms-1"><?= h((string) $reason['color']) ?></span>
                        </td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle_shopfloor_visibility">
                                <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                <input type="hidden" name="show_in_shopfloor" value="<?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-sm <?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 'btn-outline-secondary' : 'btn-outline-warning' ?>">
                                    <?= (int) ($reason['show_in_shopfloor'] ?? 1) === 1 ? 'Visível' : 'Oculto' ?>
                                </button>
                            </form>
                        </td>
                        <td><?= h((string) $reason['created_at']) ?></td>
                        <td>
                            <?php if ((int) $reason['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a href="shopfloor_absence_reasons.php?edit_id=<?= (int) $reason['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar" aria-label="Editar">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_reason">
                                    <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= (int) $reason['is_active'] === 1 ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= (int) $reason['is_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>" title="<?= (int) $reason['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>" aria-label="<?= (int) $reason['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>">
                                        <i class="bi <?= (int) $reason['is_active'] === 1 ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Tem a certeza que pretende eliminar este motivo?');">
                                    <input type="hidden" name="action" value="delete_reason">
                                    <input type="hidden" name="reason_id" value="<?= (int) $reason['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar" aria-label="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-muted">Sem motivos registados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
