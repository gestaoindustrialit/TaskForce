<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_team') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name !== '') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO teams(name, description, created_by) VALUES (?, ?, ?)');
            $stmt->execute([$name, $description, $userId]);
            $teamId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO team_members(team_id, user_id, role) VALUES (?, ?, "owner")')->execute([$teamId, $userId]);
            $pdo->commit();
            redirect('team.php?id=' . $teamId);
        }
        $flashError = 'Indique um nome para a equipa.';
    }

    if ($action === 'create_user' && $isAdmin) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, email e password para criar utilizador.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, email, password, is_admin) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), (int) ($_POST['is_admin'] ?? 0)]);
                $flashSuccess = 'Novo utilizador criado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível criar utilizador (email duplicado).';
            }
        }
    }


    if ($action === 'update_user' && $isAdmin) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $isTargetAdmin = (int) ($_POST['is_admin'] ?? 0);

        if ($targetUserId <= 0 || $name === '' || $email === '') {
            $flashError = 'Preencha nome e email para editar utilizador.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ?, is_admin = ? WHERE id = ?');
                    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, is_admin = ? WHERE id = ?');
                    $stmt->execute([$name, $email, $isTargetAdmin, $targetUserId]);
                }
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível atualizar utilizador (email duplicado).';
            }
        }
    }

    if ($action === 'upload_branding' && $isAdmin) {
        $saved = 0;
        $lightPath = save_brand_logo($_FILES['logo_navbar_light'] ?? [], 'navbar_light');
        if ($lightPath) {
            set_app_setting($pdo, 'logo_navbar_light', $lightPath);
            $saved++;
        }

        $darkPath = save_brand_logo($_FILES['logo_report_dark'] ?? [], 'report_dark');
        if ($darkPath) {
            set_app_setting($pdo, 'logo_report_dark', $darkPath);
            $saved++;
        }

        if ($saved > 0) {
            $flashSuccess = 'Logotipos atualizados com sucesso.';
        } else {
            $flashError = 'Nenhum ficheiro válido enviado (PNG, JPG, SVG ou WEBP).';
        }
    }
}

