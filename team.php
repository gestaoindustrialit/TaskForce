<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$teamId = (int) ($_GET['id'] ?? 0);

if (!$teamId || !team_accessible($pdo, $teamId, $userId)) {
    http_response_code(403);
    exit('Acesso negado à equipa.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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

$pageTitle = 'Equipa ' . $team['name'];
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card hero-card-sm mb-4 p-4">
    <a href="dashboard.php" class="small text-white">← Voltar</a>
    <h1 class="h2 mb-1 mt-2"><?= h($team['name']) ?></h1>
    <p class="text-white-50 mb-0"><?= h($team['description']) ?: 'Sem descrição' ?></p>
</section>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm soft-card">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <h2 class="h5 mb-0">Projetos</h2>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">Novo Projeto</button>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($projects as $project): ?>
                    <a href="project.php?id=<?= (int) $project['id'] ?>" class="list-group-item list-group-item-action py-3">
                        <strong><?= h($project['name']) ?></strong>
                        <p class="mb-0 text-muted small"><?= h($project['description']) ?: 'Sem descrição' ?></p>
                        <small class="text-muted">Líder: <?= h($project['leader_name'] ?: 'Não definido') ?></small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$projects): ?><div class="p-3 text-muted">Ainda não existem projetos.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm soft-card mb-3">
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

        <div class="card shadow-sm soft-card">
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
