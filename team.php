<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$teamId = (int) ($_GET['id'] ?? 0);
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;
$ticketStatuses = ticket_statuses($pdo);

function parse_ticket_description(string $description): array
{
    $normalized = str_replace("\r\n", "\n", trim($description));
    if ($normalized == '') {
        return ['summary' => '', 'details_title' => '', 'details' => []];
    }

    if (!preg_match('/\n\nDetalhes de ([^\n:]+):\n((?:- .*\n?)+)/u', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
        return ['summary' => $normalized, 'details_title' => '', 'details' => []];
    }

    $markerOffset = (int) $matches[0][1];
    $summary = trim(substr($normalized, 0, $markerOffset));
    $detailsTitle = trim($matches[1][0]);
    $detailsBlock = trim($matches[2][0]);

    $details = [];
    foreach (preg_split('/\n+/', $detailsBlock) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '- ')) {
            $line = trim(substr($line, 2));
        }
        if ($line !== '') {
            $details[] = $line;
        }
    }

    return [
        'summary' => $summary,
        'details_title' => $detailsTitle,
        'details' => $details,
    ];
}

if (!$teamId || !team_accessible($pdo, $teamId, $userId)) {
    http_response_code(403);
    exit('Acesso negado à equipa.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $memberRoleStmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
    $memberRoleStmt->execute([$teamId, $userId]);
    $memberRole = (string) $memberRoleStmt->fetchColumn();
    $canManageProjects = $isAdmin || in_array($memberRole, ['owner', 'leader'], true);

    if ($action === 'create_project') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $leaderUserId = (int) ($_POST['leader_user_id'] ?? $userId);

        if ($name !== '') {
            $leaderCheckStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
            $leaderCheckStmt->execute([$teamId, $leaderUserId]);
            if (!$leaderCheckStmt->fetchColumn()) {
                $leaderUserId = $userId;
            }

            $stmt = $pdo->prepare('INSERT INTO projects(team_id, name, description, created_by, leader_user_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$teamId, $name, $description, $userId, $leaderUserId]);
        }
    }

    if ($action === 'update_project' && $canManageProjects) {
        $targetProjectId = (int) ($_POST['project_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $leaderUserId = (int) ($_POST['leader_user_id'] ?? $userId);

        $projectStmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND team_id = ?');
        $projectStmt->execute([$targetProjectId, $teamId]);

        if (!$projectStmt->fetchColumn()) {
            $flashError = 'Projeto inválido para edição.';
        } elseif ($name === '') {
            $flashError = 'O projeto deve ter um nome.';
        } else {
            $leaderCheckStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
            $leaderCheckStmt->execute([$teamId, $leaderUserId]);
            if (!$leaderCheckStmt->fetchColumn()) {
                $leaderUserId = null;
            }

            $stmt = $pdo->prepare('UPDATE projects SET name = ?, description = ?, leader_user_id = ? WHERE id = ? AND team_id = ?');
            $stmt->execute([$name, $description, $leaderUserId, $targetProjectId, $teamId]);
            $flashSuccess = 'Projeto atualizado com sucesso.';
        }
    }

    if ($action === 'delete_project' && $canManageProjects) {
        $targetProjectId = (int) ($_POST['project_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ? AND team_id = ?');
        $stmt->execute([$targetProjectId, $teamId]);
        if ($stmt->rowCount() > 0) {
            $flashSuccess = 'Projeto eliminado com sucesso.';
        } else {
            $flashError = 'Não foi possível eliminar o projeto selecionado.';
        }
    }

    if ($action === 'save_task_creation_fields_config' && $canManageProjects) {
        $targetProjectId = (int) ($_POST['config_project_id'] ?? 0);
        $projectIdValue = null;
        if ($targetProjectId > 0) {
            $projectCheckStmt = $pdo->prepare('SELECT 1 FROM projects WHERE id = ? AND team_id = ?');
            $projectCheckStmt->execute([$targetProjectId, $teamId]);
            if (!$projectCheckStmt->fetchColumn()) {
                $flashError = 'Projeto inválido para configuração de campos.';
            } else {
                $projectIdValue = $targetProjectId;
            }
        }

        if (!$flashError) {
            $catalog = task_creation_field_catalog_for_team($pdo, $teamId);
            $visibilityInput = $_POST['visible_fields'] ?? [];
            $requiredInput = $_POST['required_fields'] ?? [];
            $visibleFields = is_array($visibilityInput) ? array_map('strval', $visibilityInput) : [];
            $requiredFields = is_array($requiredInput) ? array_map('strval', $requiredInput) : [];

            $deleteStmt = $pdo->prepare('DELETE FROM task_creation_field_rules WHERE team_id = ? AND project_id IS ?');
            $deleteStmt->execute([$teamId, $projectIdValue]);

            $insertStmt = $pdo->prepare('INSERT INTO task_creation_field_rules(team_id, project_id, field_key, is_visible, is_required, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            foreach ($catalog as $fieldKey => $meta) {
                $isVisible = in_array($fieldKey, $visibleFields, true);
                $isRequired = $isVisible && in_array($fieldKey, $requiredFields, true);
                $defaultVisible = !empty($meta['default_visible']);
                $defaultRequired = !empty($meta['default_required']);

                if ($isVisible === $defaultVisible && $isRequired === $defaultRequired) {
                    continue;
                }

                $insertStmt->execute([$teamId, $projectIdValue, $fieldKey, $isVisible ? 1 : 0, $isRequired ? 1 : 0]);
            }

            $flashSuccess = $projectIdValue ? 'Configuração de campos guardada para o projeto.' : 'Configuração de campos guardada para a equipa.';
        }
    }


    if ($action === 'create_task_custom_field' && $canManageProjects) {
        $label = trim((string) ($_POST['custom_label'] ?? ''));
        $fieldType = (string) ($_POST['custom_type'] ?? 'text');
        $optionsRaw = trim((string) ($_POST['custom_options'] ?? ''));
        $isVisible = (int) ($_POST['custom_visible'] ?? 0) === 1;
        $isRequired = $isVisible && (int) ($_POST['custom_required'] ?? 0) === 1;

        if ($label === '') {
            $flashError = 'Indique o nome do novo campo.';
        } elseif (!in_array($fieldType, ['text', 'textarea', 'number', 'date', 'select'], true)) {
            $flashError = 'Tipo de campo inválido.';
        } else {
            $fieldKeyBase = task_creation_field_key_from_label($label);
            $fieldKey = $fieldKeyBase;
            $suffix = 2;
            $fieldExistsStmt = $pdo->prepare('SELECT 1 FROM task_custom_fields WHERE team_id = ? AND field_key = ?');
            while (true) {
                $fieldExistsStmt->execute([$teamId, $fieldKey]);
                if (!$fieldExistsStmt->fetchColumn()) {
                    break;
                }
                $fieldKey = $fieldKeyBase . '_' . $suffix;
                $suffix++;
            }

            $options = [];
            if ($fieldType === 'select' && $optionsRaw !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $optionsRaw) ?: [] as $line) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $options[] = $line;
                    }
                }
            }

            $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM task_custom_fields WHERE team_id = ?');
            $maxOrderStmt->execute([$teamId]);
            $sortOrder = (int) $maxOrderStmt->fetchColumn() + 10;

            $insertCustomStmt = $pdo->prepare('INSERT INTO task_custom_fields(team_id, field_key, label, field_type, options_json, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insertCustomStmt->execute([$teamId, $fieldKey, $label, $fieldType, $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null, $sortOrder, $userId]);

            $insertRuleStmt = $pdo->prepare('INSERT INTO task_creation_field_rules(team_id, project_id, field_key, is_visible, is_required, updated_at) VALUES (?, NULL, ?, ?, ?, CURRENT_TIMESTAMP)');
            $insertRuleStmt->execute([$teamId, $fieldKey, $isVisible ? 1 : 0, $isRequired ? 1 : 0]);

            $flashSuccess = 'Novo campo criado com sucesso.';
        }
    }

    if ($action === 'delete_task_custom_field' && $canManageProjects) {
        $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
        if ($fieldKey !== '') {
            $deleteFieldStmt = $pdo->prepare('UPDATE task_custom_fields SET is_active = 0 WHERE team_id = ? AND field_key = ?');
            $deleteFieldStmt->execute([$teamId, $fieldKey]);

            $deleteRulesStmt = $pdo->prepare('DELETE FROM task_creation_field_rules WHERE team_id = ? AND field_key = ?');
            $deleteRulesStmt->execute([$teamId, $fieldKey]);
            $flashSuccess = 'Campo removido.';
        }
    }

    if ($action === 'invite_member') {
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'member';
        if (!in_array($role, ['member', 'leader'], true)) {
            $role = 'member';
        }

        if ($email !== '') {
            $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $userStmt->execute([$email]);
            $targetId = $userStmt->fetchColumn();
            if ($targetId) {
                $insert = $pdo->prepare('INSERT OR IGNORE INTO team_members(team_id, user_id, role) VALUES (?, ?, ?)');
                $insert->execute([$teamId, $targetId, $role]);
            }
        }
    }

    if ($action === 'add_team_note') {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            $stmt = $pdo->prepare('INSERT INTO team_notes(team_id, note, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$teamId, $note, $userId]);
            $flashSuccess = 'Nota da equipa adicionada.';
        }
    }

    if ($action === 'create_recurring_task' && $canManageProjects) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $recurrenceType = (string) ($_POST['recurrence_type'] ?? 'weekly');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $timeOfDay = trim($_POST['time_of_day'] ?? '');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $checklistTemplateId = (int) ($_POST['checklist_template_id'] ?? 0);

        if (!array_key_exists($recurrenceType, recurring_task_recurrence_options($pdo))) {
            $recurrenceType = 'weekly';
        }

        if ($title === '') {
            $flashError = 'A tarefa recorrente deve ter um título.';
        } else {
            $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
            if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
                $flashError = 'Data de início inválida para a tarefa recorrente.';
            } else {
                if ($timeOfDay !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
                    $timeOfDay = '';
                }

                $projectIdValue = null;
                if ($projectId > 0) {
                    $projectCheckStmt = $pdo->prepare('SELECT 1 FROM projects WHERE id = ? AND team_id = ?');
                    $projectCheckStmt->execute([$projectId, $teamId]);
                    if ($projectCheckStmt->fetchColumn()) {
                        $projectIdValue = $projectId;
                    }
                }

                $assigneeUserIdValue = null;
                if ($assigneeUserId > 0) {
                    $assigneeStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
                    $assigneeStmt->execute([$teamId, $assigneeUserId]);
                    if ($assigneeStmt->fetchColumn()) {
                        $assigneeUserIdValue = $assigneeUserId;
                    }
                }

                $checklistTemplateIdValue = null;
                if ($checklistTemplateId > 0) {
                    $templateStmt = $pdo->prepare('SELECT 1 FROM checklist_templates WHERE id = ?');
                    $templateStmt->execute([$checklistTemplateId]);
                    if ($templateStmt->fetchColumn()) {
                        $checklistTemplateIdValue = $checklistTemplateId;
                    }
                }

                $weekday = (int) $startDateObj->format('N');
                $stmt = $pdo->prepare('INSERT INTO team_recurring_tasks(team_id, project_id, assignee_user_id, checklist_template_id, title, description, weekday, recurrence_type, start_date, time_of_day, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$teamId, $projectIdValue, $assigneeUserIdValue, $checklistTemplateIdValue, $title, $description, $weekday, $recurrenceType, $startDateObj->format('Y-m-d'), $timeOfDay !== '' ? $timeOfDay : null, $userId]);
                $flashSuccess = 'Tarefa recorrente adicionada ao calendário da equipa.';
            }
        }
    }

    if ($action === 'update_recurring_task' && $canManageProjects) {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $recurrenceType = (string) ($_POST['recurrence_type'] ?? 'weekly');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $timeOfDay = trim($_POST['time_of_day'] ?? '');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $checklistTemplateId = (int) ($_POST['checklist_template_id'] ?? 0);
        $updateScope = (string) ($_POST['update_scope'] ?? 'all');
        $occurrenceDateInput = trim($_POST['occurrence_date'] ?? '');

        if (!array_key_exists($recurrenceType, recurring_task_recurrence_options($pdo))) {
            $recurrenceType = 'weekly';
        }

        if (!in_array($updateScope, ['single', 'all'], true)) {
            $updateScope = 'all';
        }

        if ($title === '') {
            $flashError = 'A tarefa recorrente deve ter um título.';
        } else {
            $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
            if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
                $flashError = 'Data de início inválida para a tarefa recorrente.';
            } else {
                if ($timeOfDay !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
                    $timeOfDay = '';
                }

                $projectIdValue = null;
                if ($projectId > 0) {
                    $projectCheckStmt = $pdo->prepare('SELECT 1 FROM projects WHERE id = ? AND team_id = ?');
                    $projectCheckStmt->execute([$projectId, $teamId]);
                    if ($projectCheckStmt->fetchColumn()) {
                        $projectIdValue = $projectId;
                    }
                }

                $assigneeUserIdValue = null;
                if ($assigneeUserId > 0) {
                    $assigneeStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
                    $assigneeStmt->execute([$teamId, $assigneeUserId]);
                    if ($assigneeStmt->fetchColumn()) {
                        $assigneeUserIdValue = $assigneeUserId;
                    }
                }

                $checklistTemplateIdValue = null;
                if ($checklistTemplateId > 0) {
                    $templateStmt = $pdo->prepare('SELECT 1 FROM checklist_templates WHERE id = ?');
                    $templateStmt->execute([$checklistTemplateId]);
                    if ($templateStmt->fetchColumn()) {
                        $checklistTemplateIdValue = $checklistTemplateId;
                    }
                }

                if ($updateScope === 'single') {
                    $occurrenceDate = DateTimeImmutable::createFromFormat('Y-m-d', $occurrenceDateInput);

                    if (!$occurrenceDate || $occurrenceDate->format('Y-m-d') !== $occurrenceDateInput) {
                        $flashError = 'Data inválida para atualizar apenas esta ocorrência.';
                    } else {
                        $taskStmt = $pdo->prepare('SELECT * FROM team_recurring_tasks WHERE id = ? AND team_id = ?');
                        $taskStmt->execute([$recurringTaskId, $teamId]);
                        $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$task) {
                            $flashError = 'Não foi possível atualizar a tarefa recorrente selecionada.';
                        } else {
                            $occurrences = recurring_task_occurrences($pdo, $task, $occurrenceDate, $occurrenceDate);
                            if (!$occurrences) {
                                $flashError = 'Esta tarefa não está agendada para a data selecionada.';
                            } else {
                                $occurrenceDateValue = $occurrenceDate->format('Y-m-d');
                                $existingOverrideStmt = $pdo->prepare('SELECT id FROM team_recurring_task_overrides WHERE recurring_task_id = ? AND occurrence_date = ?');
                                $existingOverrideStmt->execute([$recurringTaskId, $occurrenceDateValue]);
                                $existingOverrideId = $existingOverrideStmt->fetchColumn();

                                if ($existingOverrideId) {
                                    $overrideStmt = $pdo->prepare('UPDATE team_recurring_task_overrides SET project_id = ?, assignee_user_id = ?, title = ?, description = ?, time_of_day = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                                    $overrideStmt->execute([$projectIdValue, $assigneeUserIdValue, $title, $description, $timeOfDay !== '' ? $timeOfDay : null, (int) $existingOverrideId]);
                                } else {
                                    $overrideStmt = $pdo->prepare('INSERT INTO team_recurring_task_overrides(recurring_task_id, occurrence_date, project_id, assignee_user_id, title, description, time_of_day, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
                                    $overrideStmt->execute([$recurringTaskId, $occurrenceDateValue, $projectIdValue, $assigneeUserIdValue, $title, $description, $timeOfDay !== '' ? $timeOfDay : null, $userId]);
                                }

                                $markCompletedStmt = $pdo->prepare('INSERT OR IGNORE INTO team_recurring_task_completions(recurring_task_id, occurrence_date, completed_by) VALUES (?, ?, ?)');
                                $markCompletedStmt->execute([$recurringTaskId, $occurrenceDateValue, $userId]);

                                if ($markCompletedStmt->rowCount() > 0) {
                                    $flashSuccess = 'Ocorrência desta tarefa recorrente atualizada e concluída com sucesso.';
                                } else {
                                    $flashSuccess = 'Ocorrência desta tarefa recorrente atualizada com sucesso.';
                                }
                            }
                        }
                    }
                } else {
                    $weekday = (int) $startDateObj->format('N');
                    $stmt = $pdo->prepare('UPDATE team_recurring_tasks SET project_id = ?, assignee_user_id = ?, checklist_template_id = ?, title = ?, description = ?, weekday = ?, recurrence_type = ?, start_date = ?, time_of_day = ? WHERE id = ? AND team_id = ?');
                    $stmt->execute([$projectIdValue, $assigneeUserIdValue, $checklistTemplateIdValue, $title, $description, $weekday, $recurrenceType, $startDateObj->format('Y-m-d'), $timeOfDay !== '' ? $timeOfDay : null, $recurringTaskId, $teamId]);

                    if ($stmt->rowCount() > 0) {
                        $flashSuccess = 'Tarefa recorrente atualizada com sucesso.';
                    } else {
                        $flashError = 'Não foi possível atualizar a tarefa recorrente selecionada.';
                    }
                }
            }
        }
    }

    if ($action === 'complete_recurring_task') {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $occurrenceDateInput = trim($_POST['occurrence_date'] ?? '');
        $occurrenceDate = DateTimeImmutable::createFromFormat('Y-m-d', $occurrenceDateInput);

        if (!$occurrenceDate || $occurrenceDate->format('Y-m-d') !== $occurrenceDateInput) {
            $flashError = 'Data inválida para concluir tarefa recorrente.';
        } else {
            $taskStmt = $pdo->prepare('SELECT * FROM team_recurring_tasks WHERE id = ? AND team_id = ?');
            $taskStmt->execute([$recurringTaskId, $teamId]);
            $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                $flashError = 'Tarefa recorrente inválida.';
            } else {
                $occurrences = recurring_task_occurrences($pdo, $task, $occurrenceDate, $occurrenceDate);
                if (!$occurrences) {
                    $flashError = 'Esta tarefa não está agendada para a data selecionada.';
                } else {
                    $completeStmt = $pdo->prepare('INSERT OR IGNORE INTO team_recurring_task_completions(recurring_task_id, occurrence_date, completed_by) VALUES (?, ?, ?)');
                    $completeStmt->execute([$recurringTaskId, $occurrenceDate->format('Y-m-d'), $userId]);

                    if ($completeStmt->rowCount() > 0) {
                        $flashSuccess = 'Tarefa recorrente concluída com sucesso.';
                    } else {
                        $flashError = 'Esta ocorrência já tinha sido concluída.';
                    }
                }
            }
        }
    }

    if ($action === 'reopen_recurring_task') {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $occurrenceDateInput = trim($_POST['occurrence_date'] ?? '');
        $occurrenceDate = DateTimeImmutable::createFromFormat('Y-m-d', $occurrenceDateInput);

        if (!$occurrenceDate || $occurrenceDate->format('Y-m-d') !== $occurrenceDateInput) {
            $flashError = 'Data inválida para reabrir tarefa recorrente.';
        } else {
            $taskStmt = $pdo->prepare('SELECT * FROM team_recurring_tasks WHERE id = ? AND team_id = ?');
            $taskStmt->execute([$recurringTaskId, $teamId]);
            $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                $flashError = 'Tarefa recorrente inválida.';
            } else {
                $occurrences = recurring_task_occurrences($pdo, $task, $occurrenceDate, $occurrenceDate);
                if (!$occurrences) {
                    $flashError = 'Esta tarefa não está agendada para a data selecionada.';
                } else {
                    $reopenStmt = $pdo->prepare('DELETE FROM team_recurring_task_completions WHERE recurring_task_id = ? AND occurrence_date = ?');
                    $reopenStmt->execute([$recurringTaskId, $occurrenceDate->format('Y-m-d')]);

                    if ($reopenStmt->rowCount() > 0) {
                        $flashSuccess = 'Tarefa recorrente reaberta com sucesso.';
                    } else {
                        $flashError = 'Esta ocorrência já estava em aberto.';
                    }
                }
            }
        }
    }


    if ($action === 'save_recurring_task_checklist_state') {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $occurrenceDateInput = trim((string) ($_POST['occurrence_date'] ?? ''));
        $checkedItems = $_POST['checked_items'] ?? [];
        if (!is_array($checkedItems)) {
            $checkedItems = [];
        }

        $occurrenceDate = DateTimeImmutable::createFromFormat('Y-m-d', $occurrenceDateInput);
        if (!$occurrenceDate || $occurrenceDate->format('Y-m-d') !== $occurrenceDateInput) {
            $flashError = 'Data inválida para guardar checklist da recorrência.';
        } else {
            $taskStmt = $pdo->prepare('SELECT * FROM team_recurring_tasks WHERE id = ? AND team_id = ?');
            $taskStmt->execute([$recurringTaskId, $teamId]);
            $task = $taskStmt->fetch(PDO::FETCH_ASSOC);

            if (!$task) {
                $flashError = 'Tarefa recorrente inválida para guardar checklist.';
            } else {
                $templateId = (int) ($task['checklist_template_id'] ?? 0);
                if ($templateId <= 0) {
                    $flashError = 'Esta recorrência não tem checklist associada.';
                } else {
                    $templateItemsStmt = $pdo->prepare('SELECT id, content FROM checklist_template_items WHERE template_id = ? ORDER BY position ASC, id ASC');
                    $templateItemsStmt->execute([$templateId]);
                    $templateItems = $templateItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    $allowedIds = [];
                    foreach ($templateItems as $templateItem) {
                        $allowedIds[(int) $templateItem['id']] = true;
                    }

                    $state = [];
                    foreach ($templateItems as $templateItem) {
                        $itemId = (int) $templateItem['id'];
                        $state[] = [
                            'item_id' => $itemId,
                            'content' => (string) $templateItem['content'],
                            'is_done' => isset($checkedItems[(string) $itemId]) && isset($allowedIds[$itemId]),
                        ];
                    }

                    $stateJson = json_encode($state, JSON_UNESCAPED_UNICODE);
                    if ($stateJson === false) {
                        $flashError = 'Não foi possível guardar o estado da checklist.';
                    } else {
                        $saveStmt = $pdo->prepare('INSERT INTO team_recurring_task_checklist_states(recurring_task_id, occurrence_date, checklist_state_json, updated_by, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(recurring_task_id, occurrence_date) DO UPDATE SET checklist_state_json = excluded.checklist_state_json, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP');
                        $saveStmt->execute([$recurringTaskId, $occurrenceDate->format('Y-m-d'), $stateJson, $userId]);
                        $flashSuccess = 'Checklist da recorrência guardada com sucesso.';
                    }
                }
            }
        }
    }

    if ($action === 'delete_recurring_task' && $canManageProjects) {
        $recurringTaskId = (int) ($_POST['recurring_task_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM team_recurring_tasks WHERE id = ? AND team_id = ?');
        $stmt->execute([$recurringTaskId, $teamId]);

        if ($stmt->rowCount() > 0) {
            $flashSuccess = 'Tarefa recorrente removida com sucesso.';
        } else {
            $flashError = 'Não foi possível remover a tarefa recorrente selecionada.';
        }
    }

    if ($action === 'save_team_ticket_details' && $canManageProjects) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'open');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $estimatedMinutes = ($_POST['estimated_minutes'] ?? '') !== '' ? max(0, (int) $_POST['estimated_minutes']) : null;
        $actualMinutes = ($_POST['actual_minutes'] ?? '') !== '' ? max(0, (int) $_POST['actual_minutes']) : null;
        $note = trim($_POST['note'] ?? '');
        $file = $_FILES['attachment'] ?? null;
        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        $assignee = null;
        if ($assigneeUserId > 0) {
            $memberStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
            $memberStmt->execute([$teamId, $assigneeUserId]);
            if ($memberStmt->fetchColumn()) {
                $assignee = $assigneeUserId;
            }
        }

        if ($ticketId > 0 && ticket_status_value_exists($pdo, $status)) {
            $previousStatusStmt = $pdo->prepare('SELECT status FROM team_tickets WHERE id = ? AND team_id = ? LIMIT 1');
            $previousStatusStmt->execute([$ticketId, $teamId]);
            $previousStatus = $previousStatusStmt->fetchColumn();

            $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare('UPDATE team_tickets SET status = ?, assignee_user_id = ?, estimated_minutes = ?, actual_minutes = ?, completed_at = ? WHERE id = ? AND team_id = ?');
            $stmt->execute([$status, $assignee, $estimatedMinutes, $actualMinutes, $completedAt, $ticketId, $teamId]);
            if ($stmt->rowCount() > 0 && $previousStatus !== false && (string) $previousStatus !== $status) {
                record_ticket_status_history($pdo, $ticketId, (string) $previousStatus, $status, $userId);
            }
            $flashSuccess = 'Ticket atualizado com sucesso.';
        }
    }

    if ($action === 'add_team_ticket_note' && $canManageProjects) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($ticketId > 0 && $note !== '') {
            $stmt = $pdo->prepare('INSERT INTO team_ticket_notes(ticket_id, note, created_by) SELECT id, ?, ? FROM team_tickets WHERE id = ? AND team_id = ?');
            $stmt->execute([$note, $userId, $ticketId, $teamId]);
            $flashSuccess = 'Observação adicionada.';
        }
    }

    if ($action === 'upload_team_ticket_attachment' && $canManageProjects) {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $file = $_FILES['attachment'] ?? null;
        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        if ($ticketId > 0 && $hasFile) {
            $ticketCheck = $pdo->prepare('SELECT 1 FROM team_tickets WHERE id = ? AND team_id = ?');
            $ticketCheck->execute([$ticketId, $teamId]);

            if ($ticketCheck->fetchColumn()) {
                $previousStatusStmt = $pdo->prepare('SELECT status FROM team_tickets WHERE id = ? AND team_id = ? LIMIT 1');
                $previousStatusStmt->execute([$ticketId, $teamId]);
                $previousStatus = $previousStatusStmt->fetchColumn();

                $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare('UPDATE team_tickets SET status = ?, assignee_user_id = ?, estimated_minutes = ?, actual_minutes = ?, completed_at = ? WHERE id = ? AND team_id = ?');
                $stmt->execute([$status, $assignee, $estimatedMinutes, $actualMinutes, $completedAt, $ticketId, $teamId]);
                if ($stmt->rowCount() > 0 && $previousStatus !== false && (string) $previousStatus !== $status) {
                    record_ticket_status_history($pdo, $ticketId, (string) $previousStatus, $status, $userId);
                }

                if ($note !== '') {
                    $noteStmt = $pdo->prepare('INSERT INTO team_ticket_notes(ticket_id, note, created_by) VALUES (?, ?, ?)');
                    $noteStmt->execute([$ticketId, $note, $userId]);
                }

                if ($hasFile) {
                    $uploadDir = __DIR__ . '/uploads/team_ticket_attachments';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
                    $safeName = uniqid('team_ticket_', true) . ($ext ? '.' . strtolower($ext) : '');
                    $targetPath = $uploadDir . '/' . $safeName;
                    if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                        $attachStmt = $pdo->prepare('INSERT INTO team_ticket_attachments(ticket_id, original_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)');
                        $attachStmt->execute([$ticketId, (string) $file['name'], 'uploads/team_ticket_attachments/' . $safeName, $userId]);
                    }
                }

                $flashSuccess = 'Ticket atualizado com sucesso.';
            }
        }
    }

    if ($flashSuccess) {
        $_SESSION['flash_success'] = $flashSuccess;
    }
    if ($flashError) {
        $_SESSION['flash_error'] = $flashError;
    }

    redirect('team.php?id=' . $teamId);
}

$teamStmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
$teamStmt->execute([$teamId]);
$team = $teamStmt->fetch(PDO::FETCH_ASSOC);

$projectsStmt = $pdo->prepare('SELECT p.*, u.name AS leader_name FROM projects p LEFT JOIN users u ON u.id = p.leader_user_id WHERE p.team_id = ? ORDER BY p.created_at DESC');
$projectsStmt->execute([$teamId]);
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

$taskCreationFieldCatalog = task_creation_field_catalog_for_team($pdo, $teamId);
$teamTaskCreationFieldRules = task_creation_field_rules($pdo, $teamId, null, $taskCreationFieldCatalog);
$scopeProjectId = (int) ($_GET['fields_scope_project_id'] ?? 0);
$scopeProjectIdValue = null;
if ($scopeProjectId > 0) {
    foreach ($projects as $projectItem) {
        if ((int) $projectItem['id'] === $scopeProjectId) {
            $scopeProjectIdValue = $scopeProjectId;
            break;
        }
    }
}
$activeTaskCreationFieldRules = task_creation_field_rules($pdo, $teamId, $scopeProjectIdValue, $taskCreationFieldCatalog);
$customTaskFields = array_filter($taskCreationFieldCatalog, static fn (array $meta): bool => empty($meta['is_builtin']));

