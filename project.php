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

$taskCreationCatalog = task_creation_field_catalog_for_team($pdo, (int) $project['team_id']);
$taskCreationRules = task_creation_field_rules($pdo, (int) $project['team_id'], $projectId, $taskCreationCatalog);

$view = $_GET['view'] ?? 'list';
$showDone = (int) ($_GET['show_done'] ?? 1) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_task') {
        $titleVisible = !empty($taskCreationRules['title']['is_visible']);
        $descriptionVisible = !empty($taskCreationRules['description']['is_visible']);
        $estimatedVisible = !empty($taskCreationRules['estimated_minutes']['is_visible']);
        $templateVisible = !empty($taskCreationRules['checklist_template_id']['is_visible']);
        $newChecklistVisible = !empty($taskCreationRules['new_checklist_items']['is_visible']);
        $assigneeVisible = !empty($taskCreationRules['assignee_user_id']['is_visible']);

        $title = $titleVisible ? trim((string) ($_POST['title'] ?? '')) : 'Sem título';
        $description = $descriptionVisible ? trim((string) ($_POST['description'] ?? '')) : '';
        $priority = $_POST['priority'] ?? 'normal';
        $dueDate = $_POST['due_date'] ?: null;
        $estimatedInput = (string) ($_POST['estimated_minutes'] ?? '');
        $estimatedMinutes = $estimatedVisible ? parse_duration_to_minutes($estimatedInput) : null;
        $templateId = $templateVisible ? (int) ($_POST['checklist_template_id'] ?? 0) : 0;
        $newChecklistItemsRaw = $newChecklistVisible ? trim((string) ($_POST['new_checklist_items'] ?? '')) : '';
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);

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
            $_SESSION['flash_error'] = implode(' ', $errors);
            redirect('project.php?id=' . $projectId . '&view=' . $view . '&show_done=' . ($showDone ? '1' : '0'));
        }

        if ($title !== '') {
            $customPayloadJson = $customPayload ? json_encode($customPayload, JSON_UNESCAPED_UNICODE) : null;
            $assigneeUserIdValue = null;
            if ($assigneeUserId > 0) {
                $assigneeCheckStmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
                $assigneeCheckStmt->execute([(int) $project['team_id'], $assigneeUserId]);
                if ($assigneeCheckStmt->fetchColumn()) {
                    $assigneeUserIdValue = $assigneeUserId;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, description, status, priority, due_date, created_by, assignee_user_id, estimated_minutes, actual_minutes, custom_fields_json, updated_at, updated_by) VALUES (?, NULL, ?, ?, "todo", ?, ?, ?, ?, ?, NULL, ?, CURRENT_TIMESTAMP, ?)');
            $stmt->execute([$projectId, $title, $description, $priority, $dueDate, $userId, $assigneeUserIdValue, $estimatedMinutes, $customPayloadJson, $userId]);
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
        }
    }

    if ($action === 'create_subtask') {
        $parentId = (int) ($_POST['parent_task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
        $dueDate = null;

        if ($dueDateInput !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $dueDateInput);
            if ($parsedDate && $parsedDate->format('Y-m-d') === $dueDateInput) {
                $dueDate = $dueDateInput;
            }
        }

        if ($parentId && $title !== '') {
            $parentAssigneeStmt = $pdo->prepare('SELECT assignee_user_id FROM tasks WHERE id = ? AND project_id = ?');
            $parentAssigneeStmt->execute([$parentId, $projectId]);
            $parentAssignee = $parentAssigneeStmt->fetchColumn();

            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, status, due_date, created_by, assignee_user_id, updated_at, updated_by) VALUES (?, ?, ?, "todo", ?, ?, ?, CURRENT_TIMESTAMP, ?)');
            $stmt->execute([$projectId, $parentId, $title, $dueDate, $userId, $parentAssignee !== false ? $parentAssignee : null, $userId]);
        }
    }

    if ($action === 'change_status') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? 'todo';

        if (in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $stmt = $pdo->prepare('UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
            $stmt->execute([$status, $userId, $taskId, $projectId]);
        }
    }

    if ($action === 'update_time') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $estimatedInput = (string) ($_POST['estimated_minutes'] ?? '');
        $actualInput = (string) ($_POST['actual_minutes'] ?? '');
        $estimatedMinutes = parse_duration_to_minutes($estimatedInput);
        $actualMinutes = parse_duration_to_minutes($actualInput);

        if (($estimatedInput !== '' && $estimatedMinutes === null) || ($actualInput !== '' && $actualMinutes === null)) {
            $_SESSION['flash_error'] = 'Tempo inválido. Use o formato 00:00:00.';
            redirect('project.php?id=' . $projectId . '&view=' . $view . '&show_done=' . ($showDone ? '1' : '0'));
        }
        $addActualMinutes = ($_POST['add_actual_minutes'] ?? '') !== '' ? max(0, (int) $_POST['add_actual_minutes']) : 0;

        if ($addActualMinutes > 0) {
            $currentStmt = $pdo->prepare('SELECT actual_minutes FROM tasks WHERE id = ? AND project_id = ?');
            $currentStmt->execute([$taskId, $projectId]);
            $currentActual = (int) ($currentStmt->fetchColumn() ?: 0);
            $actualMinutes = $currentActual + $addActualMinutes;
        }

        $stmt = $pdo->prepare('UPDATE tasks SET estimated_minutes = ?, actual_minutes = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?');
        $stmt->execute([$estimatedMinutes, $actualMinutes, $userId, $taskId, $projectId]);
    }

    if ($action === 'add_checklist') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($taskId && $content !== '') {
            $stmt = $pdo->prepare('INSERT INTO checklist_items(task_id, content) VALUES (?, ?)');
            $stmt->execute([$taskId, $content]);
            $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?')->execute([$userId, $taskId, $projectId]);
        }
    }

    if ($action === 'toggle_checklist') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isDone = (int) ($_POST['is_done'] ?? 0);
        $stmt = $pdo->prepare('UPDATE checklist_items SET is_done = ? WHERE id = ? AND task_id IN (SELECT id FROM tasks WHERE project_id = ?)');
        $stmt->execute([$isDone, $itemId, $projectId]);
        $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = (SELECT task_id FROM checklist_items WHERE id = ?)')->execute([$userId, $itemId]);
    }

    if ($action === 'add_task_note') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($taskId > 0 && $note !== '') {
            $stmt = $pdo->prepare('INSERT INTO task_notes(task_id, note, created_by) SELECT id, ?, ? FROM tasks WHERE id = ? AND project_id = ?');
            $stmt->execute([$note, $userId, $taskId, $projectId]);
            if ($stmt->rowCount() > 0) {
                $pdo->prepare('UPDATE tasks SET updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ? AND project_id = ?')->execute([$userId, $taskId, $projectId]);
            }
        }
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
    }

    if ($action === 'add_project_note') {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note !== '') {
            $stmt = $pdo->prepare('INSERT INTO project_notes(project_id, note, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$projectId, $note, $userId]);
        }
    }


    if ($action === 'add_project_note_reply') {
        $projectNoteId = (int) ($_POST['project_note_id'] ?? 0);
        $reply = trim((string) ($_POST['reply'] ?? ''));
        if ($projectNoteId > 0 && $reply !== '') {
            $stmt = $pdo->prepare('INSERT INTO project_note_replies(project_note_id, reply, created_by) SELECT pn.id, ?, ? FROM project_notes pn WHERE pn.id = ? AND pn.project_id = ?');
            $stmt->execute([$reply, $userId, $projectNoteId, $projectId]);
        }
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
    }

    redirect('project.php?id=' . $projectId . '&view=' . $view . '&show_done=' . ($showDone ? '1' : '0'));
}

