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

$view = $_GET['view'] ?? 'list';
$showDone = (int) ($_GET['show_done'] ?? 1) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_task') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $dueDate = $_POST['due_date'] ?: null;
        $estimatedMinutes = ($_POST['estimated_minutes'] ?? '') !== '' ? max(0, (int) $_POST['estimated_minutes']) : null;
        $templateId = (int) ($_POST['checklist_template_id'] ?? 0);
        $newChecklistItemsRaw = trim((string) ($_POST['new_checklist_items'] ?? ''));

        if ($title !== '') {
            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, description, status, priority, due_date, created_by, estimated_minutes, actual_minutes, updated_at, updated_by) VALUES (?, NULL, ?, ?, "todo", ?, ?, ?, ?, NULL, CURRENT_TIMESTAMP, ?)');
            $stmt->execute([$projectId, $title, $description, $priority, $dueDate, $userId, $estimatedMinutes, $userId]);
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
        }
    }

    if ($action === 'create_subtask') {
        $parentId = (int) ($_POST['parent_task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($parentId && $title !== '') {
            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, status, created_by, updated_at, updated_by) VALUES (?, ?, ?, "todo", ?, CURRENT_TIMESTAMP, ?)');
            $stmt->execute([$projectId, $parentId, $title, $userId, $userId]);
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
        $estimatedMinutes = ($_POST['estimated_minutes'] ?? '') !== '' ? max(0, (int) $_POST['estimated_minutes']) : null;
        $actualMinutes = ($_POST['actual_minutes'] ?? '') !== '' ? max(0, (int) $_POST['actual_minutes']) : null;
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

    redirect('project.php?id=' . $projectId . '&view=' . $view . '&show_done=' . ($showDone ? '1' : '0'));
}

$tasksStmt = $pdo->prepare('SELECT t.*, u.name AS creator_name FROM tasks t INNER JOIN users u ON u.id = t.created_by WHERE t.project_id = ? ORDER BY t.parent_task_id IS NOT NULL, t.created_at DESC');
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

$checklistTemplatesStmt = $pdo->query('SELECT ct.id, ct.name, ct.description, u.name AS creator_name, COUNT(cti.id) AS items_count FROM checklist_templates ct INNER JOIN users u ON u.id = ct.created_by LEFT JOIN checklist_template_items cti ON cti.template_id = ct.id GROUP BY ct.id ORDER BY ct.name COLLATE NOCASE ASC');
$checklistTemplates = $checklistTemplatesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Projeto ' . $project['name'];
require __DIR__ . '/partials/header.php';
?>
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
            <div class="col-md-3"><input class="form-control" name="title" placeholder="Título" required></div>
            <div class="col-md-3"><input class="form-control" name="description" placeholder="Descrição"></div>
            <div class="col-md-2"><input class="form-control" type="number" min="0" name="estimated_minutes" placeholder="Previsto (min)"></div>
            <div class="col-md-4">
                <select class="form-select" name="checklist_template_id">
                    <option value="0">Checklist: sem modelo</option>
                    <?php foreach ($checklistTemplates as $template): ?>
                        <option value="<?= (int) $template['id'] ?>"><?= h($template['name']) ?> (<?= (int) $template['items_count'] ?> itens)</option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Pode gerir modelos em <a href="checklists.php">Checklists</a>.</small>
            </div>
            <div class="col-12"><textarea class="form-control" name="new_checklist_items" rows="2" placeholder="Ou escreva novos itens de checklist (1 por linha)"></textarea></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Criar</button></div>
        </form>
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
                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="action" value="change_status"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                        <select name="status" class="form-select form-select-sm"><option value="todo" <?= $task['status']==='todo'?'selected':'' ?>>Por Fazer</option><option value="in_progress" <?= $task['status']==='in_progress'?'selected':'' ?>>Em Progresso</option><option value="done" <?= $task['status']==='done'?'selected':'' ?>>Concluída</option></select>
                        <button class="btn btn-sm btn-outline-primary">Atualizar</button>
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
        <div class="d-flex justify-content-between align-items-start mb-2"><div><h2 class="h5 mb-1"><?= h($task['title']) ?></h2><p class="text-muted mb-1"><?= h($task['description']) ?></p><small class="text-muted">Criada por <?= h($task['creator_name']) ?></small></div><span class="badge bg-<?= task_badge_class($task['status']) ?>"><?= h(status_label($task['status'])) ?></span></div>
        <div class="d-flex gap-2 mb-3"><span class="time-chip">Tempo previsto: <?= h(format_minutes($estimated)) ?></span><span class="time-chip">Tempo real: <?= h(format_minutes($actual)) ?></span><?php if ($delta !== null): ?><span class="time-chip <?= $delta > 0 ? 'text-danger' : 'text-success' ?>">Discrepância: <?= $delta > 0 ? '+' : '-' ?><?= h(format_minutes(abs($delta))) ?></span><?php endif; ?></div>
        <div class="row g-2">
            <div class="col-md-4"><form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="change_status"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><select name="status" class="form-select form-select-sm"><option value="todo" <?= $task['status']==='todo'?'selected':'' ?>>Por Fazer</option><option value="in_progress" <?= $task['status']==='in_progress'?'selected':'' ?>>Em Progresso</option><option value="done" <?= $task['status']==='done'?'selected':'' ?>>Concluída</option></select><button class="btn btn-sm btn-outline-primary">Estado</button></form></div>
            <div class="col-md-4"><form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="update_time"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input class="form-control form-control-sm" type="number" min="0" name="estimated_minutes" value="<?= $estimated !== null ? $estimated : '' ?>" placeholder="Previsto"><input class="form-control form-control-sm" type="number" min="0" name="actual_minutes" value="<?= $actual !== null ? $actual : '' ?>" placeholder="Real"><button class="btn btn-sm btn-outline-dark">Tempo</button></form><div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-sm btn-outline-success js-start-timer" data-task-id="<?= (int) $task['id'] ?>">Iniciar contador</button><button type="button" class="btn btn-sm btn-outline-warning js-stop-timer" data-task-id="<?= (int) $task['id'] ?>">Parar + guardar</button></div></div>
            <div class="col-md-4"><form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="create_subtask"><input type="hidden" name="parent_task_id" value="<?= (int) $task['id'] ?>"><input name="title" class="form-control form-control-sm" placeholder="Nova sub tarefa" required><button class="btn btn-sm btn-outline-secondary">+</button></form></div>
        </div>
        <form method="post" class="d-none" id="timerForm<?= (int) $task['id'] ?>"><input type="hidden" name="action" value="update_time"><input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>"><input type="hidden" name="estimated_minutes" value="<?= $estimated !== null ? $estimated : "" ?>"><input type="hidden" name="add_actual_minutes" value="0" class="js-add-actual"></form>
        <div class="row g-2 mt-3">
            <div class="col-md-6">
                <h3 class="h6">Observações</h3>
                <form method="post" class="d-flex gap-2 mb-2">
                    <input type="hidden" name="action" value="add_task_note">
                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                    <input class="form-control form-control-sm" name="note" placeholder="Adicionar observação" required>
                    <button class="btn btn-sm btn-outline-primary">Guardar</button>
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
                    <button class="btn btn-sm btn-outline-secondary">Anexar</button>
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
