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
        $department = $_POST['department'] ?? 'tornearia';

        if ($teamId > 0 && $title !== '') {
            $stmt = $pdo->prepare('INSERT INTO team_forms(team_id, title, description, department, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$teamId, $title, $description, $department, $userId]);
            $_SESSION['flash_success'] = 'Formulário global criado e disponível para todos os utilizadores.';
        }
    }

    if ($action === 'submit_team_form') {
        $formId = (int) ($_POST['form_id'] ?? 0);
        $requesterName = trim($_POST['requester_name'] ?? '');
        $responsible = trim($_POST['responsible'] ?? '');
        $departmentRequested = trim($_POST['department_requested'] ?? '');
        $product = trim($_POST['product'] ?? '');
        $operation = trim($_POST['operation'] ?? '');
        $quantity = trim($_POST['quantity'] ?? '');
        $urgency = trim($_POST['urgency'] ?? '');
        $dueDate = $_POST['due_date'] ?: null;
        $notes = trim($_POST['notes'] ?? '');
        $attachmentRef = trim($_POST['attachment_ref'] ?? '');

        $formStmt = $pdo->prepare('SELECT id FROM team_forms WHERE id = ? AND is_active = 1');
        $formStmt->execute([$formId]);
        $exists = $formStmt->fetchColumn();

        if ($exists && $requesterName !== '' && $responsible !== '' && $product !== '' && $operation !== '' && $quantity !== '' && $urgency !== '') {
            $stmt = $pdo->prepare(
                'INSERT INTO team_form_submissions(form_id, requester_name, responsible, department_requested, product, operation, quantity, urgency, due_date, notes, attachment_ref, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$formId, $requesterName, $responsible, $departmentRequested, $product, $operation, $quantity, $urgency, $dueDate, $notes, $attachmentRef, $userId]);
            $_SESSION['flash_success'] = 'Pedido submetido com sucesso para a equipa.';
        }
    }

    redirect('requests.php');
}

$teams = $pdo->query('SELECT id, name FROM teams ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$forms = $pdo->query(
    'SELECT tf.*, t.name AS team_name, u.name AS creator_name
     FROM team_forms tf
     INNER JOIN teams t ON t.id = tf.team_id
     INNER JOIN users u ON u.id = tf.created_by
     WHERE tf.is_active = 1
     ORDER BY tf.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$submissionsStmt = $pdo->prepare(
    'SELECT s.*, f.title AS form_title, t.name AS team_name, u.name AS creator_name
     FROM team_form_submissions s
     INNER JOIN team_forms f ON f.id = s.form_id
     INNER JOIN teams t ON t.id = f.team_id
     INNER JOIN users u ON u.id = s.created_by
     ORDER BY s.created_at DESC
     LIMIT 40'
);
$submissionsStmt->execute();
$submissions = $submissionsStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <?php if ($isAdmin): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamFormModal">Gerar formulário (admin)</button>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Submeter pedido</h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($forms as $form): ?>
                    <div class="list-group-item">
                        <h4 class="h6 mb-1"><?= h($form['title']) ?></h4>
                        <p class="small text-muted mb-1">Equipa destino: <strong><?= h($form['team_name']) ?></strong> · Departamento: <strong><?= h(ucfirst($form['department'])) ?></strong></p>
                        <p class="small mb-2"><?= h($form['description']) ?></p>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#submit<?= (int) $form['id'] ?>">Abrir formulário</button>
                        <div class="collapse mt-2" id="submit<?= (int) $form['id'] ?>">
                            <form method="post" class="vstack gap-2 border rounded p-3 bg-light-subtle">
                                <input type="hidden" name="action" value="submit_team_form">
                                <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                <input class="form-control form-control-sm" name="requester_name" placeholder="Responsável" required>
                                <select class="form-select form-select-sm" name="department_requested" required>
                                    <option value="Desenho técnico 3D">Desenho técnico 3D</option>
                                    <option value="Tornearia" selected>Tornearia</option>
                                    <option value="Desenho técnico 3D e Tornearia">Desenho técnico 3D e Tornearia</option>
                                    <option value="Manutenção">Manutenção</option>
                                    <option value="Compras">Compras</option>
                                </select>
                                <input class="form-control form-control-sm" name="responsible" placeholder="Responsável interno" required>
                                <input class="form-control form-control-sm" name="product" placeholder="Produto" required>
                                <input class="form-control form-control-sm" name="operation" placeholder="Operação" required>
                                <input class="form-control form-control-sm" name="quantity" placeholder="Quantidade" required>
                                <select class="form-select form-select-sm" name="urgency" required>
                                    <option value="1 - Baixa">1 - Baixa</option>
                                    <option value="2 - Moderada">2 - Moderada</option>
                                    <option value="3 - Importante">3 - Importante</option>
                                    <option value="4 - Urgente">4 - Urgente</option>
                                    <option value="5 - Crítica">5 - Crítica</option>
                                </select>
                                <input class="form-control form-control-sm" type="date" name="due_date">
                                <input class="form-control form-control-sm" name="attachment_ref" placeholder="Plano / DXF / STEP (referência)">
                                <textarea class="form-control form-control-sm" name="notes" placeholder="Observações"></textarea>
                                <button class="btn btn-primary btn-sm">Submeter pedido</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$forms): ?><div class="list-group-item text-muted">Não existem formulários ativos. O admin pode gerar formulários globais.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card soft-card shadow-sm h-100">
            <div class="card-header bg-white"><h3 class="h5 mb-0">Últimos pedidos enviados</h3></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Formulário</th><th>Equipa</th><th>Produto</th><th>Urgência</th></tr></thead>
                    <tbody>
                        <?php foreach ($submissions as $s): ?>
                            <tr>
                                <td><strong><?= h($s['form_title']) ?></strong><br><small class="text-muted">por <?= h($s['creator_name']) ?></small></td>
                                <td><?= h($s['team_name']) ?></td>
                                <td><?= h($s['product']) ?> <small class="text-muted">(<?= h($s['quantity']) ?>)</small></td>
                                <td><span class="badge text-bg-warning"><?= h($s['urgency']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$submissions): ?><tr><td colspan="4" class="text-muted">Sem pedidos submetidos.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="createTeamFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <input type="hidden" name="action" value="create_team_form">
            <div class="modal-header"><h5 class="modal-title">Gerar formulário global (Admin)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body vstack gap-3">
                <input class="form-control" name="title" placeholder="Título" required>
                <textarea class="form-control" name="description" placeholder="Descrição"></textarea>
                <select class="form-select" name="team_id" required>
                    <option value="">Selecionar equipa destino</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int) $team['id'] ?>"><?= h($team['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="department">
                    <option value="tornearia">Tornearia</option>
                    <option value="manutenção">Manutenção</option>
                    <option value="compras">Compras</option>
                </select>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Criar formulário</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
