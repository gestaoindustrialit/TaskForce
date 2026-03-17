<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$profile = (string) ($user['access_profile'] ?? 'Utilizador');
$isAdmin = (int) ($user['is_admin'] ?? 0) === 1;
$isRh = $profile === 'RH';
$isChief = $profile === 'Chefias';

if (!$isAdmin && !in_array($profile, ['Utilizador', 'Chefias', 'RH'], true)) {
    http_response_code(403);
    exit('Acesso reservado aos perfis Utilizador, Chefias e RH.');
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
        $requestType = trim((string) ($_POST['request_type'] ?? 'Dias inteiros'));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $singleDate = trim((string) ($_POST['single_date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $endTime = trim((string) ($_POST['end_time'] ?? ''));
        $reasonId = (int) ($_POST['reason_id'] ?? 0);
        $details = trim((string) ($_POST['details'] ?? ''));

        $reasonStmt = $pdo->prepare('SELECT code, label FROM shopfloor_absence_reasons WHERE id = ? AND is_active = 1 LIMIT 1');
        $reasonStmt->execute([$reasonId]);
        $reasonData = $reasonStmt->fetch(PDO::FETCH_ASSOC);

        if (!in_array($requestType, ['Dias inteiros', 'Intervalo de tempo'], true)) {
            $flashError = 'Tipo de ausência inválido.';
        } elseif (!$reasonData) {
            $flashError = 'Selecione um motivo para comunicar ausência.';
        } elseif ($requestType === 'Dias inteiros' && ($startDate === '' || $endDate === '')) {
            $flashError = 'Preencha datas e selecione um motivo para comunicar ausência.';
        } elseif ($requestType === 'Dias inteiros' && $endDate < $startDate) {
            $flashError = 'A data final da ausência não pode ser anterior à inicial.';
        } elseif ($requestType === 'Intervalo de tempo' && ($singleDate === '' || $startTime === '' || $endTime === '')) {
            $flashError = 'Preencha data e horas para o intervalo de tempo.';
        } elseif ($requestType === 'Intervalo de tempo' && $endTime <= $startTime) {
            $flashError = 'A hora final deve ser posterior à hora inicial.';
        } else {
            if ($requestType === 'Intervalo de tempo') {
                $startDate = $singleDate;
                $endDate = $singleDate;
            }
            $reason = trim((string) ($reasonData['code'] ?? '')) . ' - ' . trim((string) ($reasonData['label'] ?? ''));
            $stmt = $pdo->prepare('INSERT INTO shopfloor_absence_requests(user_id, request_type, start_date, end_date, start_time, end_time, reason, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $userId,
                $requestType,
                $startDate,
                $endDate,
                $requestType === 'Intervalo de tempo' ? $startTime : null,
                $requestType === 'Intervalo de tempo' ? $endTime : null,
                $reason,
                $details !== '' ? $details : null,
            ]);
            $absenceId = (int) $pdo->lastInsertId();
            if ($absenceId > 0) {
                $pdo->prepare('UPDATE shopfloor_absence_requests SET status = ? WHERE id = ?')->execute(['Pendente Nível 1', $absenceId]);
            }
            log_app_event($pdo, $userId, 'shopfloor.absence.create', 'Comunicação de ausência submetida.', ['request_type' => $requestType, 'start_date' => $startDate, 'end_date' => $endDate, 'reason_id' => $reasonId]);
            $flashSuccess = 'Comunicação de ausência submetida com sucesso.';
        }
    }

    if ($action === 'review_absence' && ($isAdmin || $isChief || $isRh)) {
        $absenceId = (int) ($_POST['absence_id'] ?? 0);
        $decision = trim((string) ($_POST['decision'] ?? ''));

        $absenceStmt = $pdo->prepare('SELECT id, status FROM shopfloor_absence_requests WHERE id = ? LIMIT 1');
        $absenceStmt->execute([$absenceId]);
        $absence = $absenceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$absence || $absenceId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            $flashError = 'Pedido inválido para validação.';
        } else {
            $currentStatus = (string) ($absence['status'] ?? '');
            $newStatus = null;

            if ($isAdmin && $decision === 'approve') {
                $newStatus = 'Aprovado';
            } elseif ($isAdmin && $decision === 'reject') {
                $newStatus = 'Rejeitado';
            } elseif ($isChief) {
                if ($currentStatus !== 'Pendente Nível 1') {
                    $flashError = 'Este pedido já não está pendente do Nível 1.';
                } elseif ($decision === 'approve') {
                    $newStatus = 'Pendente Nível 2';
                } else {
                    $newStatus = 'Rejeitado';
                }
            } elseif ($isRh) {
                if (!in_array($currentStatus, ['Pendente Nível 2', 'Pendente'], true)) {
                    $flashError = 'Este pedido já não está pendente do Nível 2.';
                } elseif ($decision === 'approve') {
                    $newStatus = 'Aprovado';
                } else {
                    $newStatus = 'Rejeitado';
                }
            }

            if ($newStatus !== null) {
                $updateStmt = $pdo->prepare('UPDATE shopfloor_absence_requests SET status = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?');
                $updateStmt->execute([$newStatus, $userId, $absenceId]);
                log_app_event($pdo, $userId, 'shopfloor.absence.review', 'Validação de ausência efetuada.', ['absence_id' => $absenceId, 'status' => $newStatus]);
                $flashSuccess = 'Estado do pedido atualizado para ' . $newStatus . '.';
            }
        }
    }

    if ($action === 'submit_justification') {
        $absenceRequestId = (int) ($_POST['absence_request_id'] ?? 0);
        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $photoFile = $_FILES['photo'] ?? null;
        $attachmentPath = null;
        $hasPhotoUpload = is_array($photoFile) && (($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

        if ($hasPhotoUpload) {
            $allowedImageMimeTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            $uploadError = (int) ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                $flashError = 'Não foi possível carregar a fotografia da justificação.';
            } elseif (!isset($photoFile['tmp_name']) || !is_string($photoFile['tmp_name']) || !is_file($photoFile['tmp_name'])) {
                $flashError = 'O ficheiro da fotografia submetida é inválido.';
            } else {
                $detectedMimeType = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo !== false) {
                        $finfoMimeType = finfo_file($finfo, (string) $photoFile['tmp_name']);
                        if (is_string($finfoMimeType)) {
                            $detectedMimeType = $finfoMimeType;
                        }
                        finfo_close($finfo);
                    }
                }

                if ($detectedMimeType === '' && function_exists('mime_content_type')) {
                    $mimeType = mime_content_type((string) $photoFile['tmp_name']);
                    if (is_string($mimeType)) {
                        $detectedMimeType = $mimeType;
                    }
                }

                if (!isset($allowedImageMimeTypes[$detectedMimeType])) {
                    $flashError = 'Formato de imagem inválido. Use JPG, PNG ou WEBP.';
                } else {
                    $uploadDir = __DIR__ . '/assets/uploads/justifications';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $filename = sprintf(
                        'justification_%d_%s.%s',
                        $userId,
                        bin2hex(random_bytes(6)),
                        $allowedImageMimeTypes[$detectedMimeType]
                    );
                    $targetPath = $uploadDir . '/' . $filename;

                    if (!move_uploaded_file((string) $photoFile['tmp_name'], $targetPath)) {
                        $flashError = 'Falha ao guardar a fotografia da justificação.';
                    } else {
                        $attachmentPath = 'assets/uploads/justifications/' . $filename;
                    }
                }
            }
        }

        if ($flashError === null && ($eventDate === '' || !$hasPhotoUpload)) {
            $flashError = 'Indique a data e anexe uma fotografia para a justificação.';
        }

        if ($flashError === null) {
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
            $justificationDescription = $description !== '' ? $description : 'Fotografia anexada';
            $stmt->execute([$userId, $targetAbsenceId, $eventDate, $justificationDescription]);
            if ($attachmentPath !== null) {
                $justificationId = (int) $pdo->lastInsertId();
                if ($justificationId > 0) {
                    $attachmentStmt = $pdo->prepare('UPDATE shopfloor_justifications SET attachment_path = ? WHERE id = ? AND user_id = ?');
                    $attachmentStmt->execute([$attachmentPath, $justificationId, $userId]);
                }
            }
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
        $targetUserIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['target_user_ids'] ?? [])), static fn (int $id): bool => $id > 0)));

        if ($title === '' || $body === '') {
            $flashError = 'Preencha título e conteúdo para publicar o comunicado.';
        } else {
            $validTargetUserIds = [];
            if ($targetUserIds !== []) {
                $targetPlaceholders = implode(',', array_fill(0, count($targetUserIds), '?'));
                $targetUsersStmt = $pdo->prepare('SELECT id FROM users WHERE id IN (' . $targetPlaceholders . ')');
                $targetUsersStmt->execute($targetUserIds);
                $validTargetUserIds = array_map('intval', $targetUsersStmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $audience = $validTargetUserIds === [] ? 'shopfloor' : 'targeted';
            $stmt = $pdo->prepare('INSERT INTO shopfloor_announcements(title, body, audience, created_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$title, $body, $audience, $userId]);
            $announcementId = (int) $pdo->lastInsertId();

            if ($announcementId > 0 && $validTargetUserIds !== []) {
                $targetInsertStmt = $pdo->prepare('INSERT OR IGNORE INTO shopfloor_announcement_targets(announcement_id, user_id) VALUES (?, ?)');
                foreach ($validTargetUserIds as $targetUserId) {
                    $targetInsertStmt->execute([$announcementId, $targetUserId]);
                }
            }

            log_app_event($pdo, $userId, 'shopfloor.announcement.create', 'Comunicado publicado no Shopfloor.', ['title' => $title, 'targets' => $validTargetUserIds]);
            $flashSuccess = 'Comunicado publicado com sucesso.';
        }
    }

    if ($action === 'toggle_announcement' && ($isAdmin || $isRh)) {
        $announcementId = (int) ($_POST['announcement_id'] ?? 0);
        $toggleStmt = $pdo->prepare('UPDATE shopfloor_announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $toggleStmt->execute([$announcementId]);

        if ($toggleStmt->rowCount() > 0) {
            log_app_event($pdo, $userId, 'shopfloor.announcement.toggle', 'Comunicado ativado/desativado no Shopfloor.', ['announcement_id' => $announcementId]);
            $flashSuccess = 'Estado do comunicado atualizado com sucesso.';
        } else {
            $flashError = 'Comunicado inválido para alteração de estado.';
        }
    }

    if ($action === 'delete_announcement' && ($isAdmin || $isRh)) {
        $announcementId = (int) ($_POST['announcement_id'] ?? 0);
        $deleteStmt = $pdo->prepare('DELETE FROM shopfloor_announcements WHERE id = ?');
        $deleteStmt->execute([$announcementId]);

        if ($deleteStmt->rowCount() > 0) {
            log_app_event($pdo, $userId, 'shopfloor.announcement.delete', 'Comunicado eliminado no Shopfloor.', ['announcement_id' => $announcementId]);
            $flashSuccess = 'Comunicado eliminado com sucesso.';
        } else {
            $flashError = 'Comunicado inválido para eliminação.';
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

$absenceRequestsStmt = $pdo->prepare('SELECT a.id, a.request_type, a.start_date, a.end_date, a.start_time, a.end_time, a.reason, a.details, a.status, a.created_at, r.color AS reason_color, j.attachment_path AS latest_attachment_path FROM shopfloor_absence_requests a LEFT JOIN shopfloor_absence_reasons r ON a.reason LIKE (r.code || " - %") LEFT JOIN shopfloor_justifications j ON j.id = (SELECT j2.id FROM shopfloor_justifications j2 WHERE j2.absence_request_id = a.id AND j2.attachment_path IS NOT NULL AND TRIM(j2.attachment_path) <> "" ORDER BY j2.created_at DESC LIMIT 1) WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 10');
$absenceRequestsStmt->execute([$userId]);
$absenceRequests = $absenceRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingChiefValidations = [];
if ($isAdmin || $isChief) {
    $pendingChiefStmt = $pdo->query('SELECT a.id, a.start_date, a.end_date, a.request_type, a.start_time, a.end_time, a.reason, a.status, u.name AS user_name FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id WHERE a.status = "Pendente Nível 1" ORDER BY a.created_at ASC LIMIT 20');
    $pendingChiefValidations = $pendingChiefStmt->fetchAll(PDO::FETCH_ASSOC);
}

$rhFilter = trim((string) ($_GET['rh_filter'] ?? 'todos'));
if (!in_array($rhFilter, ['todos', 'pendentes', 'aprovados'], true)) {
    $rhFilter = 'todos';
}
$rhAbsenceRows = [];
if ($isAdmin || $isRh) {
    $rhWhere = 'WHERE a.status LIKE "Pendente%" OR a.status = "Aprovado"';
    if ($rhFilter === 'pendentes') {
        $rhWhere = 'WHERE a.status LIKE "Pendente%"';
    } elseif ($rhFilter === 'aprovados') {
        $rhWhere = 'WHERE a.status = "Aprovado"';
    }
    $rhAbsenceStmt = $pdo->query('SELECT a.id, a.request_type, a.start_date, a.end_date, a.start_time, a.end_time, a.reason, a.status, a.created_at, u.name AS user_name FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id ' . $rhWhere . ' ORDER BY a.created_at DESC LIMIT 100');
    $rhAbsenceRows = $rhAbsenceStmt->fetchAll(PDO::FETCH_ASSOC);
}

$absenceJustificationsStmt = $pdo->prepare('SELECT id, absence_request_id, event_date, description, attachment_path, status, created_at FROM shopfloor_justifications WHERE user_id = ? AND absence_request_id IS NOT NULL ORDER BY created_at DESC LIMIT 100');
$absenceJustificationsStmt->execute([$userId]);
$absenceJustificationsRows = $absenceJustificationsStmt->fetchAll(PDO::FETCH_ASSOC);
$absenceJustificationsByRequestId = [];
foreach ($absenceJustificationsRows as $absenceJustificationRow) {
    $requestId = (int) ($absenceJustificationRow['absence_request_id'] ?? 0);
    if ($requestId <= 0) {
        continue;
    }
    if (!array_key_exists($requestId, $absenceJustificationsByRequestId)) {
        $absenceJustificationsByRequestId[$requestId] = [];
    }
    $absenceJustificationsByRequestId[$requestId][] = $absenceJustificationRow;
}

$vacationRequestsStmt = $pdo->prepare('SELECT id, start_date, end_date, total_days, status, created_at FROM shopfloor_vacation_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$vacationRequestsStmt->execute([$userId]);
$vacationRequests = $vacationRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

$announcementTargetUsers = [];
if ($isAdmin || $isRh) {
    $announcementTargetUsersStmt = $pdo->query('SELECT id, name, username FROM users ORDER BY name COLLATE NOCASE ASC');
    $announcementTargetUsers = $announcementTargetUsersStmt->fetchAll(PDO::FETCH_ASSOC);
}

$announcementsStmt = $pdo->prepare('SELECT a.id, a.title, a.body, a.created_at, a.is_active, a.audience, COALESCE(u.name, "Sistema") AS created_by_name FROM shopfloor_announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.is_active = 1 AND (a.audience IN ("all", "shopfloor") OR EXISTS (SELECT 1 FROM shopfloor_announcement_targets t WHERE t.announcement_id = a.id AND t.user_id = ?)) ORDER BY a.created_at DESC LIMIT 8');
$announcementsStmt->execute([$userId]);
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

$managedAnnouncements = [];
if ($isAdmin || $isRh) {
    $managedAnnouncementsStmt = $pdo->query('SELECT a.id, a.title, a.body, a.created_at, a.is_active, a.audience, COALESCE(u.name, "Sistema") AS created_by_name, (SELECT COUNT(*) FROM shopfloor_announcement_targets t WHERE t.announcement_id = a.id) AS target_count FROM shopfloor_announcements a LEFT JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC LIMIT 25');
    $managedAnnouncements = $managedAnnouncementsStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            <form method="post" class="shopfloor-form-grid shopfloor-form-grid-request" id="absenceRequestForm">
                <input type="hidden" name="action" value="submit_absence">
                <div>
                    <label class="form-label">Tipo</label>
                    <select name="request_type" class="form-select" id="absenceRequestType" required>
                        <option value="Dias inteiros">Dia(s) inteiro(s)</option>
                        <option value="Intervalo de tempo">Intervalo de tempo</option>
                    </select>
                </div>
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
                <div class="absence-full-days-field request-second-row">
                    <label class="form-label">Data início</label>
                    <input type="date" name="start_date" class="form-control" required>
                </div>
                <div class="absence-full-days-field request-second-row">
                    <label class="form-label">Data fim</label>
                    <input type="date" name="end_date" class="form-control" required>
                </div>
                <div class="absence-time-range-field d-none request-second-row">
                    <label class="form-label">Data</label>
                    <input type="date" name="single_date" class="form-control">
                </div>
                <div class="absence-time-range-field d-none request-second-row">
                    <label class="form-label">Hora início</label>
                    <input type="time" name="start_time" class="form-control">
                </div>
                <div class="absence-time-range-field d-none request-second-row">
                    <label class="form-label">Hora fim</label>
                    <input type="time" name="end_time" class="form-control">
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
                            <div class="d-flex align-items-center gap-2">
                                <span class="shopfloor-dot" style="background: <?= h((string) ($absence['reason_color'] ?? '#2563eb')) ?>"></span>
                                <span><?= h((string) $absence['reason']) ?></span>
                            </div>
                            <div class="small text-secondary">Código: <?= (int) $absence['id'] ?></div>
                        </td>
                        <td>
                            <?php if (($absence['request_type'] ?? 'Dias inteiros') === 'Intervalo de tempo'): ?>
                                <?= h((string) $absence['start_date']) ?> · <?= h((string) ($absence['start_time'] ?? '')) ?> → <?= h((string) ($absence['end_time'] ?? '')) ?>
                            <?php else: ?>
                                <?= h((string) $absence['start_date']) ?><?= $absence['end_date'] !== $absence['start_date'] ? ' → ' . h((string) $absence['end_date']) : '' ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge shopfloor-status-pill"><?= h((string) $absence['status']) ?></span></td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-2">
                                <?php if (!empty($absence['latest_attachment_path'])): ?>
                                    <a
                                        href="<?= h((string) $absence['latest_attachment_path']) ?>"
                                        class="btn btn-outline-secondary btn-sm"
                                        data-lightbox-image="<?= h((string) $absence['latest_attachment_path']) ?>"
                                    >Ver ficheiro</a>
                                <?php endif; ?>
                                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#justification-form-<?= (int) $absence['id'] ?>">Anexar</button>
                            </div>
                        </td>
                    </tr>
                    <tr class="collapse" id="justification-form-<?= (int) $absence['id'] ?>">
                        <td colspan="4" class="bg-transparent">
                            <form method="post" class="row g-2 align-items-end" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="submit_justification">
                                <input type="hidden" name="absence_request_id" value="<?= (int) $absence['id'] ?>">
                                <div class="col-md-3">
                                    <label class="form-label mb-1">Data</label>
                                    <input type="date" name="event_date" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label mb-1">Fotografia</label>
                                    <input type="file" name="photo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Submeter</button>
                                </div>
                            </form>

                            <?php $absenceJustifications = $absenceJustificationsByRequestId[(int) $absence['id']] ?? []; ?>
                            <?php if ($absenceJustifications): ?>
                                <div class="mt-3 border-top pt-2">
                                    <div class="small text-secondary mb-2">Justificações ligadas a esta ausência</div>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($absenceJustifications as $absenceJustification): ?>
                                            <div class="d-flex flex-wrap align-items-center gap-2 small">
                                                <span class="text-secondary"><?= h((string) $absenceJustification['event_date']) ?></span>
                                                <span>— <?= h((string) $absenceJustification['description']) ?></span>
                                                <?php if (!empty($absenceJustification['attachment_path'])): ?>
                                                    <a
                                                        href="<?= h((string) $absenceJustification['attachment_path']) ?>"
                                                        class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                        data-lightbox-image="<?= h((string) $absenceJustification['attachment_path']) ?>"
                                                    >Ver ficheiro</a>
                                                <?php else: ?>
                                                    <span class="text-secondary">Sem anexo</span>
                                                <?php endif; ?>
                                                <span class="badge shopfloor-status-pill"><?= h((string) $absenceJustification['status']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-secondary">Sem comunicações de ausência.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($isAdmin || $isChief): ?>
        <div class="shopfloor-panel mb-4">
            <div class="shopfloor-panel-header">
                <h2 class="h5 mb-0">Validação Nível 1 (Chefe do departamento)</h2>
                <span class="badge text-bg-light border"><?= (int) count($pendingChiefValidations) ?> pendente(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm shopfloor-table mb-0">
                    <thead><tr><th>Colaborador</th><th>Motivo</th><th>Data</th><th>Estado</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php if ($pendingChiefValidations): foreach ($pendingChiefValidations as $pendingAbsence): ?>
                        <tr>
                            <td><?= h((string) $pendingAbsence['user_name']) ?></td>
                            <td><?= h((string) $pendingAbsence['reason']) ?></td>
                            <td>
                                <?php if (($pendingAbsence['request_type'] ?? 'Dias inteiros') === 'Intervalo de tempo'): ?>
                                    <?= h((string) $pendingAbsence['start_date']) ?> · <?= h((string) ($pendingAbsence['start_time'] ?? '')) ?> → <?= h((string) ($pendingAbsence['end_time'] ?? '')) ?>
                                <?php else: ?>
                                    <?= h((string) $pendingAbsence['start_date']) ?><?= $pendingAbsence['end_date'] !== $pendingAbsence['start_date'] ? ' → ' . h((string) $pendingAbsence['end_date']) : '' ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge shopfloor-status-pill"><?= h((string) $pendingAbsence['status']) ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="review_absence">
                                        <input type="hidden" name="absence_id" value="<?= (int) $pendingAbsence['id'] ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button class="btn btn-sm btn-outline-success">Aprovar</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="review_absence">
                                        <input type="hidden" name="absence_id" value="<?= (int) $pendingAbsence['id'] ?>">
                                        <input type="hidden" name="decision" value="reject">
                                        <button class="btn btn-sm btn-outline-danger">Rejeitar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-secondary">Sem pedidos pendentes para validação de Nível 1.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin || $isRh): ?>
        <div class="shopfloor-panel mb-4">
            <div class="shopfloor-panel-header flex-wrap gap-2">
                <h2 class="h5 mb-0">Acompanhamento RH (pendentes e aprovados)</h2>
                <div class="btn-group btn-group-sm" role="group" aria-label="Filtro RH">
                    <a class="btn <?= $rhFilter === 'todos' ? 'btn-primary' : 'btn-outline-primary' ?>" href="shopfloor.php?rh_filter=todos">Todos</a>
                    <a class="btn <?= $rhFilter === 'pendentes' ? 'btn-primary' : 'btn-outline-primary' ?>" href="shopfloor.php?rh_filter=pendentes">Pendentes</a>
                    <a class="btn <?= $rhFilter === 'aprovados' ? 'btn-primary' : 'btn-outline-primary' ?>" href="shopfloor.php?rh_filter=aprovados">Aprovados</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm shopfloor-table mb-0">
                    <thead><tr><th>Colaborador</th><th>Motivo</th><th>Data</th><th>Estado</th><th class="text-end">Validação RH</th></tr></thead>
                    <tbody>
                    <?php if ($rhAbsenceRows): foreach ($rhAbsenceRows as $rhAbsence): ?>
                        <tr>
                            <td><?= h((string) $rhAbsence['user_name']) ?></td>
                            <td><?= h((string) $rhAbsence['reason']) ?></td>
                            <td>
                                <?php if (($rhAbsence['request_type'] ?? 'Dias inteiros') === 'Intervalo de tempo'): ?>
                                    <?= h((string) $rhAbsence['start_date']) ?> · <?= h((string) ($rhAbsence['start_time'] ?? '')) ?> → <?= h((string) ($rhAbsence['end_time'] ?? '')) ?>
                                <?php else: ?>
                                    <?= h((string) $rhAbsence['start_date']) ?><?= $rhAbsence['end_date'] !== $rhAbsence['start_date'] ? ' → ' . h((string) $rhAbsence['end_date']) : '' ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge shopfloor-status-pill"><?= h((string) $rhAbsence['status']) ?></span></td>
                            <td class="text-end">
                                <?php if (($rhAbsence['status'] ?? '') === 'Pendente Nível 2' || ($rhAbsence['status'] ?? '') === 'Pendente'): ?>
                                    <div class="d-inline-flex gap-2">
                                        <form method="post">
                                            <input type="hidden" name="action" value="review_absence">
                                            <input type="hidden" name="absence_id" value="<?= (int) $rhAbsence['id'] ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button class="btn btn-sm btn-outline-success">Aprovar</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="review_absence">
                                            <input type="hidden" name="absence_id" value="<?= (int) $rhAbsence['id'] ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <button class="btn btn-sm btn-outline-danger">Rejeitar</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-secondary small">Sem ação</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-secondary">Sem pedidos para o filtro selecionado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

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
                    <form method="post" class="vstack gap-2 mb-4">
                        <input type="hidden" name="action" value="publish_announcement">
                        <input type="text" name="title" class="form-control" placeholder="Título" required>
                        <textarea name="body" class="form-control" rows="3" placeholder="Mensagem" required></textarea>
                        <div>
                            <label class="form-label mb-1">Direcionado a utilizadores (opcional)</label>
                            <select name="target_user_ids[]" class="form-select" multiple size="5">
                                <?php foreach ($announcementTargetUsers as $targetUser): ?>
                                    <option value="<?= (int) $targetUser['id'] ?>"><?= h((string) $targetUser['name']) ?> (<?= h((string) $targetUser['username']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Se não selecionar ninguém, o comunicado fica visível para todos os utilizadores do Shopfloor.</div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Publicar</button>
                    </form>

                    <h3 class="h6">Gerir comunicados</h3>
                    <ul class="list-group list-group-flush">
                        <?php if ($managedAnnouncements): foreach ($managedAnnouncements as $announcement): ?>
                            <li class="list-group-item shopfloor-list-item">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div class="fw-semibold"><?= h((string) $announcement['title']) ?></div>
                                    <span class="badge <?= ((int) $announcement['is_active'] === 1) ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= ((int) $announcement['is_active'] === 1) ? 'Ativo' : 'Inativo' ?></span>
                                </div>
                                <div class="small text-secondary mb-1">Por <?= h((string) $announcement['created_by_name']) ?> em <?= h((string) $announcement['created_at']) ?></div>
                                <div class="small text-secondary mb-2">
                                    <?= (string) $announcement['audience'] === 'targeted' ? ('Direcionado a ' . (int) $announcement['target_count'] . ' utilizador(es).') : 'Visível para todos os utilizadores Shopfloor.' ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?= ((int) $announcement['is_active'] === 1) ? 'Desativar' : 'Ativar' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Eliminar este comunicado?');">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; else: ?>
                            <li class="list-group-item shopfloor-list-item text-secondary">Sem comunicados para gerir.</li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

</section>

<div class="modal fade" id="justificationLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-dark border-0">
            <div class="modal-header border-0">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <img src="" alt="Anexo da justificação" id="justificationLightboxImage" class="img-fluid rounded">
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="justificationLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-dark border-0">
            <div class="modal-header border-0">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <img src="" alt="Anexo da justificação" id="justificationLightboxImage" class="img-fluid rounded">
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const typeSelect = document.getElementById('absenceRequestType');
    const form = document.getElementById('absenceRequestForm');
    if (!typeSelect || !form) {
        return;
    }

    const fullDayFields = form.querySelectorAll('.absence-full-days-field');
    const intervalFields = form.querySelectorAll('.absence-time-range-field');
    const startDateInput = form.querySelector('input[name="start_date"]');
    const endDateInput = form.querySelector('input[name="end_date"]');
    const singleDateInput = form.querySelector('input[name="single_date"]');
    const startTimeInput = form.querySelector('input[name="start_time"]');
    const endTimeInput = form.querySelector('input[name="end_time"]');

    const refreshFields = () => {
        const isInterval = typeSelect.value === 'Intervalo de tempo';

        fullDayFields.forEach((field) => {
            field.classList.toggle('d-none', isInterval);
        });
        intervalFields.forEach((field) => {
            field.classList.toggle('d-none', !isInterval);
        });

        if (startDateInput) {
            startDateInput.required = !isInterval;
        }
        if (endDateInput) {
            endDateInput.required = !isInterval;
        }
        if (singleDateInput) {
            singleDateInput.required = isInterval;
        }
        if (startTimeInput) {
            startTimeInput.required = isInterval;
        }
        if (endTimeInput) {
            endTimeInput.required = isInterval;
        }
    };

    typeSelect.addEventListener('change', refreshFields);
    refreshFields();
})();

(() => {
    const modalElement = document.getElementById('justificationLightbox');
    const imageElement = document.getElementById('justificationLightboxImage');
    if (!modalElement || !imageElement || typeof bootstrap === 'undefined') {
        return;
    }

    const lightboxModal = new bootstrap.Modal(modalElement);
    document.querySelectorAll('[data-lightbox-image]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const imageUrl = link.getAttribute('data-lightbox-image');
            if (!imageUrl) {
                return;
            }
            imageElement.src = imageUrl;
            lightboxModal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        imageElement.src = '';
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
