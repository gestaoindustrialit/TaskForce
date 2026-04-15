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

function break_duration_to_seconds(string $duration): int
{
    $normalized = trim($duration);
    if (!preg_match('/^(\d{1,3}):(\d{2})$/', $normalized, $matches)) {
        return 0;
    }

    $minutes = (int) $matches[1];
    $seconds = (int) $matches[2];
    if ($seconds > 59) {
        return 0;
    }

    return max(0, ($minutes * 60) + $seconds);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_types') {
        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $pdo->beginTransaction();
        try {
            $saveTypeStmt = $pdo->prepare('UPDATE shopfloor_break_reasons SET code = ?, label = ?, notes = ?, planned_seconds = ?, break_type = ?, requires_comment = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            foreach ($rows as $rowId => $rowData) {
                if (!is_array($rowData)) {
                    continue;
                }

                $id = (int) $rowId;
                $code = strtoupper(trim((string) ($rowData['code'] ?? '')));
                $label = trim((string) ($rowData['label'] ?? ''));
                if ($id <= 0 || $code === '' || $label === '') {
                    continue;
                }

                $breakType = trim((string) ($rowData['break_type'] ?? 'Pausa'));
                if (!in_array($breakType, ['Pausa', 'Paragem'], true)) {
                    $breakType = 'Pausa';
                }

                $saveTypeStmt->execute([
                    $code,
                    $label,
                    trim((string) ($rowData['notes'] ?? '')) ?: null,
                    break_duration_to_seconds((string) ($rowData['planned_duration'] ?? '00:00')),
                    $breakType,
                    isset($rowData['requires_comment']) ? 1 : 0,
                    isset($rowData['is_active']) ? 1 : 0,
                    $id,
                ]);
            }

            $newCode = strtoupper(trim((string) ($_POST['new']['code'] ?? '')));
            $newLabel = trim((string) ($_POST['new']['label'] ?? ''));
            if ($newCode !== '' && $newLabel !== '') {
                $newType = trim((string) ($_POST['new']['break_type'] ?? 'Pausa'));
                if (!in_array($newType, ['Pausa', 'Paragem'], true)) {
                    $newType = 'Pausa';
                }

                $insertTypeStmt = $pdo->prepare('INSERT INTO shopfloor_break_reasons(code, label, notes, planned_seconds, break_type, requires_comment, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
                $insertTypeStmt->execute([
                    $newCode,
                    $newLabel,
                    trim((string) ($_POST['new']['notes'] ?? '')) ?: null,
                    break_duration_to_seconds((string) ($_POST['new']['planned_duration'] ?? '00:00')),
                    $newType,
                    isset($_POST['new']['requires_comment']) ? 1 : 0,
                    $userId,
                ]);
            }

            $pdo->commit();
            $flashSuccess = 'Tipos de pausa/paragem guardados com sucesso.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flashError = 'Não foi possível guardar os tipos: ' . $exception->getMessage();
        }
    }

    if ($action === 'create_entry' || $action === 'update_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $reasonId = (int) ($_POST['break_reason_id'] ?? 0);
        $startAt = trim((string) ($_POST['started_at'] ?? ''));
        $endAtRaw = trim((string) ($_POST['ended_at'] ?? ''));
        $endAt = $endAtRaw !== '' ? $endAtRaw : null;
        $comment = trim((string) ($_POST['comment'] ?? ''));

        $reasonStmt = $pdo->prepare('SELECT id, break_type, requires_comment FROM shopfloor_break_reasons WHERE id = ? LIMIT 1');
        $reasonStmt->execute([$reasonId]);
        $reason = $reasonStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($targetUserId <= 0 || !$reason || $startAt === '') {
            $flashError = 'Preencha utilizador, tipo e data de início.';
        } elseif ((int) ($reason['requires_comment'] ?? 0) === 1 && $comment === '') {
            $flashError = 'Este motivo exige comentário obrigatório.';
        } elseif ($endAt !== null && strtotime($endAt) !== false && strtotime($startAt) !== false && strtotime($endAt) < strtotime($startAt)) {
            $flashError = 'A data de fim não pode ser anterior à data de início.';
        } else {
            if ($action === 'create_entry') {
                $insertEntryStmt = $pdo->prepare('INSERT INTO shopfloor_break_entries(user_id, break_reason_id, break_type, comment, started_at, ended_at) VALUES (?, ?, ?, ?, ?, ?)');
                $insertEntryStmt->execute([
                    $targetUserId,
                    $reasonId,
                    (string) ($reason['break_type'] ?? 'Pausa'),
                    $comment !== '' ? $comment : null,
                    $startAt,
                    $endAt,
                ]);
                $flashSuccess = 'Registo de pausa/paragem criado com sucesso.';
            } else {
                $updateEntryStmt = $pdo->prepare('UPDATE shopfloor_break_entries SET user_id = ?, break_reason_id = ?, break_type = ?, comment = ?, started_at = ?, ended_at = ? WHERE id = ?');
                $updateEntryStmt->execute([
                    $targetUserId,
                    $reasonId,
                    (string) ($reason['break_type'] ?? 'Pausa'),
                    $comment !== '' ? $comment : null,
                    $startAt,
                    $endAt,
                    $entryId,
                ]);
                $flashSuccess = 'Registo de pausa/paragem atualizado com sucesso.';
            }
        }
    }

    if ($action === 'delete_entry') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        if ($entryId > 0) {
            $deleteEntryStmt = $pdo->prepare('DELETE FROM shopfloor_break_entries WHERE id = ?');
            $deleteEntryStmt->execute([$entryId]);
            $flashSuccess = 'Registo removido com sucesso.';
        }
    }
}

