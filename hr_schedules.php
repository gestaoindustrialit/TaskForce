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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_schedule' || $action === 'update_schedule') {
        $id = (int) ($_POST['schedule_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $breakMinutes = max(0, (int) ($_POST['break_minutes'] ?? 0));
        $weekdays = $_POST['weekdays'] ?? [];
        $weekdays = is_array($weekdays) ? array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', $weekdays))) : [];
        $weekdaysMask = count($weekdays) > 0 ? implode(',', $weekdays) : '1,2,3,4,5';

        if ($name === '' || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $flashError = 'Preencha nome e horas válidas (HH:MM).';
        } else {
            try {
                if ($action === 'create_schedule') {
                    $stmt = $pdo->prepare('INSERT INTO hr_schedules(name, start_time, end_time, break_minutes, weekdays_mask) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $startTime, $endTime, $breakMinutes, $weekdaysMask]);
                    $flashSuccess = 'Horário criado com sucesso.';
                } else {
                    $stmt = $pdo->prepare('UPDATE hr_schedules SET name = ?, start_time = ?, end_time = ?, break_minutes = ?, weekdays_mask = ? WHERE id = ?');
                    $stmt->execute([$name, $startTime, $endTime, $breakMinutes, $weekdaysMask, $id]);
                    $flashSuccess = 'Horário atualizado com sucesso.';
                }
            } catch (PDOException $e) {
                $flashError = 'Não foi possível guardar horário (nome duplicado).';
            }
        }
    }
}

$schedules = $pdo->query('SELECT id, name, start_time, end_time, break_minutes, weekdays_mask, created_at FROM hr_schedules ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$weekdayLabels = ['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'];

$pageTitle = 'Horários';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Gestão de horários</h1>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Novo horário</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="create_schedule">
            <div class="col-md-3"><label class="form-label">Nome</label><input class="form-control" name="name" placeholder="Ex.: Turno Manhã" required></div>
            <div class="col-md-2"><label class="form-label">Entrada</label><input class="form-control" type="time" name="start_time" required></div>
            <div class="col-md-2"><label class="form-label">Saída</label><input class="form-control" type="time" name="end_time" required></div>
            <div class="col-md-2"><label class="form-label">Pausa (min)</label><input class="form-control" type="number" min="0" name="break_minutes" value="60"></div>
            <div class="col-md-3">
                <label class="form-label d-block">Dias</label>
                <?php foreach ($weekdayLabels as $value => $label): ?>
                    <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>" <?= (int) $value <= 5 ? 'checked' : '' ?>><span class="form-check-label"><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="col-12"><button class="btn btn-primary">Criar horário</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Horários existentes</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Nome</th><th>Jornada</th><th>Pausa</th><th>Dias</th><th>Editar</th></tr></thead>
                <tbody>
                <?php foreach ($schedules as $schedule): ?>
                    <?php $mask = array_filter(explode(',', (string) $schedule['weekdays_mask'])); ?>
                    <tr>
                        <td><?= h($schedule['name']) ?></td>
                        <td><?= h((string) $schedule['start_time']) ?> - <?= h((string) $schedule['end_time']) ?></td>
                        <td><?= (int) $schedule['break_minutes'] ?> min</td>
                        <td><?= h(implode(', ', array_map(static fn($d) => $weekdayLabels[$d] ?? $d, $mask))) ?></td>
                        <td>
                            <form method="post" class="row g-1">
                                <input type="hidden" name="action" value="update_schedule">
                                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                                <div class="col-md-3"><input class="form-control form-control-sm" name="name" value="<?= h($schedule['name']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="start_time" value="<?= h((string) $schedule['start_time']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="end_time" value="<?= h((string) $schedule['end_time']) ?>" required></div>
                                <div class="col-md-2"><input class="form-control form-control-sm" type="number" min="0" name="break_minutes" value="<?= (int) $schedule['break_minutes'] ?>"></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Guardar</button></div>
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