$membersStmt = $pdo->prepare('SELECT u.id, u.name, u.email, tm.role FROM team_members tm INNER JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ?');
$membersStmt->execute([$teamId]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

$teamNotesStmt = $pdo->prepare('SELECT tn.*, u.name AS user_name FROM team_notes tn INNER JOIN users u ON u.id = tn.created_by WHERE tn.team_id = ? ORDER BY tn.created_at DESC');
$teamNotesStmt->execute([$teamId]);
$teamNotes = $teamNotesStmt->fetchAll(PDO::FETCH_ASSOC);

$weekdayNames = [
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado',
    7 => 'Domingo',
];
$recurrenceOptions = recurring_task_recurrence_options($pdo);
$checklistTemplatesStmt = $pdo->query('SELECT ct.id, ct.name, COUNT(cti.id) AS items_count FROM checklist_templates ct LEFT JOIN checklist_template_items cti ON cti.template_id = ct.id GROUP BY ct.id ORDER BY ct.name COLLATE NOCASE ASC');
$checklistTemplates = $checklistTemplatesStmt->fetchAll(PDO::FETCH_ASSOC);
$checklistTemplateItemsByTemplateId = [];
if ($checklistTemplates) {
    $templateIds = array_values(array_unique(array_map(static fn (array $template): int => (int) $template['id'], $checklistTemplates)));
    if ($templateIds) {
        $templateItemsPlaceholders = implode(',', array_fill(0, count($templateIds), '?'));
        $checklistTemplateItemsStmt = $pdo->prepare('SELECT template_id, id, content FROM checklist_template_items WHERE template_id IN (' . $templateItemsPlaceholders . ') ORDER BY template_id ASC, position ASC, id ASC');
        $checklistTemplateItemsStmt->execute($templateIds);
        foreach ($checklistTemplateItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $templateItem) {
            $checklistTemplateItemsByTemplateId[(int) $templateItem['template_id']][] = [
                'id' => (int) $templateItem['id'],
                'content' => (string) $templateItem['content'],
            ];
        }
    }
}

$calendarViewLabels = [
    'day' => 'Dia',
    'week' => 'Semanal',
    'month' => 'Mensal',
    'year' => 'Anual',
];
$calendarView = (string) ($_GET['calendar_view'] ?? 'week');
if (!isset($calendarViewLabels[$calendarView])) {
    $calendarView = 'week';
}

$referenceDateInput = (string) ($_GET['reference_date'] ?? date('Y-m-d'));
$referenceDate = DateTimeImmutable::createFromFormat('Y-m-d', $referenceDateInput);
if (!$referenceDate || $referenceDate->format('Y-m-d') !== $referenceDateInput) {
    $referenceDate = new DateTimeImmutable('today');
}
$referenceDate = $referenceDate->setTime(0, 0);

$calendarPeriodStart = $referenceDate;
$calendarPeriodEnd = $referenceDate;
$calendarPeriodLabel = $referenceDate->format('d/m/Y');

if ($calendarView === 'week') {
    $calendarPeriodStart = $referenceDate->modify('monday this week');
    $calendarPeriodEnd = $calendarPeriodStart->modify('+6 day');
    $calendarPeriodLabel = $calendarPeriodStart->format('d/m/Y') . ' - ' . $calendarPeriodEnd->format('d/m/Y');
} elseif ($calendarView === 'month') {
    $calendarPeriodStart = $referenceDate->modify('first day of this month');
    $calendarPeriodEnd = $referenceDate->modify('last day of this month');
    $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    $calendarPeriodLabel = $monthNames[(int) $referenceDate->format('n')] . ' de ' . $referenceDate->format('Y');
} elseif ($calendarView === 'year') {
    $calendarPeriodStart = $referenceDate->setDate((int) $referenceDate->format('Y'), 1, 1);
    $calendarPeriodEnd = $referenceDate->setDate((int) $referenceDate->format('Y'), 12, 31);
    $calendarPeriodLabel = $referenceDate->format('Y');
}

$recurringTasksStmt = $pdo->prepare('SELECT rt.*, u.name AS creator_name, p.name AS project_name, a.name AS assignee_name, ct.name AS checklist_template_name FROM team_recurring_tasks rt INNER JOIN users u ON u.id = rt.created_by LEFT JOIN projects p ON p.id = rt.project_id LEFT JOIN users a ON a.id = rt.assignee_user_id LEFT JOIN checklist_templates ct ON ct.id = rt.checklist_template_id WHERE rt.team_id = ? ORDER BY rt.created_at ASC');
$recurringTasksStmt->execute([$teamId]);
$recurringTasks = $recurringTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$recurringCompletionsByTaskAndDate = [];
$recurringOverridesByTaskAndDate = [];
$recurringChecklistStateByTaskAndDate = [];
$recurringTaskIds = array_values(array_unique(array_map(static fn (array $task): int => (int) $task['id'], $recurringTasks)));
if ($recurringTaskIds) {
    $completionParams = [$calendarPeriodStart->format('Y-m-d'), $calendarPeriodEnd->format('Y-m-d')];
    $completionParams = array_merge($completionParams, $recurringTaskIds);
    $completionPlaceholders = implode(',', array_fill(0, count($recurringTaskIds), '?'));
    $completionStmt = $pdo->prepare('SELECT c.recurring_task_id, c.occurrence_date, c.completed_at, u.name AS completed_by_name FROM team_recurring_task_completions c INNER JOIN users u ON u.id = c.completed_by WHERE c.occurrence_date BETWEEN ? AND ? AND c.recurring_task_id IN (' . $completionPlaceholders . ')');
    $completionStmt->execute($completionParams);

    foreach ($completionStmt->fetchAll(PDO::FETCH_ASSOC) as $completion) {
        $recurringCompletionsByTaskAndDate[(int) $completion['recurring_task_id'] . '|' . (string) $completion['occurrence_date']] = [
            'completed_at' => (string) $completion['completed_at'],
            'completed_by_name' => (string) $completion['completed_by_name'],
        ];
    }

    $overrideStmt = $pdo->prepare('SELECT o.*, p.name AS project_name, a.name AS assignee_name FROM team_recurring_task_overrides o LEFT JOIN projects p ON p.id = o.project_id LEFT JOIN users a ON a.id = o.assignee_user_id WHERE o.occurrence_date BETWEEN ? AND ? AND o.recurring_task_id IN (' . $completionPlaceholders . ')');
    $overrideStmt->execute($completionParams);

    foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $override) {
        $recurringOverridesByTaskAndDate[(int) $override['recurring_task_id'] . '|' . (string) $override['occurrence_date']] = [
            'project_id' => $override['project_id'] !== null ? (int) $override['project_id'] : null,
            'assignee_user_id' => $override['assignee_user_id'] !== null ? (int) $override['assignee_user_id'] : null,
            'title' => (string) $override['title'],
            'description' => (string) ($override['description'] ?? ''),
            'time_of_day' => (string) ($override['time_of_day'] ?? ''),
            'project_name' => (string) ($override['project_name'] ?? ''),
            'assignee_name' => (string) ($override['assignee_name'] ?? ''),
        ];
    }

    $checklistStateStmt = $pdo->prepare('SELECT recurring_task_id, occurrence_date, checklist_state_json, updated_at FROM team_recurring_task_checklist_states WHERE occurrence_date BETWEEN ? AND ? AND recurring_task_id IN (' . $completionPlaceholders . ')');
    $checklistStateStmt->execute($completionParams);
    foreach ($checklistStateStmt->fetchAll(PDO::FETCH_ASSOC) as $stateRow) {
        $state = json_decode((string) $stateRow['checklist_state_json'], true);
        if (!is_array($state)) {
            continue;
        }
        $recurringChecklistStateByTaskAndDate[(int) $stateRow['recurring_task_id'] . '|' . (string) $stateRow['occurrence_date']] = [
            'items' => $state,
            'updated_at' => (string) $stateRow['updated_at'],
        ];
    }
}

