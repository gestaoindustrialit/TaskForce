<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$currentUser = current_user($pdo);
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$projectFilter = (int) ($_GET['project_id'] ?? 0);

$tasksSql = 'SELECT t.id, t.title, t.status, t.estimated_minutes, t.actual_minutes, t.updated_at, p.id AS project_id, p.name AS project_name, tm.team_id, teams.name AS team_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             INNER JOIN teams ON teams.id = p.team_id
             INNER JOIN team_members tm ON tm.team_id = p.team_id AND tm.user_id = ?
             WHERE DATE(t.updated_at) = ? AND t.updated_by = ?';
$params = [$userId, $selectedDate, $userId];
if ($projectFilter > 0) {
    $tasksSql .= ' AND p.id = ?';
    $params[] = $projectFilter;
}
$tasksSql .= ' ORDER BY p.name, t.updated_at DESC';
$tasksStmt = $pdo->prepare($tasksSql);
$tasksStmt->execute($params);
$tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_daily_report') {
    $selectedTaskIds = array_map('intval', $_POST['task_ids'] ?? []);
    $summaryText = trim($_POST['summary'] ?? '');
    $reportTasks = array_values(array_filter($tasks, static fn($task) => in_array((int) $task['id'], $selectedTaskIds, true)));

    if (!$reportTasks) {
        $_SESSION['flash_error'] = 'Selecione pelo menos uma tarefa alterada.';
        redirect('daily_report.php?date=' . urlencode($selectedDate));
    }

    $reportLogo = app_setting($pdo, 'logo_report_dark');
    $rowsHtml = '';
    foreach ($reportTasks as $task) {
        $estimated = $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null;
        $actual = $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null;
        $delta = task_time_delta($estimated, $actual);
        $taskLink = app_base_url() . '/project.php?id=' . (int) $task['project_id'];
        $rowsHtml .= '<tr><td>' . h($task['project_name']) . '</td><td>' . h($task['title']) . '</td><td>' . h(format_minutes($estimated)) . '</td><td>' . h(format_minutes($actual)) . '</td><td>' . ($delta === null ? '-' : (($delta > 0 ? '+' : '-') . h(format_minutes(abs($delta))))) . '</td><td><a href="' . h($taskLink) . '">Abrir</a></td></tr>';
    }

    $htmlContent = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">'
        . '<div><h1 style="margin:0">Relatório diário do colaborador</h1><p style="margin:4px 0 0 0">Data: ' . h($selectedDate) . '</p><p style="margin:4px 0 0 0">Colaborador: ' . h((string) ($currentUser['name'] ?? '')) . '</p></div>'
        . ($reportLogo ? '<img src="' . h(app_base_url() . '/' . $reportLogo) . '" alt="logo" style="max-height:55px">' : '')
        . '</div>'
        . '<p><strong>Resumo:</strong> ' . h($summaryText !== '' ? $summaryText : 'Sem notas adicionais.') . '</p>'
        . '<table border="1" cellpadding="8" cellspacing="0" width="100%"><thead><tr><th>Projeto</th><th>Tarefa</th><th>Previsto</th><th>Real</th><th>Discrepância</th><th>Link</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>';

    $stmt = $pdo->prepare('INSERT INTO daily_reports(user_id, report_date, summary, html_content) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $selectedDate, $summaryText, $htmlContent]);
    $reportId = (int) $pdo->lastInsertId();

    $teamIds = array_values(array_unique(array_map(static fn($task) => (int) $task['team_id'], $reportTasks)));
    $emails = [];
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $emailStmt = $pdo->prepare('SELECT DISTINCT u.email FROM team_members tm INNER JOIN users u ON u.id = tm.user_id WHERE tm.team_id IN (' . $placeholders . ') AND tm.role IN ("owner", "leader") AND u.email <> ""');
        $emailStmt->execute($teamIds);
        $emails = $emailStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $directLink = app_base_url() . '/daily_report_view.php?id=' . $reportId;
    $subject = 'Relatório diário - ' . ($currentUser['name'] ?? 'Colaborador') . ' (' . $selectedDate . ')';
    $mailBody = "Segue relatório diário com tarefas alteradas e detalhe de tempos.\n\nLink direto: {$directLink}\n\nResumo: {$summaryText}\n";
    foreach ($emails as $email) {
        deliver_report((string) $email, $subject, $mailBody);
    }

    $_SESSION['flash_success'] = 'Relatório enviado para os líderes e disponível em formato A4 para PDF.';
    redirect('daily_report_view.php?id=' . $reportId);
}

$projects = $pdo->prepare('SELECT DISTINCT p.id, p.name FROM projects p INNER JOIN team_members tm ON tm.team_id = p.team_id WHERE tm.user_id = ? ORDER BY p.name');
$projects->execute([$userId]);
$projectList = $projects->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Relatório diário';
require __DIR__ . '/partials/header.php';
?>
<h1 class="h3 mb-3">Relatório diário por colaborador</h1>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h((string) $_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div><?php endif; ?>
<form method="get" class="row g-2 mb-3">
    <div class="col-md-3"><input class="form-control" type="date" name="date" value="<?= h($selectedDate) ?>"></div>
    <div class="col-md-5"><select name="project_id" class="form-select"><option value="0">Todos os projetos</option><?php foreach ($projectList as $proj): ?><option value="<?= (int) $proj['id'] ?>" <?= $projectFilter === (int) $proj['id'] ? 'selected' : '' ?>><?= h($proj['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filtrar</button></div>
</form>

<form method="post" class="card shadow-sm soft-card">
    <input type="hidden" name="action" value="send_daily_report">
    <div class="card-body">
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th></th><th>Projeto</th><th>Tarefa alterada</th><th>Previsto</th><th>Real</th><th>Discrepância</th><th>Atualizada</th></tr></thead><tbody>
            <?php foreach ($tasks as $task): $estimated = $task['estimated_minutes'] !== null ? (int) $task['estimated_minutes'] : null; $actual = $task['actual_minutes'] !== null ? (int) $task['actual_minutes'] : null; $delta = task_time_delta($estimated, $actual); ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="task_ids[]" value="<?= (int) $task['id'] ?>" checked></td>
                    <td><?= h($task['project_name']) ?></td><td><?= h($task['title']) ?></td><td><?= h(format_minutes($estimated)) ?></td><td><?= h(format_minutes($actual)) ?></td><td><?= $delta === null ? '-' : ($delta > 0 ? '+' : '-') . h(format_minutes(abs($delta))) ?></td><td><?= h((string) $task['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tasks): ?><tr><td colspan="7" class="text-muted">Sem tarefas alteradas por si nesta data.</td></tr><?php endif; ?>
        </tbody></table></div>
        <label class="form-label">Resumo para o líder</label>
        <textarea name="summary" class="form-control mb-3" rows="3" placeholder="Descreva o que foi feito durante o dia..."></textarea>
        <button class="btn btn-primary" <?= !$tasks ? 'disabled' : '' ?>>Enviar relatório e gerar versão A4</button>
    </div>
</form>
<?php require __DIR__ . '/partials/footer.php'; ?>
