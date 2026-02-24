<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;

$ticketTypeTemplates = [
    'compras' => [
        'label' => 'Compras',
        'fields' => [
            ['name' => 'item_name', 'label' => 'Item a comprar', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex.: Parafusos M8 inox'],
            ['name' => 'quantity', 'label' => 'Quantidade', 'type' => 'number', 'required' => true, 'placeholder' => 'Ex.: 120'],
            ['name' => 'needed_by', 'label' => 'Necessário até', 'type' => 'date', 'required' => false, 'placeholder' => ''],
            ['name' => 'supplier', 'label' => 'Fornecedor preferencial', 'type' => 'text', 'required' => false, 'placeholder' => 'Opcional'],
            ['name' => 'attachment', 'label' => 'Anexo do pedido', 'type' => 'file', 'required' => false],
        ],
    ],
    'manutencao' => [
        'label' => 'Manutenção',
        'fields' => [
            ['name' => 'equipment', 'label' => 'Equipamento', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex.: CNC Haas VF-2'],
            ['name' => 'failure', 'label' => 'Avaria reportada', 'type' => 'textarea', 'required' => true, 'placeholder' => 'Descreve o problema'],
            ['name' => 'stopped', 'label' => 'Máquina parada', 'type' => 'select', 'required' => true, 'options' => ['Sim', 'Não']],
            ['name' => 'location', 'label' => 'Localização', 'type' => 'text', 'required' => false, 'placeholder' => 'Zona/Linha'],
            ['name' => 'attachment', 'label' => 'Anexo da avaria', 'type' => 'file', 'required' => false],
        ],
    ],
    'desenho_tecnico' => [
        'label' => 'Desenho técnico',
        'fields' => [
            ['name' => 'project_ref', 'label' => 'Referência do projeto', 'type' => 'text', 'required' => true, 'placeholder' => 'Ex.: PRJ-2026-014'],
            ['name' => 'drawing_type', 'label' => 'Tipo de desenho', 'type' => 'select', 'required' => true, 'options' => ['2D', '3D', 'Ambos']],
            ['name' => 'materials', 'label' => 'Materiais', 'type' => 'text', 'required' => false, 'placeholder' => 'Ex.: Aço S275'],
            ['name' => 'deadline', 'label' => 'Prazo pretendido', 'type' => 'date', 'required' => false, 'placeholder' => ''],
            ['name' => 'attachment', 'label' => 'Anexo técnico', 'type' => 'file', 'required' => false],
        ],
    ],
];

function infer_team_ticket_preset(string $teamName): array
{
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($teamName, 'UTF-8')
        : strtolower($teamName);

    if (string_contains($normalized, 'desenho')) {
        return [
            'ticket_type' => 'desenho_tecnico',
            'urgency' => 'Média',
            'title_placeholder' => 'Ex.: Atualizar desenho de peça',
            'description_placeholder' => 'Descreve alterações, materiais e referências do desenho',
        ];
    }

    if (string_contains($normalized, 'manuten')) {
        return [
            'ticket_type' => 'manutencao',
            'urgency' => 'Alta',
            'title_placeholder' => 'Ex.: Avaria na linha de produção',
            'description_placeholder' => 'Indica equipamento, impacto e estado atual da avaria',
        ];
    }

    if (string_contains($normalized, 'compra') || string_contains($normalized, 'logíst') || string_contains($normalized, 'logist')) {
        return [
            'ticket_type' => 'compras',
            'urgency' => 'Média',
            'title_placeholder' => 'Ex.: Pedido de consumíveis',
            'description_placeholder' => 'Detalha item, quantidade e prazo necessário',
        ];
    }

    return [
        'ticket_type' => '',
        'urgency' => 'Média',
        'title_placeholder' => 'Ex.: Corrigir integração com faturação',
        'description_placeholder' => 'Descreve o pedido com contexto e impacto',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_team') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name !== '') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO teams(name, description, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description, $userId]);
            $teamId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO team_members(team_id, user_id, role) VALUES (?, ?, "owner")')->execute([$teamId, $userId]);
            $pdo->commit();
            log_app_event($pdo, $userId, 'team.create', 'Nova equipa criada no dashboard.', ['team_id' => $teamId, 'team_name' => $name]);
            redirect('team.php?id=' . $teamId);
        }
        $flashError = 'Indique um nome para a equipa.';
    }

    if ($action === 'create_user' && $isAdmin) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, email e password para criar utilizador.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, email, password, is_admin) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), (int) ($_POST['is_admin'] ?? 0)]);
                $createdUserId = (int) $pdo->lastInsertId();
                log_app_event($pdo, $userId, 'user.create', 'Utilizador criado por administrador.', ['target_user_id' => $createdUserId, 'email' => $email]);
                $flashSuccess = 'Novo utilizador criado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível criar utilizador (email duplicado).';
            }
        }
    }


    if ($action === 'update_user' && $isAdmin) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $isTargetAdmin = (int) ($_POST['is_admin'] ?? 0);

        if ($targetUserId <= 0 || $name === '' || $email === '') {
            $flashError = 'Preencha nome e email para editar utilizador.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ?, is_admin = ? WHERE id = ?');
                    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, is_admin = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $isTargetAdmin, $targetUserId]);
                }
                log_app_event($pdo, $userId, 'user.update', 'Utilizador atualizado por administrador.', ['target_user_id' => $targetUserId, 'email' => $email, 'is_admin' => $isTargetAdmin]);
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível atualizar utilizador (email duplicado).';
            }
        }
    }

    if ($action === 'upload_branding' && $isAdmin) {
        $saved = 0;
        $lightPath = save_brand_logo($_FILES['logo_navbar_light'] ?? [], 'navbar_light');
        if ($lightPath) {
            set_app_setting($pdo, 'logo_navbar_light', $lightPath);
            $saved++;
        }

        $darkPath = save_brand_logo($_FILES['logo_report_dark'] ?? [], 'report_dark');
        if ($darkPath) {
            set_app_setting($pdo, 'logo_report_dark', $darkPath);
            $saved++;
        }

        if ($saved > 0) {
            log_app_event($pdo, $userId, 'branding.update', 'Logótipos da empresa atualizados.', ['total_files' => $saved]);
            $flashSuccess = 'Logotipos atualizados com sucesso.';
        } else {
            $flashError = 'Nenhum ficheiro válido enviado (PNG, JPG, SVG ou WEBP).';
        }
    }

    if ($action === 'update_scheduled_task') {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));
        $urgency = trim((string) ($_POST['urgency'] ?? 'Média'));
        $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
        $estimatedMinutes = ($_POST['estimated_minutes'] ?? '') !== '' ? max(0, (int) $_POST['estimated_minutes']) : null;
        $actualMinutes = ($_POST['actual_minutes'] ?? '') !== '' ? max(0, (int) $_POST['actual_minutes']) : null;

        $allowedUrgencies = ['Baixa', 'Média', 'Alta', 'Crítica'];
        if ($ticketId <= 0 || !ticket_status_value_exists($pdo, $status) || !in_array($urgency, $allowedUrgencies, true)) {
            $flashError = 'Dados inválidos para atualizar tarefa.';
        } else {
            $checkStmt = $pdo->prepare('SELECT tt.id FROM team_tickets tt INNER JOIN team_members tm ON tm.team_id = tt.team_id WHERE tt.id = ? AND tt.assignee_user_id = ? AND tm.user_id = ? LIMIT 1');
            $checkStmt->execute([$ticketId, $userId, $userId]);
            $canUpdateOwnTicket = (bool) $checkStmt->fetchColumn();

            if (!$canUpdateOwnTicket) {
                $flashError = 'Sem permissão para atualizar esta tarefa.';
            } else {
                $dueDate = null;
                if ($dueDateInput !== '') {
                    $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
                    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dueDateInput) {
                        $flashError = 'Data limite inválida.';
                    } else {
                        $dueDate = $dueDateInput;
                    }
                }

                if ($flashError === null) {
                    $currentStmt = $pdo->prepare('SELECT status FROM team_tickets WHERE id = ? AND assignee_user_id = ? LIMIT 1');
                    $currentStmt->execute([$ticketId, $userId]);
                    $previousStatus = $currentStmt->fetchColumn();

                    $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
                    $updateStmt = $pdo->prepare('UPDATE team_tickets SET status = ?, urgency = ?, due_date = ?, estimated_minutes = ?, actual_minutes = ?, completed_at = ? WHERE id = ? AND assignee_user_id = ?');
                    $updateStmt->execute([$status, $urgency, $dueDate, $estimatedMinutes, $actualMinutes, $completedAt, $ticketId, $userId]);
                    if ($updateStmt->rowCount() > 0) {
                        if ($previousStatus !== false && (string) $previousStatus !== $status) {
                            record_ticket_status_history($pdo, $ticketId, (string) $previousStatus, $status, $userId);
                        }
                        log_app_event($pdo, $userId, 'ticket.update.self', 'Tarefa atribuída atualizada no dashboard.', ['ticket_id' => $ticketId, 'status' => $status, 'urgency' => $urgency]);
                        $flashSuccess = 'Tarefa atualizada com sucesso.';
                    }
                }
            }
        }
    }


    if ($action === 'update_project_scheduled_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'todo'));
        $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
        $estimatedMinutes = ($_POST['estimated_minutes'] ?? '') !== '' ? max(0, (int) $_POST['estimated_minutes']) : null;
        $actualMinutes = ($_POST['actual_minutes'] ?? '') !== '' ? max(0, (int) $_POST['actual_minutes']) : null;

        if ($taskId <= 0 || !in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $flashError = 'Dados inválidos para atualizar tarefa de projeto.';
        } else {
            $dueDate = null;
            if ($dueDateInput !== '') {
                $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dueDateInput) {
                    $flashError = 'Data prevista inválida.';
                } else {
                    $dueDate = $dueDateInput;
                }
            }

            if ($flashError === null) {
                $updateStmt = $pdo->prepare('UPDATE tasks SET status = ?, due_date = ?, estimated_minutes = ?, actual_minutes = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND assignee_user_id = ?');
                $updateStmt->execute([$status, $dueDate, $estimatedMinutes, $actualMinutes, $userId, $taskId, $userId]);
                if ($updateStmt->rowCount() > 0) {
                    $flashSuccess = 'Tarefa de projeto atualizada com sucesso.';
                }
            }
        }
    }

    if ($action === 'create_team_ticket') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $urgency = trim($_POST['urgency'] ?? 'Média');
        $dueDate = trim($_POST['due_date'] ?? '');

        $allowedTeamStmt = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ?');
        $allowedTeamStmt->execute([$userId]);
        $allowedTeamIds = array_map('intval', $allowedTeamStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($teamId <= 0 || $title === '' || $description === '') {
            $flashError = 'Preencha equipa, nome do pedido e descrição do ticket.';
        } elseif ($dueDate === '') {
            $flashError = 'Data limite é obrigatória para criar tarefa.';
        } elseif (!in_array($teamId, $allowedTeamIds, true) && !$isAdmin) {
            $flashError = 'Sem permissão para abrir ticket para essa equipa.';
        } else {
            $canCreateTicket = true;
            $ticketType = (string) ($_POST['ticket_type'] ?? '');

            $selectedTeamNameStmt = $pdo->prepare('SELECT name FROM teams WHERE id = ? LIMIT 1');
            $selectedTeamNameStmt->execute([$teamId]);
            $selectedTeamName = (string) $selectedTeamNameStmt->fetchColumn();
            $teamPreset = infer_team_ticket_preset($selectedTeamName);
            if (($teamPreset['ticket_type'] ?? '') !== '') {
                $ticketType = (string) $teamPreset['ticket_type'];
            }

            if (!array_key_exists($ticketType, $ticketTypeTemplates)) {
                $ticketType = '';
            }

            if ($ticketType !== '') {
                $template = $ticketTypeTemplates[$ticketType];
                $extraLines = [];
                foreach ($template['fields'] as $field) {
                    $fieldName = 'ticket_field_' . $field['name'];
                    $fieldType = (string) ($field['type'] ?? 'text');

                    if ($fieldType === 'file') {
                        $file = $_FILES[$fieldName] ?? null;
                        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                        if (!empty($field['required']) && !$hasFile) {
                            $flashError = 'Preencha os campos obrigatórios para ' . $template['label'] . '.';
                            $canCreateTicket = false;
                            break;
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
                                $flashError = 'Não foi possível carregar o ficheiro obrigatório de ' . $template['label'] . '.';
                                $canCreateTicket = false;
                                break;
                            }
                        }

                        continue;
                    }

                    $value = trim((string) ($_POST[$fieldName] ?? ''));
                    if (!empty($field['required']) && $value === '') {
                        $flashError = 'Preencha os campos obrigatórios para ' . $template['label'] . '.';
                        $canCreateTicket = false;
                        break;
                    }

                    if ($value !== '') {
                        $extraLines[] = ($field['label'] ?? $fieldName) . ': ' . $value;
                    }
                }

                if ($flashError === null && count($extraLines) > 0) {
                    $description .= "\n\nDetalhes de " . $template['label'] . ":\n- " . implode("\n- ", $extraLines);
                }
            }

            if ($canCreateTicket) {
                $ticketCode = generate_ticket_code($pdo);
                $defaultStatus = default_open_ticket_status($pdo);
                $stmt = $pdo->prepare('INSERT INTO team_tickets(ticket_code, team_id, title, description, urgency, due_date, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$ticketCode, $teamId, $title, $description, $urgency ?: 'Média', $dueDate, $userId, $defaultStatus]);
                $ticketId = (int) $pdo->lastInsertId();
                record_ticket_status_history($pdo, $ticketId, null, $defaultStatus, $userId);
                log_app_event($pdo, $userId, 'ticket.create', 'Novo ticket de equipa criado no dashboard.', ['ticket_id' => $ticketId, 'ticket_code' => $ticketCode, 'team_id' => $teamId]);
                $flashSuccess = 'Ticket criado com sucesso.';
            }
        }
    }
}

