<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$profile = (string) ($user['access_profile'] ?? 'Utilizador');
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
$isRh = $profile === 'RH';

if (!$isAdmin && $profile !== 'Utilizador') {
    http_response_code(403);
    exit('Acesso reservado ao perfil Utilizador.');
}

$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'clock_entry') {
        $entryType = trim((string) ($_POST['entry_type'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!in_array($entryType, ['entrada', 'saida'], true)) {
            $flashError = 'Tipo de registo de ponto inválido.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO shopfloor_time_entries(user_id, entry_type, note) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $entryType, $note !== '' ? $note : null]);
            log_app_event($pdo, $userId, 'shopfloor.clock.' . $entryType, 'Registo de ponto no Shopfloor.', ['entry_type' => $entryType]);
            $flashSuccess = $entryType === 'entrada' ? 'Ponto de entrada registado com sucesso.' : 'Ponto de saída registado com sucesso.';
        }
    }

    if ($action === 'submit_absence') {
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $details = trim((string) ($_POST['details'] ?? ''));

        $reasonStmt = $pdo->prepare('SELECT code, label FROM shopfloor_absence_reasons WHERE id = ? AND is_active = 1 LIMIT 1');
        $reasonStmt->execute([$reasonId]);
        $reasonData = $reasonStmt->fetch(PDO::FETCH_ASSOC);

        if ($startDate === '' || $endDate === '' || !$reasonData) {
            $flashError = 'Preencha datas e selecione um motivo para comunicar ausência.';
        } elseif ($endDate < $startDate) {
            $flashError = 'A data final da ausência não pode ser anterior à inicial.';
        } else {
            $reason = trim((string) ($reasonData['code'] ?? '')) . ' - ' . trim((string) ($reasonData['label'] ?? ''));
            $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_requests(user_id, start_date, end_date, reason, details) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $startDate, $endDate, $reason, $details !== '' ? $details : null]);
            log_app_event($pdo, $userId, 'shopfloor.absence.create', 'Comunicação de ausência submetida.', ['start_date' => $startDate, 'end_date' => $endDate, 'reason_id' => $reasonId]);
            $flashSuccess = 'Comunicação de ausência submetida com sucesso.';
        }
    }

    if ($action === 'submit_justification') {
        $absenceRequestId = (int) ($_POST['absence_request_id'] ?? 0);
        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($eventDate === '' || $description === '') {
            $flashError = 'Indique data e descrição da justificação.';
        } else {
            $targetAbsenceId = null;
            if ($absenceRequestId > 0) {
                $checkAbsenceStmt = $pdo->prepare('SELECT id FROM shopfloor_absence_requests WHERE id = ? AND user_id = ?');
                $checkAbsenceStmt->execute([$absenceRequestId, $userId]);
                $targetAbsenceId = (int) $checkAbsenceStmt->fetchColumn();
                if ($targetAbsenceId <= 0) {
                    $targetAbsenceId = null;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO shopfloor_justifications(user_id, absence_request_id, event_date, description) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $targetAbsenceId, $eventDate, $description]);
            log_app_event($pdo, $userId, 'shopfloor.justification.create', 'Justificação submetida no Shopfloor.', ['event_date' => $eventDate]);
            $flashSuccess = 'Justificação submetida com sucesso.';
        }
    }

    if ($action === 'submit_vacation') {
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($startDate === '' || $endDate === '') {
            $flashError = 'Indique o período de férias.';
        } elseif ($endDate < $startDate) {
            $flashError = 'A data final das férias não pode ser anterior à inicial.';
        } else {
            $start = new DateTimeImmutable($startDate);
            $end = new DateTimeImmutable($endDate);
            $totalDays = (float) $start->diff($end)->days + 1;

            $stmt = $pdo->prepare('INSERT INTO shopfloor_vacation_requests(user_id, start_date, end_date, total_days, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $startDate, $endDate, $totalDays, $notes !== '' ? $notes : null]);
            log_app_event($pdo, $userId, 'shopfloor.vacation.create', 'Pedido de férias submetido no Shopfloor.', ['start_date' => $startDate, 'end_date' => $endDate]);
            $flashSuccess = 'Pedido de férias submetido com sucesso.';
        }
    }

    if ($action === 'publish_announcement' && ($isAdmin || $isRh)) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            $flashError = 'Preencha título e conteúdo para publicar o comunicado.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO shopfloor_announcements(title, body, audience, created_by) VALUES (?, ?, "shopfloor", ?)');
            $stmt->execute([$title, $body, $userId]);
            log_app_event($pdo, $userId, 'shopfloor.announcement.create', 'Comunicado publicado no Shopfloor.', ['title' => $title]);
            $flashSuccess = 'Comunicado publicado com sucesso.';
        }
    }
}

