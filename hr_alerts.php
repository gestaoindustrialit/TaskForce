<?php
require_once __DIR__ . '/helpers.php';
require_login();

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
        $userId = (int) $rawValue;
        if ($userId > 0) {
            $selected[$userId] = $userId;
        }
    }

    return array_values($selected);
}

function format_alert_selected_users_summary(array $selectedUsers, array $userNameMap): string
{
    if (!$selectedUsers) {
        return 'Todos os colaboradores ativos';
    }

    $names = [];
    foreach ($selectedUsers as $selectedUserId) {
        if (isset($userNameMap[$selectedUserId])) {
            $names[] = $userNameMap[$selectedUserId];
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

function render_alert_collaborator_picker(string $pickerId, array $users, array $teams, array $selectedUsers, string $inputName, string $buttonLabel = 'Selecionar colaboradores'): void
{
    ?>
    <div class="alert-collaborator-picker" data-hr-alert-picker data-input-name="<?= h($inputName) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label">Equipa</label>
                <select class="form-select js-alert-team-filter">
                    <option value="0">Todas</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int) $team['id'] ?>"><?= h((string) $team['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-8">
                <label class="form-label">Colaboradores alvo</label>
                <div class="alert-collaborator-meta border rounded p-2">
                    <div class="small text-muted js-alert-users-summary">Todos os colaboradores ativos</div>
                    <div class="results-selected-chips js-alert-users-chips mt-2"></div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
            <button
                type="button"
                class="btn btn-outline-secondary d-flex justify-content-between align-items-center gap-3"
                data-bs-toggle="modal"
                data-bs-target="#<?= h($pickerId) ?>"
            >
                <span><?= h($buttonLabel) ?></span>
                <span class="badge text-bg-dark js-alert-users-count">0</span>
            </button>
            <div class="small text-muted">Se não selecionar ninguém, o alerta será enviado a todos os colaboradores elegíveis.</div>
        </div>
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
                                <?php $userLabel = (string) (($u['user_number'] ?: $u['id']) . ' - ' . $u['name']); ?>
                                <?php $userLabelSearch = function_exists('mb_strtolower') ? mb_strtolower($userLabel) : strtolower($userLabel); ?>
                                <label class="results-user-option border px-2 py-2 rounded js-alert-user-option" data-user-option data-user-id="<?= (int) $u['id'] ?>" data-user-label="<?= h($userLabelSearch) ?>" data-team-ids="<?= h(implode(',', array_map('intval', (array) ($u['team_ids'] ?? [])))) ?>">
                                    <input class="form-check-input results-user-checkbox js-alert-user-checkbox" type="checkbox" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $selectedUsers, true) ? 'checked' : '' ?>>
                                    <span class="results-user-meta flex-grow-1">
                                        <span class="d-block fw-semibold"><?= h((string) $u['name']) ?></span>
                                        <span class="d-block text-muted small"><?= h((string) ($u['user_number'] !== '' ? $u['user_number'] : $u['id'])) ?></span>
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

$userNameMap = [];
foreach ($users as &$listedUser) {
    $listedUserId = (int) ($listedUser['id'] ?? 0);
    $listedUser['team_ids'] = $userTeamMap[$listedUserId] ?? [];
    $userNameMap[$listedUserId] = (string) ($listedUser['name'] ?? '');
}
unset($listedUser);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_alert' || $action === 'update_alert') {
        $id = (int) ($_POST['alert_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = trim((string) ($_POST['alert_type'] ?? 'absences_daily'));
        $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
        $sendTime = trim((string) ($_POST['send_time'] ?? '08:00'));
        $weekdays = $_POST['weekdays'] ?? [];
        $selectedUserIds = parse_alert_selected_users($_POST['selected_user_ids'] ?? []);
        $isActive = (int) ($_POST['is_active'] ?? 0);

        $weekdays = is_array($weekdays) ? array_values(array_intersect(['1', '2', '3', '4', '5', '6', '7'], array_map('strval', $weekdays))) : [];
        $weekdaysMask = count($weekdays) > 0 ? implode(',', $weekdays) : '1,2,3,4,5';
        $selectedUsersMask = implode(',', $selectedUserIds);

        if ($name === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{2}:\d{2}$/', $sendTime)) {
            $flashError = 'Preencha nome, email válido e hora de envio.';
        } else {
            if (!array_key_exists($type, $alertTypeOptions)) {
                $type = 'absences_daily';
            }

            if ($action === 'create_alert') {
                $stmt = $pdo->prepare('INSERT INTO hr_alerts(name, alert_type, recipient_email, send_time, weekdays_mask, selected_user_ids, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $type, $recipient, $sendTime, $weekdaysMask, $selectedUsersMask, $isActive, $userId]);
                $flashSuccess = 'Alerta criado com sucesso.';
            } else {
                $stmt = $pdo->prepare('UPDATE hr_alerts SET name = ?, alert_type = ?, recipient_email = ?, send_time = ?, weekdays_mask = ?, selected_user_ids = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $type, $recipient, $sendTime, $weekdaysMask, $selectedUsersMask, $isActive, $id]);
                $flashSuccess = 'Alerta atualizado com sucesso.';
            }
        }
    }
}

$alerts = $pdo->query('SELECT id, name, alert_type, recipient_email, send_time, weekdays_mask, selected_user_ids, is_active, created_at FROM hr_alerts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Alertas RH';
require __DIR__ . '/partials/header.php';
?>
<style>
    .alert-collaborator-meta {
        min-height: 4.5rem;
        background: #fff;
    }
</style>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Alertas RH por e-mail</h1>
<p class="text-muted">Exemplos suportados: ausências do dia para RH e mapa mensal de picagens enviado automaticamente aos colaboradores no 1.º dia de cada mês.</p>

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
            <div class="col-12">
                <?php render_alert_collaborator_picker('alertCollaboratorsCreateModal', $users, $teams, [], 'selected_user_ids[]'); ?>
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
                <thead><tr><th>Nome</th><th>Tipo</th><th>Destino</th><th>Hora</th><th>Dias</th><th>Colaboradores</th><th>Estado</th><th>Editar</th></tr></thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                        <?php $mask = array_filter(explode(',', (string) $alert['weekdays_mask'])); ?>
                        <?php $selectedAlertUsers = parse_alert_selected_users((string) ($alert['selected_user_ids'] ?? '')); ?>
                        <tr>
                            <td><?= h($alert['name']) ?></td>
                            <td><?= h($alertTypeOptions[$alert['alert_type']] ?? $alert['alert_type']) ?></td>
                            <td><?= h($alert['recipient_email']) ?></td>
                            <td><?= h((string) $alert['send_time']) ?></td>
                            <td><?= h(implode(', ', array_map(static fn($d) => $weekdayLabels[$d] ?? $d, $mask))) ?></td>
                            <td><?= h(format_alert_selected_users_summary($selectedAlertUsers, $userNameMap)) ?></td>
                            <td><?= (int) $alert['is_active'] === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                            <td>
                                <form method="post" class="row g-2">
                                    <input type="hidden" name="action" value="update_alert">
                                    <input type="hidden" name="alert_id" value="<?= (int) $alert['id'] ?>">
                                    <div class="col-md-3"><input class="form-control form-control-sm" name="name" value="<?= h($alert['name']) ?>" required></div>
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="email" name="recipient_email" value="<?= h($alert['recipient_email']) ?>" required></div>
                                    <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="send_time" value="<?= h((string) $alert['send_time']) ?>" required></div>
                                    <div class="col-md-2"><select class="form-select form-select-sm" name="alert_type"><?php foreach ($alertTypeOptions as $k => $label): ?><option value="<?= h($k) ?>" <?= $alert['alert_type'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-md-2 d-flex align-items-center gap-2 flex-wrap">
                                        <?php foreach (['1', '2', '3', '4', '5'] as $weekdayValue): ?>
                                            <input type="hidden" name="weekdays[]" value="<?= h($weekdayValue) ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="is_active" value="<?= (int) $alert['is_active'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary">Guardar</button>
                                    </div>
                                    <div class="col-12">
                                        <?php render_alert_collaborator_picker('alertCollaboratorsEditModal' . (int) $alert['id'], $users, $teams, $selectedAlertUsers, 'selected_user_ids[]', 'Editar colaboradores'); ?>
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
