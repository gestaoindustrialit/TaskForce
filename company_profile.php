<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
if (!$isAdmin) {
    redirect('dashboard.php');
}

$flashSuccess = null;
$flashError = null;

$companyName = '';
$companyAddress = '';
$companyEmail = '';
$companyPhone = '';
$smtpHost = '';
$smtpPort = '587';
$smtpSecure = 'tls';
$smtpUsername = '';
$smtpPassword = '';
$smtpTimeout = '10';
$mailFromAddress = 'noreply@calcadacorp.ch';
$mailFromName = 'TaskForce';
$hrAlertsCronRunsPerDay = '1440';
$navbarLogo = null;
$reportLogo = null;
$ticketStatuses = [];
$recurrenceCatalog = [];
$pendingDepartmentCatalog = [];

try {
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $flashError = 'Apenas administradores podem editar os dados da empresa.';
    } elseif (($_POST['action'] ?? '') === 'save_company_profile') {
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
        $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
        $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));
        $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
        $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
        $smtpSecure = strtolower(trim((string) ($_POST['smtp_secure'] ?? 'tls')));
        $smtpUsername = trim((string) ($_POST['smtp_username'] ?? ''));
        $smtpPassword = trim((string) ($_POST['smtp_password'] ?? ''));
        $smtpTimeout = (int) ($_POST['smtp_timeout_seconds'] ?? 10);
        $mailFromAddress = trim((string) ($_POST['mail_from_address'] ?? ''));
        $mailFromName = trim((string) ($_POST['mail_from_name'] ?? ''));
        $hrAlertsCronRunsPerDay = (int) ($_POST['hr_alerts_inline_cron_runs_per_day'] ?? 1440);

        $statusValues = $_POST['ticket_status_value'] ?? [];
        $statusLabels = $_POST['ticket_status_label'] ?? [];
        $statusCompleted = $_POST['ticket_status_completed'] ?? [];
        $statusSortOrders = $_POST['ticket_status_sort_order'] ?? [];
        $statusColors = $_POST['ticket_status_color'] ?? [];
        $ticketStatuses = [];

        $recurrenceValues = $_POST['recurrence_value'] ?? [];
        $recurrenceLabels = $_POST['recurrence_label'] ?? [];
        $recurrenceEnabled = $_POST['recurrence_enabled'] ?? [];
        $recurrenceCatalog = [];

        $pendingDepartmentValues = $_POST['pending_department_value'] ?? [];
        $pendingDepartmentEnabled = $_POST['pending_department_enabled'] ?? [];
        $pendingDepartmentCatalog = [];

        foreach ($statusValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            $label = trim((string) ($statusLabels[$index] ?? ''));
            $sortOrder = (int) ($statusSortOrders[$index] ?? 0);
            $color = strtoupper(trim((string) ($statusColors[$index] ?? '')));

            if ($value === '' && $label === '') {
                continue;
            }

            $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?: '';
            if ($value === '' || $label === '') {
                continue;
            }

            if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
                $color = (isset($statusCompleted[$index]) && $statusCompleted[$index] === '1') ? '#22C55E' : '#FACC15';
            }

            if (isset($ticketStatuses[$value])) {
                continue;
            }

            $ticketStatuses[$value] = [
                'value' => $value,
                'label' => $label,
                'is_completed' => isset($statusCompleted[$index]) && $statusCompleted[$index] === '1',
                'sort_order' => $sortOrder,
                'color' => $color,
            ];
        }

        $defaultRecurrences = default_recurring_task_recurrence_options();
        $allowedRecurrences = [];
        foreach ($defaultRecurrences as $defaultRecurrence) {
            $allowedRecurrences[(string) $defaultRecurrence['value']] = (string) $defaultRecurrence['label'];
        }

        $defaultPendingDepartments = default_pending_ticket_department_options($pdo);
        $allowedPendingDepartments = [];
        foreach ($defaultPendingDepartments as $defaultDepartment) {
            $allowedPendingDepartments[(string) $defaultDepartment['value']] = (string) $defaultDepartment['label'];
        }

        foreach ($pendingDepartmentValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            if (!isset($allowedPendingDepartments[$value]) || isset($pendingDepartmentCatalog[$value])) {
                continue;
            }

            $pendingDepartmentCatalog[$value] = [
                'value' => $value,
                'label' => $allowedPendingDepartments[$value],
                'enabled' => isset($pendingDepartmentEnabled[$index]) && $pendingDepartmentEnabled[$index] === '1',
            ];
        }

        foreach ($defaultPendingDepartments as $defaultDepartment) {
            $value = (string) $defaultDepartment['value'];
            if (!isset($pendingDepartmentCatalog[$value])) {
                $pendingDepartmentCatalog[$value] = [
                    'value' => $value,
                    'label' => (string) $defaultDepartment['label'],
                    'enabled' => !empty($defaultDepartment['enabled']),
                ];
            }
        }

        foreach ($recurrenceValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            if (!isset($allowedRecurrences[$value]) || isset($recurrenceCatalog[$value])) {
                continue;
            }

            $label = trim((string) ($recurrenceLabels[$index] ?? ''));
            if ($label === '') {
                $label = $allowedRecurrences[$value];
            }

            $recurrenceCatalog[$value] = [
                'value' => $value,
                'label' => $label,
                'enabled' => isset($recurrenceEnabled[$index]) && $recurrenceEnabled[$index] === '1',
            ];
        }

        foreach ($defaultRecurrences as $defaultRecurrence) {
            $value = (string) $defaultRecurrence['value'];
            if (!isset($recurrenceCatalog[$value])) {
                $recurrenceCatalog[$value] = [
                    'value' => $value,
                    'label' => (string) $defaultRecurrence['label'],
                    'enabled' => !empty($defaultRecurrence['enabled']),
                ];
            }
        }

        if (!in_array($smtpSecure, ['', 'tls', 'ssl'], true)) {
            $smtpSecure = 'tls';
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        if ($smtpTimeout < 3 || $smtpTimeout > 120) {
            $smtpTimeout = 10;
        }
        if ($hrAlertsCronRunsPerDay < 1) {
            $hrAlertsCronRunsPerDay = 1;
        } elseif ($hrAlertsCronRunsPerDay > 1440) {
            $hrAlertsCronRunsPerDay = 1440;
        }

        if ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para a empresa.';
        } elseif ($smtpHost !== '' && $smtpUsername === '') {
            $flashError = 'Preencha o utilizador SMTP quando definir um servidor SMTP.';
        } elseif ($smtpHost !== '' && $smtpPassword === '') {
            $flashError = 'Preencha a password SMTP quando definir um servidor SMTP.';
        } elseif ($mailFromAddress !== '' && filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para o remetente.';
        } elseif (count($ticketStatuses) === 0) {
            $flashError = 'Defina pelo menos um estado para os tickets.';
        } elseif (!array_filter($ticketStatuses, static fn (array $status): bool => empty($status['is_completed']))) {
            $flashError = 'Defina pelo menos um estado não concluído para os tickets.';
        } elseif (!array_filter($recurrenceCatalog, static fn (array $entry): bool => !empty($entry['enabled']))) {
            $flashError = 'Ative pelo menos um tipo de recorrência para tarefas recorrentes.';
        } elseif (!array_filter($pendingDepartmentCatalog, static fn (array $entry): bool => !empty($entry['enabled']))) {
            $flashError = 'Ative pelo menos um departamento para pendentes no dashboard.';
        } else {
            set_app_setting($pdo, 'company_name', $companyName);
            set_app_setting($pdo, 'company_address', $companyAddress);
            set_app_setting($pdo, 'company_email', $companyEmail);
            set_app_setting($pdo, 'company_phone', $companyPhone);
            set_app_setting($pdo, 'smtp_host', $smtpHost);
            set_app_setting($pdo, 'smtp_port', (string) $smtpPort);
            set_app_setting($pdo, 'smtp_secure', $smtpSecure);
            set_app_setting($pdo, 'smtp_username', $smtpUsername);
            set_app_setting($pdo, 'smtp_password', $smtpPassword);
            set_app_setting($pdo, 'smtp_timeout_seconds', (string) $smtpTimeout);
            set_app_setting($pdo, 'mail_from_address', $mailFromAddress);
            set_app_setting($pdo, 'mail_from_name', $mailFromName);
            set_app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', (string) $hrAlertsCronRunsPerDay);
            set_app_setting($pdo, 'ticket_statuses_json', json_encode(array_values($ticketStatuses), JSON_UNESCAPED_UNICODE));
            set_app_setting($pdo, 'recurring_task_recurrences_json', json_encode(array_values($recurrenceCatalog), JSON_UNESCAPED_UNICODE));
            set_app_setting($pdo, 'pending_ticket_departments_json', json_encode(array_values($pendingDepartmentCatalog), JSON_UNESCAPED_UNICODE));

            $savedLogos = 0;
            $lightPath = save_brand_logo($_FILES['logo_navbar_light'] ?? [], 'navbar_light');
            if ($lightPath) {
                set_app_setting($pdo, 'logo_navbar_light', $lightPath);
                $savedLogos++;
            }

            $darkPath = save_brand_logo($_FILES['logo_report_dark'] ?? [], 'report_dark');
            if ($darkPath) {
                set_app_setting($pdo, 'logo_report_dark', $darkPath);
                $savedLogos++;
            }

            $flashSuccess = $savedLogos > 0
                ? 'Dados da empresa e logotipos atualizados com sucesso.'
                : 'Dados da empresa atualizados com sucesso.';
        }
    } elseif (($_POST['action'] ?? '') === 'reset_hr_operational_data') {
        $confirmation = trim((string) ($_POST['reset_confirmation'] ?? ''));
        if (mb_strtoupper($confirmation, 'UTF-8') !== 'RESET') {
            $flashError = 'Para confirmar a limpeza dos dados, escreva RESET no campo de confirmação.';
        } else {
            $tablesToReset = [
                'shopfloor_absence_time_allocations',
                'shopfloor_justifications',
                'shopfloor_absence_requests',
                'shopfloor_vacation_requests',
                'shopfloor_time_entries',
                'shopfloor_hour_banks',
                'shopfloor_bh_overrides',
                'shopfloor_bh_override_logs',
                'hr_hour_bank_logs',
                'hr_vacation_events',
                'hr_vacation_balances',
                'hr_calendar_events',
            ];

            try {
                $pdo->beginTransaction();
                $deletedRows = 0;
                foreach ($tablesToReset as $tableName) {
                    $deletedRows += (int) $pdo->exec('DELETE FROM ' . $tableName);
                }
                $pdo->commit();
                log_app_event($pdo, $userId, 'company_profile.reset_hr_operational_data', 'Limpeza total de picagens e pedidos de ausências/férias.', ['deleted_rows' => $deletedRows]);
                $flashSuccess = $deletedRows > 0
                    ? 'Limpeza concluída: foram removidos ' . $deletedRows . ' registos de picagens, ausências e férias.'
                    : 'Não existiam registos de picagens, ausências ou férias para limpar.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flashError = 'Não foi possível concluir a limpeza dos dados operacionais.';
            }
        }
    }
}

