<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!is_admin($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores.');
}

$accessProfileOptions = ['Utilizador', 'Chefias', 'RH', 'Administração'];
$userTypeOptions = ['Funcionário', 'Administrador', 'Trabalhador Externo', 'Prestador'];
$timezoneOptions = ['Europe/Lisbon', 'Europe/Madrid', 'UTC'];

$departmentOptionsStmt = $pdo->query('SELECT d.id, d.name, g.name AS group_name FROM hr_departments d LEFT JOIN hr_department_groups g ON g.id = d.group_id ORDER BY d.name COLLATE NOCASE ASC');
$departmentOptions = $departmentOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$scheduleOptionsStmt = $pdo->query('SELECT id, name, start_time, end_time FROM hr_schedules ORDER BY name COLLATE NOCASE ASC');
$scheduleOptions = $scheduleOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentNameById = [];
foreach ($departmentOptions as $departmentOption) {
    $departmentNameById[(int) $departmentOption['id']] = (string) $departmentOption['name'];
}

$flashSuccess = null;
$flashError = null;

function find_user_conflict(PDO $pdo, string $field, string $value, ?int $excludeUserId = null): ?array
{
    $normalizedValue = trim($value);
    if ($normalizedValue === '' || !in_array($field, ['email', 'username'], true)) {
        return null;
    }

    $sql = 'SELECT id, name, username, email FROM users WHERE LOWER(TRIM(' . $field . ')) = LOWER(TRIM(?))';
    $params = [$normalizedValue];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function build_user_conflict_message(string $field, string $value, ?array $existingUser = null): string
{
    $label = $field === 'email' ? 'email' : 'utilizador';
    $message = 'Não foi possível guardar o utilizador: o ' . $label . ' “' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '” já está em uso';

    if ($existingUser) {
        $existingName = trim((string) ($existingUser['name'] ?? ''));
        if ($existingName !== '') {
            $message .= ' por ' . htmlspecialchars($existingName, ENT_QUOTES, 'UTF-8');
        }
    }

    return $message . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $isAdmin = (int) ($_POST['is_admin'] ?? 0);
        $accessProfile = trim((string) ($_POST['access_profile'] ?? 'Utilizador'));
        $isActive = (int) ($_POST['is_active'] ?? 0);
        $mustChangePassword = (int) ($_POST['must_change_password'] ?? 0);
        $pinCode = preg_replace('/\D+/', '', (string) ($_POST['pin_code'] ?? ''));
        $pinCodeHash = $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : null;
        $pinOnlyLogin = (int) ($_POST['pin_only_login'] ?? 0);

        $userType = trim((string) ($_POST['user_type'] ?? 'Funcionário'));
        $userNumber = trim((string) ($_POST['user_number'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $initials = strtoupper(trim((string) ($_POST['initials'] ?? '')));
        if ($initials === '') {
            $initials = build_initials($name);
        }
        $emailNotificationsActive = (int) ($_POST['email_notifications_active'] ?? 0);
        $smsNotificationsActive = (int) ($_POST['sms_notifications_active'] ?? 0);
        $profession = trim((string) ($_POST['profession'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $managerName = trim((string) ($_POST['manager_name'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
        $terminationDate = trim((string) ($_POST['termination_date'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Lisbon'));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sendAccessEmail = (int) ($_POST['send_access_email'] ?? 0);

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $flashError = 'Preencha nome, utilizador, email e password para criar utilizador.';
        } elseif ($pinCode !== '' && !preg_match('/^\d{6}$/', $pinCode)) {
            $flashError = 'O PIN deve ter exatamente 6 dígitos.';
        } elseif ($pinOnlyLogin === 1 && $pinCode === '') {
            $flashError = 'Defina um PIN de 6 dígitos para login apenas com PIN.';
        } elseif ($existingEmailUser = find_user_conflict($pdo, 'email', $email)) {
            $flashError = build_user_conflict_message('email', $email, $existingEmailUser);
        } elseif ($existingUsernameUser = find_user_conflict($pdo, 'username', $username)) {
            $flashError = build_user_conflict_message('username', $username, $existingUsernameUser);
        } else {
            if (!in_array($accessProfile, $accessProfileOptions, true)) {
                $accessProfile = 'Utilizador';
            }
            if (!in_array($userType, $userTypeOptions, true)) {
                $userType = 'Funcionário';
            }
            if (!in_array($timezone, $timezoneOptions, true)) {
                $timezone = 'Europe/Lisbon';
            }

            if ($departmentId > 0 && isset($departmentNameById[$departmentId])) {
                $department = $departmentNameById[$departmentId];
            }

            try {
                $stmt = $pdo->prepare('INSERT INTO users(name, username, email, password, is_admin, access_profile, is_active, must_change_password, pin_code_hash, pin_code, pin_only_login, user_type, user_number, title, short_name, initials, email_notifications_active, sms_notifications_active, profession, category, manager_name, department, department_id, schedule_id, hire_date, termination_date, timezone, phone, mobile, notes, send_access_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $name,
                    $username,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $isAdmin,
                    $accessProfile,
                    $isActive,
                    $mustChangePassword,
                    $pinCodeHash,
                    $pinCodeHash,
                    $pinOnlyLogin,
                    $userType,
                    $userNumber,
                    $title,
                    $shortName,
                    $initials,
                    $emailNotificationsActive,
                    $smsNotificationsActive,
                    $profession,
                    $category,
                    $managerName,
                    $department,
                    $departmentId > 0 ? $departmentId : null,
                    $scheduleId > 0 ? $scheduleId : null,
                    $hireDate,
                    $terminationDate,
                    $timezone,
                    $phone,
                    $mobile,
                    $notes,
                    $sendAccessEmail,
                ]);
                $flashSuccess = 'Utilizador criado com sucesso.';
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'users.email') !== false) {
                    $flashError = build_user_conflict_message('email', $email, find_user_conflict($pdo, 'email', $email));
                } elseif (stripos($e->getMessage(), 'users.username') !== false) {
                    $flashError = build_user_conflict_message('username', $username, find_user_conflict($pdo, 'username', $username));
                } else {
                    error_log('[TaskForce][users.php] Erro ao criar utilizador: ' . $e->getMessage());
                    $flashError = 'Não foi possível criar utilizador. Verifique os dados e tente novamente.';
                }
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
        $pinCode = preg_replace('/\D+/', '', (string) ($_POST['pin_code'] ?? ''));
        $pinCodeHash = $pinCode !== '' ? password_hash($pinCode, PASSWORD_DEFAULT) : null;
        $pinOnlyLogin = (int) ($_POST['pin_only_login'] ?? 0);

        $userType = trim((string) ($_POST['user_type'] ?? 'Funcionário'));
        $userNumber = trim((string) ($_POST['user_number'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $shortName = trim((string) ($_POST['short_name'] ?? ''));
        $initials = strtoupper(trim((string) ($_POST['initials'] ?? '')));
        if ($initials === '') {
            $initials = build_initials($name);
        }
        $emailNotificationsActive = (int) ($_POST['email_notifications_active'] ?? 0);
        $smsNotificationsActive = (int) ($_POST['sms_notifications_active'] ?? 0);
        $profession = trim((string) ($_POST['profession'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $managerName = trim((string) ($_POST['manager_name'] ?? ''));
        $department = trim((string) ($_POST['department'] ?? ''));
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $hireDate = trim((string) ($_POST['hire_date'] ?? ''));
        $terminationDate = trim((string) ($_POST['termination_date'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Lisbon'));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $sendAccessEmail = (int) ($_POST['send_access_email'] ?? 0);

        if ($targetUserId <= 0 || $name === '' || $username === '' || $email === '') {
            $flashError = 'Dados inválidos para atualizar utilizador.';
        } elseif ($pinCode !== '' && !preg_match('/^\d{6}$/', $pinCode)) {
            $flashError = 'O PIN deve ter exatamente 6 dígitos.';
        } elseif ($pinOnlyLogin === 1 && $pinCode === '') {
            $flashError = 'Defina um PIN de 6 dígitos para login apenas com PIN.';
        } elseif ($existingEmailUser = find_user_conflict($pdo, 'email', $email, $targetUserId)) {
            $flashError = build_user_conflict_message('email', $email, $existingEmailUser);
        } elseif ($existingUsernameUser = find_user_conflict($pdo, 'username', $username, $targetUserId)) {
            $flashError = build_user_conflict_message('username', $username, $existingUsernameUser);
        } else {
            if (!in_array($accessProfile, $accessProfileOptions, true)) {
                $accessProfile = 'Utilizador';
            }
            if (!in_array($userType, $userTypeOptions, true)) {
                $userType = 'Funcionário';
            }
            if (!in_array($timezone, $timezoneOptions, true)) {
                $timezone = 'Europe/Lisbon';
            }

            if ($departmentId > 0 && isset($departmentNameById[$departmentId])) {
                $department = $departmentNameById[$departmentId];
            }

            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $pinCodeHash, $pinCodeHash, $pinOnlyLogin, $userType, $userNumber, $title, $shortName, $initials, $emailNotificationsActive, $smsNotificationsActive, $profession, $category, $managerName, $department, $departmentId > 0 ? $departmentId : null, $scheduleId > 0 ? $scheduleId : null, $hireDate, $terminationDate, $timezone, $phone, $mobile, $notes, $sendAccessEmail, $targetUserId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, access_profile = ?, is_active = ?, must_change_password = ?, pin_code_hash = COALESCE(?, pin_code_hash), pin_code = COALESCE(?, pin_code), pin_only_login = ?, user_type = ?, user_number = ?, title = ?, short_name = ?, initials = ?, email_notifications_active = ?, sms_notifications_active = ?, profession = ?, category = ?, manager_name = ?, department = ?, department_id = ?, schedule_id = ?, hire_date = ?, termination_date = ?, timezone = ?, phone = ?, mobile = ?, notes = ?, send_access_email = ? WHERE id = ?');
                    $stmt->execute([$name, $username, $email, $isTargetAdmin, $accessProfile, $isActive, $mustChangePassword, $pinCodeHash, $pinCodeHash, $pinOnlyLogin, $userType, $userNumber, $title, $shortName, $initials, $emailNotificationsActive, $smsNotificationsActive, $profession, $category, $managerName, $department, $departmentId > 0 ? $departmentId : null, $scheduleId > 0 ? $scheduleId : null, $hireDate, $terminationDate, $timezone, $phone, $mobile, $notes, $sendAccessEmail, $targetUserId]);
                }
                $flashSuccess = 'Utilizador atualizado com sucesso.';
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'users.email') !== false) {
                    $flashError = build_user_conflict_message('email', $email, find_user_conflict($pdo, 'email', $email, $targetUserId));
                } elseif (stripos($e->getMessage(), 'users.username') !== false) {
                    $flashError = build_user_conflict_message('username', $username, find_user_conflict($pdo, 'username', $username, $targetUserId));
                } else {
                    error_log('[TaskForce][users.php] Erro ao atualizar utilizador #' . $targetUserId . ': ' . $e->getMessage());
                    $flashError = 'Não foi possível atualizar utilizador. Verifique os dados e tente novamente.';
                }
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

$usersStmt = $pdo->prepare('SELECT id, name, username, email, is_admin, access_profile, is_active, must_change_password, pin_only_login, created_at, user_type, user_number, title, short_name, initials, email_notifications_active, sms_notifications_active, profession, category, manager_name, department, department_id, schedule_id, hire_date, termination_date, timezone, phone, mobile, notes, send_access_email FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
$usersStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$usersStmt->bindValue(2, $offset, PDO::PARAM_INT);
$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$managerOptions = $pdo->query('SELECT name FROM users WHERE name IS NOT NULL AND TRIM(name) <> "" ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$departmentOptions = $pdo->query('SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND TRIM(department) <> "" ORDER BY department ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

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
                <thead><tr><th>#</th><th>Nome</th><th>Utilizador</th><th>Email</th><th>Perfil</th><th>Tipo</th><th>Estado</th><th>Segurança</th><th>Criado</th><th></th></tr></thead>
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
                        <td><?= h((string) ($user['user_type'] ?? 'Funcionário')) ?></td>
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

<style>
    .user-form-compact .form-label { margin-bottom: .25rem; font-size: .85rem; }
    .user-form-compact .form-control,
    .user-form-compact .form-select { font-size: .9rem; padding: .35rem .6rem; min-height: calc(1.5em + .7rem + 2px); }
    .user-form-compact .form-check-label { font-size: .9rem; }
</style>

<datalist id="managerOptions">
    <?php foreach ($managerOptions as $managerOption): ?>
        <option value="<?= h((string) $managerOption) ?>"></option>
    <?php endforeach; ?>
</datalist>

<datalist id="departmentOptions">
    <?php foreach ($departmentOptions as $departmentOption): ?>
        <option value="<?= h((string) $departmentOption) ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content user-form-compact" method="post">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header"><h5 class="modal-title">Novo utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control js-initials-source" name="name" placeholder="Nome" required></div>
                    <div class="col-md-3"><label class="form-label">Número</label><input class="form-control" name="user_number" placeholder="Número"></div>
                    <div class="col-md-3"><label class="form-label">Sigla (automático)</label><input class="form-control js-initials-target" name="initials" placeholder="Sigla" readonly></div>
                    <div class="col-md-6"><label class="form-label">Utilizador</label><input class="form-control" name="username" placeholder="Utilizador" required></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" placeholder="Email" required></div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="user_type" required>
                            <?php foreach ($userTypeOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Funcionário' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Perfil de acesso</label>
                        <select class="form-select" name="access_profile" required>
                            <?php foreach ($accessProfileOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Utilizador' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Password</label><input class="form-control" type="password" name="password" placeholder="Password" required></div>
                    <div class="col-md-4"><label class="form-label">PIN (6 dígitos)</label><input class="form-control" type="text" name="pin_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Opcional"></div>
                    <div class="col-md-4"><label class="form-label">Profissão</label><input class="form-control" name="profession" placeholder="Profissão"></div>
                    <div class="col-md-4"><label class="form-label">Categoria</label><input class="form-control" name="category" placeholder="Categoria"></div>
                    <div class="col-md-4"><label class="form-label">Responsável</label><input class="form-control" name="manager_name" placeholder="Responsável" list="managerOptions" autocomplete="off"></div>
                    <div class="col-md-4">
                        <label class="form-label">Departamento</label>
                        <select class="form-select" name="department_id">
                            <option value="">Sem departamento</option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <option value="<?= (int) $departmentOption['id'] ?>"><?= h($departmentOption['name']) ?><?= !empty($departmentOption['group_name']) ? ' · ' . h($departmentOption['group_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Horário</label>
                        <select class="form-select" name="schedule_id">
                            <option value="">Sem horário</option>
                            <?php foreach ($scheduleOptions as $scheduleOption): ?>
                                <option value="<?= (int) $scheduleOption['id'] ?>"><?= h($scheduleOption['name']) ?> (<?= h((string) $scheduleOption['start_time']) ?>-<?= h((string) $scheduleOption['end_time']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Data de admissão</label><input class="form-control" type="date" name="hire_date"></div>
                    <div class="col-md-4"><label class="form-label">Data saída</label><input class="form-control" type="date" name="termination_date"></div>
                    <div class="col-md-4">
                        <label class="form-label">Fuso horário</label>
                        <select class="form-select" name="timezone">
                            <?php foreach ($timezoneOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Europe/Lisbon' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Telefone</label><input class="form-control" name="phone" placeholder="Telefone"></div>
                    <div class="col-md-4"><label class="form-label">Telemóvel</label><input class="form-control" name="mobile" placeholder="Telemóvel"></div>
                    <div class="col-md-4"></div>
                    <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" name="notes" rows="3" placeholder="Observações"></textarea></div>
                </div>

                <hr>

                <div class="row g-2">
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdmin"><label class="form-check-label" for="isAdmin">Administrador</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked><label class="form-check-label" for="isActive">Ativo</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="email_notifications_active" value="1" id="emailNotificationsActive" checked><label class="form-check-label" for="emailNotificationsActive">Ativo para email</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_notifications_active" value="1" id="smsNotificationsActive"><label class="form-check-label" for="smsNotificationsActive">Ativo para SMS</label></div>
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="send_access_email" value="1" id="sendAccessEmail"><label class="form-check-label" for="sendAccessEmail">Enviar dados de acesso</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePassword"><label class="form-check-label" for="mustChangePassword">Obrigar alteração da senha no próximo login</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="pin_only_login" value="1" id="pinOnlyLogin"><label class="form-check-label" for="pinOnlyLogin">Login apenas com PIN (Shopfloor)</label></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar utilizador</button></div>
        </form>
    </div>
</div>

<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?= (int) $user['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content user-form-compact" method="post">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
            <div class="modal-header"><h5 class="modal-title">Editar utilizador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Nome</label><input class="form-control js-initials-source" name="name" value="<?= h($user['name']) ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Número</label><input class="form-control" name="user_number" value="<?= h((string) ($user['user_number'] ?? '')) ?>" placeholder="Número"></div>
                    <div class="col-md-3"><label class="form-label">Sigla (automático)</label><input class="form-control js-initials-target" name="initials" value="<?= h((string) ($user['initials'] ?? '')) ?>" placeholder="Sigla" readonly></div>
                    <div class="col-md-6"><label class="form-label">Utilizador</label><input class="form-control" name="username" value="<?= h((string) ($user['username'] ?? '')) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h($user['email']) ?>" required></div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="user_type" required>
                            <?php foreach ($userTypeOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['user_type'] ?? 'Funcionário')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Perfil de acesso</label>
                        <select class="form-select" name="access_profile" required>
                            <?php foreach ($accessProfileOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['access_profile'] ?? 'Utilizador')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Nova password (opcional)</label><input class="form-control" type="password" name="password" placeholder="Nova password (opcional)"></div>
                    <div class="col-md-4"><label class="form-label">Novo PIN (6 dígitos)</label><input class="form-control" type="text" name="pin_code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Opcional"></div>
                    <div class="col-md-4"><label class="form-label">Profissão</label><input class="form-control" name="profession" value="<?= h((string) ($user['profession'] ?? '')) ?>" placeholder="Profissão"></div>
                    <div class="col-md-4"><label class="form-label">Categoria</label><input class="form-control" name="category" value="<?= h((string) ($user['category'] ?? '')) ?>" placeholder="Categoria"></div>
                    <div class="col-md-4"><label class="form-label">Responsável</label><input class="form-control" name="manager_name" value="<?= h((string) ($user['manager_name'] ?? '')) ?>" placeholder="Responsável" list="managerOptions" autocomplete="off"></div>
                    <div class="col-md-4">
                        <label class="form-label">Departamento</label>
                        <select class="form-select" name="department_id">
                            <option value="">Sem departamento</option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <option value="<?= (int) $departmentOption['id'] ?>" <?= (int) ($user['department_id'] ?? 0) === (int) $departmentOption['id'] ? 'selected' : '' ?>><?= h($departmentOption['name']) ?><?= !empty($departmentOption['group_name']) ? ' · ' . h($departmentOption['group_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Horário</label>
                        <select class="form-select" name="schedule_id">
                            <option value="">Sem horário</option>
                            <?php foreach ($scheduleOptions as $scheduleOption): ?>
                                <option value="<?= (int) $scheduleOption['id'] ?>" <?= (int) ($user['schedule_id'] ?? 0) === (int) $scheduleOption['id'] ? 'selected' : '' ?>><?= h($scheduleOption['name']) ?> (<?= h((string) $scheduleOption['start_time']) ?>-<?= h((string) $scheduleOption['end_time']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Data de admissão</label><input class="form-control" type="date" name="hire_date" value="<?= h((string) ($user['hire_date'] ?? '')) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Data saída</label><input class="form-control" type="date" name="termination_date" value="<?= h((string) ($user['termination_date'] ?? '')) ?>"></div>
                    <div class="col-md-4">
                        <label class="form-label">Fuso horário</label>
                        <select class="form-select" name="timezone">
                            <?php foreach ($timezoneOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= ((string) ($user['timezone'] ?? 'Europe/Lisbon')) === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Telefone</label><input class="form-control" name="phone" value="<?= h((string) ($user['phone'] ?? '')) ?>" placeholder="Telefone"></div>
                    <div class="col-md-4"><label class="form-label">Telemóvel</label><input class="form-control" name="mobile" value="<?= h((string) ($user['mobile'] ?? '')) ?>" placeholder="Telemóvel"></div>
                    <div class="col-md-4"></div>
                    <div class="col-12"><label class="form-label">Observações</label><textarea class="form-control" name="notes" rows="3" placeholder="Observações"><?= h((string) ($user['notes'] ?? '')) ?></textarea></div>
                </div>

                <hr>

                <div class="row g-2">
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="is_admin" value="1" id="isAdminEdit<?= (int) $user['id'] ?>" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isAdminEdit<?= (int) $user['id'] ?>">Administrador</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="isActiveEdit<?= (int) $user['id'] ?>">Ativo</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="email_notifications_active" value="1" id="emailNotificationsActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['email_notifications_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="emailNotificationsActiveEdit<?= (int) $user['id'] ?>">Ativo para email</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_notifications_active" value="1" id="smsNotificationsActiveEdit<?= (int) $user['id'] ?>" <?= (int) ($user['sms_notifications_active'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="smsNotificationsActiveEdit<?= (int) $user['id'] ?>">Ativo para SMS</label></div>
                    <div class="col-md-6 form-check"><input class="form-check-input" type="checkbox" name="send_access_email" value="1" id="sendAccessEmailEdit<?= (int) $user['id'] ?>" <?= (int) ($user['send_access_email'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="sendAccessEmailEdit<?= (int) $user['id'] ?>">Enviar dados de acesso</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="must_change_password" value="1" id="mustChangePasswordEdit<?= (int) $user['id'] ?>" <?= (int) ($user['must_change_password'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="mustChangePasswordEdit<?= (int) $user['id'] ?>">Obrigar alteração da senha no próximo login</label></div>
                    <div class="col-md-6 form-check form-switch"><input class="form-check-input" type="checkbox" name="pin_only_login" value="1" id="pinOnlyLoginEdit<?= (int) $user['id'] ?>" <?= (int) ($user['pin_only_login'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pinOnlyLoginEdit<?= (int) $user['id'] ?>">Login apenas com PIN (Shopfloor)</label></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Guardar utilizador</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script>
    function buildInitialsFromName(name) {
        return name.trim().split(/\s+/).filter(Boolean).slice(0, 3).map((part) => part.charAt(0).toUpperCase()).join('');
    }

    document.querySelectorAll('.modal-content.user-form-compact').forEach((form) => {
        const source = form.querySelector('.js-initials-source');
        const target = form.querySelector('.js-initials-target');
        if (!source || !target) {
            return;
        }

        const syncInitials = () => {
            target.value = buildInitialsFromName(source.value);
        };

        source.addEventListener('input', syncInitials);
        if (!target.value) {
            syncInitials();
        }
    });
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
