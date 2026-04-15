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

    if ($action === 'save_rows') {
        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $pdo->beginTransaction();
        try {
            $saveStmt = $pdo->prepare('UPDATE shopfloor_break_reasons SET code = ?, label = ?, notes = ?, planned_seconds = ?, break_type = ?, requires_comment = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            foreach ($rows as $rowId => $rowData) {
                $id = (int) $rowId;
                if ($id <= 0 || !is_array($rowData)) {
                    continue;
                }

                $code = strtoupper(trim((string) ($rowData['code'] ?? '')));
                $label = trim((string) ($rowData['label'] ?? ''));
                if ($code === '' || $label === '') {
                    continue;
                }

                $notes = trim((string) ($rowData['notes'] ?? ''));
                $duration = trim((string) ($rowData['planned_duration'] ?? '00:00'));
                $type = trim((string) ($rowData['break_type'] ?? 'Pausa'));
                $requiresComment = isset($rowData['requires_comment']) ? 1 : 0;
                $isActive = isset($rowData['is_active']) ? 1 : 0;

                if (!in_array($type, ['Pausa', 'Paragem'], true)) {
                    $type = 'Pausa';
                }

                $parts = explode(':', $duration);
                $minutes = (count($parts) === 2) ? (((int) $parts[0]) * 60 + (int) $parts[1]) : 0;
                $plannedSeconds = max(0, $minutes * 60);

                $saveStmt->execute([
                    $code,
                    $label,
                    $notes !== '' ? $notes : null,
                    $plannedSeconds,
                    $type,
                    $requiresComment,
                    $isActive,
                    $id,
                ]);
            }

            if (isset($_POST['new']) && is_array($_POST['new'])) {
                $newCode = strtoupper(trim((string) ($_POST['new']['code'] ?? '')));
                $newLabel = trim((string) ($_POST['new']['label'] ?? ''));
                if ($newCode !== '' && $newLabel !== '') {
                    $newDuration = trim((string) ($_POST['new']['planned_duration'] ?? '00:00'));
                    $newType = trim((string) ($_POST['new']['break_type'] ?? 'Pausa'));
                    $newRequiresComment = isset($_POST['new']['requires_comment']) ? 1 : 0;
                    $parts = explode(':', $newDuration);
                    $minutes = (count($parts) === 2) ? (((int) $parts[0]) * 60 + (int) $parts[1]) : 0;
                    $plannedSeconds = max(0, $minutes * 60);
                    if (!in_array($newType, ['Pausa', 'Paragem'], true)) {
                        $newType = 'Pausa';
                    }

                    $insertStmt = $pdo->prepare('INSERT INTO shopfloor_break_reasons(code, label, notes, planned_seconds, break_type, requires_comment, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
                    $insertStmt->execute([
                        $newCode,
                        $newLabel,
                        trim((string) ($_POST['new']['notes'] ?? '')) ?: null,
                        $plannedSeconds,
                        $newType,
                        $newRequiresComment,
                        $userId,
                    ]);
                }
            }

            $pdo->commit();
            $flashSuccess = 'Tipos de pausa/paragem guardados com sucesso.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flashError = 'Não foi possível guardar alterações: ' . $exception->getMessage();
        }
    }

}

$reasonsStmt = $pdo->query('SELECT id, code, label, notes, planned_seconds, break_type, requires_comment, is_active FROM shopfloor_break_reasons ORDER BY code COLLATE NOCASE ASC, id ASC');
$reasons = $reasonsStmt ? $reasonsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$pageTitle = 'Pausas e paragens';
$bodyClass = 'bg-light';
require __DIR__ . '/partials/header.php';
?>

<section class="shopfloor-shell">
    <div class="shopfloor-panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">Pausas e paragens</h1>
            <a href="shopfloor.php" class="btn btn-outline-secondary btn-sm">Voltar ao Shopfloor</a>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success"><?= h($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert alert-danger"><?= h($flashError) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="save_rows">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="min-width: 90px;">Código</th>
                            <th style="min-width: 220px;">Nome</th>
                            <th style="min-width: 200px;">Observações</th>
                            <th style="min-width: 130px;">Duração (mm:ss)</th>
                            <th style="min-width: 160px;">Tipo</th>
                            <th class="text-center" style="min-width: 170px;">Comentário obrigatório</th>
                            <th class="text-center" style="min-width: 110px;">Ativo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reasons as $reason): ?>
                            <?php $plannedSeconds = max(0, (int) ($reason['planned_seconds'] ?? 0)); ?>
                            <tr>
                                <td><input type="text" class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][code]" value="<?= h((string) $reason['code']) ?>" required></td>
                                <td><input type="text" class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][label]" value="<?= h((string) $reason['label']) ?>" required></td>
                                <td><input type="text" class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][notes]" value="<?= h((string) ($reason['notes'] ?? '')) ?>"></td>
                                <td><input type="text" class="form-control form-control-sm" name="rows[<?= (int) $reason['id'] ?>][planned_duration]" value="<?= h(sprintf('%02d:%02d', intdiv($plannedSeconds, 60), $plannedSeconds % 60)) ?>"></td>
                                <td>
                                    <select class="form-select form-select-sm" name="rows[<?= (int) $reason['id'] ?>][break_type]">
                                        <option value="Pausa" <?= (string) ($reason['break_type'] ?? '') === 'Pausa' ? 'selected' : '' ?>>Pausa</option>
                                        <option value="Paragem" <?= (string) ($reason['break_type'] ?? '') === 'Paragem' ? 'selected' : '' ?>>Paragem</option>
                                    </select>
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[<?= (int) $reason['id'] ?>][requires_comment]" <?= (int) ($reason['requires_comment'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="rows[<?= (int) $reason['id'] ?>][is_active]" <?= (int) ($reason['is_active'] ?? 0) === 1 ? 'checked' : '' ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light">
                            <td><input type="text" class="form-control form-control-sm" name="new[code]" placeholder="09"></td>
                            <td><input type="text" class="form-control form-control-sm" name="new[label]" placeholder="Nova pausa/paragem"></td>
                            <td><input type="text" class="form-control form-control-sm" name="new[notes]" placeholder="Opcional"></td>
                            <td><input type="text" class="form-control form-control-sm" name="new[planned_duration]" value="00:00"></td>
                            <td>
                                <select class="form-select form-select-sm" name="new[break_type]">
                                    <option value="Pausa">Pausa</option>
                                    <option value="Paragem">Paragem</option>
                                </select>
                            </td>
                            <td class="text-center"><input type="checkbox" class="form-check-input" name="new[requires_comment]"></td>
                            <td class="text-center">Novo</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Guardar alterações</button>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php';
