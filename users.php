<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores.');
}

$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, utilizador, email e password para criar utilizador.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), (int) ($_POST['is_admin'] ?? 0), $accessProfile, $isActive, $mustChangePassword]);
                $flashSuccess = 'Utilizador criado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível criar utilizador (email/utilizador já em uso).';
            }
        }
    }

    if ($action === 'update_user') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $isTargetAdmin = (int) ($_POST['is_admin'] ?? 0);
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);

        if ($targetUserId <= 0 || $name === '' || $username === '' || $email === '') {
            $flashError = 'Dados inválidos para atualizar utilizador.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $targetUserId]);
                }
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                $flashError = 'Não foi possível atualizar utilizador (email/utilizador já em uso).';
            }
        }
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$usersStmt = $pdo->prepare('SELECT id, name, username, email, is_admin, access_profile, is_active, must_change_password, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
$usersStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$usersStmt->bindValue(2, $offset, PDO::PARAM_INT);
$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Utilizadores';
require __DIR__ . '/partials/header.php';
?>
<a href="dashboard.php" class="btn btn-link px-0">&larr; Voltar à dashboard</a>

<div class="card shadow-sm soft-card">
    <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0">Utilizadores</h1>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">Novo utilizador</button>
    </div>
    <div class="card-body px-4 pb-4">
        <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th>Nome</th><th>Utilizador</th><th>Email</th><th>Perfil</th><th>Estado</th><th>Segurança</th><th>Criado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= (int) $user['id'] ?></td>
                        <td><?= h($user['name']) ?></td>
                        <td><?= h((string) ($user['username'] ?? '')) ?></td>
                        <td><?= h($user['email']) ?></td>
                        <td>
                            <?= h((string) ($user['access_profile'] ?? 'Utilizador')) ?>
                            <?= (int) $user['is_admin'] === 1 ? '<span class="badge text-bg-dark ms-1">Admin</span>' : '' ?>
                        </td>
                        <td><?= (int) ($user['is_active'] ?? 1) === 1 ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-warning">Inativo</span>' ?></td>
                        <td><?= (int) ($user['must_change_password'] ?? 0) === 1 ? '<span class="badge text-bg-info">Troca de senha pendente</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= h(date('d/m/Y', strtotime((string) $user['created_at']))) ?></td>
                        <td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int) $user['id'] ?>">Editar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="users.php?page=<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="create_user"><div class="modal-header"><h5 class="modal-title">Novo utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" placeholder="Nome" required><input class="form-control" name="username" placeholder="Utilizador" required><input class="form-control" type="email" name="email" placeholder="Email" required><input class="form-control" name="access_profile" placeholder="Perfil de acesso" value="Utilizador" required><input class="form-control" type="password" name="password" placeholder="Password" required><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdmin"><label class="form-check-label" for="isAdmin">Administrador</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked><label class="form-check-label" for="isActive">Ativo</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePassword"><label class="form-check-label" for="mustChangePassword">Obrigar alteração da senha no próximo login</label></div></div><div class="modal-footer"><button class="btn btn-primary">Criar utilizador</button></div></form></div></div>

<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= (int) $user['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><div class="modal-header"><h5 class="modal-title">Editar utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body vstack gap-3"><input class="form-control" name="name" value="<?= h($user['name']) ?>" required><input class="form-control" name="username" value="<?= h((string) ($user['username'] ?? '')) ?>" required><input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required><input class="form-control" name="access_profile" value="<?= h((string) ($user['access_profile'] ?? 'Utilizador')) ?>" required><input class="form-control" type="password" name="password" placeholder="Nova password (opcional)"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdminEdit<?= (int) $user['id'] ?>" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isAdminEdit<?= (int) $user['id'] ?>">Administrador</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isActiveEdit<?= (int) $user['id'] ?>">Ativo</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePasswordEdit<?= (int) $user['id'] ?>" <?= (int) ($user['must_change_password'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="mustChangePasswordEdit<?= (int) $user['id'] ?>">Obrigar alteração da senha no próximo login</label></div></div><div class="modal-footer"><button class="btn btn-primary">Guardar utilizador</button></div></form></div></div>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