$calendarOccurrences = [];
foreach ($recurringTasks as $recurringTask) {
    $occurrenceDates = recurring_task_occurrences($pdo, $recurringTask, $calendarPeriodStart, $calendarPeriodEnd);
    foreach ($occurrenceDates as $occurrenceDate) {
        $dateKey = $occurrenceDate->format('Y-m-d');
        if (!isset($calendarOccurrences[$dateKey])) {
            $calendarOccurrences[$dateKey] = [];
        }

        $completionKey = (int) $recurringTask['id'] . '|' . $dateKey;
        $completion = $recurringCompletionsByTaskAndDate[$completionKey] ?? null;
        $override = $recurringOverridesByTaskAndDate[$completionKey] ?? null;

        $calendarOccurrences[$dateKey][] = [
            'id' => (int) $recurringTask['id'],
            'occurrence_date' => $dateKey,
            'project_id' => $override['project_id'] ?? ($recurringTask['project_id'] !== null ? (int) $recurringTask['project_id'] : null),
            'assignee_user_id' => $override['assignee_user_id'] ?? ($recurringTask['assignee_user_id'] !== null ? (int) $recurringTask['assignee_user_id'] : null),
            'title' => $override['title'] ?? (string) $recurringTask['title'],
            'description' => $override['description'] ?? (string) ($recurringTask['description'] ?? ''),
            'time_of_day' => $override['time_of_day'] ?? (string) ($recurringTask['time_of_day'] ?? ''),
            'creator_name' => (string) $recurringTask['creator_name'],
            'project_name' => $override['project_name'] ?? (string) ($recurringTask['project_name'] ?? ''),
            'assignee_name' => $override['assignee_name'] ?? (string) ($recurringTask['assignee_name'] ?? ''),
            'checklist_template_id' => $recurringTask['checklist_template_id'] !== null ? (int) $recurringTask['checklist_template_id'] : null,
            'checklist_template_name' => (string) ($recurringTask['checklist_template_name'] ?? ''),
            'recurrence_label' => recurring_task_recurrence_label($pdo, (string) ($recurringTask['recurrence_type'] ?? 'weekly')),
            'start_date' => (string) ($recurringTask['start_date'] ?? ''),
            'completed_at' => $completion['completed_at'] ?? null,
            'completed_by_name' => $completion['completed_by_name'] ?? null,
            'checklist_state' => $recurringChecklistStateByTaskAndDate[$completionKey]['items'] ?? [],
            'checklist_updated_at' => $recurringChecklistStateByTaskAndDate[$completionKey]['updated_at'] ?? null,
        ];
    }
}

