<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    $loggedUser = current_user($pdo);
    if ($loggedUser && (int) ($loggedUser['pin_only_login'] ?? 0) === 1) {
        redirect('shopfloor.php');
    }

    redirect('dashboard.php');
}

$error = null;
$email = trim((string) ($_POST['email'] ?? ''));
$loginMode = 'identify';
$pendingUser = null;
$requestIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

function safe_log_app_event(PDO $pdo, ?int $userId, string $eventType, string $description, array $context = []): void
{
    try {
        log_app_event($pdo, $userId, $eventType, $description, $context);
    } catch (Throwable $exception) {
        error_log('[TaskForce] Não foi possível registar evento de login: ' . $exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort(true);
    $action = trim((string) ($_POST['action'] ?? 'identify_user'));
    $identifier = strtolower($email !== '' ? $email : 'unknown');

    if (RateLimiter::tooManyLoginAttempts($pdo, $identifier, $requestIp)) {
        $error = 'Demasiadas tentativas de login. Tente novamente em 15 minutos.';
    }

    if ($error === null && $email === '') {
        $error = 'Indique um email válido.';
    } elseif ($error === null) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $pendingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pendingUser) {
            RateLimiter::recordLoginAttempt($pdo, $identifier, $requestIp, false);
            safe_log_app_event($pdo, null, 'auth.login_failed', 'Tentativa de login falhada (email não encontrado ou inativo).', ['email' => $email]);
            $error = 'Credenciais inválidas.';
        } else {
            $loginMode = (int) ($pendingUser['pin_only_login'] ?? 0) === 1 ? 'pin' : 'password';

            if ($action === 'login_password' && $loginMode === 'password') {
                $password = (string) ($_POST['password'] ?? '');
                if (password_verify($password, (string) ($pendingUser['password'] ?? ''))) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $pendingUser['id'];
                    $_SESSION['login_at'] = date('Y-m-d H:i:s');
                    RateLimiter::recordLoginAttempt($pdo, $identifier, $requestIp, true);
                    $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $pendingUser['id']]);
                    AuditLog::write($pdo, (int) $pendingUser['id'], 'auth.login_success', ['mode' => 'password']);
                    safe_log_app_event($pdo, (int) $pendingUser['id'], 'auth.login_success', 'Login com sucesso.');
                    redirect('dashboard.php');
                }

                RateLimiter::recordLoginAttempt($pdo, $identifier, $requestIp, false);
                safe_log_app_event($pdo, (int) $pendingUser['id'], 'auth.login_failed', 'Tentativa de login falhada.', ['email' => $email]);
                $error = 'Credenciais inválidas.';
            }

            if ($action === 'login_pin' && $loginMode === 'pin') {
                $pin = preg_replace('/\D+/', '', (string) ($_POST['pin'] ?? ''));

                $pinUsersStmt = $pdo->query('SELECT * FROM users WHERE is_active = 1 AND pin_only_login = 1');
                $pinUsers = $pinUsersStmt->fetchAll(PDO::FETCH_ASSOC);

                $matchedPinUser = null;
                foreach ($pinUsers as $pinUser) {
                    if (verify_pin_code($pinUser, $pin)) {
                        $matchedPinUser = $pinUser;
                        break;
                    }
                }

                if ($matchedPinUser) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $matchedPinUser['id'];
                    $_SESSION['login_at'] = date('Y-m-d H:i:s');
                    RateLimiter::recordLoginAttempt($pdo, $identifier, $requestIp, true);
                    $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $matchedPinUser['id']]);
                    AuditLog::write($pdo, (int) $matchedPinUser['id'], 'auth.login_success', ['mode' => 'pin']);
                    safe_log_app_event(
                        $pdo,
                        (int) $matchedPinUser['id'],
                        'auth.login_success',
                        'Login com PIN efetuado com sucesso.',
                        ['login_email' => $email]
                    );
                    redirect('shopfloor.php');
                }

                RateLimiter::recordLoginAttempt($pdo, $identifier, $requestIp, false);
                safe_log_app_event($pdo, (int) $pendingUser['id'], 'auth.login_failed', 'Tentativa de login com PIN falhada.', ['email' => $email]);
                $error = 'PIN inválido.';
            }

            if ($action === 'login_password' && $loginMode !== 'password') {
                $error = 'Este utilizador utiliza autenticação por PIN.';
            }

            if ($action === 'login_pin' && $loginMode !== 'pin') {
                $error = 'Este utilizador utiliza autenticação por password.';
            }
        }
    }
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

                <?php if ($loginMode === 'identify'): ?>
                    <form method="post" class="vstack gap-3" id="identifyOrPasswordForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="identify_user" id="identifyActionInput">
                        <input class="form-control form-control-lg" type="email" name="email" id="identifyEmailInput" placeholder="Email" value="<?= h((string) $email) ?>" required>
                        <div id="identifyPasswordWrapper" class="d-none">
                            <input class="form-control form-control-lg" type="password" name="password" id="identifyPasswordInput" placeholder="Password">
                        </div>
                        <button class="btn btn-primary btn-lg" id="identifySubmitButton">Continuar</button>
                    </form>
                    <script>
                        (() => {
                            const emailInput = document.getElementById('identifyEmailInput');
                            const passwordWrapper = document.getElementById('identifyPasswordWrapper');
                            const passwordInput = document.getElementById('identifyPasswordInput');
                            const actionInput = document.getElementById('identifyActionInput');
                            const submitButton = document.getElementById('identifySubmitButton');

                            const updateLoginFlowByEmail = () => {
                                const normalizedEmail = (emailInput.value || '').trim().toLowerCase();
                                const hasAtSymbol = normalizedEmail.includes('@');
                                const shouldUsePassword = hasAtSymbol && !normalizedEmail.startsWith('shopfloor@');

                                passwordWrapper.classList.toggle('d-none', !shouldUsePassword);
                                passwordInput.required = shouldUsePassword;
                                actionInput.value = shouldUsePassword ? 'login_password' : 'identify_user';
                                submitButton.textContent = shouldUsePassword ? 'Login' : 'Continuar';
                            };

                            emailInput.addEventListener('input', updateLoginFlowByEmail);
                            updateLoginFlowByEmail();
                        })();
                    </script>
                <?php elseif ($loginMode === 'password'): ?>
                    <form method="post" class="vstack gap-3">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="login_password">
                        <input class="form-control form-control-lg" type="email" name="email" value="<?= h((string) $email) ?>" readonly required>
                        <input class="form-control form-control-lg" type="password" name="password" placeholder="Password" required>
                        <button class="btn btn-primary btn-lg">Login</button>
                    </form>
                    <div class="text-center mt-3"><a href="login.php" class="small">Trocar de utilizador</a></div>
                <?php else: ?>
                    <form method="post" class="vstack gap-3" id="pinLoginForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="login_pin">
                        <input type="hidden" name="email" value="<?= h((string) $email) ?>">
                        <input type="password" class="form-control form-control-lg text-center" name="pin" id="pinInput" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="PIN de 6 dígitos" readonly required>
                        <div class="d-grid gap-2" style="grid-template-columns: repeat(3, 1fr); display:grid;">
                            <?php for ($digit = 1; $digit <= 9; $digit++): ?>
                                <button type="button" class="btn btn-outline-secondary btn-lg pin-key" data-value="<?= $digit ?>"><?= $digit ?></button>
                            <?php endfor; ?>
                            <button type="button" class="btn btn-outline-danger btn-lg" id="pinClear">Limpar</button>
                            <button type="button" class="btn btn-outline-secondary btn-lg pin-key" data-value="0">0</button>
                            <button type="submit" class="btn btn-primary btn-lg">Entrar</button>
                        </div>
                    </form>
                    <div class="text-center mt-3"><a href="login.php" class="small">Trocar de utilizador</a></div>
                    <script>
                        (() => {
                            const pinInput = document.getElementById('pinInput');
                            document.querySelectorAll('.pin-key').forEach((button) => {
                                button.addEventListener('click', () => {
                                    if (pinInput.value.length < 6) {
                                        pinInput.value += button.dataset.value;
                                    }
                                });
                            });
                            document.getElementById('pinClear')?.addEventListener('click', () => {
                                pinInput.value = '';
                            });
                        })();
                    </script>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
