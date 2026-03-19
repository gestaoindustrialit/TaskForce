<?php
require_once __DIR__ . '/helpers.php';
require_login();

if (!function_exists('format_user_picker_label')) {
    function format_user_picker_label(array $user): string
    {
        $userId = (int) ($user['id'] ?? 0);
        $userNumber = trim((string) ($user['user_number'] ?? ''));
        $userName = trim((string) ($user['name'] ?? ''));
        $labelNumber = $userNumber !== '' ? $userNumber : (string) $userId;

        return trim($labelNumber . ' - ' . $userName, ' -');
    }
}

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

function parse_alert_selected_users($value): array
{
    $rawValues = is_array($value) ? $value : array_filter(explode(',', (string) $value));
    $selected = [];

    foreach ($rawValues as $rawValue) {
        $parsedUserId = (int) $rawValue;
        if ($parsedUserId > 0) {
            $selected[$parsedUserId] = $parsedUserId;
        }
    }

    return array_values($selected);
}

function format_alert_selected_users_summary(array $selectedUsers, array $userLabelMap): string
{
    if (!$selectedUsers) {
        return 'Todos os colaboradores ativos';
    }

    $names = [];
    foreach ($selectedUsers as $selectedUserId) {
        if (isset($userLabelMap[$selectedUserId])) {
            $names[] = $userLabelMap[$selectedUserId];
        }
    }

    if (!$names) {
        return 'Colaboradores selecionados';
    }

    if (count($names) <= 2) {
        return implode(', ', $names);
    }

    return count($names) . ' colaboradores selecionados';
}

function normalize_alert_schedule_frequency(string $value): string
{
    return in_array($value, ['weekly', 'monthly'], true) ? $value : 'weekly';
}

function normalize_alert_monthly_day($value): int
{
    $day = (int) $value;
    if ($day < 1) {
        return 1;
    }
    if ($day > 31) {
        return 31;
    }

    return $day;
}

