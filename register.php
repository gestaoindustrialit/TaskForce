<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha todos os campos.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO users(name, email, password) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['user_id'] = (int) $pdo->lastInsertId();
            redirect('dashboard.php');
        } catch (PDOException $e) {
            $error = 'Email já está em uso.';
        }
    }
}

$pageTitle = 'Criar conta';
require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Criar conta</h1>
                <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                <form method="post" class="vstack gap-3">
                    <input class="form-control" name="name" placeholder="Nome" required>
                    <input class="form-control" type="email" name="email" placeholder="Email" required>
                    <input class="form-control" type="password" name="password" placeholder="Password" required>
                    <button class="btn btn-primary">Registar</button>
                </form>
                <p class="small mt-3 mb-0">Já tens conta? <a href="login.php">Entrar</a></p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
