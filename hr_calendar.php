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


function output_calendar_csv_template(): void
{
    $rows = [
        ['título', 'tipo', 'data_inicio', 'data_fim', 'cor'],
        ['Ano Novo', 'Feriado', date('Y') . '-01-01', date('Y') . '-01-01', '#d63384'],
        ['Ponte da empresa', 'Ponte', date('Y') . '-12-24', date('Y') . '-12-24', '#fd7e14'],
        ['Férias coletivas', 'Férias', date('Y') . '-08-01', date('Y') . '-08-15', '#198754'],
    ];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_calendario_rh.csv"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }

    fwrite($output, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'csv-template') {
    output_calendar_csv_template();
}

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
$printWeekDays = ['S', 'T', 'Q', 'Q', 'S', 'S', 'D'];
$printLogo = app_setting($pdo, 'logo_report_dark');
$companyName = trim((string) app_setting($pdo, 'company_name', ''));
$companyAddress = trim((string) app_setting($pdo, 'company_address', ''));
$companyPhone = trim((string) app_setting($pdo, 'company_phone', ''));
$companyEmail = trim((string) app_setting($pdo, 'company_email', ''));

$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);
$yearEventsStmt = $pdo->prepare('SELECT title, event_type, start_date, end_date, color FROM hr_calendar_events WHERE start_date <= ? AND end_date >= ? ORDER BY start_date ASC');
$yearEventsStmt->execute([$yearEnd, $yearStart]);
$yearEvents = $yearEventsStmt->fetchAll(PDO::FETCH_ASSOC);

$yearEventMap = [];
$legendByType = [];
$priorityByType = ['Feriado' => 1, 'Ponte' => 2, 'Férias' => 3, 'Outros' => 4];
foreach ($yearEvents as $event) {
    $eventStart = new DateTimeImmutable((string) $event['start_date']);
    $eventEnd = new DateTimeImmutable((string) $event['end_date']);
    $cursor = $eventStart;
    while ($cursor <= $eventEnd) {
        if ((int) $cursor->format('Y') === $year) {
            $dateKey = $cursor->format('Y-m-d');
            if (!isset($yearEventMap[$dateKey])) {
                $yearEventMap[$dateKey] = [];
            }
            $yearEventMap[$dateKey][] = $event;
        }
        $cursor = $cursor->modify('+1 day');
    }
    $type = (string) ($event['event_type'] ?: 'Outros');
    if (!isset($legendByType[$type])) {
        $legendByType[$type] = (string) ($event['color'] ?: '#6c757d');
    }
}

if (!function_exists('calendar_day_background')) {
    function calendar_day_background(array $events, array $priorityByType): string
    {
        if (!$events) {
            return '';
        }
        usort($events, static function (array $a, array $b) use ($priorityByType): int {
            $typeA = (string) ($a['event_type'] ?? 'Outros');
            $typeB = (string) ($b['event_type'] ?? 'Outros');
            $priorityA = $priorityByType[$typeA] ?? 99;
            $priorityB = $priorityByType[$typeB] ?? 99;
            if ($priorityA === $priorityB) {
                return 0;
            }
            return $priorityA < $priorityB ? -1 : 1;
        });
        return (string) (($events[0]['color'] ?? '') ?: '#6c757d');
    }
}

if (!function_exists('calendar_safe_color')) {
    function calendar_safe_color(string $color, string $fallback = '#6c757d'): string
    {
        $normalized = trim($color);
        if ($normalized !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $normalized) === 1) {
            return $normalized;
        }
        return $fallback;
    }
}

