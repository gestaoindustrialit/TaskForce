<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        log_app_event($pdo, (int) $user['id'], 'auth.login_success', 'Login com sucesso.');
        redirect('dashboard.php');
    }

    log_app_event($pdo, null, 'auth.login_failed', 'Tentativa de login falhada.', ['email' => $email]);
    $error = 'Credenciais inválidas.';
}

$logoLight = app_setting($pdo, 'logo_navbar_light');
$logoDark = app_setting($pdo, 'logo_report_dark');
$hasLightLogo = !empty($logoLight);
$hasDarkLogo = !empty($logoDark);

$pageTitle = 'Login';
require __DIR__ . '/partials/header.php';
?>
<div class="row justify-content-center align-items-center auth-row">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm auth-card">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <?php if ($hasDarkLogo): ?>
                        <img src="<?= h($logoDark) ?>" alt="Logótipo empresa" class="auth-logo mb-3">
                    <?php elseif ($hasLightLogo): ?>
                        <img src="<?= h($logoLight) ?>" alt="Logótipo empresa" class="auth-logo mb-3">
                    <?php endif; ?>
                    <h1 class="h4 mb-1">Entrar</h1>
                    <p class="text-secondary small mb-0">Bem-vindo de volta ao TaskForce.</p>
                </div>
                <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
                <form method="post" class="vstack gap-3">
                    <input class="form-control form-control-lg" type="email" name="email" placeholder="Email" required>
                    <input class="form-control form-control-lg" type="password" name="password" placeholder="Password" required>
                    <button class="btn btn-primary btn-lg">Login</button>
                </form>
                <p class="small mt-4 mb-0 text-center">Ainda não tens conta? <a href="register.php">Registar</a></p>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
