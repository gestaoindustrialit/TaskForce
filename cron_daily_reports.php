<?php
require_once __DIR__ . '/helpers.php';

$projects = $pdo->query(
    'SELECT p.id, p.name, t.name AS team_name,
            COALESCE(leader.email, fallback.email) AS receiver_email
     FROM projects p
     INNER JOIN teams t ON t.id = p.team_id
     LEFT JOIN users leader ON leader.id = p.leader_user_id
     LEFT JOIN users fallback ON fallback.id = (
        SELECT tm.user_id
        FROM team_members tm
        WHERE tm.team_id = t.id AND tm.role IN ("owner", "leader")
        ORDER BY CASE tm.role WHEN "owner" THEN 0 ELSE 1 END
        LIMIT 1
     )'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $project) {
    if (!$project['receiver_email']) {
        continue;
    }

    $summaryStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN status = "todo" THEN 1 ELSE 0 END) AS todo,
            SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = "done" THEN 1 ELSE 0 END) AS done,
            COUNT(*) AS total
        FROM tasks
        WHERE project_id = ? AND parent_task_id IS NULL'
    );
    $summaryStmt->execute([(int) $project['id']]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['todo' => 0, 'in_progress' => 0, 'done' => 0, 'total' => 0];

    $subject = 'Relatório diário automático: ' . $project['name'];
    $body = "Projeto: {$project['name']}\n" .
        "Equipa: {$project['team_name']}\n" .
        "Data: " . date('Y-m-d') . "\n\n" .
        "Tarefas principais: {$summary['total']}\n" .
        "- Por fazer: {$summary['todo']}\n" .
        "- Em progresso: {$summary['in_progress']}\n" .
        "- Concluídas: {$summary['done']}\n";

    deliver_report($project['receiver_email'], $subject, $body);
    echo "Relatório enviado para {$project['receiver_email']} ({$project['name']})\n";
}