$pageTitle = 'Calendário RH';
require __DIR__ . '/partials/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;700&display=swap" rel="stylesheet">
<style>
@media print {
    @page { size: A4 landscape; margin: 8mm; }
    nav.navbar,
    .btn-link,
    .d-flex.justify-content-between.align-items-center.mb-3.gap-2.flex-wrap,
    .alert,
    .calendar-screen { display: none !important; }
    main.container { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
    .calendar-screen { display: none !important; }
    .calendar-print-export { display: block !important; font-size: 11px; }
    .calendar-print-export, .calendar-print-export * { font-family: "Raleway", Arial, sans-serif !important; }
    .calendar-print-header { display: flex; justify-content: center; margin-bottom: 6px; }
    .calendar-print-logo { height: 32px; width: auto; object-fit: contain; }
    .calendar-print-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .calendar-print-month { border: 1px solid #d0d7de; border-radius: 6px; padding: 6px; break-inside: avoid; }
    .calendar-print-month h3 { font-size: 12px; margin: 0 0 4px; text-transform: capitalize; text-align: center; }
    .calendar-print-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .calendar-print-table th, .calendar-print-table td { border: 1px solid #dee2e6; text-align: left; padding: 1px 2px; height: 16px; font-size: 9px; }
    .calendar-print-table th { background: #f8f9fa !important; }
    .calendar-print-table td.out-month { background: #fafafa !important; color: #adb5bd; }
    .calendar-print-legend { margin-top: 8px; border-top: 1px solid #d0d7de; padding-top: 6px; display: flex; flex-wrap: wrap; gap: 10px; }
    .calendar-print-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 10px; }
    .calendar-print-chip span { width: 10px; height: 10px; border-radius: 2px; display: inline-block; border: 1px solid rgba(0,0,0,.15); print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .calendar-print-footer { margin-top: 6px; font-size: 9px; color: #4b5563; border-top: 1px solid #d0d7de; padding-top: 4px; text-align: center; }
}
</style>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<div class="calendar-print-export d-none">
    <div class="calendar-print-header">
        <?php if (!empty($printLogo)): ?>
            <img src="<?= h($printLogo) ?>" alt="Logo empresa" class="calendar-print-logo">
        <?php endif; ?>
    </div>
    <h1 class="h5 mb-1 text-center">Calendário anual <?= (int) $year ?></h1>
    <div class="calendar-print-grid">
        <?php for ($printMonth = 1; $printMonth <= 12; $printMonth++): ?>
            <?php
            $printStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $printMonth));
            $printCalendarStart = $printStart->modify('monday this week');
            $printCalendarEnd = $printStart->modify('last day of this month')->modify('sunday this week');
            $printDays = [];
            $cursor = $printCalendarStart;
            while ($cursor <= $printCalendarEnd) {
                $printDays[] = $cursor;
                $cursor = $cursor->modify('+1 day');
            }
            ?>
            <section class="calendar-print-month">
                <h3><?= h($monthNames[$printMonth] . ' ' . $year) ?></h3>
                <table class="calendar-print-table">
                    <thead>
                        <tr><?php foreach ($printWeekDays as $dayLabel): ?><th><?= h($dayLabel) ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_chunk($printDays, 7) as $printWeek): ?>
                            <tr>
                                <?php foreach ($printWeek as $printDay): ?>
                                    <?php
                                    $printDateKey = $printDay->format('Y-m-d');
                                    $printEvents = $yearEventMap[$printDateKey] ?? [];
                                    $bgColor = calendar_day_background($printEvents, $priorityByType);
                                    $isPrintCurrentMonth = (int) $printDay->format('n') === $printMonth;
                                    ?>
                                    <td class="<?= $isPrintCurrentMonth ? '' : 'out-month' ?>" style="<?= $bgColor !== '' ? 'background:' . h($bgColor) . ' !important;box-shadow: inset 0 0 0 999px ' . h($bgColor) . ';border-color:' . h($bgColor) . ';color:#111;' : '' ?>">
                                        <?= $printDay->format('j') ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endfor; ?>
    </div>
    <div class="calendar-print-legend">
        <?php foreach ($legendByType as $type => $legendColor): ?>
            <?php $legendColorSafe = calendar_safe_color((string) $legendColor); ?>
            <div class="calendar-print-chip"><span style="background-color:<?= h($legendColorSafe) ?> !important;box-shadow: inset 0 0 0 999px <?= h($legendColorSafe) ?>;"></span><?= h((string) $type) ?></div>
        <?php endforeach; ?>
    </div>
    <?php
    $companyParts = array_values(array_filter([$companyName, $companyAddress, $companyPhone, $companyEmail], static fn (string $value): bool => $value !== ''));
    ?>
    <?php if ($companyParts): ?>
        <div class="calendar-print-footer"><?= h(implode(' • ', $companyParts)) ?></div>
    <?php endif; ?>
</div>
<div class="calendar-screen">
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
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <small class="text-muted">CSV: título,tipo,data_inicio,data_fim,cor</small>
                        <a class="btn btn-sm btn-outline-secondary" href="hr_calendar.php?download=csv-template"><i class="bi bi-download"></i> Template CSV</a>
                    </div>
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
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
