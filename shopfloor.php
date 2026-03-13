<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$profile = (string) ($user['access_profile'] ?? 'Utilizador');
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;

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
            $flashSuccess = $entryType === 'entrada' ? 'Entrada registada com sucesso.' : 'Saída registada com sucesso.';
        }
    }

    if ($action === 'submit_absence') {
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));

        if ($startDate === '' || $endDate === '' || $reason === '') {
            $flashError = 'Preencha datas e motivo para comunicar ausência.';
        } elseif ($endDate < $startDate) {
            $flashError = 'A data final da ausência não pode ser anterior à inicial.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_requests(user_id, start_date, end_date, reason, details) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $startDate, $endDate, $reason, $details !== '' ? $details : null]);
            log_app_event($pdo, $userId, 'shopfloor.absence.create', 'Comunicação de ausência submetida.', ['start_date' => $startDate, 'end_date' => $endDate]);
            $flashSuccess = 'Comunicação de ausência submetida.';
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

    if ($action === 'publish_announcement' && ($isAdmin || $profile === 'RH')) {
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

$hourBankStmt = $pdo->prepare('SELECT balance_hours, notes, updated_at FROM shopfloor_hour_banks WHERE user_id = ? LIMIT 1');
$hourBankStmt->execute([$userId]);
$hourBank = $hourBankStmt->fetch(PDO::FETCH_ASSOC);
if (!$hourBank) {
    $pdo->prepare('INSERT INTO shopfloor_hour_banks(user_id, balance_hours, notes) VALUES (?, 0, NULL)')->execute([$userId]);
    $hourBank = ['balance_hours' => 0, 'notes' => null, 'updated_at' => date('Y-m-d H:i:s')];
}

$todayEntriesStmt = $pdo->prepare('SELECT entry_type, note, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = date("now", "localtime") ORDER BY occurred_at DESC');
$todayEntriesStmt->execute([$userId]);
$todayEntries = $todayEntriesStmt->fetchAll(PDO::FETCH_ASSOC);

$absenceRequestsStmt = $pdo->prepare('SELECT id, start_date, end_date, reason, status, created_at FROM shopfloor_absence_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
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

$pageTitle = 'Shopfloor';
require __DIR__ . '/partials/header.php';
?>
<a href="dashboard.php" class="btn btn-link px-0">&larr; Voltar à dashboard</a>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Shopfloor</h1>
    <span class="badge text-bg-dark">Perfil <?= h($profile) ?></span>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= h($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-danger"><?= h($flashError) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Banco de horas</div>
                <div class="display-6 mb-1"><?= number_format((float) ($hourBank['balance_hours'] ?? 0), 1, ',', '.') ?>h</div>
                <div class="small text-muted">Atualizado em <?= h((string) ($hourBank['updated_at'] ?? '-')) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Ponto (entrada / saída)</h2>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="clock_entry">
                        <input type="hidden" name="entry_type" value="entrada">
                        <button type="submit" class="btn btn-success">Marcar entrada</button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="clock_entry">
                        <input type="hidden" name="entry_type" value="saida">
                        <button type="submit" class="btn btn-outline-danger">Marcar saída</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Tipo</th><th>Hora</th><th>Nota</th></tr></thead>
                        <tbody>
                        <?php if ($todayEntries): ?>
                            <?php foreach ($todayEntries as $entry): ?>
                                <tr>
                                    <td><?= $entry['entry_type'] === 'entrada' ? 'Entrada' : 'Saída' ?></td>
                                    <td><?= h(date('H:i:s', strtotime((string) $entry['occurred_at']))) ?></td>
                                    <td><?= h((string) ($entry['note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-muted">Sem registos de ponto hoje.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Comunicar ausência</h2>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="submit_absence">
                    <input type="date" name="start_date" class="form-control" required>
                    <input type="date" name="end_date" class="form-control" required>
                    <input type="text" name="reason" class="form-control" placeholder="Motivo" required>
                    <textarea name="details" class="form-control" rows="2" placeholder="Detalhes"></textarea>
                    <button type="submit" class="btn btn-primary">Submeter ausência</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Submeter justificação</h2>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="submit_justification">
                    <select name="absence_request_id" class="form-select">
                        <option value="0">Sem ligação a ausência específica</option>
                        <?php foreach ($absenceRequests as $absence): ?>
                            <option value="<?= (int) $absence['id'] ?>">#<?= (int) $absence['id'] ?> · <?= h((string) $absence['start_date']) ?> a <?= h((string) $absence['end_date']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="event_date" class="form-control" required>
                    <textarea name="description" class="form-control" rows="3" placeholder="Descrição/justificação" required></textarea>
                    <button type="submit" class="btn btn-primary">Submeter justificação</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Pedido de férias</h2>
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="submit_vacation">
                    <input type="date" name="start_date" class="form-control" required>
                    <input type="date" name="end_date" class="form-control" required>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Notas"></textarea>
                    <button type="submit" class="btn btn-primary">Pedir férias</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Estado das comunicações de ausência</h2>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Período</th><th>Motivo</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php if ($absenceRequests): foreach ($absenceRequests as $absence): ?>
                            <tr>
                                <td><?= h((string) $absence['start_date']) ?> → <?= h((string) $absence['end_date']) ?></td>
                                <td><?= h((string) $absence['reason']) ?></td>
                                <td><span class="badge text-bg-secondary"><?= h((string) $absence['status']) ?></span></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-muted">Sem ausências comunicadas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Estado de pedidos de férias</h2>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Período</th><th>Dias</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php if ($vacationRequests): foreach ($vacationRequests as $vacation): ?>
                            <tr>
                                <td><?= h((string) $vacation['start_date']) ?> → <?= h((string) $vacation['end_date']) ?></td>
                                <td><?= h(number_format((float) ($vacation['total_days'] ?? 0), 1, ',', '.')) ?></td>
                                <td><span class="badge text-bg-secondary"><?= h((string) $vacation['status']) ?></span></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-muted">Sem pedidos de férias.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Histórico de justificações</h2>
                <ul class="list-group list-group-flush">
                    <?php if ($justifications): foreach ($justifications as $justification): ?>
                        <li class="list-group-item px-0">
                            <div class="fw-semibold"><?= h((string) $justification['event_date']) ?> <span class="badge text-bg-light"><?= h((string) $justification['status']) ?></span></div>
                            <div class="small text-muted"><?= h((string) $justification['description']) ?></div>
                        </li>
                    <?php endforeach; else: ?>
                        <li class="list-group-item px-0 text-muted">Sem justificações submetidas.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6">Comunicados da chefia / RH</h2>
                <ul class="list-group list-group-flush mb-3">
                    <?php if ($announcements): foreach ($announcements as $announcement): ?>
                        <li class="list-group-item px-0">
                            <div class="fw-semibold"><?= h((string) $announcement['title']) ?></div>
                            <div class="small text-muted mb-1">Por <?= h((string) $announcement['created_by_name']) ?> em <?= h((string) $announcement['created_at']) ?></div>
                            <div><?= nl2br(h((string) $announcement['body'])) ?></div>
                        </li>
                    <?php endforeach; else: ?>
                        <li class="list-group-item px-0 text-muted">Sem comunicados ativos.</li>
                    <?php endif; ?>
                </ul>

                <?php if ($isAdmin || $profile === 'RH'): ?>
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
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
