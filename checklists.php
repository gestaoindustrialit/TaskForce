<?php
require_once __DIR__ . '/helpers.php';
require_login();

$userId = (int) $_SESSION['user_id'];
$flashSuccess = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_template') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $itemsRaw = trim((string) ($_POST['items'] ?? ''));

        if ($name === '') {
            $flashError = 'O modelo precisa de um nome.';
        } else {
            $items = [];
            foreach (preg_split('/\r\n|\r|\n/', $itemsRaw) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $items[] = $line;
                }
            }

            if (!$items) {
                $flashError = 'Adicione pelo menos um item para o checklist.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO checklist_templates(name, description, created_by) VALUES (?, ?, ?)');
                $stmt->execute([$name, $description !== '' ? $description : null, $userId]);
                $templateId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO checklist_template_items(template_id, content, position) VALUES (?, ?, ?)');
                foreach ($items as $index => $item) {
                    $itemStmt->execute([$templateId, $item, $index + 1]);
                }
                $flashSuccess = 'Checklist criada com sucesso.';
            }
        }
    }

    if ($action === 'delete_template') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            $stmt = $pdo->prepare('DELETE FROM checklist_templates WHERE id = ? AND created_by = ?');
            $stmt->execute([$templateId, $userId]);
            if ($stmt->rowCount() > 0) {
                $flashSuccess = 'Checklist removida com sucesso.';
            } else {
                $flashError = 'Não foi possível remover esta checklist (apenas o autor pode remover).';
            }
        }
    }
}

$templatesStmt = $pdo->query('SELECT ct.*, u.name AS creator_name FROM checklist_templates ct INNER JOIN users u ON u.id = ct.created_by ORDER BY ct.created_at DESC');
$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

$templateItemsStmt = $pdo->query('SELECT * FROM checklist_template_items ORDER BY template_id ASC, position ASC, id ASC');
$templateItemsById = [];
foreach ($templateItemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $templateItemsById[(int) $item['template_id']][] = $item;
}

$pageTitle = 'Checklists';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-1">Modelos de checklist</h1>
        <p class="text-muted mb-0">Crie checklists reutilizáveis para tarefas e recorrências.</p>
    </div>
</div>

<?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm soft-card">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Nova checklist</h2></div>
            <div class="card-body">
                <form method="post" class="vstack gap-2">
                    <input type="hidden" name="action" value="create_template">
                    <input class="form-control" name="name" placeholder="Nome" required>
                    <input class="form-control" name="description" placeholder="Descrição (opcional)">
                    <textarea class="form-control" name="items" rows="8" placeholder="Um item por linha" required></textarea>
                    <button class="btn btn-primary">Guardar checklist</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm soft-card">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Checklists criadas</h2></div>
            <div class="card-body vstack gap-2">
                <?php foreach ($templates as $template): ?>
                    <div class="border rounded p-3">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h3 class="h6 mb-1"><?= h($template['name']) ?></h3>
                                <?php if ((string) ($template['description'] ?? '') !== ''): ?><p class="small text-muted mb-1"><?= h((string) $template['description']) ?></p><?php endif; ?>
                                <small class="text-muted">Criada por <?= h($template['creator_name']) ?></small>
                            </div>
                            <?php if ((int) $template['created_by'] === $userId): ?>
                                <form method="post" onsubmit="return confirm('Remover checklist?');">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Remover</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <ul class="mb-0 mt-2">
                            <?php foreach (($templateItemsById[(int) $template['id']] ?? []) as $item): ?>
                                <li><?= h($item['content']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
                <?php if (!$templates): ?><div class="text-muted">Sem checklists criadas.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
