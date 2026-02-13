<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);
$showCompleted = (int) ($_GET['show_completed'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_team_form' && $isAdmin) {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? 'tornearia');

        $fields = [];
        $labels = $_POST['field_label'] ?? [];
        $types = $_POST['field_type'] ?? [];
        $requiredFlags = $_POST['field_required'] ?? [];
        $optionsList = $_POST['field_options'] ?? [];

        foreach ($labels as $idx => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $type = (string) ($types[$idx] ?? 'text');
            if (!in_array($type, ['text', 'number', 'date', 'textarea', 'select', 'file'], true)) {
                $type = 'text';
            }

            $required = isset($requiredFlags[$idx]) && $requiredFlags[$idx] === '1';
            $options = [];
            if ($type === 'select') {
                $raw = trim((string) ($optionsList[$idx] ?? ''));
                if ($raw !== '') {
                    $options = array_values(array_filter(array_map('trim', explode(',', $raw))));
                }
            }

            $fields[] = [
                'label' => $label,
                'name' => 'field_' . ($idx + 1),
                'type' => $type,
                'required' => $required,
                'options' => $options,
            ];
        }

        if ($teamId > 0 && $title !== '' && count($fields) > 0) {
            $stmt = $pdo->prepare('INSERT INTO team_forms(team_id, title, description, department, fields_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$teamId, $title, $description, $department, json_encode($fields, JSON_UNESCAPED_UNICODE), $userId]);
            $_SESSION['flash_success'] = 'Formulário global criado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Preencha equipa, título e pelo menos um campo do formulário.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'update_team_form' && $isAdmin) {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? '');

        $fields = [];
        $labels = $_POST['field_label'] ?? [];
        $types = $_POST['field_type'] ?? [];
        $requiredFlags = $_POST['field_required'] ?? [];
        $optionsList = $_POST['field_options'] ?? [];

        foreach ($labels as $idx => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $type = (string) ($types[$idx] ?? 'text');
            if (!in_array($type, ['text', 'number', 'date', 'textarea', 'select', 'file'], true)) {
                $type = 'text';
            }

            $required = isset($requiredFlags[$idx]) && $requiredFlags[$idx] === '1';
            $options = [];
            if ($type === 'select') {
                $raw = trim((string) ($optionsList[$idx] ?? ''));
                if ($raw !== '') {
                    $options = array_values(array_filter(array_map('trim', explode(',', $raw))));
                }
            }

            $fields[] = [
                'label' => $label,
                'name' => 'field_' . ($idx + 1),
                'type' => $type,
                'required' => $required,
                'options' => $options,
            ];
        }

        if ($formId > 0 && $teamId > 0 && $title !== '' && count($fields) > 0) {
            $stmt = $pdo->prepare('UPDATE team_forms SET team_id = ?, title = ?, description = ?, department = ?, fields_json = ? WHERE id = ?');
            $stmt->execute([$teamId, $title, $description, $department, json_encode($fields, JSON_UNESCAPED_UNICODE), $formId]);
            $_SESSION['flash_success'] = 'Formulário atualizado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Para editar: preencha equipa, título e pelo menos um campo.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'delete_team_form' && $isAdmin) {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM team_forms WHERE id = ?');
        $stmt->execute([$formId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_success'] = 'Formulário eliminado com sucesso.';
        } else {
            $_SESSION['flash_error'] = 'Não foi possível eliminar o formulário selecionado.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'submit_team_form') {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        $formStmt = $pdo->prepare('SELECT id, fields_json FROM team_forms WHERE id = ? AND is_active = 1');
        $formStmt->execute([$formId]);
        $form = $formStmt->fetch(PDO::FETCH_ASSOC);

        if ($form) {
            $fields = json_decode((string) $form['fields_json'], true) ?: [];
            $payload = [];
            $valid = true;

            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                $required = !empty($field['required']);

                if ($type === 'file') {
                    $file = $_FILES[$name] ?? null;
                    $hasFile = $file && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                    if ($required && !$hasFile) {
                        $valid = false;
                        break;
                    }

                    $storedFile = null;
                    if ($hasFile) {
                        $uploadDir = __DIR__ . '/uploads/tickets';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }

                        $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
                        $safeName = uniqid('ticket_', true) . ($ext ? '.' . strtolower($ext) : '');
                        $targetPath = $uploadDir . '/' . $safeName;
                        if (move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
                            $storedFile = [
                                'name' => (string) $file['name'],
                                'path' => 'uploads/tickets/' . $safeName,
                            ];
                        } elseif ($required) {
                            $valid = false;
                            break;
                        }
                    }

                    $payload[] = [
                        'label' => $field['label'] ?? $name,
                        'name' => $name,
                        'type' => $type,
                        'value' => $storedFile,
                    ];
                    continue;
                }

                $value = trim((string) ($_POST[$name] ?? ''));
                if ($required && $value === '') {
                    $valid = false;
                    break;
                }

                $payload[] = [
                    'label' => $field['label'] ?? $name,
                    'name' => $name,
                    'type' => $type,
                    'value' => $value,
                ];
            }

            if ($valid) {
                $assignee = $assigneeUserId > 0 ? $assigneeUserId : null;
                $insert = $pdo->prepare('INSERT INTO team_form_entries(form_id, payload_json, created_by, assignee_user_id, status) VALUES (?, ?, ?, ?, "open")');
                $insert->execute([$formId, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId, $assignee]);
                $_SESSION['flash_success'] = 'Ticket submetido com sucesso.';
            } else {
                $_SESSION['flash_error'] = 'Existem campos obrigatórios por preencher.';
            }
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }

    if ($action === 'update_ticket' && $isAdmin) {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'open');
        $assigneeUserId = (int) ($_POST['assignee_user_id'] ?? 0);
        if (in_array($status, ['open', 'done'], true) && $entryId > 0) {
            $completedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;
            $stmt = $pdo->prepare('UPDATE team_form_entries SET status = ?, assignee_user_id = ?, completed_at = ? WHERE id = ?');
            $stmt->execute([$status, $assigneeUserId > 0 ? $assigneeUserId : null, $completedAt, $entryId]);
            $_SESSION['flash_success'] = 'Ticket atualizado.';
        }

        redirect('requests.php?show_completed=' . ($showCompleted ? '1' : '0'));
    }
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query('SELECT id, name, email FROM users ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$forms = $pdo->query('SELECT tf.*, t.name AS team_name, u.name AS creator_name FROM team_forms tf INNER JOIN teams t ON t.id = tf.team_id INNER JOIN users u ON u.id = tf.created_by WHERE tf.is_active = 1 ORDER BY tf.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$entriesSql = 'SELECT e.*, f.title AS form_title, t.name AS team_name, u.name AS creator_name, a.name AS assignee_name
FROM team_form_entries e
INNER JOIN team_forms f ON f.id = e.form_id
INNER JOIN teams t ON t.id = f.team_id
INNER JOIN users u ON u.id = e.created_by
LEFT JOIN users a ON a.id = e.assignee_user_id';
$entriesWhere = $showCompleted ? '' : ' WHERE e.status != "done"';
$entries = $pdo->query($entriesSql . $entriesWhere . ' ORDER BY e.created_at DESC LIMIT 80')->fetchAll(PDO::FETCH_ASSOC);
$completedEntries = $pdo->query($entriesSql . ' WHERE e.status = "done" ORDER BY COALESCE(e.completed_at, e.created_at) DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pedidos às equipas';
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card mb-4 p-4">
    <h1 class="h3 mb-1">Sistema de ticketing</h1>
    <p class="mb-0 text-white-50">Submete pedidos simples, atribui a qualquer utilizador e acompanha histórico.</p>
</section>

<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Formulários globais</h2>
    <div class="d-flex align-items-center gap-3">
        <form method="get" class="form-check form-switch mb-0">
            <input type="hidden" name="show_completed" value="0">
            <input class="form-check-input" type="checkbox" id="showCompleted" name="show_completed" value="1" <?= $showCompleted ? 'checked' : '' ?> onchange="this.form.submit()">
            <label class="form-check-label small" for="showCompleted">Mostrar concluídos na vista principal</label>
        </form>
        <?php if ($isAdmin): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamFormModal">Gerar formulário (admin)</button><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Submeter ticket</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($forms as $form): ?>
                    <?php $formFields = json_decode((string) $form['fields_json'], true) ?: []; ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h4 class="h6 mb-1"><?= h($form['title']) ?></h4>
                                <p class="small text-muted mb-2"><?= h($form['description']) ?: 'Sem descrição' ?></p>
                                <small class="text-muted d-block">Equipa: <?= h($form['team_name']) ?> · Departamento: <?= h($form['department']) ?></small>
                                <small class="text-muted d-block">Criado por <?= h($form['creator_name']) ?></small>
                            </div>
                            <?php if ($isAdmin): ?>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editFormModal<?= (int) $form['id'] ?>">Editar</button>
                                    <form method="post" onsubmit="return confirm('Eliminar este formulário e todos os tickets associados?');">
                                        <input type="hidden" name="action" value="delete_team_form">
                                        <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Eliminar</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="border rounded p-3 mt-3 bg-light-subtle">
                            <form method="post" enctype="multipart/form-data" class="vstack gap-2">
                                <input type="hidden" name="action" value="submit_team_form">
                                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">

                                <?php foreach ($formFields as $field): ?>
                                    <?php
                                    $fieldName = (string) ($field['name'] ?? '');
                                    $required = !empty($field['required']);
                                    $fieldType = (string) ($field['type'] ?? 'text');
                                    ?>
                                    <label class="form-label small mb-1"><?= h($field['label']) ?><?= $required ? ' *' : '' ?></label>
                                    <?php if ($fieldType === 'textarea'): ?>
                                        <textarea class="form-control form-control-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>></textarea>
                                    <?php elseif ($fieldType === 'select'): ?>
                                        <select class="form-select form-select-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                            <option value="">Selecionar...</option>
                                            <?php foreach (($field['options'] ?? []) as $option): ?>
                                                <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fieldType === 'file'): ?>
                                        <input class="form-control form-control-sm" type="file" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                    <?php else: ?>
                                        <input class="form-control form-control-sm" type="<?= h($fieldType) ?>" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <label class="form-label small mb-1">Atribuir ticket a</label>
                                <select class="form-select form-select-sm" name="assignee_user_id">
                                    <option value="0">Sem atribuição</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int) $user['id'] ?>"><?= h($user['name']) ?> (<?= h($user['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>

                                <button class="btn btn-primary btn-sm mt-2">Submeter ticket</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$forms): ?><div class="list-group-item text-muted">Não existem formulários ativos. O admin pode gerar formulários globais.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Tickets recentes<?= $showCompleted ? ' (inclui concluídos)' : '' ?></h3></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Ticket</th><th>Atribuído</th><th>Estado</th></tr></thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td>
                                    <?= h($entry['form_title']) ?><br>
                                    <small class="text-muted"><?= h(date('d/m/Y H:i', strtotime($entry['created_at']))) ?> · <?= h($entry['team_name']) ?></small>
                                </td>
                                <td>
                                    <?= h($entry['assignee_name'] ?: 'Sem atribuição') ?><br>
                                    <small class="text-muted">por <?= h($entry['creator_name']) ?></small>
                                </td>
                                <td>
                                    <?php if ($isAdmin): ?>
                                        <form method="post" class="vstack gap-1">
                                            <input type="hidden" name="action" value="update_ticket">
                                            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                                            <select name="assignee_user_id" class="form-select form-select-sm">
                                                <option value="0">Sem atribuição</option>
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?= (int) $user['id'] ?>" <?= (int) $entry['assignee_user_id'] === (int) $user['id'] ? 'selected' : '' ?>><?= h($user['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select name="status" class="form-select form-select-sm">
                                                <option value="open" <?= $entry['status'] === 'open' ? 'selected' : '' ?>>Aberto</option>
                                                <option value="done" <?= $entry['status'] === 'done' ? 'selected' : '' ?>>Concluído</option>
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary">Guardar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-<?= $entry['status'] === 'done' ? 'success' : 'secondary' ?>"><?= $entry['status'] === 'done' ? 'Concluído' : 'Aberto' ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$entries): ?><tr><td colspan="3" class="text-muted">Sem tickets para apresentar.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card soft-card shadow-sm mt-4">
    <div class="card-header bg-white"><h3 class="h5 mb-0">Histórico de tickets concluídos</h3></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Ticket</th><th>Atribuído</th><th>Concluído em</th></tr></thead>
            <tbody>
                <?php foreach ($completedEntries as $entry): ?>
                    <tr>
                        <td><?= h($entry['form_title']) ?><br><small class="text-muted"><?= h($entry['team_name']) ?> · criado por <?= h($entry['creator_name']) ?></small></td>
                        <td><?= h($entry['assignee_name'] ?: 'Sem atribuição') ?></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($entry['completed_at'] ?: $entry['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$completedEntries): ?><tr><td colspan="3" class="text-muted">Sem histórico de tickets concluídos.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="createTeamFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_team_form">
            <div class="modal-header"><h5 class="modal-title">Gerar formulário global (Admin)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="title" placeholder="Título" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
                <select class="form-select" name="team_id" required>
                    <option value="">Selecionar equipa destino</option>
                    <?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>"><?= h($team['name']) ?></option><?php endforeach; ?>
                </select>
                <input class="form-control" name="department" placeholder="Departamento (ex: Tornearia)">
                <div class="border rounded p-3">
                    <h6>Campos personalizados</h6>
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4"><input class="form-control form-control-sm" name="field_label[]" placeholder="Label do campo"></div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" name="field_type[]">
                                    <option value="text">Texto</option>
                                    <option value="number">Número</option>
                                    <option value="date">Data</option>
                                    <option value="textarea">Área de texto</option>
                                    <option value="select">Escolha</option>
                                    <option value="file">Ficheiros</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select form-select-sm" name="field_required[]">
                                    <option value="0" selected>Opcional</option>
                                    <option value="1">Obrigatório</option>
                                </select>
                            </div>
                            <div class="col-md-3"><input class="form-control form-control-sm" name="field_options[]" placeholder="Opções (a,b,c)"></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar formulário</button></div>
        </form>
    </div>
</div>

<?php foreach ($forms as $form): ?>
    <?php $formFields = json_decode((string) $form['fields_json'], true) ?: []; ?>
    <div class="modal fade" id="editFormModal<?= (int) $form['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post">
                <input type="hidden" name="action" value="update_team_form">
                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Editar formulário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body vstack gap-3">
                    <input class="form-control" name="title" value="<?= h($form['title']) ?>" required>
                    <textarea class="form-control" name="description" placeholder="Descrição"><?= h($form['description']) ?></textarea>
                    <select class="form-select" name="team_id" required>
                        <option value="">Selecionar equipa destino</option>
                        <?php foreach ($teams as $team): ?><option value="<?= (int) $team['id'] ?>" <?= (int) $team['id'] === (int) $form['team_id'] ? 'selected' : '' ?>><?= h($team['name']) ?></option><?php endforeach; ?>
                    </select>
                    <input class="form-control" name="department" value="<?= h($form['department']) ?>" placeholder="Departamento (ex: Tornearia)">

                    <div class="border rounded p-3">
                        <h6>Campos personalizados</h6>
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <?php $field = $formFields[$i] ?? []; ?>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><input class="form-control form-control-sm" name="field_label[]" value="<?= h($field['label'] ?? '') ?>" placeholder="Label do campo"></div>
                                <div class="col-md-3">
                                    <?php $type = $field['type'] ?? 'text'; ?>
                                    <select class="form-select form-select-sm" name="field_type[]">
                                        <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>Texto</option>
                                        <option value="number" <?= $type === 'number' ? 'selected' : '' ?>>Número</option>
                                        <option value="date" <?= $type === 'date' ? 'selected' : '' ?>>Data</option>
                                        <option value="textarea" <?= $type === 'textarea' ? 'selected' : '' ?>>Área de texto</option>
                                        <option value="select" <?= $type === 'select' ? 'selected' : '' ?>>Escolha</option>
                                        <option value="file" <?= $type === 'file' ? 'selected' : '' ?>>Ficheiros</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select form-select-sm" name="field_required[]">
                                        <option value="0" <?= empty($field['required']) ? 'selected' : '' ?>>Opcional</option>
                                        <option value="1" <?= !empty($field['required']) ? 'selected' : '' ?>>Obrigatório</option>
                                    </select>
                                </div>
                                <div class="col-md-3"><input class="form-control form-control-sm" name="field_options[]" value="<?= h(implode(',', $field['options'] ?? [])) ?>" placeholder="Opções (a,b,c)"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary">Guardar alterações</button></div>
            </form>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
