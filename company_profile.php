<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $flashError = 'Apenas administradores podem editar os dados da empresa.';
    } elseif (($_POST['action'] ?? '') === 'save_company_profile') {
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $companyAddress = trim((string) ($_POST['company_address'] ?? ''));
        $companyEmail = trim((string) ($_POST['company_email'] ?? ''));
        $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));

        $statusValues = $_POST['ticket_status_value'] ?? [];
        $statusLabels = $_POST['ticket_status_label'] ?? [];
        $statusCompleted = $_POST['ticket_status_completed'] ?? [];
        $ticketStatuses = [];

        $recurrenceValues = $_POST['recurrence_value'] ?? [];
        $recurrenceLabels = $_POST['recurrence_label'] ?? [];
        $recurrenceEnabled = $_POST['recurrence_enabled'] ?? [];
        $recurrenceCatalog = [];

        foreach ($statusValues as $index => $rawValue) {
            $value = strtolower(trim((string) $rawValue));
            $label = trim((string) ($statusLabels[$index] ?? ''));

            if ($value === '' && $label === '') {
                continue;
            }

            $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?: '';
            if ($value === '' || $label === '') {
                continue;
            }

            if (isset($ticketStatuses[$value])) {
                continue;
            }

            $ticketStatuses[$value] = [
                'value' => $value,
                'label' => $label,
                'is_completed' => isset($statusCompleted[$index]) && $statusCompleted[$index] === '1',
            ];
        }

        $defaultRecurrences = default_recurring_task_recurrence_options();
        $allowedRecurrences = [];
        foreach ($defaultRecurrences as $defaultRecurrence) {
            $allowedRecurrences[(string) $defaultRecurrence['value']] = (string) $defaultRecurrence['label'];
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

        if ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $flashError = 'Indique um email válido para a empresa.';
        } elseif (count($ticketStatuses) === 0) {
            $flashError = 'Defina pelo menos um estado para os tickets.';
        } elseif (!array_filter($ticketStatuses, static fn (array $status): bool => empty($status['is_completed']))) {
            $flashError = 'Defina pelo menos um estado não concluído para os tickets.';
        } elseif (!array_filter($recurrenceCatalog, static fn (array $entry): bool => !empty($entry['enabled']))) {
            $flashError = 'Ative pelo menos um tipo de recorrência para tarefas recorrentes.';
        } else {
            set_app_setting($pdo, 'company_name', $companyName);
            set_app_setting($pdo, 'company_address', $companyAddress);
            set_app_setting($pdo, 'company_email', $companyEmail);
            set_app_setting($pdo, 'company_phone', $companyPhone);
            set_app_setting($pdo, 'ticket_statuses_json', json_encode(array_values($ticketStatuses), JSON_UNESCAPED_UNICODE));
            set_app_setting($pdo, 'recurring_task_recurrences_json', json_encode(array_values($recurrenceCatalog), JSON_UNESCAPED_UNICODE));

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
    }
}

$companyName = app_setting($pdo, 'company_name', '');
$companyAddress = app_setting($pdo, 'company_address', '');
$companyEmail = app_setting($pdo, 'company_email', '');
$companyPhone = app_setting($pdo, 'company_phone', '');
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$reportLogo = app_setting($pdo, 'logo_report_dark');
$ticketStatuses = ticket_statuses($pdo);
$recurrenceCatalog = recurring_task_recurrence_catalog($pdo);

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

            <div class="col-md-6">
                <label class="form-label mb-0">Logo claro (navbar)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="logo_navbar_light" accept="image/png,image/jpeg,image/svg+xml,image/webp" <?= !$isAdmin ? 'disabled' : '' ?>>
                <?php if ($navbarLogo): ?><img src="<?= h($navbarLogo) ?>" alt="Logo navbar" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
            </div>
            <div class="col-12">
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">Estados dos tickets</label>
                    <?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="add-ticket-status">Adicionar estado</button><?php endif; ?>
                </div>
                <p class="small text-muted mb-2">Defina os estados disponíveis no ticketing e assinale quais contam como concluídos.</p>
                <div id="ticket-status-list" class="vstack gap-2">
                    <?php foreach ($ticketStatuses as $index => $status): ?>
                        <div class="row g-2 align-items-center ticket-status-row">
                            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_value[]" value="<?= h($status['value']) ?>" placeholder="valor_tecnico" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-5"><input class="form-control form-control-sm" name="ticket_status_label[]" value="<?= h($status['label']) ?>" placeholder="Etiqueta" <?= !$isAdmin ? 'readonly' : '' ?>></div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ticket_status_completed[<?= (int) $index ?>]" value="1" <?= !empty($status['is_completed']) ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <label class="form-check-label small">Concluído</label>
                                </div>
                            </div>
                            <div class="col-md-1 text-end">
                                <?php if ($isAdmin): ?><button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button><?php endif; ?>
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
<script>
(function () {
    const list = document.getElementById('ticket-status-list');
    const addButton = document.getElementById('add-ticket-status');
    if (!list || !addButton) {
        return;
    }

    const bindRemove = (button) => {
        button.addEventListener('click', () => {
            const row = button.closest('.ticket-status-row');
            if (row) {
                row.remove();
            }
        });
    };

    list.querySelectorAll('.remove-ticket-status').forEach(bindRemove);

    addButton.addEventListener('click', () => {
        const index = list.querySelectorAll('.ticket-status-row').length;
        const wrapper = document.createElement('div');
        wrapper.className = 'row g-2 align-items-center ticket-status-row';
        wrapper.innerHTML = `
            <div class="col-md-3"><input class="form-control form-control-sm" name="ticket_status_value[]" placeholder="valor_tecnico"></div>
            <div class="col-md-5"><input class="form-control form-control-sm" name="ticket_status_label[]" placeholder="Etiqueta"></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="ticket_status_completed[${index}]" value="1"><label class="form-check-label small">Concluído</label></div></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-ticket-status">×</button></div>
        `;
        list.appendChild(wrapper);
        const remove = wrapper.querySelector('.remove-ticket-status');
        if (remove) {
            bindRemove(remove);
        }
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