$teamsStmt = $pdo->prepare('SELECT t.*, tm.role, (SELECT COUNT(*) FROM projects p WHERE p.team_id = t.id) AS total_projects FROM teams t INNER JOIN team_members tm ON tm.team_id = t.id WHERE tm.user_id = ? ORDER BY t.created_at DESC');
$teamsStmt->execute([$userId]);
$teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM team_members WHERE user_id = ?) AS total_teams, (SELECT COUNT(*) FROM users) AS total_users, (SELECT COUNT(*) FROM projects p INNER JOIN team_members tm ON tm.team_id = p.team_id WHERE tm.user_id = ?) AS total_projects');
$statsStmt->execute([$userId, $userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name, email, is_admin FROM users ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$navbarLogo = app_setting($pdo, 'logo_navbar_light');
$reportLogo = app_setting($pdo, 'logo_report_dark');

$pageTitle = 'Dashboard';
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card mb-4 p-4 p-md-5">
    <div class="row align-items-center g-3">
        <div class="col-lg-8">
            <h1 class="display-6 fw-semibold mb-2">Gestão da tua operação em tempo real</h1>
            <p class="mb-0 text-white-50">Organiza equipas, projetos, tarefas e pedidos internos num único espaço.</p>
        </div>
        <div class="col-lg-4"><div class="row g-2 text-center"><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_teams'] ?></strong><span>Equipas</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_projects'] ?></strong><span>Projetos</span></div></div><div class="col-4"><div class="stat-pill"><strong><?= (int) $stats['total_users'] ?></strong><span>Users</span></div></div></div></div>
    </div>
</section>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm soft-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center bg-white border-0 pt-4 px-4"><h2 class="h4 mb-0">As tuas equipas</h2><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teamModal">Nova Equipa</button></div>
            <div class="card-body px-4 pb-4"><div class="row g-3"><?php foreach ($teams as $team): ?><div class="col-md-6"><div class="team-card h-100 p-3"><h3 class="h5 mb-1"><?= h($team['name']) ?></h3><p class="text-muted small mb-2"><?= h($team['description']) ?: 'Sem descrição' ?></p><p class="small mb-3">Projetos: <strong><?= (int) $team['total_projects'] ?></strong> · Papel: <span class="badge text-bg-light border"><?= h($team['role']) ?></span></p><a class="btn btn-outline-primary btn-sm" href="team.php?id=<?= (int) $team['id'] ?>">Abrir equipa</a></div></div><?php endforeach; ?><?php if (!$teams): ?><p class="text-muted mb-0">Ainda não fazes parte de equipas.</p><?php endif; ?></div></div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm soft-card mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Gestão de utilizadores</h2><?php if ($isAdmin): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#userModal">Novo user</button><?php endif; ?></div>
            <div class="card-body px-4"><?php foreach ($users as $user): ?><div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2"><div><strong class="small"><?= h($user['name']) ?></strong> <?= (int) $user['is_admin'] === 1 ? '<span class="badge text-bg-dark">admin</span>' : '' ?><div class="small text-muted"><?= h($user['email']) ?></div></div><div class="d-flex align-items-center gap-2"><span class="small text-muted">#<?= (int) $user['id'] ?></span><?php if ($isAdmin): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int) $user['id'] ?>">Editar</button><?php endif; ?></div></div><?php endforeach; ?></div>
        </div>

        <div class="card shadow-sm soft-card"><div class="card-body p-4"><h3 class="h5">Pedidos diretos às equipas</h3><p class="small text-muted">Fora de projetos: abre o módulo de pedidos e submete tickets às equipas.</p><a href="requests.php" class="btn btn-primary btn-sm">Abrir pedidos</a></div></div>

        <?php if ($isAdmin): ?>
            <div class="card shadow-sm soft-card mt-4"><div class="card-body p-4"><h3 class="h5">Branding</h3><p class="small text-muted">Carrega os logotipos da empresa (claro para navbar e escuro para cabeçalho de relatório).</p>
                <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                    <input type="hidden" name="action" value="upload_branding">
                    <label class="form-label mb-0">Logo claro (navbar)</label>
                    <input class="form-control form-control-sm" type="file" name="logo_navbar_light" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                    <?php if ($navbarLogo): ?><img src="<?= h($navbarLogo) ?>" alt="Logo navbar" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
                    <label class="form-label mb-0">Logo escuro (report)</label>
                    <input class="form-control form-control-sm" type="file" name="logo_report_dark" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                    <?php if ($reportLogo): ?><img src="<?= h($reportLogo) ?>" alt="Logo report" class="img-fluid border rounded p-2 mb-2"><?php endif; ?>
                    <button class="btn btn-outline-primary btn-sm">Guardar branding</button>
                </form>
            </div></div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="teamModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_team"><div class="modal-header"><h5 class="modal-title">Criar Equipa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome da equipa" required><textarea class="form-control" name="description" placeholder="Descrição"></textarea></div><div class="modal-footer"><button class="btn btn-primary">Criar</button></div></form></div></div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_user"><div class="modal-header"><h5 class="modal-title">Novo utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome" required><input class="form-control" type="email" name="email" placeholder="Email" required><input class="form-control" type="password" name="password" placeholder="Password" required><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdmin"><label class="form-check-label" for="isAdmin">Administrador</label></div></div><div class="modal-footer"><button class="btn btn-primary">Criar utilizador</button></div></form></div></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= (int) $user['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><div class="modal-header"><h5 class="modal-title">Editar utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" value="<?= h($user['name']) ?>" required><input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required><input class="form-control" type="password" name="password" placeholder="Nova password (opcional)"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdminEdit<?= (int) $user['id'] ?>" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isAdminEdit<?= (int) $user['id'] ?>">Administrador</label></div></div><div class="modal-footer"><button class="btn btn-primary">Guardar utilizador</button></div></form></div></div>
<?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
