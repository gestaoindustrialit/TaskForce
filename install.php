<?php
require_once __DIR__ . '/helpers.php';

$hasSqlite = extension_loaded('pdo_sqlite');
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasSqlite) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha todos os campos para criar o utilizador administrador.';
    } else {
        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $usersCount = (int) $stmt->fetchColumn();

        if ($usersCount === 0) {
            $insert = $pdo->prepare('INSERT INTO users(name, email, password, is_admin) VALUES (?, ?, ?, 1)');
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $success = 'Instalação concluída. Admin criado com sucesso.';
        } else {
            $error = 'A instalação já foi executada (já existem utilizadores criados).';
        }
    }
}

$pageTitle = 'Instalação';
require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Instalação inicial do TaskForce</h1>
                <p class="text-muted">Esta página prepara o sistema e cria o primeiro utilizador administrador.</p>

                <?php if (!$hasSqlite): ?>
                    <div class="alert alert-danger">A extensão <code>pdo_sqlite</code> não está ativa no PHP.</div>
                <?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

                <form method="post" class="vstack gap-3">
                    <input class="form-control" name="name" placeholder="Nome do admin" required>
                    <input class="form-control" type="email" name="email" placeholder="Email do admin" required>
                    <input class="form-control" type="password" name="password" placeholder="Password" required>
                    <button class="btn btn-primary" <?= !$hasSqlite ? 'disabled' : '' ?>>Concluir instalação</button>
                </form>

                <a href="login.php" class="btn btn-link px-0 mt-3">Ir para login</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
