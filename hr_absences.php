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

if (!function_exists('format_absence_employee_label')) {
    function format_absence_employee_label(array $user): string
    {
        $userLabel = format_user_picker_label($user);
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            return $userLabel;
        }

        $nameParts = preg_split('/\s+/', $name) ?: [];
        if (count($nameParts) >= 2) {
            $compactName = trim($nameParts[0] . ' ' . $nameParts[count($nameParts) - 1]);
            $labelNumber = trim((string) ($user['user_number'] ?? ''));
            if ($labelNumber === '') {
                $labelNumber = (string) ((int) ($user['id'] ?? 0));
            }

            return trim($labelNumber . ' - ' . $compactName, ' -');
        }

        return $userLabel;
    }
}

$users = $pdo->query('SELECT id, name, user_number FROM users WHERE is_active = 1 AND pin_only_login = 0 AND TRIM(COALESCE(name, "")) <> "" ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$reasons = $pdo->query('SELECT reason_type, code, label FROM shopfloor_absence_reasons WHERE is_active = 1 ORDER BY reason_type COLLATE NOCASE ASC, label COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'submit_absence') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($targetUserId <= 0 || $startDate === '' || $endDate === '' || $startDate > $endDate || $reasonCode === '') {
            $flashError = 'Preencha todos os campos obrigatórios do pedido.';
        } else {
            $label = '';
            foreach ($reasons as $reason) {
                if ((string) $reason['code'] === $reasonCode) {
                    $label = (string) $reason['label'];
                    break;
                }
            }
            $reasonText = $label !== '' ? $reasonCode . ' - ' . $label : $reasonCode;
            $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_requests(user_id, request_type, start_date, end_date, reason, details, status) VALUES (?, "Dias inteiros", ?, ?, ?, ?, "Pendente Nível 1")');
            $stmt->execute([$targetUserId, $startDate, $endDate, $reasonText, $notes]);
            $flashSuccess = 'Pedido submetido para aprovação N1.';
        }
    }

    if ($action === 'review_absence') {
        $absenceId = (int) ($_POST['absence_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $level = (string) ($_POST['level'] ?? '');
        $newStatus = null;
        if ($decision === 'reject') {
            $newStatus = 'Rejeitado';
        } elseif ($decision === 'approve' && $level === 'n1') {
            $newStatus = 'Pendente RH';
        } elseif ($decision === 'approve' && $level === 'rh') {
            $newStatus = 'Aprovado';
        }

        if ($absenceId <= 0 || $newStatus === null) {
            $flashError = 'Não foi possível atualizar o pedido.';
        } else {
            $stmt = $pdo->prepare('UPDATE shopfloor_absence_requests SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$newStatus, $userId, $absenceId]);
            $flashSuccess = 'Estado atualizado com sucesso.';
        }
    }
}

$pendingStmt = $pdo->query('SELECT a.id, a.user_id, a.start_date, a.end_date, a.reason, a.status, u.name AS user_name, u.user_number FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id WHERE a.status IN ("Pendente Nível 1", "Pendente RH") ORDER BY a.created_at ASC LIMIT 50');
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$requestsStmt = $pdo->query('SELECT a.id, a.user_id, a.start_date, a.end_date, a.reason, a.details, a.status, a.created_at, u.name AS user_name, u.user_number FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 200');
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Ausências RH';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Ausências / Comunicação & workflow</h1>
        <p class="text-muted mb-0">Pedidos com aprovação N1 → N2(RH), comentários e histórico.</p>
    </div>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h5">Novo pedido de ausência</h2>
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="submit_absence">
            <div class="col-md-3"><label class="form-label">Colaborador</label><select class="form-select" name="user_id" required><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= h(format_absence_employee_label($u)) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" required></div>
            <div class="col-md-3"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" required></div>
            <div class="col-md-3"><label class="form-label">Motivo</label><select class="form-select" name="reason_code" required><option value="">Selecione</option><?php foreach ($reasons as $r): ?><option value="<?= h((string) $r['code']) ?>"><?= h(trim((string) ($r['reason_type'] ?? 'Ausência')) . ' · ' . (string) $r['code'] . ' - ' . (string) $r['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Justificação / Observações</label><input class="form-control" name="notes" placeholder="Visível em todas as etapas"></div>
            <div class="col-md-3 d-grid"><button class="btn btn-dark">Submeter pedido</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between"><h2 class="h5 mb-0">Pendentes de aprovação</h2><span class="badge text-bg-warning"><?= count($pending) ?></span></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle"><thead><tr><th>Colaborador</th><th>Período</th><th>Motivo</th><th>Estado</th><th>Ações</th></tr></thead><tbody>
            <?php if (!$pending): ?><tr><td colspan="5" class="text-muted">Sem pendentes.</td></tr><?php endif; ?>
            <?php foreach ($pending as $row): ?><tr><td><?= h(format_absence_employee_label(['id' => $row['user_id'] ?? 0, 'user_number' => $row['user_number'] ?? '', 'name' => $row['user_name'] ?? ''])) ?></td><td><?= h((string) $row['start_date']) ?> → <?= h((string) $row['end_date']) ?></td><td><?= h((string) $row['reason']) ?></td><td><span class="badge text-bg-secondary"><?= h((string) $row['status']) ?></span></td><td><div class="d-flex gap-1"><?php if ((string) $row['status'] === 'Pendente Nível 1'): ?><form method="post"><input type="hidden" name="action" value="review_absence"><input type="hidden" name="absence_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="decision" value="approve"><input type="hidden" name="level" value="n1"><button class="btn btn-sm btn-outline-success">Aprovar N1</button></form><?php endif; ?><?php if ((string) $row['status'] === 'Pendente RH'): ?><form method="post"><input type="hidden" name="action" value="review_absence"><input type="hidden" name="absence_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="decision" value="approve"><input type="hidden" name="level" value="rh"><button class="btn btn-sm btn-outline-success">Aprovar RH</button></form><?php endif; ?><form method="post"><input type="hidden" name="action" value="review_absence"><input type="hidden" name="absence_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="decision" value="reject"><input type="hidden" name="level" value="<?= (string) $row['status'] === 'Pendente RH' ? 'rh' : 'n1' ?>"><button class="btn btn-sm btn-outline-danger">Rejeitar</button></form></div></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Pedidos (após pendentes)</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle"><thead><tr><th>Colaborador</th><th>Período</th><th>Estado</th><th>Motivo</th><th>Observações</th></tr></thead><tbody>
            <?php if (!$requests): ?><tr><td colspan="5" class="text-muted">Sem pedidos registados.</td></tr><?php endif; ?>
            <?php foreach ($requests as $row): ?><tr><td><?= h(format_absence_employee_label(['id' => $row['user_id'] ?? 0, 'user_number' => $row['user_number'] ?? '', 'name' => $row['user_name'] ?? ''])) ?></td><td><?= h((string) $row['start_date']) ?> → <?= h((string) $row['end_date']) ?></td><td><span class="badge text-bg-light border"><?= h((string) $row['status']) ?></span></td><td><?= h((string) $row['reason']) ?></td><td><?= h((string) ($row['details'] ?? '')) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
