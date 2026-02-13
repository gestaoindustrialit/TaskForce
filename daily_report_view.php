<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$reportId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT dr.*, u.name AS user_name FROM daily_reports dr INNER JOIN users u ON u.id = dr.user_id WHERE dr.id = ?');
$stmt->execute([$reportId]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    exit('Relatório não encontrado.');
}

$allowedStmt = $pdo->prepare('SELECT 1 FROM team_members tm INNER JOIN projects p ON p.team_id = tm.team_id INNER JOIN tasks t ON t.project_id = p.id WHERE tm.user_id = ? AND t.updated_by = ? LIMIT 1');
$allowedStmt->execute([$userId, (int) $report['user_id']]);
if ($userId !== (int) $report['user_id'] && !$allowedStmt->fetchColumn()) {
    http_response_code(403);
    exit('Sem acesso ao relatório.');
}

$pageTitle = 'Relatório diário A4';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h1 class="h4 mb-0">Relatório diário A4</h1>
    <button class="btn btn-outline-primary" onclick="window.print()">Gerar PDF (imprimir A4)</button>
</div>
<div class="report-sheet">
    <?= $report['html_content'] ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
