<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_team') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name !== '') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO teams(name, description, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $userId]);
        $teamId = (int) $pdo->lastInsertId();

        $memberStmt = $pdo->prepare('INSERT INTO team_members(team_id, user_id, role) VALUES (?, ?, "owner")');
        $memberStmt->execute([$teamId, $userId]);
        $pdo->commit();
        redirect('team.php?id=' . $teamId);
    }
}

$stmt = $pdo->prepare(
    'SELECT t.*, tm.role,
        (SELECT COUNT(*) FROM projects p WHERE p.team_id = t.id) AS total_projects
     FROM teams t
     INNER JOIN team_members tm ON tm.team_id = t.id
     WHERE tm.user_id = ?
     ORDER BY t.created_at DESC'
);
$stmt->execute([$userId]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Equipas</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teamModal">Nova Equipa</button>
</div>

<div class="row g-3">
    <?php foreach ($teams as $team): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5"><?= h($team['name']) ?></h2>
                    <p class="text-muted small"><?= h($team['description']) ?: 'Sem descrição' ?></p>
                    <p class="small mb-3">Projetos: <strong><?= (int) $team['total_projects'] ?></strong> · Papel: <strong><?= h($team['role']) ?></strong></p>
                    <a class="btn btn-outline-primary btn-sm" href="team.php?id=<?= (int) $team['id'] ?>">Abrir equipa</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="teamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_team">
            <div class="modal-header">
                <h5 class="modal-title">Criar Equipa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="name" placeholder="Nome da equipa" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Criar</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