$companyName = (string) app_setting($pdo, 'company_name', '');
$companyAddress = (string) app_setting($pdo, 'company_address', '');
$companyEmail = (string) app_setting($pdo, 'company_email', '');
$companyPhone = (string) app_setting($pdo, 'company_phone', '');
$smtpHost = (string) app_setting($pdo, 'smtp_host', '');
$smtpPort = (string) app_setting($pdo, 'smtp_port', '587');
$smtpSecure = (string) app_setting($pdo, 'smtp_secure', 'tls');
$smtpUsername = (string) app_setting($pdo, 'smtp_username', '');
$smtpPassword = (string) app_setting($pdo, 'smtp_password', '');
$smtpTimeout = (string) app_setting($pdo, 'smtp_timeout_seconds', '10');
$mailFromAddress = (string) app_setting($pdo, 'mail_from_address', 'noreply@calcadacorp.ch');
$mailFromName = (string) app_setting($pdo, 'mail_from_name', 'TaskForce');
$hrAlertsCronRunsPerDay = (string) app_setting($pdo, 'hr_alerts_inline_cron_runs_per_day', '1440');
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$reportLogo = app_setting($pdo, 'logo_report_dark');
$ticketStatuses = ticket_statuses($pdo);
$recurrenceCatalog = recurring_task_recurrence_catalog($pdo);
$pendingDepartmentCatalog = function_exists('pending_ticket_department_catalog')
    ? pending_ticket_department_catalog($pdo)
    : default_pending_ticket_department_options($pdo);
} catch (Throwable $exception) {
    $flashError = 'Não foi possível carregar as definições da página. Verifique a configuração e tente novamente.';
    if (function_exists('taskforce_log_bootstrap_error')) {
        taskforce_log_bootstrap_error('[TaskForce][company_profile] ' . $exception->getMessage());
    }
}