$hourBankStmt = $pdo->prepare('SELECT balance_hours, updated_at FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
$hourBankStmt->execute([$userId]);
$hourBank = $hourBankStmt->fetch(PDO::FETCH_ASSOC);
if (!$hourBank) {
    $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes) VALUES (?, 0, NULL)')->execute([$userId]);
    $hourBank = ['balance_hours' => 0, 'updated_at' => date('Y-m-d H:i:s')];
}

$todayEntriesStmt = $pdo->prepare('SELECT entry_type, note, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = date("now", "localtime") ORDER BY occurred_at DESC');
$todayEntriesStmt->execute([$userId]);
$todayEntries = $todayEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

$latestTodayEntry = $todayEntries[0] ?? null;
$nextEntryType = $latestTodayEntry && (($latestTodayEntry['entry_type'] ?? '') === 'entrada') ? 'saida' : 'entrada';
$clockButtonLabel = $nextEntryType === 'entrada' ? 'Ponto de entrada' : 'Ponto de saída';
$clockButtonClass = $nextEntryType === 'entrada' ? 'btn-primary' : 'btn-outline-light';
$latestEntryTimeLabel = null;
if ($latestTodayEntry && !empty($latestTodayEntry['occurred_at'])) {
    $latestTimestamp = strtotime((string) $latestTodayEntry['occurred_at']);
    if ($latestTimestamp !== false) {
        $latestEntryTimeLabel = sprintf(
            '%s às %s',
            (($latestTodayEntry['entry_type'] ?? '') === 'entrada') ? 'Entrada' : 'Saída',
            date('H:i', $latestTimestamp)
        );
    }
}