function fetch_alert_target_users(PDO $pdo, array $selectedUserIds): array
{
    $sql = 'SELECT id, name, email
            FROM users
            WHERE is_active = 1
              AND email_notifications_active = 1
              AND pin_only_login = 0
              AND TRIM(email) <> ""';
    $params = [];

    if ($selectedUserIds) {
        $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
        $sql .= ' AND id IN (' . $placeholders . ')';
        $params = $selectedUserIds;
    }

    $sql .= ' ORDER BY name COLLATE NOCASE ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function render_alert_collaborator_picker(string $pickerId, array $users, array $teams, array $selectedUsers, string $inputName, string $buttonLabel = 'Selecionar colaboradores'): void
{
    ?>
    <div class="alert-collaborator-picker" data-hr-alert-picker data-input-name="<?= h($inputName) ?>">
        <div class="alert-collaborator-toolbar">
            <div class="alert-collaborator-field alert-collaborator-field-team">
                <label class="form-label">Equipa</label>
                <select class="form-select form-select-sm js-alert-team-filter">
                    <option value="0">Todas</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int) $team['id'] ?>"><?= h((string) $team['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="alert-collaborator-field alert-collaborator-field-summary">
                <label class="form-label">Colaboradores alvo</label>
                <div class="alert-collaborator-meta border rounded p-2">
                    <div class="small text-muted js-alert-users-summary">Todos os colaboradores ativos</div>
                    <div class="results-selected-chips js-alert-users-chips mt-2"></div>
                </div>
            </div>
            <div class="alert-collaborator-field alert-collaborator-field-action">
                <label class="form-label d-none d-lg-block">&nbsp;</label>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm alert-collaborator-trigger d-flex justify-content-between align-items-center gap-2"
                    data-bs-toggle="modal"
                    data-bs-target="#<?= h($pickerId) ?>"
                >
                    <span><?= h($buttonLabel) ?></span>
                    <span class="badge text-bg-dark js-alert-users-count">0</span>
                </button>
            </div>
        </div>
        <div class="small text-muted mt-2">Se não selecionar ninguém, o alerta será enviado a todos os colaboradores elegíveis.</div>
        <div class="d-none js-alert-user-hidden-inputs">
            <?php foreach ($selectedUsers as $selectedUserId): ?>
                <input type="hidden" name="<?= h($inputName) ?>" value="<?= (int) $selectedUserId ?>">
            <?php endforeach; ?>
        </div>

        <div class="modal fade" id="<?= h($pickerId) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5">Escolher colaboradores</h2>
                            <p class="text-muted small mb-0">Pesquise, selecione vários colaboradores e aplique ao alerta.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-md-6">
                                <input type="search" class="form-control js-alert-user-search" placeholder="Pesquisar colaborador">
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                    <button type="button" class="btn btn-outline-secondary btn-sm js-alert-select-all">Selecionar todos</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm js-alert-clear-all">Limpar</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm js-alert-select-team">Só da equipa escolhida</button>
                                </div>
                            </div>
                        </div>
                        <div class="results-users-modal-list border rounded p-2">
                            <?php foreach ($users as $u): ?>
                                <?php $userLabel = format_user_picker_label($u); ?>
                                <?php $userLabelSearch = function_exists('mb_strtolower') ? mb_strtolower($userLabel) : strtolower($userLabel); ?>
                                <label class="results-user-option border px-2 py-2 rounded js-alert-user-option" data-user-option data-user-id="<?= (int) $u['id'] ?>" data-user-label="<?= h($userLabelSearch) ?>" data-user-display-label="<?= h($userLabel) ?>" data-team-ids="<?= h(implode(',', array_map('intval', (array) ($u['team_ids'] ?? [])))) ?>">
                                    <input class="form-check-input results-user-checkbox js-alert-user-checkbox" type="checkbox" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'checked' : '' ?>>
                                    <span class="results-user-meta flex-grow-1">
                                        <span class="d-block fw-semibold js-alert-user-label"><?= h($userLabel) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-dark js-alert-apply-users">Aplicar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function render_alert_schedule_fields(array $weekdayLabels, string $prefix, string $scheduleFrequency, array $selectedWeekdays, int $monthlyDay): void
{
    ?>
    <div class="alert-schedule-config" data-alert-schedule-config>
        <div class="alert-schedule-grid">
            <div class="alert-schedule-field">
                <label class="form-label">Periodicidade</label>
                <select class="form-select form-select-sm js-alert-schedule-mode" name="schedule_frequency">
                    <option value="weekly" <?= $scheduleFrequency === 'weekly' ? 'selected' : '' ?>>Semanal</option>
                    <option value="monthly" <?= $scheduleFrequency === 'monthly' ? 'selected' : '' ?>>Mensal</option>
                </select>
            </div>
            <div class="alert-schedule-field js-alert-monthly-day-wrap<?= $scheduleFrequency === 'monthly' ? '' : ' d-none' ?>">
                <label class="form-label">Dia do mês</label>
                <select class="form-select form-select-sm" name="monthly_day">
                    <?php for ($day = 1; $day <= 31; $day++): ?>
                        <option value="<?= $day ?>" <?= $monthlyDay === $day ? 'selected' : '' ?>><?= $day ?></option>
                    <?php endfor; ?>
                </select>
                <div class="form-text">Se o mês não tiver esse dia, o envio corre no último dia do mês.</div>
            </div>
            <div class="alert-schedule-field alert-schedule-field-days js-alert-weekdays-wrap<?= $scheduleFrequency === 'weekly' ? '' : ' d-none' ?>">
                <label class="form-label d-block">Dias da semana</label>
                <div class="alert-weekday-chips">
                    <?php foreach ($weekdayLabels as $value => $label): ?>
                        <label class="alert-weekday-chip">
                            <input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $value ?>" <?= in_array((string) $value, $selectedWeekdays, true) ? 'checked' : '' ?>>
                            <span><?= $label ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

$flashSuccess = null;
$flashError = null;
$alertTypeOptions = [
    'absences_daily' => 'Ausências previstas do dia',
    'vacations_weekly' => 'Resumo semanal de férias',
    'attendance_summary' => 'Resumo de assiduidade (futuro)',
    'attendance_monthly_map' => 'Mapa mensal de picagens para colaboradores',
];
$weekdayLabels = ['1' => 'Seg', '2' => 'Ter', '3' => 'Qua', '4' => 'Qui', '5' => 'Sex', '6' => 'Sáb', '7' => 'Dom'];
$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name, user_number FROM users WHERE is_active = 1 AND pin_only_login = 0 ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$userTeamMap = [];
$userTeamStmt = $pdo->query('SELECT user_id, team_id FROM team_members');
foreach ($userTeamStmt->fetchAll(PDO::FETCH_ASSOC) as $membership) {
    $memberUserId = (int) ($membership['user_id'] ?? 0);
    $memberTeamId = (int) ($membership['team_id'] ?? 0);
    if ($memberUserId <= 0 || $memberTeamId <= 0) {
        continue;
    }

    if (!isset($userTeamMap[$memberUserId])) {
        $userTeamMap[$memberUserId] = [];
    }

    $userTeamMap[$memberUserId][] = $memberTeamId;
}

$userLabelMap = [];
foreach ($users as &$listedUser) {
    $listedUserId = (int) ($listedUser['id'] ?? 0);
    $listedUser['team_ids'] = $userTeamMap[$listedUserId] ?? [];
    $userLabelMap[$listedUserId] = format_user_picker_label($listedUser);
}
unset($listedUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_alert' || $action === 'update_alert') {
        $id = (int) ($_POST['alert_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['alert_type'] ?? 'absences_daily'));
        $sendTime = trim((string) ($_POST['send_time'] ?? '08:00'));
        $scheduleFrequency = normalize_alert_schedule_frequency((string) ($_POST['schedule_frequency'] ?? 'weekly'));
        $monthlyDay = normalize_alert_monthly_day($_POST['monthly_day'] ?? 1);
        $weekdays = $_POST['weekdays'] ?? [];
        $selectedUserIds = parse_alert_selected_users($_POST['selected_user_ids'] ?? []);
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $weekdays = is_array($weekdays) ? array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', $weekdays))) : [];
        if ($scheduleFrequency === 'weekly' && count($weekdays) === 0) {
            $weekdays = ['1', '2', '3', '4', '5'];
        }

        $weekdaysMask = implode(',', $weekdays);
        $selectedUsersMask = implode(',', $selectedUserIds);

        if ($name === '' || !preg_match('/^\d{2}:\d{2}$/', $sendTime)) {
            $flashError = 'Preencha o nome e uma hora de envio válida.';
        } else {
            if (!array_key_exists($type, $alertTypeOptions)) {
                $type = 'absences_daily';
            }

            $targetUsers = fetch_alert_target_users($pdo, $selectedUserIds);
            if (!$targetUsers) {
                $flashError = 'Não existem colaboradores elegíveis com e-mail ativo para este alerta.';
            } elseif ($action === 'create_alert') {
                $stmt = $pdo->prepare('INSERT INTO hr_alerts(name, alert_type, recipient_email, send_time, weekdays_mask, schedule_frequency, monthly_day, selected_user_ids, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $type, '', $sendTime, $weekdaysMask, $scheduleFrequency, $monthlyDay, $selectedUsersMask, $isActive, $userId]);
                $flashSuccess = 'Alerta criado com sucesso.';
            } else {
                $stmt = $pdo->prepare('UPDATE hr_alerts SET name = ?, alert_type = ?, recipient_email = ?, send_time = ?, weekdays_mask = ?, schedule_frequency = ?, monthly_day = ?, selected_user_ids = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $type, '', $sendTime, $weekdaysMask, $scheduleFrequency, $monthlyDay, $selectedUsersMask, $isActive, $id]);
                $flashSuccess = 'Alerta atualizado com sucesso.';
            }
        }
    }
}

$alerts = $pdo->query('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, schedule_frequency, monthly_day, selected_user_ids, is_active, created_at FROM hr_alerts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Alertas RH';
require __DIR__ . '/partials/header.php';
?>
<style>
    .alert-page-card {
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 18px;
    }

    .alert-form-grid .form-control,
    .alert-form-grid .form-select,
    .alert-schedule-config .form-control,
    .alert-schedule-config .form-select,
    .alert-collaborator-picker .form-select {
        min-height: 38px;
    }

    .alert-inline-switch {
        display: flex;
        align-items: center;
        min-height: 38px;
    }

    .alert-config-panel {
        display: grid;
        gap: 1rem;
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.8) 0%, #fff 100%);
    }

    .alert-schedule-config,
    .alert-collaborator-picker,
    .alert-schedule-field,
    .alert-collaborator-field {
        min-width: 0;
    }

    .alert-schedule-grid,
    .alert-collaborator-toolbar {
        display: grid;
        gap: 1rem;
        align-items: start;
        grid-template-columns: minmax(180px, 220px) minmax(180px, 220px) minmax(180px, 220px) minmax(260px, 1fr);
    }

    .alert-schedule-field-days {
        grid-column: 1 / -1;
    }

    .alert-collaborator-field-action {
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
    }

    .alert-collaborator-trigger {
        width: 100%;
        min-height: 38px;
        white-space: nowrap;
    }

    .alert-collaborator-meta {
        min-height: 3.25rem;
        background: #fff;
        font-size: .9rem;
        line-height: 1;
    }

    .alert-weekday-chips,
    .results-selected-chips {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
    }

    .alert-weekday-chip {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .38rem .7rem;
        border: 1px solid rgba(148, 163, 184, 0.4);
        border-radius: 999px;
        background: #fff;
        font-size: .9rem;
        line-height: 1;
    }

    .alert-weekday-chip .form-check-input {
        margin: 0;
    }

    @media (max-width: 991.98px) {
        .alert-schedule-grid,
        .alert-collaborator-toolbar {
            grid-template-columns: 1fr;
        }

        .alert-schedule-field-days {
            grid-column: auto;
        }
    }
</style>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Alertas RH por e-mail</h1>
<p class="text-muted">Agora pode configurar alertas com execução semanal ou mensal. Para o mapa mensal de picagens, defina explicitamente o dia do mês em que pretende o envio.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4 alert-page-card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Novo alerta</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3 alert-form-grid">
            <input type="hidden" name="action" value="create_alert">
            <div class="col-xl-4 col-md-6">
                <label class="form-label">Nome interno</label>
                <input class="form-control" name="name" placeholder="Mapa mensal assiduidade" required>
            </div>
            <div class="col-xl-4 col-md-6">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="alert_type"><?php foreach ($alertTypeOptions as $k => $label): ?><option value="<?= h($k) ?>" <?= $k === 'attendance_monthly_map' ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label">Hora envio</label>
                <input class="form-control" type="time" name="send_time" value="08:00" required>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label d-none d-md-block">&nbsp;</label>
                <div class="form-check form-switch alert-inline-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label ms-2">Ativo</label></div>
            </div>
            <div class="col-12">
                <div class="alert-config-panel border rounded p-3">
                    <?php render_alert_schedule_fields($weekdayLabels, 'create', 'monthly', ['1', '2', '3', '4', '5'], 1); ?>
                    <?php render_alert_collaborator_picker('alertCollaboratorsCreateModal', $users, $teams, [], 'selected_user_ids[]'); ?>
                </div>
            </div>
            <div class="alert-form-field-wide"><button class="btn btn-primary">Criar alerta</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Alertas configurados</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Nome</th><th>Tipo</th><th>Hora</th><th>Agendamento</th><th>Colaboradores</th><th>Estado</th><th>Editar</th></tr></thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                        <?php $mask = array_filter(explode(',', (string) ($alert['weekdays_mask'] ?? ''))); ?>
                        <?php $selectedAlertUsers = parse_alert_selected_users((string) ($alert['selected_user_ids'] ?? '')); ?>
                        <?php $scheduleFrequency = normalize_alert_schedule_frequency((string) ($alert['schedule_frequency'] ?? 'weekly')); ?>
                        <?php $monthlyDay = normalize_alert_monthly_day($alert['monthly_day'] ?? 1); ?>
                        <?php $scheduleSummary = $scheduleFrequency === 'monthly'
                            ? 'Mensal · dia ' . $monthlyDay
                            : 'Semanal · ' . implode(', ', array_map(static fn($d) => $weekdayLabels[$d] ?? $d, $mask)); ?>
                        <tr>
                            <td><?= h($alert['name']) ?></td>
                            <td><?= h($alertTypeOptions[$alert['alert_type']] ?? $alert['alert_type']) ?></td>
                            <td><?= h((string) $alert['send_time']) ?></td>
                            <td><?= h(implode(', ', array_map(static fn($d) => $weekdayLabels[$d] ?? $d, $mask))) ?></td>
                            <td><?= h(format_alert_selected_users_summary($selectedAlertUsers, $userLabelMap)) ?></td>
                            <td><?= (int) $alert['is_active'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                            <td>
                                <form method="post" class="row g-2">
                                    <input type="hidden" name="action" value="update_alert">
                                    <input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>">
                                    <div class="col-md-4"><input class="form-control form-control-sm" name="name" value="<?= h($alert['name']) ?>" required></div>
                                    <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="send_time" value="<?= h((string) $alert['send_time']) ?>" required></div>
                                    <div class="col-md-4"><select class="form-select form-select-sm" name="alert_type"><?php foreach ($alertTypeOptions as $k => $label): ?><option value="<?= h($k) ?>" <?= $alert['alert_type'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-2 d-flex align-items-center gap-2 flex-wrap">
                                        <input type="hidden" name="is_active" value="<?= (int) $alert['is_active'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary">Guardar</button>
                                    </div>
                                    <div class="col-12">
                                        <div class="alert-config-panel border rounded p-3">
                                            <?php render_alert_schedule_fields($weekdayLabels, 'edit' . (int) $alert['id'], $scheduleFrequency, array_map('strval', $mask), $monthlyDay); ?>
                                            <?php render_alert_collaborator_picker('alertCollaboratorsEditModal' . (int) $alert['id'], $users, $teams, $selectedAlertUsers, 'selected_user_ids[]', 'Editar colaboradores'); ?>
                                        </div>
                                    </div>
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
