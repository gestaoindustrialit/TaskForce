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
$statusOptions = ['Planeado', 'Aprovado', 'Pendente'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_vacation') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'Aprovado'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($targetUserId <= 0 || $startDate === '' || $endDate === '' || $startDate > $endDate) {
            $flashError = 'Dados inválidos para registar férias.';
        } else {
            if (!in_array($status, $statusOptions, true)) {
                $status = 'Aprovado';
            }
            $stmt = $pdo->prepare('INSERT INTO hr_vacation_events(user_id, start_date, end_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$targetUserId, $startDate, $endDate, $status, $notes, $userId]);
            $flashSuccess = 'Férias registadas com sucesso.';
        }
    }
}

$users = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$events = $pdo->query('SELECT v.id, v.user_id, v.start_date, v.end_date, v.status, v.notes, u.name AS user_name FROM hr_vacation_events v INNER JOIN users u ON u.id = v.user_id ORDER BY v.start_date ASC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Calendário de Férias';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Calendário de férias</h1>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Registar férias/ausência</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="create_vacation">
            <div class="col-md-4">
                <label class="form-label">Utilizador</label>
                <select class="form-select" name="user_id" required>
                    <option value="">Selecionar</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= h($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Início</label><input class="form-control" type="date" name="start_date" required></div>
            <div class="col-md-2"><label class="form-label">Fim</label><input class="form-control" type="date" name="end_date" required></div>
            <div class="col-md-2">
                <label class="form-label">Estado</label>
                <select class="form-select" name="status">
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= h($status) ?>" <?= $status === 'Aprovado' ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid align-self-end"><button class="btn btn-primary">Guardar</button></div>
            <div class="col-12"><textarea class="form-control" name="notes" rows="2" placeholder="Notas"></textarea></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Mapa de ausências</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Colaborador</th><th>Início</th><th>Fim</th><th>Estado</th><th>Notas</th></tr></thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= h($event['user_name']) ?></td>
                            <td><?= h(date('d/m/Y', strtotime((string) $event['start_date']))) ?></td>
                            <td><?= h(date('d/m/Y', strtotime((string) $event['end_date']))) ?></td>
                            <td><span class="badge text-bg-secondary"><?= h($event['status']) ?></span></td>
                            <td><?= h((string) $event['notes']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