$usersStmt = $pdo->query('SELECT id, name, username FROM users ORDER BY name COLLATE NOCASE ASC');
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$reasonsStmt = $pdo->query('SELECT id, code, label, notes, planned_seconds, break_type, requires_comment, is_active FROM shopfloor_break_reasons ORDER BY code COLLATE NOCASE ASC');
$reasons = $reasonsStmt ? $reasonsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$filters = [
    'user_id' => (int) ($_GET['user_id'] ?? 0),
    'break_type' => trim((string) ($_GET['break_type'] ?? '')),
    'break_reason_id' => (int) ($_GET['break_reason_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
if (!in_array($filters['break_type'], ['', 'Pausa', 'Paragem'], true)) {
    $filters['break_type'] = '';
}

$where = [];
$params = [];
if ($filters['user_id'] > 0) {
    $where[] = 'b.user_id = ?';
    $params[] = $filters['user_id'];
}
if ($filters['break_type'] !== '') {
    $where[] = 'b.break_type = ?';
    $params[] = $filters['break_type'];
}
if ($filters['break_reason_id'] > 0) {
    $where[] = 'b.break_reason_id = ?';
    $params[] = $filters['break_reason_id'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'date(b.started_at) >= date(?)';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'date(b.started_at) <= date(?)';
    $params[] = $filters['date_to'];
}
if ($filters['search'] !== '') {
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR r.label LIKE ? OR r.code LIKE ? OR COALESCE(b.comment, "") LIKE ?)';
    $searchTerm = '%' . $filters['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$entriesSql = 'SELECT b.id, b.user_id, u.name AS user_name, b.started_at, b.ended_at, b.break_type, b.comment, b.break_reason_id, r.code, r.label FROM shopfloor_break_entries b INNER JOIN users u ON u.id = b.user_id INNER JOIN shopfloor_break_reasons r ON r.id = b.break_reason_id';
if ($where) {
    $entriesSql .= ' WHERE ' . implode(' AND ', $where);
}
$entriesSql .= ' ORDER BY b.started_at DESC LIMIT 250';

$entriesStmt = $pdo->prepare($entriesSql);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pausas e paragens';
$bodyClass = 'bg-light';
require __DIR__ . '/partials/header.php';
?>

<section class="shopfloor-shell d-flex flex-column">
    <div class="shopfloor-panel mb-4" style="order:2;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">Pausas e paragens</h1>
            <span class="small text-secondary">Gestão RH/Admin de tipos e histórico.</span>
        </div>

        <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="save_types">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Código</th><th>Nome</th><th>Observações</th><th>Duração (mm:ss)</th><th>Tipo</th><th class="text-center">Comentário obrigatório</th><th class="text-center">Ativo</th></tr></thead>
                    <tbody>
                        <?php foreach ($reasons as $reason): ?>
                            <?php $plannedSeconds = max(0, (int) ($reason['planned_seconds'] ?? 0)); ?>
                            <tr>
                                <td><input class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][code]" value="<?= h((string) $reason['code']) ?>" required></td>
                                <td><input class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][label]" value="<?= h((string) $reason['label']) ?>" required></td>
                                <td><input class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][notes]" value="<?= h((string) ($reason['notes'] ?? '')) ?>"></td>
                                <td><input class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][planned_duration]" value="<?= h(sprintf('%02d:%02d', intdiv($plannedSeconds, 60), $plannedSeconds % 60)) ?>"></td>
                                <td><select class="form-select form-select-sm" name="rows[<?= (int) $reason['id'] ?>][break_type]"><option value="Pausa" <?= (string) $reason['break_type'] === 'Pausa' ? 'selected' : '' ?>>Pausa</option><option value="Paragem" <?= (string) $reason['break_type'] === 'Paragem' ? 'selected' : '' ?>>Paragem</option></select></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[<?= (int) $reason['id'] ?>][requires_comment]" <?= (int) ($reason['requires_comment'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[<?= (int) $reason['id'] ?>][is_active]" <?= (int) ($reason['is_active'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light">
                            <td><input class="form-control form-control-sm" name="new[code]" placeholder="09"></td>
                            <td><input class="form-control form-control-sm" name="new[label]" placeholder="Nova pausa/paragem"></td>
                            <td><input class="form-control form-control-sm" name="new[notes]" placeholder="Opcional"></td>
                            <td><input class="form-control form-control-sm" name="new[planned_duration]" value="00:00"></td>
                            <td><select class="form-select form-select-sm" name="new[break_type]"><option value="Pausa">Pausa</option><option value="Paragem">Paragem</option></select></td>
                            <td class="text-center"><input type="checkbox" class="form-check-input" name="new[requires_comment]"></td>
                            <td class="text-center">Novo</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">Guardar alterações</button>
            </div>
        </form>
    </div>

    <div class="shopfloor-panel" style="order:1;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h4 mb-0">Registos de pausas e paragens</h2>
            <span class="small text-secondary">Listagem com filtro e edição manual.</span>
        </div>

        <form method="get" class="row g-2 mb-3">
            <div class="col-md-3"><label class="form-label">Utilizador</label><select name="user_id" class="form-select"><option value="0">Todos</option><?php foreach ($users as $listUser): ?><option value="<?= (int) $listUser['id'] ?>" <?= $filters['user_id'] === (int) $listUser['id'] ? 'selected' : '' ?>><?= h((string) $listUser['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Tipo</label><select name="break_type" class="form-select"><option value="">Todos</option><option value="Pausa" <?= $filters['break_type'] === 'Pausa' ? 'selected' : '' ?>>Pausa</option><option value="Paragem" <?= $filters['break_type'] === 'Paragem' ? 'selected' : '' ?>>Paragem</option></select></div>
            <div class="col-md-3"><label class="form-label">Motivo</label><select name="break_reason_id" class="form-select"><option value="0">Todos</option><?php foreach ($reasons as $reason): ?><option value="<?= (int) $reason['id'] ?>" <?= $filters['break_reason_id'] === (int) $reason['id'] ? 'selected' : '' ?>><?= h((string) $reason['code']) ?> | <?= h((string) $reason['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Data início (de)</label><input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Data início (até)</label><input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>"></div>
            <div class="col-md-8"><label class="form-label">Pesquisa</label><input type="text" name="search" class="form-control" value="<?= h($filters['search']) ?>" placeholder="Nome, utilizador, motivo, comentário..."></div>
            <div class="col-md-4 d-flex align-items-end gap-2"><button type="submit" class="btn btn-outline-primary w-100">Filtrar</button><a href="shopfloor_break_reasons.php" class="btn btn-outline-secondary w-100">Limpar</a></div>
        </form>

        <div class="border rounded p-3 bg-light mb-3">
            <h3 class="h6">Novo registo</h3>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="create_entry">
                <div class="col-md-3"><label class="form-label">Utilizador</label><select name="user_id" class="form-select" required><option value="">Selecionar</option><?php foreach ($users as $listUser): ?><option value="<?= (int) $listUser['id'] ?>"><?= h((string) $listUser['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Motivo</label><select name="break_reason_id" class="form-select" required><option value="">Selecionar</option><?php foreach ($reasons as $reason): ?><option value="<?= (int) $reason['id'] ?>"><?= h((string) $reason['code']) ?> | <?= h((string) $reason['label']) ?> (<?= h((string) $reason['break_type']) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">Início</label><input type="datetime-local" name="started_at" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">Fim</label><input type="datetime-local" name="ended_at" class="form-control"></div>
                <div class="col-md-2"><label class="form-label">Comentário</label><input type="text" name="comment" class="form-control"></div>
                <div class="col-12"><button type="submit" class="btn btn-success">Guardar registo</button></div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>ID</th><th>Utilizador</th><th>Início</th><th>Fim</th><th>Tipo</th><th>Motivo</th><th>Comentário</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                    <?php if (!$entries): ?>
                        <tr><td colspan="8" class="text-secondary">Sem registos para os filtros selecionados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="update_entry">
                                <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                <td><?= (int) $entry['id'] ?></td>
                                <td><select name="user_id" class="form-select form-select-sm"><?php foreach ($users as $listUser): ?><option value="<?= (int) $listUser['id'] ?>" <?= (int) $entry['user_id'] === (int) $listUser['id'] ? 'selected' : '' ?>><?= h((string) $listUser['name']) ?></option><?php endforeach; ?></select></td>
                                <td><input type="datetime-local" name="started_at" class="form-control form-control-sm" value="<?= h(date('Y-m-d\TH:i', strtotime((string) $entry['started_at']))) ?>"></td>
                                <td><input type="datetime-local" name="ended_at" class="form-control form-control-sm" value="<?= !empty($entry['ended_at']) ? h(date('Y-m-d\TH:i', strtotime((string) $entry['ended_at']))) : '' ?>"></td>
                                <td><?= h((string) $entry['break_type']) ?></td>
                                <td><select name="break_reason_id" class="form-select form-select-sm"><?php foreach ($reasons as $reason): ?><option value="<?= (int) $reason['id'] ?>" <?= (int) $entry['break_reason_id'] === (int) $reason['id'] ? 'selected' : '' ?>><?= h((string) $reason['code']) ?> | <?= h((string) $reason['label']) ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" name="comment" class="form-control form-control-sm" value="<?= h((string) ($entry['comment'] ?? '')) ?>"></td>
                                <td class="text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Eliminar este registo?');">
                                <input type="hidden" name="action" value="delete_entry">
                                <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                            </form>
                                </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php';
