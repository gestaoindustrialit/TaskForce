<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$user = current_user($pdo);
$isAdmin = is_admin($pdo, $userId);
$isPinOnlyUser = $user && (int) ($user['pin_only_login'] ?? 0) === 1;
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

    if ($action === 'clock_entry' && !$isPinOnlyUser) {
        $entryType = trim((string) ($_POST['entry_type'] ?? ''));

        if (!in_array($entryType, ['entrada', 'saida'], true)) {
            $flashError = 'Tipo de registo de ponto inválido.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO shopfloor_time_entries(user_id, entry_type, note) VALUES (?, ?, NULL)');
            $stmt->execute([$userId, $entryType]);
            log_app_event($pdo, $userId, 'shopfloor.clock.' . $entryType, 'Registo de ponto no dashboard.', ['entry_type' => $entryType]);
            $flashSuccess = $entryType === 'entrada' ? 'Ponto de entrada registado com sucesso.' : 'Ponto de saída registado com sucesso.';
        }
    }

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
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);

        if ($username === '') {
            $username = $email;
        }

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, utilizador, email e password para criar utilizador.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), (int) ($_POST['is_admin'] ?? 0), $accessProfile, $isActive, $mustChangePassword]);
                $createdUserId = (int) $pdo->lastInsertId();
                log_app_event($pdo, $userId, 'user.create', 'Utilizador criado por administrador.', ['target_user_id' => $createdUserId, 'email' => $email]);
                $flashSuccess = 'Novo utilizador criado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível criar utilizador (email/utilizador duplicado).';
            }
        }
    }


    if ($action === 'update_user' && $isAdmin) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $isTargetAdmin = (int) ($_POST['is_admin'] ?? 0);
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);

        if ($username === '') {
            $username = $email;
        }

        if ($targetUserId <= 0 || $name === '' || $username === '' || $email === '') {
            $flashError = 'Preencha nome, utilizador e email para editar utilizador.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $targetUserId]);
                }
                log_app_event($pdo, $userId, 'user.update', 'Utilizador atualizado por administrador.', ['target_user_id' => $targetUserId, 'email' => $email, 'is_admin' => $isTargetAdmin]);
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível atualizar utilizador (email/utilizador duplicado).';
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

    if ($action === 'update_recurring_scheduled_task') {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $occurrenceDateInput = trim((string) ($_POST['occurrence_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'todo'));
        $allowedStatuses = ['todo', 'in_progress', 'done'];

        if ($recurringTaskId <= 0 || !in_array($status, $allowedStatuses, true)) {
            $flashError = 'Dados inválidos para atualizar tarefa recorrente.';
        } else {
            $occurrenceDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $occurrenceDateInput);
            if (!$occurrenceDateObj || $occurrenceDateObj->format('Y-m-d') !== $occurrenceDateInput) {
                $flashError = 'Data inválida para tarefa recorrente.';
            } else {
                $taskStmt = $pdo->prepare('SELECT rt.* FROM team_recurring_tasks rt LEFT JOIN team_recurring_task_overrides o ON o.recurring_task_id = rt.id AND date(o.occurrence_date) = ? WHERE rt.id = ? AND (rt.assignee_user_id = ? OR o.assignee_user_id = ?) LIMIT 1');
                $taskStmt->execute([$occurrenceDateInput, $recurringTaskId, $userId, $userId]);
                $recurringTask = $taskStmt->fetch(PDO::FETCH_ASSOC);

                if (!$recurringTask) {
                    $flashError = 'Sem permissão para atualizar esta tarefa recorrente.';
                } else {
                    $occurrences = recurring_task_occurrences($pdo, $recurringTask, $occurrenceDateObj, $occurrenceDateObj);
                    if (!$occurrences) {
                        $flashError = 'Esta tarefa recorrente não está agendada para a data escolhida.';
                    } else {
                        $statusStmt = $pdo->prepare('INSERT INTO team_recurring_task_statuses(recurring_task_id, occurrence_date, status, updated_by) VALUES (?, ?, ?, ?) ON CONFLICT(recurring_task_id, occurrence_date) DO UPDATE SET status = excluded.status, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
                        $statusStmt->execute([$recurringTaskId, $occurrenceDateInput, $status, $userId]);

                        if ($status === 'done') {
                            $completeStmt = $pdo->prepare('INSERT OR IGNORE INTO team_recurring_task_completions(recurring_task_id, occurrence_date, completed_by) VALUES (?, ?, ?)');
                            $completeStmt->execute([$recurringTaskId, $occurrenceDateInput, $userId]);
                        } else {
                            $reopenStmt = $pdo->prepare('DELETE FROM team_recurring_task_completions WHERE recurring_task_id = ? AND occurrence_date = ?');
                            $reopenStmt->execute([$recurringTaskId, $occurrenceDateInput]);
                        }

                        $flashSuccess = 'Tarefa recorrente atualizada com sucesso.';
                    }
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

$navbarClockControl = null;
if (!$isPinOnlyUser) {
    $todayEntriesStmt = $pdo->prepare('SELECT entry_type, occurred_at FROM shopfloor_time_entries WHERE user_id = ? AND date(occurred_at) = date("now", "localtime") ORDER BY occurred_at DESC');
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

    $navbarClockControl = [
        'form_action' => 'dashboard.php',
        'entry_type' => $nextEntryType,
        'button_label' => $clockButtonLabel,
        'button_class' => $clockButtonClass,
        'latest_time_label' => $latestEntryTimeLabel,
    ];
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
$users = $isAdmin ? $pdo->query('SELECT id, name, email, is_admin FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC) : [];

$todayDate = date('Y-m-d');

$scheduledTasksStmt = $pdo->prepare("SELECT tt.id, tt.title, tt.description, tt.status, tt.urgency, tt.due_date, tt.created_at, tt.estimated_minutes, tt.actual_minutes, t.id AS team_id, t.name AS team_name
    FROM team_tickets tt
    INNER JOIN teams t ON t.id = tt.team_id
    INNER JOIN team_members tm ON tm.team_id = t.id
    WHERE tt.assignee_user_id = ? AND tm.user_id = ?
      AND (tt.due_date = ? OR date(tt.created_at) = ?)
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
$scheduledTasksStmt->execute([$userId, $userId, $todayDate, $todayDate]);
$scheduledTasks = $scheduledTasksStmt->fetchAll(PDO::FETCH_ASSOC);
$scheduledTaskIds = array_map(static fn (array $task): int => (int) $task['id'], $scheduledTasks);

$todayDateObj = new DateTimeImmutable($todayDate);
$recurringTasksTodayStmt = $pdo->prepare('SELECT rt.*, t.name AS team_name, p.name AS project_name, a.name AS assignee_name
    FROM team_recurring_tasks rt
    INNER JOIN teams t ON t.id = rt.team_id
    LEFT JOIN projects p ON p.id = rt.project_id
    LEFT JOIN users a ON a.id = rt.assignee_user_id
    WHERE EXISTS (SELECT 1 FROM team_members tm WHERE tm.team_id = rt.team_id AND tm.user_id = ?)
       OR rt.assignee_user_id = ?
       OR EXISTS (
            SELECT 1
            FROM team_recurring_task_overrides o
            WHERE o.recurring_task_id = rt.id
              AND date(o.occurrence_date) = ?
              AND o.assignee_user_id = ?
       )
    ORDER BY rt.created_at ASC');
$recurringTasksTodayStmt->execute([$userId, $userId, $todayDate, $userId]);
$recurringTasksForDashboard = $recurringTasksTodayStmt->fetchAll(PDO::FETCH_ASSOC);

$scheduledRecurringTasks = [];
$scheduledRecurringTaskIds = array_values(array_unique(array_map(static fn (array $task): int => (int) $task['id'], $recurringTasksForDashboard)));
$recurringOverridesByTaskAndDate = [];
$recurringCompletionsByTaskAndDate = [];
$recurringStatusesByTaskAndDate = [];

if ($scheduledRecurringTaskIds) {
    $recurringDateParams = [$todayDate];
    $recurringDateParams = array_merge($recurringDateParams, $scheduledRecurringTaskIds);
    $recurringPlaceholders = implode(',', array_fill(0, count($scheduledRecurringTaskIds), '?'));

    $recurringOverrideStmt = $pdo->prepare('SELECT o.*, p.name AS project_name, a.name AS assignee_name FROM team_recurring_task_overrides o LEFT JOIN projects p ON p.id = o.project_id LEFT JOIN users a ON a.id = o.assignee_user_id WHERE date(o.occurrence_date) = ? AND o.recurring_task_id IN (' . $recurringPlaceholders . ')');
    $recurringOverrideStmt->execute($recurringDateParams);
    foreach ($recurringOverrideStmt->fetchAll(PDO::FETCH_ASSOC) as $override) {
        $overrideDate = date('Y-m-d', strtotime((string) $override['occurrence_date']));
        $overrideKey = (int) $override['recurring_task_id'] . '|' . $overrideDate;
        $recurringOverridesByTaskAndDate[$overrideKey] = [
            'project_id' => $override['project_id'] !== null ? (int) $override['project_id'] : null,
            'assignee_user_id' => $override['assignee_user_id'] !== null ? (int) $override['assignee_user_id'] : null,
            'title' => (string) $override['title'],
            'description' => (string) ($override['description'] ?? ''),
            'time_of_day' => (string) ($override['time_of_day'] ?? ''),
            'project_name' => (string) ($override['project_name'] ?? ''),
            'assignee_name' => (string) ($override['assignee_name'] ?? ''),
        ];
    }

    $recurringCompletionStmt = $pdo->prepare('SELECT c.recurring_task_id, c.occurrence_date, c.completed_at, u.name AS completed_by_name FROM team_recurring_task_completions c INNER JOIN users u ON u.id = c.completed_by WHERE date(c.occurrence_date) = ? AND c.recurring_task_id IN (' . $recurringPlaceholders . ')');
    $recurringCompletionStmt->execute($recurringDateParams);
    foreach ($recurringCompletionStmt->fetchAll(PDO::FETCH_ASSOC) as $completion) {
        $completionDate = date('Y-m-d', strtotime((string) $completion['occurrence_date']));
        $completionKey = (int) $completion['recurring_task_id'] . '|' . $completionDate;
        $recurringCompletionsByTaskAndDate[$completionKey] = [
            'completed_at' => (string) $completion['completed_at'],
            'completed_by_name' => (string) $completion['completed_by_name'],
        ];
    }

    $recurringStatusStmt = $pdo->prepare('SELECT recurring_task_id, occurrence_date, status FROM team_recurring_task_statuses WHERE date(occurrence_date) = ? AND recurring_task_id IN (' . $recurringPlaceholders . ')');
    $recurringStatusStmt->execute($recurringDateParams);
    foreach ($recurringStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $statusRow) {
        $statusDate = date('Y-m-d', strtotime((string) $statusRow['occurrence_date']));
        $statusKey = (int) $statusRow['recurring_task_id'] . '|' . $statusDate;
        $recurringStatusesByTaskAndDate[$statusKey] = (string) $statusRow['status'];
    }
}

foreach ($recurringTasksForDashboard as $recurringTask) {
    $occurrences = recurring_task_occurrences($pdo, $recurringTask, $todayDateObj, $todayDateObj);
    if (!$occurrences) {
        continue;
    }

    $taskId = (int) $recurringTask['id'];
    $occurrenceDate = $occurrences[0]->format('Y-m-d');
    $occurrenceKey = $taskId . '|' . $occurrenceDate;

    $override = $recurringOverridesByTaskAndDate[$occurrenceKey] ?? null;
    $assigneeUserId = $override['assignee_user_id'] ?? ($recurringTask['assignee_user_id'] !== null ? (int) $recurringTask['assignee_user_id'] : null);
    if ($assigneeUserId !== $userId) {
        continue;
    }

    $completion = $recurringCompletionsByTaskAndDate[$occurrenceKey] ?? null;
    $recurringStatus = $recurringStatusesByTaskAndDate[$occurrenceKey] ?? ($completion ? 'done' : 'todo');
    $scheduledRecurringTasks[] = [
        'id' => $taskId,
        'occurrence_date' => $occurrenceDate,
        'title' => $override['title'] ?? (string) $recurringTask['title'],
        'description' => $override['description'] ?? (string) ($recurringTask['description'] ?? ''),
        'time_of_day' => $override['time_of_day'] ?? (string) ($recurringTask['time_of_day'] ?? ''),
        'team_name' => (string) ($recurringTask['team_name'] ?? ''),
        'project_name' => $override['project_name'] ?? (string) ($recurringTask['project_name'] ?? ''),
        'recurrence_label' => recurring_task_recurrence_label($pdo, (string) ($recurringTask['recurrence_type'] ?? 'weekly')),
        'status' => in_array($recurringStatus, ['todo', 'in_progress', 'done'], true) ? $recurringStatus : 'todo',
        'completed_at' => $completion['completed_at'] ?? null,
        'completed_by_name' => $completion['completed_by_name'] ?? null,
    ];
}

usort($scheduledRecurringTasks, static function (array $left, array $right): int {
    $leftTime = $left['time_of_day'] !== '' ? (string) $left['time_of_day'] : '23:59';
    $rightTime = $right['time_of_day'] !== '' ? (string) $right['time_of_day'] : '23:59';
    return strcmp($leftTime, $rightTime);
});

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

$recentProjectNotesStmt = $pdo->prepare("SELECT pn.id, pn.note, pn.created_at, p.id AS project_id, p.name AS project_name, u.name AS author_name
    FROM project_notes pn
    INNER JOIN projects p ON p.id = pn.project_id
    INNER JOIN team_members tm ON tm.team_id = p.team_id
    INNER JOIN users u ON u.id = pn.created_by
    WHERE tm.user_id = ? AND date(pn.created_at) = ?
    ORDER BY pn.created_at DESC
    LIMIT 20");
$recentProjectNotesStmt->execute([$userId, $todayDate]);
$recentProjectNotes = $recentProjectNotesStmt->fetchAll(PDO::FETCH_ASSOC);


$todayProjectTasksStmt = $pdo->prepare("SELECT ta.id, ta.title, ta.status, ta.due_date, p.id AS project_id, p.name AS project_name
    FROM tasks ta
    INNER JOIN projects p ON p.id = ta.project_id
    INNER JOIN team_members tm ON tm.team_id = p.team_id
    WHERE tm.user_id = ? AND ta.assignee_user_id = ?
      AND (ta.due_date = ? OR date(ta.created_at) = ?)
    ORDER BY ta.due_date ASC, ta.created_at DESC");
$todayProjectTasksStmt->execute([$userId, $userId, $todayDate, $todayDate]);
$todayProjectTasks = $todayProjectTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$taskMetrics = [
    'todo' => 0,
    'in_progress' => 0,
    'pending' => 0,
    'done' => 0,
    'overdue' => 0,
    'due_soon' => 0,
    'due_today' => 0,
];

$soonLimitDate = $todayDateObj->modify('+3 days')->format('Y-m-d');
foreach ($scheduledProjectTasks as $projectTask) {
    $taskStatus = (string) ($projectTask['status'] ?? 'todo');
    if ($taskStatus === 'done') {
        $taskMetrics['done']++;
    } elseif ($taskStatus === 'in_progress') {
        $taskMetrics['in_progress']++;
        $taskMetrics['pending']++;
    } else {
        $taskMetrics['todo']++;
        $taskMetrics['pending']++;
    }

    if ($taskStatus === 'done') {
        continue;
    }

    $taskDueDate = trim((string) ($projectTask['due_date'] ?? ''));
    if ($taskDueDate === '') {
        continue;
    }

    if ($taskDueDate < $todayDate) {
        $taskMetrics['overdue']++;
    } elseif ($taskDueDate === $todayDate) {
        $taskMetrics['due_today']++;
    } elseif ($taskDueDate <= $soonLimitDate) {
        $taskMetrics['due_soon']++;
    }
}

$newsMetrics = [
    'new_tasks' => count($todayProjectTasks),
    'new_comments' => count($recentProjectNotes),
    'overdue_projects_tasks' => $taskMetrics['overdue'],
];

$ticketStatuses = ticket_statuses($pdo);
$completedStatusValues = [];
foreach ($ticketStatuses as $status) {
    if (!empty($status['is_completed'])) {
        $completedStatusValues[] = (string) $status['value'];
    }
}

$pendingDepartments = pending_ticket_department_catalog($pdo);
$pendingTasksByPreset = [];
foreach ($pendingDepartments as $pendingDepartment) {
    if (empty($pendingDepartment['enabled'])) {
        continue;
    }

    $pendingTasksByPreset[(string) $pendingDepartment['value']] = [];
}

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

    $presetType = 'team_' . (int) $pendingTask['team_id'];
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

if ($isAdmin) {
    $pendingRecurringTasksStmt = $pdo->query('SELECT rt.id, rt.title, t.id AS team_id, t.name AS team_name
        FROM team_recurring_tasks rt
        INNER JOIN teams t ON t.id = rt.team_id
        ORDER BY rt.created_at DESC');
    $pendingRecurringTasks = $pendingRecurringTasksStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $pendingRecurringTasksStmt = $pdo->prepare('SELECT rt.id, rt.title, t.id AS team_id, t.name AS team_name
        FROM team_recurring_tasks rt
        INNER JOIN teams t ON t.id = rt.team_id
        INNER JOIN team_members tm ON tm.team_id = t.id
        WHERE tm.user_id = ?
        ORDER BY rt.created_at DESC');
    $pendingRecurringTasksStmt->execute([$userId]);
    $pendingRecurringTasks = $pendingRecurringTasksStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pendingRecurringOccurrences = [];
$pendingRecurringTaskIds = [];
foreach ($pendingRecurringTasks as $pendingRecurringTask) {
    $occurrences = recurring_task_occurrences($pdo, $pendingRecurringTask, $todayDateObj, $todayDateObj);
    if (!$occurrences) {
        continue;
    }

    $recurringTaskId = (int) $pendingRecurringTask['id'];
    $pendingRecurringTaskIds[] = $recurringTaskId;
    $pendingRecurringOccurrences[$recurringTaskId] = [
        'title' => (string) $pendingRecurringTask['title'],
        'team_name' => (string) $pendingRecurringTask['team_name'],
        'team_id' => (int) $pendingRecurringTask['team_id'],
    ];
}

if ($pendingRecurringTaskIds) {
    $pendingRecurringTaskIds = array_values(array_unique($pendingRecurringTaskIds));
    $recurringPlaceholders = implode(',', array_fill(0, count($pendingRecurringTaskIds), '?'));

    $recurringCompletionStmt = $pdo->prepare('SELECT recurring_task_id FROM team_recurring_task_completions WHERE date(occurrence_date) = ? AND recurring_task_id IN (' . $recurringPlaceholders . ')');
    $recurringCompletionStmt->execute(array_merge([$todayDate], $pendingRecurringTaskIds));
    $completedRecurringIds = array_map('intval', $recurringCompletionStmt->fetchAll(PDO::FETCH_COLUMN));
    $completedRecurringLookup = array_fill_keys($completedRecurringIds, true);

    foreach ($pendingRecurringOccurrences as $recurringTaskId => $pendingRecurringOccurrence) {
        if (!empty($completedRecurringLookup[$recurringTaskId])) {
            continue;
        }

        $presetType = 'team_' . (int) $pendingRecurringOccurrence['team_id'];
        if (!array_key_exists($presetType, $pendingTasksByPreset)) {
            continue;
        }

        $pendingTasksByPreset[$presetType][] = [
            'title' => (string) $pendingRecurringOccurrence['title'],
            'team_name' => (string) $pendingRecurringOccurrence['team_name'],
            'urgency' => 'Recorrente',
            'due_date' => $todayDate,
            'team_id' => (int) $pendingRecurringOccurrence['team_id'],
        ];
    }
}


$pendingAbsenceApprovals = [];
$pendingVacationApprovals = [];
if ($isAdmin) {
    $pendingAbsenceApprovals = $pdo->query('SELECT a.id, a.status, a.start_date, a.end_date, u.name AS user_name FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id WHERE a.status IN ("Pendente", "Pendente Nível 1", "Pendente Nível 2") ORDER BY a.created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    $pendingVacationApprovals = $pdo->query('SELECT v.id, v.status, v.start_date, v.end_date, u.name AS user_name FROM shopfloor_vacation_requests v INNER JOIN users u ON u.id = v.user_id WHERE v.status = "Pendente" ORDER BY v.created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $profileStmt = $pdo->prepare('SELECT access_profile FROM users WHERE id = ? LIMIT 1');
    $profileStmt->execute([$userId]);
    $accessProfile = (string) $profileStmt->fetchColumn();
    if ($accessProfile === 'Chefias' || $accessProfile === 'RH') {
        $pendingAbsenceApprovals = $pdo->query('SELECT a.id, a.status, a.start_date, a.end_date, u.name AS user_name FROM shopfloor_absence_requests a INNER JOIN users u ON u.id = a.user_id WHERE a.status IN ("Pendente Nível 1", "Pendente Nível 2") ORDER BY a.created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($accessProfile === 'RH') {
        $pendingVacationApprovals = $pdo->query('SELECT v.id, v.status, v.start_date, v.end_date, u.name AS user_name FROM shopfloor_vacation_requests v INNER JOIN users u ON u.id = v.user_id WHERE v.status = "Pendente" ORDER BY v.created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    }
}

$dailyReportSelectedDate = trim((string) ($_GET['daily_report_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyReportSelectedDate)) {
    $dailyReportSelectedDate = date('Y-m-d');
}
$dailyReportProjectFilter = (int) ($_GET['daily_report_project_id'] ?? 0);

$dailyReportTasksSql = 'SELECT t.id, t.title, t.status, t.estimated_minutes, t.actual_minutes, t.updated_at, p.id AS project_id, p.name AS project_name, tm.team_id, teams.name AS team_name '
    . 'FROM tasks t '
    . 'INNER JOIN projects p ON p.id = t.project_id '
    . 'INNER JOIN teams ON teams.id = p.team_id '
    . 'INNER JOIN team_members tm ON tm.team_id = p.team_id AND tm.user_id = ? '
    . 'WHERE DATE(t.updated_at) = ? AND t.updated_by = ?';
$dailyReportTaskParams = [$userId, $dailyReportSelectedDate, $userId];
if ($dailyReportProjectFilter > 0) {
    $dailyReportTasksSql .= ' AND p.id = ?';
    $dailyReportTaskParams[] = $dailyReportProjectFilter;
}
$dailyReportTasksSql .= ' ORDER BY p.name, t.updated_at DESC';
$dailyReportTasksStmt = $pdo->prepare($dailyReportTasksSql);
$dailyReportTasksStmt->execute($dailyReportTaskParams);
$dailyReportProjectTasks = $dailyReportTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$dailyReportRecurringSql = 'SELECT c.recurring_task_id AS id, '
    . 'COALESCE(o.title, rt.title) AS title, '
    . '"done" AS status, '
    . 'NULL AS estimated_minutes, '
    . 'NULL AS actual_minutes, '
    . 'c.completed_at AS updated_at, '
    . 'COALESCE(o.project_id, rt.project_id) AS project_id, '
    . 'p.name AS project_name, '
    . 'rt.team_id, '
    . 'teams.name AS team_name, '
    . 'c.occurrence_date '
    . 'FROM team_recurring_task_completions c '
    . 'INNER JOIN team_recurring_tasks rt ON rt.id = c.recurring_task_id '
    . 'INNER JOIN team_members tm ON tm.team_id = rt.team_id AND tm.user_id = ? '
    . 'INNER JOIN teams ON teams.id = rt.team_id '
    . 'LEFT JOIN team_recurring_task_overrides o ON o.recurring_task_id = c.recurring_task_id AND o.occurrence_date = c.occurrence_date '
    . 'LEFT JOIN projects p ON p.id = COALESCE(o.project_id, rt.project_id) '
    . 'WHERE c.completed_by = ? AND c.occurrence_date = ?';
$dailyReportRecurringParams = [$userId, $userId, $dailyReportSelectedDate];
if ($dailyReportProjectFilter > 0) {
    $dailyReportRecurringSql .= ' AND COALESCE(o.project_id, rt.project_id) = ?';
    $dailyReportRecurringParams[] = $dailyReportProjectFilter;
}
$dailyReportRecurringSql .= ' ORDER BY c.completed_at DESC';
$dailyReportRecurringStmt = $pdo->prepare($dailyReportRecurringSql);
$dailyReportRecurringStmt->execute($dailyReportRecurringParams);
$dailyReportRecurringTasks = $dailyReportRecurringStmt->fetchAll(PDO::FETCH_ASSOC);

$dailyReportTasks = [];
foreach ($dailyReportProjectTasks as $dailyReportTask) {
    $dailyReportTask['entry_type'] = 'project';
    $dailyReportTask['entry_key'] = 'project_' . (int) $dailyReportTask['id'];
    $dailyReportTasks[] = $dailyReportTask;
}
foreach ($dailyReportRecurringTasks as $dailyReportTask) {
    $dailyReportTask['entry_type'] = 'recurring';
    $dailyReportTask['entry_key'] = 'recurring_' . (int) $dailyReportTask['id'] . '_' . (string) $dailyReportTask['occurrence_date'];
    $dailyReportTasks[] = $dailyReportTask;
}

usort($dailyReportTasks, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

$dailyReportProjectsStmt = $pdo->prepare('SELECT DISTINCT p.id, p.name FROM projects p INNER JOIN team_members tm ON tm.team_id = p.team_id WHERE tm.user_id = ? ORDER BY p.name');
$dailyReportProjectsStmt->execute([$userId]);
$dailyReportProjectList = $dailyReportProjectsStmt->fetchAll(PDO::FETCH_ASSOC);
$dailyReportSubmitUrl = 'daily_report.php?date=' . urlencode($dailyReportSelectedDate);
if ($dailyReportProjectFilter > 0) {
    $dailyReportSubmitUrl .= '&project_id=' . $dailyReportProjectFilter;
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
        <div class="col-lg-4">
            <div class="row g-2 text-center mb-2">
                <div class="col-3">
                    <a class="stat-pill stat-pill-link stat-pill-state-todo d-block text-reset text-decoration-none" href="#assignedProjectTasks" data-task-filter="todo">
                        <strong><?= (int) $taskMetrics['todo'] ?></strong><span>Por iniciar</span>
                    </a>
                </div>
                <div class="col-3">
                    <a class="stat-pill stat-pill-link stat-pill-state-progress d-block text-reset text-decoration-none" href="#assignedProjectTasks" data-task-filter="in_progress">
                        <strong><?= (int) $taskMetrics['in_progress'] ?></strong><span>Em trabalho</span>
                    </a>
                </div>
                <div class="col-3">
                    <a class="stat-pill stat-pill-link stat-pill-state-blocked d-block text-reset text-decoration-none" href="#assignedProjectTasks" data-task-filter="pending">
                        <strong><?= (int) $taskMetrics['pending'] ?></strong><span>Bloqueadas</span>
                    </a>
                </div>
                <div class="col-3">
                    <a class="stat-pill stat-pill-link stat-pill-state-total d-block text-reset text-decoration-none" href="#assignedProjectTasks" data-task-filter="all">
                        <strong><?= (int) ($taskMetrics['todo'] + $taskMetrics['in_progress'] + $taskMetrics['pending'] + $taskMetrics['done']) ?></strong><span>Total</span>
                    </a>
                </div>
            </div>
            <div class="row g-2 text-center mb-2">
                <div class="col-4"><div class="stat-pill stat-pill-delay-overdue"><strong><?= (int) $taskMetrics['overdue'] ?></strong><span>Fora de prazo</span></div></div>
                <div class="col-4"><div class="stat-pill stat-pill-delay-soon"><strong><?= (int) $taskMetrics['due_soon'] ?></strong><span>A expirar</span></div></div>
                <div class="col-4"><div class="stat-pill stat-pill-delay-ontime"><strong><?= (int) $taskMetrics['due_today'] ?></strong><span>Na data</span></div></div>
            </div>
            <div class="row g-2 text-center"><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_teams'] ?></strong><span>Equipas</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_projects'] ?></strong><span>Projetos</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= $isAdmin ? (int) $stats['total_users'] : '—' ?></strong><span>Users</span></div></div></div>
        </div>
    </div>
</section>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<?php if ($pendingAbsenceApprovals || $pendingVacationApprovals): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5">Validações rápidas (RH/Chefias)</h2>
        <div class="row g-3">
            <div class="col-lg-6">
                <h3 class="h6">Ausências pendentes</h3>
                <?php if ($pendingAbsenceApprovals): ?><ul class="mb-0"><?php foreach ($pendingAbsenceApprovals as $item): ?><li><?= h((string) $item['user_name']) ?> · <?= h((string) $item['start_date']) ?><?= $item['end_date'] !== $item['start_date'] ? ' → ' . h((string) $item['end_date']) : '' ?> <span class="text-muted">(<?= h((string) $item['status']) ?>)</span></li><?php endforeach; ?></ul><?php else: ?><p class="text-muted mb-0">Sem ausências pendentes.</p><?php endif; ?>
            </div>
            <div class="col-lg-6">
                <h3 class="h6">Férias pendentes</h3>
                <?php if ($pendingVacationApprovals): ?><ul class="mb-0"><?php foreach ($pendingVacationApprovals as $item): ?><li><?= h((string) $item['user_name']) ?> · <?= h((string) $item['start_date']) ?> → <?= h((string) $item['end_date']) ?></li><?php endforeach; ?></ul><?php else: ?><p class="text-muted mb-0">Sem férias pendentes.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="h4 mb-1"><?= (int) $newsMetrics['new_tasks'] ?> Notícias</h2>
                    <p class="small text-muted mb-0">Novas tasks: <strong><?= (int) $newsMetrics['new_tasks'] ?></strong> · Novos comentários: <strong><?= (int) $newsMetrics['new_comments'] ?></strong> · Fora da data: <strong><?= (int) $newsMetrics['overdue_projects_tasks'] ?></strong></p>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ticketModal">Novo ticket</button>
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
                <h3 class="h6">Tickets de equipa para hoje</h3>
                <?php if ($scheduledTasks): ?>
                    <div class="vstack gap-3" id="scheduledTasksList">
                        <?php foreach ($scheduledTasks as $task): ?>
                            <?php $ticketDescription = parse_ticket_description((string) ($task['description'] ?? '')); ?>
                            <form method="post" class="border rounded p-3 vstack gap-2 js-scheduled-task" data-is-completed="<?= ticket_status_is_completed($pdo, (string) $task['status']) ? '1' : '0' ?>">
                                <input type="hidden" name="action" value="update_scheduled_task">
                                <input type="hidden" name="ticket_id" value="<?= (int) $task['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <strong><?= h($task['title']) ?></strong>
                                        <div class="small text-muted">Equipa: <?= h($task['team_name']) ?></div>
                                        <?php if ($ticketDescription['summary'] !== ''): ?>
                                            <p class="small text-muted mb-0 mt-1"><?= h($ticketDescription['summary']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($ticketDescription['details'])): ?>
                                            <ul class="small text-muted mb-0 mt-1 ps-3">
                                                <?php foreach ($ticketDescription['details'] as $detailLine): ?>
                                                    <li><?= h($detailLine) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
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

                <hr>
                <h3 class="h6">Tarefas recorrentes para hoje</h3>
                <?php if ($scheduledRecurringTasks): ?>
                    <div class="vstack gap-2 mb-3">
                        <?php foreach ($scheduledRecurringTasks as $recurringTask): ?>
                            <form method="post" class="border rounded p-2 vstack gap-2">
                                <input type="hidden" name="action" value="update_recurring_scheduled_task">
                                <input type="hidden" name="recurring_task_id" value="<?= (int) $recurringTask['id'] ?>">
                                <input type="hidden" name="occurrence_date" value="<?= h((string) $recurringTask['occurrence_date']) ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <strong><?= h((string) $recurringTask['title']) ?></strong>
                                        <div class="small text-muted">Equipa: <?= h((string) $recurringTask['team_name']) ?><?= $recurringTask['project_name'] !== '' ? ' · Projeto: ' . h((string) $recurringTask['project_name']) : '' ?></div>
                                    </div>
                                    <span class="badge <?= $recurringTask['status'] === 'done' ? 'text-bg-success' : ($recurringTask['status'] === 'in_progress' ? 'text-bg-warning text-dark' : 'text-bg-secondary') ?>"><?= $recurringTask['status'] === 'done' ? 'Concluída' : ($recurringTask['status'] === 'in_progress' ? 'Em curso' : 'Pendente') ?></span>
                                </div>
                                <div class="small text-muted">Recorrência: <?= h((string) $recurringTask['recurrence_label']) ?> · Hora: <?= h($recurringTask['time_of_day'] !== '' ? (string) $recurringTask['time_of_day'] : 'Sem hora') ?></div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-8">
                                        <label class="form-label mb-1 small">Estado</label>
                                        <select class="form-select form-select-sm" name="status">
                                            <option value="todo" <?= $recurringTask['status'] === 'todo' ? 'selected' : '' ?>>Pendente</option>
                                            <option value="in_progress" <?= $recurringTask['status'] === 'in_progress' ? 'selected' : '' ?>>Em curso</option>
                                            <option value="done" <?= $recurringTask['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-sm btn-primary w-100">Guardar</button>
                                    </div>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-3">Sem tarefas recorrentes atribuídas para hoje.</p>
                <?php endif; ?>

                <hr>
                <h3 class="h6">Tarefas de projeto atribuídas hoje</h3>
                <?php if (!$todayProjectTasks): ?>
                    <p class="text-muted mb-0">Sem tarefas de projeto previstas para hoje.</p>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($todayProjectTasks as $projectTask): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <strong><?= h((string) $projectTask['title']) ?></strong>
                                    <div class="small text-muted"><?= h((string) $projectTask['project_name']) ?> · <?= h(status_label((string) $projectTask['status'])) ?></div>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="project.php?id=<?= (int) $projectTask['project_id'] ?>">Abrir</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted mb-3">Sem tickets de equipa para hoje.</p>
                    <h3 class="h6">Tarefas recorrentes para hoje</h3>
                    <?php if ($scheduledRecurringTasks): ?>
                        <div class="vstack gap-2 mb-0">
                            <?php foreach ($scheduledRecurringTasks as $recurringTask): ?>
                                <form method="post" class="border rounded p-2 vstack gap-2">
                                    <input type="hidden" name="action" value="update_recurring_scheduled_task">
                                    <input type="hidden" name="recurring_task_id" value="<?= (int) $recurringTask['id'] ?>">
                                    <input type="hidden" name="occurrence_date" value="<?= h((string) $recurringTask['occurrence_date']) ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <strong><?= h((string) $recurringTask['title']) ?></strong>
                                            <div class="small text-muted">Equipa: <?= h((string) $recurringTask['team_name']) ?><?= $recurringTask['project_name'] !== '' ? ' · Projeto: ' . h((string) $recurringTask['project_name']) : '' ?></div>
                                        </div>
                                        <span class="badge <?= $recurringTask['status'] === 'done' ? 'text-bg-success' : ($recurringTask['status'] === 'in_progress' ? 'text-bg-warning text-dark' : 'text-bg-secondary') ?>"><?= $recurringTask['status'] === 'done' ? 'Concluída' : ($recurringTask['status'] === 'in_progress' ? 'Em curso' : 'Pendente') ?></span>
                                    </div>
                                    <div class="small text-muted">Recorrência: <?= h((string) $recurringTask['recurrence_label']) ?> · Hora: <?= h($recurringTask['time_of_day'] !== '' ? (string) $recurringTask['time_of_day'] : 'Sem hora') ?></div>
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-8">
                                            <label class="form-label mb-1 small">Estado</label>
                                            <select class="form-select form-select-sm" name="status">
                                                <option value="todo" <?= $recurringTask['status'] === 'todo' ? 'selected' : '' ?>>Pendente</option>
                                                <option value="in_progress" <?= $recurringTask['status'] === 'in_progress' ? 'selected' : '' ?>>Em curso</option>
                                                <option value="done" <?= $recurringTask['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <button class="btn btn-sm btn-primary w-100">Guardar</button>
                                        </div>
                                    </div>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Sem tarefas recorrentes atribuídas para hoje.</p>
                    <?php endif; ?>
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
                    <?php if (!$pendingTasksByPreset): ?>
                        <p class="text-muted mb-0">Sem departamentos ativos para mostrar pendentes. Configure em Empresa e Branding.</p>
                    <?php else: ?>
                        <?php foreach ($pendingDepartments as $pendingDepartment): ?>
                            <?php
                                if (empty($pendingDepartment['enabled'])) {
                                    continue;
                                }
                                $departmentValue = (string) $pendingDepartment['value'];
                                $departmentTasks = $pendingTasksByPreset[$departmentValue] ?? [];
                            ?>
                            <div class="border rounded p-3">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <h3 class="h6 mb-0"><?= h((string) $pendingDepartment['label']) ?></h3>
                                    <span class="badge text-bg-light border"><?= (int) count($departmentTasks) ?> pendente(s)</span>
                                </div>
                                <?php if (!$departmentTasks): ?>
                                    <p class="text-muted mb-0">Não existem tarefas pendentes neste grupo.</p>
                                <?php else: ?>
                                    <ul class="list-group">
                                        <?php foreach ($departmentTasks as $task): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-start gap-3">
                                                <div>
                                                    <strong><?= h((string) $task['title']) ?></strong>
                                                    <div class="small text-muted"><?= h((string) $task['team_name']) ?> · <?= h((string) $task['urgency']) ?><?= $task['due_date'] !== '' ? ' · Até ' . h((string) $task['due_date']) : '' ?></div>
                                                </div>
                                                <a class="btn btn-sm btn-outline-primary" href="team.php?id=<?= (int) $task['team_id'] ?>">Abrir</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm soft-card mb-4" id="assignedProjectTasks">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Tarefas de projetos atribuídas</h2>
                <span class="badge text-bg-light border" id="projectTaskFilterBadge">Filtro: Todas</span>
            </div>
            <div class="card-body p-4">
                <?php if ($scheduledProjectTasks): ?>
                    <div class="vstack gap-3">
                        <?php foreach ($scheduledProjectTasks as $task): ?>
                            <?php
                                $taskDueDate = trim((string) ($task['due_date'] ?? ''));
                                $taskDueClass = 'none';
                                if ($taskDueDate !== '' && (string) $task['status'] !== 'done') {
                                    if ($taskDueDate < $todayDate) {
                                        $taskDueClass = 'overdue';
                                    } elseif ($taskDueDate === $todayDate) {
                                        $taskDueClass = 'today';
                                    } elseif ($taskDueDate <= $soonLimitDate) {
                                        $taskDueClass = 'soon';
                                    }
                                }
                            ?>
                            <form method="post" class="border rounded p-3 vstack gap-2 js-project-task" data-task-status="<?= h((string) $task['status']) ?>" data-task-due-state="<?= h($taskDueClass) ?>">
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

        <div class="card shadow-sm soft-card h-100">
            <div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h4 mb-0">Novas notas de projetos</h2></div>
            <div class="card-body p-4">
                <p class="small text-muted">Notas publicadas hoje em projetos onde estás envolvido.</p>
                <?php if (!$recentProjectNotes): ?>
                    <p class="text-muted mb-0">Sem notas novas hoje.</p>
                <?php else: ?>
                    <div class="vstack gap-2">
                        <?php foreach ($recentProjectNotes as $projectNote): ?>
                            <div class="border rounded p-2 bg-light small">
                                <strong><?= h($projectNote['project_name']) ?></strong>
                                <div><?= h($projectNote['note']) ?></div>
                                <div class="text-muted"><?= h($projectNote['author_name']) ?> · <?= h(date('d/m/Y H:i', strtotime((string) $projectNote['created_at']))) ?></div>
                                <a href="project.php?id=<?= (int) $projectNote['project_id'] ?>" class="btn btn-link btn-sm px-0">Abrir projeto</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($isAdmin): ?>
        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Gestão de utilizadores</h2><a class="btn btn-sm btn-outline-primary" href="users.php">Abrir página</a></div>
            <div class="card-body px-4"><p class="small text-muted mb-0">A consulta de utilizadores foi movida para uma página dedicada com paginação.</p></div>
        </div>
        <?php endif; ?>

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

<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm soft-card" id="dailyReportPanel">
            <div class="card-header bg-white border-0 pt-4 px-4">
        <h2 class="h3 mb-1">Relatório diário por colaborador</h2>
        <p class="small text-muted mb-0">Preenche e envia o relatório diário diretamente a partir da dashboard.</p>
            </div>
            <div class="card-body px-4 pb-4">
        <form method="get" class="row g-2 mb-3" action="dashboard.php#dailyReportPanel">
            <div class="col-md-3"><input class="form-control" type="date" name="daily_report_date" value="<?= h($dailyReportSelectedDate) ?>"></div>
            <div class="col-md-5"><select name="daily_report_project_id" class="form-select"><option value="0">Todos os projetos</option><?php foreach ($dailyReportProjectList as $dailyReportProject): ?><option value="<?= (int) $dailyReportProject['id'] ?>" <?= $dailyReportProjectFilter === (int) $dailyReportProject['id'] ? 'selected' : '' ?>><?= h($dailyReportProject['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filtrar</button></div>
        </form>

        <form method="post" action="<?= h($dailyReportSubmitUrl) ?>" class="card border-0 bg-transparent">
            <input type="hidden" name="action" value="send_daily_report">
            <div class="table-responsive"><table class="table align-middle"><thead><tr><th></th><th>Projeto</th><th>Tarefa alterada</th><th>Previsto</th><th>Real</th><th>Discrepância</th><th>Atualizada</th></tr></thead><tbody>
                <?php foreach ($dailyReportTasks as $dailyReportTask): $estimated = $dailyReportTask['estimated_minutes'] !== null ? (int) $dailyReportTask['estimated_minutes'] : null; $actual = $dailyReportTask['actual_minutes'] !== null ? (int) $dailyReportTask['actual_minutes'] : null; $delta = task_time_delta($estimated, $actual); ?>
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="task_ids[]" value="<?= h((string) $dailyReportTask['entry_key']) ?>" checked></td>
                        <td><?= h((string) ($dailyReportTask['project_name'] ?: $dailyReportTask['team_name'])) ?></td><td><?= h($dailyReportTask['title']) ?><?php if (($dailyReportTask['entry_type'] ?? '') === 'recurring'): ?> <span class="badge text-bg-success">Recorrente concluída</span><?php endif; ?></td><td><?= h(format_minutes($estimated)) ?></td><td><?= h(format_minutes($actual)) ?></td><td><?= $delta === null ? '-' : ($delta > 0 ? '+' : '-') . h(format_minutes(abs($delta))) ?></td><td><?= h((string) $dailyReportTask['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dailyReportTasks): ?><tr><td colspan="7" class="text-muted">Sem tarefas alteradas por si nesta data.</td></tr><?php endif; ?>
            </tbody></table></div>
            <label class="form-label">Resumo para o líder</label>
            <textarea name="summary" class="form-control mb-3" rows="3" placeholder="Descreva o que foi feito durante o dia..."></textarea>
            <div class="d-flex justify-content-start">
                <button class="btn btn-primary" <?= !$dailyReportTasks ? 'disabled' : '' ?>>Enviar relatório e gerar versão A4</button>
            </div>
        </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Novo ticket de equipa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body vstack gap-2">
        <input type="hidden" name="action" value="create_team_ticket">
        <div><label class="form-label mb-1">Equipa responsável</label><select class="form-select" name="team_id" id="dashboardTeamSelect" required><option value="">Selecionar equipa</option><?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>"><?= h($team['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label mb-1">Nome do pedido</label><input class="form-control" name="title" id="dashboardTicketTitle" placeholder="Ex.: Corrigir integração com faturação" required></div>
        <div><label class="form-label mb-1">Tipo de pedido</label><select class="form-select" name="ticket_type" id="dashboardTicketType"><option value="">Geral</option><?php foreach ($ticketTypeTemplates as $templateKey => $template): ?><option value="<?= h($templateKey) ?>"><?= h($template['label']) ?></option><?php endforeach; ?></select><div id="dashboardTicketTypeHint" class="form-text d-none"></div></div>
        <div id="dashboardTicketTypeFields" class="vstack gap-2"></div>
        <div><label class="form-label mb-1">Descrição do ticket</label><textarea class="form-control" name="description" id="dashboardTicketDescription" rows="5" placeholder="Descreve o pedido com contexto e impacto" required></textarea></div>
        <div class="row g-2"><div class="col-md-6"><label class="form-label mb-1">Nível de urgência</label><select class="form-select" name="urgency" id="dashboardUrgencySelect"><option value="Baixa">Baixa</option><option value="Média" selected>Média</option><option value="Alta">Alta</option><option value="Crítica">Crítica</option></select></div><div class="col-md-6"><label class="form-label mb-1">Data limite</label><input class="form-control" type="date" name="due_date" required></div></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Criar ticket</button></div>
    </form>
  </div>
</div>

<script>
const dashboardTicketTypeTemplates = <?= json_encode($ticketTypeTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const dashboardTeamTicketPresets = <?= json_encode($teamTicketPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
const projectTaskRows = document.querySelectorAll('.js-project-task');
const projectTaskFilterBadge = document.getElementById('projectTaskFilterBadge');
const taskFilterTriggers = document.querySelectorAll('[data-task-filter]');

function updateProjectTaskFilterBadge(labelText) {
    if (!projectTaskFilterBadge) {
        return;
    }

    projectTaskFilterBadge.textContent = `Filtro: ${labelText}`;
}

function matchesProjectTaskFilter(taskElement, taskFilter) {
    if (!taskElement || taskFilter === 'all') {
        return true;
    }

    const status = taskElement.dataset.taskStatus || 'todo';
    if (taskFilter === 'pending') {
        return status === 'todo' || status === 'in_progress';
    }

    return status === taskFilter;
}

function applyProjectTaskFilter(taskFilter) {
    if (projectTaskRows.length === 0) {
        return;
    }

    projectTaskRows.forEach((taskRow) => {
        taskRow.classList.toggle('d-none', !matchesProjectTaskFilter(taskRow, taskFilter));
    });

    const filterLabels = {
        all: 'Todas',
        todo: 'Por iniciar',
        in_progress: 'Em trabalho',
        pending: 'Bloqueadas',
        done: 'Concluídos',
    };
    updateProjectTaskFilterBadge(filterLabels[taskFilter] || 'Todas');
}


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

if (taskFilterTriggers.length > 0) {
    taskFilterTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const taskFilter = trigger.dataset.taskFilter || 'all';
            applyProjectTaskFilter(taskFilter);
            taskFilterTriggers.forEach((item) => item.classList.remove('active'));
            trigger.classList.add('active');
            if (event) {
                event.preventDefault();
            }
            const target = document.getElementById('assignedProjectTasks');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
}

applyProjectTaskFilter('all');

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

</script>

<div class="modal fade" id="teamModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_team"><div class="modal-header"><h5 class="modal-title">Criar Equipa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome da equipa" required><textarea class="form-control" name="description" placeholder="Descrição"></textarea></div><div class="modal-footer"><button class="btn btn-primary">Criar</button></div></form></div></div>


<?php require __DIR__ . '/partials/footer.php'; ?>
