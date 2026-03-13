<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores.');
}

$flashSuccess = null;
$flashError = null;
$alertTypeOptions = [
    'absences_daily' => 'Ausências previstas do dia',
    'vacations_weekly' => 'Resumo semanal de férias',
    'attendance_summary' => 'Resumo de assiduidade (futuro)',
];
$weekdayLabels = ['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_alert' || $action === 'update_alert') {
        $id = (int) ($_POST['alert_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['alert_type'] ?? 'absences_daily'));
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        $sendTime = trim((string) ($_POST['send_time'] ?? '08:00'));
        $weekdays = $_POST['weekdays'] ?? [];
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $weekdays = is_array($weekdays) ? array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', $weekdays))) : [];
        $weekdaysMask = count($weekdays) > 0 ? implode(',', $weekdays) : '1,2,3,4,5';

        if ($name === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{2}:\d{2}$/', $sendTime)) {
            $flashError = 'Preencha nome, email válido e hora de envio.';
        } else {
            if (!array_key_exists($type, $alertTypeOptions)) {
                $type = 'absences_daily';
            }

            if ($action === 'create_alert') {
                $stmt = $pdo->prepare('INSERT INTO hr_alerts(name, alert_type, recipient_email, send_time, weekdays_mask, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $type, $recipient, $sendTime, $weekdaysMask, $isActive, $userId]);
                $flashSuccess = 'Alerta criado com sucesso.';
            } else {
                $stmt = $pdo->prepare('UPDATE hr_alerts SET name = ?, alert_type = ?, recipient_email = ?, send_time = ?, weekdays_mask = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $type, $recipient, $sendTime, $weekdaysMask, $isActive, $id]);
                $flashSuccess = 'Alerta atualizado com sucesso.';
            }
        }
    }
}

$alerts = $pdo->query('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, is_active, created_at FROM hr_alerts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Alertas RH';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Alertas RH por e-mail</h1>
<p class="text-muted">Exemplo suportado: envio às 08:00 para a responsável de RH com as ausências previstas do dia.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Novo alerta</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="create_alert">
            <div class="col-md-3"><label class="form-label">Nome interno</label><input class="form-control" name="name" placeholder="Resumo ausências 08:00" required></div>
            <div class="col-md-3"><label class="form-label">Tipo</label><select class="form-select" name="alert_type"><?php foreach ($alertTypeOptions as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">E-mail destino</label><input class="form-control" type="email" name="recipient_email" placeholder="rh@empresa.pt" required></div>
            <div class="col-md-2"><label class="form-label">Hora envio</label><input class="form-control" type="time" name="send_time" value="08:00" required></div>
            <div class="col-md-1 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label">Ativo</label></div></div>
            <div class="col-12">
                <?php foreach ($weekdayLabels as $value => $label): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>" <?= (int) $value <= 5 ? 'checked' : '' ?>><span class="form-check-label"><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="col-12"><button class="btn btn-primary">Criar alerta</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Alertas configurados</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Nome</th><th>Tipo</th><th>Destino</th><th>Hora</th><th>Dias</th><th>Estado</th><th>Editar</th></tr></thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                        <?php $mask = array_filter(explode(',', (string) $alert['weekdays_mask'])); ?>
                        <tr>
                            <td><?= h($alert['name']) ?></td>
                            <td><?= h($alertTypeOptions[$alert['alert_type']] ?? $alert['alert_type']) ?></td>
                            <td><?= h($alert['recipient_email']) ?></td>
                            <td><?= h((string) $alert['send_time']) ?></td>
                            <td><?= h(implode(', ', array_map(static fn($d) => $weekdayLabels[$d] ?? $d, $mask))) ?></td>
                            <td><?= (int) $alert['is_active'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                            <td>
                                <form method="post" class="row g-1">
                                    <input type="hidden" name="action" value="update_alert">
                                    <input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>">
                                    <div class="col-md-3"><input class="form-control form-control-sm" name="name" value="<?= h($alert['name']) ?>" required></div>
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="email" name="recipient_email" value="<?= h($alert['recipient_email']) ?>" required></div>
                                    <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="send_time" value="<?= h((string) $alert['send_time']) ?>" required></div>
                                    <div class="col-md-2"><select class="form-select form-select-sm" name="alert_type"><?php foreach ($alertTypeOptions as $k => $label): ?><option value="<?= h($k) ?>" <?= $alert['alert_type'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-1"><input type="hidden" name="weekdays[]" value="1"><input type="hidden" name="weekdays[]" value="2"><input type="hidden" name="weekdays[]" value="3"><input type="hidden" name="weekdays[]" value="4"><input type="hidden" name="weekdays[]" value="5"><input type="hidden" name="is_active" value="<?= (int) $alert['is_active'] ?>"></div>
                                    <div class="col-md-1 d-grid"><button class="btn btn-sm btn-outline-secondary">OK</button></div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