$tasksStmt = $pdo->prepare('SELECT t.*, u.name AS creator_name, a.name AS assignee_name FROM tasks t INNER JOIN users u ON u.id = t.created_by LEFT JOIN users a ON a.id = t.assignee_user_id WHERE t.project_id = ? ORDER BY t.parent_task_id IS NOT NULL, t.created_at DESC');
$tasksStmt->execute([$projectId]);
$allTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

$tasks = [];
$doneHistory = [];
$subtasksByParent = [];
foreach ($allTasks as $task) {
    if ($task['parent_task_id']) {
        $subtasksByParent[$task['parent_task_id']][] = $task;
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

$checkStmt = $pdo->prepare('SELECT c.* FROM checklist_items c INNER JOIN tasks t ON t.id = c.task_id WHERE t.project_id = ? ORDER BY c.created_at ASC');
$checkStmt->execute([$projectId]);
$checklistItems = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
$checklistByTask = [];
foreach ($checklistItems as $item) {
    $checklistByTask[$item['task_id']][] = $item;
}

$taskNotesStmt = $pdo->prepare('SELECT tn.*, u.name AS user_name FROM task_notes tn INNER JOIN tasks t ON t.id = tn.task_id INNER JOIN users u ON u.id = tn.created_by WHERE t.project_id = ? ORDER BY tn.created_at DESC');
$taskNotesStmt->execute([$projectId]);
$taskNotesByTask = [];
foreach ($taskNotesStmt->fetchAll(PDO::FETCH_ASSOC) as $taskNote) {
    $taskNotesByTask[$taskNote['task_id']][] = $taskNote;
}

$taskAttachStmt = $pdo->prepare('SELECT ta.*, u.name AS user_name FROM task_attachments ta INNER JOIN tasks t ON t.id = ta.task_id INNER JOIN users u ON u.id = ta.uploaded_by WHERE t.project_id = ? ORDER BY ta.created_at DESC');
$taskAttachStmt->execute([$projectId]);
$taskAttachmentsByTask = [];
foreach ($taskAttachStmt->fetchAll(PDO::FETCH_ASSOC) as $taskAttachment) {
    $taskAttachmentsByTask[$taskAttachment['task_id']][] = $taskAttachment;
}

$teamMembersStmt = $pdo->prepare('SELECT u.id, u.name FROM users u INNER JOIN team_members tm ON tm.user_id = u.id WHERE tm.team_id = ? ORDER BY u.name COLLATE NOCASE ASC');
$teamMembersStmt->execute([(int) $project['team_id']]);
$teamMembers = $teamMembersStmt->fetchAll(PDO::FETCH_ASSOC);

$projectNotesStmt = $pdo->prepare('SELECT pn.*, u.name AS user_name FROM project_notes pn INNER JOIN users u ON u.id = pn.created_by WHERE pn.project_id = ? ORDER BY pn.created_at DESC');
$projectNotesStmt->execute([$projectId]);
$projectNotes = $projectNotesStmt->fetchAll(PDO::FETCH_ASSOC);

$projectNoteRepliesStmt = $pdo->prepare('SELECT pr.*, u.name AS user_name, pn.project_id FROM project_note_replies pr INNER JOIN project_notes pn ON pn.id = pr.project_note_id INNER JOIN users u ON u.id = pr.created_by WHERE pn.project_id = ? ORDER BY pr.created_at ASC');
$projectNoteRepliesStmt->execute([$projectId]);
$projectNoteRepliesByNote = [];
foreach ($projectNoteRepliesStmt->fetchAll(PDO::FETCH_ASSOC) as $replyRow) {
    $projectNoteRepliesByNote[(int) $replyRow['project_note_id']][] = $replyRow;
}

$projectDocumentsStmt = $pdo->prepare('SELECT pd.*, u.name AS user_name FROM project_documents pd INNER JOIN users u ON u.id = pd.uploaded_by WHERE pd.project_id = ? ORDER BY pd.created_at DESC');
$projectDocumentsStmt->execute([$projectId]);
$projectDocuments = $projectDocumentsStmt->fetchAll(PDO::FETCH_ASSOC);

$checklistTemplatesStmt = $pdo->query('SELECT ct.id, ct.name, ct.description, u.name AS creator_name, COUNT(cti.id) AS items_count FROM checklist_templates ct INNER JOIN users u ON u.id = ct.created_by LEFT JOIN checklist_template_items cti ON cti.template_id = ct.id GROUP BY ct.id ORDER BY ct.name COLLATE NOCASE ASC');
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
<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= h((string) $_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div><?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h((string) $_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>
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
            <?php if (!empty($taskCreationRules['title']['is_visible'])): ?>
                <div class="col-md-3"><input class="form-control" name="title" placeholder="Título" <?= !empty($taskCreationRules['title']['is_required']) ? 'required' : '' ?>></div>
            <?php endif; ?>
            <?php if (!empty($taskCreationRules['description']['is_visible'])): ?>
                <div class="col-md-3"><input class="form-control" name="description" placeholder="Descrição" <?= !empty($taskCreationRules['description']['is_required']) ? 'required' : '' ?>></div>
            <?php endif; ?>
            <?php if (!empty($taskCreationRules['estimated_minutes']['is_visible'])): ?>
                <div class="col-md-2"><input class="form-control" type="text" name="estimated_minutes" placeholder="00:00:00" pattern="\d{1,3}:\d{2}:\d{2}" <?= !empty($taskCreationRules['estimated_minutes']['is_required']) ? 'required' : '' ?>></div>
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
                            <option value="<?= (int) $template['id'] ?>"><?= h($template['name']) ?> (<?= (int) $template['items_count'] ?> itens)</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Pode gerir modelos em <a href="checklists.php">Checklists</a>.</small>
                </div>
            <?php endif; ?>
            <?php if (!empty($taskCreationRules['new_checklist_items']['is_visible'])): ?>
                <div class="col-12"><textarea class="form-control" name="new_checklist_items" rows="2" placeholder="Ou escreva novos itens de checklist (1 por linha)" <?= !empty($taskCreationRules['new_checklist_items']['is_required']) ? 'required' : '' ?>></textarea></div>
            <?php endif; ?>
            <?php foreach ($visibleCustomTaskFields as $customFieldKey => $customFieldRule): ?>
                <?php $customType = (string) ($customFieldRule['type'] ?? 'text'); ?>
                <div class="<?= $customType === 'textarea' ? 'col-12' : 'col-md-3' ?>">
                    <?php if ($customType === 'textarea'): ?>
                        <textarea class="form-control" name="custom_fields[<?= h($customFieldKey) ?>]" rows="2" placeholder="<?= h($customFieldRule['label']) ?>" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>></textarea>
                    <?php elseif ($customType === 'date'): ?>
                        <input class="form-control" type="date" name="custom_fields[<?= h($customFieldKey) ?>]" placeholder="<?= h($customFieldRule['label']) ?>" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>>
                    <?php elseif ($customType === 'number'): ?>
                        <input class="form-control" type="number" name="custom_fields[<?= h($customFieldKey) ?>]" placeholder="<?= h($customFieldRule['label']) ?>" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>>
                    <?php elseif ($customType === 'select'): ?>
                        <select class="form-select" name="custom_fields[<?= h($customFieldKey) ?>]" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>>
                            <option value=""><?= h($customFieldRule['label']) ?></option>
                            <?php foreach ((array) ($customFieldRule['options'] ?? []) as $customOption): ?>
                                <option value="<?= h((string) $customOption) ?>"><?= h((string) $customOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input class="form-control" name="custom_fields[<?= h($customFieldKey) ?>]" placeholder="<?= h($customFieldRule['label']) ?>" <?= !empty($customFieldRule['is_required']) ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="col-md-2"><button class="btn btn-primary w-100">Criar</button></div>
        </form>
    </div>
</div>


<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Documentos do projeto</h2>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mb-3">
                    <input type="hidden" name="action" value="upload_project_document">
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
                <form method="post" class="d-flex gap-2 mb-3">
                    <input type="hidden" name="action" value="add_project_note">
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
                        <form method="post" class="d-flex gap-2 mt-2">
                            <input type="hidden" name="action" value="add_project_note_reply">
                            <input type="hidden" name="project_note_id" value="<?= (int) $note['id'] ?>">
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
        <div class="col-md-4"><div class="card board-column shadow-sm soft-card"><div class="card-header"><strong><?= h($label) ?></strong></div><div class="card-body vstack gap-2">
            <?php foreach ($tasks as $task): if ($task['status'] !== $status) { continue; } ?>
                <?php $delta = task_time_delta($task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null, $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null); ?>
                <div class="task-card border rounded p-2 bg-white">
                    <strong><?= h($task['title']) ?></strong>
                    <p class="small text-muted mb-1"><?= h($task['description']) ?></p>
                    <div class="small d-flex gap-2 mb-2"><span class="time-chip">Prev: <?= h(format_minutes($task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null)) ?></span><span class="time-chip">Real: <?= h(format_minutes($task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null)) ?></span><?php if ($delta !== null): ?><span class="time-chip <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">Δ <?= $delta > 0 ? '+' : '' ?><?= h(format_minutes(abs($delta))) ?></span><?php endif; ?></div>
                    <form method="post" class="d-flex gap-2 js-auto-submit-select">
                        <input type="hidden" name="action" value="change_status"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <select name="status" class="form-select form-select-sm js-auto-submit-trigger"><option value="todo" <?= $task['status']==='todo'?'selected':'' ?>>Por Fazer</option><option value="in_progress" <?= $task['status']==='in_progress'?'selected':'' ?>>Em Progresso</option><option value="done" <?= $task['status']==='done'?'selected':'' ?>>Concluída</option></select>
                    </form>
                </div>
            <?php endforeach; ?>
        </div></div></div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="vstack gap-3">
<?php foreach ($tasks as $task): ?>
    <?php $estimated = $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null; $actual = $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null; $delta = task_time_delta($estimated, $actual); ?>
    <div class="card shadow-sm soft-card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2"><div><h2 class="h5 mb-1"><?= h($task['title']) ?></h2><p class="text-muted mb-1"><?= h($task['description']) ?></p><small class="text-muted">Criada por <?= h($task['creator_name']) ?> · Atribuído a <?= h($task['assignee_name'] ?? 'Sem responsável') ?></small></div><span class="badge bg-<?= task_badge_class($task['status']) ?>"><?= h(status_label($task['status'])) ?></span></div>
        <div class="d-flex gap-2 mb-3 flex-wrap"><span class="time-chip">Tempo previsto: <?= h(format_minutes($estimated)) ?></span><span class="time-chip">Tempo real: <?= h(format_minutes($actual)) ?></span><span class="time-chip">Entrega: <?= h($task['due_date'] ? date('d/m/Y', strtotime((string) $task['due_date'])) : 'Sem data') ?></span><?php if ($delta !== null): ?><span class="time-chip <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">Discrepância: <?= $delta > 0 ? '+' : '-' ?><?= h(format_minutes(abs($delta))) ?></span><?php endif; ?></div>
        <!-- Layout clássico mantido para estabilidade visual e evitar regressões na view de projeto. -->
        <div class="row g-2 align-items-end">
            <div class="col-lg-3 col-md-6">
                <form method="post" class="vstack gap-1 js-auto-submit-select">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <label class="form-label small mb-0">Estado</label>
                    <div class="d-flex gap-2">
                        <select name="status" class="form-select form-select-sm js-auto-submit-trigger">
                            <option value="todo" <?= $task['status']==='todo'?'selected':'' ?>>Por Fazer</option>
                            <option value="in_progress" <?= $task['status']==='in_progress'?'selected':'' ?>>Em Progresso</option>
                            <option value="done" <?= $task['status']==='done'?'selected':'' ?>>Concluída</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="col-lg-3 col-md-6">
                <form method="post" class="vstack gap-1 js-auto-submit-select">
                    <input type="hidden" name="action" value="assign_task">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <label class="form-label small mb-0">Atribuído</label>
                    <div class="d-flex gap-2">
                        <select name="assignee_user_id" class="form-select form-select-sm js-auto-submit-trigger">
                            <option value="0">Sem responsável</option>
                            <?php foreach ($teamMembers as $member): ?>
                                <option value="<?= (int) $member['id'] ?>" <?= (int) ($task['assignee_user_id'] ?? 0) === (int) $member['id'] ? 'selected' : '' ?>><?= h($member['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="col-lg-6 col-md-12">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="update_time">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-0">Tempo previsto</label>
                        <input class="form-control form-control-sm" type="text" name="estimated_minutes" value="<?= h(format_minutes($estimated)) ?>" placeholder="00:00:00" pattern="\d{1,3}:\d{2}:\d{2}">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-0">Tempo real</label>
                        <input class="form-control form-control-sm" type="text" name="actual_minutes" value="<?= h(format_minutes($actual)) ?>" placeholder="00:00:00" pattern="\d{1,3}:\d{2}:\d{2}">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-sm btn-outline-dark icon-btn" aria-label="Guardar tempo"><i class="bi bi-save"></i></button>
                    </div>
                </form>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-success js-start-timer" data-task-id="<?= (int) $task['id'] ?>" aria-label="Iniciar contador"><i class="bi bi-play-fill"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-warning js-stop-timer" data-task-id="<?= (int) $task['id'] ?>" aria-label="Parar contador e guardar"><i class="bi bi-stop-fill"></i></button>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <form method="post" class="vstack gap-1 js-auto-submit-select">
                    <input type="hidden" name="action" value="update_due_date">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <label class="form-label small mb-0">Data prevista de entrega</label>
                    <input type="date" name="due_date" class="form-control form-control-sm js-auto-submit-trigger" value="<?= h((string) ($task['due_date'] ?? '')) ?>">
                </form>
            </div>
            <div class="col-12">
                <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
                    <input type="hidden" name="action" value="create_subtask">
                    <input type="hidden" name="parent_task_id" value="<?= (int) $task['id'] ?>">
                    <div class="flex-grow-1">
                        <label class="form-label small mb-0">Nova sub tarefa</label>
                        <input name="title" class="form-control form-control-sm" placeholder="Descrição da sub tarefa" required>
                    </div>
                    <div>
                        <label class="form-label small mb-0">Data prevista</label>
                        <input type="date" name="due_date" class="form-control form-control-sm">
                    </div>
                    <button class="btn btn-sm btn-outline-secondary icon-btn" aria-label="Adicionar sub tarefa"><i class="bi bi-plus-lg"></i></button>
                </form>
            </div>
        </div>
        <?php if (!empty($subtasksByParent[$task['id']])): ?>
            <div class="mt-3 border rounded p-2 bg-light-subtle">
                <div class="small fw-semibold mb-2">Sub tarefas</div>
                <div class="vstack gap-1">
                    <?php foreach ($subtasksByParent[$task['id']] as $subtask): ?>
                        <?php $subEstimated = $subtask['estimated_minutes'] !== null ? (int) $subtask['estimated_minutes'] : null; $subActual = $subtask['actual_minutes'] !== null ? (int) $subtask['actual_minutes'] : null; ?>
                        <div class="small border rounded px-2 py-1 bg-white">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span><?= h($subtask['title']) ?></span>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-<?= task_badge_class((string) $subtask['status']) ?>"><?= h(status_label((string) $subtask['status'])) ?></span>
                                    <button class="btn btn-sm btn-outline-secondary icon-btn" type="button" data-bs-toggle="collapse" data-bs-target="#subtaskTimer<?= (int) $subtask['id'] ?>" aria-expanded="false" aria-label="Abrir sub tarefa"><i class="bi bi-chevron-down"></i></button>
                                </div>
                            </div>
                            <div class="collapse mt-2" id="subtaskTimer<?= (int) $subtask['id'] ?>">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="time-chip">Prev: <?= h(format_minutes($subEstimated)) ?></span>
                                    <span class="time-chip">Real: <?= h(format_minutes($subActual)) ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-success js-start-timer" data-task-id="<?= (int) $subtask['id'] ?>" aria-label="Iniciar contador sub tarefa"><i class="bi bi-play-fill"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-warning js-stop-timer" data-task-id="<?= (int) $subtask['id'] ?>" aria-label="Parar contador sub tarefa"><i class="bi bi-stop-fill"></i></button>
                                </div>
                                <form method="post" class="d-none" id="timerForm<?= (int) $subtask['id'] ?>"><input type="hidden" name="action" value="update_time"><input type="hidden" name="task_id" value="<?= (int) $subtask['id'] ?>"><input type="hidden" name="estimated_minutes" value="<?= h(format_minutes($subEstimated)) ?>"><input type="hidden" name="add_actual_minutes" value="0" class="js-add-actual"></form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <form method="post" class="d-none" id="timerForm<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="update_time"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="estimated_minutes" value="<?= h(format_minutes($estimated)) ?>"><input type="hidden" name="add_actual_minutes" value="0" class="js-add-actual"></form>
        <div class="row g-2 mt-3">
            <div class="col-md-6">
                <h3 class="h6">Observações</h3>
                <form method="post" class="d-flex gap-2 mb-2">
                    <input type="hidden" name="action" value="add_task_note">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <input class="form-control form-control-sm" name="note" placeholder="Adicionar observação" required>
                    <button class="btn btn-sm btn-outline-primary icon-btn" aria-label="Guardar"><i class="bi bi-save"></i></button>
                </form>
                <?php foreach (($taskNotesByTask[$task['id']] ?? []) as $note): ?>
                    <div class="small border rounded p-2 mb-1 bg-light">
                        <?= h($note['note']) ?><br>
                        <small class="text-muted"><?= h($note['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($note['created_at']))) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="col-md-6">
                <h3 class="h6">Documentos anexados</h3>
                <form method="post" enctype="multipart/form-data" class="d-flex gap-2 mb-2">
                    <input type="hidden" name="action" value="upload_task_attachment">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <input class="form-control form-control-sm" type="file" name="attachment" required>
                    <button class="btn btn-sm btn-outline-secondary icon-btn" aria-label="Anexar"><i class="bi bi-paperclip"></i></button>
                </form>
                <?php foreach (($taskAttachmentsByTask[$task['id']] ?? []) as $attachment): ?>
                    <div class="small border rounded p-2 mb-1 bg-light">
                        <a href="<?= h($attachment['file_path']) ?>" target="_blank" rel="noopener"><?= h($attachment['original_name']) ?></a><br>
                        <small class="text-muted"><?= h($attachment['user_name']) ?> · <?= h(date('d/m/Y H:i', strtotime($attachment['created_at']))) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