$pageTitle = 'Empresa e Branding';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Empresa e Branding</h1>
<p class="text-muted">Configure os dados corporativos para reutilizar nos relatórios e e-mails.</p>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card shadow-sm soft-card">
    <input type="hidden" name="action" value="save_company_profile">
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Nome da empresa</label>
                <input class="form-control" name="company_name" value="<?= h($companyName) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="company_email" value="<?= h($companyEmail) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Telefone</label>
                <input class="form-control" name="company_phone" value="<?= h($companyPhone) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-6">
                <label class="form-label">Morada</label>
                <input class="form-control" name="company_address" value="<?= h($companyAddress) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Configuração de envio de email (SMTP)</label>
                <p class="small text-muted mb-2">Preencha estes campos para envio autenticado de alertas e relatórios quando o servidor não usa <code>mail()</code>.</p>
            </div>
            <div class="col-md-4">
                <label class="form-label">Servidor SMTP</label>
                <input class="form-control" name="smtp_host" placeholder="smtp.seudominio.com" value="<?= h($smtpHost) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Porta SMTP</label>
                <input class="form-control" type="number" min="1" max="65535" name="smtp_port" value="<?= h((string) $smtpPort) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Segurança</label>
                <select class="form-select" name="smtp_secure" <?= !$isAdmin ? 'disabled' : '' ?>>
                    <option value="" <?= $smtpSecure === '' ? 'selected' : '' ?>>Sem TLS</option>
                    <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Utilizador SMTP</label>
                <input class="form-control" name="smtp_username" value="<?= h($smtpUsername) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">Password SMTP</label>
                <input class="form-control" type="password" name="smtp_password" value="<?= h($smtpPassword) ?>" autocomplete="new-password" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-2">
                <label class="form-label">Timeout (s)</label>
                <input class="form-control" type="number" min="3" max="120" name="smtp_timeout_seconds" value="<?= h((string) $smtpTimeout) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Email remetente</label>
                <input class="form-control" type="email" name="mail_from_address" value="<?= h($mailFromAddress) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nome remetente</label>
                <input class="form-control" name="mail_from_name" value="<?= h($mailFromName) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Verificações alertas RH por dia</label>
                <input class="form-control" type="number" min="1" max="1440" name="hr_alerts_inline_cron_runs_per_day" value="<?= h((string) $hrAlertsCronRunsPerDay) ?>" <?= !$isAdmin ? 'readonly' : '' ?>>
                <p class="small text-muted mb-0 mt-1">Define quantas vezes por dia o sistema verifica se existem envios de alertas RH por executar (1 a 1440).</p>
            </div>

            <div class="col-md-6">
                <label class="form-label mb-0">Logo claro (navbar)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_navbar_light" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($navbarLogo): ?><img src="<?= h($navbarLogo) ?>" alt="Logo navbar" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label mb-0">Logo escuro (relatórios)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_report_dark" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($reportLogo): ?><img src="<?= h($reportLogo) ?>" alt="Logo relatório" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
            <div class="col-12">
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Estados dos tickets</label>
                    <?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="add-ticket-status">Adicionar estado</button><?php endif; ?>
                </div>
                <p class="small text-muted mb-2">Defina os estados disponíveis no ticketing, a ordem de apresentação e a cor hexadecimal do badge de alerta.</p>
                <div id="ticket-status-list" class="vstack gap-2">
                    <?php foreach ($ticketStatuses as $index => $status): ?>
                        <div class="row g-2 align-items-center ticket-status-row border rounded p-2">
                            <div class="col-md-2"><input class="form-control form-control-sm" name="ticket_status_value[]" value="<?= h($status['value']) ?>" placeholder="valor_tecnico" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_label[]" value="<?= h($status['label']) ?>" placeholder="Etiqueta" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-2"><input class="form-control form-control-sm ticket-status-sort-order" type="number" name="ticket_status_sort_order[]" value="<?= (int) ($status['sort_order'] ?? (($index + 1) * 10)) ?>" placeholder="Ordem" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-2">
                                <div class="d-flex align-items-center gap-2">
                                    <input class="form-control form-control-color form-control-sm ticket-status-color-picker" type="color" value="<?= h($status['color'] ?? (!empty($status['is_completed']) ? '#22C55E' : '#FACC15')) ?>" title="Cor do badge" <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <input class="form-control form-control-sm text-uppercase ticket-status-color-hex" name="ticket_status_color[]" value="<?= h($status['color'] ?? (!empty($status['is_completed']) ? '#22C55E' : '#FACC15')) ?>" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#RRGGBB" <?= !$isAdmin ? 'readonly' : '' ?>>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check">
                                    <input class="form-check-input ticket-status-completed" type="checkbox" name="ticket_status_completed[<?= (int) $index ?>]" value="1" <?= !empty($status['is_completed']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Concluído</label>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <?php if ($isAdmin): ?>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-up" title="Subir">↑</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-down" title="Descer">↓</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Departamentos pendentes no dashboard</label>
                <p class="small text-muted mb-2">Escolha os departamentos que devem aparecer automaticamente no bloco de pendentes por equipa técnica.</p>
                <div id="pending-department-list" class="vstack gap-2 mb-2">
                    <?php foreach ($pendingDepartmentCatalog as $index => $department): ?>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4"><input class="form-control form-control-sm" name="pending_department_value[]" value="<?= h($department['value']) ?>" readonly></div>
                            <div class="col-md-5"><input class="form-control form-control-sm" value="<?= h($department['label']) ?>" readonly></div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="pending_department_enabled[<?= (int) $index ?>]" value="1" <?= !empty($department['enabled']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Ativo</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <hr>
                <label class="form-label mb-0">Recorrências de tarefas</label>
                <p class="small text-muted mb-2">Personalize os nomes e ative/desative tipos de recorrência disponíveis ao criar tarefas recorrentes.</p>
                <div id="recurrence-list" class="vstack gap-2">
                    <?php foreach ($recurrenceCatalog as $index => $recurrence): ?>
                        <div class="row g-2 align-items-center recurrence-row">
                            <div class="col-md-3"><input class="form-control form-control-sm" name="recurrence_value[]" value="<?= h($recurrence['value']) ?>" readonly></div>
                            <div class="col-md-6"><input class="form-control form-control-sm" name="recurrence_label[]" value="<?= h($recurrence['label']) ?>" placeholder="Etiqueta" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recurrence_enabled[<?= (int) $index ?>]" value="1" <?= !empty($recurrence['enabled']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Ativo</label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <button class="btn btn-primary mt-3">Guardar dados da empresa</button>
        <?php endif; ?>
    </div>
</form>

<?php if ($isAdmin): ?>
<form method="post" class="card shadow-sm border-danger-subtle mt-3">
    <input type="hidden" name="action" value="reset_hr_operational_data">
    <div class="card-body">
        <h2 class="h5 text-danger">Zona de limpeza para nova empresa</h2>
        <p class="small text-muted mb-2">
            Esta ação elimina todos os registos operacionais de picagens e pedidos de ausências/férias para preparar uma implementação nova.
        </p>
        <ul class="small text-muted mb-3">
            <li>Picagens (Shopfloor)</li>
            <li>Pedidos e justificações de ausências</li>
            <li>Pedidos e eventos de férias</li>
            <li>Saldos e movimentos de banco de horas</li>
        </ul>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Confirmação</label>
                <input class="form-control" name="reset_confirmation" placeholder="Escreva RESET para confirmar" required>
            </div>
            <div class="col-md-8">
                <button class="btn btn-outline-danger" onclick="return confirm('Confirma a eliminação total de picagens e pedidos de ausências/férias? Esta ação é irreversível.');">
                    Eliminar dados de picagens e ausências/férias
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<?php if ($isAdmin): ?>
<script>
(function () {
    const list = document.getElementById('ticket-status-list');
    const addButton = document.getElementById('add-ticket-status');
    if (!list || !addButton) {
        return;
    }


    const normalizeRows = () => {
        list.querySelectorAll('.ticket-status-row').forEach((row, index) => {
            const sortOrderInput = row.querySelector('.ticket-status-sort-order');
            if (sortOrderInput) {
                sortOrderInput.value = String((index + 1) * 10);
            }

            const completedInput = row.querySelector('.ticket-status-completed');
            if (completedInput) {
                completedInput.name = `ticket_status_completed[${index}]`;
            }

            const upBtn = row.querySelector('.move-ticket-status-up');
            const downBtn = row.querySelector('.move-ticket-status-down');
            if (upBtn) {
                upBtn.disabled = index === 0;
            }
            if (downBtn) {
                downBtn.disabled = index === list.querySelectorAll('.ticket-status-row').length - 1;
            }
        });
    };

    const bindColorSync = (row) => {
        const picker = row.querySelector('.ticket-status-color-picker');
        const hex = row.querySelector('.ticket-status-color-hex');
        if (!picker || !hex) {
            return;
        }

        picker.addEventListener('input', () => {
            hex.value = picker.value.toUpperCase();
        });

        hex.addEventListener('input', () => {
            const normalized = hex.value.trim().toUpperCase();
            if (/^#[0-9A-F]{6}$/.test(normalized)) {
                picker.value = normalized;
            }
        });
    };

    const bindMove = (button, direction) => {
        button.addEventListener('click', () => {
            const row = button.closest('.ticket-status-row');
            if (!row) {
                return;
            }

            if (direction === 'up') {
                const previous = row.previousElementSibling;
                if (previous) {
                    list.insertBefore(row, previous);
                }
            } else {
                const next = row.nextElementSibling;
                if (next) {
                    list.insertBefore(next, row);
                }
            }

            normalizeRows();
        });
    };

    const bindRemove = (button) => {
        button.addEventListener('click', () => {
            const row = button.closest('.ticket-status-row');
            if (row) {
                row.remove();
                normalizeRows();
            }
        });
    };

    const bindRowControls = (row) => {
        bindColorSync(row);

        const remove = row.querySelector('.remove-ticket-status');
        if (remove) {
            bindRemove(remove);
        }

        const up = row.querySelector('.move-ticket-status-up');
        if (up) {
            bindMove(up, 'up');
        }

        const down = row.querySelector('.move-ticket-status-down');
        if (down) {
            bindMove(down, 'down');
        }
    };

    list.querySelectorAll('.ticket-status-row').forEach(bindRowControls);
    normalizeRows();

    addButton.addEventListener('click', () => {
        const index = list.querySelectorAll('.ticket-status-row').length;
        const wrapper = document.createElement('div');
        wrapper.className = 'row g-2 align-items-center ticket-status-row border rounded p-2';
        wrapper.innerHTML = `
            <div class="col-md-2"><input class="form-control form-control-sm" name="ticket_status_value[]" placeholder="valor_tecnico"></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_label[]" placeholder="Etiqueta"></div>
            <div class="col-md-2"><input class="form-control form-control-sm ticket-status-sort-order" type="number" name="ticket_status_sort_order[]" value="${(index + 1) * 10}" placeholder="Ordem"></div>
            <div class="col-md-2">
                <div class="d-flex align-items-center gap-2">
                    <input class="form-control form-control-color form-control-sm ticket-status-color-picker" type="color" value="#FACC15" title="Cor do badge">
                    <input class="form-control form-control-sm text-uppercase ticket-status-color-hex" name="ticket_status_color[]" value="#FACC15" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#RRGGBB">
                </div>
            </div>
            <div class="col-md-2"><div class="form-check"><input class="form-check-input ticket-status-completed" type="checkbox" name="ticket_status_completed[${index}]" value="1"><label class="form-check-label small">Concluído</label></div></div>
            <div class="col-md-1">
                <div class="d-flex gap-1 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-up" title="Subir">↑</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary move-ticket-status-down" title="Descer">↓</button>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button>
                </div>
            </div>
        `;
        list.appendChild(wrapper);
        bindRowControls(wrapper);
        normalizeRows();
    });

    const form = list.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            normalizeRows();
        });
    }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
