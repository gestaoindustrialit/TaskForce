<?php
$helpersPath = dirname(__DIR__) . '/helpers.php';
if (!is_file($helpersPath)) {
    $helpersPath = __DIR__ . '/helpers.php';
}
require_once $helpersPath;
$user = current_user($pdo);
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$showHrMenu = $user && ((int) ($user['is_admin'] ?? 0) === 1 || (string) ($user['access_profile'] ?? '') === 'RH');
$isPinOnlyUser = $user && (int) ($user['pin_only_login'] ?? 0) === 1;

if ($user && !isset($navbarClockControl)) {
    $todayEntriesStmt = $pdo->prepare('SELECT entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = date("now", "localtime") ORDER BY occurred_at DESC');
    $todayEntriesStmt->execute([(int) $user['id']]);
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

    $navbarClockControl = [
        'form_action' => 'shopfloor.php',
        'entry_type' => $nextEntryType,
        'button_label' => $clockButtonLabel,
        'button_class' => $clockButtonClass,
        'latest_time_label' => $latestEntryTimeLabel,
    ];

    $activeBreakStmt = $pdo->prepare('SELECT b.id, b.break_reason_id, b.started_at, b.comment, r.code, r.label, r.break_type, r.requires_comment FROM shopfloor_break_entries b INNER JOIN shopfloor_break_reasons r ON r.id = b.break_reason_id WHERE b.user_id = ? AND b.ended_at IS NULL ORDER BY b.started_at DESC LIMIT 1');
    $activeBreakStmt->execute([(int) $user['id']]);
    $activeBreak = $activeBreakStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $breakReasonOptionsStmt = $pdo->query('SELECT id, code, label, break_type, requires_comment FROM shopfloor_break_reasons WHERE is_active = 1 ORDER BY code COLLATE NOCASE ASC');
    $breakReasonOptions = $breakReasonOptionsStmt ? $breakReasonOptionsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $navbarBreakControl = [
        'form_action' => 'shopfloor.php',
        'active_break' => $activeBreak,
        'reason_options' => $breakReasonOptions,
    ];
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>TaskForce</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body class="<?= isset($bodyClass) ? h($bodyClass) : 'bg-light' ?>">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <?php if ($navbarLogo): ?>
                <img src="<?= h($navbarLogo) ?>" alt="Logo empresa" class="brand-logo">
            <?php endif; ?>
            <span>TaskForce</span>
        </a>
        <?php if ($user): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Alternar navega&ccedil;&atilde;o">
                <span class="navbar-toggler-icon"></span>
            </button>
        <?php endif; ?>
        <?php if ($user): ?>
            <?php
            $navTeamsStmt = $pdo->prepare(
                'SELECT t.id, t.name
                 FROM teams t
                 INNER JOIN team_members tm ON tm.team_id = t.id
                 WHERE tm.user_id = ?
                 ORDER BY t.name COLLATE NOCASE ASC'
            );
            $navTeamsStmt->execute([(int) $user['id']]);
            $navTeams = $navTeamsStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <div class="navbar-nav me-auto ms-lg-4">
                    <?php if ($isPinOnlyUser): ?>
                        <a class="nav-link" href="shopfloor.php">Shopfloor</a>
                    <?php else: ?>
                    <a class="nav-link" href="dashboard.php">Vis&atilde;o geral</a>
                    <a class="nav-link" href="shopfloor.php">Shopfloor</a>
                    <?php if ($showHrMenu): ?>
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">RH</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="hr.php">M&oacute;dulo RH</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Gestão base</h6></li>
                                <li><a class="dropdown-item" href="users.php">Utilizadores</a></li>
                                <li><a class="dropdown-item" href="hr_departments.php">Departamentos</a></li>
                                <li><a class="dropdown-item" href="hr_schedules.php">Hor&aacute;rios</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Operação diária</h6></li>
                                <li><a class="dropdown-item" href="hr_calendar.php">Calend&aacute;rio</a></li>
                                <li><a class="dropdown-item" href="hr_bank.php">Banco de horas</a></li>
                                <li><a class="dropdown-item" href="hr_absences.php">Aus&ecirc;ncias</a></li>
                                <li><a class="dropdown-item" href="hr_vacations.php">F&eacute;rias</a></li>
                                <li><a class="dropdown-item" href="hr_alerts.php">Alertas RH</a></li>
                                <li><a class="dropdown-item" href="hr_evaluations.php">Avaliações</a></li>
                                <li><a class="dropdown-item" href="hr_evaluation_rules.php">Regras de avaliações</a></li>
                                <li><a class="dropdown-item" href="resultados.php">Resultados</a></li>
                                <li><a class="dropdown-item" href="shopfloor_absence_reasons.php">Motivos de ausência</a></li>
                                <li><a class="dropdown-item" href="shopfloor_break_reasons.php">Pausas e paragens</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Equipas</a>
                        <ul class="dropdown-menu">
                            <?php if ($navTeams): ?>
                                <?php foreach ($navTeams as $navTeam): ?>
                                    <li><a class="dropdown-item" href="team.php?id=<?= (int) $navTeam['id'] ?>"><?= h($navTeam['name']) ?></a></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li><span class="dropdown-item-text text-muted">Sem equipas dispon&iacute;veis</span></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php if ((int) $user['is_admin'] === 1): ?>
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Administra&ccedil;&atilde;o</a>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-lg-start">
                                <li><a class="dropdown-item" href="company_profile.php">Empresa &amp; Branding</a></li>
                                <li><a class="dropdown-item" href="requests.php">Gerar formul&aacute;rios</a></li>
                                <li><a class="dropdown-item" href="checklists.php">Checklists</a></li>
                                <li><a class="dropdown-item" href="app_logs.php">Logs da aplica&ccedil;&atilde;o</a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                    <?php endif; ?>
                </div>
                <div class="navbar-user mt-3 mt-lg-0 ms-lg-auto d-flex flex-column flex-lg-row align-items-start align-items-lg-center gap-2 gap-lg-3 text-white">
                    <span class="small">Ol&aacute;, <?= h($user['name']) ?><?= (int) $user['is_admin'] === 1 ? ' &middot; Admin' : '' ?></span>
                    <?php if (isset($navbarClockControl) && is_array($navbarClockControl)): ?>
                        <form method="post" action="<?= h((string) ($navbarClockControl['form_action'] ?? 'shopfloor.php')) ?>" class="d-flex align-items-center gap-2 mb-0">
                            <input type="hidden" name="action" value="clock_entry">
                            <input type="hidden" name="entry_type" value="<?= h((string) ($navbarClockControl['entry_type'] ?? 'entrada')) ?>">
                            <button type="submit" class="btn btn-sm fw-semibold <?= h((string) ($navbarClockControl['button_class'] ?? 'btn-primary')) ?>"><?= h((string) ($navbarClockControl['button_label'] ?? 'Ponto de entrada')) ?></button>
                            <?php if (!empty($navbarClockControl['latest_time_label'])): ?>
                                <span class="small text-white-50"><?= h((string) $navbarClockControl['latest_time_label']) ?></span>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if (isset($navbarBreakControl) && is_array($navbarBreakControl)): ?>
                        <?php $activeBreak = $navbarBreakControl['active_break'] ?? null; ?>
                        <button
                            type="button"
                            class="btn btn-sm fw-semibold <?= $activeBreak ? 'btn-danger' : 'btn-success' ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#navbarBreakModal"
                        >
                            <?= $activeBreak ? 'Terminar pausa/paragem' : 'Iniciar pausa/paragem' ?>
                        </button>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">Sair</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>
<?php if (isset($navbarBreakControl) && is_array($navbarBreakControl)): ?>
    <?php $activeBreak = $navbarBreakControl['active_break'] ?? null; ?>
    <div
        class="modal fade"
        id="navbarBreakModal"
        tabindex="-1"
        aria-labelledby="navbarBreakModalLabel"
        aria-hidden="true"
        <?= $activeBreak ? 'data-bs-backdrop="static" data-bs-keyboard="false"' : '' ?>
        <?= $activeBreak ? ('data-break-started-at="' . h((string) ($activeBreak['started_at'] ?? '')) . '"') : '' ?>
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= h((string) ($navbarBreakControl['form_action'] ?? 'shopfloor.php')) ?>">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="navbarBreakModalLabel"><?= $activeBreak ? 'Terminar pausa/paragem' : 'Iniciar pausa/paragem' ?></h2>
                        <?php if (!$activeBreak): ?>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        <?php endif; ?>
                    </div>
                    <div class="modal-body">
                        <?php if ($activeBreak): ?>
                            <input type="hidden" name="action" value="stop_break">
                            <p class="mb-2"><strong><?= h((string) ($activeBreak['break_type'] ?? 'Pausa')) ?></strong> em curso: <?= h((string) ($activeBreak['code'] ?? '')) ?> | <?= h((string) ($activeBreak['label'] ?? '')) ?></p>
                            <p class="small text-secondary">Iniciada às <?= h(date('H:i', strtotime((string) ($activeBreak['started_at'] ?? 'now')))) ?>.</p>
                            <div class="display-5 fw-bold text-center text-danger mb-3" id="navbarBreakElapsed">00:00:00</div>
                            <div class="mb-2 <?= (int) ($activeBreak['requires_comment'] ?? 0) === 1 ? '' : 'd-none' ?>">
                                <label class="form-label">Comentário</label>
                                <textarea class="form-control" name="break_comment" rows="3" placeholder="Comentário obrigatório para terminar" <?= (int) ($activeBreak['requires_comment'] ?? 0) === 1 ? 'required' : '' ?>></textarea>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="action" value="start_break">
                            <div class="mb-3">
                                <label class="form-label">Tipo de pausa/paragem</label>
                                <select class="form-select" name="break_reason_id" id="navbarBreakReasonSelect" required>
                                    <option value="">Selecionar</option>
                                    <?php foreach (($navbarBreakControl['reason_options'] ?? []) as $breakReasonOption): ?>
                                        <option
                                            value="<?= (int) $breakReasonOption['id'] ?>"
                                            data-requires-comment="<?= (int) ($breakReasonOption['requires_comment'] ?? 0) ?>"
                                        >
                                            <?= h((string) $breakReasonOption['code']) ?> | <?= h((string) $breakReasonOption['label']) ?> (<?= h((string) $breakReasonOption['break_type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2 d-none" id="navbarBreakCommentWrap">
                                <label class="form-label">Comentário</label>
                                <textarea class="form-control" name="break_comment" id="navbarBreakComment" rows="3" placeholder="Obrigatório para este tipo"></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if (!$activeBreak): ?>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <?php endif; ?>
                        <button type="submit" class="btn fw-semibold <?= $activeBreak ? 'btn-danger' : 'btn-success' ?>">
                            <?= $activeBreak ? 'Terminar' : 'Iniciar' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const modalElement = document.getElementById('navbarBreakModal');
            const reasonSelect = document.getElementById('navbarBreakReasonSelect');
            const commentWrap = document.getElementById('navbarBreakCommentWrap');
            const commentField = document.getElementById('navbarBreakComment');
            const elapsedElement = document.getElementById('navbarBreakElapsed');
            let timerIntervalId = null;

            if (reasonSelect && commentWrap && commentField) {
                const syncCommentVisibility = () => {
                    const selectedOption = reasonSelect.options[reasonSelect.selectedIndex] || null;
                    const requiresComment = selectedOption ? selectedOption.getAttribute('data-requires-comment') === '1' : false;
                    commentWrap.classList.toggle('d-none', !requiresComment);
                    commentField.required = requiresComment;
                    if (!requiresComment) {
                        commentField.value = '';
                    }
                };

                reasonSelect.addEventListener('change', syncCommentVisibility);
                syncCommentVisibility();
            }

            if (modalElement && elapsedElement && modalElement.dataset.breakStartedAt) {
                const startTimestamp = Date.parse(modalElement.dataset.breakStartedAt.replace(' ', 'T'));
                const formatSeconds = (seconds) => {
                    const safeSeconds = Math.max(0, seconds);
                    const hours = Math.floor(safeSeconds / 3600);
                    const minutes = Math.floor((safeSeconds % 3600) / 60);
                    const remainingSeconds = safeSeconds % 60;
                    return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(remainingSeconds).padStart(2, '0');
                };

                const renderElapsed = () => {
                    if (Number.isNaN(startTimestamp)) {
                        elapsedElement.textContent = '00:00:00';
                        return;
                    }
                    const elapsedSeconds = Math.floor((Date.now() - startTimestamp) / 1000);
                    elapsedElement.textContent = formatSeconds(elapsedSeconds);
                };

                renderElapsed();
                timerIntervalId = window.setInterval(renderElapsed, 1000);

                if (window.bootstrap && window.bootstrap.Modal) {
                    const modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalElement);
                    modalInstance.show();
                }
            }

            window.addEventListener('beforeunload', () => {
                if (timerIntervalId !== null) {
                    window.clearInterval(timerIntervalId);
                }
            });
        })();
    </script>
<?php endif; ?>
<main class="container py-4">
