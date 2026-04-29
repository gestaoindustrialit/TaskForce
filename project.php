<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$projectId = (int) ($_GET['id'] ?? 0);
$project = project_for_user($pdo, $projectId, $userId);

if (!$project) {
    http_response_code(403);
    exit('Sem acesso ao projeto.');
}

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$ajaxResponse = static function (bool $ok, string $message = '', array $extra = []) use ($isAjax): void {
    if (!$isAjax) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

$redirectBack = static function () use ($projectId): void {
    $view = $_GET['view'] ?? ($_POST['view'] ?? 'list');
    $showDone = (int) ($_GET['show_done'] ?? ($_POST['show_done'] ?? 1)) === 1;
    redirect('project.php?id=' . $projectId . '&view=' . $view . '&show_done=' . ($showDone ? '1' : '0'));
};

$view = $_GET['view'] ?? 'list';
$showDone = (int) ($_GET['show_done'] ?? 1) === 1;

$taskCreationCatalog = task_creation_field_catalog_for_team($pdo, (int) $project['team_id']);
$taskCreationRules = task_creation_field_rules($pdo, (int) $project['team_id'], $projectId, $taskCreationCatalog);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_task') {
        $titleVisible = !empty($taskCreationRules['title']['is_visible']);
        $descriptionVisible = !empty($taskCreationRules['description']['is_visible']);
        $estimatedVisible = !empty($taskCreationRules['estimated_minutes']['is_visible']);
        $templateVisible = !empty($taskCreationRules['checklist_template_id']['is_visible']);
        $newChecklistVisible = !empty($taskCreationRules['new_checklist_items']['is_visible']);
        $assigneeVisible = !empty($taskCreationRules['assignee_user_id']['is_visible']);

        $title = $titleVisible ? trim((string) ($_POST['title'] ?? '')) : 'Sem título';
        $description = $descriptionVisible ? trim((string) ($_POST['description'] ?? '')) : '';
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $dueDate = $_POST['due_date'] ?: null;
        $estimatedInput = (string) ($_POST['estimated_minutes'] ?? '');
        $estimatedMinutes = $estimatedVisible ? parse_duration_to_minutes($estimatedInput) : null;
        $templateId = $templateVisible ? (int) ($_POST['checklist_template_id'] ?? 0) : 0;
        $newChecklistItemsRaw = $newChecklistVisible ? trim((string) ($_POST['new_checklist_items'] ?? '')) : '';
        $assigneeUserId = $assigneeVisible ? (int) ($_POST['assignee_user_id'] ?? 0) : 0;

        $customInput = $_POST['custom_fields'] ?? [];
        $customInput = is_array($customInput) ? $customInput : [];
        $customPayload = [];

        $errors = [];

        if (!empty($taskCreationRules['title']['is_required']) && $title === '') {
            $errors[] = 'Título é obrigatório.';
        }

        if (!empty($taskCreationRules['description']['is_required']) && $description === '') {
            $errors[] = 'Descrição é obrigatória.';
        }

        if (!empty($taskCreationRules['estimated_minutes']['is_required']) && $estimatedInput === '') {
            $errors[] = 'Tempo previsto é obrigatório.';
        }

        if ($estimatedInput !== '' && $estimatedMinutes === null) {
            $errors[] = 'Tempo previsto deve estar no formato 00:00:00.';
        }

        if (!empty($taskCreationRules['checklist_template_id']['is_required']) && $templateId <= 0) {
            $errors[] = 'Checklist (modelo) é obrigatório.';
        }

        if (!empty($taskCreationRules['new_checklist_items']['is_required']) && $newChecklistItemsRaw === '') {
            $errors[] = 'Checklist (novos itens) é obrigatório.';
        }

        if (!empty($taskCreationRules['assignee_user_id']['is_required']) && $assigneeUserId <= 0) {
            $errors[] = 'Atribuído é obrigatório.';
        }

        foreach ($taskCreationRules as $fieldKey => $fieldRule) {
            if (!str_starts_with($fieldKey, 'custom_') || empty($fieldRule['is_visible'])) {
                continue;
            }

            $rawValue = trim((string) ($customInput[$fieldKey] ?? ''));

            if (!empty($fieldRule['is_required']) && $rawValue === '') {
                $errors[] = $fieldRule['label'] . ' é obrigatório.';
                continue;
            }

            if ($rawValue === '') {
                continue;
            }

            $fieldType = (string) ($fieldRule['type'] ?? 'text');

            if ($fieldType === 'number' && !is_numeric($rawValue)) {
                $errors[] = $fieldRule['label'] . ' deve ser numérico.';
                continue;
            }

            if ($fieldType === 'date') {
                $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $rawValue);
                if (!$dateObj || $dateObj->format('Y-m-d') !== $rawValue) {
                    $errors[] = $fieldRule['label'] . ' deve ter uma data válida.';
                    continue;
                }
            }

            if ($fieldType === 'select') {
                $allowedOptions = array_map('strval', is_array($fieldRule['options'] ?? null) ? $fieldRule['options'] : []);
                if ($allowedOptions && !in_array($rawValue, $allowedOptions, true)) {
                    $errors[] = $fieldRule['label'] . ' tem opção inválida.';
                    continue;
                }
            }

            $customPayload[$fieldKey] = $rawValue;
        }

        if ($errors) {
            $message = implode(' ', $errors);
            $_SESSION['flash_error'] = $message;
            $ajaxResponse(false, $message);
            $redirectBack();
        }

        $assigneeUserIdValue = null;
        if ($assigneeUserId > 0) {
            $assigneeCheckStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
            $assigneeCheckStmt->execute([(int) $project['team_id'], $assigneeUserId]);
            if ($assigneeCheckStmt->fetchColumn()) {
                $assigneeUserIdValue = $assigneeUserId;
            }
        }

        $customPayloadJson = $customPayload ? json_encode($customPayload, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare('
            INSERT INTO tasks(
                project_id,
                parent_task_id,
                title,
                description,
                status,
                priority,
                due_date,
                created_by,
                assignee_user_id,
                estimated_minutes,
                actual_minutes,
                custom_fields_json,
                updated_at,
                updated_by
            )
            VALUES (?, NULL, ?, ?, "todo", ?, ?, ?, ?, ?, NULL, ?, CURRENT_TIMESTAMP, ?)
        ');
        $stmt->execute([
            $projectId,
            $title,
            $description,
            $priority,
            $dueDate,
            $userId,
            $assigneeUserIdValue,
            $estimatedMinutes,
            $customPayloadJson,
            $userId
        ]);

        $taskId = (int) $pdo->lastInsertId();

        if ($templateId > 0) {
            $templateItemsStmt = $pdo->prepare('SELECT content FROM checklist_template_items WHERE template_id = ? ORDER BY position ASC, id ASC');
            $templateItemsStmt->execute([$templateId]);
            foreach ($templateItemsStmt->fetchAll(PDO::FETCH_COLUMN) as $templateContent) {
                $content = trim((string) $templateContent);
                if ($content === '') {
                    continue;
                }
                $pdo->prepare('INSERT INTO checklist_items(task_id, content) VALUES (?, ?)')->execute([$taskId, $content]);
            }
        }

        if ($newChecklistItemsRaw !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $newChecklistItemsRaw) ?: [];
            foreach ($lines as $line) {
                $content = trim((string) $line);
                if ($content === '') {
                    continue;
                }
                $pdo->prepare('INSERT INTO checklist_items(task_id, content) VALUES (?, ?)')->execute([$taskId, $content]);
            }
        }

        $_SESSION['flash_success'] = 'Tarefa criada com sucesso.';
        $ajaxResponse(true, 'Tarefa criada com sucesso.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'create_subtask') {
        $parentId = (int) ($_POST['parent_task_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
        $dueDate = null;

        if ($dueDateInput !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
            if ($parsedDate && $parsedDate->format('Y-m-d') === $dueDateInput) {
                $dueDate = $dueDateInput;
            }
        }

        if ($parentId > 0 && $title !== '') {
            $parentAssigneeStmt = $pdo->prepare('SELECT assignee_user_id FROM tasks WHERE id = ? AND project_id = ?');
            $parentAssigneeStmt->execute([$parentId, $projectId]);
            $parentAssignee = $parentAssigneeStmt->fetchColumn();

            $stmt = $pdo->prepare('
                INSERT INTO tasks(
                    project_id,
                    parent_task_id,
                    title,
                    status,
                    due_date,
                    created_by,
                    assignee_user_id,
                    updated_at,
                    updated_by
                )
                VALUES (?, ?, ?, "todo", ?, ?, ?, CURRENT_TIMESTAMP, ?)
            ');
            $stmt->execute([
                $projectId,
                $parentId,
                $title,
                $dueDate,
                $userId,
                $parentAssignee !== false ? $parentAssignee : null,
                $userId
            ]);
        }

        $ajaxResponse(true, 'Sub tarefa criada.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'change_status') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'todo');

        if (in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $stmt = $pdo->prepare('UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
            $stmt->execute([$status, $userId, $taskId, $projectId]);
        }

        $ajaxResponse(true, 'Estado atualizado.');
        $redirectBack();
    }

    if ($action === 'update_time') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $estimatedInput = (string) ($_POST['estimated_minutes'] ?? '');
        $actualInput = (string) ($_POST['actual_minutes'] ?? '');
        $estimatedMinutes = parse_duration_to_minutes($estimatedInput);
        $actualMinutes = parse_duration_to_minutes($actualInput);

        if (($estimatedInput !== '' && $estimatedMinutes === null) || ($actualInput !== '' && $actualMinutes === null)) {
            $_SESSION['flash_error'] = 'Tempo inválido. Use o formato 00:00:00.';
            $ajaxResponse(false, 'Tempo inválido. Use o formato 00:00:00.');
            $redirectBack();
        }

        $addActualSeconds = ($_POST['add_actual_seconds'] ?? '') !== '' ? max(0, (int) $_POST['add_actual_seconds']) : 0;

        if ($addActualSeconds > 0) {
            $currentStmt = $pdo->prepare('SELECT actual_minutes FROM tasks WHERE id = ? AND project_id = ?');
            $currentStmt->execute([$taskId, $projectId]);
            $currentActual = (int) ($currentStmt->fetchColumn() ?: 0);
            $actualMinutes = $currentActual + $addActualSeconds;
        }

        $stmt = $pdo->prepare('UPDATE tasks SET estimated_minutes = ?, actual_minutes = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$estimatedMinutes, $actualMinutes, $userId, $taskId, $projectId]);

        $ajaxResponse(true, 'Tempo atualizado.', [
            'estimated' => format_minutes($estimatedMinutes),
            'actual' => format_minutes($actualMinutes),
            'reload' => true
        ]);
        $redirectBack();
    }

    if ($action === 'add_checklist') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $content = trim((string) ($_POST['content'] ?? ''));

        if ($taskId > 0 && $content !== '') {
            $stmt = $pdo->prepare('INSERT INTO checklist_items(task_id, content) VALUES (?, ?)');
            $stmt->execute([$taskId, $content]);
            $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?')->execute([$userId, $taskId, $projectId]);
        }

        $ajaxResponse(true, 'Checklist atualizado.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'toggle_checklist') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isDone = (int) ($_POST['is_done'] ?? 0);

        $stmt = $pdo->prepare('UPDATE checklist_items SET is_done = ? WHERE id = ? AND task_id IN (SELECT id FROM tasks WHERE project_id = ?)');
        $stmt->execute([$isDone, $itemId, $projectId]);

        $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = (SELECT task_id FROM checklist_items WHERE id = ?)')->execute([$userId, $itemId]);

        $ajaxResponse(true, 'Checklist atualizada.');
        $redirectBack();
    }

    if ($action === 'update_checklist_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $content = trim((string) ($_POST['content'] ?? ''));

        if ($itemId > 0 && $content !== '') {
            $stmt = $pdo->prepare('UPDATE checklist_items SET content = ? WHERE id = ? AND task_id IN (SELECT id FROM tasks WHERE project_id = ?)');
            $stmt->execute([$content, $itemId, $projectId]);

            $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = (SELECT task_id FROM checklist_items WHERE id = ?)')->execute([$userId, $itemId]);
        }

        $ajaxResponse(true, 'Texto da checklist atualizado.');
        $redirectBack();
    }

    if ($action === 'add_task_note') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($taskId > 0 && $note !== '') {
            $stmt = $pdo->prepare('INSERT INTO task_notes(task_id, note, created_by) SELECT id, ?, ? FROM tasks WHERE id = ? AND project_id = ?');
            $stmt->execute([$note, $userId, $taskId, $projectId]);

            if ($stmt->rowCount() > 0) {
                $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?')->execute([$userId, $taskId, $projectId]);
            }
        }

        $ajaxResponse(true, 'Observação guardada.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'upload_task_attachment') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $file = $_FILES['attachment'] ?? null;
        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($taskId > 0 && $hasFile) {
            $taskCheck = $pdo->prepare('SELECT 1 FROM tasks WHERE id = ? AND project_id = ?');
            $taskCheck->execute([$taskId, $projectId]);

            if ($taskCheck->fetchColumn()) {
                $uploadDir = __DIR__ . '/uploads/task_attachments';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
                $safeName = uniqid('task_', true) . ($ext ? '.' . strtolower($ext) : '');
                $targetPath = $uploadDir . '/' . $safeName;

                if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                    $stmt = $pdo->prepare('INSERT INTO task_attachments(task_id, original_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$taskId, (string) $file['name'], 'uploads/task_attachments/' . $safeName, $userId]);
                    $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?')->execute([$userId, $taskId, $projectId]);
                }
            }
        }

        $ajaxResponse(true, 'Ficheiro anexado.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'assign_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $assigneeUserIdValue = null;

        if ($assigneeUserId > 0) {
            $assigneeCheckStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
            $assigneeCheckStmt->execute([(int) $project['team_id'], $assigneeUserId]);

            if ($assigneeCheckStmt->fetchColumn()) {
                $assigneeUserIdValue = $assigneeUserId;
            }
        }

        $stmt = $pdo->prepare('UPDATE tasks SET assignee_user_id = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$assigneeUserIdValue, $userId, $taskId, $projectId]);

        $ajaxResponse(true, 'Responsável atualizado.');
        $redirectBack();
    }

    if ($action === 'delete_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);

        $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND project_id = ? AND created_by = ?');
        $stmt->execute([$taskId, $projectId, $userId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Tarefa eliminada.';
            $ajaxResponse(true, 'Tarefa eliminada.', ['reload' => true]);
        }

        $_SESSION['flash_error'] = 'Só pode eliminar tarefas criadas por si.';
        $ajaxResponse(false, 'Só pode eliminar tarefas criadas por si.');
        $redirectBack();
    }

    if ($action === 'update_due_date') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
        $dueDate = null;

        if ($dueDateInput !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
            if ($parsedDate && $parsedDate->format('Y-m-d') === $dueDateInput) {
                $dueDate = $dueDateInput;
            }
        }

        $stmt = $pdo->prepare('UPDATE tasks SET due_date = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$dueDate, $userId, $taskId, $projectId]);

        $ajaxResponse(true, 'Data prevista atualizada.');
        $redirectBack();
    }

    if ($action === 'add_project_note') {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            $stmt = $pdo->prepare('INSERT INTO project_notes(project_id, note, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$projectId, $note, $userId]);
        }

        $ajaxResponse(true, 'Nota do projeto guardada.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'add_project_note_reply') {
        $projectNoteId = (int) ($_POST['project_note_id'] ?? 0);
        $reply = trim((string) ($_POST['reply'] ?? ''));

        if ($projectNoteId > 0 && $reply !== '') {
            $stmt = $pdo->prepare('INSERT INTO project_note_replies(project_note_id, reply, created_by) SELECT pn.id, ?, ? FROM project_notes pn WHERE pn.id = ? AND pn.project_id = ?');
            $stmt->execute([$reply, $userId, $projectNoteId, $projectId]);
        }

        $ajaxResponse(true, 'Resposta guardada.', ['reload' => true]);
        $redirectBack();
    }

    if ($action === 'upload_project_document') {
        $file = $_FILES['document'] ?? null;
        $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($hasFile) {
            $uploadDir = __DIR__ . '/uploads/project_documents';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
            $safeName = uniqid('project_' . $projectId . '_', true) . ($ext ? '.' . strtolower($ext) : '');
            $targetPath = $uploadDir . '/' . $safeName;

            if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                $stmt = $pdo->prepare('INSERT INTO project_documents(project_id, original_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)');
                $stmt->execute([$projectId, (string) $file['name'], 'uploads/project_documents/' . $safeName, $userId]);
            }
        }

        $ajaxResponse(true, 'Documento do projeto adicionado.', ['reload' => true]);
        $redirectBack();
    }

    $ajaxResponse(false, 'Ação inválida.');
    $redirectBack();
}

$tasksStmt = $pdo->prepare('
    SELECT
        t.*,
        u.name AS creator_name,
        a.name AS assignee_name
    FROM tasks t
    INNER JOIN users u ON u.id = t.created_by
    LEFT JOIN users a ON a.id = t.assignee_user_id
    WHERE t.project_id = ?
    ORDER BY t.parent_task_id IS NOT NULL, t.created_at DESC
');
$tasksStmt->execute([$projectId]);
$allTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

$tasks = [];
$doneHistory = [];
$subtasksByParent = [];

foreach ($allTasks as $task) {
    if ($task['parent_task_id']) {
        $subtasksByParent[(int) $task['parent_task_id']][] = $task;
        continue;
    }

    if ($task['status'] === 'done') {
        $doneHistory[] = $task;
        if (!$showDone) {
            continue;
        }
    }

    $tasks[] = $task;
}

$checkStmt = $pdo->prepare('
    SELECT c.*
    FROM checklist_items c
    INNER JOIN tasks t ON t.id = c.task_id
    WHERE t.project_id = ?
    ORDER BY c.created_at ASC
');
$checkStmt->execute([$projectId]);
$checklistItems = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
$checklistByTask = [];
foreach ($checklistItems as $item) {
    $checklistByTask[(int) $item['task_id']][] = $item;
}

$taskNotesStmt = $pdo->prepare('
    SELECT tn.*, u.name AS user_name
    FROM task_notes tn
    INNER JOIN tasks t ON t.id = tn.task_id
    INNER JOIN users u ON u.id = tn.created_by
    WHERE t.project_id = ?
    ORDER BY tn.created_at DESC
');
$taskNotesStmt->execute([$projectId]);
$taskNotesByTask = [];
foreach ($taskNotesStmt->fetchAll(PDO::FETCH_ASSOC) as $taskNote) {
    $taskNotesByTask[(int) $taskNote['task_id']][] = $taskNote;
}

$taskAttachStmt = $pdo->prepare('
    SELECT ta.*, u.name AS user_name
    FROM task_attachments ta
    INNER JOIN tasks t ON t.id = ta.task_id
    INNER JOIN users u ON u.id = ta.uploaded_by
    WHERE t.project_id = ?
    ORDER BY ta.created_at DESC
');
$taskAttachStmt->execute([$projectId]);
$taskAttachmentsByTask = [];
foreach ($taskAttachStmt->fetchAll(PDO::FETCH_ASSOC) as $taskAttachment) {
    $taskAttachmentsByTask[(int) $taskAttachment['task_id']][] = $taskAttachment;
}

$teamMembersStmt = $pdo->prepare('
    SELECT u.id, u.name
    FROM users u
    INNER JOIN team_members tm ON tm.user_id = u.id
    WHERE tm.team_id = ?
    ORDER BY u.name COLLATE NOCASE ASC
');
$teamMembersStmt->execute([(int) $project['team_id']]);
$teamMembers = $teamMembersStmt->fetchAll(PDO::FETCH_ASSOC);

$projectNotesStmt = $pdo->prepare('
    SELECT pn.*, u.name AS user_name
    FROM project_notes pn
    INNER JOIN users u ON u.id = pn.created_by
    WHERE pn.project_id = ?
    ORDER BY pn.created_at DESC
');
$projectNotesStmt->execute([$projectId]);
$projectNotes = $projectNotesStmt->fetchAll(PDO::FETCH_ASSOC);

$projectNoteRepliesStmt = $pdo->prepare('
    SELECT pr.*, u.name AS user_name, pn.project_id
    FROM project_note_replies pr
    INNER JOIN project_notes pn ON pn.id = pr.project_note_id
    INNER JOIN users u ON u.id = pr.created_by
    WHERE pn.project_id = ?
    ORDER BY pr.created_at ASC
');
$projectNoteRepliesStmt->execute([$projectId]);
$projectNoteRepliesByNote = [];
foreach ($projectNoteRepliesStmt->fetchAll(PDO::FETCH_ASSOC) as $replyRow) {
    $projectNoteRepliesByNote[(int) $replyRow['project_note_id']][] = $replyRow;
}

$projectDocumentsStmt = $pdo->prepare('
    SELECT pd.*, u.name AS user_name
    FROM project_documents pd
    INNER JOIN users u ON u.id = pd.uploaded_by
    WHERE pd.project_id = ?
    ORDER BY pd.created_at DESC
');
$projectDocumentsStmt->execute([$projectId]);
$projectDocuments = $projectDocumentsStmt->fetchAll(PDO::FETCH_ASSOC);

$checklistTemplatesStmt = $pdo->query('
    SELECT
        ct.id,
        ct.name,
        ct.description,
        u.name AS creator_name,
        COUNT(cti.id) AS items_count
    FROM checklist_templates ct
    INNER JOIN users u ON u.id = ct.created_by
    LEFT JOIN checklist_template_items cti ON cti.template_id = ct.id
    GROUP BY ct.id
    ORDER BY ct.name COLLATE NOCASE ASC
');
$checklistTemplates = $checklistTemplatesStmt->fetchAll(PDO::FETCH_ASSOC);

$visibleCustomTaskFields = [];
foreach ($taskCreationRules as $fieldKey => $fieldRule) {
    if (str_starts_with($fieldKey, 'custom_') && !empty($fieldRule['is_visible'])) {
        $visibleCustomTaskFields[$fieldKey] = $fieldRule;
    }
}

$pageTitle = 'Projeto ' . $project['name'];
require __DIR__ . '/partials/header.php';
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success"><?= h((string) $_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?= h((string) $_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<script>window.taskPage = true;</script>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <a href="team.php?id=<?= (int) $project['team_id'] ?>" class="btn btn-link px-0">&larr; Voltar à equipa</a>
        <h1 class="h3 mb-1"><?= h($project['name']) ?></h1>
        <p class="text-muted mb-0">Equipa: <?= h($project['team_name']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="project.php?id=<?= $projectId ?>&view=list&show_done=<?= $showDone ? '1' : '0' ?>">Lista</a>
        <a class="btn btn-outline-secondary" href="project.php?id=<?= $projectId ?>&view=board&show_done=<?= $showDone ? '1' : '0' ?>">Quadro</a>
        <a class="btn btn-outline-primary" href="daily_report.php?project_id=<?= $projectId ?>">Enviar relatório diário</a>
    </div>
</div>

<div class="card shadow-sm soft-card mb-4">
    <div class="card-body">
        <h2 class="h5">Nova tarefa</h2>
        <p class="small text-muted mb-2">Define o tempo previsto na criação. O tempo gasto é gerido depois em cada tarefa.</p>

        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="create_task">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

            <?php if (!empty($taskCreationRules['title']['is_visible'])): ?>
                <div class="col-md-3">
                    <input class="form-control" name="title" placeholder="Título" <?= !empty($taskCreationRules['title']['is_required']) ? 'required' : '' ?>>
                </div>
            <?php endif; ?>

            <?php if (!empty($taskCreationRules['description']['is_visible'])): ?>
                <div class="col-md-3">
                    <input class="form-control" name="description" placeholder="Descrição" <?= !empty($taskCreationRules['description']['is_required']) ? 'required' : '' ?>>
                </div>
            <?php endif; ?>

            <?php if (!empty($taskCreationRules['estimated_minutes']['is_visible'])): ?>
                <div class="col-md-2">
                    <input
                        class="form-control"
                        type="text"
                        name="estimated_minutes"
                        placeholder="00:00:00"
                        pattern="\d{1,3}:\d{2}:\d{2}"
                        <?= !empty($taskCreationRules['estimated_minutes']['is_required']) ? 'required' : '' ?>
                    >
                </div>
            <?php endif; ?>

            <?php if (!empty($taskCreationRules['assignee_user_id']['is_visible'])): ?>
                <div class="col-md-3">
                    <select class="form-select" name="assignee_user_id" <?= !empty($taskCreationRules['assignee_user_id']['is_required']) ? 'required' : '' ?>>
                        <option value="0">Atribuído: sem responsável</option>
                        <?php foreach ($teamMembers as $member): ?>
                            <option value="<?= (int) $member['id'] ?>"><?= h($member['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (!empty($taskCreationRules['checklist_template_id']['is_visible'])): ?>
                <div class="col-md-4">
                    <select class="form-select" name="checklist_template_id" <?= !empty($taskCreationRules['checklist_template_id']['is_required']) ? 'required' : '' ?>>
                        <option value="0">Checklist: sem modelo</option>
                        <?php foreach ($checklistTemplates as $template): ?>
                            <option value="<?= (int) $template['id'] ?>">
                                <?= h($template['name']) ?> (<?= (int) $template['items_count'] ?> itens)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Pode gerir modelos em <a href="checklists.php">Checklists</a>.</small>
                </div>
            <?php endif; ?>

            <?php if (!empty($taskCreationRules['new_checklist_items']['is_visible'])): ?>
                <div class="col-12">
                    <textarea
                        class="form-control"
                        name="new_checklist_items"
                        rows="2"
                        placeholder="Ou escreva novos itens de checklist (1 por linha)"
                        <?= !empty($taskCreationRules['new_checklist_items']['is_required']) ? 'required' : '' ?>
                    ></textarea>
                </div>
            <?php endif; ?>

            <?php foreach ($visibleCustomTaskFields as $customFieldKey => $customFieldRule): ?>
                <?php $customType = (string) ($customFieldRule['type'] ?? 'text'); ?>
                <div class="<?= $customType === 'textarea' ? 'col-12' : 'col-md-3' ?>">
                    <?php if ($customType === 'textarea'): ?>
                        <textarea
                            class="form-control"
                            name="custom_fields[<?= h($customFieldKey) ?>]"
                            rows="2"
                            placeholder="<?= h($customFieldRule['label']) ?>"
                            <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>
                        ></textarea>
                    <?php elseif ($customType === 'date'): ?>
                        <input
                            class="form-control"
                            type="date"
                            name="custom_fields[<?= h($customFieldKey) ?>]"
                            placeholder="<?= h($customFieldRule['label']) ?>"
                            <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>
                        >
                    <?php elseif ($customType === 'number'): ?>
                        <input
                            class="form-control"
                            type="number"
                            name="custom_fields[<?= h($customFieldKey) ?>]"
                            placeholder="<?= h($customFieldRule['label']) ?>"
                            <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>
                        >
                    <?php elseif ($customType === 'select'): ?>
                        <select class="form-select" name="custom_fields[<?= h($customFieldKey) ?>]" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>>
                            <option value=""><?= h($customFieldRule['label']) ?></option>
                            <?php foreach ((array) ($customFieldRule['options'] ?? []) as $customOption): ?>
                                <option value="<?= h((string) $customOption) ?>"><?= h((string) $customOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input
                            class="form-control"
                            name="custom_fields[<?= h($customFieldKey) ?>]"
                            placeholder="<?= h($customFieldRule['label']) ?>"
                            <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>
                        >
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Criar</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Documentos do projeto</h2>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mb-3 ajax-form">
                    <input type="hidden" name="action" value="upload_project_document">
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                    <input class="form-control form-control-sm" type="file" name="document" required>
                    <button class="btn btn-sm btn-outline-secondary">Adicionar</button>
                </form>

                <?php if (!$projectDocuments): ?>
                    <p class="small text-muted mb-0">Ainda não existem documentos do projeto.</p>
                <?php endif; ?>

                <?php foreach ($projectDocuments as $document): ?>
                    <div class="small border rounded p-2 mb-2 bg-light">
                        <a href="<?= h($document['file_path']) ?>" target="_blank" rel="noopener"><?= h($document['original_name']) ?></a><br>
                        <small class="text-muted"><?= h($document['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($document['created_at']))) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Notas do projeto</h2>

                <form method="post" class="d-flex gap-2 mb-3 ajax-form">
                    <input type="hidden" name="action" value="add_project_note">
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                    <input class="form-control form-control-sm" name="note" placeholder="Adicionar nota da equipa" required>
                    <button class="btn btn-sm btn-outline-primary">Guardar</button>
                </form>

                <?php if (!$projectNotes): ?>
                    <p class="small text-muted mb-0">Sem notas no projeto.</p>
                <?php endif; ?>

                <?php foreach ($projectNotes as $note): ?>
                    <div class="small border rounded p-2 mb-2 bg-light">
                        <?= h($note['note']) ?><br>
                        <small class="text-muted"><?= h($note['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($note['created_at']))) ?></small>

                        <?php foreach (($projectNoteRepliesByNote[(int) $note['id']] ?? []) as $reply): ?>
                            <div class="border rounded bg-white mt-2 p-2">
                                <?= h($reply['reply']) ?><br>
                                <small class="text-muted"><?= h($reply['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime((string) $reply['created_at']))) ?></small>
                            </div>
                        <?php endforeach; ?>

                        <form method="post" class="d-flex gap-2 mt-2 ajax-form">
                            <input type="hidden" name="action" value="add_project_note_reply">
                            <input type="hidden" name="project_note_id" value="<?= (int) $note['id'] ?>">
                            <input type="hidden" name="view" value="<?= h($view) ?>">
                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                            <input class="form-control form-control-sm" name="reply" placeholder="Responder à nota" required>
                            <button class="btn btn-sm btn-outline-secondary">Responder</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($view === 'board'): ?>
    <div class="row g-3">
        <?php foreach (['todo' => 'Por Fazer', 'in_progress' => 'Em Progresso', 'done' => 'Concluídas'] as $status => $label): ?>
            <div class="col-md-4">
                <div class="card board-column shadow-sm soft-card">
                    <div class="card-header">
                        <strong><?= h($label) ?></strong>
                    </div>
                    <div class="card-body vstack gap-2">
                        <?php foreach ($tasks as $task): ?>
                            <?php if ($task['status'] !== $status) { continue; } ?>
                            <?php
                                $delta = task_time_delta(
                                    $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null,
                                    $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null
                                );
                            ?>
                            <div class="task-card border rounded p-2 bg-white">
                                <strong><?= h($task['title']) ?></strong>
                                <p class="small text-muted mb-1"><?= h($task['description']) ?></p>

                                <div class="small d-flex gap-2 mb-2 flex-wrap">
                                    <span class="time-chip">Prev: <?= h(format_minutes($task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null)) ?></span>
                                    <span class="time-chip">Real: <?= h(format_minutes($task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null)) ?></span>
                                    <?php if ($delta !== null): ?>
                                        <span class="time-chip <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">
                                            Δ <?= $delta > 0 ? '+' : '-' ?><?= h(format_minutes(abs($delta))) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <form method="post" class="d-flex gap-2 js-ajax-autosubmit ajax-form-inline">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                    <select name="status" class="form-select form-select-sm js-auto-submit-trigger" aria-label="Estado da tarefa" title="Estado">
                                        <option value="todo" <?= $task['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                        <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                        <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                    </select>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="vstack gap-3">
        <?php foreach ($tasks as $task): ?>
            <?php
                $taskId = (int) $task['id'];
                $estimated = $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null;
                $actual = $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null;
                $delta = task_time_delta($estimated, $actual);
                $canDeleteTask = (int) ($task['created_by'] ?? 0) === $userId;
                $taskChecklist = $checklistByTask[$taskId] ?? [];
                $checklistTotal = count($taskChecklist);
                $checklistDone = 0;
                foreach ($taskChecklist as $taskChecklistItem) {
                    if (!empty($taskChecklistItem['is_done'])) {
                        $checklistDone++;
                    }
                }
            ?>
            <div class="card shadow-sm soft-card task-card-compact">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start mb-2 gap-2 flex-wrap">
                        <div>
                            <h2 class="h5 mb-1"><?= h($task['title']) ?></h2>
                            <p class="text-muted mb-1"><?= h($task['description']) ?></p>
                            <small class="text-muted">
                                Criada por <?= h($task['creator_name']) ?> ·
                                Atribuído a <?= h($task['assignee_name'] ?? 'Sem responsável') ?>
                            </small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-<?= task_badge_class($task['status']) ?>"><?= h(status_label($task['status'])) ?></span>
                            <button
                                class="btn btn-sm btn-outline-secondary icon-btn"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#taskDetails<?= $taskId ?>"
                                aria-expanded="false"
                                aria-label="Abrir tarefa"
                            >
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>

                    <div class="collapse" id="taskDetails<?= $taskId ?>">
                    <div class="d-flex gap-2 mb-3 flex-wrap">
                        <span class="time-chip">Tempo previsto: <?= h(format_minutes($estimated)) ?></span>
                        <span class="time-chip">Tempo real: <?= h(format_minutes($actual)) ?></span>
                        <span class="time-chip">Entrega: <?= h($task['due_date'] ? date('d/m/Y', strtotime((string) $task['due_date'])) : 'Sem data') ?></span>
                        <?php if ($delta !== null): ?>
                            <span class="time-chip <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">
                                Discrepância: <?= $delta > 0 ? '+' : '-' ?><?= h(format_minutes(abs($delta))) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="task-compact-controls">
                        <div class="controls-row controls-row-main">
                            <div class="controls-field controls-field-status">
                                <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                    <label class="form-label task-compact-label">Estado</label>
                                    <select name="status" class="form-select form-select-sm js-auto-submit-trigger" aria-label="Estado da tarefa" title="Estado">
                                        <option value="todo" <?= $task['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                        <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                        <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                    </select>
                                </form>
                            </div>

                            <div class="controls-field controls-field-user">
                                <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                    <input type="hidden" name="action" value="assign_task">
                                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                    <label class="form-label task-compact-label">Atribuído</label>
                                    <select name="assignee_user_id" class="form-select form-select-sm js-auto-submit-trigger" aria-label="Responsável da tarefa" title="Atribuído">
                                        <option value="0">Sem responsável</option>
                                        <?php foreach ($teamMembers as $member): ?>
                                            <option value="<?= (int) $member['id'] ?>" <?= (int) ($task['assignee_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>>
                                                <?= h($member['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>

                            <div class="controls-field controls-field-time">
                                <form method="post" class="time-inline-form ajax-form-inline" id="timeEditorForm<?= $taskId ?>">
                                    <input type="hidden" name="action" value="update_time">
                                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                    <div class="time-inline-group">
                                        <label class="form-label task-compact-label">Tempo previsto</label>
                                        <input
                                            class="form-control form-control-sm"
                                            type="text"
                                            name="estimated_minutes"
                                            value="<?= h(format_minutes($estimated)) ?>"
                                            placeholder="00:00:00"
                                            pattern="\d{1,3}:\d{2}:\d{2}"
                                            aria-label="Tempo previsto"
                                            title="Tempo previsto"
                                        >
                                    </div>

                                    <div class="time-inline-group">
                                        <label class="form-label task-compact-label">Tempo real</label>
                                        <input
                                            class="form-control form-control-sm"
                                            type="text"
                                            name="actual_minutes"
                                            value="<?= h(format_minutes($actual)) ?>"
                                            placeholder="00:00:00"
                                            pattern="\d{1,3}:\d{2}:\d{2}"
                                            aria-label="Tempo real"
                                            title="Tempo real"
                                        >
                                    </div>
                                </form>
                            </div>

                            <div class="controls-field controls-field-due-date">
                                <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                    <input type="hidden" name="action" value="update_due_date">
                                    <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                    <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                    <label class="form-label task-compact-label">Data prevista</label>
                                    <input
                                        type="date"
                                        name="due_date"
                                        class="form-control form-control-sm js-auto-submit-trigger"
                                        value="<?= h((string) ($task['due_date'] ?? '')) ?>"
                                        aria-label="Data prevista de entrega"
                                        title="Data prevista de entrega"
                                    >
                                </form>
                            </div>

                            <div class="controls-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-success js-timer-toggle"
                                    data-task-id="<?= $taskId ?>"
                                    aria-label="Iniciar contador"
                                >
                                    <i class="bi bi-play-fill"></i><span>Play</span>
                                </button>

                                <button
                                    class="btn btn-sm btn-outline-dark icon-btn js-ajax-submit"
                                    type="button"
                                    data-form-id="timeEditorForm<?= $taskId ?>"
                                    aria-label="Guardar tempo"
                                >
                                    <i class="bi bi-save"></i>
                                </button>

                                <?php if ($canDeleteTask): ?>
                                    <form method="post" class="ajax-form-inline" onsubmit="return confirm('Tem a certeza que deseja eliminar esta tarefa?');">
                                        <input type="hidden" name="action" value="delete_task">
                                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                        <input type="hidden" name="view" value="<?= h($view) ?>">
                                        <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                        <button class="btn btn-sm btn-outline-danger icon-btn" aria-label="Eliminar tarefa">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="subtask-row">
                            <form method="post" class="task-compact-subtask-form ajax-form">
                                <input type="hidden" name="action" value="create_subtask">
                                <input type="hidden" name="parent_task_id" value="<?= $taskId ?>">
                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                <div class="subtask-description">
                                    <label class="form-label task-compact-label">Nova sub tarefa</label>
                                    <input name="title" class="form-control form-control-sm" placeholder="Descrição da sub tarefa" required>
                                </div>

                                <div class="subtask-due">
                                    <label class="form-label task-compact-label">Data prevista</label>
                                    <input type="date" name="due_date" class="form-control form-control-sm">
                                </div>

                                <button class="btn btn-sm btn-outline-secondary icon-btn" aria-label="Adicionar sub tarefa">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="task-checklist-panel mt-3">
                        <div class="task-checklist-header">
                            <h3 class="h6 mb-0">Checklist</h3>
                            <span class="task-checklist-count"><?= $checklistDone ?>/<?= $checklistTotal ?> concluídos</span>
                        </div>

                        <form method="post" class="task-checklist-add-form ajax-form">
                            <input type="hidden" name="action" value="add_checklist">
                            <input type="hidden" name="task_id" value="<?= $taskId ?>">
                            <input type="hidden" name="view" value="<?= h($view) ?>">
                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                            <input class="form-control form-control-sm" name="content" placeholder="Adicionar item da checklist" required>
                            <button class="btn btn-sm btn-outline-success icon-btn" aria-label="Adicionar item da checklist">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </form>

                        <?php if ($checklistTotal > 0): ?>
                            <div class="task-checklist-list">
                                <?php foreach ($taskChecklist as $checkItem): ?>
                                    <div class="task-checklist-item">
                                        <div class="task-checklist-item-row">
                                            <form method="post" class="ajax-form-inline js-ajax-autosubmit task-checklist-toggle-form">
                                                <input type="hidden" name="action" value="toggle_checklist">
                                                <input type="hidden" name="item_id" value="<?= (int) $checkItem['id'] ?>">
                                                <input type="hidden" name="is_done" value="<?= !empty($checkItem['is_done']) ? '0' : '1' ?>">
                                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                                <input class="form-check-input js-auto-submit-trigger" type="checkbox" <?= !empty($checkItem['is_done']) ? 'checked' : '' ?> aria-label="Concluir item da checklist">
                                            </form>

                                            <form method="post" class="task-checklist-edit-form ajax-form-inline js-ajax-autosubmit">
                                                <input type="hidden" name="action" value="update_checklist_item">
                                                <input type="hidden" name="item_id" value="<?= (int) $checkItem['id'] ?>">
                                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                                <input
                                                    class="form-control form-control-sm js-auto-submit-trigger task-checklist-item-input <?= !empty($checkItem['is_done']) ? 'is-done' : '' ?>"
                                                    type="text"
                                                    name="content"
                                                    value="<?= h($checkItem['content']) ?>"
                                                    aria-label="Texto do item da checklist"
                                                    required
                                                >
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="small text-muted mb-0 mt-2">Sem itens na checklist.</p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($subtasksByParent[$taskId])): ?>
                        <div class="mt-3 border rounded p-2 bg-light-subtle">
                            <div class="small fw-semibold mb-2">Sub tarefas</div>

                            <div class="vstack gap-2">
                                <?php foreach ($subtasksByParent[$taskId] as $subtask): ?>
                                    <?php
                                        $subtaskId = (int) $subtask['id'];
                                        $subEstimated = $subtask['estimated_minutes'] !== null ? (int) $subtask['estimated_minutes'] : null;
                                        $subActual = $subtask['actual_minutes'] !== null ? (int) $subtask['actual_minutes'] : null;
                                        $canDeleteSubtask = (int) ($subtask['created_by'] ?? 0) === $userId;
                                    ?>
                                    <div class="small border rounded px-2 py-2 bg-white">
                                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                            <div>
                                                <div><?= h($subtask['title']) ?></div>
                                                <small class="text-muted">Atribuído a <?= h($subtask['assignee_name'] ?? 'Sem responsável') ?></small>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-<?= task_badge_class((string) $subtask['status']) ?>">
                                                    <?= h(status_label((string) $subtask['status'])) ?>
                                                </span>
                                                <button
                                                    class="btn btn-sm btn-outline-secondary icon-btn"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#subtaskTimer<?= $subtaskId ?>"
                                                    aria-expanded="false"
                                                    aria-label="Abrir sub tarefa"
                                                >
                                                    <i class="bi bi-chevron-down"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2 mt-2 flex-wrap">
                                            <span class="time-chip">Tempo previsto: <?= h(format_minutes($subEstimated)) ?></span>
                                            <span class="time-chip">Tempo real: <?= h(format_minutes($subActual)) ?></span>
                                        </div>

                                        <div class="collapse mt-2" id="subtaskTimer<?= $subtaskId ?>">
                                            <div class="task-compact-controls">
                                                <div class="controls-row controls-row-main">
                                                    <div class="controls-field controls-field-status">
                                                        <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                                            <input type="hidden" name="action" value="change_status">
                                                            <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                            <input type="hidden" name="view" value="<?= h($view) ?>">
                                                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                                            <label class="form-label task-compact-label">Estado</label>
                                                            <select name="status" class="form-select form-select-sm js-auto-submit-trigger" aria-label="Estado da sub tarefa" title="Estado">
                                                                <option value="todo" <?= $subtask['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                                                <option value="in_progress" <?= $subtask['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                                                <option value="done" <?= $subtask['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                                            </select>
                                                        </form>
                                                    </div>

                                                    <div class="controls-field controls-field-user">
                                                        <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                                            <input type="hidden" name="action" value="assign_task">
                                                            <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                            <input type="hidden" name="view" value="<?= h($view) ?>">
                                                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                                            <label class="form-label task-compact-label">Atribuído</label>
                                                            <select name="assignee_user_id" class="form-select form-select-sm js-auto-submit-trigger" aria-label="Responsável da sub tarefa" title="Atribuído">
                                                                <option value="0">Sem responsável</option>
                                                                <?php foreach ($teamMembers as $member): ?>
                                                                    <option value="<?= (int) $member['id'] ?>" <?= (int) ($subtask['assignee_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>>
                                                                        <?= h($member['name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </form>
                                                    </div>

                                                    <div class="controls-field controls-field-time">
                                                        <form method="post" class="time-inline-form ajax-form-inline" id="timeEditorForm<?= $subtaskId ?>">
                                                            <input type="hidden" name="action" value="update_time">
                                                            <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                            <input type="hidden" name="view" value="<?= h($view) ?>">
                                                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                                            <div class="time-inline-group">
                                                                <label class="form-label task-compact-label">Tempo previsto</label>
                                                                <input class="form-control form-control-sm" type="text" name="estimated_minutes" value="<?= h(format_minutes($subEstimated)) ?>" placeholder="00:00:00" pattern="\d{1,3}:\d{2}:\d{2}" aria-label="Tempo previsto da sub tarefa" title="Tempo previsto">
                                                            </div>

                                                            <div class="time-inline-group">
                                                                <label class="form-label task-compact-label">Tempo real</label>
                                                                <input class="form-control form-control-sm" type="text" name="actual_minutes" value="<?= h(format_minutes($subActual)) ?>" placeholder="00:00:00" pattern="\d{1,3}:\d{2}:\d{2}" aria-label="Tempo real da sub tarefa" title="Tempo real">
                                                            </div>
                                                        </form>
                                                    </div>

                                                    <div class="controls-field controls-field-due-date">
                                                        <form method="post" class="ajax-form-inline js-ajax-autosubmit">
                                                            <input type="hidden" name="action" value="update_due_date">
                                                            <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                            <input type="hidden" name="view" value="<?= h($view) ?>">
                                                            <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">

                                                            <label class="form-label task-compact-label">Data prevista</label>
                                                            <input type="date" name="due_date" class="form-control form-control-sm js-auto-submit-trigger" value="<?= h((string) ($subtask['due_date'] ?? '')) ?>" aria-label="Data prevista da sub tarefa" title="Data prevista da sub tarefa">
                                                        </form>
                                                    </div>

                                                    <div class="controls-actions">
                                                        <button type="button" class="btn btn-sm btn-outline-success js-timer-toggle" data-task-id="<?= $subtaskId ?>" aria-label="Iniciar contador sub tarefa">
                                                            <i class="bi bi-play-fill"></i><span>Play</span>
                                                        </button>

                                                        <button class="btn btn-sm btn-outline-dark icon-btn js-ajax-submit" type="button" data-form-id="timeEditorForm<?= $subtaskId ?>" aria-label="Guardar tempo sub tarefa">
                                                            <i class="bi bi-save"></i>
                                                        </button>

                                                        <?php if ($canDeleteSubtask): ?>
                                                            <form method="post" class="ajax-form-inline" onsubmit="return confirm('Tem a certeza que deseja eliminar esta sub tarefa?');">
                                                                <input type="hidden" name="action" value="delete_task">
                                                                <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                                                <button class="btn btn-sm btn-outline-danger icon-btn" aria-label="Eliminar sub tarefa">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <form method="post" class="d-none ajax-form-inline" id="timerForm<?= $subtaskId ?>">
                                                <input type="hidden" name="action" value="update_time">
                                                <input type="hidden" name="task_id" value="<?= $subtaskId ?>">
                                                <input type="hidden" name="estimated_minutes" value="<?= h(format_minutes($subEstimated)) ?>">
                                                <input type="hidden" name="add_actual_seconds" value="0" class="js-add-actual">
                                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="d-none ajax-form-inline" id="timerForm<?= $taskId ?>">
                        <input type="hidden" name="action" value="update_time">
                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                        <input type="hidden" name="estimated_minutes" value="<?= h(format_minutes($estimated)) ?>">
                        <input type="hidden" name="add_actual_seconds" value="0" class="js-add-actual">
                        <input type="hidden" name="view" value="<?= h($view) ?>">
                        <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                    </form>

                    <div class="row g-2 mt-3">
                        <div class="col-md-6">
                            <h3 class="h6">Observações</h3>

                            <form method="post" class="d-flex gap-2 mb-2 ajax-form">
                                <input type="hidden" name="action" value="add_task_note">
                                <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                <input class="form-control form-control-sm" name="note" placeholder="Adicionar observação" required>
                                <button class="btn btn-sm btn-outline-primary icon-btn" aria-label="Guardar">
                                    <i class="bi bi-save"></i>
                                </button>
                            </form>

                            <?php foreach (($taskNotesByTask[$taskId] ?? []) as $note): ?>
                                <div class="small border rounded p-2 mb-1 bg-light">
                                    <?= h($note['note']) ?><br>
                                    <small class="text-muted"><?= h($note['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($note['created_at']))) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">Documentos anexados</h3>

                            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mb-2 ajax-form">
                                <input type="hidden" name="action" value="upload_task_attachment">
                                <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                <input type="hidden" name="view" value="<?= h($view) ?>">
                                <input type="hidden" name="show_done" value="<?= $showDone ? '1' : '0' ?>">
                                <input class="form-control form-control-sm" type="file" name="attachment" required>
                                <button class="btn btn-sm btn-outline-secondary icon-btn" aria-label="Anexar">
                                    <i class="bi bi-paperclip"></i>
                                </button>
                            </form>

                            <?php foreach (($taskAttachmentsByTask[$taskId] ?? []) as $attachment): ?>
                                <div class="small border rounded p-2 mb-1 bg-light">
                                    <a href="<?= h($attachment['file_path']) ?>" target="_blank" rel="noopener"><?= h($attachment['original_name']) ?></a><br>
                                    <small class="text-muted"><?= h($attachment['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($attachment['created_at']))) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const timerStorageKey = (taskId) => `task_timer_${taskId}`;

    const flashSaved = (element) => {
        if (!element) return;
        element.classList.remove('ajax-flash');
        void element.offsetWidth;
        element.classList.add('ajax-flash');
    };

    const setPending = (element, pending) => {
        if (!element) return;
        element.classList.toggle('ajax-pending', pending);
    };

    const showMessage = (message, isError = false) => {
        if (!message) return;
        if (window.bootstrap && document.body) {
            const holder = document.createElement('div');
            holder.className = 'position-fixed top-0 end-0 p-3';
            holder.style.zIndex = '1080';
            holder.innerHTML = `
                <div class="toast align-items-center text-bg-${isError ? 'danger' : 'success'} border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Fechar"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(holder);
            const closeBtn = holder.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => holder.remove());
            }
            setTimeout(() => holder.remove(), 2500);
        } else {
            alert(message);
        }
    };

    const ajaxSubmitForm = async (form, options = {}) => {
        if (!form) return null;

        const formData = new FormData(form);
        const target = options.pendingTarget || form;

        setPending(target, true);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!data.ok) {
                showMessage(data.message || 'Ocorreu um erro.', true);
                return data;
            }

            if (data.reload) {
                window.location.reload();
                return data;
            }

            flashSaved(target);

            if (options.successMessage !== false) {
                showMessage(data.message || 'Guardado com sucesso.');
            }

            return data;
        } catch (error) {
            showMessage('Erro de comunicação com o servidor.', true);
            return null;
        } finally {
            setPending(target, false);
        }
    };

    document.querySelectorAll('.js-auto-submit-trigger').forEach((input) => {
        input.addEventListener('change', async () => {
            const form = input.closest('form');
            await ajaxSubmitForm(form, {
                pendingTarget: form.closest('.controls-field') || form,
                successMessage: false
            });
        });
    });

    document.querySelectorAll('.ajax-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            await ajaxSubmitForm(form);
        });
    });

    document.querySelectorAll('.js-ajax-submit').forEach((button) => {
        button.addEventListener('click', async () => {
            const formId = button.dataset.formId || '';
            const form = document.getElementById(formId);
            await ajaxSubmitForm(form, {
                pendingTarget: form?.closest('.controls-field') || form
            });
        });
    });

    const timerIntervals = new Map();

    const formatElapsed = (totalSeconds) => {
        const safeSeconds = Math.max(0, totalSeconds);
        const hours = Math.floor(safeSeconds / 3600);
        const minutes = Math.floor((safeSeconds % 3600) / 60);
        const seconds = safeSeconds % 60;

        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    };

    const setButtonState = (button, running, elapsedSeconds = 0) => {
        const icon = button.querySelector('i');
        const label = button.querySelector('span');

        button.classList.toggle('btn-outline-success', !running);
        button.classList.toggle('btn-outline-warning', running);
        button.setAttribute('aria-label', running ? 'Parar contador e guardar' : 'Iniciar contador');

        if (icon) {
            icon.className = running ? 'bi bi-stop-fill' : 'bi bi-play-fill';
        }

        if (label) {
            label.textContent = running ? `Stop ${formatElapsed(elapsedSeconds)}` : 'Play';
        }
    };

    const stopLiveCounter = (taskId) => {
        const intervalId = timerIntervals.get(taskId);
        if (intervalId) {
            window.clearInterval(intervalId);
            timerIntervals.delete(taskId);
        }
    };

    const startLiveCounter = (button, taskId, startedAt) => {
        stopLiveCounter(taskId);

        const render = () => {
            const elapsedSeconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
            setButtonState(button, true, elapsedSeconds);
        };

        render();
        const intervalId = window.setInterval(render, 1000);
        timerIntervals.set(taskId, intervalId);
    };

    document.querySelectorAll('.js-timer-toggle').forEach((button) => {
        const taskId = Number(button.dataset.taskId || 0);
        if (!taskId) return;

        const startedAt = Number(localStorage.getItem(timerStorageKey(taskId)) || 0);

        if (startedAt) {
            startLiveCounter(button, taskId, startedAt);
        } else {
            setButtonState(button, false);
        }

        button.addEventListener('click', async () => {
            const now = Date.now();
            const currentStartedAt = Number(localStorage.getItem(timerStorageKey(taskId)) || 0);

            if (!currentStartedAt) {
                localStorage.setItem(timerStorageKey(taskId), String(now));
                startLiveCounter(button, taskId, now);
                return;
            }

            stopLiveCounter(taskId);

            const elapsedSeconds = Math.max(1, Math.floor((now - currentStartedAt) / 1000));
            localStorage.removeItem(timerStorageKey(taskId));
            setButtonState(button, false);

            const form = document.getElementById(`timerForm${taskId}`);
            if (!form) return;

            const input = form.querySelector('.js-add-actual');
            if (!input) return;

            input.value = String(elapsedSeconds);

            await ajaxSubmitForm(form, {
                pendingTarget: button.closest('.controls-actions') || button.closest('.collapse') || button,
                successMessage: false
            });

            window.location.reload();
        });
    });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
