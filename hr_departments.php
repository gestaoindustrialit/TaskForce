<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
if (!can_access_hr_module($pdo, $userId)) {
    http_response_code(403);
    exit('Acesso reservado a administradores e equipa RH.');
}

$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_group') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flashError = 'Indique um nome para o grupo.';
        } else {
            try { $pdo->prepare('INSERT INTO hr_department_groups(name) VALUES (?)')->execute([$name]); $flashSuccess = 'Grupo criado com sucesso.'; } catch (PDOException $e) { $flashError = 'Não foi possível criar o grupo (nome já existe).'; }
        }
    }
    if ($action === 'update_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($groupId <= 0 || $name === '') { $flashError = 'Dados inválidos para atualizar grupo.'; }
        else { try { $pdo->prepare('UPDATE hr_department_groups SET name = ? WHERE id = ?')->execute([$name, $groupId]); $flashSuccess = 'Grupo atualizado.'; } catch (PDOException $e) { $flashError = 'Não foi possível atualizar grupo (nome já existe).'; } }
    }
    if ($action === 'delete_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId <= 0) { $flashError = 'Grupo inválido.'; }
        else { $pdo->prepare('DELETE FROM hr_department_groups WHERE id = ?')->execute([$groupId]); $flashSuccess = 'Grupo eliminado.'; }
    }
    if ($action === 'create_department') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($name === '') { $flashError = 'Indique o nome do departamento.'; }
        else { try { $pdo->prepare('INSERT INTO hr_departments(name, group_id) VALUES (?, ?)')->execute([$name, $groupId > 0 ? $groupId : null]); $flashSuccess = 'Departamento criado com sucesso.'; } catch (PDOException $e) { $flashError = 'Não foi possível criar departamento (nome já existe).'; } }
    }
    if ($action === 'update_department') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($departmentId <= 0 || $name === '') { $flashError = 'Dados inválidos para atualizar departamento.'; }
        else { try { $pdo->prepare('UPDATE hr_departments SET name = ?, group_id = ? WHERE id = ?')->execute([$name, $groupId > 0 ? $groupId : null, $departmentId]); $flashSuccess = 'Departamento atualizado.'; } catch (PDOException $e) { $flashError = 'Não foi possível atualizar departamento (nome já existe).'; } }
    }
    if ($action === 'delete_department') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($departmentId <= 0) { $flashError = 'Departamento inválido.'; }
        else { $pdo->prepare('DELETE FROM hr_departments WHERE id = ?')->execute([$departmentId]); $flashSuccess = 'Departamento eliminado.'; }
    }
}

$groups = $pdo->query('SELECT id, name, created_at FROM hr_department_groups ORDER BY name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query('SELECT d.id, d.name, d.group_id, d.created_at, g.name AS group_name FROM hr_departments d LEFT JOIN hr_department_groups g ON g.id = d.group_id ORDER BY d.name COLLATE NOCASE ASC')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Departamentos';
require __DIR__ . '/partials/header.php';
?>
<style>
    .department-actions {
        display: flex;
        gap: .5rem;
        justify-content: flex-end;
        flex-wrap: nowrap;
    }

    .department-actions .btn {
        flex: 0 0 auto;
        min-width: 2rem;
    }

    .department-group-item,
    .department-edit-form {
        min-width: 0;
    }

    .department-edit-form .form-control,
    .department-edit-form .form-select,
    .department-group-item .form-control {
        min-width: 0;
    }

    @media (max-width: 991.98px) {
        .department-actions {
            justify-content: flex-start;
        }
    }
</style>
<a href="hr.php" class="btn btn-link px-0">&larr; Voltar ao módulo RH</a>
<h1 class="h3 mb-3">Gestão de departamentos e grupos</h1>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm"><div class="card-header bg-white"><h2 class="h6 mb-0">Grupos de departamentos</h2></div><div class="card-body">
            <form method="post" class="row g-2 mb-3"><input type="hidden" name="action" value="create_group"><div class="col-md-8"><input class="form-control" name="name" placeholder="Novo grupo (ex.: Qualidade)" required></div><div class="col-md-4 d-grid"><button class="btn btn-primary">Adicionar</button></div></form>
            <div class="list-group">
            <?php foreach ($groups as $group): ?>
                <form method="post" class="list-group-item department-group-item row g-2 align-items-center">
                    <input type="hidden" name="action" value="update_group"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                    <div class="col-lg"><input class="form-control form-control-sm" name="name" value="<?= h($group['name']) ?>" required></div>
                    <div class="col-auto">
                        <div class="department-actions">
                            <button class="btn btn-sm btn-outline-secondary" title="Guardar"><i class="bi bi-floppy"></i></button>
                            <button class="btn btn-sm btn-outline-primary" type="button" title="Editar" onclick="this.form.querySelector('input[name=name]').focus(); this.form.querySelector('input[name=name]').select();"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger" formaction="hr_departments.php" name="action" value="delete_group" title="Eliminar" onclick="return confirm('Eliminar este grupo?');"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </form>
            <?php endforeach; ?>
            </div>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm"><div class="card-header bg-white"><h2 class="h6 mb-0">Departamentos</h2></div><div class="card-body">
            <form method="post" class="row g-2 mb-3"><input type="hidden" name="action" value="create_department"><div class="col-md-6"><input class="form-control" name="name" placeholder="Nome do departamento" required></div><div class="col-md-4"><select class="form-select" name="group_id"><option value="">Sem grupo</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>"><?= h($group['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-primary">Criar</button></div></form>
            <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Departamento</th><th>Grupo</th><th style="width: 45%">Editar</th></tr></thead><tbody>
            <?php foreach ($departments as $department): ?>
                <tr><td><?= h($department['name']) ?></td><td><?= h((string) ($department['group_name'] ?? '—')) ?></td><td><form method="post" class="row g-2 department-edit-form"><input type="hidden" name="action" value="update_department"><input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>"><div class="col-xl-5 col-lg-12"><input class="form-control form-control-sm" name="name" value="<?= h($department['name']) ?>" required></div><div class="col-xl-4 col-md-8 col-lg-9"><select class="form-select form-select-sm" name="group_id"><option value="">Sem grupo</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= (int) ($department['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>><?= h($group['name']) ?></option><?php endforeach; ?></select></div><div class="col-xl-3 col-md-4 col-lg-3"><div class="department-actions"><button class="btn btn-sm btn-outline-secondary" title="Guardar"><i class="bi bi-floppy"></i></button><button class="btn btn-sm btn-outline-primary" type="button" title="Editar" onclick="this.form.querySelector('input[name=name]').focus(); this.form.querySelector('input[name=name]').select();"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-outline-danger" formaction="hr_departments.php" name="action" value="delete_department" title="Eliminar" onclick="return confirm('Eliminar este departamento?');"><i class="bi bi-trash"></i></button></div></div></form></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