$absenceReasons = $pdo->query('SELECT id, code, label, color FROM shopfloor_absence_reasons WHERE is_active = 1 ORDER BY label COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$absenceRequestsStmt = $pdo->prepare('SELECT id, start_date, end_date, reason, details, status, created_at FROM shopfloor_absence_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$absenceRequestsStmt->execute([$userId]);
$absenceRequests = $absenceRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

$justificationsStmt = $pdo->prepare('SELECT j.id, j.event_date, j.description, j.status, j.created_at, a.id AS absence_id FROM shopfloor_justifications j LEFT JOIN shopfloor_absence_requests a ON a.id = j.absence_request_id WHERE j.user_id = ? ORDER BY j.created_at DESC LIMIT 10');
$justificationsStmt->execute([$userId]);
$justifications = $justificationsStmt->fetchAll(PDO::FETCH_ASSOC);

$vacationRequestsStmt = $pdo->prepare('SELECT id, start_date, end_date, total_days, status, created_at FROM shopfloor_vacation_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$vacationRequestsStmt->execute([$userId]);
$vacationRequests = $vacationRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

$announcementsStmt = $pdo->query('SELECT a.title, a.body, a.created_at, COALESCE(u.name, "Sistema") AS created_by_name FROM shopfloor_announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.is_active = 1 AND a.audience IN ("all", "shopfloor") ORDER BY a.created_at DESC LIMIT 8');
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

$uploadsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM shopfloor_justifications WHERE user_id = ?');
$uploadsCountStmt->execute([$userId]);
$uploadsCount = (int) $uploadsCountStmt->fetchColumn();

$pendingVacationDaysStmt = $pdo->prepare('SELECT COALESCE(SUM(total_days), 0) FROM shopfloor_vacation_requests WHERE user_id = ? AND status IN ("Pendente", "Aprovado")');
$pendingVacationDaysStmt->execute([$userId]);
$pendingVacationDays = (float) $pendingVacationDaysStmt->fetchColumn();

$formattedHourBank = sprintf('%02dh%02dm', (int) floor((float) $hourBank['balance_hours']), (int) round((((float) $hourBank['balance_hours']) - floor((float) $hourBank['balance_hours'])) * 60));

$pageTitle = 'Shopfloor';
$bodyClass = 'bg-light';
$navbarClockControl = [
    'entry_type' => $nextEntryType,
    'button_label' => $clockButtonLabel,
    'button_class' => $clockButtonClass,
    'latest_time_label' => $latestEntryTimeLabel,
];
require __DIR__ . '/partials/header.php';
?>

<section class="shopfloor-shell">
    <div class="shopfloor-topbar">
        <div>
            <h1 class="h4 mb-1">Gestão pessoal</h1>
            <p class="text-secondary mb-0">Pedidos ligados ao módulo de RH e respetivas justificações.</p>
        </div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success mt-3 mb-3"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger mt-3 mb-3"><?= h($flashError) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <article class="shopfloor-kpi-card">
                <h2>Balanço de BH</h2>
                <strong><?= h($formattedHourBank) ?></strong>
            </article>
        </div>
        <div class="col-lg-4">
            <article class="shopfloor-kpi-card">
                <h2>Dias de férias a gozar</h2>
                <strong><?= h(number_format($pendingVacationDays, 1, ',', '.')) ?> dias</strong>
            </article>
        </div>
        <div class="col-lg-4">
            <article class="shopfloor-kpi-card">
                <h2>Uploads efetuados</h2>
                <strong><?= (int) $uploadsCount ?></strong>
            </article>
        </div>
    </div>

    <div class="shopfloor-panel mb-4">
        <div class="shopfloor-panel-header">
            <h2 class="h4 mb-0">Pedidos de ausência</h2>
            <button class="btn btn-primary btn-sm fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#absenceFormPanel" aria-expanded="false" aria-controls="absenceFormPanel">Novo pedido</button>
        </div>

        <div class="collapse mb-3" id="absenceFormPanel">
            <form method="post" class="shopfloor-form-grid">
                <input type="hidden" name="action" value="submit_absence">
                <div>
                    <label class="form-label d-flex justify-content-between align-items-center gap-2">
                        <span>Motivo (RH)</span>
                        <?php if ($isAdmin || $isRh): ?>
                            <a href="shopfloor_absence_reasons.php" class="small link-primary">Gerir motivos</a>
                        <?php endif; ?>
                    </label>
                    <select name="reason_id" class="form-select" required>
                        <option value="">Selecionar motivo</option>
                        <?php foreach ($absenceReasons as $reasonOption): ?>
                            <option value="<?= (int) $reasonOption['id'] ?>"><?= h((string) $reasonOption['code']) ?> — <?= h((string) $reasonOption['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Data início</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Data fim</label>
                    <input type="date" name="end_date" class="form-control" required>
                </div>
                <div class="full">
                    <label class="form-label">Justificação</label>
                    <textarea name="details" class="form-control" rows="3" placeholder="Opcional"></textarea>
                </div>
                <div class="full">
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Submeter pedido</button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-sm shopfloor-table mb-0">
                <thead><tr><th>Motivo</th><th>Data</th><th>Estado</th><th class="text-end">Justificação</th></tr></thead>
                <tbody>
                <?php if ($absenceRequests): foreach ($absenceRequests as $absence): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2"><span class="shopfloor-dot"></span><span><?= h((string) $absence['reason']) ?></span></div>
                            <div class="small text-secondary">Código: <?= (int) $absence['id'] ?></div>
                        </td>
                        <td><?= h((string) $absence['start_date']) ?><?= $absence['end_date'] !== $absence['start_date'] ? ' → ' . h((string) $absence['end_date']) : '' ?></td>
                        <td><span class="badge shopfloor-status-pill"><?= h((string) $absence['status']) ?></span></td>
                        <td class="text-end">
                            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#justification-form-<?= (int) $absence['id'] ?>">Anexar</button>
                        </td>
                    </tr>
                    <tr class="collapse" id="justification-form-<?= (int) $absence['id'] ?>">
                        <td colspan="4" class="bg-transparent">
                            <form method="post" class="row g-2 align-items-end">
                                <input type="hidden" name="action" value="submit_justification">
                                <input type="hidden" name="absence_request_id" value="<?= (int) $absence['id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label mb-1">Data</label>
                                    <input type="date" name="event_date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label mb-1">Descrição</label>
                                    <input type="text" name="description" class="form-control form-control-sm" placeholder="Justificação" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Submeter</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-secondary">Sem comunicações de ausência.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="shopfloor-panel mb-4">
        <div class="shopfloor-panel-header">
            <h2 class="h4 mb-0">Pedidos de férias</h2>
            <button class="btn btn-primary btn-sm fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#vacationFormPanel" aria-expanded="false" aria-controls="vacationFormPanel">Novo pedido</button>
        </div>

        <div class="collapse mb-3" id="vacationFormPanel">
            <form method="post" class="shopfloor-form-grid">
                <input type="hidden" name="action" value="submit_vacation">
                <div>
                    <label class="form-label">Início</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Fim</label>
                    <input type="date" name="end_date" class="form-control" required>
                </div>
                <div class="full">
                    <label class="form-label">Notas</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Observações opcionais"></textarea>
                </div>
                <div class="full">
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">Submeter pedido</button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-sm shopfloor-table mb-0">
                <thead><tr><th>Início</th><th>Fim</th><th>Dias</th><th>Estado</th></tr></thead>
                <tbody>
                <?php if ($vacationRequests): foreach ($vacationRequests as $vacation): ?>
                    <tr>
                        <td><?= h((string) $vacation['start_date']) ?></td>
                        <td><?= h((string) $vacation['end_date']) ?></td>
                        <td><?= h(number_format((float) ($vacation['total_days'] ?? 0), 1, ',', '.')) ?></td>
                        <td><span class="badge shopfloor-status-pill"><?= h((string) $vacation['status']) ?></span></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-secondary">Sem pedidos de férias.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="shopfloor-panel h-100">
                <h2 class="h5 mb-3">Histórico do dia (ponto)</h2>
                <ul class="list-group list-group-flush">
                    <?php if ($todayEntries): foreach ($todayEntries as $entry): ?>
                        <li class="list-group-item shopfloor-list-item">
                            <span class="fw-semibold"><?= $entry['entry_type'] === 'entrada' ? 'Entrada' : 'Saída' ?></span>
                            <span class="text-secondary small ms-2"><?= h(date('H:i:s', strtotime((string) $entry['occurred_at']))) ?></span>
                            <?php if (!empty($entry['note'])): ?>
                                <div class="small text-secondary mt-1"><?= h((string) $entry['note']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; else: ?>
                        <li class="list-group-item shopfloor-list-item text-secondary">Sem registos de ponto hoje.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="shopfloor-panel h-100">
                <h2 class="h5 mb-3">Comunicados da chefia / RH</h2>
                <ul class="list-group list-group-flush mb-3">
                    <?php if ($announcements): foreach ($announcements as $announcement): ?>
                        <li class="list-group-item shopfloor-list-item">
                            <div class="fw-semibold"><?= h((string) $announcement['title']) ?></div>
                            <div class="small text-secondary mb-1">Por <?= h((string) $announcement['created_by_name']) ?> em <?= h((string) $announcement['created_at']) ?></div>
                            <div><?= nl2br(h((string) $announcement['body'])) ?></div>
                        </li>
                    <?php endforeach; else: ?>
                        <li class="list-group-item shopfloor-list-item text-secondary">Sem comunicados ativos.</li>
                    <?php endif; ?>
                </ul>

                <?php if ($isAdmin || $isRh): ?>
                    <h3 class="h6">Publicar comunicado</h3>
                    <form method="post" class="vstack gap-2">
                        <input type="hidden" name="action" value="publish_announcement">
                        <input type="text" name="title" class="form-control" placeholder="Título" required>
                        <textarea name="body" class="form-control" rows="3" placeholder="Mensagem" required></textarea>
                        <button type="submit" class="btn btn-outline-primary">Publicar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($justifications): ?>
        <div class="shopfloor-panel mt-4">
            <h2 class="h5 mb-3">Últimas justificações submetidas</h2>
            <div class="table-responsive">
                <table class="table table-sm shopfloor-table mb-0">
                    <thead><tr><th>Data</th><th>Descrição</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($justifications as $justification): ?>
                        <tr>
                            <td><?= h((string) $justification['event_date']) ?></td>
                            <td><?= h((string) $justification['description']) ?></td>
                            <td><span class="badge shopfloor-status-pill"><?= h((string) $justification['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
