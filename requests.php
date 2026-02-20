<?php
require_once __DIR__ . '/helpers.php';
require_login();


if (!function_exists('is_excel_file_path')) {
    function is_excel_file_path(string $filePath): bool
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv'], true);
    }
}

if (!function_exists('absolute_url_for_path')) {
    function absolute_url_for_path(string $relativePath): ?string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return null;
        }

        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $cleanPath = ltrim($relativePath, '/');

        if ($basePath === '' || $basePath === '.') {
            return $scheme . '://' . $host . '/' . $cleanPath;
        }

        return $scheme . '://' . $host . $basePath . '/' . $cleanPath;
    }
}

if (!function_exists('google_docs_excel_preview_url')) {
    function google_docs_excel_preview_url(string $relativePath): ?string
    {
        if (!is_excel_file_path($relativePath)) {
            return null;
        }

        $absoluteUrl = absolute_url_for_path($relativePath);
        if ($absoluteUrl === null) {
            return null;
        }

        return 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($absoluteUrl);
    }
}

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$showCompleted = (int) ($_GET['show_completed'] ?? 0) === 1;
$teamRolesStmt = $pdo->prepare('SELECT team_id, role FROM team_members WHERE user_id = ?');
$teamRolesStmt->execute([$userId]);
$teamRoles = $teamRolesStmt->fetchAll(PDO::FETCH_ASSOC);
$leaderTeamIds = [];
$memberTeamIds = [];
foreach ($teamRoles as $teamRole) {
    $teamId = (int) $teamRole['team_id'];
    $memberTeamIds[] = $teamId;
    if (in_array((string) $teamRole['role'], ['leader', 'owner'], true)) {
        $leaderTeamIds[] = $teamId;
    }
}
$canManageTeamTickets = $isAdmin || !empty($leaderTeamIds);
$ticketStatuses = ticket_statuses($pdo);

$ticketTypeTemplates = [
    'compras' => [
        'label' => 'Compras',
        'fields' => [
            ['name' => 'item_name', 'label' => 'Item a comprar', 'type' => 'text', 'required' => true],
            ['name' => 'quantity', 'label' => 'Quantidade', 'type' => 'number', 'required' => true],
            ['name' => 'needed_by', 'label' => 'Necessário até', 'type' => 'date', 'required' => false],
            ['name' => 'supplier', 'label' => 'Fornecedor preferencial', 'type' => 'text', 'required' => false],
            ['name' => 'attachment', 'label' => 'Anexo do pedido', 'type' => 'file', 'required' => false],
        ],
    ],
    'manutencao' => [
        'label' => 'Manutenção',
        'fields' => [
            ['name' => 'equipment', 'label' => 'Equipamento', 'type' => 'text', 'required' => true],
            ['name' => 'failure', 'label' => 'Avaria reportada', 'type' => 'textarea', 'required' => true],
            ['name' => 'stopped', 'label' => 'Máquina parada', 'type' => 'select', 'required' => true, 'options' => ['Sim', 'Não']],
            ['name' => 'location', 'label' => 'Localização', 'type' => 'text', 'required' => false],
            ['name' => 'attachment', 'label' => 'Anexo da avaria', 'type' => 'file', 'required' => false],
        ],
    ],
    'desenho_tecnico' => [
        'label' => 'Desenho técnico',
        'fields' => [
            ['name' => 'project_ref', 'label' => 'Referência do projeto', 'type' => 'text', 'required' => true],
            ['name' => 'drawing_type', 'label' => 'Tipo de desenho', 'type' => 'select', 'required' => true, 'options' => ['2D', '3D', 'Ambos']],
            ['name' => 'materials', 'label' => 'Materiais', 'type' => 'text', 'required' => false],
            ['name' => 'deadline', 'label' => 'Prazo pretendido', 'type' => 'date', 'required' => false],
            ['name' => 'attachment', 'label' => 'Anexo técnico', 'type' => 'file', 'required' => false],
        ],
    ],
];

