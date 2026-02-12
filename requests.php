<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$isAdmin = is_admin($pdo, $userId);

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
            if (!in_array($type, ['text', 'number', 'date', 'textarea', 'select'], true)) {
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
            $_SESSION['flash_success'] = 'Formulário global criado. O botão de submissão já está disponível para todos os users.';
        } else {
            $_SESSION['flash_error'] = 'Preencha equipa, título e pelo menos um campo do formulário.';
        }

        redirect('requests.php');
    }

    if ($action === 'submit_team_form') {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $formStmt = $pdo->prepare('SELECT id, fields_json, title FROM team_forms WHERE id = ? AND is_active = 1');
        $formStmt->execute([$formId]);
        $form = $formStmt->fetch(PDO::FETCH_ASSOC);

        if ($form) {
            $fields = json_decode((string) $form['fields_json'], true) ?: [];
            $payload = [];
            $valid = true;

            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $value = trim((string) ($_POST[$name] ?? ''));
                if (($field['required'] ?? false) && $value === '') {
                    $valid = false;
                    break;
                }
                $payload[] = [
                    'label' => $field['label'] ?? $name,
                    'name' => $name,
                    'value' => $value,
                    'type' => $field['type'] ?? 'text',
                ];
            }

            if ($valid) {
                $insert = $pdo->prepare('INSERT INTO team_form_entries(form_id, payload_json, created_by) VALUES (?, ?, ?)');
                $insert->execute([$formId, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId]);
                $_SESSION['flash_success'] = 'Pedido submetido com sucesso.';
            } else {
                $_SESSION['flash_error'] = 'Existem campos obrigatórios por preencher.';
            }
        }

        redirect('requests.php');
    }
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$forms = $pdo->query('SELECT tf.*, t.name AS team_name, u.name AS creator_name FROM team_forms tf INNER JOIN teams t ON t.id = tf.team_id INNER JOIN users u ON u.id = tf.created_by WHERE tf.is_active = 1 ORDER BY tf.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$entries = $pdo->query('SELECT e.*, f.title AS form_title, t.name AS team_name, u.name AS creator_name FROM team_form_entries e INNER JOIN team_forms f ON f.id = e.form_id INNER JOIN teams t ON t.id = f.team_id INNER JOIN users u ON u.id = e.created_by ORDER BY e.created_at DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pedidos às equipas';
require __DIR__ . '/partials/header.php';
?>
<section class="hero-card mb-4 p-4">
    <h1 class="h3 mb-1">Pedidos diretos às equipas</h1>
    <p class="mb-0 text-white-50">Submete pedidos sem estar dentro de um projeto específico.</p>
</section>

<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert alert-success"><?= h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Formulários globais</h2>
    <?php if ($isAdmin): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamFormModal">Gerar formulário (admin)</button><?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Submeter pedido</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($forms as $form): ?>
                    <?php $formFields = json_decode((string) $form['fields_json'], true) ?: []; ?>
                    <div class="list-group-item">
                        <h4 class="h6 mb-1"><?= h($form['title']) ?></h4>
                        <p class="small text-muted mb-1">Equipa destino: <strong><?= h($form['team_name']) ?></strong> · Departamento: <strong><?= h($form['department']) ?></strong></p>
                        <p class="small mb-2"><?= h($form['description']) ?></p>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#submit<?= (int) $form['id'] ?>">Abrir formulário</button>

                        <div class="collapse mt-2" id="submit<?= (int) $form['id'] ?>">
                            <form method="post" class="vstack gap-2 border rounded p-3 bg-light-subtle">
                                <input type="hidden" name="action" value="submit_team_form">
                                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">

                                <?php foreach ($formFields as $field): ?>
                                    <?php $fieldName = h($field['name']); $required = !empty($field['required']); ?>
                                    <label class="form-label small mb-1"><?= h($field['label']) ?><?= $required ? ' *' : '' ?></label>
                                    <?php if (($field['type'] ?? 'text') === 'textarea'): ?>
                                        <textarea class="form-control form-control-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>></textarea>
                                    <?php elseif (($field['type'] ?? 'text') === 'select'): ?>
                                        <select class="form-select form-select-sm" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                            <option value="">Selecionar...</option>
                                            <?php foreach (($field['options'] ?? []) as $option): ?>
                                                <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input class="form-control form-control-sm" type="<?= h(($field['type'] ?? 'text')) ?>" name="<?= $fieldName ?>" <?= $required ? 'required' : '' ?>>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <button class="btn btn-primary btn-sm mt-2">Submeter pedido</button>
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
            <div class="card-header bg-white"><h3 class="h5 mb-0">Últimos pedidos enviados</h3></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Formulário</th><th>Equipa</th><th>Criado por</th></tr></thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?= h($entry['form_title']) ?><br><small class="text-muted"><?= h(date('d/m/Y H:i', strtotime($entry['created_at']))) ?></small></td>
                                <td><?= h($entry['team_name']) ?></td>
                                <td><?= h($entry['creator_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$entries): ?><tr><td colspan="3" class="text-muted">Sem pedidos submetidos.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                    <p class="small text-muted mb-2">Define os campos do formulário. Podes remover ou deixar opções vazias quando não for select.</p>
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
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
