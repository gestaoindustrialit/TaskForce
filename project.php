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

        if ($title !== '') {
            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, description, status, priority, due_date, created_by) VALUES (?, NULL, ?, ?, "todo", ?, ?, ?)');
            $stmt->execute([$projectId, $title, $description, $priority, $dueDate, $userId]);
        }
    }

    if ($action === 'create_subtask') {
        $parentId = (int) ($_POST['parent_task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($parentId && $title !== '') {
            $stmt = $pdo->prepare('INSERT INTO tasks(project_id, parent_task_id, title, status, created_by) VALUES (?, ?, ?, "todo", ?)');
            $stmt->execute([$projectId, $parentId, $title, $userId]);
        }
    }

    if ($action === 'change_status') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? 'todo';

        if (in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $stmt = $pdo->prepare('UPDATE tasks SET status = ? WHERE id = ? AND project_id = ?');
            $stmt->execute([$status, $taskId, $projectId]);
        }
    }

    if ($action === 'add_checklist') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $content = trim($_POST['content'] ?? '');

        if ($taskId && $content !== '') {
            $stmt = $pdo->prepare('INSERT INTO checklist_items(task_id, content) VALUES (?, ?)');
            $stmt->execute([$taskId, $content]);
        }
    }

    if ($action === 'toggle_checklist') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $isDone = (int) ($_POST['is_done'] ?? 0);
        $stmt = $pdo->prepare('UPDATE checklist_items SET is_done = ? WHERE id = ? AND task_id IN (SELECT id FROM tasks WHERE project_id = ?)');
        $stmt->execute([$isDone, $itemId, $projectId]);
    }

    if ($action === 'send_daily_report') {
        $summaryStmt = $pdo->prepare('SELECT SUM(CASE WHEN status = "todo" THEN 1 ELSE 0 END) AS todo, SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) AS in_progress, SUM(CASE WHEN status = "done" THEN 1 ELSE 0 END) AS done, COUNT(*) AS total FROM tasks WHERE project_id = ? AND parent_task_id IS NULL');
        $summaryStmt->execute([$projectId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['todo' => 0, 'in_progress' => 0, 'done' => 0, 'total' => 0];

        $checkStmt = $pdo->prepare('SELECT SUM(is_done) AS done_items, COUNT(*) AS total_items FROM checklist_items c INNER JOIN tasks t ON t.id = c.task_id WHERE t.project_id = ?');
        $checkStmt->execute([$projectId]);
        $checkStats = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: ['done_items' => 0, 'total_items' => 0];

        $leaderEmail = $project['leader_email'] ?: null;
        if (!$leaderEmail) {
            $leaderStmt = $pdo->prepare('SELECT u.email FROM team_members tm INNER JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.role IN ("owner", "leader") ORDER BY CASE tm.role WHEN "owner" THEN 0 ELSE 1 END LIMIT 1');
            $leaderStmt->execute([$project['team_id']]);
            $leaderEmail = $leaderStmt->fetchColumn() ?: null;
        }

        if ($leaderEmail) {
            $subject = 'Relatório diário do projeto: ' . $project['name'];
            $body = "Projeto: {$project['name']}\nEquipa: {$project['team_name']}\nData: " . date('Y-m-d') . "\n\nTarefas: {$summary['total']}\n- Por fazer: {$summary['todo']}\n- Em progresso: {$summary['in_progress']}\n- Concluídas: {$summary['done']}\nChecklist: {$checkStats['done_items']}/{$checkStats['total_items']}\n";
            deliver_report($leaderEmail, $subject, $body);
            $_SESSION['flash_success'] = 'Relatório diário enviado ao líder do projeto/equipa.';
        } else {
            $_SESSION['flash_error'] = 'Não foi possível enviar: este projeto/equipa não tem líder com email disponível.';
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

$pageTitle = 'Projeto ' . $project['name'];
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <a href="team.php?id=<?= (int) $project['team_id'] ?>" class="small">← Voltar à equipa</a>
        <h1 class="h3 mb-1"><?= h($project['name']) ?></h1>
        <p class="text-muted mb-0">Equipa: <?= h($project['team_name']) ?> · Líder: <?= h($project['leader_name'] ?: 'Não definido') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="requests.php">Pedidos às equipas</a>
        <form method="post"><input type="hidden" name="action" value="send_daily_report"><button class="btn btn-outline-secondary">Enviar relatório diário</button></form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">Nova Tarefa</button>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <ul class="nav nav-tabs mb-0">
        <li class="nav-item"><a class="nav-link <?= $view === 'list' ? 'active' : '' ?>" href="project.php?id=<?= $projectId ?>&view=list&show_done=<?= $showDone ? '1' : '0' ?>">Vista Lista</a></li>
        <li class="nav-item"><a class="nav-link <?= $view === 'board' ? 'active' : '' ?>" href="project.php?id=<?= $projectId ?>&view=board&show_done=<?= $showDone ? '1' : '0' ?>">Vista Quadro</a></li>
    </ul>
    <form method="get" class="form-check form-switch mb-0">
        <input type="hidden" name="id" value="<?= $projectId ?>">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="show_done" value="0">
        <input class="form-check-input" type="checkbox" id="showDone" name="show_done" value="1" <?= $showDone ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="showDone">Mostrar concluídas na vista</label>
    </form>
</div>

<?php if ($view === 'board'): ?>
    <div class="row g-3">
        <?php foreach (['todo' => 'Por Fazer', 'in_progress' => 'Em Progresso', 'done' => 'Concluídas'] as $status => $label): ?>
            <div class="col-md-4">
                <div class="card board-column shadow-sm soft-card">
                    <div class="card-header"><strong><?= h($label) ?></strong></div>
                    <div class="card-body vstack gap-2">
                        <?php foreach ($tasks as $task): ?>
                            <?php if ($task['status'] !== $status) continue; ?>
                            <div class="task-card border rounded p-2 bg-white">
                                <div class="d-flex justify-content-between align-items-start">
                                    <strong><?= h($task['title']) ?></strong>
                                    <span class="badge bg-<?= task_badge_class($task['status']) ?>"><?= h(status_label($task['status'])) ?></span>
                                </div>
                                <p class="small text-muted mb-2"><?= h($task['description']) ?></p>
                                <form method="post" class="d-flex gap-2">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="todo" <?= $task['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                        <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                        <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary">Atualizar</button>
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
            <div class="card shadow-sm soft-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h2 class="h5 mb-1"><?= h($task['title']) ?></h2>
                            <p class="text-muted mb-1"><?= h($task['description']) ?></p>
                            <small class="text-muted">Criada por <?= h($task['creator_name']) ?> · Prioridade <?= h($task['priority']) ?><?= $task['due_date'] ? ' · Prazo ' . h($task['due_date']) : '' ?></small>
                        </div>
                        <span class="badge bg-<?= task_badge_class($task['status']) ?>"><?= h(status_label($task['status'])) ?></span>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <form method="post" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="todo" <?= $task['status'] === 'todo' ? 'selected' : '' ?>>Por Fazer</option>
                                    <option value="in_progress" <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>Em Progresso</option>
                                    <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>Concluída</option>
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Guardar</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="action" value="create_subtask">
                                <input type="hidden" name="parent_task_id" value="<?= (int) $task['id'] ?>">
                                <input name="title" class="form-control form-control-sm" placeholder="Nova sub tarefa" required>
                                <button class="btn btn-sm btn-outline-secondary">+</button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" class="d-flex gap-2">
                                <input type="hidden" name="action" value="add_checklist">
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <input name="content" class="form-control form-control-sm" placeholder="Item checklist" required>
                                <button class="btn btn-sm btn-outline-success">+</button>
                            </form>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h3 class="h6">Sub Tarefas</h3>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($subtasksByParent[$task['id']] ?? [] as $subtask): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                        <?= h($subtask['title']) ?>
                                        <span class="badge bg-<?= task_badge_class($subtask['status']) ?>"><?= h(status_label($subtask['status'])) ?></span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (empty($subtasksByParent[$task['id']])): ?><li class="list-group-item px-0 text-muted">Sem sub tarefas.</li><?php endif; ?>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h3 class="h6">Checklist</h3>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($checklistByTask[$task['id']] ?? [] as $item): ?>
                                    <li class="list-group-item px-0">
                                        <form method="post" class="d-flex align-items-center gap-2">
                                            <input type="hidden" name="action" value="toggle_checklist">
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="is_done" value="<?= $item['is_done'] ? 0 : 1 ?>">
                                            <input class="form-check-input" type="checkbox" onchange="this.form.submit()" <?= $item['is_done'] ? 'checked' : '' ?>>
                                            <span class="<?= $item['is_done'] ? 'text-decoration-line-through text-muted' : '' ?>"><?= h($item['content']) ?></span>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (empty($checklistByTask[$task['id']])): ?><li class="list-group-item px-0 text-muted">Sem checklist.</li><?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$tasks): ?><div class="alert alert-info">Sem tarefas na vista atual.</div><?php endif; ?>
    </div>
<?php endif; ?>

<div class="card soft-card shadow-sm mt-4">
    <div class="card-header bg-white"><h3 class="h5 mb-0">Histórico de tarefas concluídas</h3></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($doneHistory as $task): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= h($task['title']) ?></strong>
                    <div class="small text-muted">Concluída por estado · Criada por <?= h($task['creator_name']) ?></div>
                </div>
                <small class="text-muted"><?= h(date('d/m/Y H:i', strtotime($task['created_at']))) ?></small>
            </li>
        <?php endforeach; ?>
        <?php if (!$doneHistory): ?><li class="list-group-item text-muted">Sem tarefas concluídas.</li><?php endif; ?>
    </ul>
</div>

<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_task">
            <div class="modal-header"><h5 class="modal-title">Nova tarefa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="title" placeholder="Título" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
                <select class="form-select" name="priority"><option value="low">Baixa</option><option value="normal" selected>Normal</option><option value="high">Alta</option></select>
                <input class="form-control" name="due_date" type="date">
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar tarefa</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