ksort($calendarOccurrences);
foreach ($calendarOccurrences as $dateKey => $items) {
    usort($items, static function (array $left, array $right): int {
        $leftTime = $left['time_of_day'] !== '' ? $left['time_of_day'] : '23:59';
        $rightTime = $right['time_of_day'] !== '' ? $right['time_of_day'] : '23:59';
        return strcmp($leftTime, $rightTime);
    });
    $calendarOccurrences[$dateKey] = $items;
}

$weekDates = [];
if ($calendarView === 'week') {
    for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
        $day = $calendarPeriodStart->modify('+' . $dayOffset . ' day');
        $weekDates[] = [
            'key' => $day->format('Y-m-d'),
            'date' => $day,
            'label' => $weekdayNames[(int) $day->format('N')],
        ];
    }
}

$teamTasksStmt = $pdo->prepare('SELECT tt.*, u.name AS creator_name, a.name AS assignee_name FROM team_tickets tt INNER JOIN users u ON u.id = tt.created_by LEFT JOIN users a ON a.id = tt.assignee_user_id WHERE tt.team_id = ? ORDER BY CASE WHEN tt.completed_at IS NULL THEN 0 ELSE 1 END, tt.created_at DESC');
$teamTasksStmt->execute([$teamId]);
$teamTasks = $teamTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$ticketNotesStmt = $pdo->prepare('SELECT tn.*, u.name AS user_name FROM team_ticket_notes tn INNER JOIN team_tickets tt ON tt.id = tn.ticket_id INNER JOIN users u ON u.id = tn.created_by WHERE tt.team_id = ? ORDER BY tn.created_at DESC');
$ticketNotesStmt->execute([$teamId]);
$ticketNotesByTicket = [];
foreach ($ticketNotesStmt->fetchAll(PDO::FETCH_ASSOC) as $ticketNote) {
    $ticketNotesByTicket[$ticketNote['ticket_id']][] = $ticketNote;
}

$ticketAttachmentsStmt = $pdo->prepare('SELECT ta.*, u.name AS user_name FROM team_ticket_attachments ta INNER JOIN team_tickets tt ON tt.id = ta.ticket_id INNER JOIN users u ON u.id = ta.uploaded_by WHERE tt.team_id = ? ORDER BY ta.created_at DESC');
$ticketAttachmentsStmt->execute([$teamId]);
$ticketAttachmentsByTicket = [];
foreach ($ticketAttachmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $ticketAttachment) {
    $ticketAttachmentsByTicket[$ticketAttachment['ticket_id']][] = $ticketAttachment;
}

$memberRoleStmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
$memberRoleStmt->execute([$teamId, $userId]);
$memberRole = (string) $memberRoleStmt->fetchColumn();
$canManageProjects = $isAdmin || in_array($memberRole, ['owner', 'leader'], true);

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$pageTitle = 'Equipa ' . $team['name'];
require __DIR__ . '/partials/header.php';
?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<section class="hero-card hero-card-sm mb-4 p-4">
    <a href="dashboard.php" class="small text-white">← Voltar</a>
    <h1 class="h2 mb-1 mt-2"><?= h($team['name']) ?></h1>
    <p class="text-white-50 mb-0"><?= h($team['description']) ?: 'Sem descrição' ?></p>
</section>

