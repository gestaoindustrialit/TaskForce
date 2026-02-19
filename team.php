<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$teamId = (int) ($_GET['id'] ?? 0);
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;
$ticketStatuses = ticket_statuses($pdo);

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

    if ($action === 'create_recurring_task' && $canManageProjects) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $recurrenceType = (string) ($_POST['recurrence_type'] ?? 'weekly');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $timeOfDay = trim($_POST['time_of_day'] ?? '');

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

                $weekday = (int) $startDateObj->format('N');
                $stmt = $pdo->prepare('INSERT INTO team_recurring_tasks(team_id, project_id, title, description, weekday, recurrence_type, start_date, time_of_day, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$teamId, $projectIdValue, $title, $description, $weekday, $recurrenceType, $startDateObj->format('Y-m-d'), $timeOfDay !== '' ? $timeOfDay : null, $userId]);
                $flashSuccess = 'Tarefa recorrente adicionada ao calendário da equipa.';
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
            $completedAt = ticket_status_is_completed($pdo, $status) ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare('UPDATE team_tickets SET status = ?, assignee_user_id = ?, estimated_minutes = ?, actual_minutes = ?, completed_at = ? WHERE id = ? AND team_id = ?');
            $stmt->execute([$status, $assignee, $estimatedMinutes, $actualMinutes, $completedAt, $ticketId, $teamId]);
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
                $completedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare('UPDATE team_tickets SET status = ?, assignee_user_id = ?, estimated_minutes = ?, actual_minutes = ?, completed_at = ? WHERE id = ? AND team_id = ?');
                $stmt->execute([$status, $assignee, $estimatedMinutes, $actualMinutes, $completedAt, $ticketId, $teamId]);

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

$membersStmt = $pdo->prepare('SELECT u.id, u.name, u.email, tm.role FROM team_members tm INNER JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ?');
$membersStmt->execute([$teamId]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

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

$recurringTasksStmt = $pdo->prepare('SELECT rt.*, u.name AS creator_name, p.name AS project_name FROM team_recurring_tasks rt INNER JOIN users u ON u.id = rt.created_by LEFT JOIN projects p ON p.id = rt.project_id WHERE rt.team_id = ? ORDER BY rt.created_at ASC');
$recurringTasksStmt->execute([$teamId]);
$recurringTasks = $recurringTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$calendarOccurrences = [];
foreach ($recurringTasks as $recurringTask) {
    $occurrenceDates = recurring_task_occurrences($pdo, $recurringTask, $calendarPeriodStart, $calendarPeriodEnd);
    foreach ($occurrenceDates as $occurrenceDate) {
        $dateKey = $occurrenceDate->format('Y-m-d');
        if (!isset($calendarOccurrences[$dateKey])) {
            $calendarOccurrences[$dateKey] = [];
        }

        $calendarOccurrences[$dateKey][] = [
            'id' => (int) $recurringTask['id'],
            'title' => (string) $recurringTask['title'],
            'description' => (string) ($recurringTask['description'] ?? ''),
            'time_of_day' => (string) ($recurringTask['time_of_day'] ?? ''),
            'creator_name' => (string) $recurringTask['creator_name'],
            'project_name' => (string) ($recurringTask['project_name'] ?? ''),
            'recurrence_label' => recurring_task_recurrence_label($pdo, (string) ($recurringTask['recurrence_type'] ?? 'weekly')),
            'start_date' => (string) ($recurringTask['start_date'] ?? ''),
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
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">Novo Projeto</button>
        </div>
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


    <div class="card shadow-sm soft-card">
        <div class="card-header d-flex justify-content-between align-items-center bg-white">
            <h2 class="h5 mb-0">Tickets</h2>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#teamTicketsCollapse" aria-expanded="true" aria-controls="teamTicketsCollapse">Mostrar/Ocultar</button>
        </div>
        <div class="collapse show" id="teamTicketsCollapse">
            <div class="list-group list-group-flush">
            <?php foreach ($teamTasks as $task): ?>
                <?php $collapseId = 'ticket-details-' . (int) $task['id']; ?>
                <div class="list-group-item py-3">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                        <div>
                            <div class="small text-muted">ID <?= h($task['ticket_code']) ?></div>
                            <strong><?= h($task['title']) ?></strong>
                            <p class="mb-1 small text-muted"><?= h($task['description']) ?></p>
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
            <?php if ($canManageProjects): ?>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#recurringTaskModal">Nova tarefa recorrente</button>
            <?php endif; ?>
        </div>
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
                                                    <strong class="small"><?= h($occurrence['title']) ?></strong>
                                                    <span class="text-muted small"><?= h($occurrence['time_of_day'] !== '' ? $occurrence['time_of_day'] : 'Sem hora') ?></span>
                                                </div>
                                                <div class="small text-muted"><?= h($occurrence['recurrence_label']) ?><?= $occurrence['project_name'] !== '' ? ' · Projeto: ' . h($occurrence['project_name']) : '' ?></div>
                                                <?php if ($occurrence['description'] !== ''): ?><p class="mb-1 small text-muted"><?= h($occurrence['description']) ?></p><?php endif; ?>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">Criado por <?= h($occurrence['creator_name']) ?></small>
                                                    <?php if ($canManageProjects): ?>
                                                        <form method="post" onsubmit="return confirm('Remover esta tarefa recorrente?');">
                                                            <input type="hidden" name="action" value="delete_recurring_task">
                                                            <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                            <button class="btn btn-sm btn-outline-danger py-0 px-2">Remover</button>
                                                        </form>
                                                    <?php endif; ?>
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
                                                <strong class="small"><?= h($occurrence['title']) ?></strong>
                                                <span class="text-muted small"><?= h($occurrence['time_of_day'] !== '' ? $occurrence['time_of_day'] : 'Sem hora') ?></span>
                                            </div>
                                            <div class="small text-muted"><?= h($occurrence['recurrence_label']) ?><?= $occurrence['project_name'] !== '' ? ' · Projeto: ' . h($occurrence['project_name']) : '' ?></div>
                                            <?php if ($occurrence['description'] !== ''): ?><p class="mb-1 small text-muted"><?= h($occurrence['description']) ?></p><?php endif; ?>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">Criado por <?= h($occurrence['creator_name']) ?></small>
                                                <?php if ($canManageProjects): ?>
                                                    <form method="post" onsubmit="return confirm('Remover esta tarefa recorrente?');">
                                                        <input type="hidden" name="action" value="delete_recurring_task">
                                                        <input type="hidden" name="recurring_task_id" value="<?= (int) $occurrence['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-danger py-0 px-2">Remover</button>
                                                    </form>
                                                <?php endif; ?>
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

<div class="row g-4 mt-1">
    <div class="col-lg-6">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Membros da equipa</h2></div>
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
            <div class="card-header bg-white"><h2 class="h6 mb-0">Adicionar membro</h2></div>
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
<?php require __DIR__ . '/partials/footer.php'; ?>
