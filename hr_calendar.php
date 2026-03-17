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

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_event') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $type = trim((string) ($_POST['event_type'] ?? 'Feriado'));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $color = trim((string) ($_POST['color'] ?? '#d63384'));

        if ($title === '' || $startDate === '' || $endDate === '' || $startDate > $endDate) {
            $flashError = 'Preencha título e período válido.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO hr_calendar_events(title, event_type, start_date, end_date, color, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $type, $startDate, $endDate, $color, $userId]);
            $flashSuccess = 'Evento adicionado ao calendário.';
        }
    }

    if ($action === 'import_csv') {
        $defaultType = trim((string) ($_POST['default_type'] ?? 'Feriado'));
        $count = 0;
        if (!isset($_FILES['calendar_csv']) || (int) ($_FILES['calendar_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'Selecione um ficheiro CSV para importar.';
        } else {
            $tmpName = (string) $_FILES['calendar_csv']['tmp_name'];
            $handle = fopen($tmpName, 'rb');
            if ($handle === false) {
                $flashError = 'Não foi possível ler o ficheiro CSV.';
            } else {
                while (($row = fgetcsv($handle, 0, ',')) !== false) {
                    if (!$row || count($row) < 3) {
                        continue;
                    }
                    $title = trim((string) ($row[0] ?? ''));
                    $type = trim((string) ($row[1] ?? $defaultType));
                    $startDate = trim((string) ($row[2] ?? ''));
                    $endDate = trim((string) ($row[3] ?? $startDate));
                    $color = trim((string) ($row[4] ?? '#d63384'));
                    if ($title === '' || $startDate === '' || $endDate === '' || $startDate > $endDate) {
                        continue;
                    }
                    $stmt = $pdo->prepare('INSERT INTO hr_calendar_events(title, event_type, start_date, end_date, color, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$title, $type !== '' ? $type : $defaultType, $startDate, $endDate, $color !== '' ? $color : '#d63384', $userId]);
                    $count++;
                }
                fclose($handle);
                $flashSuccess = $count > 0 ? "Importação concluída ({$count} registos)." : 'CSV processado sem linhas válidas.';
            }
        }
    }
}

$startOfMonth = sprintf('%04d-%02d-01', $year, $month);
$calendarStart = (new DateTimeImmutable($startOfMonth))->modify('monday this week');
$calendarEnd = (new DateTimeImmutable($startOfMonth))->modify('last day of this month')->modify('sunday this week');

$eventsStmt = $pdo->prepare('SELECT id, title, event_type, start_date, end_date, color FROM hr_calendar_events WHERE start_date <= ? AND end_date >= ? ORDER BY start_date ASC');
$eventsStmt->execute([$calendarEnd->format('Y-m-d'), $calendarStart->format('Y-m-d')]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$days = [];
$current = $calendarStart;
while ($current <= $calendarEnd) {
    $dayEvents = [];
    foreach ($events as $event) {
        if ((string) $event['start_date'] <= $current->format('Y-m-d') && (string) $event['end_date'] >= $current->format('Y-m-d')) {
            $dayEvents[] = $event;
        }
    }
    $days[] = ['date' => $current, 'events' => $dayEvents];
    $current = $current->modify('+1 day');
}

$monthNames = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$weekDays = ['seg.', 'ter.', 'qua.', 'qui.', 'sex.', 'sáb.', 'dom.'];

$pageTitle = 'Calendário RH';
require __DIR__ . '/partials/header.php';
?>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Calendário anual</h1>
        <p class="text-muted mb-0">Mapa consolidado de férias, feriados e pontes.</p>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="number" class="form-control" style="max-width:110px" name="year" min="2000" max="2100" value="<?= (int) $year ?>">
        <input type="hidden" name="month" value="<?= (int) $month ?>">
        <button class="btn btn-outline-secondary">Atualizar</button>
    </form>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <?php $prev = (new DateTimeImmutable($startOfMonth))->modify('-1 month'); $next = (new DateTimeImmutable($startOfMonth))->modify('+1 month'); ?>
                    <div class="btn-group">
                        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $prev->format('Y') ?>&month=<?= $prev->format('n') ?>">&larr;</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= date('Y') ?>&month=<?= date('n') ?>">Hoje</a>
                        <a class="btn btn-outline-secondary btn-sm" href="?year=<?= $next->format('Y') ?>&month=<?= $next->format('n') ?>">&rarr;</a>
                    </div>
                    <h2 class="h4 mb-0"><?= h($monthNames[$month] . ' de ' . $year) ?></h2>
                    <button class="btn btn-dark btn-sm" onclick="window.print()">Exportar PDF</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle small mb-0">
                        <thead><tr><?php foreach ($weekDays as $day): ?><th><?= h($day) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach (array_chunk($days, 7) as $week): ?>
                            <tr>
                                <?php foreach ($week as $day): ?>
                                    <?php $isCurrentMonth = (int) $day['date']->format('n') === $month; ?>
                                    <td class="p-2" style="height:98px;<?= $isCurrentMonth ? '' : 'background:#f8f9fa;color:#9aa0a6' ?>">
                                        <div class="fw-semibold"><?= $day['date']->format('j') ?></div>
                                        <?php foreach (array_slice($day['events'], 0, 2) as $event): ?>
                                            <div class="badge text-bg-light border mt-1 w-100 text-start" style="border-left:4px solid <?= h((string) ($event['color'] ?: '#6c757d')) ?> !important"><?= h((string) $event['title']) ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 d-grid gap-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Importar calendário</h2>
                <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                    <input type="hidden" name="action" value="import_csv">
                    <input class="form-control" type="file" name="calendar_csv" accept=".csv,text/csv" required>
                    <select class="form-select" name="default_type">
                        <option>Feriado</option><option>Ponte</option><option>Férias</option><option>Outros</option>
                    </select>
                    <small class="text-muted">CSV: título,tipo,data_inicio,data_fim,cor</small>
                    <button class="btn btn-outline-dark">Importar</button>
                </form>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Adicionar feriado / ponte</h2>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="add_event">
                    <input class="form-control" name="title" placeholder="Título" required>
                    <select class="form-select" name="event_type"><option>Férias</option><option>Feriado</option><option>Ponte</option><option>Outros</option></select>
                    <div class="row g-2"><div class="col"><input class="form-control" type="date" name="start_date" required></div><div class="col"><input class="form-control" type="date" name="end_date" required></div></div>
                    <input class="form-control form-control-color" type="color" name="color" value="#d63384">
                    <button class="btn btn-dark">Guardar</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