$teamsStmt = $pdo->prepare('SELECT t.*, tm.role, (SELECT COUNT(*) FROM projects p WHERE p.team_id = t.id) AS total_projects FROM teams t INNER JOIN team_members tm ON tm.team_id = t.id WHERE tm.user_id = ? ORDER BY t.created_at DESC');
$teamsStmt->execute([$userId]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$teamTicketPresets = [];
foreach ($teams as $team) {
    $teamTicketPresets[(string) $team['id']] = infer_team_ticket_preset((string) $team['name']);
}

$statsStmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM team_members WHERE user_id = ?) AS total_teams, (SELECT COUNT(*) FROM users) AS total_users, (SELECT COUNT(*) FROM projects p INNER JOIN team_members tm ON tm.team_id = p.team_id WHERE tm.user_id = ?) AS total_projects');
$statsStmt->execute([$userId, $userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name, email, is_admin FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$scheduledTasksStmt = $pdo->prepare("SELECT tt.id, tt.title, tt.status, tt.urgency, tt.due_date, tt.created_at, tt.estimated_minutes, tt.actual_minutes, t.id AS team_id, t.name AS team_name
    FROM team_tickets tt
    INNER JOIN teams t ON t.id = tt.team_id
    INNER JOIN team_members tm ON tm.team_id = t.id
    WHERE tt.assignee_user_id = ? AND tm.user_id = ?
    ORDER BY
        CASE WHEN tt.due_date IS NULL THEN 1 ELSE 0 END ASC,
        tt.due_date ASC,
        CASE tt.urgency
            WHEN 'Crítica' THEN 4
            WHEN 'Alta' THEN 3
            WHEN 'Média' THEN 2
            WHEN 'Baixa' THEN 1
            ELSE 0
        END DESC,
        tt.created_at DESC");
$scheduledTasksStmt->execute([$userId, $userId]);
$scheduledTasks = $scheduledTasksStmt->fetchAll(PDO::FETCH_ASSOC);
$scheduledTaskIds = array_map(static fn (array $task): int => (int) $task['id'], $scheduledTasks);

$scheduledProjectTasksStmt = $pdo->prepare("SELECT t.id, t.title, t.status, t.due_date, t.estimated_minutes, t.actual_minutes, t.created_at, p.id AS project_id, p.name AS project_name
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    INNER JOIN team_members tm ON tm.team_id = p.team_id
    WHERE t.assignee_user_id = ? AND tm.user_id = ?
    ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC, t.due_date ASC, t.created_at DESC");
$scheduledProjectTasksStmt->execute([$userId, $userId]);
$scheduledProjectTasks = $scheduledProjectTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$scheduledTaskHistoryByTicket = [];
if ($scheduledTaskIds) {
    $placeholders = implode(',', array_fill(0, count($scheduledTaskIds), '?'));
    $historyStmt = $pdo->prepare("SELECT tsh.ticket_id, tsh.from_status, tsh.to_status, tsh.changed_at, u.name AS changed_by_name FROM team_ticket_status_history tsh LEFT JOIN users u ON u.id = tsh.changed_by WHERE tsh.ticket_id IN ($placeholders) ORDER BY tsh.changed_at DESC, tsh.id DESC");
    $historyStmt->execute($scheduledTaskIds);
    foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {
        $scheduledTaskHistoryByTicket[(int) $historyRow['ticket_id']][] = $historyRow;
    }
}
$ticketStatuses = ticket_statuses($pdo);
$completedStatusValues = [];
foreach ($ticketStatuses as $status) {
    if (!empty($status['is_completed'])) {
        $completedStatusValues[] = (string) $status['value'];
    }
}

$pendingTasksByPreset = [
    'desenho_tecnico' => [],
    'manutencao' => [],
];

if ($isAdmin) {
    $teamPendingTicketsStmt = $pdo->query("SELECT tt.id, tt.title, tt.status, tt.urgency, tt.due_date, t.id AS team_id, t.name AS team_name
        FROM team_tickets tt
        INNER JOIN teams t ON t.id = tt.team_id
        ORDER BY
            CASE WHEN tt.due_date IS NULL THEN 1 ELSE 0 END ASC,
            tt.due_date ASC,
            CASE tt.urgency
                WHEN 'Crítica' THEN 4
                WHEN 'Alta' THEN 3
                WHEN 'Média' THEN 2
                WHEN 'Baixa' THEN 1
                ELSE 0
            END DESC,
            tt.created_at DESC");
    $teamPendingTickets = $teamPendingTicketsStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $teamPendingTicketsStmt = $pdo->prepare("SELECT tt.id, tt.title, tt.status, tt.urgency, tt.due_date, t.id AS team_id, t.name AS team_name
        FROM team_tickets tt
        INNER JOIN teams t ON t.id = tt.team_id
        INNER JOIN team_members tm ON tm.team_id = t.id
        WHERE tm.user_id = ?
        ORDER BY
            CASE WHEN tt.due_date IS NULL THEN 1 ELSE 0 END ASC,
            tt.due_date ASC,
            CASE tt.urgency
                WHEN 'Crítica' THEN 4
                WHEN 'Alta' THEN 3
                WHEN 'Média' THEN 2
                WHEN 'Baixa' THEN 1
                ELSE 0
            END DESC,
            tt.created_at DESC");
    $teamPendingTicketsStmt->execute([$userId]);
    $teamPendingTickets = $teamPendingTicketsStmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($teamPendingTickets as $pendingTask) {
    if (in_array((string) $pendingTask['status'], $completedStatusValues, true)) {
        continue;
    }

    $presetType = (string) (infer_team_ticket_preset((string) $pendingTask['team_name'])['ticket_type'] ?? '');
    if (!array_key_exists($presetType, $pendingTasksByPreset)) {
        continue;
    }

    $pendingTasksByPreset[$presetType][] = [
        'title' => (string) $pendingTask['title'],
        'team_name' => (string) $pendingTask['team_name'],
        'urgency' => (string) $pendingTask['urgency'],
        'due_date' => $pendingTask['due_date'] !== null ? (string) $pendingTask['due_date'] : '',
        'team_id' => (int) $pendingTask['team_id'],
    ];
}

$pageTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card mb-4 p-4 p-md-5">
    <div class="row align-items-center g-3">
        <div class="col-lg-8">
            <h1 class="display-6 fw-semibold mb-2">Gestão da tua operação em tempo real</h1>
            <p class="mb-0 text-white-50">Organiza equipas, projetos, tarefas e pedidos internos num único espaço.</p>
        </div>
        <div class="col-lg-4"><div class="row g-2 text-center"><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_teams'] ?></strong><span>Equipas</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_projects'] ?></strong><span>Projetos</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_users'] ?></strong><span>Users</span></div></div></div></div>
    </div>
</section>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <h2 class="h4 mb-0">Tarefas programadas para ti</h2>
                <div class="d-flex align-items-center gap-3 ms-auto">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="toggleCompletedTasks">
                        <label class="form-check-label small" for="toggleCompletedTasks">Ocultar concluídas</label>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#scheduledTasksContent" aria-expanded="true" aria-controls="scheduledTasksContent" id="scheduledTasksVisibilityBtn" title="Colapsar/expandir">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            <div class="collapse show" id="scheduledTasksContent">
            <div class="card-body p-4">
                <?php if ($scheduledTasks): ?>
                    <div class="vstack gap-3" id="scheduledTasksList">
                        <?php foreach ($scheduledTasks as $task): ?>
                            <form method="post" class="border rounded p-3 vstack gap-2 js-scheduled-task" data-is-completed="<?= ticket_status_is_completed($pdo, (string) $task['status']) ? '1' : '0' ?>">
                                <input type="hidden" name="action" value="update_scheduled_task">
                                <input type="hidden" name="ticket_id" value="<?= (int) $task['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <strong><?= h($task['title']) ?></strong>
                                        <div class="small text-muted">Equipa: <?= h($task['team_name']) ?></div>
                                    </div>
                                    <span class="badge" style="<?= h(ticket_status_badge_style($pdo, (string) $task['status'])) ?>"><?= h(ticket_status_label($pdo, (string) $task['status'])) ?></span>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label mb-1 small">Estado</label>
                                        <select class="form-select form-select-sm" name="status">
                                            <?php foreach ($ticketStatuses as $statusOption): ?>
                                                <option value="<?= h((string) $statusOption['value']) ?>" <?= (string) $task['status'] === (string) $statusOption['value'] ? 'selected' : '' ?>><?= h((string) $statusOption['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label mb-1 small">Urgência</label>
                                        <select class="form-select form-select-sm" name="urgency">
                                            <?php foreach (['Baixa', 'Média', 'Alta', 'Crítica'] as $urgencyOption): ?>
                                                <option value="<?= h($urgencyOption) ?>" <?= (string) $task['urgency'] === $urgencyOption ? 'selected' : '' ?>><?= h($urgencyOption) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label mb-1 small">Data limite</label>
                                        <input class="form-control form-control-sm" type="date" name="due_date" value="<?= h((string) ($task['due_date'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label mb-1 small">Previsto (min)</label>
                                        <input class="form-control form-control-sm" type="number" min="0" name="estimated_minutes" value="<?= $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : '' ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1 small">Real (min)</label>
                                        <input class="form-control form-control-sm" type="number" min="0" name="actual_minutes" value="<?= $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : '' ?>">
                                    </div>
                                </div>
                                <?php $taskHistory = $scheduledTaskHistoryByTicket[(int) $task['id']] ?? []; ?>
                                <?php if ($taskHistory): ?>
                                    <div class="border rounded p-2 bg-light">
                                        <div class="small fw-semibold mb-1">Histórico de estados</div>
                                        <ul class="small mb-0 ps-3">
                                            <?php foreach ($taskHistory as $historyItem): ?>
                                                <li>
                                                    <?= h(ticket_status_label($pdo, (string) $historyItem['to_status'])) ?>
                                                    <?php if (!empty($historyItem['from_status'])): ?>
                                                        <span class="text-muted">(de <?= h(ticket_status_label($pdo, (string) $historyItem['from_status'])) ?>)</span>
                                                    <?php endif; ?>
                                                    · <?= h(date('d/m/Y H:i', strtotime((string) $historyItem['changed_at']))) ?>
                                                    · <?= h((string) ($historyItem['changed_by_name'] ?: 'Sistema')) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center">
                                    <a class="btn btn-link btn-sm px-0" href="team.php?id=<?= (int) $task['team_id'] ?>">Abrir equipa</a>
                                    <button class="btn btn-sm btn-primary">Guardar alterações</button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted mb-0 d-none" id="scheduledTasksEmptyState">Sem tarefas pendentes com o filtro atual.</p>
                <?php else: ?>
                    <p class="text-muted mb-0">Ainda não tens tarefas atribuídas.</p>
                <?php endif; ?>
            </div>
            </div>
        </div>

        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center gap-3">
                <h2 class="h5 mb-0">Pendentes por equipa técnica</h2>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#pendingByTypeContent" aria-expanded="false" aria-controls="pendingByTypeContent" id="pendingByTypeVisibilityBtn" title="Colapsar/expandir">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="collapse" id="pendingByTypeContent">
                <div class="card-body p-4 vstack gap-3">
                    <div>
                        <label class="form-label mb-1" for="pendingBoardFilter">Ver pendentes de</label>
                        <select class="form-select" id="pendingBoardFilter">
                            <option value="desenho_tecnico">Desenho técnico</option>
                            <option value="manutencao">Manutenção</option>
                        </select>
                    </div>
                    <ul class="list-group" id="pendingBoardList"></ul>
                    <p class="text-muted mb-0 d-none" id="pendingBoardEmpty">Não existem tarefas pendentes neste grupo.</p>
                </div>
            </div>
        </div>

        <div class="card shadow-sm soft-card h-100">
            <div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h4 mb-0">Novo ticket de equipa</h2></div>
            <div class="card-body p-4">
                <p class="small text-muted">Cria tarefas para equipas fora de qualquer projeto.</p>
                <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                    <input type="hidden" name="action" value="create_team_ticket">
                    <div>
                        <label class="form-label mb-1">Equipa responsável</label>
                        <select class="form-select" name="team_id" id="dashboardTeamSelect" required>
                            <option value="">Selecionar equipa</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= (int) $team['id'] ?>"><?= h($team['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1">Nome do pedido</label>
                        <input class="form-control" name="title" id="dashboardTicketTitle" placeholder="Ex.: Corrigir integração com faturação" required>
                    </div>
                    <div>
                        <label class="form-label mb-1">Tipo de pedido</label>
                        <select class="form-select" name="ticket_type" id="dashboardTicketType">
                            <option value="">Geral</option>
                            <?php foreach ($ticketTypeTemplates as $templateKey => $template): ?>
                                <option value="<?= h($templateKey) ?>"><?= h($template['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="dashboardTicketTypeHint" class="form-text d-none"></div>
                    </div>
                    <div id="dashboardTicketTypeFields" class="vstack gap-2"></div>
                    <div>
                        <label class="form-label mb-1">Descrição do ticket</label>
                        <textarea class="form-control" name="description" id="dashboardTicketDescription" rows="5" placeholder="Descreve o pedido com contexto e impacto" required></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label mb-1">Nível de urgência</label>
                            <select class="form-select" name="urgency" id="dashboardUrgencySelect">
                                <option value="Baixa">Baixa</option>
                                <option value="Média" selected>Média</option>
                                <option value="Alta">Alta</option>
                                <option value="Crítica">Crítica</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label mb-1">Data limite</label>
                            <input class="form-control" type="date" name="due_date" required>
                        </div>
                    </div>
                    <button class="btn btn-primary">Criar ticket</button>
                </form>
                <a href="requests.php" class="btn btn-link px-0 mt-2">Ver todos os pedidos</a>
            </div>
        </div>

        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4">
                <h2 class="h5 mb-0">Tarefas de projetos atribuídas</h2>
            </div>
            <div class="card-body p-4">
                <?php if ($scheduledProjectTasks): ?>
                    <div class="vstack gap-3">
                        <?php foreach ($scheduledProjectTasks as $task): ?>
                            <form method="post" class="border rounded p-3 vstack gap-2">
                                <input type="hidden" name="action" value="update_project_scheduled_task">
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <strong><?= h($task['title']) ?></strong>
                                        <div class="small text-muted">Projeto: <?= h($task['project_name']) ?></div>
                                    </div>
                                    <span class="badge bg-<?= task_badge_class((string) $task['status']) ?>"><?= h(status_label((string) $task['status'])) ?></span>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label mb-1 small">Estado</label>
                                        <select class="form-select form-select-sm" name="status">
                                            <option value="todo" <?= (string) $task['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                            <option value="in_progress" <?= (string) $task['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                            <option value="done" <?= (string) $task['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label mb-1 small">Data prevista</label>
                                        <input class="form-control form-control-sm" type="date" name="due_date" value="<?= h((string) ($task['due_date'] ?? '')) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1 small">Previsto</label>
                                        <input class="form-control form-control-sm" type="number" min="0" name="estimated_minutes" value="<?= $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : '' ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label mb-1 small">Real</label>
                                        <input class="form-control form-control-sm" type="number" min="0" name="actual_minutes" value="<?= $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : '' ?>">
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-dark icon-btn" aria-label="Guardar tarefa de projeto"><i class="bi bi-save"></i></button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Sem tarefas de projeto atribuídas.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Gestão de utilizadores</h2><?php if ($isAdmin): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#userModal">Novo user</button><?php endif; ?></div>
            <div class="card-body px-4"><?php foreach ($users as $user): ?><div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2"><div><strong class="small"><?= h($user['name']) ?></strong> <?= (int) $user['is_admin'] === 1 ? '<span class="badge text-bg-dark">admin</span>' : '' ?><div class="small text-muted"><?= h($user['email']) ?></div></div><div class="d-flex align-items-center gap-2"><span class="small text-muted">#<?= (int) $user['id'] ?></span><?php if ($isAdmin): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int) $user['id'] ?>">Editar</button><?php endif; ?></div></div><?php endforeach; ?></div>
        </div>

        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">As tuas equipas</h2><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#teamModal">Nova Equipa</button></div>
            <div class="card-body px-4 pb-4">
                <div class="vstack gap-2">
                    <?php foreach ($teams as $team): ?>
                        <div class="team-card p-3">
                            <h3 class="h6 mb-1"><?= h($team['name']) ?></h3>
                            <p class="text-muted small mb-2"><?= h($team['description']) ?: 'Sem descrição' ?></p>
                            <p class="small mb-2">Projetos: <strong><?= (int) $team['total_projects'] ?></strong> · Papel: <span class="badge text-bg-light border"><?= h($team['role']) ?></span></p>
                            <a class="btn btn-outline-primary btn-sm" href="team.php?id=<?= (int) $team['id'] ?>">Abrir equipa</a>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$teams): ?><p class="text-muted mb-0">Ainda não fazes parte de equipas.</p><?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
const dashboardTicketTypeTemplates = <?= json_encode($ticketTypeTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const dashboardTeamTicketPresets = <?= json_encode($teamTicketPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const dashboardPendingTasksByPreset = <?= json_encode($pendingTasksByPreset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const dashboardTeamSelect = document.getElementById('dashboardTeamSelect');
const dashboardTicketTypeSelect = document.getElementById('dashboardTicketType');
const dashboardTicketTypeFields = document.getElementById('dashboardTicketTypeFields');
const dashboardTicketTypeHint = document.getElementById('dashboardTicketTypeHint');
const dashboardUrgencySelect = document.getElementById('dashboardUrgencySelect');
const dashboardTicketTitle = document.getElementById('dashboardTicketTitle');
const dashboardTicketDescription = document.getElementById('dashboardTicketDescription');
const toggleCompletedTasks = document.getElementById('toggleCompletedTasks');
const scheduledTaskRows = document.querySelectorAll('.js-scheduled-task');
const scheduledTasksEmptyState = document.getElementById('scheduledTasksEmptyState');
const scheduledTasksVisibilityBtn = document.getElementById('scheduledTasksVisibilityBtn');
const pendingByTypeVisibilityBtn = document.getElementById('pendingByTypeVisibilityBtn');
const pendingByTypeFilter = document.getElementById('pendingBoardFilter');
const pendingByTypeList = document.getElementById('pendingBoardList');
const pendingByTypeEmpty = document.getElementById('pendingBoardEmpty');

function refreshScheduledTasksVisibility() {
    if (!toggleCompletedTasks || scheduledTaskRows.length === 0) {
        return;
    }

    let visibleCount = 0;
    scheduledTaskRows.forEach((taskRow) => {
        const isCompleted = taskRow.dataset.isCompleted === '1';
        const shouldHide = toggleCompletedTasks.checked && isCompleted;
        taskRow.classList.toggle('d-none', shouldHide);
        if (!shouldHide) {
            visibleCount += 1;
        }
    });

    if (scheduledTasksEmptyState) {
        scheduledTasksEmptyState.classList.toggle('d-none', visibleCount > 0);
    }
}

function updateCollapseEyeIcon(buttonElement, isExpanded) {
    if (!buttonElement) {
        return;
    }

    const icon = buttonElement.querySelector('i');
    if (!icon) {
        return;
    }

    icon.classList.toggle('bi-eye', isExpanded);
    icon.classList.toggle('bi-eye-slash', !isExpanded);
}

function renderPendingTasksByPreset() {
    if (!pendingByTypeFilter || !pendingByTypeList || !pendingByTypeEmpty) {
        return;
    }

    const selectedPreset = pendingByTypeFilter.value;
    const tasks = dashboardPendingTasksByPreset[selectedPreset] || [];
    pendingByTypeList.innerHTML = '';

    tasks.forEach((task) => {
        const dueDateLabel = task.due_date ? ` · Até ${task.due_date}` : '';
        const listItem = document.createElement('li');
        listItem.className = 'list-group-item d-flex justify-content-between align-items-start gap-3';
        listItem.innerHTML = `
            <div>
                <strong>${task.title}</strong>
                <div class="small text-muted">${task.team_name} · ${task.urgency}${dueDateLabel}</div>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="team.php?id=${task.team_id}">Abrir</a>
        `;
        pendingByTypeList.appendChild(listItem);
    });

    pendingByTypeEmpty.classList.toggle('d-none', tasks.length > 0);
}

function buildDashboardTicketTypeFields() {
    if (!dashboardTicketTypeSelect || !dashboardTicketTypeFields) {
        return;
    }

    const selected = dashboardTicketTypeSelect.value;
    const config = dashboardTicketTypeTemplates[selected];
    dashboardTicketTypeFields.innerHTML = '';

    if (!config || !Array.isArray(config.fields)) {
        return;
    }

    config.fields.forEach((field) => {
        const fieldWrapper = document.createElement('div');
        const label = document.createElement('label');
        label.className = 'form-label mb-1';
        label.textContent = field.label || 'Campo';
        fieldWrapper.appendChild(label);

        const inputName = `ticket_field_${field.name}`;
        if (field.type === 'textarea') {
            const textarea = document.createElement('textarea');
            textarea.className = 'form-control';
            textarea.name = inputName;
            textarea.required = !!field.required;
            textarea.rows = 3;
            textarea.placeholder = field.placeholder || '';
            fieldWrapper.appendChild(textarea);
        } else if (field.type === 'file') {
            const input = document.createElement('input');
            input.className = 'form-control';
            input.type = 'file';
            input.name = inputName;
            input.required = !!field.required;
            input.accept = '.xls,.xlsx,.xlsm,.xlsb,.ods,.csv';
            fieldWrapper.appendChild(input);
        } else if (field.type === 'select') {
            const select = document.createElement('select');
            select.className = 'form-select';
            select.name = inputName;
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
            fieldWrapper.appendChild(select);
        } else {
            const input = document.createElement('input');
            input.className = 'form-control';
            input.type = field.type || 'text';
            input.name = inputName;
            input.required = !!field.required;
            input.placeholder = field.placeholder || '';
            fieldWrapper.appendChild(input);
        }

        dashboardTicketTypeFields.appendChild(fieldWrapper);
    });
}

function applyTeamPresetToDashboardForm() {
    if (!dashboardTeamSelect) {
        return;
    }

    const selectedTeamId = dashboardTeamSelect.value;
    const preset = dashboardTeamTicketPresets[selectedTeamId] || {
        ticket_type: '',
        urgency: 'Média',
        title_placeholder: 'Ex.: Corrigir integração com faturação',
        description_placeholder: 'Descreve o pedido com contexto e impacto',
    };

    if (dashboardTicketTypeSelect) {
        dashboardTicketTypeSelect.value = preset.ticket_type || '';
        dashboardTicketTypeSelect.disabled = !!preset.ticket_type;

        if (dashboardTicketTypeHint) {
            if (preset.ticket_type && dashboardTicketTypeTemplates[preset.ticket_type]) {
                dashboardTicketTypeHint.textContent = `Tipo definido automaticamente pela equipa selecionada (${dashboardTicketTypeTemplates[preset.ticket_type].label}).`;
                dashboardTicketTypeHint.classList.remove('d-none');
            } else {
                dashboardTicketTypeHint.textContent = '';
                dashboardTicketTypeHint.classList.add('d-none');
            }
        }

        buildDashboardTicketTypeFields();
    }

    if (dashboardUrgencySelect) {
        const targetUrgency = preset.urgency || 'Média';
        if (Array.from(dashboardUrgencySelect.options).some((option) => option.value === targetUrgency)) {
            dashboardUrgencySelect.value = targetUrgency;
        }
    }

    if (dashboardTicketTitle) {
        dashboardTicketTitle.placeholder = preset.title_placeholder || 'Ex.: Corrigir integração com faturação';
    }

    if (dashboardTicketDescription) {
        dashboardTicketDescription.placeholder = preset.description_placeholder || 'Descreve o pedido com contexto e impacto';
    }
}

if (dashboardTicketTypeSelect) {
    dashboardTicketTypeSelect.addEventListener('change', buildDashboardTicketTypeFields);
    buildDashboardTicketTypeFields();
}

if (dashboardTeamSelect) {
    dashboardTeamSelect.addEventListener('change', applyTeamPresetToDashboardForm);
    applyTeamPresetToDashboardForm();
}

if (toggleCompletedTasks) {
    toggleCompletedTasks.addEventListener('change', refreshScheduledTasksVisibility);
    refreshScheduledTasksVisibility();
}

const scheduledTasksCollapse = document.getElementById('scheduledTasksContent');
if (scheduledTasksCollapse) {
    scheduledTasksCollapse.addEventListener('shown.bs.collapse', () => updateCollapseEyeIcon(scheduledTasksVisibilityBtn, true));
    scheduledTasksCollapse.addEventListener('hidden.bs.collapse', () => updateCollapseEyeIcon(scheduledTasksVisibilityBtn, false));
}

const pendingByTypeCollapse = document.getElementById('pendingByTypeContent');
if (pendingByTypeCollapse) {
    pendingByTypeCollapse.addEventListener('shown.bs.collapse', () => updateCollapseEyeIcon(pendingByTypeVisibilityBtn, true));
    pendingByTypeCollapse.addEventListener('hidden.bs.collapse', () => updateCollapseEyeIcon(pendingByTypeVisibilityBtn, false));
}

if (pendingByTypeFilter) {
    pendingByTypeFilter.addEventListener('change', renderPendingTasksByPreset);
    renderPendingTasksByPreset();
}
</script>

<div class="modal fade" id="teamModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_team"><div class="modal-header"><h5 class="modal-title">Criar Equipa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome da equipa" required><textarea class="form-control" name="description" placeholder="Descrição"></textarea></div><div class="modal-footer"><button class="btn btn-primary">Criar</button></div></form></div></div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_user"><div class="modal-header"><h5 class="modal-title">Novo utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome" required><input class="form-control" type="email" name="email" placeholder="Email" required><input class="form-control" type="password" name="password" placeholder="Password" required><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdmin"><label class="form-check-label" for="isAdmin">Administrador</label></div></div><div class="modal-footer"><button class="btn btn-primary">Criar utilizador</button></div></form></div></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= (int) $user['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><div class="modal-header"><h5 class="modal-title">Editar utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" value="<?= h($user['name']) ?>" required><input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required><input class="form-control" type="password" name="password" placeholder="Nova password (opcional)"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdminEdit<?= (int) $user['id'] ?>" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isAdminEdit<?= (int) $user['id'] ?>">Administrador</label></div></div><div class="modal-footer"><button class="btn btn-primary">Guardar utilizador</button></div></form></div></div>
<?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