$teamFormPresets = [
    'compras' => [
        'title' => 'Pedido de Compras',
        'description' => 'Formulário base para pedidos de compras.',
        'department' => 'Compras',
        'fields' => [
            ['label' => 'Item a comprar', 'type' => 'text', 'required' => true, 'options' => []],
            ['label' => 'Quantidade', 'type' => 'number', 'required' => true, 'options' => []],
            ['label' => 'Necessário até', 'type' => 'date', 'required' => false, 'options' => []],
            ['label' => 'Fornecedor preferencial', 'type' => 'text', 'required' => false, 'options' => []],
            ['label' => 'Anexo do pedido', 'type' => 'file', 'required' => false, 'options' => []],
        ],
    ],
    'manutencao' => [
        'title' => 'Pedido de Manutenção',
        'description' => 'Formulário base para avarias e manutenção.',
        'department' => 'Manutenção',
        'fields' => [
            ['label' => 'Equipamento', 'type' => 'text', 'required' => true, 'options' => []],
            ['label' => 'Avaria reportada', 'type' => 'textarea', 'required' => true, 'options' => []],
            ['label' => 'Máquina parada', 'type' => 'select', 'required' => true, 'options' => ['Sim', 'Não']],
            ['label' => 'Localização', 'type' => 'text', 'required' => false, 'options' => []],
            ['label' => 'Anexo da avaria', 'type' => 'file', 'required' => false, 'options' => []],
        ],
    ],
    'desenho_tecnico' => [
        'title' => 'Pedido de Desenho Técnico',
        'description' => 'Formulário base para solicitações de desenho técnico.',
        'department' => 'Desenho técnico',
        'fields' => [
            ['label' => 'Referência do projeto', 'type' => 'text', 'required' => true, 'options' => []],
            ['label' => 'Tipo de desenho', 'type' => 'select', 'required' => true, 'options' => ['2D', '3D', 'Ambos']],
            ['label' => 'Materiais', 'type' => 'text', 'required' => false, 'options' => []],
            ['label' => 'Prazo pretendido', 'type' => 'date', 'required' => false, 'options' => []],
            ['label' => 'Anexo técnico', 'type' => 'file', 'required' => false, 'options' => []],
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';


    if ($action === 'create_team_ticket') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $urgency = trim($_POST['urgency'] ?? 'Média');
        $dueDate = trim($_POST['due_date'] ?? '');

        if ($teamId <= 0 || $title === '' || $description === '') {
            $_SESSION['flash_error'] = 'Preencha equipa, assunto e detalhe do ticket.';
        } elseif (!in_array($teamId, $memberTeamIds, true) && !$isAdmin) {
            $_SESSION['flash_error'] = 'Sem permissão para abrir ticket para essa equipa.';
        } else {
            $ticketType = (string) ($_POST['ticket_type'] ?? '');
            if (array_key_exists($ticketType, $ticketTypeTemplates)) {
                $template = $ticketTypeTemplates[$ticketType];
                $extraLines = [];
                foreach ($template['fields'] as $field) {
                    $fieldName = 'ticket_field_' . $field['name'];
                    $fieldType = (string) ($field['type'] ?? 'text');

                    if ($fieldType === 'file') {
                        $file = $_FILES[$fieldName] ?? null;
                        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                        if (!empty($field['required']) && !$hasFile) {
                            $_SESSION['flash_error'] = 'Preencha os campos obrigatórios para ' . $template['label'] . '.';
                            redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
                        }

                        if ($hasFile) {
                            $uploadDir = __DIR__ . '/uploads/team_ticket_attachments';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0775, true);
                            }
                            $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
                            $safeName = uniqid('ticket_attach_', true) . ($ext ? '.' . strtolower($ext) : '');
                            $targetPath = $uploadDir . '/' . $safeName;
                            if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                                $extraLines[] = ($field['label'] ?? $fieldName) . ': uploads/team_ticket_attachments/' . $safeName;
                            } elseif (!empty($field['required'])) {
                                $_SESSION['flash_error'] = 'Não foi possível carregar o ficheiro obrigatório de ' . $template['label'] . '.';
                                redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
                            }
                        }

                        continue;
                    }

                    $value = trim((string) ($_POST[$fieldName] ?? ''));
                    if (!empty($field['required']) && $value === '') {
                        $_SESSION['flash_error'] = 'Preencha os campos obrigatórios para ' . $template['label'] . '.';
                        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
                    }
                    if ($value !== '') {
                        $extraLines[] = ($field['label'] ?? $fieldName) . ': ' . $value;
                    }
                }

                if (count($extraLines) > 0) {
                    $description .= "\n\nDetalhes de " . $template['label'] . ":\n- " . implode("\n- ", $extraLines);
                }
            }

            $ticketCode = generate_ticket_code($pdo);
            $defaultStatus = default_open_ticket_status($pdo);
            $stmt = $pdo->prepare('INSERT INTO team_tickets(ticket_code, team_id, title, description, urgency, due_date, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$ticketCode, $teamId, $title, $description, $urgency ?: 'Média', $dueDate !== '' ? $dueDate : null, $userId, $defaultStatus]);
            $_SESSION['flash_success'] = 'Ticket submetido com sucesso.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'update_team_ticket' && $canManageTeamTickets) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'open');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);

        if (ticket_status_value_exists($pdo, $status) && $ticketId > 0) {
            $teamCheckSql = 'SELECT team_id FROM team_tickets WHERE id = ?';
            $teamCheckStmt = $pdo->prepare($teamCheckSql);
            $teamCheckStmt->execute([$ticketId]);
            $ticketTeamId = (int) ($teamCheckStmt->fetchColumn() ?: 0);

            if ($ticketTeamId > 0 && ($isAdmin || in_array($ticketTeamId, $leaderTeamIds, true))) {
                $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare('UPDATE team_tickets SET status = ?, assignee_user_id = ?, completed_at = ? WHERE id = ?');
                $stmt->execute([$status, $assigneeUserId > 0 ? $assigneeUserId : null, $completedAt, $ticketId]);
                $_SESSION['flash_success'] = 'Ticket atualizado.';
            } else {
                $_SESSION['flash_error'] = 'Sem permissão para atualizar este ticket.';
            }
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'create_team_form' && $isAdmin) {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? 'tornearia');

        $fields = [];
        $labels = $_POST['field_label'] ?? [];
        $types = $_POST['field_type'] ?? [];
        $requiredFlags = $_POST['field_required'] ?? [];
        $optionsList = $_POST['field_options'] ?? [];

        foreach ($labels as $idx => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $type = (string) ($types[$idx] ?? 'text');
            if (!in_array($type, ['text', 'number', 'date', 'textarea', 'select', 'file'], true)) {
                $type = 'text';
            }

            $required = isset($requiredFlags[$idx]) && $requiredFlags[$idx] === '1';
            $options = [];
            if ($type === 'select') {
                $raw = trim((string) ($optionsList[$idx] ?? ''));
                if ($raw !== '') {
                    $options = array_values(array_filter(array_map('trim', explode(',', $raw))));
                }
            }

            $fields[] = [
                'label' => $label,
                'name' => 'field_' . ($idx + 1),
                'type' => $type,
                'required' => $required,
                'options' => $options,
            ];
        }

        if ($teamId > 0 && $title !== '' && count($fields) > 0) {
            $stmt = $pdo->prepare('INSERT INTO team_forms(team_id, title, description, department, fields_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$teamId, $title, $description, $department, json_encode($fields, JSON_UNESCAPED_UNICODE), $userId]);
            $_SESSION['flash_success'] = 'Formulário global criado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Preencha equipa, título e pelo menos um campo do formulário.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'update_team_form' && $isAdmin) {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? '');

        $fields = [];
        $labels = $_POST['field_label'] ?? [];
        $types = $_POST['field_type'] ?? [];
        $requiredFlags = $_POST['field_required'] ?? [];
        $optionsList = $_POST['field_options'] ?? [];

        foreach ($labels as $idx => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $type = (string) ($types[$idx] ?? 'text');
            if (!in_array($type, ['text', 'number', 'date', 'textarea', 'select', 'file'], true)) {
                $type = 'text';
            }

            $required = isset($requiredFlags[$idx]) && $requiredFlags[$idx] === '1';
            $options = [];
            if ($type === 'select') {
                $raw = trim((string) ($optionsList[$idx] ?? ''));
                if ($raw !== '') {
                    $options = array_values(array_filter(array_map('trim', explode(',', $raw))));
                }
            }

            $fields[] = [
                'label' => $label,
                'name' => 'field_' . ($idx + 1),
                'type' => $type,
                'required' => $required,
                'options' => $options,
            ];
        }

        if ($formId > 0 && $teamId > 0 && $title !== '' && count($fields) > 0) {
            $stmt = $pdo->prepare('UPDATE team_forms SET team_id = ?, title = ?, description = ?, department = ?, fields_json = ? WHERE id = ?');
            $stmt->execute([$teamId, $title, $description, $department, json_encode($fields, JSON_UNESCAPED_UNICODE), $formId]);
            $_SESSION['flash_success'] = 'Formulário atualizado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Para editar: preencha equipa, título e pelo menos um campo.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'delete_team_form' && $isAdmin) {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM team_forms WHERE id = ?');
        $stmt->execute([$formId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Formulário eliminado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Não foi possível eliminar o formulário selecionado.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'submit_team_form') {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $formStmt = $pdo->prepare('SELECT id, fields_json FROM team_forms WHERE id = ? AND is_active = 1');
        $formStmt->execute([$formId]);
        $form = $formStmt->fetch(PDO::FETCH_ASSOC);

        if ($form) {
            $fields = json_decode((string) $form['fields_json'], true) ?: [];
            $payload = [];
            $valid = true;

            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                $required = !empty($field['required']);

                if ($type === 'file') {
                    $file = $_FILES[$name] ?? null;
                    $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                    if ($required && !$hasFile) {
                        $valid = false;
                        break;
                    }

                    $storedFile = null;
                    if ($hasFile) {
                        $uploadDir = __DIR__ . '/uploads/tickets';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }

                        $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
                        $safeName = uniqid('ticket_', true) . ($ext ? '.' . strtolower($ext) : '');
                        $targetPath = $uploadDir . '/' . $safeName;
                        if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                            $storedFile = [
                                'name' => (string) $file['name'],
                                'path' => 'uploads/tickets/' . $safeName,
                                'preview_url' => google_docs_excel_preview_url('uploads/tickets/' . $safeName),
                            ];
                        } elseif ($required) {
                            $valid = false;
                            break;
                        }
                    }

                    $payload[] = [
                        'label' => $field['label'] ?? $name,
                        'name' => $name,
                        'type' => $type,
                        'value' => $storedFile,
                    ];
                    continue;
                }

                $value = trim((string) ($_POST[$name] ?? ''));
                if ($required && $value === '') {
                    $valid = false;
                    break;
                }

                $payload[] = [
                    'label' => $field['label'] ?? $name,
                    'name' => $name,
                    'type' => $type,
                    'value' => $value,
                ];
            }

            if ($valid) {
                $assignee = $assigneeUserId > 0 ? $assigneeUserId : null;
                $defaultStatus = default_open_ticket_status($pdo);
                $insert = $pdo->prepare('INSERT INTO team_form_entries(form_id, payload_json, created_by, assignee_user_id, status) VALUES (?, ?, ?, ?, ?)');
                $insert->execute([$formId, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId, $assignee, $defaultStatus]);
                $_SESSION['flash_success'] = 'Ticket submetido com sucesso.';
            } else {
                $_SESSION['flash_error'] = 'Existem campos obrigatórios por preencher.';
            }
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'update_ticket' && $isAdmin) {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'open');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        if (ticket_status_value_exists($pdo, $status) && $entryId > 0) {
            $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare('UPDATE team_form_entries SET status = ?, assignee_user_id = ?, completed_at = ? WHERE id = ?');
            $stmt->execute([$status, $assigneeUserId > 0 ? $assigneeUserId : null, $completedAt, $entryId]);
            $_SESSION['flash_success'] = 'Ticket atualizado.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name, email FROM users ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$forms = $pdo->query('SELECT tf.*, t.name AS team_name, u.name AS creator_name FROM team_forms tf INNER JOIN teams t ON t.id = tf.team_id INNER JOIN users u ON u.id = tf.created_by WHERE tf.is_active = 1 ORDER BY tf.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$ticketSql = 'SELECT tt.*, t.name AS team_name, u.name AS creator_name, a.name AS assignee_name
FROM team_tickets tt
INNER JOIN teams t ON t.id = tt.team_id
INNER JOIN users u ON u.id = tt.created_by
LEFT JOIN users a ON a.id = tt.assignee_user_id';

$completedStatusValues = [];
$activeStatusValues = [];
foreach ($ticketStatuses as $statusConfig) {
    if (!empty($statusConfig['is_completed'])) {
        $completedStatusValues[] = (string) $statusConfig['value'];
    } else {
        $activeStatusValues[] = (string) $statusConfig['value'];
    }
}

$quotedCompletedStatuses = array_map(static fn (string $value): string => $pdo->quote($value), $completedStatusValues);
$quotedActiveStatuses = array_map(static fn (string $value): string => $pdo->quote($value), $activeStatusValues);
$completedStatusSql = count($quotedCompletedStatuses) > 0 ? implode(',', $quotedCompletedStatuses) : "''";
$activeStatusSql = count($quotedActiveStatuses) > 0 ? implode(',', $quotedActiveStatuses) : "''";

$openTicketConditions = ['tt.status IN (' . $activeStatusSql . ')'];
if (!$isAdmin) {
    if (!empty($leaderTeamIds)) {
        $openTicketConditions[] = 'tt.team_id IN (' . implode(',', array_map('intval', $leaderTeamIds)) . ')';
    } else {
        $openTicketConditions[] = 'tt.created_by = ' . $userId;
    }
}
$openTickets = $pdo->query($ticketSql . ' WHERE ' . implode(' AND ', $openTicketConditions) . ' ORDER BY tt.created_at DESC LIMIT 120')->fetchAll(PDO::FETCH_ASSOC);

$completedTicketConditions = ['tt.status IN (' . $completedStatusSql . ')'];
if (!$isAdmin) {
    if (!empty($leaderTeamIds)) {
        $completedTicketConditions[] = 'tt.team_id IN (' . implode(',', array_map('intval', $leaderTeamIds)) . ')';
    } else {
        $completedTicketConditions[] = 'tt.created_by = ' . $userId;
    }
}
$completedTickets = $pdo->query($ticketSql . ' WHERE ' . implode(' AND ', $completedTicketConditions) . ' ORDER BY COALESCE(tt.completed_at, tt.created_at) DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);

$entriesSql = 'SELECT e.*, f.title AS form_title, t.name AS team_name, u.name AS creator_name, a.name AS assignee_name
FROM team_form_entries e
INNER JOIN team_forms f ON f.id = e.form_id
INNER JOIN teams t ON t.id = f.team_id
INNER JOIN users u ON u.id = e.created_by
LEFT JOIN users a ON a.id = e.assignee_user_id';
$entriesWhere = $showCompleted ? '' : ' WHERE e.status IN (' . $activeStatusSql . ')';
$entries = $pdo->query($entriesSql . $entriesWhere . ' ORDER BY e.created_at DESC LIMIT 80')->fetchAll(PDO::FETCH_ASSOC);
$completedEntries = $pdo->query($entriesSql . ' WHERE e.status IN (' . $completedStatusSql . ') ORDER BY COALESCE(e.completed_at, e.created_at) DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pedidos às equipas';
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card mb-4 p-4">
    <h1 class="h3 mb-1">Sistema de ticketing</h1>
    <p class="mb-0 text-white-50">Submete pedidos simples, atribui a qualquer utilizador e acompanha histórico.</p>
</section>

<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
    <h2 class="h4 mb-0">Pedidos e tickets</h2>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="#forms-list" class="btn btn-outline-secondary btn-sm">Ir para formulários</a>
        <form method="get" class="form-check form-switch mb-0">
            <input type="hidden" name="show_completed" value="0">
            <input class="form-check-input" type="checkbox" id="showCompleted" name="show_completed" value="1" <?= $showCompleted ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label small" for="showCompleted">Mostrar concluídos na vista principal</label>
        </form>
        <?php if ($isAdmin): ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTeamFormModal">Gerar formulário (admin)</button><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Formulários de pedidos de tarefas (separado de tickets)</h3></div>
            <div class="list-group list-group-flush" id="forms-list">
                <?php foreach ($forms as $form): ?>
                    <?php $formFields = json_decode((string) $form['fields_json'], true) ?: []; ?>
                    <div class="list-group-item" id="form-<?= (int) $form['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h4 class="h6 mb-1"><?= h($form['title']) ?></h4>
                                <p class="small text-muted mb-2"><?= h($form['description']) ?: 'Sem descrição' ?></p>
                                <small class="text-muted d-block">Equipa: <?= h($form['team_name']) ?> · Departamento: <?= h($form['department']) ?></small>
                                <small class="text-muted d-block">Criado por <?= h($form['creator_name']) ?></small>
                            </div>
                            <div class="d-flex gap-2 align-items-start">
                            <?php if ($isAdmin): ?>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editFormModal<?= (int) $form['id'] ?>">Editar</button>
                                    <form method="post" onsubmit="return confirm('Eliminar este formulário e todos os tickets associados?');">
                                        <input type="hidden" name="action" value="delete_team_form">
                                        <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                                <button
                                    class="btn btn-sm btn-outline-primary"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#team-form-collapse-<?= (int) $form['id'] ?>"
                                    aria-expanded="false"
                                    aria-controls="team-form-collapse-<?= (int) $form['id'] ?>"
                                >
                                    Abrir formulário
                                </button>
                            </div>
                        </div>

                        <div class="collapse mt-3" id="team-form-collapse-<?= (int) $form['id'] ?>">
                        <div class="border rounded p-3 bg-light-subtle">
                            <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                                <input type="hidden" name="action" value="submit_team_form">
                                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">

                                <?php foreach ($formFields as $field): ?>
                                    <?php
                                    $fieldName = (string) ($field['name'] ?? '');
                                    $required = !empty($field['required']);
                                    $fieldType = (string) ($field['type'] ?? 'text');
                                    ?>
                                    <label class="form-label small mb-1"><?= h($field['label']) ?><?= $required ? ' *' : '' ?></label>
                                    <?php if ($fieldType === 'textarea'): ?>
                                        <textarea class="form-control form-control-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>></textarea>
                                    <?php elseif ($fieldType === 'select'): ?>
                                        <select class="form-select form-select-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                            <option value="">Selecionar...</option>
                                            <?php foreach (($field['options'] ?? []) as $option): ?>
                                                <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fieldType === 'file'): ?>
                                        <input class="form-control form-control-sm" type="file" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                    <?php else: ?>
                                        <input class="form-control form-control-sm" type="<?= h($fieldType) ?>" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <label class="form-label small mb-1">Atribuir ticket a</label>
                                <select class="form-select form-select-sm" name="assignee_user_id">
                                    <option value="0">Sem atribuição</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id'] ?>"><?= h($user['name']) ?> (<?= h($user['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>

                                <button class="btn btn-primary btn-sm mt-2">Submeter ticket</button>
                            </form>
                        </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$forms): ?><div class="list-group-item text-muted">Não existem formulários ativos. O admin pode gerar formulários globais.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Tickets abertos por equipa</h3></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Ticket</th><th>Atribuído</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php foreach ($openTickets as $entry): ?>
                            <tr>
                                <td>
                                    <?= h($entry['title']) ?><br>
                                    <small class="text-muted"><?= h(date('d/m/Y H:i', strtotime($entry['created_at']))) ?> · <?= h($entry['team_name']) ?> · Urgência: <?= h($entry['urgency']) ?></small>
                                    <?php if (preg_match('/uploads\/team_ticket_attachments\/[A-Za-z0-9_.\-]+/', (string) $entry['description'], $matches)): ?>
                                        <?php $attachPath = $matches[0]; ?>
                                        <div class="small mt-1"><a href="<?= h($attachPath) ?>" target="_blank" rel="noopener">Abrir anexo</a></div>
                                        <?php $previewUrl = google_docs_excel_preview_url($attachPath); ?>
                                        <?php if ($previewUrl): ?><div class="small"><a href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Pré-visualizar Excel (Google)</a></div><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h($entry['assignee_name'] ?: 'Sem atribuição') ?><br>
                                    <small class="text-muted">por <?= h($entry['creator_name']) ?></small>
                                </td>
                                <td>
                                    <?php if ($isAdmin || in_array((int) $entry['team_id'], $leaderTeamIds, true)): ?>
                                        <form method="post" class="vstack gap-1">
                                            <input type="hidden" name="action" value="update_team_ticket">
                                            <input type="hidden" name="ticket_id" value="<?= (int) $entry['id'] ?>">
                                            <select name="assignee_user_id" class="form-select form-select-sm">
                                                <option value="0">Sem atribuição</option>
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?= (int) $user['id'] ?>" <?= (int) $entry['assignee_user_id'] === (int) $user['id'] ? 'selected' : '' ?>><?= h($user['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select name="status" class="form-select form-select-sm">
                                                <?php foreach ($ticketStatuses as $statusOption): ?>
                                                    <option value="<?= h($statusOption['value']) ?>" <?= $entry['status'] === $statusOption['value'] ? 'selected' : '' ?>><?= h($statusOption['label']) ?></option>
                                                <?php endforeach; ?>
                                                <?php if (!ticket_status_value_exists($pdo, (string) $entry['status'])): ?>
                                                    <option value="<?= h($entry['status']) ?>" selected><?= h(ticket_status_label($pdo, (string) $entry['status'])) ?> (legado)</option>
                                                <?php endif; ?>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary">Guardar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge" style="<?= h(ticket_status_badge_style($pdo, (string) $entry['status'])) ?>"><?= h(ticket_status_label($pdo, (string) $entry['status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$openTickets): ?><tr><td colspan="3" class="text-muted">Sem tickets ativos para apresentar.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card soft-card shadow-sm mt-4">
    <div class="card-header bg-white"><h3 class="h5 mb-0">Histórico de tickets concluídos</h3></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Ticket</th><th>Atribuído</th><th>Concluído em</th></tr></thead>
            <tbody>
                <?php foreach ($completedTickets as $entry): ?>
                    <tr>
                        <td><?= h($entry['title']) ?><br><small class="text-muted"><?= h($entry['team_name']) ?> · criado por <?= h($entry['creator_name']) ?></small>
                            <?php if (preg_match('/uploads\/team_ticket_attachments\/[A-Za-z0-9_.\-]+/', (string) $entry['description'], $matches)): ?>
                                <?php $attachPath = $matches[0]; ?>
                                <div class="small mt-1"><a href="<?= h($attachPath) ?>" target="_blank" rel="noopener">Abrir anexo</a></div>
                                <?php $previewUrl = google_docs_excel_preview_url($attachPath); ?>
                                <?php if ($previewUrl): ?><div class="small"><a href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Pré-visualizar Excel (Google)</a></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= h($entry['assignee_name'] ?: 'Sem atribuição') ?></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($entry['completed_at'] ?: $entry['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$completedTickets): ?><tr><td colspan="3" class="text-muted">Sem histórico de tickets concluídos.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="createTeamFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" id="createTeamFormForm">
            <input type="hidden" name="action" value="create_team_form">
            <div class="modal-header"><h5 class="modal-title">Gerar formulário global (Admin)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="title" placeholder="Título" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
                <select class="form-select" name="team_id" required>
                    <option value="">Selecionar equipa destino</option>
                    <?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>"><?= h($team['name']) ?></option><?php endforeach; ?>
                </select>
                <input class="form-control" name="department" placeholder="Departamento (ex: Tornearia)">
                <div>
                    <label class="form-label small">Base rápida</label>
                    <select class="form-select" id="createFormPreset">
                        <option value="">Sem base</option>
                        <?php foreach ($teamFormPresets as $presetKey => $preset): ?><option value="<?= h($presetKey) ?>"><?= h($preset['title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="border rounded p-3">
                    <h6>Campos personalizados</h6>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4"><input class="form-control form-control-sm" name="field_label[]" placeholder="Label do campo"></div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" name="field_type[]">
                                    <option value="text">Texto</option>
                                    <option value="number">Número</option>
                                    <option value="date">Data</option>
                                    <option value="textarea">Área de texto</option>
                                    <option value="select">Escolha</option>
                                    <option value="file">Ficheiros</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select form-select-sm" name="field_required[]">
                                    <option value="0" selected>Opcional</option>
                                    <option value="1">Obrigatório</option>
                                </select>
                            </div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="field_options[]" placeholder="Opções (a,b,c)"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar formulário</button></div>
        </form>
    </div>
</div>

<?php foreach ($forms as $form): ?>
    <?php $formFields = json_decode((string) $form['fields_json'], true) ?: []; ?>
    <div class="modal fade" id="editFormModal<?= (int) $form['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post">
                <input type="hidden" name="action" value="update_team_form">
                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Editar formulário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body vstack gap-3">
                    <input class="form-control" name="title" value="<?= h($form['title']) ?>" required>
                    <textarea class="form-control" name="description" placeholder="Descrição"><?= h($form['description']) ?></textarea>
                    <select class="form-select" name="team_id" required>
                        <option value="">Selecionar equipa destino</option>
                        <?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === (int) $form['team_id'] ? 'selected' : '' ?>><?= h($team['name']) ?></option><?php endforeach; ?>
                    </select>
                    <input class="form-control" name="department" value="<?= h($form['department']) ?>" placeholder="Departamento (ex: Tornearia)">

                    <div class="border rounded p-3">
                        <h6>Campos personalizados</h6>
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <?php $field = $formFields[$i] ?? []; ?>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><input class="form-control form-control-sm" name="field_label[]" value="<?= h($field['label'] ?? '') ?>" placeholder="Label do campo"></div>
                                <div class="col-md-3">
                                    <?php $type = $field['type'] ?? 'text'; ?>
                                    <select class="form-select form-select-sm" name="field_type[]">
                                        <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>Texto</option>
                                        <option value="number" <?= $type === 'number' ? 'selected' : '' ?>>Número</option>
                                        <option value="date" <?= $type === 'date' ? 'selected' : '' ?>>Data</option>
                                        <option value="textarea" <?= $type === 'textarea' ? 'selected' : '' ?>>Área de texto</option>
                                        <option value="select" <?= $type === 'select' ? 'selected' : '' ?>>Escolha</option>
                                        <option value="file" <?= $type === 'file' ? 'selected' : '' ?>>Ficheiros</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm" name="field_required[]">
                                        <option value="0" <?= empty($field['required']) ? 'selected' : '' ?>>Opcional</option>
                                        <option value="1" <?= !empty($field['required']) ? 'selected' : '' ?>>Obrigatório</option>
                                    </select>
                                </div>
                                <div class="col-md-3"><input class="form-control form-control-sm" name="field_options[]" value="<?= h(implode(',', $field['options'] ?? [])) ?>" placeholder="Opções (a,b,c)"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary">Guardar alterações</button></div>
            </form>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<script>
const requestsTicketTypeTemplates = <?= json_encode($ticketTypeTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const teamFormPresets = <?= json_encode($teamFormPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const requestsTicketTypeSelect = document.getElementById('requestsTicketType');
const requestsTicketTypeFields = document.getElementById('requestsTicketTypeFields');

function renderRequestsTicketTypeFields() {
    if (!requestsTicketTypeSelect || !requestsTicketTypeFields) {
        return;
    }

    requestsTicketTypeFields.innerHTML = '';
    const selectedKey = requestsTicketTypeSelect.value;
    const template = requestsTicketTypeTemplates[selectedKey];
    if (!template || !Array.isArray(template.fields)) {
        return;
    }

    const row = document.createElement('div');
    row.className = 'row g-2';

    template.fields.forEach((field) => {
        const column = document.createElement('div');
        column.className = field.type === 'textarea' ? 'col-md-12' : 'col-md-3';

        const label = document.createElement('label');
        label.className = 'form-label small';
        label.textContent = field.label || 'Campo';
        column.appendChild(label);

        const fieldName = `ticket_field_${field.name}`;
        if (field.type === 'textarea') {
            const textarea = document.createElement('textarea');
            textarea.className = 'form-control';
            textarea.name = fieldName;
            textarea.rows = 2;
            textarea.required = !!field.required;
            column.appendChild(textarea);
        } else if (field.type === 'file') {
            const input = document.createElement('input');
            input.className = 'form-control';
            input.type = 'file';
            input.name = fieldName;
            input.accept = '.xls,.xlsx,.xlsm,.xlsb,.ods,.csv';
            input.required = !!field.required;
            column.appendChild(input);
        } else if (field.type === 'select') {
            const select = document.createElement('select');
            select.className = 'form-select';
            select.name = fieldName;
            select.required = !!field.required;
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'Selecionar...';
            select.appendChild(empty);
            (field.options || []).forEach((optionValue) => {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                select.appendChild(option);
            });
            column.appendChild(select);
        } else {
            const input = document.createElement('input');
            input.className = 'form-control';
            input.type = field.type || 'text';
            input.name = fieldName;
            input.required = !!field.required;
            column.appendChild(input);
        }

        row.appendChild(column);
    });

    requestsTicketTypeFields.appendChild(row);
}

function applyPresetToForm(formElement, presetKey) {
    if (!formElement) {
        return;
    }

    const preset = teamFormPresets[presetKey];
    if (!preset) {
        return;
    }

    const titleInput = formElement.querySelector('input[name="title"]');
    const descriptionInput = formElement.querySelector('textarea[name="description"]');
    const departmentInput = formElement.querySelector('input[name="department"]');
    const labels = formElement.querySelectorAll('input[name="field_label[]"]');
    const types = formElement.querySelectorAll('select[name="field_type[]"]');
    const requireds = formElement.querySelectorAll('select[name="field_required[]"]');
    const options = formElement.querySelectorAll('input[name="field_options[]"]');

    if (titleInput) titleInput.value = preset.title || '';
    if (descriptionInput) descriptionInput.value = preset.description || '';
    if (departmentInput) departmentInput.value = preset.department || '';

    labels.forEach((input, idx) => {
        const field = preset.fields[idx] || null;
        input.value = field ? (field.label || '') : '';
    });
    types.forEach((input, idx) => {
        const field = preset.fields[idx] || null;
        input.value = field ? (field.type || 'text') : 'text';
    });
    requireds.forEach((input, idx) => {
        const field = preset.fields[idx] || null;
        input.value = field && field.required ? '1' : '0';
    });
    options.forEach((input, idx) => {
        const field = preset.fields[idx] || null;
        input.value = field ? ((field.options || []).join(',')) : '';
    });
}

if (requestsTicketTypeSelect) {
    requestsTicketTypeSelect.addEventListener('change', renderRequestsTicketTypeFields);
    renderRequestsTicketTypeFields();
}

const createFormPreset = document.getElementById('createFormPreset');
const createTeamFormForm = document.getElementById('createTeamFormForm');
if (createFormPreset && createTeamFormForm) {
    createFormPreset.addEventListener('change', () => {
        const presetKey = createFormPreset.value;
        if (presetKey !== '') {
            applyPresetToForm(createTeamFormForm, presetKey);
        }
    });
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