<div class="vstack gap-4">
    <div class="card shadow-sm soft-card">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <h2 class="h5 mb-0">Projetos</h2>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-secondary js-collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#teamProjectsCollapse" aria-expanded="true" aria-controls="teamProjectsCollapse" title="Mostrar/Ocultar projetos">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    <span class="visually-hidden">Mostrar/Ocultar projetos</span>
                </button>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">Novo Projeto</button>
            </div>
        </div>
        <div class="collapse show" id="teamProjectsCollapse">
            <div class="list-group list-group-flush">
            <?php foreach ($projects as $project): ?>
                <div class="list-group-item py-3">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                        <div>
                            <a href="project.php?id=<?= (int) $project['id'] ?>" class="text-decoration-none"><strong><?= h($project['name']) ?></strong></a>
                            <p class="mb-0 text-muted small"><?= h($project['description']) ?: 'Sem descrição' ?></p>
                            <small class="text-muted">Líder: <?= h($project['leader_name'] ?: 'Não definido') ?></small>
                        </div>
                        <?php if ($canManageProjects): ?>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editProjectModal<?= (int) $project['id'] ?>">Editar</button>
                                <form method="post" onsubmit="return confirm('Eliminar este projeto e todas as tarefas associadas?');">
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$projects): ?><div class="p-3 text-muted">Ainda não existem projetos.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm soft-card">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <h2 class="h5 mb-0">Tickets</h2>
            <button class="btn btn-sm btn-outline-secondary js-collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#teamTicketsCollapse" aria-expanded="false" aria-controls="teamTicketsCollapse" title="Mostrar/Ocultar tickets">
                <i class="bi bi-eye" aria-hidden="true"></i>
                <span class="visually-hidden">Mostrar/Ocultar tickets</span>
            </button>
        </div>
        <div class="collapse" id="teamTicketsCollapse">
            <div class="list-group list-group-flush">
            <?php foreach ($teamTasks as $task): ?>
                <?php
                $collapseId = 'ticket-details-' . (int) $task['id'];
                $ticketDescription = parse_ticket_description((string) $task['description']);
                ?>
                <div class="list-group-item py-3">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="small text-muted">ID <?= h($task['ticket_code']) ?></div>
                            <strong><?= h($task['title']) ?></strong>
                            <?php if ($ticketDescription['summary'] !== ''): ?>
                                <p class="mb-1 small text-muted"><?= h($ticketDescription['summary']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($ticketDescription['details'])): ?>
                                <div class="small mt-1">
                                    <span class="text-muted d-block"><?= h($ticketDescription['details_title'] !== '' ? $ticketDescription['details_title'] : 'Campos preenchidos') ?>:</span>
                                    <ul class="mb-1 ps-3">
                                        <?php foreach ($ticketDescription['details'] as $detailLine): ?>
                                            <li><?= h($detailLine) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <small class="text-muted">Urgência: <?= h($task['urgency']) ?> · Prazo: <?= h($task['due_date'] ?: 'Sem data') ?> · Criado por <?= h($task['creator_name']) ?> · Atribuído a <?= h($task['assignee_name'] ?: 'Sem atribuição') ?></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge" style="<?= h(ticket_status_badge_style($pdo, (string) $task['status'])) ?>"><?= h(ticket_status_label($pdo, (string) $task['status'])) ?></span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">Detalhes</button>
                        </div>
                    </div>
                    <div class="collapse mt-2" id="<?= h($collapseId) ?>">
                        <?php if ($canManageProjects): ?>
                            <form method="post" enctype="multipart/form-data" class="border rounded p-3 bg-light-subtle">
                                <input type="hidden" name="action" value="save_team_ticket_details">
                                <input type="hidden" name="ticket_id" value="<?= (int) $task['id'] ?>">
                                <div class="row g-2 align-items-center">
                                    <div class="col-md-2">
                                        <select class="form-select form-select-sm" name="status">
                                            <?php foreach ($ticketStatuses as $statusOption): ?>
                                                <option value="<?= h($statusOption['value']) ?>" <?= $task['status'] === $statusOption['value'] ? 'selected' : '' ?>><?= h($statusOption['label']) ?></option>
                                            <?php endforeach; ?>
                                            <?php if (!ticket_status_value_exists($pdo, (string) $task['status'])): ?>
                                                <option value="<?= h($task['status']) ?>" selected><?= h(ticket_status_label($pdo, (string) $task['status'])) ?> (legado)</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select form-select-sm" name="assignee_user_id">
                                            <option value="0">Sem atribuição</option>
                                            <?php foreach ($members as $member): ?>
                                                <option value="<?= (int) $member['id'] ?>" <?= (int) $task['assignee_user_id'] === (int) $member['id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2"><input class="form-control form-control-sm" type="number" min="0" name="estimated_minutes" value="<?= $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : '' ?>" placeholder="Previsto (min)"></div>
                                    <div class="col-md-2"><input class="form-control form-control-sm" type="number" min="0" name="actual_minutes" value="<?= $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : '' ?>" placeholder="Real (min)"></div>
                                    <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Guardar alterações</button></div>
                                </div>

                                <div class="row g-2 mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Nova observação (opcional)</label>
                                        <input class="form-control form-control-sm" name="note" placeholder="Escreva uma observação...">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Anexar documento (opcional)</label>
                                        <input class="form-control form-control-sm" type="file" name="attachment">
                                    </div>
                                </div>
                            </form>

                            <div class="row g-2 mt-2">
                                <div class="col-md-6">
                                    <?php foreach (($ticketNotesByTicket[$task['id']] ?? []) as $note): ?>
                                        <div class="small border rounded p-2 mt-1 bg-light"><?= h($note['note']) ?><br><small class="text-muted"><?= h($note['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($note['created_at']))) ?></small></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-md-6">
                                    <?php foreach (($ticketAttachmentsByTicket[$task['id']] ?? []) as $attachment): ?>
                                        <div class="small border rounded p-2 mt-1 bg-light"><a href="<?= h($attachment['file_path']) ?>" target="_blank" rel="noopener"><?= h($attachment['original_name']) ?></a><br><small class="text-muted"><?= h($attachment['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($attachment['created_at']))) ?></small></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="small text-muted">Sem permissões para editar este ticket.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$teamTasks): ?><div class="p-3 text-muted">Sem tarefas diretas para esta equipa.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm soft-card">
        <div class="card-header d-flex justify-content-between align-items-center bg-white flex-wrap gap-2">
            <h2 class="h5 mb-0">Calendário da equipa</h2>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-sm btn-outline-secondary js-collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#teamRecurringTasksCollapse" aria-expanded="true" aria-controls="teamRecurringTasksCollapse" title="Mostrar/Ocultar tarefas recorrentes">
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    <span class="visually-hidden">Mostrar/Ocultar tarefas recorrentes</span>
                </button>
                <?php if ($canManageProjects): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#recurringTaskModal">Nova tarefa recorrente</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="collapse show" id="teamRecurringTasksCollapse">
            <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="btn-group btn-group-sm" role="group" aria-label="Vistas do calendário">
                    <?php foreach ($calendarViewLabels as $viewKey => $viewLabel): ?>
                        <a class="btn <?= $calendarView === $viewKey ? 'btn-primary' : 'btn-outline-primary' ?>" href="team.php?id=<?= (int) $teamId ?>&calendar_view=<?= h($viewKey) ?>&reference_date=<?= h($referenceDate->format('Y-m-d')) ?>"><?= h($viewLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="id" value="<?= (int) $teamId ?>">
                        <input type="hidden" name="calendar_view" value="<?= h($calendarView) ?>">
                        <input class="form-control form-control-sm" type="date" name="reference_date" value="<?= h($referenceDate->format('Y-m-d')) ?>">
                        <button class="btn btn-sm btn-outline-secondary">Ir</button>
                    </form>
                </div>
            </div>

            <p class="text-muted small mb-3">Vista atual: <strong><?= h($calendarViewLabels[$calendarView]) ?></strong> · Período: <?= h($calendarPeriodLabel) ?></p>

            <?php if ($calendarView === 'week'): ?>
                <div class="row g-3">
                    <?php foreach ($weekDates as $weekDate): ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                <h3 class="h6 mb-1"><?= h($weekDate['label']) ?></h3>
                                <div class="small text-muted mb-2"><?= h($weekDate['date']->format('d/m/Y')) ?></div>
                                <?php if (!empty($calendarOccurrences[$weekDate['key']])): ?>
                                    <div class="vstack gap-2">
                                        <?php foreach ($calendarOccurrences[$weekDate['key']] as $occurrence): ?>
                                            <div class="border rounded p-2 bg-white">
                                                <div class="d-flex justify-content-between gap-2">
                                                    <?php if ($canManageProjects): ?>
                                                        <button type="button" class="btn btn-link btn-sm text-start p-0 text-decoration-none fw-semibold small" data-bs-toggle="modal" data-bs-target="#editRecurringTaskModal<?= (int) $occurrence['id'] ?>" data-occurrence-date="<?= h($occurrence['occurrence_date']) ?>" data-occurrence-title="<?= h($occurrence['title']) ?>" data-occurrence-description="<?= h($occurrence['description']) ?>" data-occurrence-time="<?= h($occurrence['time_of_day']) ?>" data-occurrence-project-id="<?= $occurrence['project_id'] !== null ? (int) $occurrence['project_id'] : 0 ?>" data-occurrence-assignee-id="<?= $occurrence['assignee_user_id'] !== null ? (int) $occurrence['assignee_user_id'] : 0 ?>"><?= h($occurrence['title']) ?></button>
                                                    <?php else: ?>
                                                        <strong class="small"><?= h($occurrence['title']) ?></strong>
                                                    <?php endif; ?>
                                                    <span class="text-muted small"><?= h($occurrence['time_of_day'] !== '' ? $occurrence['time_of_day'] : 'Sem hora') ?></span>
                                                </div>
                                                <div class="small text-muted"><?= h($occurrence['recurrence_label']) ?><?= $occurrence['project_name'] !== '' ? ' · Projeto: ' . h($occurrence['project_name']) : '' ?><?= $occurrence['assignee_name'] !== '' ? ' · Atribuído a ' . h($occurrence['assignee_name']) : '' ?></div>
                                                <?php if ($occurrence['description'] !== ''): ?><p class="mb-1 small text-muted"><?= h($occurrence['description']) ?></p><?php endif; ?>
                                                <?php if ($occurrence['completed_at']): ?>
                                                    <div class="small text-success-emphasis">Concluída por <?= h((string) $occurrence['completed_by_name']) ?> em <?= h(date('d/m/Y H:i', strtotime((string) $occurrence['completed_at']))) ?></div>
                                                <?php endif; ?>
                                                <div class="d-flex justify-content-between align-items-center gap-2 mt-1">
                                                    <small class="text-muted">Criado por <?= h($occurrence['creator_name']) ?></small>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <?php if (!$occurrence['completed_at']): ?>
                                                            <form method="post" class="m-0">
                                                                <input type="hidden" name="action" value="complete_recurring_task">
                                                                <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                                <input type="hidden" name="occurrence_date" value="<?= h($occurrence['occurrence_date']) ?>">
                                                                <button class="btn btn-sm btn-outline-success py-0 px-2" title="Marcar como concluída">✓</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="post" class="m-0">
                                                                <input type="hidden" name="action" value="reopen_recurring_task">
                                                                <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                                <input type="hidden" name="occurrence_date" value="<?= h($occurrence['occurrence_date']) ?>">
                                                                <button class="btn btn-sm btn-success py-0 px-2" title="Reabrir tarefa recorrente">✓</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($canManageProjects): ?>
                                                            <form method="post" class="m-0" onsubmit="return confirm('Remover esta tarefa recorrente?');">
                                                                <input type="hidden" name="action" value="delete_recurring_task">
                                                                <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                                <button class="btn btn-sm btn-outline-danger py-0 px-2">Remover</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-muted">Sem tarefas recorrentes.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php if (!empty($calendarOccurrences)): ?>
                    <div class="vstack gap-2">
                        <?php foreach ($calendarOccurrences as $occurrenceDate => $occurrences): ?>
                            <?php $dateObj = new DateTimeImmutable($occurrenceDate); ?>
                            <div class="border rounded p-3 bg-light-subtle">
                                <h3 class="h6 mb-2"><?= h($weekdayNames[(int) $dateObj->format('N')] . ', ' . $dateObj->format('d/m/Y')) ?></h3>
                                <div class="vstack gap-2">
                                    <?php foreach ($occurrences as $occurrence): ?>
                                        <div class="border rounded p-2 bg-white">
                                            <div class="d-flex justify-content-between gap-2">
                                                <?php if ($canManageProjects): ?>
                                                    <button type="button" class="btn btn-link btn-sm text-start p-0 text-decoration-none fw-semibold small" data-bs-toggle="modal" data-bs-target="#editRecurringTaskModal<?= (int) $occurrence['id'] ?>" data-occurrence-date="<?= h($occurrence['occurrence_date']) ?>" data-occurrence-title="<?= h($occurrence['title']) ?>" data-occurrence-description="<?= h($occurrence['description']) ?>" data-occurrence-time="<?= h($occurrence['time_of_day']) ?>" data-occurrence-project-id="<?= $occurrence['project_id'] !== null ? (int) $occurrence['project_id'] : 0 ?>" data-occurrence-assignee-id="<?= $occurrence['assignee_user_id'] !== null ? (int) $occurrence['assignee_user_id'] : 0 ?>"><?= h($occurrence['title']) ?></button>
                                                <?php else: ?>
                                                    <strong class="small"><?= h($occurrence['title']) ?></strong>
                                                <?php endif; ?>
                                                <span class="text-muted small"><?= h($occurrence['time_of_day'] !== '' ? $occurrence['time_of_day'] : 'Sem hora') ?></span>
                                            </div>
                                            <div class="small text-muted"><?= h($occurrence['recurrence_label']) ?><?= $occurrence['project_name'] !== '' ? ' · Projeto: ' . h($occurrence['project_name']) : '' ?><?= $occurrence['assignee_name'] !== '' ? ' · Atribuído a ' . h($occurrence['assignee_name']) : '' ?></div>
                                            <?php if ($occurrence['checklist_template_name'] !== ''): ?>
                                                <div class="small text-muted">Checklist: <?= h($occurrence['checklist_template_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($occurrence['description'] !== ''): ?><p class="mb-1 small text-muted"><?= h($occurrence['description']) ?></p><?php endif; ?>
                                            <?php if ($occurrence['checklist_template_id']): ?>
                                                <?php
                                                $recurrenceTemplateItems = $checklistTemplateItemsByTemplateId[(int) $occurrence['checklist_template_id']] ?? [];
                                                $checklistStateByItemId = [];
                                                foreach (($occurrence['checklist_state'] ?? []) as $stateItem) {
                                                    if (isset($stateItem['item_id'])) {
                                                        $checklistStateByItemId[(int) $stateItem['item_id']] = !empty($stateItem['is_done']);
                                                    }
                                                }
                                                $totalChecklistItems = count($recurrenceTemplateItems);
                                                $doneChecklistItems = 0;
                                                foreach ($recurrenceTemplateItems as $templateItem) {
                                                    if (!empty($checklistStateByItemId[(int) $templateItem['id']])) {
                                                        $doneChecklistItems++;
                                                    }
                                                }
                                                $checklistExecutionModalId = 'runChecklistModal' . (int) $occurrence['id'] . str_replace('-', '', $occurrence['occurrence_date']);
                                                ?>
                                                <?php if ($totalChecklistItems > 0): ?>
                                                    <div class="small mt-1 border rounded p-2 bg-light-subtle d-flex justify-content-between align-items-center gap-2">
                                                        <div>
                                                            <div class="fw-semibold">Execução da checklist</div>
                                                            <div class="text-muted"><?= $doneChecklistItems ?>/<?= $totalChecklistItems ?> itens concluídos</div>
                                                            <?php if (!empty($occurrence['checklist_updated_at'])): ?><div class="text-muted">Atualizada em <?= h(date('d/m/Y H:i', strtotime((string) $occurrence['checklist_updated_at']))) ?></div><?php endif; ?>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= h($checklistExecutionModalId) ?>">Executar checklist</button>
                                                    </div>
                                                    <div class="modal fade" id="<?= h($checklistExecutionModalId) ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <form method="post" class="modal-content">
                                                                <input type="hidden" name="action" value="save_recurring_task_checklist_state">
                                                                <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                                <input type="hidden" name="occurrence_date" value="<?= h($occurrence['occurrence_date']) ?>">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Checklist da ocorrência</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    <div class="small text-muted mb-2"><?= h($occurrence['title']) ?> · <?= h(date('d/m/Y', strtotime((string) $occurrence['occurrence_date']))) ?></div>
                                                                    <div class="vstack gap-2">
                                                                        <?php foreach ($recurrenceTemplateItems as $templateItem): ?>
                                                                            <label class="d-flex align-items-center gap-2">
                                                                                <input class="form-check-input m-0" type="checkbox" name="checked_items[<?= (int) $templateItem['id'] ?>]" value="1" <?= !empty($checklistStateByItemId[(int) $templateItem['id']]) ? 'checked' : '' ?>>
                                                                                <span><?= h($templateItem['content']) ?></span>
                                                                            </label>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                                                                    <button class="btn btn-primary">Guardar checklist</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($occurrence['completed_at']): ?>
                                                <div class="small text-success-emphasis">Concluída por <?= h((string) $occurrence['completed_by_name']) ?> em <?= h(date('d/m/Y H:i', strtotime((string) $occurrence['completed_at']))) ?></div>
                                            <?php endif; ?>
                                            <div class="d-flex justify-content-between align-items-center gap-2 mt-1">
                                                <small class="text-muted">Criado por <?= h($occurrence['creator_name']) ?></small>
                                                <div class="d-flex align-items-center gap-1">
                                                    <?php if (!$occurrence['completed_at']): ?>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="action" value="complete_recurring_task">
                                                            <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                            <input type="hidden" name="occurrence_date" value="<?= h($occurrence['occurrence_date']) ?>">
                                                            <button class="btn btn-sm btn-outline-success py-0 px-2" title="Marcar como concluída">✓</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="action" value="reopen_recurring_task">
                                                            <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                            <input type="hidden" name="occurrence_date" value="<?= h($occurrence['occurrence_date']) ?>">
                                                            <button class="btn btn-sm btn-success py-0 px-2" title="Reabrir tarefa recorrente">✓</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($canManageProjects): ?>
                                                        <form method="post" class="m-0" onsubmit="return confirm('Remover esta tarefa recorrente?');">
                                                            <input type="hidden" name="action" value="delete_recurring_task">
                                                            <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                            <button class="btn btn-sm btn-outline-danger py-0 px-2">Remover</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="small text-muted">Sem tarefas recorrentes para o período selecionado.</div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <?php $hasTeamNotes = !empty($teamNotes); ?>
    <div class="card shadow-sm soft-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <h2 class="h5 mb-0">Notas da equipa</h2>
            <button class="btn btn-sm btn-outline-secondary js-collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#teamNotesCollapse" aria-expanded="<?= $hasTeamNotes ? 'true' : 'false' ?>" aria-controls="teamNotesCollapse" title="Mostrar/Ocultar notas">
                <i class="bi bi-eye" aria-hidden="true"></i>
                <span class="visually-hidden">Mostrar/Ocultar notas</span>
            </button>
        </div>
        <div class="collapse <?= $hasTeamNotes ? 'show' : '' ?>" id="teamNotesCollapse">
            <div class="card-body">
                <form method="post" class="d-flex gap-2 mb-3">
                    <input type="hidden" name="action" value="add_team_note">
                    <input class="form-control form-control-sm" name="note" placeholder="Adicionar nota para a equipa" required>
                    <button class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>
                <?php if (!$teamNotes): ?>
                    <p class="small text-muted mb-0">Ainda não existem notas da equipa.</p>
                <?php endif; ?>
                <?php foreach ($teamNotes as $note): ?>
                    <div class="small border rounded p-2 mb-2 bg-light">
                        <?= h($note['note']) ?><br>
                        <small class="text-muted"><?= h($note['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($note['created_at']))) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($canManageProjects): ?>
    <div class="card shadow-sm soft-card">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <h2 class="h5 mb-0">Campos na criação de tarefas</h2>
            <button class="btn btn-sm btn-outline-secondary js-collapse-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#teamTaskFieldsCollapse" aria-expanded="true" aria-controls="teamTaskFieldsCollapse" title="Mostrar/Ocultar configuração">
                <i class="bi bi-eye" aria-hidden="true"></i>
                <span class="visually-hidden">Mostrar/Ocultar configuração</span>
            </button>
        </div>
        <div class="collapse show" id="teamTaskFieldsCollapse">
            <div class="card-body">
                <p class="text-muted small">Crie campos personalizados (tipo texto, número, data, seleção) e configure visibilidade/obrigatoriedade por âmbito sem gerar listas infinitas.</p>

                <form method="post" class="border rounded p-3 mb-3 row g-2 align-items-end">
                    <input type="hidden" name="action" value="create_task_custom_field">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Nome do campo</label>
                        <input class="form-control" name="custom_label" placeholder="Ex.: Cliente final" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Tipo</label>
                        <select class="form-select" name="custom_type" required>
                            <option value="text">Texto</option>
                            <option value="textarea">Texto longo</option>
                            <option value="number">Número</option>
                            <option value="date">Data</option>
                            <option value="select">Seleção</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Opções (1 por linha, para seleção)</label>
                        <textarea class="form-control" name="custom_options" rows="2" placeholder="Opção A&#10;Opção B"></textarea>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="custom_visible" value="1" checked id="customVisible"><label class="form-check-label" for="customVisible">Mostrar</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="custom_required" value="1" id="customRequired"><label class="form-check-label" for="customRequired">Obrigatório</label></div>
                    </div>
                    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Adicionar campo</button></div>
                </form>

                <?php if ($customTaskFields): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="fw-semibold mb-2">Campos personalizados</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($customTaskFields as $customFieldKey => $customFieldMeta): ?>
                                <form method="post" class="d-flex align-items-center gap-2 border rounded px-2 py-1" onsubmit="return confirm('Remover este campo personalizado?');">
                                    <input type="hidden" name="action" value="delete_task_custom_field">
                                    <input type="hidden" name="field_key" value="<?= h($customFieldKey) ?>">
                                    <span class="small"><?= h($customFieldMeta['label']) ?> (<?= h((string) ($customFieldMeta['type'] ?? 'text')) ?>)</span>
                                    <button class="btn btn-sm btn-outline-danger">Remover</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="border rounded p-3 mb-3">
                    <form method="get" class="row g-2 align-items-end">
                        <input type="hidden" name="id" value="<?= (int) $teamId ?>">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Âmbito da configuração</label>
                            <select class="form-select" name="fields_scope_project_id" onchange="this.form.submit()">
                                <option value="0" <?= $scopeProjectIdValue === null ? 'selected' : '' ?>>Padrão da equipa</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int) $project['id'] ?>" <?= $scopeProjectIdValue === (int) $project['id'] ? 'selected' : '' ?>>Projeto: <?= h($project['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8 small text-muted">
                            <?= $scopeProjectIdValue ? 'A editar regras específicas do projeto selecionado.' : 'A editar regras padrão que se aplicam à equipa.' ?>
                        </div>
                    </form>
                </div>

                <form method="post" class="border rounded p-3">
                    <input type="hidden" name="action" value="save_task_creation_fields_config">
                    <input type="hidden" name="config_project_id" value="<?= $scopeProjectIdValue !== null ? (int) $scopeProjectIdValue : 0 ?>">
                    <div class="row g-2">
                        <?php foreach ($taskCreationFieldCatalog as $fieldKey => $fieldMeta): ?>
                            <?php $fieldRules = $activeTaskCreationFieldRules[$fieldKey] ?? ['is_visible' => !empty($fieldMeta['default_visible']), 'is_required' => !empty($fieldMeta['default_required'])]; ?>
                            <div class="col-md-6">
                                <div class="border rounded p-2 h-100">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?= h($fieldMeta['label']) ?></div>
                                            <div class="small text-muted">Tipo: <?= h((string) ($fieldMeta['type'] ?? 'text')) ?></div>
                                        </div>
                                        <?php if (empty($fieldMeta['is_builtin'])): ?>
                                            <span class="badge bg-secondary">Personalizado</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input js-visible-toggle" type="checkbox" id="scopeVisible<?= h($fieldKey) ?>" name="visible_fields[]" value="<?= h($fieldKey) ?>" <?= !empty($fieldRules['is_visible']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="scopeVisible<?= h($fieldKey) ?>">Mostrar</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input js-required-toggle" type="checkbox" id="scopeRequired<?= h($fieldKey) ?>" name="required_fields[]" value="<?= h($fieldKey) ?>" <?= !empty($fieldRules['is_required']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="scopeRequired<?= h($fieldKey) ?>">Obrigatório</label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3"><button class="btn btn-sm btn-primary">Guardar configuração</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>


    </div>

</div>

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Membros da Equipa</h2></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($members as $member): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($member['name']) ?><br><small class="text-muted"><?= h($member['email']) ?></small></span>
                        <span class="badge text-bg-light border"><?= h($member['role']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Adicionar Membro</h2></div>
            <div class="card-body">
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="invite_member">
                    <input class="form-control" type="email" name="email" placeholder="email@empresa.com" required>
                    <select class="form-select" name="role"><option value="member" selected>Membro</option><option value="leader">Líder</option></select>
                    <button class="btn btn-outline-primary">Adicionar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageProjects): ?>
    <?php foreach ($projects as $project): ?>
        <div class="modal fade" id="editProjectModal<?= (int) $project['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content" method="post">
                    <input type="hidden" name="action" value="update_project">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Editar projeto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body vstack gap-3">
                        <input class="form-control" name="name" value="<?= h($project['name']) ?>" required>
                        <textarea class="form-control" name="description" placeholder="Descrição"><?= h($project['description']) ?></textarea>
                        <select class="form-select" name="leader_user_id">
                            <?php foreach ($members as $member): ?>
                                <option value="<?= (int) $member['id'] ?>" <?= (int) $member['id'] === (int) $project['leader_user_id'] ? 'selected' : '' ?>><?= h($member['name']) ?> (<?= h($member['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer"><button class="btn btn-primary">Guardar alterações</button></div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($recurringTasks as $recurringTask): ?>
        <div class="modal fade" id="editRecurringTaskModal<?= (int) $recurringTask['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content recurring-task-edit-form" method="post" data-default-title="<?= h($recurringTask['title']) ?>" data-default-description="<?= h((string) ($recurringTask['description'] ?? '')) ?>" data-default-time="<?= h((string) ($recurringTask['time_of_day'] ?? '')) ?>" data-default-project-id="<?= $recurringTask['project_id'] !== null ? (int) $recurringTask['project_id'] : 0 ?>" data-default-assignee-id="<?= $recurringTask['assignee_user_id'] !== null ? (int) $recurringTask['assignee_user_id'] : 0 ?>" data-default-checklist-template-id="<?= $recurringTask['checklist_template_id'] !== null ? (int) $recurringTask['checklist_template_id'] : 0 ?>">
                    <input type="hidden" name="recurring_task_id" value="<?= (int) $recurringTask['id'] ?>">
                    <input type="hidden" name="occurrence_date" value="">
                    <div class="modal-header"><h5 class="modal-title">Editar tarefa recorrente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body vstack gap-3">
                        <input class="form-control" name="title" value="<?= h($recurringTask['title']) ?>" required>
                        <textarea class="form-control" name="description" placeholder="Descrição (opcional)"><?= h((string) ($recurringTask['description'] ?? '')) ?></textarea>
                        <div>
                            <label class="form-label small text-muted mb-1">Projeto (opcional)</label>
                            <select class="form-select" name="project_id">
                                <option value="0">Sem projeto</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= (int) $project['id'] ?>" <?= (int) $project['id'] === (int) $recurringTask['project_id'] ? 'selected' : '' ?>><?= h($project['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Atribuir a (opcional)</label>
                            <select class="form-select" name="assignee_user_id">
                                <option value="0">Sem atribuição</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?= (int) $member['id'] ?>" <?= (int) $member['id'] === (int) $recurringTask['assignee_user_id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Checklist (opcional)</label>
                            <select class="form-select" name="checklist_template_id">
                                <option value="0">Sem checklist</option>
                                <?php foreach ($checklistTemplates as $template): ?>
                                    <option value="<?= (int) $template['id'] ?>" <?= (int) $template['id'] === (int) ($recurringTask['checklist_template_id'] ?? 0) ? 'selected' : '' ?>><?= h($template['name']) ?> (<?= (int) $template['items_count'] ?> itens)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="border rounded p-2 bg-light-subtle">
                            <div class="small fw-semibold mb-1">Aplicar alterações</div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="update_scope" value="single" id="updateScopeSingle<?= (int) $recurringTask['id'] ?>">
                                <label class="form-check-label" for="updateScopeSingle<?= (int) $recurringTask['id'] ?>">Apenas neste dia</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="update_scope" value="all" id="updateScopeAll<?= (int) $recurringTask['id'] ?>" checked>
                                <label class="form-check-label" for="updateScopeAll<?= (int) $recurringTask['id'] ?>">Em todos os eventos</label>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Recorrência</label>
                                <select class="form-select" name="recurrence_type" required>
                                    <?php foreach ($recurrenceOptions as $recurrenceValue => $recurrenceLabel): ?>
                                        <option value="<?= h($recurrenceValue) ?>" <?= $recurrenceValue === (string) ($recurringTask['recurrence_type'] ?? 'weekly') ? 'selected' : '' ?>><?= h($recurrenceLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted mb-1">Início</label>
                                <input class="form-control" type="date" name="start_date" value="<?= h((string) ($recurringTask['start_date'] ?? date('Y-m-d'))) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted mb-1">Hora</label>
                                <input class="form-control" type="time" name="time_of_day" value="<?= h((string) ($recurringTask['time_of_day'] ?? '')) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button class="btn btn-primary" name="action" value="update_recurring_task">Guardar alterações</button>
                        <button class="btn btn-outline-danger" name="action" value="delete_recurring_task" onclick="return confirm('Remover esta tarefa recorrente?');">Eliminar recorrência</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="modal fade" id="recurringTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_recurring_task">
            <div class="modal-header"><h5 class="modal-title">Nova tarefa recorrente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="title" placeholder="Título da tarefa" required>
                <textarea class="form-control" name="description" placeholder="Descrição (opcional)"></textarea>
                <div>
                    <label class="form-label small text-muted mb-1">Projeto (opcional)</label>
                    <select class="form-select" name="project_id">
                        <option value="0">Sem projeto</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?= (int) $project['id'] ?>"><?= h($project['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label small text-muted mb-1">Atribuir a (opcional)</label>
                    <select class="form-select" name="assignee_user_id">
                        <option value="0">Sem atribuição</option>
                        <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['id'] ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label small text-muted mb-1">Checklist (opcional)</label>
                    <select class="form-select" name="checklist_template_id">
                        <option value="0">Sem checklist</option>
                        <?php foreach ($checklistTemplates as $template): ?>
                            <option value="<?= (int) $template['id'] ?>"><?= h($template['name']) ?> (<?= (int) $template['items_count'] ?> itens)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Recorrência</label>
                        <select class="form-select" name="recurrence_type" required>
                            <?php foreach ($recurrenceOptions as $recurrenceValue => $recurrenceLabel): ?>
                                <option value="<?= h($recurrenceValue) ?>" <?= $recurrenceValue === 'weekly' ? 'selected' : '' ?>><?= h($recurrenceLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Início</label>
                        <input class="form-control" type="date" name="start_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Hora</label>
                        <input class="form-control" type="time" name="time_of_day">
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Guardar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="projectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_project">
            <div class="modal-header"><h5 class="modal-title">Criar projeto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="name" placeholder="Nome" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
                <select class="form-select" name="leader_user_id">
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int) $member['id'] ?>" <?= $member['id'] === $userId ? 'selected' : '' ?>><?= h($member['name']) ?> (<?= h($member['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar</button></div>
        </form>
    </div>
</div>
<script>


const updateTaskFieldConfigForm = (formEl) => {
    const visibleToggles = formEl.querySelectorAll('.js-visible-toggle');
    visibleToggles.forEach((visibleToggle) => {
        const fieldKey = visibleToggle.value;
        const requiredToggle = Array.from(formEl.querySelectorAll('.js-required-toggle')).find((el) => el.value === fieldKey);
        if (!requiredToggle) {
            return;
        }
        requiredToggle.disabled = !visibleToggle.checked;
        if (!visibleToggle.checked) {
            requiredToggle.checked = false;
        }

        visibleToggle.addEventListener('change', () => {
            requiredToggle.disabled = !visibleToggle.checked;
            if (!visibleToggle.checked) {
                requiredToggle.checked = false;
            }
        });
    });
};

document.querySelectorAll('form').forEach((formEl) => {
    if (formEl.querySelector('.js-visible-toggle') && formEl.querySelector('.js-required-toggle')) {
        updateTaskFieldConfigForm(formEl);
    }
});

document.querySelectorAll('.modal[id^="editRecurringTaskModal"]').forEach((modalEl) => {
    modalEl.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const form = modalEl.querySelector('.recurring-task-edit-form');
        if (!form) {
            return;
        }

        const titleField = form.querySelector('input[name="title"]');
        const descriptionField = form.querySelector('textarea[name="description"]');
        const timeField = form.querySelector('input[name="time_of_day"]');
        const projectField = form.querySelector('select[name="project_id"]');
        const assigneeField = form.querySelector('select[name="assignee_user_id"]');
        const checklistTemplateField = form.querySelector('select[name="checklist_template_id"]');
        const occurrenceDateField = form.querySelector('input[name="occurrence_date"]');
        const singleScopeField = form.querySelector('input[name="update_scope"][value="single"]');
        const allScopeField = form.querySelector('input[name="update_scope"][value="all"]');

        const defaultTitle = form.dataset.defaultTitle ?? '';
        const defaultDescription = form.dataset.defaultDescription ?? '';
        const defaultTime = form.dataset.defaultTime ?? '';
        const defaultProjectId = form.dataset.defaultProjectId ?? '0';
        const defaultAssigneeId = form.dataset.defaultAssigneeId ?? '0';
        const defaultChecklistTemplateId = form.dataset.defaultChecklistTemplateId ?? '0';

        let occurrenceDate = '';
        let title = defaultTitle;
        let description = defaultDescription;
        let timeOfDay = defaultTime;
        let projectId = defaultProjectId;
        let assigneeId = defaultAssigneeId;
        let checklistTemplateId = defaultChecklistTemplateId;

        if (trigger && trigger.dataset && trigger.dataset.occurrenceDate) {
            occurrenceDate = trigger.dataset.occurrenceDate;
            title = trigger.dataset.occurrenceTitle ?? title;
            description = trigger.dataset.occurrenceDescription ?? description;
            timeOfDay = trigger.dataset.occurrenceTime ?? timeOfDay;
            projectId = trigger.dataset.occurrenceProjectId ?? projectId;
            assigneeId = trigger.dataset.occurrenceAssigneeId ?? assigneeId;
            if (singleScopeField) {
                singleScopeField.checked = true;
            }
        } else if (allScopeField) {
            allScopeField.checked = true;
        }

        if (titleField) {
            titleField.value = title;
        }
        if (descriptionField) {
            descriptionField.value = description;
        }
        if (timeField) {
            timeField.value = timeOfDay;
        }
        if (projectField) {
            projectField.value = projectId;
        }
        if (assigneeField) {
            assigneeField.value = assigneeId;
        }
        if (checklistTemplateField) {
            checklistTemplateField.value = checklistTemplateId;
        }
        if (occurrenceDateField) {
            occurrenceDateField.value = occurrenceDate;
        }
    });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
